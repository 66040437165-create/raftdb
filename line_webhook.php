<?php
/**
 * LINE Webhook Receiver
 * รับ Event จาก LINE เมื่อมีคนส่งข้อความหาบอท
 * แล้วบันทึก User ID ไว้ใน line_uid_log.txt
 */

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!empty($data['events'])) {
    foreach ($data['events'] as $event) {
        if (isset($event['source']['userId'])) {
            $uid = $event['source']['userId'];
            $type = $event['source']['type'] ?? 'user';
            $timestamp = date('Y-m-d H:i:s');
            
            // บันทึกลงไฟล์ log
            $log_file = __DIR__ . '/line_uid_log.txt';
            file_put_contents($log_file, "[$timestamp] Type: $type | UserID: $uid\n", FILE_APPEND);
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
?>
