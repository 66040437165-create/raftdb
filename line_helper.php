/**
 * ฟังก์ชันส่งรูปภาพและข้อความเข้า LINE แอดมิน
 */
function send_line_slip_alert($image_url, $caption_text) {
    if (empty($image_url)) return false;

    $url = 'https://api.line.me/v2/bot/message/push';
    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ];

    $payload = [
        'to' => LINE_ADMIN_USER_ID,
        'messages' => [
            [
                'type' => 'text',
                'text' => $caption_text
            ],
            [
                'type' => 'image',
                'originalContentUrl' => $image_url,
                'previewImageUrl' => $image_url
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
    curl_close($ch);

    return $response;
}
