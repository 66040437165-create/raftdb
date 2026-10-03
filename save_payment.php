<?php
session_start();

if (file_exists(__DIR__ . '/db_config.php')) {
    require_once __DIR__ . '/db_config.php';
} else {
    require_once __DIR__ . '/../db_config.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_slip'])) {
    
    $booking_id = intval($_POST['booking_id'] ?? 0);

    if ($booking_id <= 0 || !$conn) {
        header("Location: index.php");
        exit();
    }

    // 1. ตรวจสอบว่ามีข้อมูลการจองจริงหรือไม่ (PostgreSQL)
    $sql_bk = "SELECT b.*, COALESCE(r.name, r.raft_name, '') AS raft_name 
               FROM bookings b 
               LEFT JOIN rafts r ON b.raft_id = r.id 
               WHERE b.id = $1 LIMIT 1";
    $booking_res = @pg_query_params($conn, $sql_bk, array($booking_id));

    if (!$booking_res || pg_num_rows($booking_res) === 0) {
        // Fallback เผื่อตารางใช้ booking_id แทน id
        $sql_bk = "SELECT b.*, COALESCE(r.name, r.raft_name, '') AS raft_name 
                   FROM bookings b 
                   LEFT JOIN rafts r ON b.raft_id = r.raft_id 
                   WHERE b.booking_id = $1 LIMIT 1";
        $booking_res = @pg_query_params($conn, $sql_bk, array($booking_id));
        
        if (!$booking_res || pg_num_rows($booking_res) === 0) {
            echo "<script>alert('ไม่พบข้อมูลการจอง'); window.location.href='index.php';</script>";
            exit();
        }
    }
    $booking = pg_fetch_assoc($booking_res);

    // ดึงราคาที่ต้องชำระ
    $default_price = floatval($booking['total_amount'] ?? $booking['total_price'] ?? $booking['raft_price'] ?? 0);

    // 2. ดึงข้อมูลจากฟอร์ม
    $bank_name = !empty($_POST['bank_name']) ? trim($_POST['bank_name']) : 'โอนเงิน/PromptPay';
    $transfer_time_input = !empty($_POST['transfer_time']) ? trim($_POST['transfer_time']) : date('Y-m-d H:i:s');
    $paid_at = date('Y-m-d H:i:s', strtotime($transfer_time_input));
    $transfer_ref = trim($_POST['transfer_ref'] ?? '');
    $transfer_amount = (!empty($_POST['transfer_amount']) && floatval($_POST['transfer_amount']) > 0) 
                       ? floatval($_POST['transfer_amount']) 
                       : $default_price;

    // 3. จัดการอัปโหลดไฟล์รูปสลิป
    $target_dir = "uploads/slips/";
    if (!file_exists($target_dir)) { 
        @mkdir($target_dir, 0777, true); 
    }

    $file_ext = strtolower(pathinfo($_FILES["payment_slip"]["name"], PATHINFO_EXTENSION));
    
    if (!in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        echo "<script>alert('กรุณาอัปโหลดไฟล์รูปภาพนามสกุล JPG, PNG หรือ WEBP เท่านั้น'); window.history.back();</script>";
        exit();
    }

    $new_filename = "slip_" . $booking_id . "_" . time() . "." . $file_ext;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($_FILES["payment_slip"]["tmp_name"], $target_file)) {
        
        $payment_code = "PAY" . date('Ymd') . str_pad($booking_id, 4, '0', STR_PAD_LEFT);
        $notes = "ธนาคาร/ช่องทางโอน: " . $bank_name;

        // 4. ตรวจสอบและบันทึกลงตาราง payments (ถ้ามีตาราง payments)
        $chk_pay = @pg_query($conn, "SELECT to_regclass('public.payments')");
        $has_pay = ($chk_pay && ($r_tbl = pg_fetch_row($chk_pay)) && !empty($r_tbl[0]));

        if ($has_pay) {
            $sql_pay = "INSERT INTO payments (
                            payment_code, booking_id, payment_method_id, amount, payment_type, 
                            status, transaction_ref, slip_image, paid_at, notes, created_at
                        ) VALUES (
                            $1, $2, 1, $3, 'full', 'pending', $4, $5, $6, $7, NOW()
                        )";
            @pg_query_params($conn, $sql_pay, array(
                $payment_code, 
                $booking_id, 
                $transfer_amount, 
                $transfer_ref, 
                $new_filename, 
                $paid_at, 
                $notes
            ));
        }

        // 5. ปรับสถานะในตาราง bookings และบันทึกชื่อสลิป
        $b_cols = [];
        $chk_b = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
        if ($chk_b) {
            while ($bc = pg_fetch_assoc($chk_b)) {
                $b_cols[] = strtolower($bc['column_name']);
            }
        }

        $b_updates = [];
        $b_params = [];
        $b_idx = 1;

        if (in_array('status_id', $b_cols)) {
            $b_updates[] = "status_id = $" . $b_idx++;
            $b_params[] = 1;
        }
        if (in_array('status', $b_cols)) {
            $b_updates[] = "status = $" . $b_idx++;
            $b_params[] = 'pending';
        }
        if (in_array('slip_image', $b_cols)) {
            $b_updates[] = "slip_image = $" . $b_idx++;
            $b_params[] = $new_filename;
        }

        if (!empty($b_updates)) {
            $b_params[] = $booking_id;
            // เช็คเงื่อนไข Primary Key ว่าเป็น id หรือ booking_id
            $pk_col = in_array('booking_id', $b_cols) && !in_array('id', $b_cols) ? 'booking_id' : 'id';
            $sql_up_b = "UPDATE bookings SET " . implode(", ", $b_updates) . " WHERE $pk_col = $" . $b_idx;
            @pg_query_params($conn, $sql_up_b, $b_params);
        }

        // 6. ส่งแจ้งเตือน LINE พร้อมรูปสลิป (ผ่าน LINE Messaging API)
        $guest_name = !empty($booking['guest_name']) ? $booking['guest_name'] : 'ลูกค้า';
        $raft_display = $booking['raft_name'] ?? ('แพ #' . ($booking['raft_id'] ?? ''));

        $line_msg  = "💸 แจ้งโอนเงิน/แนบสลิปใหม่!\n";
        $line_msg .= "━━━━━━━━━━━━━━━━\n";
        $line_msg .= "📋 Booking ID: #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . "\n";
        $line_msg .= "💳 Payment Code: $payment_code\n";
        $line_msg .= "👤 ชื่อผู้จอง: $guest_name\n";
        $line_msg .= "⛵ แพ: $raft_display\n";
        $line_msg .= "🏦 ธนาคาร: $bank_name\n";
        $line_msg .= "🕒 เวลาโอน: " . date('d/m/Y H:i', strtotime($paid_at)) . " น.\n";
        if (!empty($transfer_ref)) {
            $line_msg .= "🔢 เลขอ้างอิง: $transfer_ref\n";
        }
        $line_msg .= "💰 ยอดโอน: ฿" . number_format($transfer_amount, 2) . "\n";
        $line_msg .= "━━━━━━━━━━━━━━━━\n";
        $line_msg .= "⚠️ รูปสลิปหลักฐานการโอนด้านล่างนี้ 👇";

        // สร้าง Public URL ของรูปสลิปสำหรับส่งให้ LINE Bot ดึงภาพ
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $slip_public_url = "$protocol://$host" . ($base_dir ? $base_dir : '') . "/uploads/slips/" . $new_filename;

        // ส่งผ่าน LINE Messaging API (Push Message)
        $line_access_token = 'YOUR_LINE_CHANNEL_ACCESS_TOKEN'; // 🔑 ใส่ Channel Access Token ของบอทร้าน
        $line_to_id = 'YOUR_ADMIN_USER_OR_GROUP_ID';             // 🔑 ใส่ User ID หรือ Group ID ของแอดมิน

        if (!empty($line_access_token) && $line_access_token !== 'YOUR_LINE_CHANNEL_ACCESS_TOKEN') {
            $push_data = [
                'to' => $line_to_id,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => $line_msg
                    ],
                    [
                        'type' => 'image',
                        'originalContentUrl' => $slip_public_url,
                        'previewImageUrl' => $slip_public_url
                    ]
                ]
            ];

            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($push_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $line_access_token
            ]);
            @curl_exec($ch);
            @curl_close($ch);
        }

        // เผื่อมีฟังก์ชัน send_line_message เดิมที่ใช้ร่วมกัน
        if (function_exists('send_line_message') && empty($line_access_token)) {
            @send_line_message($line_msg);
        }
        
        echo "<script>
                alert('บันทึกหลักฐานการชำระเงินเรียบร้อยแล้ว');
                window.location.href = 'booking_success.php?id=$booking_id';
              </script>";
        exit();

    } else {
        echo "<script>alert('ขออภัย, เกิดข้อผิดพลาดในการอัปโหลดไฟล์สลิป'); window.history.back();</script>";
        exit();
    }

} else {
    header("Location: index.php");
    exit();
}
?>
