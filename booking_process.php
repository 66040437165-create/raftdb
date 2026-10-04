<?php
session_start();

if (file_exists(__DIR__ . '/db_config.php')) {
    require_once __DIR__ . '/db_config.php';
} else {
    require_once __DIR__ . '/../db_config.php';
}

if (file_exists(__DIR__ . '/line_helper.php')) {
    require_once __DIR__ . '/line_helper.php';
} elseif (file_exists(__DIR__ . '/../line_helper.php')) {
    require_once __DIR__ . '/../line_helper.php';
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $guest_name    = trim($_POST['guest_name'] ?? '');
    $guest_tel     = trim($_POST['guest_tel'] ?? '');
    $guest_email   = trim($_POST['guest_email'] ?? '');
    $raft_id       = intval($_POST['raft_id'] ?? 0);
    
    // วันที่และเวลาที่ลูกค้าเลือกจากฟอร์ม
    $check_in_date = trim($_POST['check_in'] ?? date('Y-m-d'));
    $check_in_time = trim($_POST['check_in_time'] ?? '09:00');
    $booking_type  = trim($_POST['booking_type'] ?? 'daily');

    if ($raft_id <= 0 || empty($guest_name) || empty($guest_tel)) {
        header("Location: index.php");
        exit();
    }

    // 1. ตรวจสอบตาราง customers
    $customer_id = null;
    $chk_table_cust = @pg_query($conn, "SELECT 1 FROM information_schema.tables WHERE table_name = 'customers'");
    
    if ($chk_table_cust && pg_num_rows($chk_table_cust) > 0 && !empty($guest_tel)) {
        $c_cols = [];
        $chk_c_cols = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'customers'");
        if ($chk_c_cols) {
            while ($col = pg_fetch_assoc($chk_c_cols)) {
                $c_cols[] = strtolower($col['column_name']);
            }
        }

        $cust_res = @pg_query_params($conn, "SELECT id FROM customers WHERE phone = $1 LIMIT 1", array($guest_tel));
        if ($cust_res && pg_num_rows($cust_res) > 0) {
            $cust_row = pg_fetch_assoc($cust_res);
            $customer_id = intval($cust_row['id']);
            if (in_array('total_bookings', $c_cols)) {
                @pg_query_params($conn, "UPDATE customers SET total_bookings = total_bookings + 1 WHERE id = $1", array($customer_id));
            }
        } else {
            $cust_code = 'CUST' . date('ymd') . rand(100, 999);
            $c_fields = [];
            $c_placeholders = [];
            $c_vals = [];
            $cp_idx = 1;

            if (in_array('customer_code', $c_cols)) { $c_fields[] = 'customer_code'; $c_placeholders[] = '$' . $cp_idx++; $c_vals[] = $cust_code; }
            if (in_array('full_name', $c_cols))     { $c_fields[] = 'full_name';     $c_placeholders[] = '$' . $cp_idx++; $c_vals[] = $guest_name; }
            if (in_array('phone', $c_cols))         { $c_fields[] = 'phone';         $c_placeholders[] = '$' . $cp_idx++; $c_vals[] = $guest_tel; }
            if (in_array('email', $c_cols))         { $c_fields[] = 'email';         $c_placeholders[] = '$' . $cp_idx++; $c_vals[] = $guest_email; }
            if (in_array('total_bookings', $c_cols)){ $c_fields[] = 'total_bookings'; $c_placeholders[] = '1'; }

            if (!empty($c_fields)) {
                $sql_ins_cust = "INSERT INTO customers (" . implode(", ", $c_fields) . ") VALUES (" . implode(", ", $c_placeholders) . ") RETURNING id";
                $res_ins_cust = @pg_query_params($conn, $sql_ins_cust, $c_vals);
                if ($res_ins_cust && $row_c = pg_fetch_assoc($res_ins_cust)) {
                    $customer_id = intval($row_c['id']);
                }
            }
        }
    }

    // 2. ดึงข้อมูลแพ
    $raft_res = @pg_query_params($conn, "SELECT name, price_per_day, price_per_hour FROM rafts WHERE id = $1 LIMIT 1", array($raft_id));
    $raft = ($raft_res && pg_num_rows($raft_res) > 0) ? pg_fetch_assoc($raft_res) : null;
    if (!$raft) { die("ไม่พบข้อมูลแพ"); }

    $raft_name      = $raft['name'];
    $price_per_day  = floatval($raft['price_per_day'] ?? 0);
    $price_per_hour = floatval($raft['price_per_hour'] ?? 0);

    // คำนวณวัน-เวลาเช็คเอาท์
    if ($booking_type === 'hourly') {
        $check_out_time = $_POST['check_out_time'] ?? '12:00';
        $check_out_date = $check_in_date;
        if (strtotime($check_out_time) <= strtotime($check_in_time)) {
            $check_out_date = date('Y-m-d', strtotime($check_in_date . ' +1 day'));
        }
        $diff_hrs = ceil((strtotime("$check_out_date $check_out_time:00") - strtotime("$check_in_date $check_in_time:00")) / 3600);
        if ($diff_hrs < 1) $diff_hrs = 1;
        $total_price = $diff_hrs * $price_per_hour;
    } else {
        $settings = [];
        $res_settings = @pg_query($conn, "SELECT setting_key, setting_value FROM settings");
        if ($res_settings) {
            while ($row = pg_fetch_assoc($res_settings)) { $settings[$row['setting_key']] = $row['setting_value']; }
        }
        $check_out_date = $check_in_date;
        $check_out_time = $settings['close_time'] ?? '17:30';
        $total_price    = $price_per_day;
    }

    // 3. สร้างรหัสการจอง
    $booking_code = 'BK' . date('ymd') . rand(100, 999);
    $booking_date = date('Y-m-d H:i:s');
    $status_id    = 1; // Pending
    $total_guests = intval($_POST['guests'] ?? $_POST['num_guests'] ?? 2);

    // ตรวจสอบคอลัมน์ของตาราง bookings
    $b_cols = [];
    $check_b = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
    if ($check_b) {
        while ($bc = pg_fetch_assoc($check_b)) { $b_cols[] = strtolower($bc['column_name']); }
    }

    $insert_fields = [];
    $insert_values = [];
    $params = [];
    $p_idx = 1;

    if (in_array('booking_code', $b_cols)) { $insert_fields[] = "booking_code"; $insert_values[] = '$' . $p_idx++; $params[] = $booking_code; }
    if (in_array('customer_id', $b_cols) && $customer_id > 0) { $insert_fields[] = "customer_id"; $insert_values[] = '$' . $p_idx++; $params[] = $customer_id; }
    if (in_array('user_id', $b_cols) && !empty($_SESSION['user_id'])) { $insert_fields[] = "user_id"; $insert_values[] = '$' . $p_idx++; $params[] = (int)$_SESSION['user_id']; }
    
    $sess_line_id = $_SESSION['line_user_id'] ?? $_SESSION['user_line_id'] ?? null;
    if (in_array('line_user_id', $b_cols) && !empty($sess_line_id)) { $insert_fields[] = "line_user_id"; $insert_values[] = '$' . $p_idx++; $params[] = $sess_line_id; }
    elseif (in_array('line_id', $b_cols) && !empty($sess_line_id)) { $insert_fields[] = "line_id"; $insert_values[] = '$' . $p_idx++; $params[] = $sess_line_id; }

    if (in_array('raft_id', $b_cols))   { $insert_fields[] = "raft_id"; $insert_values[] = '$' . $p_idx++; $params[] = $raft_id; }
    if (in_array('status_id', $b_cols)) { $insert_fields[] = "status_id"; $insert_values[] = '$' . $p_idx++; $params[] = $status_id; }
    if (in_array('status', $b_cols))    { $insert_fields[] = "status"; $insert_values[] = '$' . $p_idx++; $params[] = 'pending'; }

    if (in_array('booking_date', $b_cols)) { $insert_fields[] = "booking_date"; $insert_values[] = '$' . $p_idx++; $params[] = $booking_date; }
    if (in_array('created_at', $b_cols) && !in_array('booking_date', $b_cols)) { $insert_fields[] = "created_at"; $insert_values[] = "NOW()"; }

    // 🟢 บันทึกวันที่เข้าพักให้ครบทุกฟิลด์ (ป้องกันไม่ให้ตกเป็นวันที่ 3)
    if (in_array('check_in_date', $b_cols)) { $insert_fields[] = "check_in_date"; $insert_values[] = '$' . $p_idx++; $params[] = $check_in_date; }
    if (in_array('check_in_time', $b_cols)) { $insert_fields[] = "check_in_time"; $insert_values[] = '$' . $p_idx++; $params[] = $check_in_time; }
    if (in_array('check_in', $b_cols))      { $insert_fields[] = "check_in"; $insert_values[] = '$' . $p_idx++; $params[] = "{$check_in_date} {$check_in_time}:00"; }

    if (in_array('check_out_date', $b_cols)) { $insert_fields[] = "check_out_date"; $insert_values[] = '$' . $p_idx++; $params[] = $check_out_date; }
    if (in_array('check_out_time', $b_cols)) { $insert_fields[] = "check_out_time"; $insert_values[] = '$' . $p_idx++; $params[] = $check_out_time; }
    if (in_array('check_out', $b_cols))      { $insert_fields[] = "check_out"; $insert_values[] = '$' . $p_idx++; $params[] = "{$check_out_date} {$check_out_time}:00"; }

    if (in_array('total_guests', $b_cols)) { $insert_fields[] = "total_guests"; $insert_values[] = '$' . $p_idx++; $params[] = $total_guests; }
    elseif (in_array('num_guests', $b_cols)) { $insert_fields[] = "num_guests"; $insert_values[] = '$' . $p_idx++; $params[] = $total_guests; }

    if (in_array('total_amount', $b_cols)) { $insert_fields[] = "total_amount"; $insert_values[] = '$' . $p_idx++; $params[] = $total_price; }
    if (in_array('total_price', $b_cols))  { $insert_fields[] = "total_price"; $insert_values[] = '$' . $p_idx++; $params[] = $total_price; }
    if (in_array('raft_price', $b_cols))   { $insert_fields[] = "raft_price"; $insert_values[] = '$' . $p_idx++; $params[] = $total_price; }

    if (in_array('guest_name', $b_cols))  { $insert_fields[] = "guest_name"; $insert_values[] = '$' . $p_idx++; $params[] = $guest_name; }
    if (in_array('guest_tel', $b_cols))   { $insert_fields[] = "guest_tel"; $insert_values[] = '$' . $p_idx++; $params[] = $guest_tel; }
    if (in_array('guest_email', $b_cols)) { $insert_fields[] = "guest_email"; $insert_values[] = '$' . $p_idx++; $params[] = $guest_email; }

    $sql = "INSERT INTO bookings (" . implode(", ", $insert_fields) . ") VALUES (" . implode(", ", $insert_values) . ") RETURNING id";
    $result_insert = @pg_query_params($conn, $sql, $params);

    if ($result_insert && $row_ins = pg_fetch_assoc($result_insert)) {
        $booking_id = intval($row_ins['id']);

        // ❌ จุดที่เคยผิด: ตัดคำสั่ง UPDATE rafts SET status = 'pending' ทิ้งเด็ดขาด เพื่อไม่ให้แพทั้งลำถูกล็อก

        // ส่งแจ้งเตือน LINE
        if (function_exists('send_line_message')) {
            $site_host = $_SERVER['HTTP_HOST'] ?? 'raftdb.onrender.com';
            $admin_msg  = "🔔 มีรายการจองแพใหม่!\n━━━━━━━━━━━━━━━━\n📋 รหัส: {$booking_code} (#{$booking_id})\n⛵ แพ: {$raft_name}\n👤 ผู้จอง: {$guest_name}\n📞 เบอร์โทร: {$guest_tel}\n📅 วันที่: " . date('d/m/Y', strtotime($check_in_date)) . "\n⏰ เวลา: {$check_in_time} - {$check_out_time} น.\n💰 ยอดชำระ: ฿" . number_format($total_price, 2) . "\n━━━━━━━━━━━━━━━━\n👉 ดูในระบบ: https://{$site_host}/admin_dashboard.php";
            if (defined('LINE_ADMIN_USER_ID')) { send_line_message(LINE_ADMIN_USER_ID, $admin_msg); }

            if (!empty($sess_line_id)) {
                $cust_msg  = "🎉 ขอบคุณสำหรับการจองแพหนองกวาก!\n━━━━━━━━━━━━━━━━\n📋 รหัส: {$booking_code}\n⛵ แพ: {$raft_name}\n📅 วันที่เข้าพัก: " . date('d/m/Y', strtotime($check_in_date)) . "\n⏰ เวลา: {$check_in_time} - {$check_out_time} น.\n💰 ยอดชำระ: ฿" . number_format($total_price, 2) . "\n━━━━━━━━━━━━━━━━\n💳 บัญชี: ธ.กสิกรไทย 161-1-99653-1\nโปรดแนบสลิปเพื่อยืนยันคิวของคุณครับ 👇\nhttps://{$site_host}/booking_success.php?id={$booking_id}";
                send_line_message($sess_line_id, $cust_msg);
            }
        }

        header("Location: booking_success.php?id=" . $booking_id);
        exit();
    } else {
        echo "เกิดข้อผิดพลาดในการบันทึก: " . pg_last_error($conn);
    }
} else {
    header("Location: index.php");
    exit();
}
