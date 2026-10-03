<?php
// กำหนดค่าการเชื่อมต่อ LINE Messaging API
define('LINE_CHANNEL_ACCESS_TOKEN', 'ใส่_CHANNEL_ACCESS_TOKEN_ตรงนี้');
define('LINE_ADMIN_USER_ID', 'ใส่_YOUR_USER_ID_ตรงนี้');

/**
 * ฟังก์ชันส่งข้อความตัวอักษรเข้า LINE (Push Message)
 * @param string $to_user_id รหัส LINE ของผู้รับ (เช่น LINE_ADMIN_USER_ID หรือ line_user_id ของลูกค้า)
 * @param string $message ข้อความที่ต้องการส่ง
 * @return array ผลลัพธ์การส่ง
 */
function send_line_message($to_user_id, $message) {
    if (empty($to_user_id) || empty($message)) {
        return ['status' => false, 'error' => 'Missing recipient or message'];
    }

    $url = 'https://api.line.me/v2/bot/message/push';
    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ];

    $payload = [
        'to' => $to_user_id,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => ($http_code === 200),
        'http_code' => $http_code,
        'response' => $response,
        'error' => $curl_error
    ];
}
