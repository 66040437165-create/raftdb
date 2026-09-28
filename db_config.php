<?php
// ตั้งค่าการเชื่อมต่อฐานข้อมูล
$base_url = "http://localhost/projectpa/";
$servername = "localhost";
$username = "root";
$password = ""; 
$dbname = "project"; // แก้ไขชื่อฐานข้อมูลให้ตรงกับ phpMyAdmin

// 1. สร้างการเชื่อมต่อ (Connection) ด้วยระบบ Fallback เพื่อความเสถียร 100%
if (!isset($conn) || !($conn instanceof mysqli)) {
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = @new mysqli($servername, $username, $password, $dbname);
    
    // หาก localhost เชื่อมต่อไม่ได้ ให้ลองใช้ 127.0.0.1 เป็น Fallback
    if ($conn->connect_error) {
        $conn = @new mysqli("127.0.0.1", $username, $password, $dbname);
    }
    
    // ตรวจสอบการเชื่อมต่อขั้นสุดท้าย
    if ($conn->connect_error) {
        die("<div style='font-family:Sarabun,sans-serif; padding:20px; background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:12px; margin:20px;'>
                <h2>❌ ไม่สามารถเชื่อมต่อฐานข้อมูลได้ (Database Connection Error)</h2>
                <p><b>สาเหตุ:</b> " . htmlspecialchars($conn->connect_error) . "</p>
                <p>กรุณาตรวจสอบว่า <b>Apache & MySQL ใน XAMPP Control Panel</b> เปิดทำงานอยู่ และชื่อฐานข้อมูลคือ <code>" . htmlspecialchars($dbname) . "</code></p>
             </div>");
    }

    // 2. ตั้งค่าภาษาและไทม์โซนระบบให้ตรงกันทุกหน้า
    $conn->set_charset("utf8mb4");
    date_default_timezone_set('Asia/Bangkok');
    $conn->query("SET time_zone = '+07:00'");

    // 3. ตรวจสอบการสร้างตารางตั้งค่าพื้นฐาน
    $conn->query("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $defaults = [
        'open_time' => '09:00',
        'close_time' => '17:30',
        'business_name' => 'ล่องแพหนองกวาก'
    ];

    foreach ($defaults as $key => $val) {
        $conn->query("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('$key', '$val')");
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
            'Content-Type: application/json',
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
if (isset($conn) && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $res_p = $conn->query("SELECT COUNT(*) as cnt FROM bookings WHERE status = 'pending'");
    if ($res_p && $row_p = $res_p->fetch_assoc()) {
        $pending_bookings_count = $row_p['cnt'];
    }
}
?>