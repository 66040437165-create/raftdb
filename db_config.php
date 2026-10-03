<?php
// ตั้งค่า Base URL สำหรับรันบน Render
$base_url = "https://" . $_SERVER['HTTP_HOST'] . "/";

// ดึงค่า DATABASE_URL จาก Environment Variable ของ Render
$database_url = getenv("DATABASE_URL");

$conn = null;
if ($database_url) {
    $db = parse_url($database_url);
    $servername = $db["host"] ?? "";
    $username   = $db["user"] ?? "";
    $password   = $db["pass"] ?? "";
    $dbname     = ltrim($db["path"] ?? "", "/");
    $port       = $db["port"] ?? "5432";

    $conn_string = "host=$servername port=$port dbname=$dbname user=$username password=$password";
    $conn = @pg_connect($conn_string);
}

// ถ้ายังต่อไม่ได้ ให้ข้ามไปก่อนเพื่อไม่ให้เว็บ Fatal Error ทันที
if (!$conn) {
    $conn = null; 
} else {
    // 1. ตั้งค่าไทม์โซนประเทศไทย
    @pg_query($conn, "SET timezone = 'Asia/Bangkok'");
    
    // 2. สร้างตาราง settings พื้นฐาน
    @pg_query($conn, "CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 🟢 3. ปลดล็อกสถานะแพทุกลำให้พร้อมให้บริการ
    @pg_query($conn, "UPDATE rafts SET status = 'available' WHERE status IS NULL OR LOWER(TRIM(status)) = 'pending'");

    // 🟢 4. ตรวจสอบและเพิ่มคอลัมน์วันที่ที่จำเป็นในตาราง bookings
    @pg_query($conn, "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS check_in_date DATE");
    @pg_query($conn, "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS check_in_time VARCHAR(20)");
    @pg_query($conn, "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS check_out_date DATE");
    @pg_query($conn, "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS check_out_time VARCHAR(20)");
    @pg_query($conn, "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS check_in TIMESTAMP");
    @pg_query($conn, "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS check_out TIMESTAMP");

    // 🟢 5. แก้ไขข้อมูลการจองเดิมที่บันทึกวันผิด
    @pg_query($conn, "UPDATE bookings 
                      SET check_in = (check_in_date || ' ' || COALESCE(check_in_time, '09:00'))::timestamp 
                      WHERE check_in_date IS NOT NULL 
                        AND check_in IS NOT NULL 
                        AND check_in::date != check_in_date");

    @pg_query($conn, "UPDATE bookings 
                      SET check_in_date = check_in::date 
                      WHERE check_in_date IS NULL AND check_in IS NOT NULL");

    // 🟢 6. ปรับคอลัมน์รูปภาพในตาราง rafts ให้เป็น TEXT ถาวร
    @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN featured_image TYPE TEXT");
    @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN image_1 TYPE TEXT");
    @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN image_2 TYPE TEXT");
    @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN image_3 TYPE TEXT");
    @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN image_4 TYPE TEXT");
    @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN image_5 TYPE TEXT");

    // 🟢 7. กู้คืนรูปภาพแพ (รันเฉพาะตอนที่รูปเป็นค่าว่าง และปลอดภัยไม่เขียนทับมั่ว)
    // 7.1 ดึงรูปจาก image_1 กลับมาใส่ featured_image สำหรับลำที่ยังมีค่าใน image_1
    @pg_query($conn, "UPDATE rafts 
                      SET featured_image = image_1 
                      WHERE (featured_image IS NULL OR featured_image = '' OR featured_image LIKE '%unsplash%') 
                        AND (image_1 IS NOT NULL AND image_1 != '' AND image_1 NOT LIKE '%unsplash%')");

    // 7.2 ค้นหารูปแพจริงลำใดก็ได้ที่มีอยู่ในระบบ มาใส่ให้ลำที่รูปยังว่างอยู่
    $chk_any_img = @pg_query($conn, "SELECT image_1 FROM rafts WHERE image_1 IS NOT NULL AND image_1 != '' AND image_1 NOT LIKE '%unsplash%' LIMIT 1");
    if ($chk_any_img && $img_row = pg_fetch_assoc($chk_any_img)) {
        $found_real_img = $img_row['image_1'];
        if (!empty($found_real_img)) {
            @pg_query_params($conn, "UPDATE rafts SET featured_image = $1 WHERE featured_image IS NULL OR featured_image = '' OR featured_image LIKE '%unsplash%'", array($found_real_img));
        }
    }
}

// --- ตั้งค่า LINE Login (สำหรับเข้าสู่ระบบบนเว็บ) ---
if (!defined("LINE_CLIENT_ID")) define("LINE_CLIENT_ID", "2010677463"); 
if (!defined("LINE_CLIENT_SECRET")) define("LINE_CLIENT_SECRET", "3684f4e6a269259f388538a275957873"); 
if (!defined("LINE_REDIRECT_URI")) define("LINE_REDIRECT_URI", $base_url . "line_callback.php"); 

// --- โหลดระบบแจ้งเตือน LINE จาก line_helper.php ---
if (file_exists(__DIR__ . '/line_helper.php')) {
    require_once __DIR__ . '/line_helper.php';
} elseif (file_exists(__DIR__ . '/../line_helper.php')) {
    require_once __DIR__ . '/../line_helper.php';
}

// Query จำนวนรายการจองที่รอตรวจสอบสำหรับ Admin (ใช้ใน Sidebar และ Header)
$pending_bookings_count = 0;
if ($conn && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION["role"]) && strtolower($_SESSION["role"]) === "admin") {
    $res_p = @pg_query($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE status = 'pending' OR status_id = 1");
    if ($res_p) {
        $row_p = pg_fetch_assoc($res_p);
        if ($row_p) {
            $pending_bookings_count = intval($row_p["cnt"]);
        }
    }
}
?>
