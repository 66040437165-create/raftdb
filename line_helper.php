<?php
// กำหนดค่าการเชื่อมต่อ LINE Messaging API
define('LINE_CHANNEL_ACCESS_TOKEN', 'jStaztWHf7QXNoCVTPhoqat7sCmK5HZp5GBJXrlUv+c9NMT26dzuAbalCnpxp53VSGoGBIU16cV5CSfyuKq4qpqbBv+Xd8ju3CTw3/sHfa3PpcS2RwYykgN3CqcJye6QEqexCW+w0MD8B9tF5w+FxAdB04t89/1O/w1cDnyilFU=');
define('LINE_ADMIN_USER_ID', 'Uc363e24c7774830ce61b1995d0b11e9d');

/**
 * ฟังก์ชันส่งข้อความตัวอักษรเข้า LINE (Push Message)
 * @param string $to_user_id รหัส LINE ของผู้รับ
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
