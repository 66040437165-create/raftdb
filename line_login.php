<?php
session_start();
require_once __DIR__ . '/db_config.php';

// ตรวจสอบว่ามีการตั้งค่า LINE Constants ใน db_config.php หรือยัง
if (!defined('LINE_CLIENT_ID') || !defined('LINE_CLIENT_SECRET') || !defined('LINE_REDIRECT_URI') ||
    empty(LINE_CLIENT_ID) || LINE_CLIENT_SECRET === 'ใส่_Channel_Secret_ตรงนี้') {
    die("กรุณาตั้งค่า Channel ID, Channel Secret และ LINE_REDIRECT_URI ในไฟล์ db_config.php ให้ถูกต้องก่อนใช้งาน");
}

// สร้าง State ป้องกัน CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['line_state'] = $state;

// สร้าง URL สำหรับ Redirect ไปยังหน้าล็อกอินของ LINE
$authorize_url = "https://access.line.me/oauth2/v2.1/authorize?" . http_build_query([
    'response_type' => 'code',
    'client_id'     => LINE_CLIENT_ID,
    'redirect_uri'  => LINE_REDIRECT_URI,
    'state'         => $state,
    'scope'         => 'profile openid',
]);

header("Location: " . $authorize_url);
exit();
