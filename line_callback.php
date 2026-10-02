<?php
session_start();
require_once __DIR__ . '/db_config.php';

// 1. ตรวจสอบค่า State ป้องกัน CSRF
if (!isset($_GET['state']) || $_GET['state'] !== ($_SESSION['line_state'] ?? '')) {
    die("ความถูกต้องของ Session ล้มเหลว (State Mismatch) กรุณาลองใหม่อีกครั้ง");
}

// ล้าง State ใน session ออกเมื่อผ่านการตรวจสอบ
unset($_SESSION['line_state']);

// 2. ตรวจสอบว่าได้ Authorization Code กลับมาไหม
if (!isset($_GET['code'])) {
    die("การเข้าสู่ระบบถูกปฏิเสธ หรือ ไม่ได้รับอนุญาตจากผู้ใช้งาน LINE");
}

$code = $_GET['code'];

// 3. แลกเปลี่ยน Code เพื่อเอา Access Token
$token_url = "https://api.line.me/oauth2/v2.1/token";
$post_data = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => LINE_REDIRECT_URI,
    'client_id'     => LINE_CLIENT_ID,
    'client_secret' => LINE_CLIENT_SECRET
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($response, true);

if (!isset($token_data['access_token'])) {
    die("เกิดข้อผิดพลาดในการแลกเปลี่ยน Token กับ LINE API: " . htmlspecialchars($response));
}

$access_token = $token_data['access_token'];

// 4. ใช้ Access Token ดึงข้อมูลโปรไฟล์ผู้ใช้ LINE
$profile_url = "https://api.line.me/v2/profile";
$headers = [
    "Authorization: Bearer " . $access_token
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $profile_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$profile_response = curl_exec($ch);
curl_close($ch);

$profile = json_decode($profile_response, true);

if (!isset($profile['userId'])) {
    die("ไม่สามารถดึงข้อมูลโปรไฟล์จาก LINE API ได้");
}

$line_id      = $profile['userId'];
$display_name = $profile['displayName'] ?? 'LINE User';
$picture_url  = $profile['pictureUrl'] ?? '';

// 5. ตรวจสอบในฐานข้อมูลว่ามีผู้ใช้ที่เคยผูก LINE ID นี้ไว้แล้วหรือยัง (PostgreSQL)
if ($conn) {
    $query = "SELECT * FROM users WHERE line_id = $1 LIMIT 1";
    $res = @pg_query_params($conn, $query, array($line_id));

    if ($res && pg_num_rows($res) > 0) {
        // ผู้ใช้เคยล็อกอินด้วย LINE มาก่อนแล้ว -> ล็อกอินเข้าสู่ระบบทันที
        $user = pg_fetch_assoc($res);
        
        $_SESSION['user_id']  = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $_SESSION['username'] = $user['username'] ?? '';
        $_SESSION['fullname'] = $user['fullname'] ?? $user['full_name'] ?? $display_name;
        $_SESSION['role']     = strtolower($user['role'] ?? 'customer');
        
        if ($_SESSION['role'] === 'admin') {
            header("Location: admin_dashboard.php");
        } else {
            header("Location: index.php");
        }
        exit();
    } else {
        // ผู้ใช้คนนี้ล็อกอินเข้ามาเป็นครั้งแรก -> สมัครสมาชิกให้อัตโนมัติ (ใช้ RETURNING เพื่อเอา ID)
        $random_username = "line_" . substr($line_id, 0, 10);
        $random_password = bin2hex(random_bytes(6)); 
        $hashed_password = password_hash($random_password, PASSWORD_DEFAULT); 
        $default_tel     = '';
        
        $sql_insert = "INSERT INTO users (username, password, fullname, role, tel, line_id) 
                       VALUES ($1, $2, $3, 'customer', $4, $5) 
                       RETURNING *";
        $res_insert = @pg_query_params($conn, $sql_insert, array(
            $random_username, 
            $hashed_password, 
            $display_name, 
            $default_tel, 
            $line_id
        ));
        
        if ($res_insert && ($new_user = pg_fetch_assoc($res_insert))) {
            $new_user_id = (int)($new_user['user_id'] ?? $new_user['id'] ?? 0);
            
            $_SESSION['user_id']  = $new_user_id;
            $_SESSION['username'] = $random_username;
            $_SESSION['fullname'] = $display_name;
            $_SESSION['role']     = 'customer';
            
            header("Location: index.php");
            exit();
        } else {
            die("เกิดข้อผิดพลาดในการลงทะเบียนสมาชิกใหม่ด้วย LINE: " . pg_last_error($conn));
        }
    }
} else {
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
}
?>
