<?php
require_once __DIR__ . '/db_config.php';

// คำสั่งลบตารางเก่า (ถ้ามีโครงสร้างผิด) แล้วสร้างใหม่ให้ถูกต้อง
$sql = "DROP TABLE IF EXISTS expenses;
        CREATE TABLE expenses (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            amount NUMERIC(10,2) NOT NULL,
            expense_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );";

if ($conn) {
    $result = @pg_query($conn, $sql);
    if ($result) {
        echo "✅ รีเซ็ตและสร้างตาราง 'expenses' สำเร็จเรียบร้อยแล้ว!<br>";
        echo "<br><a href='manage_finances.php' style='font-size: 16px; font-weight: bold; color: blue;'>คลิกที่นี่เพื่อกลับไปหน้าจัดการบัญชีและรายจ่าย</a>";
    } else {
        echo "❌ Error: " . pg_last_error($conn) . "<br>";
    }
} else {
    echo "❌ Error: Database connection not established.";
}
?>
