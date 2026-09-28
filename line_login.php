<?php
session_start();
require_once __DIR__ . '/db_config.php';

// ป้องกันหากยังไม่ได้กรอกค่า Channel ID
if (LINE_CLIENT_ID === '' || LINE_CLIENT_ID === '2010677463' && LINE_CLIENT_SECRET === 'ใส่_Channel_Secret_ตรงนี้') {
    die("กรุณาตั้งค่า Channel ID และ Channel Secret ในไฟล์ db_config.php ก่อนใช้งาน LINE Login");
}

// สร้าง State เพื่อความปลอดภัยกัน CSRF (Cross-Site Request Forgery)
$state = bin2hex(random_bytes(16));
$_SESSION['line_state'] = $state;

// ลิงก์สำหรับเปลี่ยนเส้นทางผู้ใช้ไปหน้าขออนุญาตสิทธิ์ของ LINE
$authorize_url = "https://access.line.me/oauth2/v2.1/authorize?" . http_build_query([
    'response_type' => 'code',
    'client_id'     => LINE_CLIENT_ID,
    'redirect_uri'  => LINE_REDIRECT_URI,
    'state'         => $state,
    'scope'         => 'profile openid',
]);

header("Location: " . $authorize_url);
exit();
?>
