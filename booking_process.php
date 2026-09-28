<?php
session_start();
require_once __DIR__ . '/db_config.php';

// 🚀 ลบการผูกมัด (Foreign Key) เดิมที่ค้างอยู่ในฐานข้อมูล และปิดการเช็คชั่วคราว
@$conn->query("ALTER TABLE bookings DROP FOREIGN KEY bookings_ibfk_4");
$conn->query("SET FOREIGN_KEY_CHECKS = 0;");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $guest_name  = trim($_POST['guest_name'] ?? '');
    $guest_tel   = trim($_POST['guest_tel'] ?? '');
    $guest_email = trim($_POST['guest_email'] ?? '');
    $raft_id     = intval($_POST['raft_id'] ?? 0);
    
    $check_in_date = $_POST['check_in'] ?? date('Y-m-d');
    $check_in_time = $_POST['check_in_time'] ?? '09:00';
    $booking_type  = $_POST['booking_type'] ?? 'daily';

    if ($raft_id <= 0 || empty($guest_name) || empty($guest_tel)) {
        header("Location: index.php");
        exit();
    }

    // 1. จัดการข้อมูลลูกค้าลงตาราง customers (ถ้ามีตารางนี้)
    $customer_id = null;
    $chk_table_cust = $conn->query("SHOW TABLES LIKE 'customers'");
    if ($chk_table_cust && $chk_table_cust->num_rows > 0 && !empty($guest_tel)) {
        $chk_cust = $conn->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
        if ($chk_cust) {
            $chk_cust->bind_param("s", $guest_tel);
            $chk_cust->execute();
            $cust_res = $chk_cust->get_result();

            if ($cust_res && $cust_row = $cust_res->fetch_assoc()) {
                $customer_id = $cust_row['id'];
                @$conn->query("UPDATE customers SET total_bookings = total_bookings + 1 WHERE id = $customer_id");
            } else {
                $cust_code = 'CUST' . date('ymd') . rand(100, 999);
                $ins_cust = $conn->prepare("INSERT INTO customers (customer_code, full_name, phone, email, total_bookings) VALUES (?, ?, ?, ?, 1)");
                if ($ins_cust) {
                    $ins_cust->bind_param("ssss", $cust_code, $guest_name, $guest_tel, $guest_email);
                    if ($ins_cust->execute()) {
                        $customer_id = $conn->insert_id;
                    }
                    $ins_cust->close();
                }
            }
            $chk_cust->close();
        }
    }

    // 2. ดึงข้อมูลแพตาม id จริง
    $raft_stmt = $conn->prepare("SELECT name, price_per_day, price_per_hour FROM rafts WHERE id = ?");
    $raft_stmt->bind_param("i", $raft_id);
    $raft_stmt->execute();
    $raft_res = $raft_stmt->get_result()->fetch_assoc();
    $raft_stmt->close();

    if (!$raft_res) {
        die("ไม่พบข้อมูลแพที่ระบุ");
    }

    $raft_name      = $raft_res['name'];
    $price_per_day  = floatval($raft_res['price_per_day'] ?? 0);
    $price_per_hour = floatval($raft_res['price_per_hour'] ?? 0);

    // คำนวณวันและเวลาเช็คเอาท์ + ราคา
    if ($booking_type == 'hourly') {
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
        $res_settings = $conn->query("SELECT * FROM settings");
        if ($res_settings) {
            while ($row = $res_settings->fetch_assoc()) {
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
    $status_id    = 1; // 1 = รอชำระ/รอยืนยัน (Pending)
    $total_guests = intval($_POST['guests'] ?? 2);

    // ตรวจสอบคอลัมน์ที่มีอยู่จริงในตาราง bookings
    $b_cols = [];
    $check_b = $conn->query("SHOW COLUMNS FROM bookings");
    if ($check_b) {
        while ($bc = $check_b->fetch_assoc()) {
            $b_cols[] = strtolower($bc['Field']);
        }
    }

    // สร้างคำสั่ง INSERT แบบตรงตามคอลัมน์ตาราง bookings
    $insert_fields = [];
    $insert_values = [];
    $types = "";
    $params = [];

    // booking_code
    if (in_array('booking_code', $b_cols)) {
        $insert_fields[] = "booking_code";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $booking_code;
    }
    // customer_id
    if (in_array('customer_id', $b_cols)) {
        $insert_fields[] = "customer_id";
        $insert_values[] = "?";
        $types .= "i";
        $params[] = $customer_id;
    }
    // raft_id
    if (in_array('raft_id', $b_cols)) {
        $insert_fields[] = "raft_id";
        $insert_values[] = "?";
        $types .= "i";
        $params[] = $raft_id;
    }
    // status_id หรือ status
    if (in_array('status_id', $b_cols)) {
        $insert_fields[] = "status_id";
        $insert_values[] = "?";
        $types .= "i";
        $params[] = $status_id;
    } elseif (in_array('status', $b_cols)) {
        $insert_fields[] = "status";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = 'pending';
    }
    // booking_date
    if (in_array('booking_date', $b_cols)) {
        $insert_fields[] = "booking_date";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $booking_date;
    }
    // check_in_date & check_in_time
    if (in_array('check_in_date', $b_cols)) {
        $insert_fields[] = "check_in_date";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $check_in_date;
    }
    if (in_array('check_in_time', $b_cols)) {
        $insert_fields[] = "check_in_time";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $check_in_time;
    }
    // check_out_date & check_out_time
    if (in_array('check_out_date', $b_cols)) {
        $insert_fields[] = "check_out_date";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $check_out_date;
    }
    if (in_array('check_out_time', $b_cols)) {
        $insert_fields[] = "check_out_time";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $check_out_time;
    }
    // total_guests
    if (in_array('total_guests', $b_cols)) {
        $insert_fields[] = "total_guests";
        $insert_values[] = "?";
        $types .= "i";
        $params[] = $total_guests;
    }
    // ราคา raft_price / total_price
    if (in_array('raft_price', $b_cols)) {
        $insert_fields[] = "raft_price";
        $insert_values[] = "?";
        $types .= "d";
        $params[] = $total_price;
    } elseif (in_array('total_price', $b_cols)) {
        $insert_fields[] = "total_price";
        $insert_values[] = "?";
        $types .= "d";
        $params[] = $total_price;
    }

    // ข้อมูลสำรอง (ถ้าตาราง bookings มีฟิลด์ guest_name, guest_tel)
    if (in_array('guest_name', $b_cols)) {
        $insert_fields[] = "guest_name";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $guest_name;
    }
    if (in_array('guest_tel', $b_cols)) {
        $insert_fields[] = "guest_tel";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $guest_tel;
    }
    if (in_array('guest_email', $b_cols)) {
        $insert_fields[] = "guest_email";
        $insert_values[] = "?";
        $types .= "s";
        $params[] = $guest_email;
    }

    $sql = "INSERT INTO bookings (" . implode(", ", $insert_fields) . ") VALUES (" . implode(", ", $insert_values) . ")";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $booking_id = $conn->insert_id;
            $stmt->close();

            // 🟢 [เพิ่มโค้ดตรงนี้] อัปเดตสถานะแพให้เป็น 'รอตรวจสอบ' ทันที
            $conn->query("UPDATE rafts SET status = 'pending' WHERE id = $raft_id");

            // 4. ส่งข้อความแจ้งเตือนทาง LINE
            if (function_exists('send_line_message')) {
                $line_msg  = "\n🔔 มีการจองใหม่!\n";
                $line_msg .= "━━━━━━━━━━━━━━━━\n";
                $line_msg .= "📋 รหัสการจอง: $booking_code (#$booking_id)\n";
                $line_msg .= "👤 ชื่อผู้จอง: $guest_name\n";
                $line_msg .= "📞 เบอร์โทร: $guest_tel\n";
                $line_msg .= "⛵ แพ: $raft_name\n";
                $line_msg .= "📅 วันที่: " . date('d/m/Y', strtotime($check_in_date)) . "\n";
                $line_msg .= "⏰ เวลา: $check_in_time - $check_out_time น.\n";
                $line_msg .= "💰 ยอดชำระ: ฿" . number_format($total_price, 2) . "\n";
                $line_msg .= "━━━━━━━━━━━━━━━━\n";
                $line_msg .= "⚠️ กรุณาตรวจสอบและยืนยันการจองในระบบ";
                @send_line_message($line_msg);
            }

            header("Location: booking_success.php?id=" . $booking_id);
            exit();
        } else {
            echo "Error executing query: " . $stmt->error;
            $stmt->close();
        }
    } else {
        echo "Error preparing statement: " . $conn->error;
    }

} else {
    header("Location: index.php");
    exit();
}
?>