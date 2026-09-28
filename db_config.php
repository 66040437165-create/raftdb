<?php
// ตั้งค่า Base URL สำหรับรันบน Render
$base_url = "https://" . $_SERVER['HTTP_HOST'] . "/";

// ดึงค่า DATABASE_URL จาก Environment Variable ของ Render
$database_url = getenv("DATABASE_URL");

if ($database_url) {
    $db = parse_url($database_url);
    $servername = $db["host"] ?? "";
    $username = $db["user"] ?? "";
    $password = $db["pass"] ?? "";
    $dbname = ltrim($db["path"] ?? "", "/");
    $port = $db["port"] ?? "5432";
} else {
    // ค่า Fallback สำหรับเทสบน Local (ถ้าจำเป็น)
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "project";
    $port = "5432";
}

// 1. สร้างการเชื่อมต่อ PostgreSQL ด้วย pg_connect
if (!isset($conn) || !$conn) {
    $conn_string = "host=$servername port=$port dbname=$dbname user=$username password=$password";
    $conn = @pg_connect($conn_string);
    
    // ตรวจสอบการเชื่อมต่อ
    if (!$conn) {
        $error_msg = pg_last_error();
        die("<div style='font-family:Sarabun,sans-serif; padding:20px; background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:12px; margin:20px;'>
                <h2>❌ ไม่สามารถเชื่อมต่อฐานข้อมูล PostgreSQL ได้ (Database Connection Error)</h2>
                <p><b>สาเหตุ:</b> " . htmlspecialchars($error_msg) . "</p>
                <p>กรุณาตรวจสอบ Environment Variables <code>DATABASE_URL</code> บน Render อีกครั้ง</p>
             </div>");
    }

    // 2. ตั้งค่าไทม์โซนระบบให้ตรงกัน
    date_default_timezone_set('Asia/Bangkok');
    @pg_query($conn, "SET timezone = 'Asia/Bangkok'");

    // 3. ตรวจสอบและสร้างตาราง settings พื้นฐาน (PostgreSQL Syntax)
    @pg_query($conn, "CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $defaults = [
        'open_time' => '09:00',
        'close_time' => '17:30',
        'business_name' => 'ล่องแพหนองกวาก'
    ];

    foreach ($defaults as $key => $val) {
        // ใช้ INSERT ... ON CONFLICT สำหรับ PostgreSQL
        $escaped_key = pg_escape_string($conn, $key);
        $escaped_val = pg_escape_string($conn, $val);
        @pg_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('$escaped_key', '$escaped_val') ON CONFLICT (setting_key) DO NOTHING");
    }
}

// --- ตั้งค่า LINE Login ---
if (!defined('LINE_CLIENT_ID')) define('LINE_CLIENT_ID', '2010677463'); 
if (!defined('LINE_CLIENT_SECRET')) define('LINE_CLIENT_SECRET', '3684f4e6a269259f388538a275957873'); 
if (!defined('LINE_REDIRECT_URI')) define('LINE_REDIRECT_URI', $base_url . 'line_callback.php'); 

// --- ตั้งค่า LINE Messaging API ---
if (!defined('LINE_BOT_ACCESS_TOKEN')) define('LINE_BOT_ACCESS_TOKEN', 'C9RIGCTTHW24CjZEyy1glVBPhSLBx15Kb48CQ+qIJX1NZiH3NeQte32Tp1C5zQD2dJIdi8ud55keeFkkcMwlpRsM8CEJHKPiphK1Lzl1NcOQlIyPEdCOjtRpkZYskqbnSDSGiRhCGPM7w3BdJ9ZTXQdB04t89/1O/w1cDnyilFU='); 
if (!defined('LINE_NOTIFY_TARGET_ID')) define('LINE_NOTIFY_TARGET_ID', 'Uc363e24c7774830ce61b1995d0b11e9d'); 

/**
 * ฟังก์ชันสำหรับส่งข้อความแจ้งเตือนเข้าสู่ LINE
 */
if (!function_exists('send_line_message')) {
    function send_line_message($message_text, $custom_target_id = null) {
        $target_id = (!empty($custom_target_id)) ? $custom_target_id : LINE_NOTIFY_TARGET_ID;

        if (LINE_BOT_ACCESS_TOKEN === '' || empty($target_id)) {
            return false; 
        }

        $url = 'https://api.line.me/v2/bot/message/push';
        $data = [
            'to' => $target_id,
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $message_text
                ]
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: json',
            'Authorization: Bearer ' . LINE_BOT_ACCESS_TOKEN
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}

// Query จำนวนรายการจองที่รอตรวจสอบสำหรับ Admin (ใช้ใน Sidebar)
$pending_bookings_count = 0;
if (isset($conn) && $conn && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $res_p = @pg_query($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE status = 'pending'");
    if ($res_p) {
        $row_p = pg_fetch_assoc($res_p);
        if ($row_p) {
            $pending_bookings_count = $row_p['cnt'];
        }
    }
}
?>
