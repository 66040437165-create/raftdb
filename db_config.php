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

if ($conn) {
    // 1. ตั้งค่าไทม์โซนประเทศไทย (คงไว้เสมอ)
    @pg_query($conn, "SET timezone = 'Asia/Bangkok'");
    
    // หมายเหตุ: คำสั่ง ALTER TABLE และ UPDATE กู้คืนข้อมูลทำงานสำเร็จไปแล้ว 
    // จึงตัดออกเพื่อเพิ่มความเร็วในการโหลดหน้าเว็บ
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
