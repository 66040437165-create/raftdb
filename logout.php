<?php
session_start();

// 1. ล้างตัวแปร Session ทั้งหมดในหน่วยความจำ
$_SESSION = array();

// 2. ลบคุกกี้ประจำเซสชันออกจากเบราว์เซอร์ (ถ้ามี)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// 3. ทำลาย Session บนเซิร์ฟเวอร์
session_destroy();

// 4. ส่งกลับไปหน้าเข้าสู่ระบบ
header("Location: login.php");
exit();
?>