<?php
/**
 * หน้านี้ใช้สำหรับดึง LINE User ID ของแอดมิน
 * หลังจากได้ User ID แล้ว ให้นำไปใส่ใน db_config.php แล้วลบไฟล์นี้ทิ้งได้เลย
 */
include 'db_config.php';

if (!defined('LINE_BOT_ACCESS_TOKEN') || LINE_BOT_ACCESS_TOKEN === '') {
    die("❌ ยังไม่ได้ตั้งค่า LINE_BOT_ACCESS_TOKEN ใน db_config.php");
}

// เรียก LINE API เพื่อดูรายชื่อ Followers ล่าสุด (ต้องแอดบอทเป็นเพื่อนก่อน)
$ch = curl_init('https://api.line.me/v2/bot/followers/ids?limit=10');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . LINE_BOT_ACCESS_TOKEN
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$result = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($result, true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ดึง LINE User ID</title>
<style>
    body { font-family: sans-serif; background: #f0f4f8; display: flex; justify-content: center; padding: 40px; }
    .card { background: white; border-radius: 12px; padding: 30px; max-width: 600px; width: 100%; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
    h2 { color: #06c755; margin-top: 0; }
    .uid { background: #f0fdf4; border: 2px solid #06c755; border-radius: 8px; padding: 14px 18px; font-size: 15px; font-weight: bold; word-break: break-all; margin: 8px 0; }
    .error { background: #fef2f2; border: 2px solid #ef4444; border-radius: 8px; padding: 14px 18px; color: #b91c1c; }
    .note { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 0 8px 8px 0; margin-top: 20px; font-size: 14px; }
    .copy-btn { background: #06c755; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; margin-left: 10px; font-size: 13px; }
</style>
</head>
<body>
<div class="card">
    <h2>🔍 ดึง LINE User ID</h2>

    <?php if ($http_code !== 200): ?>
        <div class="error">
            ❌ เรียก LINE API ไม่ได้ (HTTP <?= $http_code ?>)<br>
            <small><?= htmlspecialchars($result) ?></small>
        </div>

    <?php elseif (empty($data['userIds'])): ?>
        <div class="error">
            ⚠️ ยังไม่พบ User ID ใดเลย<br><br>
            <strong>คุณต้องแอดบอทนี้เป็นเพื่อนก่อน!</strong><br>
            กลับไปที่ LINE OA Manager → สแกน QR Code ของบอท → แอดเป็นเพื่อน → ส่งข้อความใดก็ได้ → กลับมารีโหลดหน้านี้ใหม่ครับ
        </div>

    <?php else: ?>
        <p>✅ พบ User IDs ทั้งหมด <strong><?= count($data['userIds']) ?></strong> รายการ:</p>

        <?php foreach ($data['userIds'] as $i => $uid): ?>
            <div class="uid">
                <?= ($i + 1) ?>. <?= htmlspecialchars($uid) ?>
                <button class="copy-btn" onclick="navigator.clipboard.writeText('<?= $uid ?>'); this.textContent='คัดลอกแล้ว ✓'">คัดลอก</button>
            </div>
        <?php endforeach; ?>

        <div class="note">
            📌 <strong>วิธีใช้:</strong> นำ User ID ด้านบน (ของแอดมิน/ตัวคุณเอง) ไปใส่ใน <code>db_config.php</code> บรรทัดที่เขียนว่า <code>LINE_NOTIFY_TARGET_ID</code><br><br>
            ⚠️ หลังจากนำไปใส่แล้ว <strong>ลบไฟล์นี้ทิ้ง</strong>ได้เลย เพื่อความปลอดภัย
        </div>
    <?php endif; ?>
</div>
</body>
</html>
