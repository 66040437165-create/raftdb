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
        echo "<script>alert('เกิดข้อผิดพลาดในการแนบรูป (Error Code: $file_error)'); window.history.back();</script>";
        exit();
    }

    // 2. ค้นหาข้อมูลการจองจากตาราง bookings
    $booking = null;
    $sql_bk = "SELECT * FROM bookings WHERE id = $1 LIMIT 1";
    $booking_res = @pg_query_params($conn, $sql_bk, array($booking_id));

    if (!$booking_res || pg_num_rows($booking_res) === 0) {
        $sql_bk = "SELECT * FROM bookings WHERE booking_id = $1 LIMIT 1";
        $booking_res = @pg_query_params($conn, $sql_bk, array($booking_id));
        if (!$booking_res || pg_num_rows($booking_res) === 0) {
            echo "<script>alert('ไม่พบข้อมูลการจองในระบบ'); window.location.href='index.php';</script>";
            exit();
        }
    }
    $booking = pg_fetch_assoc($booking_res);

    // ดึงชื่อแพ
    $raft_name = '';
    $raft_id = intval($booking['raft_id'] ?? 0);
    if ($raft_id > 0) {
        $raft_res = @pg_query_params($conn, "SELECT COALESCE(name, '') as r_name FROM rafts WHERE id = $1 LIMIT 1", array($raft_id));
        if ($raft_res && pg_num_rows($raft_res) > 0) {
            $raft_name = pg_fetch_assoc($raft_res)['r_name'];
        }
    }
    $booking['raft_name'] = $raft_name;

    $default_price = floatval($booking['total_amount'] ?? $booking['total_price'] ?? $booking['raft_price'] ?? 0);
    $payment_code = "PAY" . date('Ymd') . str_pad($booking_id, 4, '0', STR_PAD_LEFT);

    // 🟢 3. ส่งรูปภาพไปฝากที่ ImgBB ผ่าน API ของ PHP (cURL)
    $image_tmp_name = $_FILES['payment_slip']['tmp_name'];
    $image_data = base64_encode(file_get_contents($image_tmp_name));
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.imgbb.com/1/upload?key=30ca5dbcc6895c25e839e334df58d4d8');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['image' => $image_data]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $imgbb_result = json_decode($response, true);
    
    // หากฝากรูปไม่สำเร็จ
    if (!$imgbb_result || !isset($imgbb_result['data']['url'])) {
        echo "<script>alert('ระบบฝากรูปขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง'); window.history.back();</script>";
        exit();
    }
    
    // ได้รับ URL รูปภาพกลับมาแล้ว (รูปฝากไว้ที่ ImgBB อย่างถาวร)
    $slip_url = $imgbb_result['data']['url'];

    // 4. บันทึกลงตาราง payments
    $chk_pay = @pg_query($conn, "SELECT to_regclass('public.payments')");
    $has_pay = ($chk_pay && ($r_tbl = pg_fetch_row($chk_pay)) && !empty($r_tbl[0]));

    if ($has_pay) {
        $sql_pay = "INSERT INTO payments (
                        payment_code, booking_id, payment_method_id, amount, payment_type, 
                        status, slip_image, paid_at, created_at
                    ) VALUES (
                        $1, $2, 1, $3, 'full', 'pending', $4, NOW(), NOW()
                    )";
        @pg_query_params($conn, $sql_pay, array($payment_code, $booking_id, $default_price, $slip_url));
    }

    // 5. อัปเดตตาราง bookings (บันทึกลิงก์รูป)
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

    if (in_array('status_id', $b_cols)) { $b_updates[] = "status_id = $" . $b_idx++; $b_params[] = 1; }
    if (in_array('status', $b_cols)) { $b_updates[] = "status = $" . $b_idx++; $b_params[] = 'pending'; }
    if (in_array('slip_image', $b_cols)) { $b_updates[] = "slip_image = $" . $b_idx++; $b_params[] = $slip_url; }

    if (!empty($b_updates)) {
        $b_params[] = $booking_id;
        $pk_col = in_array('booking_id', $b_cols) && !in_array('id', $b_cols) ? 'booking_id' : 'id';
        $sql_up_b = "UPDATE bookings SET " . implode(", ", $b_updates) . " WHERE $pk_col = $" . $b_idx;
        @pg_query_params($conn, $sql_up_b, $b_params);
    }

    // 6. ส่งแจ้งเตือน LINE ไปหาแอดมินพร้อมแนบรูปสลิป
    $guest_name = !empty($booking['guest_name']) ? $booking['guest_name'] : 'ลูกค้า';
    $raft_display = !empty($raft_name) ? $raft_name : ('แพ #' . $raft_id);

    $line_msg  = "💸 ลูกค้าแจ้งแนบสลิปใหม่!\n";
    $line_msg .= "📋 Booking ID: #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . "\n";
    $line_msg .= "👤 ผู้จอง: $guest_name\n";
    $line_msg .= "⛵ แพ: $raft_display\n";
    $line_msg .= "💰 ยอดโอน: ฿" . number_format($default_price, 2) . "\n";
    $line_msg .= "━━━━━━━━━━━━━━━━\n";
    $line_msg .= "⚠️ กรุณาเข้าสู่ระบบหลังบ้านเพื่อตรวจสอบและกดอนุมัติครับ";

    $line_access_token = 'jStaztWHf7QXNoCVTPhoqat7sCmK5HZp5GBJXrlUv+c9NMT26dzuAbalCnpxp53VSGoGBIU16cV5CSfyuKq4qpqbBv+Xd8ju3CTw3/sHfa3PpcS2RwYykgN3CqcJye6QEqexCW+w0MD8B9tF5w+FxAdB04t89/1O/w1cDnyilFU='; 
    $line_to_id = 'YOUR_ADMIN_USER_OR_GROUP_ID'; // ⚠ อย่าลืมเปลี่ยนเป็น User ID หรือ Group ID แอดมิน (ถ้าตั้งค่าไว้แล้วปล่อยเดิมได้เลย)

    if (!empty($line_access_token) && $line_to_id !== 'YOUR_ADMIN_USER_OR_GROUP_ID') {
        $push_data = [
            'to' => $line_to_id,
            'messages' => [
                ['type' => 'text', 'text' => $line_msg],
                ['type' => 'image', 'originalContentUrl' => $slip_url, 'previewImageUrl' => $slip_url]
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
            alert('อัปโหลดและบันทึกสลิปสำเร็จเรียบร้อยแล้ว!');
            window.location.href = 'booking_success.php?id=$booking_id';
          </script>";
    exit();

} else {
    echo "<script>alert('ไม่สามารถดำเนินการได้ หรือเกิดข้อผิดพลาดในการรับข้อมูล'); window.location.href='index.php';</script>";
    exit();
}
?>
