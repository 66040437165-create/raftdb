<?php
require_once __DIR__ . '/line_helper.php';

$test_message  = "🔔 ทดสอบระบบแจ้งเตือน ล่องแพหนองกวาก\n";
$test_message .= "━━━━━━━━━━━━━━━━\n";
$test_message .= "เวลาทดสอบ: " . date('Y-m-d H:i:s') . "\n";
$test_message .= "ระบบ LINE Messaging API เชื่อมต่อสำเร็จเรียบร้อยแล้ว!";

$result = send_line_message(LINE_ADMIN_USER_ID, $test_message);

if ($result['status']) {
    echo "<h2 style='color: green;'>✅ ส่งข้อความสำเร็จ! ตรวจสอบ LINE ของคุณได้เลย</h2>";
} else {
    echo "<h2 style='color: red;'>❌ ส่งข้อความไม่สำเร็จ</h2>";
    echo "<p><strong>HTTP Status Code:</strong> " . $result['http_code'] . "</p>";
    echo "<p><strong>Response จาก LINE:</strong> " . htmlspecialchars($result['response']) . "</p>";
    if (!empty($result['error'])) {
        echo "<p><strong>cURL Error:</strong> " . htmlspecialchars($result['error']) . "</p>";
    }
}
