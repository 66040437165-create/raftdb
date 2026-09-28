<?php
session_start();
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 1) {
    header("Location: admin_dashboard.php?msg=access_denied");
    exit();
}

// Table creation and defaults are now handled in db_config.php

// 3. ระบบบันทึกข้อมูล
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_settings'])) {
    foreach ($_POST['settings'] as $key => $value) {
        $key = $conn->real_escape_string($key);
        $value = $conn->real_escape_string($value);
        $conn->query("UPDATE settings SET setting_value = '$value' WHERE setting_key = '$key'");
    }
    header("Location: manage_settings.php?msg=success");
    exit();
}

// 4. ดึงข้อมูลมาแสดง
$settings = [];
$res = $conn->query("SELECT * FROM settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ตั้งค่าระบบ - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 flex min-h-screen">

    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 px-8 sticky top-0 z-30">
            <h2 class="text-xl font-black text-slate-800">ตั้งค่าระบบ</h2>
        </header>

        <div class="p-8">
            <div class="max-w-2xl bg-white p-8 rounded-[2.5rem] shadow-sm border border-gray-100">
                <h3 class="text-xl font-black text-slate-800 mb-8 flex items-center gap-3">
                    <i class="fa fa-clock text-blue-500"></i> เวลาทำการและข้อมูลทั่วไป
                </h3>

                <?php if(isset($_GET['msg']) && $_GET['msg'] == 'success'): ?>
                    <div class="bg-emerald-100 text-emerald-600 p-4 rounded-2xl mb-6 font-bold text-sm">
                        <i class="fa fa-check-circle mr-2"></i> บันทึกการตั้งค่าเรียบร้อยแล้ว
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="save_settings" value="1">
                    
                    <div>
                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-2">ชื่อธุรกิจ</label>
                        <input type="text" name="settings[business_name]" value="<?php echo htmlspecialchars($settings['business_name']); ?>"
                               class="w-full px-5 py-3 rounded-2xl bg-gray-50 border-none focus:ring-2 focus:ring-blue-400 outline-none font-bold">
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-2">เวลาเปิด (เริ่มต้นจอง)</label>
                            <input type="time" name="settings[open_time]" value="<?php echo $settings['open_time']; ?>"
                                   class="w-full px-5 py-3 rounded-2xl bg-gray-50 border-none focus:ring-2 focus:ring-blue-400 outline-none font-bold">
                        </div>
                        <div>
                            <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-2">เวลาปิด (สิ้นสุดจอง)</label>
                            <input type="time" name="settings[close_time]" value="<?php echo $settings['close_time']; ?>"
                                   class="w-full px-5 py-3 rounded-2xl bg-gray-50 border-none focus:ring-2 focus:ring-blue-400 outline-none font-bold">
                        </div>
                    </div>

                    <div class="bg-blue-50 p-6 rounded-2xl border border-blue-100">
                        <p class="text-xs text-blue-600 leading-relaxed">
                            <i class="fa fa-info-circle mr-1"></i> <strong>หมายเหตุ:</strong> ระบบจะใช้เวลา "เปิด" และ "ปิด" เป็นค่ามาตรฐานสำหรับการจองแบบ "เหมาทั้งวัน" (Day Trip) โดยลูกค้าจะจองได้ในช่วงเวลา 09:00 - 17:30 ตามที่ท่านกำหนดไว้นี้
                        </p>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl shadow-xl hover:bg-blue-600 transition flex items-center justify-center gap-2 mt-4">
                        <i class="fa fa-save"></i> บันทึกการตั้งค่า
                    </button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
