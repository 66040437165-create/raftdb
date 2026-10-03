<?php
session_start();

// รองรับ path ไฟล์ db_config.php ทั้งในโฟลเดอร์เดียวกันและโฟลเดอร์หลัก
if (file_exists(__DIR__ . '/db_config.php')) {
    require_once __DIR__ . '/db_config.php';
} else {
    require_once __DIR__ . '/../db_config.php';
}

// โหลดไฟล์ line_helper.php สำหรับส่งแจ้งเตือน LINE
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
    
    $check_in_date = trim($_POST['check_in'] ?? date('Y-m-d'));
    $check_in_time = trim($_POST['check_in_time'] ?? '09:00');
    $booking_type  = trim($_POST['booking_type'] ?? 'daily');

    if ($raft_id <= 0 || empty($guest_name) || empty($guest_tel)) {
        header("Location: index.php");
        exit();
    }

    // 1. ตรวจสอบว่ามีตาราง customers หรือไม่ (PostgreSQL Syntax)
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

        // ค้นหาลูกค้าเดิมจากเบอร์โทร
        $cust_res = @pg_query_params($conn, "SELECT id FROM customers WHERE phone = $1 LIMIT 1", array($guest_tel));
        if ($cust_res && pg_num_rows($cust_res) > 0) {
            $cust_row = pg_fetch_assoc($cust_res);
            $customer_id = intval($cust_row['id']);
            if (in_array('total_bookings', $c_cols)) {
                @pg_query_params($conn, "UPDATE customers SET total_bookings = total_bookings + 1 WHERE id = $1", array($customer_id));
            }
        } else {
            // สร้างลูกค้าใหม่
            $cust_code = 'CUST' . date('ymd') . rand(100, 999);
            $c_fields = [];
            $c_placeholders = [];
            $c_vals = [];
            $cp_idx = 1;

            if (in_array('customer_code', $c_cols)) {
                $c_fields[] = 'customer_code';
                $c_placeholders[] = '$' . $cp_idx++;
                $c_vals[] = $cust_code;
            }
            if (in_array('full_name', $c_cols)) {
                $c_fields[] = 'full_name';
                $c_placeholders[] = '$' . $cp_idx++;
                $c_vals[] = $guest_name;
            }
            if (in_array('phone', $c_cols)) {
                $c_fields[] = 'phone';
                $c_placeholders[] = '$' . $cp_idx++;
                $c_vals[] = $guest_tel;
            }
            if (in_array('email', $c_cols)) {
                $c_fields[] = 'email';
                $c_placeholders[] = '$' . $cp_idx++;
                $c_vals[] = $guest_email;
            }
            if (in_array('total_bookings', $c_cols)) {
                $c_fields[] = 'total_bookings';
                $c_placeholders[] = '1';
            }

            if (!empty($c_fields)) {
                $sql_ins_cust = "INSERT INTO customers (" . implode(", ", $c_fields) . ") VALUES (" . implode(", ", $c_placeholders) . ") RETURNING id";
                $res_ins_cust = @pg_query_params($conn, $sql_ins_cust, $c_vals);
                if ($res_ins_cust && $row_c = pg_fetch_assoc($res_ins_cust)) {
                    $customer_id = intval($row_c['id']);
                }
            }
        }
    }

    // 2. ดึงข้อมูลแพตาม id
    $raft_res = @pg_query_params($conn, "SELECT name, price_per_day, price_per_hour FROM rafts WHERE id = $1 LIMIT 1", array($raft_id));
    $raft = ($raft_res && pg_num_rows($raft_res) > 0) ? pg_fetch_assoc($raft_res) : null;

    if (!$raft) {
        die("ไม่พบข้อมูลแพที่ระบุ");
    }

    $raft_name      = $raft['name'];
    $price_per_day  = floatval($raft['price_per_day'] ?? 0);
    $price_per_hour = floatval($raft['price_per_hour'] ?? 0);

    // คำนวณวันและเวลาเช็คเอาท์ + ราคา
    if ($booking_type === 'hourly') {
        $check_out_time = $_POST['check_out_time'] ?? '12:00';
        $check_out_date = $check_in_date;
        
        if (strtotime($check_out_time) <= strtotime($check_in_time)) {
            $check_out_date = date('Y-m-d', strtotime($check_in_date . ' +1 day'));
        }
        
        $start_dt = strtotime("$check_in_date $check_in_time:00");
        $end_dt   = strtotime("$check_out_date $check_out_time:00");
        $diff_hrs = ceil(($end_dt - $start_dt) / 3600);
        if ($diff_hrs < 1) $diff_hrs = 1;
        
        $total_price = $diff_hrs * $price_per_hour;
    } else {
        // รายวัน: ดึงเวลาปิดจาก settings
        $settings = [];
        $res_settings = @pg_query($conn, "SELECT setting_key, setting_value FROM settings");
        if ($res_settings) {
            while ($row = pg_fetch_assoc($res_settings)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        $close_time = $settings['close_time'] ?? '17:30';

        $check_out_date = $check_in_date;
        $check_out_time = $close_time;
        $total_price    = $price_per_day;
    }

    // 3. สร้างรหัสการจอง (booking_code)
    $booking_code = 'BK' . date('ymd') . rand(100, 999);
    $booking_date = date('Y-m-d H:i:s');
    $status_id    = 1; // 1 = รอตรวจสอบ (Pending)
    $total_guests = intval($_POST['guests'] ?? $_POST['num_guests'] ?? 2);

    // ตรวจสอบคอลัมน์ที่มีอยู่จริงในตาราง bookings
    $b_cols = [];
    $check_b = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
    if ($check_b) {
        while ($bc = pg_fetch_assoc($check_b)) {
            $b_cols[] = strtolower($bc['column_name']);
        }
    }

    // สร้างคำสั่ง INSERT แบบ Dynamic ตรงตามโครงสร้างคอลัมน์
    $insert_fields = [];
    $insert_values = [];
    $params = [];
    $p_idx = 1;

    // booking_code
    if (in_array('booking_code', $b_cols)) {
        $insert_fields[] = "booking_code";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $booking_code;
    }
    // customer_id
    if (in_array('customer_id', $b_cols) && $customer_id > 0) {
        $insert_fields[] = "customer_id";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $customer_id;
    }
    // user_id (ถ้ามีผู้ใช้ล็อกอินอยู่)
    if (in_array('user_id', $b_cols) && !empty($_SESSION['user_id'])) {
        $insert_fields[] = "user_id";
        $insert_values[] = '$' . $p_idx++;
        $params[] = (int)$_SESSION['user_id'];
    }
    // line_user_id หรือ line_id
    $sess_line_id = $_SESSION['line_user_id'] ?? $_SESSION['user_line_id'] ?? null;
    if (in_array('line_user_id', $b_cols) && !empty($sess_line_id)) {
        $insert_fields[] = "line_user_id";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $sess_line_id;
    } elseif (in_array('line_id', $b_cols) && !empty($sess_line_id)) {
        $insert_fields[] = "line_id";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $sess_line_id;
    }
    // raft_id
    if (in_array('raft_id', $b_cols)) {
        $insert_fields[] = "raft_id";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $raft_id;
    }
    // status_id หรือ status
    if (in_array('status_id', $b_cols)) {
        $insert_fields[] = "status_id";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $status_id;
    }
    if (in_array('status', $b_cols)) {
        $insert_fields[] = "status";
        $insert_values[] = '$' . $p_idx++;
        $params[] = 'pending';
    }
    // booking_date หรือ created_at
    if (in_array('booking_date', $b_cols)) {
        $insert_fields[] = "booking_date";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $booking_date;
    }
    if (in_array('created_at', $b_cols) && !in_array('booking_date', $b_cols)) {
        $insert_fields[] = "created_at";
        $insert_values[] = "NOW()";
    }
    // check_in_date & check_in_time
    if (in_array('check_in_date', $b_cols)) {
        $insert_fields[] = "check_in_date";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $check_in_date;
    }
    if (in_array('check_in_time', $b_cols)) {
        $insert_fields[] = "check_in_time";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $check_in_time;
    }
    // check_out_date & check_out_time
    if (in_array('check_out_date', $b_cols)) {
        $insert_fields[] = "check_out_date";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $check_out_date;
    }
    if (in_array('check_out_time', $b_cols)) {
        $insert_fields[] = "check_out_time";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $check_out_time;
    }
    // total_guests หรือ num_guests
    if (in_array('total_guests', $b_cols)) {
        $insert_fields[] = "total_guests";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $total_guests;
    } elseif (in_array('num_guests', $b_cols)) {
        $insert_fields[] = "num_guests";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $total_guests;
    }

    // ราคา
    if (in_array('total_amount', $b_cols)) {
        $insert_fields[] = "total_amount";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $total_price;
    }
    if (in_array('total_price', $b_cols)) {
        $insert_fields[] = "total_price";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $total_price;
    }
    if (in_array('raft_price', $b_cols)) {
        $insert_fields[] = "raft_price";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $total_price;
    }

    // ข้อมูลลูกค้า
    if (in_array('guest_name', $b_cols)) {
        $insert_fields[] = "guest_name";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $guest_name;
    }
    if (in_array('guest_tel', $b_cols)) {
        $insert_fields[] = "guest_tel";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $guest_tel;
    }
    if (in_array('guest_email', $b_cols)) {
        $insert_fields[] = "guest_email";
        $insert_values[] = '$' . $p_idx++;
        $params[] = $guest_email;
    }

    $sql = "INSERT INTO bookings (" . implode(", ", $insert_fields) . ") VALUES (" . implode(", ", $insert_values) . ") RETURNING id";
    $result_insert = @pg_query_params($conn, $sql, $params);

    if ($result_insert && $row_ins = pg_fetch_assoc($result_insert)) {
        $booking_id = intval($row_ins['id']);

        // 🟢 หมายเหตุ: ไม่ต้อง UPDATE rafts ให้เป็น pending แล้ว 
        // ปล่อยให้สถานะแพคงเดิม เพื่อให้หน้า index.php ตรวจสอบคิวว่างตามวันที่จริงผ่านตาราง bookings

        // 4. ส่งข้อความแจ้งเตือนทาง LINE
        if (function_exists('send_line_message')) {
            $site_host = $_SERVER['HTTP_HOST'] ?? 'raftdb.onrender.com';

            // 4.1 ข้อความแจ้งเตือนสำหรับ "แอดมิน"
            $admin_msg  = "🔔 มีรายการจองแพใหม่!\n";
            $admin_msg .= "━━━━━━━━━━━━━━━━\n";
            $admin_msg .= "📋 รหัสการจอง: {$booking_code} (#{$booking_id})\n";
            $admin_msg .= "⛵ แพ: {$raft_name}\n";
            $admin_msg .= "👤 ผู้จอง: {$guest_name}\n";
            $admin_msg .= "📞 เบอร์โทร: {$guest_tel}\n";
            $admin_msg .= "📅 วันที่เข้าพัก: " . date('d/m/Y', strtotime($check_in_date)) . "\n";
            $admin_msg .= "⏰ เวลา: {$check_in_time} - {$check_out_time} น.\n";
            $admin_msg .= "💰 ยอดชำระ: ฿" . number_format($total_price, 2) . "\n";
            $admin_msg .= "━━━━━━━━━━━━━━━━\n";
            $admin_msg .= "👉 ตรวจสอบในระบบหลังบ้าน: https://{$site_host}/admin_dashboard.php";

            if (defined('LINE_ADMIN_USER_ID')) {
                send_line_message(LINE_ADMIN_USER_ID, $admin_msg);
            }

            // 4.2 ข้อความยืนยันสำหรับ "ลูกค้า" (กรณีลูกค้าล็อกอินผ่าน LINE)
            $customer_line_id = $_SESSION['line_user_id'] ?? $_SESSION['user_line_id'] ?? null;
            if (!empty($customer_line_id)) {
                $customer_msg  = "🎉 ขอบคุณสำหรับการจอง ล่องแพหนองกวาก!\n";
                $customer_msg .= "━━━━━━━━━━━━━━━━\n";
                $customer_msg .= "📋 รหัสการจอง: {$booking_code}\n";
                $customer_msg .= "⛵ แพที่จอง: {$raft_name}\n";
                $customer_msg .= "📅 วันที่เข้าพัก: " . date('d/m/Y', strtotime($check_in_date)) . "\n";
                $customer_msg .= "⏰ เวลา: {$check_in_time} - {$check_out_time} น.\n";
                $customer_msg .= "💰 ยอดชำระ: ฿" . number_format($total_price, 2) . "\n";
                $customer_msg .= "━━━━━━━━━━━━━━━━\n";
                $customer_msg .= "💳 บัญชีโอนเงิน: ธ.กสิกรไทย 012-3-45678-9\n";
                $customer_msg .= "โปรดแนบสลิปเพื่อยืนยันคิวของคุณครับ 👇\n";
                $customer_msg .= "https://{$site_host}/booking_success.php?id={$booking_id}";

                send_line_message($customer_line_id, $customer_msg);
            }
        }

        header("Location: booking_success.php?id=" . $booking_id);
        exit();
    } else {
        echo "เกิดข้อผิดพลาดในการบันทึกการจอง: " . pg_last_error($conn);
    }

} else {
    header("Location: index.php");
    exit();
}
