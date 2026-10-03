<?php 
session_start(); 
require_once __DIR__ . '/db_config.php'; 

// 1. ดึงค่าตั้งค่าจากฐานข้อมูล (PostgreSQL)
$settings = [];
if ($conn) {
    $res_settings = @pg_query($conn, "SELECT setting_key, setting_value FROM settings");
    if ($res_settings) {
        while ($row = pg_fetch_assoc($res_settings)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
}
$open_time  = $settings['open_time'] ?? '09:00';
$close_time = $settings['close_time'] ?? '17:30';

// 2. รับค่าค้นหาจากฟอร์ม
$checkin         = isset($_GET['checkin']) && !empty($_GET['checkin']) ? $_GET['checkin'] : date('Y-m-d');
$checkin_time    = isset($_GET['checkin_time']) ? $_GET['checkin_time'] : $open_time;
$checkout        = date('Y-m-d', strtotime($checkin . ' +1 day'));
$checkout_time   = '11:00';
$guests          = isset($_GET['guests']) ? intval($_GET['guests']) : 2;
$search_keyword  = isset($_GET['search']) ? trim($_GET['search']) : '';

// 3. ฟังก์ชันตรวจหาไฟล์ในโฟลเดอร์ uploads แบบ Case-Insensitive (รองรับ Linux บน Render)
function find_local_upload_file($filename) {
    if (empty($filename)) return false;

    // ตัด path ข้างหน้าออก ให้เหลือเฉพาะชื่อไฟล์
    $clean_name = ltrim(preg_replace('/^(\.\.\/|\.\/|uploads\/|\/uploads\/)+/i', '', trim($filename)), '/');
    if (empty($clean_name)) return false;

    $dir = __DIR__ . '/uploads/';
    if (!is_dir($dir)) return false;

    // ตรวจสอบตรงๆ ก่อน
    if (file_exists($dir . $clean_name)) {
        return 'uploads/' . $clean_name;
    }

    // สแกนหาไฟล์แบบไม่สนตัวพิมพ์เล็ก-ใหญ่ (.jpg vs .JPG / S__ vs s__)
    $target_lower = strtolower($clean_name);
    $files = @scandir($dir);
    if ($files !== false) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (strtolower($file) === $target_lower) {
                return 'uploads/' . $file;
            }
        }
    }

    return false;
}

// 4. ฟังก์ชันดึงรูปภาพแพแบบปลอดภัยและมี Fallback ที่ตรงธีม
function get_raft_image_url($row) {
    $fallback_raft_img = "https://images.unsplash.com/photo-1501785888041-af3ef285b470?auto=format&fit=crop&w=800&q=80";

    if (!is_array($row)) {
        return $fallback_raft_img;
    }

    $image_fields = [
        'featured_image', 'cover_image', 'image', 'raft_image',
        'image_1', 'image_2', 'image_3', 'image_4', 'image_5',
        'picture', 'photo', 'thumbnail', 'img'
    ];

    foreach ($image_fields as $field) {
        if (!empty($row[$field])) {
            $val = trim($row[$field]);
            if (empty($val)) continue;

            // กรณีเก็บ URL ภายนอก
            if (preg_match('/^https?:\/\//i', $val) || strpos($val, '//') === 0) {
                return $val;
            }

            // ค้นหาไฟล์ใน uploads
            $found_file = find_local_upload_file($val);
            if ($found_file) {
                return $found_file;
            }
        }
    }

    // หากไม่มีรูปเฉพาะแพลำนี้ ให้ลองตรวจว่ามีไฟล์รูปใดๆ อยู่ใน uploads หรือไม่
    $all_uploads = @glob(__DIR__ . '/uploads/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
    if (!empty($all_uploads)) {
        return 'uploads/' . basename($all_uploads[0]);
    }

    return $fallback_raft_img;
}

// ค้นหารูปภาพ Hero Background
$hero_bg_file = find_local_upload_file('S__12296202.jpg');
if (!$hero_bg_file) {
    $all_images = @glob(__DIR__ . '/uploads/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
    $hero_bg_file = !empty($all_images) ? 'uploads/' . basename($all_images[0]) : 'https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=1600&q=80';
}

// 5. ดึงข้อมูลแพว่างจากตาราง rafts
$rafts = [];
if ($conn) {
    $r_cols = [];
    $chk_r = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'rafts'");
    if ($chk_r) {
        while ($rc = pg_fetch_assoc($chk_r)) {
            $r_cols[] = strtolower($rc['column_name']);
        }
    }

    $b_cols = [];
    $chk_b = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
    if ($chk_b) {
        while ($bc = pg_fetch_assoc($chk_b)) {
            $b_cols[] = strtolower($bc['column_name']);
        }
    }

    $params = [];
    $p_idx = 1;

    $where_clauses = [
        "(r.status IS NULL OR TRIM(LOWER(r.status)) NOT IN ('maintenance', 'closed', 'repair', 'disabled', 'inactive', 'ปิดปรับปรุง', 'ปิดบริการ', '0'))"
    ];

    if (in_array('is_active', $r_cols)) {
        $where_clauses[] = "(r.is_active = 1 OR r.is_active IS NULL)";
    }

    if (!empty($search_keyword)) {
        $search_fields = [];
        if (in_array('name', $r_cols)) $search_fields[] = "r.name ILIKE $" . $p_idx;
        if (in_array('raft_code', $r_cols)) $search_fields[] = "r.raft_code ILIKE $" . $p_idx;
        if (in_array('description', $r_cols)) $search_fields[] = "r.description ILIKE $" . $p_idx;
        
        if (!empty($search_fields)) {
            $where_clauses[] = "(" . implode(" OR ", $search_fields) . ")";
            $params[] = '%' . $search_keyword . '%';
            $p_idx++;
        }
    }

    if (!empty($b_cols) && in_array('raft_id', $b_cols)) {
        $date_expr = null;
        if (in_array('check_in_date', $b_cols) && in_array('check_in', $b_cols)) {
            $date_expr = "COALESCE(b.check_in_date, b.check_in::date)";
        } elseif (in_array('check_in_date', $b_cols)) {
            $date_expr = "b.check_in_date";
        } elseif (in_array('check_in', $b_cols)) {
            $date_expr = "b.check_in::date";
        }

        if ($date_expr) {
            $st_filters = [];
            if (in_array('status_id', $b_cols)) {
                $st_filters[] = "COALESCE(b.status_id, 0) NOT IN (3, 4)";
            }
            if (in_array('status', $b_cols)) {
                $st_filters[] = "LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'rejected', 'cancel')";
            }
            $st_sql = !empty($st_filters) ? " AND (" . implode(" AND ", $st_filters) . ")" : "";

            $where_clauses[] = "NOT EXISTS (
                SELECT 1 FROM bookings b 
                WHERE b.raft_id = r.id 
                  AND $date_expr = $" . $p_idx . "::date
                  $st_sql
            )";
            $params[] = $checkin;
            $p_idx++;
        }
    }

    $sql = "SELECT r.* FROM rafts r WHERE " . implode(" AND ", $where_clauses) . " ORDER BY r.id DESC";
    $result = !empty($params) ? @pg_query_params($conn, $sql, $params) : @pg_query($conn, $sql);

    if (!$result || pg_num_rows($result) === 0) {
        $fallback_sql = "SELECT * FROM rafts WHERE (status IS NULL OR TRIM(LOWER(status)) NOT IN ('maintenance', 'closed', 'repair', 'disabled', 'inactive', 'ปิดปรับปรุง', 'ปิดบริการ', '0')) ORDER BY id DESC";
        $result = @pg_query($conn, $fallback_sql);
    }

    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $rafts[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ล่องแพหนองกวาก</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style> 
        body { font-family: 'Sarabun', sans-serif; }
        .glass-effect { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
        @media (max-width: 768px) {
            .glass-effect { background: rgba(255, 255, 255, 0.98); }
        }
        .hero-bg {
            background-image: linear-gradient(to bottom, rgba(15, 23, 42, 0.45), rgba(15, 23, 42, 0.75)), 
                              url('<?php echo htmlspecialchars($hero_bg_file); ?>');
            background-size: cover;
            background-position: center;
        }
        .btn-animate { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-animate:active { transform: scale(0.95); }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 relative">

    <!-- Navbar -->
    <nav class="bg-white/90 backdrop-blur-md p-3 md:p-4 shadow-sm sticky top-0 z-50">
        <div class="container mx-auto flex justify-between items-center">
            <a href="index.php" class="text-xl md:text-2xl font-black text-blue-600 flex items-center gap-2">
                <span class="text-2xl md:text-3xl">ล่องแพหนองกวาก</span>
                <span class="hidden xs:inline text-sm md:text-base font-bold text-gray-700">จองแพออนไลน์</span>
            </a>
            <div class="flex items-center space-x-2 md:space-x-4">
                <a href="booking_calendar.php" class="bg-blue-50 hover:bg-blue-100 text-blue-600 border border-blue-200 px-3 py-2 rounded-xl text-xs transition font-bold flex items-center gap-1">
                    <i class="fa fa-calendar-alt text-blue-500"></i> ปฏิทินการจอง
                </a>
                
                <a href="https://line.me/R/ti/p/@906kkkfr" target="_blank" class="hidden md:flex bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 px-3 py-2 rounded-xl text-xs transition font-bold items-center gap-1.5">
                    <i class="fab fa-line text-emerald-500 text-sm"></i> @906kkkfr
                </a>

                <?php if(isset($_SESSION['user_id'])): ?>
                    <span class="hidden sm:inline text-sm font-bold text-gray-600">👤 <?php echo htmlspecialchars($_SESSION['fullname'] ?? ''); ?></span>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-xl text-[10px] md:text-xs transition font-bold shadow-lg shadow-red-100 btn-animate">ออกจากระบบ</a>
                <?php else: ?>
                    <a href="login.php" class="text-blue-600 px-2 md:px-3 py-2 rounded-lg font-bold hover:text-blue-800 transition text-[11px] md:text-sm">เจ้าหน้าที่</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Header Hero -->
    <header class="hero-bg text-white pt-32 pb-20 md:pt-48 md:pb-12 h-[75vh] md:h-auto flex items-center justify-center">
        <div class="container mx-auto text-center px-6">
            <h2 class="text-4xl md:text-6xl font-extrabold mb-4 drop-shadow-2xl leading-tight">ล่องแพหนองกวาก</h2>
            <p class="text-base md:text-xl mb-12 md:mb-16 text-blue-50 font-medium opacity-90">สัมผัสธรรมชาติ พักผ่อนหย่อนใจ บริการล่องแพมาตรฐาน</p>

            <div class="glass-effect p-6 md:p-8 rounded-[2rem] md:rounded-[2.5rem] shadow-2xl text-gray-800 max-w-6xl mx-auto border border-white/40 md:-mb-24 relative z-10">
                <form action="index.php#rafts" method="GET" class="flex flex-col md:grid md:grid-cols-5 gap-4 md:gap-6 items-stretch md:items-end">
                    <div class="text-left md:px-2">
                        <label class="block text-[9px] md:text-[10px] font-black text-blue-500 uppercase mb-1 md:mb-2 tracking-widest pl-1">ค้นหาชื่อแพ</label>
                        <div class="relative">
                            <i class="fa fa-ship absolute left-4 top-1/2 -translate-y-1/2 text-blue-400 text-xs md:hidden"></i>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search_keyword); ?>" placeholder="ชื่อแพที่ต้องการ..." class="w-full outline-none p-3 md:p-2 pl-10 md:pl-2 border-2 md:border-0 md:border-b-2 border-gray-100 md:border-gray-100 focus:border-blue-500 bg-white md:bg-transparent font-bold rounded-xl md:rounded-none transition-all">
                        </div>
                    </div>
                    <div class="text-left md:px-2">
                        <label class="block text-[9px] md:text-[10px] font-black text-blue-500 uppercase mb-1 md:mb-2 tracking-widest pl-1">วันที่เช็คอิน</label>
                        <div class="relative">
                            <i class="fa fa-calendar absolute left-4 top-1/2 -translate-y-1/2 text-blue-400 text-xs md:hidden"></i>
                            <input type="date" name="checkin" value="<?php echo $checkin; ?>" class="w-full outline-none p-3 md:p-2 pl-10 md:pl-2 border-2 md:border-0 md:border-b-2 border-gray-100 md:border-gray-100 focus:border-blue-500 bg-white md:bg-transparent font-bold rounded-xl md:rounded-none transition-all" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    <div class="text-left md:px-2 md:border-l border-gray-100">
                        <label class="block text-[9px] md:text-[10px] font-black text-blue-500 uppercase mb-1 md:mb-2 tracking-widest pl-1">เวลาเช็คอิน</label>
                        <div class="relative">
                            <i class="fa fa-clock absolute left-4 top-1/2 -translate-y-1/2 text-blue-400 text-xs md:hidden"></i>
                            <input type="time" name="checkin_time" value="<?php echo $checkin_time; ?>" class="w-full outline-none p-3 md:p-2 pl-10 md:pl-2 border-2 md:border-0 md:border-b-2 border-gray-100 md:border-gray-100 focus:border-blue-500 bg-white md:bg-transparent font-bold rounded-xl md:rounded-none transition-all">
                        </div>
                    </div>
                    <div class="text-left md:px-2 md:border-l border-gray-100">
                        <label class="block text-[9px] md:text-[10px] font-black text-blue-500 uppercase mb-1 md:mb-2 tracking-widest pl-1">จำนวนผู้ลงแพ</label>
                        <div class="relative">
                            <i class="fa fa-users absolute left-4 top-1/2 -translate-y-1/2 text-blue-400 text-xs md:hidden"></i>
                            <select name="guests" class="w-full p-3 md:p-2 pl-10 md:pl-2 border-2 md:border-0 md:border-b-2 border-gray-100 md:border-gray-100 outline-none bg-white md:bg-transparent font-bold appearance-none rounded-xl md:rounded-none transition-all">
                                <option value="2" <?php if($guests<=2) echo 'selected'; ?>>1-15 ท่าน</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="bg-blue-600 text-white font-black rounded-xl md:rounded-2xl hover:bg-blue-700 transition shadow-xl shadow-blue-200 uppercase tracking-widest flex items-center justify-center gap-2 h-14 md:h-12 w-full btn-animate mt-2 md:mt-0">
                        <i class="fa fa-search"></i> ค้นหาแพว่าง
                    </button>
                </form>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main id="rafts" class="container mx-auto py-12 md:py-24 px-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between mb-8 md:mb-12 border-l-4 border-blue-600 pl-4">
            <h3 class="text-2xl md:text-3xl font-black text-gray-800">
                <?php echo (isset($_GET['checkin']) && !empty($_GET['checkin'])) ? "📅 ผลการค้นหาแพว่าง" : "🏖️ รายการแพว่างพร้อมให้บริการ"; ?>
            </h3>
            <p class="text-blue-500 font-bold text-sm mt-1 md:mt-0">
                <?php echo (isset($_GET['checkin']) && !empty($_GET['checkin'])) ? "$checkin ($checkin_time)" : "สถานะพร้อมจองวันนี้"; ?>
            </p>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 md:gap-12">
            <?php
            if (!empty($rafts)):
                foreach ($rafts as $row):
                    $raft_id    = $row['id'];
                    $raft_name  = htmlspecialchars($row['name']);
                    $displayImg = get_raft_image_url($row);
            ?>
                <a href="booking.php?raft_id=<?php echo $raft_id; ?>&checkin=<?php echo $checkin; ?>&checkin_time=<?php echo $checkin_time; ?>&checkout=<?php echo $checkout; ?>&checkout_time=<?php echo $checkout_time; ?>" 
                   class="group bg-white rounded-[2rem] md:rounded-[2.5rem] shadow-sm hover:shadow-2xl transition duration-500 overflow-hidden border border-gray-100 flex flex-col h-full">
                    
                    <!-- ส่วนแสดงรูปภาพแพ -->
                    <div class="relative h-60 md:h-72 overflow-hidden bg-gray-100">
                        <img src="<?php echo htmlspecialchars($displayImg); ?>" 
                             alt="<?php echo $raft_name; ?>" 
                             class="h-full w-full object-cover transition duration-700 group-hover:scale-110"
                             onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1501785888041-af3ef285b470?auto=format&fit=crop&w=800&q=80';">
                        
                        <div class="absolute top-4 left-4 md:top-6 md:left-6">
                            <span class="bg-emerald-500/90 backdrop-blur-md text-white px-3 py-1.5 md:px-4 md:py-2 rounded-full text-[9px] md:text-[10px] font-black uppercase tracking-widest shadow-sm">
                                <i class="fa fa-check-circle mr-1"></i> ว่าง
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-6 md:p-8 flex flex-col flex-grow text-left">
                        <div class="flex justify-between items-start mb-2 md:mb-3">
                            <h4 class="text-xl md:text-2xl font-black text-gray-800 group-hover:text-blue-600 transition truncate pr-2"><?php echo $raft_name; ?></h4>
                            <span class="text-emerald-600 font-black text-lg md:text-xl shrink-0">฿<?php echo number_format($row['price_per_day'] ?? 0); ?></span>
                        </div>
                        <p class="text-gray-400 text-xs md:text-sm mb-6 line-clamp-2 leading-relaxed h-10 md:h-11"><?php echo htmlspecialchars($row['description'] ?: 'ไม่มีรายละเอียดเพิ่มเติม'); ?></p>
                        
                        <div class="mt-auto pt-4 md:pt-6 border-t border-gray-50 flex items-center justify-between">
                            <div class="flex items-center space-x-3 md:space-x-4 text-gray-400 font-bold text-[10px] md:text-xs uppercase">
                                <span><i class="fa fa-users text-blue-400 mr-1"></i> <?php echo $row['capacity'] ?? 0; ?> ท่าน</span>
                                <span><i class="fa fa-star text-yellow-400 mr-1"></i> 4.9</span>
                            </div>
                            
                            <div class="bg-slate-900 text-white px-4 py-2.5 md:px-6 md:py-3 rounded-xl md:rounded-2xl font-black text-xs md:text-sm group-hover:bg-blue-600 transition shadow-lg shadow-gray-200 btn-animate">
                                จองแพ
                            </div>
                        </div>
                    </div>
                </a>
            <?php 
                endforeach;
            else:
                echo "<div class='col-span-full py-16 md:py-24 text-center bg-white rounded-[2rem] md:rounded-[3rem] border-2 border-dashed border-gray-200'>
                        <p class='text-gray-400 text-lg md:text-xl font-bold'>🏜️ ไม่พบแพว่างที่พร้อมให้บริการในขณะนี้</p>
                        <a href='index.php' class='mt-4 inline-block text-blue-600 font-bold hover:underline italic'>ล้างการค้นหา</a>
                      </div>";
            endif; 
            ?>
        </div>
    </main>

    <!-- ส่วน LINE Official -->
    <section class="container mx-auto px-6 mb-16">
        <div class="bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-700 rounded-[2.5rem] p-8 md:p-12 text-white shadow-2xl relative overflow-hidden">
            <div class="absolute -right-16 -bottom-16 w-64 h-64 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
            <div class="absolute -left-16 -top-16 w-64 h-64 bg-emerald-400/20 rounded-full blur-2xl pointer-events-none"></div>

            <div class="relative z-10 flex flex-col lg:flex-row items-center justify-between gap-8 md:gap-12">
                <div class="text-center lg:text-left max-w-xl">
                    <span class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-md px-3.5 py-1.5 rounded-full text-xs font-black uppercase tracking-wider text-emerald-100 mb-4">
                        <i class="fab fa-line text-sm"></i> Official Account
                    </span>
                    <h3 class="text-3xl md:text-4xl font-black mb-3 leading-tight">
                        แอดไลน์ร้าน ล่องแพหนองกวาก
                    </h3>
                    <p class="text-emerald-100 text-sm md:text-base leading-relaxed mb-6">
                        รับแจ้งเตือนสถานะการจองทันใจ ส่งสลิปโอนเงิน หรือสอบถามพูดคุยกับเจ้าหน้าที่ได้ตลอด 24 ชั่วโมง สะดวก รวดเร็ว ไม่พลาดทุกการติดต่อ
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-8 text-left">
                        <div class="bg-white/10 backdrop-blur-sm p-3.5 rounded-2xl border border-white/10">
                            <p class="font-bold text-xs"><i class="fa fa-bell mr-1.5 text-yellow-300"></i> แจ้งเตือนคิวจอง</p>
                            <p class="text-[11px] text-emerald-100 mt-1">อัปเดตสถานะทันทีใน LINE</p>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm p-3.5 rounded-2xl border border-white/10">
                            <p class="font-bold text-xs"><i class="fa fa-receipt mr-1.5 text-cyan-300"></i> แจ้งส่งสลิป</p>
                            <p class="text-[11px] text-emerald-100 mt-1">ส่งหลักฐานยืนยันง่ายๆ</p>
                        </div>
                        <div class="bg-white/10 backdrop-blur-sm p-3.5 rounded-2xl border border-white/10">
                            <p class="font-bold text-xs"><i class="fa fa-comment-dots mr-1.5 text-pink-300"></i> สอบถามแอดมิน</p>
                            <p class="text-[11px] text-emerald-100 mt-1">ดูแลข้อมูลและเส้นทาง</p>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row items-center gap-4 justify-center lg:justify-start">
                        <a href="https://line.me/R/ti/p/@906kkkfr" target="_blank" 
                           class="w-full sm:w-auto bg-white text-emerald-700 hover:bg-emerald-50 px-8 py-4 rounded-2xl font-black text-sm md:text-base shadow-xl transition flex items-center justify-center gap-2 btn-animate">
                            <i class="fab fa-line text-2xl text-[#06C755]"></i> เพิ่มเพื่อน LINE ID: @906kkkfr
                        </a>
                        <span class="text-xs text-emerald-100 font-bold">หรือค้นหาไอดี: <span class="underline font-black text-white">@906kkkfr</span></span>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-3xl shadow-xl text-center text-gray-800 shrink-0 border-4 border-emerald-300/40">
                    <p class="text-[10px] font-black uppercase text-emerald-600 tracking-widest mb-3">สแกน QR Code เพิ่มเพื่อน</p>
                    <div class="bg-gray-50 p-2 rounded-2xl inline-block shadow-inner mb-3">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=https://line.me/R/ti/p/@906kkkfr" 
                             alt="LINE QR Code @906kkkfr" 
                             class="w-40 h-40 object-contain mx-auto">
                    </div>
                    <p class="text-xs font-black text-gray-700">@906kkkfr</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">ล่องแพหนองกวาก จ.หนองคาย</p>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Footer -->
    <footer class="bg-white py-12 md:py-20 border-t border-gray-100 text-center">
        <div class="container mx-auto px-6">
            <div class="max-w-xs mx-auto mb-10 p-6 bg-gray-50 rounded-2xl md:rounded-3xl border border-dashed border-gray-200">
                <p class="text-[9px] md:text-[10px] font-black text-blue-500 uppercase tracking-[0.2em] mb-4">สแกนเพื่อจองบนมือถือ</p>
                <div class="bg-white p-4 rounded-xl md:rounded-2xl shadow-sm inline-block mb-4">
                    <div id="index_qrcode_canvas" class="flex justify-center"></div>
                </div>
                <p class="text-[10px] md:text-xs text-gray-400 font-bold">สแกนเพื่อจองผ่านมือถือได้ทันที<br>สะดวก รวดเร็ว ทุกที่ทุกเวลา</p>
            </div>
            <p class="text-gray-400 text-[9px] md:text-[10px] font-black uppercase tracking-widest">
                สัมผัสธรรมชาติที่แตกต่าง ล่องแพหนองกวาก
            </p>
        </div>
    </footer>

    <!-- ปุ่มลอย LINE -->
    <a href="https://line.me/R/ti/p/@906kkkfr" target="_blank" 
       class="fixed bottom-6 right-6 z-50 bg-[#06C755] hover:bg-[#05b04b] text-white p-4 rounded-full shadow-2xl flex items-center justify-center gap-2 group transition-all duration-300 hover:pr-6 hover:shadow-emerald-300 hover:-translate-y-1">
        <i class="fab fa-line text-3xl"></i>
        <span class="max-w-0 overflow-hidden whitespace-nowrap group-hover:max-w-xs transition-all duration-300 font-bold text-sm">
            แอดไลน์ร้าน @906kkkfr
        </span>
    </a>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const qrElem = document.getElementById("index_qrcode_canvas");
        if (qrElem) {
            new QRCode(qrElem, {
                text: window.location.href,
                width: 160,
                height: 160,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('booking') === 'success') {
            Swal.fire({
                title: 'จองสำเร็จ!',
                text: 'เราได้รับข้อมูลการจองของคุณเรียบร้อยแล้ว กรุณารอการติดต่อกลับ',
                icon: 'success',
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#2563eb',
                backdrop: `rgba(0,0,123,0.4)`
            }).then(() => {
                window.history.replaceState({}, document.title, window.location.pathname);
            });
        }
    </script>
</body>
</html>
