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
        echo "<script>alert('ข้อมูลการจองไม่ถูกต้อง หรือไม่ได้เชื่อมต่อฐานข้อมูล'); window.location.href='index.php';</script>";
        exit();
    }

    // 1. ตรวจสอบไฟล์อัปโหลดว่ามี Error จาก PHP หรือไม่
    $file_error = $_FILES['payment_slip']['error'];
    if ($file_error !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE   => 'ขนาดไฟล์ใหญ่เกินกำหนดใน php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'ขนาดไฟล์ใหญ่เกินกำหนดในฟอร์ม HTML',
            UPLOAD_ERR_PARTIAL    => 'ไฟล์ถูกอัปโหลดมาไม่ครบถ้วน',
            UPLOAD_ERR_NO_FILE    => 'ไม่ได้เลือกไฟล์สลิป',
            UPLOAD_ERR_NO_TMP_DIR => 'ไม่พบโฟลเดอร์ชั่วคราวบนเซิร์ฟเวอร์',
            UPLOAD_ERR_CANT_WRITE => 'ไม่สามารถเขียนไฟล์ลงดิสก์ได้ (ตรวจสอบสิทธิ์ Permission โฟลเดอร์)',
            UPLOAD_ERR_EXTENSION  => 'ถูกบล็อกโดย PHP Extension'
        ];
        $msg = $error_messages[$file_error] ?? 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (Code: ' . $file_error . ')';
        echo "<script>alert('$msg'); window.history.back();</script>";
        exit();
    }

    // 2. ค้นหาข้อมูลการจองจากตาราง bookings อย่างปลอดภัย (รองรับทั้ง id และ booking_id)
    $booking = null;
    $sql_bk = "SELECT * FROM bookings WHERE id = $1 LIMIT 1";
    $booking_res = @pg_query_params($conn, $sql_bk, array($booking_id));

    if (!$booking_res || pg_num_rows($booking_res) === 0) {
        $sql_bk = "SELECT * FROM bookings WHERE booking_id = $1 LIMIT 1";
        $booking_res = @pg_query_params($conn, $sql_bk, array($booking_id));
        
        if (!$booking_res || pg_num_rows($booking_res) === 0) {
            echo "<script>alert('ไม่พบข้อมูลการจองในระบบ (รหัส: $booking_id)'); window.location.href='index.php';</script>";
            exit();
        }
    }
    $booking = pg_fetch_assoc($booking_res);

    // 3. ดึงชื่อแพแยกต่างหาก เพื่อป้องกัน SQL Error จากชื่อคอลัมน์ที่ไม่ตรงกัน
    $raft_name = '';
    $raft_id = intval($booking['raft_id'] ?? 0);
    if ($raft_id > 0) {
        $raft_res = @pg_query_params($conn, "SELECT COALESCE(name, raft_name, '') as r_name FROM rafts WHERE id = $1 LIMIT 1", array($raft_id));
        if (!$raft_res || pg_num_rows($raft_res) === 0) {
            $raft_res = @pg_query_params($conn, "SELECT COALESCE(name, raft_name, '') as r_name FROM rafts WHERE raft_id = $1 LIMIT 1", array($raft_id));
        }
        if ($raft_res && pg_num_rows($raft_res) > 0) {
            $r_row = pg_fetch_assoc($raft_res);
            $raft_name = $r_row['r_name'] ?? '';
        }
    }
    $booking['raft_name'] = $raft_name;

    $default_price = floatval($booking['total_amount'] ?? $booking['total_price'] ?? $booking['raft_price'] ?? 0);

    // 4. จัดการโฟลเดอร์อัปโหลดสลิป
    $target_dir = __DIR__ . "/uploads/slips/";
    if (!file_exists($target_dir)) { 
        if (!@mkdir($target_dir, 0755, true)) {
            echo "<script>alert('ไม่สามารถสร้างโฟลเดอร์ uploads/slips/ ได้ (ตรวจสอบ Permission โฟลเดอร์หลัก)'); window.history.back();</script>";
            exit();
        }
    }

    // ตรวจสอบนามสกุลไฟล์
    $file_ext = strtolower(pathinfo($_FILES["payment_slip"]["name"], PATHINFO_EXTENSION));
    if (!in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        echo "<script>alert('กรุณาอัปโหลดไฟล์รูปภาพนามสกุล JPG, JPEG, PNG หรือ WEBP เท่านั้น'); window.history.back();</script>";
        exit();
    }

    $new_filename = "slip_" . $booking_id . "_" . time() . "." . $file_ext;
    $target_file = $target_dir . $new_filename;

    // 5. ย้ายไฟล์ไปยังโฟลเดอร์ปลายทาง
    if (move_uploaded_file($_FILES["payment_slip"]["tmp_name"], $target_file)) {
        
        $payment_code = "PAY" . date('Ymd') . str_pad($booking_id, 4, '0', STR_PAD_LEFT);
        $bank_name = !empty($_POST['bank_name']) ? trim($_POST['bank_name']) : 'โอนเงิน/PromptPay';
        $transfer_time_input = !empty($_POST['transfer_time']) ? trim($_POST['transfer_time']) : date('Y-m-d H:i:s');
        $paid_at = date('Y-m-d H:i:s', strtotime($transfer_time_input));
        $transfer_ref = trim($_POST['transfer_ref'] ?? '');
        $transfer_amount = (!empty($_POST['transfer_amount']) && floatval($_POST['transfer_amount']) > 0) 
                           ? floatval($_POST['transfer_amount']) 
                           : $default_price;
        $notes = "ธนาคาร/ช่องทางโอน: " . $bank_name;

        // 6. บันทึกลงตาราง payments (ถ้ามีตาราง)
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

        // 7. อัปเดตตาราง bookings
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
            $pk_col = in_array('booking_id', $b_cols) && !in_array('id', $b_cols) ? 'booking_id' : 'id';
            $sql_up_b = "UPDATE bookings SET " . implode(", ", $b_updates) . " WHERE $pk_col = $" . $b_idx;
            @pg_query_params($conn, $sql_up_b, $b_params);
        }

        // 8. ส่งแจ้งเตือน LINE ไปหาแอดมินพร้อมรูปสลิป
        $guest_name = !empty($booking['guest_name']) ? $booking['guest_name'] : 'ลูกค้า';
        $raft_display = !empty($booking['raft_name']) ? $booking['raft_name'] : ('แพ #' . $raft_id);

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

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $slip_public_url = "$protocol://$host" . ($base_dir ? $base_dir : '') . "/uploads/slips/" . $new_filename;

        $line_access_token = 'jStaztWHf7QXNoCVTPhoqat7sCmK5HZp5GBJXrlUv+c9NMT26dzuAbalCnpxp53VSGoGBIU16cV5CSfyuKq4qpqbBv+Xd8ju3CTw3/sHfa3PpcS2RwYykgN3CqcJye6QEqexCW+w0MD8B9tF5w+FxAdB04t89/1O/w1cDnyilFU='; 
        $line_to_id = 'YOUR_ADMIN_USER_OR_GROUP_ID'; // ⚠️️ อย่าลืมใส่ User ID หรือ Group ID ของแอดมิน

        if (!empty($line_access_token) && $line_to_id !== 'YOUR_ADMIN_USER_OR_GROUP_ID') {
            $push_data = [
                'to' => $line_to_id,
                'messages' => [
                    ['type' => 'text', 'text' => $line_msg],
                    ['type' => 'image', 'originalContentUrl' => $slip_public_url, 'previewImageUrl' => $slip_public_url]
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
        
        echo "<script>
                alert('บันทึกหลักฐานการชำระเงินเรียบร้อยแล้ว');
                window.location.href = 'booking_success.php?id=$booking_id';
              </script>";
        exit();

    } else {
        echo "<script>alert('เกิดข้อผิดพลาดในการบันทึกไฟล์ภาพลงเซิร์ฟเวอร์ (โปรดตรวจสอบสิทธิ์เขียนไฟล์ของโฟลเดอร์ uploads/slips/)'); window.history.back();</script>";
        exit();
    }

} else {
    header("Location: index.php");
    exit();
}
?>
