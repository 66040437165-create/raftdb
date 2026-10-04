<?php
require_once __DIR__ . '/db_config.php';

$sql = "CREATE TABLE IF NOT EXISTS expenses (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    amount NUMERIC(10,2) NOT NULL,
    expense_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";

if ($conn) {
    $result = @pg_query($conn, $sql);
    if ($result) {
        echo "✅ Table 'expenses' created successfully or already exists.<br>";
        echo "<br><a href='manage_finances.php' style='font-size: 16px; font-weight: bold;'>คลิกที่นี่เพื่อกลับไปหน้าจัดการบัญชีและรายจ่าย</a>";
    } else {
        echo "❌ Error creating table: " . pg_last_error($conn) . "<br>";
    }
} else {
    echo "❌ Error: Database connection not established.";
}
?>
