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
    // ตั้งค่าไทม์โซนและตารางพื้นฐานถ้าเชื่อมต่อสำเร็จ
    @pg_query($conn, "SET timezone = 'Asia/Bangkok'");
    
    @pg_query($conn, "CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
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

// Query จำนวนรายการจองที่รอตรวจสอบสำหรับ Admin (ใช้ใน Sidebar)
$pending_bookings_count = 0;
if ($conn && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION["role"]) && $_SESSION["role"] === "admin") {
    $res_p = @pg_query($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE status = 'pending'");
    if ($res_p) {
        $row_p = pg_fetch_assoc($res_p);
        if ($row_p) {
            $pending_bookings_count = $row_p["cnt"];
        }
    }
}
?>
