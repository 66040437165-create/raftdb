<?php
session_start();
require_once __DIR__ . '/db_config.php';

// 1. ดึงข้อมูลผู้ใช้งาน และตรวจสอบสถานะ LINE Login
$is_logged_in = isset($_SESSION['user_id']);
$user_id = $is_logged_in ? intval($_SESSION['user_id']) : null;
$user_fullname = $is_logged_in ? ($_SESSION['fullname'] ?? '') : '';
$user_tel = '';

// ตรวจสอบ LINE User ID ของลูกค้าจาก Session
$line_user_id = $_SESSION['line_user_id'] ?? $_SESSION['user_line_id'] ?? null;
$line_display_name = $_SESSION['line_display_name'] ?? '';

if (empty($user_fullname) && !empty($line_display_name)) {
    $user_fullname = $line_display_name;
}

if ($is_logged_in && $conn) {
    $is_admin = (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') || 
                (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);
    
    if ($is_admin) {
        $user_fullname = '';
        $user_tel = '';
    } else {
        // ดึงข้อมูลผู้ใช้จากตาราง users
        $user_res = @pg_query_params($conn, "SELECT * FROM users WHERE id = $1 LIMIT 1", array($user_id));
        if ($user_res && pg_num_rows($user_res) > 0) {
            $user_data = pg_fetch_assoc($user_res);
            $user_tel = $user_data['tel'] ?? $user_data['phone'] ?? '';
            if (empty($user_fullname)) {
                $user_fullname = $user_data['fullname'] ?? $user_data['full_name'] ?? $user_data['name'] ?? '';
            }
        }
    }
}

// 2. ตรวจสอบข้อมูลแพที่เลือก
if (!isset($_GET['raft_id']) || empty($_GET['raft_id'])) { 
    header("Location: index.php"); 
    exit(); 
}

$raft_id = intval($_GET['raft_id']);
$checkin_val = $_GET['checkin'] ?? date('Y-m-d'); 
$checkout_val = $_GET['checkout'] ?? date('Y-m-d', strtotime($checkin_val . ' +1 day')); 
$checkin_time_val = $_GET['checkin_time'] ?? '09:00';
$checkout_time_val = $_GET['checkout_time'] ?? '17:30';

// ดึงข้อมูลแพตาม id (PostgreSQL)
$raft = null;
if ($conn) {
    $stmt_raft = @pg_query_params($conn, "SELECT * FROM rafts WHERE id = $1 LIMIT 1", array($raft_id));
    if ($stmt_raft) {
        $raft = pg_fetch_assoc($stmt_raft);
    }
}

if (!$raft) { 
    header("Location: index.php"); 
    exit(); 
}

// ดึงรูปภาพทั้งหมด
$images = [];

// 2.1 ตรวจสอบและดึงจากตาราง raft_images (ถ้ามีตาราง)
if ($conn) {
    $chk_tbl = @pg_query($conn, "SELECT to_regclass('public.raft_images')");
    $has_tbl = ($chk_tbl && ($r_tbl = pg_fetch_row($chk_tbl)) && !empty($r_tbl[0]));
    if ($has_tbl) {
        $res_imgs = @pg_query_params($conn, "SELECT image_path, is_main FROM raft_images WHERE raft_id = $1 ORDER BY is_main DESC", array($raft_id));
        if ($res_imgs && pg_num_rows($res_imgs) > 0) {
            while ($img = pg_fetch_assoc($res_imgs)) {
                $images[] = $img;
            }
        }
    }
}

// 2.2 ถ้าใน raft_images ไม่มี ให้ดึงจากคอลัมน์ featured_image และ image_1 ถึง image_5
if (empty($images)) {
    if (!empty($raft['featured_image'])) {
        $images[] = ['image_path' => $raft['featured_image'], 'is_main' => 1];
    }
    
    for ($i = 1; $i <= 5; $i++) {
        $col_name = "image_" . $i;
        if (!empty($raft[$col_name])) {
            $images[] = ['image_path' => $raft[$col_name], 'is_main' => 0];
        }
    }
}

$main_image = !empty($images) ? $images[0]['image_path'] : '';

// 3. ดึงค่าตั้งค่าเวลาเปิด-ปิด
$settings = [];
if ($conn) {
    $res_settings = @pg_query($conn, "SELECT setting_key, setting_value FROM settings");
    if ($res_settings) {
        while ($row = pg_fetch_assoc($res_settings)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
}
$open_time = $settings['open_time'] ?? '09:00';
$close_time = $settings['close_time'] ?? '17:30';

if (empty($_GET['checkin_time'])) {
    $checkin_time_val = $open_time;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยืนยันการจอง - <?php echo htmlspecialchars($raft['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen pb-20">

    <nav class="p-6">
        <div class="max-w-2xl mx-auto">
            <a href="index.php" class="text-blue-600 font-bold flex items-center gap-2 hover:gap-3 transition-all">
                <i class="fa fa-arrow-left"></i> กลับไปเลือกแพใหม่
            </a>
        </div>
    </nav>

    <div class="max-w-2xl mx-auto bg-white rounded-[2.5rem] shadow-2xl overflow-hidden border border-gray-100 mb-10">
        <div class="relative group">
            <!-- Main Image -->
            <div class="h-64 md:h-80 relative overflow-hidden cursor-pointer" onclick="openLightbox(currentGalleryIndex)">
                <?php if (!empty($main_image)): ?>
                    <img id="mainBookingImage" src="uploads/<?php echo htmlspecialchars($main_image); ?>" class="w-full h-full object-cover transition-all duration-500 group-hover:scale-105">
                <?php else: ?>
                    <div class="w-full h-full bg-slate-200 flex items-center justify-center text-slate-400 font-bold">ไม่มีรูปภาพ</div>
                <?php endif; ?>
                <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-black/30"></div>
                
                <!-- Gallery badge -->
                <div class="absolute top-4 right-4 bg-black/60 backdrop-blur-md text-white text-[11px] font-bold px-3 py-1.5 rounded-full flex items-center gap-1.5 shadow-lg border border-white/20 hover:bg-blue-600 transition">
                    <i class="fa fa-images text-yellow-400"></i>
                    <span id="galleryBadgeText">📷 1/<?php echo count($images) > 0 ? count($images) : 1; ?> (ขยายรูป)</span>
                </div>

                <div class="absolute bottom-6 left-8 right-8 flex justify-between items-end">
                    <div>
                        <span class="bg-blue-600 text-white text-[10px] px-3 py-1 rounded-full font-black uppercase tracking-widest mb-2 inline-block shadow-lg shadow-blue-500/30">ยืนยันการจอง</span>
                        <h1 class="text-3xl font-black text-white"><?php echo htmlspecialchars($raft['name']); ?></h1>
                        <p class="text-blue-100 text-xs mt-0.5"><i class="fa fa-users mr-1"></i> รองรับสูงสุด <?php echo $raft['capacity']; ?> ท่าน</p>
                    </div>
                </div>
            </div>

            <!-- Image Thumbnails -->
            <?php if (count($images) > 1): ?>
                <div class="flex gap-2 p-3 bg-slate-900/90 backdrop-blur-md overflow-x-auto no-scrollbar scroll-smooth">
                    <?php foreach ($images as $index => $img): ?>
                        <div class="shrink-0 cursor-pointer group" onclick="setGalleryIndex(<?php echo $index; ?>)">
                            <img src="uploads/<?php echo htmlspecialchars($img['image_path']); ?>" 
                                 class="main-thumb-item w-20 h-16 md:w-24 md:h-20 object-cover rounded-xl border-2 <?php echo ($index == 0) ? 'border-blue-500 scale-105' : 'border-transparent opacity-70 hover:opacity-100'; ?> transition-all duration-300"
                                 data-index="<?php echo $index; ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <style>
                    .no-scrollbar::-webkit-scrollbar { display: none; }
                    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
                </style>
            <?php endif; ?>
        </div>

        <form action="booking_process.php" method="POST" class="p-8">
            <input type="hidden" name="raft_id" value="<?php echo $raft_id; ?>">
            <input type="hidden" name="booking_type" id="booking_type" value="daily">
            
            <input type="hidden" id="price_per_day" value="<?php echo $raft['price_per_day']; ?>">
            <input type="hidden" id="price_per_hour" value="<?php echo isset($raft['price_per_hour']) ? $raft['price_per_hour'] : 0; ?>">
            <input type="hidden" name="total_price" id="total_price_input" value="<?php echo $raft['price_per_day']; ?>">
            
            <input type="hidden" id="setting_open_time" value="<?php echo $open_time; ?>">
            <input type="hidden" id="setting_close_time" value="<?php echo $close_time; ?>">

            <div class="space-y-8">
                <!-- Section 1: Guest Information & LINE Alert Status -->
                <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100">
                    
                    <!-- ส่วนแจ้งเตือนสถานะ LINE -->
                    <?php if (!empty($line_user_id)): ?>
                        <div class="mb-5 p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center justify-between gap-3 shadow-sm">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-[#06C755] text-white rounded-full flex items-center justify-center shrink-0 text-xl shadow-md shadow-emerald-200">
                                    <i class="fab fa-line"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-black text-emerald-800">เชื่อมต่อ LINE รับแจ้งเตือนแล้ว</p>
                                    <p class="text-[11px] text-emerald-600 font-semibold">บอทจะส่งใบยืนยันการจองเข้า LINE ของคุณทันทีหลังบันทึกรายการ</p>
                                </div>
                            </div>
                            <span class="bg-emerald-200/70 text-emerald-800 text-[10px] font-black px-3 py-1 rounded-full shrink-0">
                                <i class="fa fa-check-circle"></i> เปิดแจ้งเตือน
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="mb-5 p-5 bg-gradient-to-r from-emerald-600 to-teal-600 rounded-2xl text-white shadow-lg shadow-emerald-200/50 flex flex-col sm:flex-row items-center justify-between gap-4">
                            <div class="flex items-center gap-3 text-center sm:text-left">
                                <div class="w-11 h-11 bg-white text-[#06C755] rounded-xl flex items-center justify-center shrink-0 text-2xl shadow-sm">
                                    <i class="fab fa-line"></i>
                                </div>
                                <div>
                                    <h4 class="font-black text-sm">ต้องการรับใบยืนยันการจองผ่าน LINE ไหม?</h4>
                                    <p class="text-[11px] text-emerald-100 mt-0.5">กดเข้าสู่ระบบด้วย LINE เพื่อให้ระบบส่งสรุปยอดและเลขบัญชีเข้าแชทคุณอัตโนมัติ</p>
                                </div>
                            </div>
                            <a href="line_login.php" class="w-full sm:w-auto bg-white text-emerald-700 hover:bg-emerald-50 font-black px-4 py-2.5 rounded-xl text-xs transition shadow-md flex items-center justify-center gap-1.5 shrink-0 active:scale-95">
                                <i class="fab fa-line text-lg text-[#06C755]"></i> เข้าสู่ระบบด้วย LINE
                            </a>
                        </div>
                    <?php endif; ?>

                    <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-5 flex items-center">
                        <i class="fa fa-id-card-o mr-2 text-blue-500"></i> ข้อมูลผู้ติดต่อ
                    </h3>
                    
                    <div class="space-y-5">
                        <div class="relative group">
                            <label class="block text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1.5 ml-1">ชื่อผู้จอง <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <input type="text" name="guest_name" value="<?php echo htmlspecialchars($user_fullname); ?>" 
                                       placeholder="ระบุชื่อผู้จอง..." required
                                       class="w-full h-14 px-5 pl-12 bg-white border-2 border-gray-100 focus:border-blue-500 rounded-2xl outline-none font-bold text-gray-700 transition shadow-sm">
                                <i class="fa fa-user absolute left-5 top-1/2 -translate-y-1/2 text-gray-300 group-focus-within:text-blue-500 transition-colors"></i>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="relative">
                                <label class="block text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1.5 ml-1">เบอร์โทรศัพท์ <span class="text-rose-500">*</span></label>
                                <input type="tel" name="guest_tel" value="<?php echo htmlspecialchars($user_tel); ?>" required 
                                       maxlength="10" minlength="10" pattern="[0-9]{10}"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)"
                                       placeholder="0812345678"
                                       class="w-full h-12 px-5 bg-white border-2 border-gray-100 focus:border-blue-500 rounded-2xl outline-none font-bold text-gray-700 transition shadow-sm">
                            </div>
                            <div class="relative">
                                <label class="block text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1.5 ml-1">อีเมล (ถ้ามี)</label>
                                <input type="email" name="guest_email" placeholder="example@email.com" 
                                       class="w-full h-12 px-5 bg-white border-2 border-gray-100 focus:border-blue-500 rounded-2xl outline-none font-bold text-gray-700 transition shadow-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Booking Schedule -->
                <div class="bg-white p-0 rounded-3xl overflow-hidden">
                    <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-5 flex items-center ml-1">
                        <i class="fa fa-calendar-check-o mr-2 text-blue-500"></i> รายละเอียดการเข้าพัก
                    </h3>

                    <div class="space-y-6">
                        <!-- Booking Type Toggle -->
                        <div class="p-1 bg-gray-100 rounded-2xl flex">
                            <button type="button" id="btn_daily" onclick="setBookingType('daily')" 
                                    class="flex-1 py-3.5 rounded-xl font-bold text-sm shadow-sm bg-white text-blue-600 transition duration-300 shadow-md scale-105">
                                <i class="fa fa-sun-o mr-2"></i> เหมาทั้งวัน
                            </button>
                            <button type="button" id="btn_hourly" onclick="setBookingType('hourly')" 
                                    class="flex-1 py-3.5 rounded-xl font-bold text-sm text-gray-500 hover:text-gray-700 transition duration-300">
                                <i class="fa fa-clock-o mr-2"></i> รายชั่วโมง
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <!-- Check-in Date -->
                            <div class="space-y-2">
                                <label class="block text-[10px] font-black uppercase tracking-widest ml-1 italic text-blue-500">วันที่เช็คอิน</label>
                                <input type="date" name="check_in" id="check_in" value="<?php echo $checkin_val; ?>" required 
                                       min="<?php echo date('Y-m-d'); ?>" onchange="calculatePrice()"
                                       class="w-full h-12 px-4 bg-blue-50/50 border-2 border-transparent focus:border-blue-500 focus:bg-white rounded-2xl outline-none font-bold transition text-blue-700">
                            </div>

                            <!-- Check-in Time -->
                            <div class="space-y-2">
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">เวลาเช็คอิน</label>
                                <input type="time" name="check_in_time" id="check_in_time" value="<?php echo $checkin_time_val; ?>" required onchange="calculatePrice()" readonly
                                       class="w-full h-12 px-4 bg-gray-50 border-2 border-transparent focus:border-blue-500 focus:bg-white rounded-2xl outline-none font-bold text-gray-700 transition">
                            </div>
                        </div>
                        
                        <!-- Checkout Section (Dynamic) -->
                        <div id="checkout_section" class="grid grid-cols-2 gap-4 hidden">
                            <div class="col-start-2 space-y-2">
                                <label class="block text-[10px] font-black uppercase tracking-widest ml-1 text-rose-500">เวลาเช็คเอาท์</label>
                                <input type="time" name="check_out_time" id="check_out_time" onchange="calculatePrice()"
                                       class="w-full h-12 px-4 bg-rose-50/50 border-2 border-transparent focus:border-rose-500 focus:bg-white rounded-2xl outline-none font-bold text-rose-700 transition">
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="check_out" id="check_out_date_hidden" value="<?php echo $checkout_val; ?>">
                <input type="hidden" name="check_out_time_hidden" id="check_out_time_hidden" value="<?php echo $close_time; ?>">

                <!-- Section 3: Pricing Summary -->
                <div class="pt-2">
                    <div class="bg-blue-600 p-6 rounded-[2rem] shadow-xl shadow-blue-500/20 relative overflow-hidden transition-all duration-300" id="price_card">
                        <i class="fa fa-ship absolute -right-6 -bottom-6 text-9xl text-white/10 -rotate-12"></i>
                        <div class="relative z-10 flex justify-between items-center text-white">
                            <div class="text-left">
                                <p class="text-[10px] text-blue-200 bg-black/10 px-3 py-2 rounded-xl border border-white/10 backdrop-blur-sm" id="price_note">
                                    <i class="fa fa-info-circle mr-1"></i> เวลาให้บริการ <?php echo $open_time; ?> - <?php echo $close_time; ?> น.
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-blue-100 text-[10px] font-black uppercase tracking-[0.2em] mb-1" id="price_label">ราคาเหมาจ่ายต่อวัน</p>
                                <div class="flex items-baseline justify-end gap-1">
                                    <span class="text-lg font-bold">฿</span>
                                    <span id="display_price" class="text-4xl font-black"><?php echo number_format($raft['price_per_day']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="group w-full bg-slate-900 hover:bg-blue-600 text-white font-black py-5 rounded-[2rem] text-lg shadow-2xl transition-all duration-500 flex items-center justify-center gap-4 active:scale-[0.98]">
                    <span>ยืนยันข้อมูลการจอง</span>
                    <i class="fa fa-check-circle text-xl group-hover:scale-125 transition-transform"></i>
                </button>
            </div>
        </form>
    </div>

    <!-- Fullscreen Interactive Lightbox Gallery Modal -->
    <div id="lightboxModal" class="fixed inset-0 bg-black/95 backdrop-blur-md z-50 hidden flex flex-col justify-between p-4 md:p-8 select-none transition-opacity duration-300">
        <div class="flex justify-between items-center text-white z-10">
            <div class="flex items-center gap-3">
                <span class="bg-blue-600 px-3 py-1 rounded-full text-xs font-bold shadow-lg" id="lightboxCounter">1 / 1</span>
                <span class="text-xs text-slate-300 font-bold hidden sm:inline"><?php echo htmlspecialchars($raft['name']); ?></span>
            </div>
            <button type="button" onclick="closeLightbox()" class="w-11 h-11 bg-white/10 hover:bg-rose-600 rounded-full flex items-center justify-center text-white text-xl transition shadow-lg">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <div class="relative flex-grow flex items-center justify-center my-4 overflow-hidden">
            <?php if (count($images) > 1): ?>
                <button type="button" onclick="prevLightboxImage()" class="absolute left-2 md:left-6 z-20 w-12 h-12 md:w-14 md:h-14 bg-black/50 hover:bg-blue-600 text-white rounded-full flex items-center justify-center text-xl backdrop-blur-sm transition shadow-lg">
                    <i class="fa fa-chevron-left"></i>
                </button>
            <?php endif; ?>

            <img id="lightboxImage" src="" class="max-h-[75vh] max-w-[92vw] object-contain rounded-2xl shadow-2xl transition-all duration-300">

            <?php if (count($images) > 1): ?>
                <button type="button" onclick="nextLightboxImage()" class="absolute right-2 md:right-6 z-20 w-12 h-12 md:w-14 md:h-14 bg-black/50 hover:bg-blue-600 text-white rounded-full flex items-center justify-center text-xl backdrop-blur-sm transition shadow-lg">
                    <i class="fa fa-chevron-right"></i>
                </button>
            <?php endif; ?>
        </div>

        <?php if (count($images) > 1): ?>
            <div class="flex justify-center gap-2 overflow-x-auto py-2 no-scrollbar max-w-full">
                <?php foreach ($images as $idx => $img): ?>
                    <img src="uploads/<?php echo htmlspecialchars($img['image_path']); ?>" 
                         onclick="setLightboxImage(<?php echo $idx; ?>)"
                         class="lightbox-thumb-item w-14 h-14 md:w-16 md:h-16 object-cover rounded-xl border-2 cursor-pointer transition opacity-50 hover:opacity-100 shrink-0" 
                         data-index="<?php echo $idx; ?>">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const galleryImages = <?php 
            $js_imgs = [];
            foreach ($images as $i) { 
                $js_imgs[] = 'uploads/' . $i['image_path']; 
            }
            echo json_encode(!empty($js_imgs) ? $js_imgs : ["uploads/" . $main_image]); 
        ?>;
        let currentGalleryIndex = 0;

        function setBookingType(type) {
            document.getElementById('booking_type').value = type;
            const btnDaily = document.getElementById('btn_daily');
            const btnHourly = document.getElementById('btn_hourly');
            const checkoutSection = document.getElementById('checkout_section');
            const checkoutTimeInput = document.getElementById('check_out_time');
            const priceCard = document.getElementById('price_card');
            const priceNote = document.getElementById('price_note');

            if (type === 'daily') {
                btnDaily.className = 'flex-1 py-3.5 rounded-xl font-bold text-sm shadow-sm bg-white text-blue-600 transition duration-300 shadow-md scale-105';
                btnHourly.className = 'flex-1 py-3.5 rounded-xl font-bold text-sm text-gray-500 hover:text-gray-700 transition duration-300';
                checkoutSection.classList.add('hidden');
                checkoutTimeInput.removeAttribute('required');
                priceCard.className = 'bg-blue-600 p-6 rounded-[2rem] shadow-xl shadow-blue-500/20 relative overflow-hidden transition-all duration-300';
                
                const openTime = document.getElementById('setting_open_time').value;
                const closeTime = document.getElementById('setting_close_time').value;
                priceNote.innerHTML = `<i class="fa fa-info-circle mr-1"></i> เหมาทั้งวัน เวลา ${openTime} - ${closeTime} น.`;
                
                document.getElementById('check_in_time').value = openTime;
                document.getElementById('check_in_time').readOnly = true;
            } else {
                btnHourly.className = 'flex-1 py-3.5 rounded-xl font-bold text-sm shadow-sm bg-white text-blue-600 transition duration-300 shadow-md scale-105';
                btnDaily.className = 'flex-1 py-3.5 rounded-xl font-bold text-sm text-gray-500 hover:text-gray-700 transition duration-300';
                checkoutSection.classList.remove('hidden');
                checkoutTimeInput.setAttribute('required', 'required');
                priceCard.className = 'bg-emerald-600 p-6 rounded-[2rem] shadow-xl shadow-emerald-500/20 relative overflow-hidden transition-all duration-300';
                const pricePerHourVal = parseFloat(document.getElementById('price_per_hour').value);
                priceNote.innerHTML = `<i class="fa fa-info-circle mr-1"></i> คิดราคาตามจริง รายชั่วโมง (ชม.ละ ${new Intl.NumberFormat().format(pricePerHourVal)} บาท)`;
                
                document.getElementById('check_in_time').readOnly = false;
            }
            calculatePrice();
        }

        function calculatePrice() {
            const type = document.getElementById('booking_type').value;
            const pricePerDay = parseFloat(document.getElementById('price_per_day').value) || 0;
            const pricePerHour = parseFloat(document.getElementById('price_per_hour').value) || 0;
            
            const displayPrice = document.getElementById('display_price');
            const priceLabel = document.getElementById('price_label');
            const totalPriceInput = document.getElementById('total_price_input');

            if (type === 'daily') {
                priceLabel.innerText = "ราคาเหมาจ่ายต่อวัน";
                displayPrice.innerText = new Intl.NumberFormat().format(pricePerDay);
                totalPriceInput.value = pricePerDay;
                
                const checkInDate = document.getElementById('check_in').value;
                const closeTime = document.getElementById('setting_close_time').value;
                if (checkInDate) {
                    document.getElementById('check_out_date_hidden').value = checkInDate;
                    document.getElementById('check_out_time_hidden').value = closeTime;
                }
            } else {
                priceLabel.innerText = "ราคารวม (รายชั่วโมง)";
                const checkInTime = document.getElementById('check_in_time').value;
                const checkOutTime = document.getElementById('check_out_time').value;

                if (checkInTime && checkOutTime) {
                    const today = new Date().toISOString().split('T')[0];
                    const start = new Date(today + " " + checkInTime);
                    const end = new Date(today + " " + checkOutTime);
                    
                    let diffMs = end - start;
                    if (diffMs <= 0) { 
                        diffMs += 24 * 60 * 60 * 1000; 
                    }

                    const diffHrs = Math.ceil(diffMs / (1000 * 60 * 60));
                    const total = diffHrs * pricePerHour;

                    displayPrice.innerText = new Intl.NumberFormat().format(total);
                    totalPriceInput.value = total;
                } else {
                    displayPrice.innerText = "0";
                    totalPriceInput.value = 0;
                }
            }
        }

        function setGalleryIndex(index) {
            if (index < 0 || index >= galleryImages.length) return;
            currentGalleryIndex = index;
            
            const mainImg = document.getElementById('mainBookingImage');
            if (mainImg) {
                mainImg.style.opacity = '0.3';
                setTimeout(() => {
                    mainImg.src = galleryImages[index];
                    mainImg.style.opacity = '1';
                }, 150);
            }

            const badgeText = document.getElementById('galleryBadgeText');
            if (badgeText) {
                badgeText.innerText = `📷 ${index + 1}/${galleryImages.length} (ขยายรูป)`;
            }

            document.querySelectorAll('.main-thumb-item').forEach(img => {
                const idx = parseInt(img.getAttribute('data-index'));
                if (idx === index) {
                    img.classList.remove('border-transparent', 'opacity-70');
                    img.classList.add('border-blue-500', 'scale-105');
                } else {
                    img.classList.remove('border-blue-500', 'scale-105');
                    img.classList.add('border-transparent', 'opacity-70');
                }
            });
        }

        function openLightbox(index = 0) {
            currentGalleryIndex = index;
            updateLightboxView();
            const modal = document.getElementById('lightboxModal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox() {
            const modal = document.getElementById('lightboxModal');
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        function setLightboxImage(index) {
            currentGalleryIndex = index;
            updateLightboxView();
            setGalleryIndex(index);
        }

        function prevLightboxImage() {
            currentGalleryIndex = (currentGalleryIndex - 1 + galleryImages.length) % galleryImages.length;
            updateLightboxView();
            setGalleryIndex(currentGalleryIndex);
        }

        function nextLightboxImage() {
            currentGalleryIndex = (currentGalleryIndex + 1) % galleryImages.length;
            updateLightboxView();
            setGalleryIndex(currentGalleryIndex);
        }

        function updateLightboxView() {
            const lbImg = document.getElementById('lightboxImage');
            const counter = document.getElementById('lightboxCounter');
            
            if (lbImg) {
                lbImg.style.opacity = '0.4';
                setTimeout(() => {
                    lbImg.src = galleryImages[currentGalleryIndex];
                    lbImg.style.opacity = '1';
                }, 100);
            }

            if (counter) {
                counter.innerText = `${currentGalleryIndex + 1} / ${galleryImages.length}`;
            }

            document.querySelectorAll('.lightbox-thumb-item').forEach(img => {
                const idx = parseInt(img.getAttribute('data-index'));
                if (idx === currentGalleryIndex) {
                    img.classList.remove('opacity-50', 'border-transparent');
                    img.classList.add('opacity-100', 'border-blue-500', 'scale-110');
                } else {
                    img.classList.remove('opacity-100', 'border-blue-500', 'scale-110');
                    img.classList.add('opacity-50', 'border-transparent');
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            const modal = document.getElementById('lightboxModal');
            if (modal && !modal.classList.contains('hidden')) {
                if (e.key === 'ArrowLeft') { prevLightboxImage(); }
                else if (e.key === 'ArrowRight') { nextLightboxImage(); }
                else if (e.key === 'Escape') { closeLightbox(); }
            }
        });
    </script>
</body>
</html>
