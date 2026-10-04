<?php
session_start();
require_once __DIR__ . '/db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['payment_slip'])) {
    
    $booking_id = intval($_POST['booking_id']);

    // 1. ตรวจสอบว่ามีข้อมูลการจองจริงหรือไม่ (ใช้ pg_query_params เพื่อความปลอดภัย)
    $booking_sql = "SELECT b.*, r.name AS raft_name FROM bookings b JOIN rafts r ON b.raft_id = r.id WHERE b.id = $1";
    $booking_res = @pg_query_params($conn, $booking_sql, array($booking_id));
    
    if (!$booking_res || pg_num_rows($booking_res) == 0) {
        echo "<script>alert('ไม่พบข้อมูลการจอง'); window.location.href='index.php';</script>";
        exit();
    }
    $booking = pg_fetch_assoc($booking_res);

    // ดึงราคาจาก raft_price
    $default_price = floatval($booking['raft_price'] ?? 0);

    // 2. ดึงข้อมูลจากฟอร์ม (ใช้ trim แทน real_escape_string เพราะเราจะใช้ pg_query_params ป้องกัน injection อยู่แล้ว)
    $bank_name = isset($_POST['bank_name']) && !empty($_POST['bank_name']) ? trim($_POST['bank_name']) : 'โอนเงิน/PromptPay';
    $transfer_time_input = isset($_POST['transfer_time']) && !empty($_POST['transfer_time']) ? $_POST['transfer_time'] : date('Y-m-d H:i:s');
    $paid_at = date('Y-m-d H:i:s', strtotime($transfer_time_input));
    $transfer_ref = isset($_POST['transfer_ref']) ? trim($_POST['transfer_ref']) : '';
    $transfer_amount = isset($_POST['transfer_amount']) && !empty($_POST['transfer_amount']) ? floatval($_POST['transfer_amount']) : $default_price;

    // 3. จัดการอัปโหลดไฟล์รูปสลิป
    // 🟢 แก้ไขตรงนี้: เปลี่ยนจาก uploads/slips/ เป็น uploads/
    $target_dir = "uploads/";
    if (!file_exists($target_dir)) { 
        mkdir($target_dir, 0777, true); 
    }

    $file_ext = strtolower(pathinfo($_FILES["payment_slip"]["name"], PATHINFO_EXTENSION));
    $new_filename = "slip_" . $booking_id . "_" . time() . "." . $file_ext;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($_FILES["payment_slip"]["tmp_name"], $target_file)) {
        
        // กำหนดรหัสการชำระเงิน และหมายเหตุ
        $payment_code = "PAY" . date('Ymd') . str_pad($booking_id, 4, '0', STR_PAD_LEFT);
        $notes = "ธนาคาร/ช่องทางโอน: " . $bank_name;

        // 4. บันทึกข้อมูลลงตาราง payments (เก็บ booking_id ไว้เชื่อมโยงตามเดิม)
        $insert_pay_sql = "INSERT INTO payments (payment_code, booking_id, payment_method_id, amount, payment_type, status, transaction_ref, slip_image, paid_at, notes, created_at) 
                           VALUES ($1, $2, 1, $3, 'full', 'pending', $4, $5, $6, $7, NOW())";
                           
        $pay_params = array(
            $payment_code, 
            $booking_id, 
            $transfer_amount, 
            $transfer_ref, 
            $new_filename, 
            $paid_at, 
            $notes
        );
        
        $result_pay = @pg_query_params($conn, $insert_pay_sql, $pay_params);
        
        if ($result_pay) {
            
            // 5. ปรับสถานะในตาราง bookings ให้เป็นรอตรวจสอบ (status_id = 1 และ status = 'pending')
            $update_book_sql = "UPDATE bookings SET status_id = 1, status = 'pending' WHERE id = $1";
            @pg_query_params($conn, $update_book_sql, array($booking_id));

            // 6. ส่งแจ้งเตือน LINE หาแอดมิน (ถ้ามีฟังก์ชัน send_line_message)
            if (function_exists('send_line_message')) {
                $guest_name = !empty($booking['guest_name']) ? $booking['guest_name'] : 'ลูกค้า';
                
                $line_msg = "\n💸 แจ้งโอนเงิน/แนบสลิปใหม่!\n";
                $line_msg .= "━━━━━━━━━━━━━━━━\n";
                $line_msg .= "📋 Booking ID: #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . "\n";
                $line_msg .= "💳 Payment Code: $payment_code\n";
                $line_msg .= "👤 ชื่อผู้จอง: $guest_name\n";
                $line_msg .= "⛵ แพ: " . $booking['raft_name'] . "\n";
                $line_msg .= "🏦 ธนาคาร: $bank_name\n";
                $line_msg .= "🕒 เวลาโอนตามสลิป: " . date('d/m/Y H:i', strtotime($paid_at)) . " น.\n";
                if (!empty($transfer_ref)) {
                    $line_msg .= "🔢 เลขอ้างอิง: $transfer_ref\n";
                }
                $line_msg .= "💰 ยอดเงินโอนจริง: ฿" . number_format($transfer_amount, 2) . "\n";
                $line_msg .= "━━━━━━━━━━━━━━━━\n";
                $line_msg .= "⚠️ กรุณาตรวจสอบและกดอนุมัติการจองในระบบแอดมิน";
                
                send_line_message($line_msg);
            }
            
            // 🟢 เปลี่ยนเส้นทางกลับไปยังหน้า booking_success.php
            echo "<script>
                    alert('บันทึกหลักฐานและรายละเอียดการชำระเงินเรียบร้อยแล้ว');
                    window.location.href = 'booking_success.php?id=$booking_id';
                  </script>";
            exit();
        } else {
            $error_msg = pg_last_error($conn);
            echo "เกิดข้อผิดพลาดในการบันทึกข้อมูลการชำระเงิน: " . htmlspecialchars($error_msg);
        }
    } else {
        echo "<script>alert('ขออภัย, เกิดข้อผิดพลาดในการอัปโหลดไฟล์สลิป'); window.history.back();</script>";
    }

} else {
    header("Location: index.php");
    exit();
}
?>
