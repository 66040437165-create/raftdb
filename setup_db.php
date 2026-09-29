<?php
require_once DIR . '/db_config.php';

if (!$conn) {
    die("❌ ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
}

// คำสั่งสร้างตาราง employees (เวอร์ชัน PostgreSQL)
$sql = "
CREATE TABLE IF NOT EXISTS employees (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role_id INT DEFAULT 1,
    is_active INT DEFAULT 1,
    last_login TIMESTAMP
);

-- เพิ่มข้อมูลแอดมินเริ่มต้น (ถ้ายังไม่มี)
INSERT INTO employees (username, password, full_name, role_id, is_active) 
VALUES ('admin', '123456', 'ผู้ดูแลระบบสูงสุด', 1, 1)
ON CONFLICT (username) DO NOTHING;
";

$result = @pg_query($conn, $sql);

if ($result) {
    echo "<div style='font-family:sans-serif; text-align:center; margin-top:50px;'>";
    echo "<h1 style='color:green;'>✅ สร้างตาราง employees และแอดมินเริ่มต้นสำเร็จ!</h1>";
    echo "<p>ตอนนี้คุณสามารถเข้าสู่ระบบด้วยชื่อผู้ใช้งาน: <b>admin</b> และ รหัสผ่าน: <b>123456</b></p>";
    echo "<a href='login.php' style='padding:10px 20px; background:blue; color:white; text-decoration:none; border-radius:5px;'>กลับไปหน้าเข้าสู่ระบบ</a>";
    echo "</div>";
} else {
    echo "❌ เกิดข้อผิดพลาดในการสร้างตาราง: " . pg_last_error($conn);
}
?>
