<?php 
session_start(); 
require_once __DIR__ . '/db_config.php'; 

// 1. ดึงค่าตั้งค่าจากฐานข้อมูล (เปลี่ยนเป็น PostgreSQL syntax)
$settings = [];
if ($conn) {
    $res_settings = @pg_query($conn, "SELECT * FROM settings");
    if ($res_settings) {
        while ($row = pg_fetch_assoc($res_settings)) {$settings[$row['setting_key']] =$row['setting_value'];
        }
    }
}
$open_time =$settings['open_time'] ?? '09:00';
$close_time =$settings['close_time'] ?? '17:30';

// 2. รับค่าค้นหาจากฟอร์ม (เปลี่ยนการ escape string เป็น PostgreSQL)
$checkin = isset($_GET['checkin']) && !empty($_GET['checkin']) ?$_GET['checkin'] : date('Y-m-d');
$checkin_time = isset($_GET['checkin_time']) ? $_GET['checkin_time'] :$open_time;
$checkout = date('Y-m-d', strtotime($checkin . ' +1 day'));$checkout_time = '11:00';

$guests = isset($_GET['guests']) ? intval($_GET['guests']) : 2;
$search_keyword = '';
if (isset($_GET['search']) && $conn) {$search_keyword = pg_escape_string($conn, trim($_GET['search']));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChillRaft - สัมผัสธรรมชาติเหนือผืนน้ำ</title>
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
            background-image: linear-gradient(to bottom, rgba(255, 255, 255, 0.5), rgba(15, 23, 42, 0.7)), 
                              url('uploads/S__12296202.jpg');
            background-size: cover;
            background-position: center;
        }
        .btn-animate { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-animate:active { transform: scale(0.95); }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">

    <!-- Navbar -->
    <nav class="bg-white/90 backdrop-blur-md p-3 md:p-4 shadow-sm sticky top-0 z-50">
        <div class="container mx-auto flex justify-between items-center">
            <a href="index.php" class="text-xl md:text-2xl font-black text-blue-600 flex items-center gap-2">
                <span class="text-2xl md:text-3xl">🌊ล่องแพหนองกวาก</span>
                <span class="hidden xs:inline">จองแพออนไลน์</span>
            </a>
            <div class="flex items-center space-x-2 md:space-x-4">
                <a href="booking_calendar.php" class="bg-blue-50 hover:bg-blue-100 text-blue-600 border border-blue-200 px-3 py-2 rounded-xl text-xs transition font-bold flex items-center gap-1">
                    <i class="fa fa-calendar-alt text-blue-500"></i> ปฏิทินการจอง
                </a>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <span class="hidden sm:inline text-sm font-bold text-gray-600">👤 <?php echo htmlspecialchars($_SESSION['fullname'] ?? ''); ?></span>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-xl text-[10px] md:text-xs transition font-bold shadow-lg shadow-red-100 btn-animate">ออกจากระบบ</a>
                <?php else: ?>
                    <a href="login.php" class="text-blue-600 px-2 md:px-5 py-2 rounded-lg font-bold hover:text-blue-800 transition text-[11px] md:text-sm">เข้าสู่ระบบ</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Header Hero -->
    <header class="hero-bg text-white pt-32 pb-20 md:pt-48 md:pb-12 h-[75vh] md:h-auto flex items-center justify-center">
        <div class="container mx-auto text-center px-6">
            <h2 class="text-4xl md:text-6xl font-extrabold mb-4 drop-shadow-2xl leading-tight"><br class="md:hidden"></h2>
            <p class="text-base md:text-xl mb-12 md:mb-16 text-blue-50 font-medium opacity-90"></p>

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
                                <option value="2" <?php if($guests<=2) echo 'selected'; ?>>1-2 ท่าน</option>
                                <option value="5" <?php if($guests>2 &&$guests<=5) echo 'selected'; ?>>3-5 ท่าน</option>
                                <option value="10" <?php if($guests>5) echo 'selected'; ?>>6-10 ท่าน</option>
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
            if ($conn) {
                // 3. ปรับ SQL ดึงข้อมูลจากตาราง rafts (PostgreSQL Syntax)
                $sql = "SELECT r.* FROM rafts r 
                        WHERE (TRIM(LOWER(r.status)) = 'available' OR r.status = 'ว่าง')";

                if (!empty($search_keyword)) {$sql .= " AND (r.name LIKE '%$search_keyword%' OR r.raft_code LIKE '%$search_keyword\%' OR r.description LIKE '\%$search_keyword%')";
                }

                // ตรวจสอบกับ check_in_date ในตาราง bookings
                $sql .= " AND NOT EXISTS (
                            SELECT 1 FROM bookings b 
                            WHERE b.raft_id = r.id 
                              AND b.check_in_date = '$checkin'
                              AND b.status_id NOT IN (3, 4) -- ไม่นับรายการที่ยกเลิก
                        )
                        ORDER BY r.id DESC";

                $result = @pg_query($conn,$sql);
                
                if ($result && pg_num_rows($result) > 0):
                    while($row = pg_fetch_assoc($result)):
                        $raft_id =$row['id'];
                        $raft_name = htmlspecialchars($row['name']);
                        
                        // ระบบดึงรูปภาพแบบสลับเลือก (Smart Fallback Image)
                        $displayImg = "";
                        $target_dir = __DIR__ . "/uploads/";

                        // 1. ตรวจสอบ featured_image ก่อน
                        if (!empty($row['featured_image']) && file_exists($target_dir .$row['featured_image'])) {
                            $displayImg = "uploads/" . $row['featured_image'];
                        } else {
                            // 2. ถ้าไม่มี featured_image ให้ไล่เช็ค image_1 ถึง image_5
                            for ($i = 1; $i <= 5; $i++) {
                                $img_col = "image_" . $i;
                                if (!empty($row[$img_col]) && file_exists($target_dir .$row[$img_col])) {$displayImg = "uploads/" . $row[$img_col];
                                    break;
                                }
                            }
                        }

                        // 3. หากไม่มีรูปในระบบเลย ให้ใช้ รูป Default
                        if (empty($displayImg)) {$displayImg = "https://images.unsplash.com/photo-1544644181-1484b3fdfc62?auto=format&fit=crop&w=800&q=80";
                        }
            ?>
                <a href="booking.php?raft_id=<?php echo $raft_id; ?>&checkin=<?php echo $checkin; ?>&checkin_time=<?php echo$checkin_time; ?>&checkout=<?php echo $checkout; ?>&checkout_time=<?php echo$checkout_time; ?>" 
                   class="group bg-white rounded-[2rem] md:rounded-[2.5rem] shadow-sm hover:shadow-2xl transition duration-500 overflow-hidden border border-gray-100 flex flex-col h-full">
                    <div class="relative h-60 md:h-72 overflow-hidden bg-gray-100">
                        <img src="<?php echo $displayImg; ?>" alt="<?php echo $raft_name; ?>" class="h-full w-full object-cover transition duration-700 group-hover:scale-110">
                        <div class="absolute top-4 left-4 md:top-6 md:left-6">
                            <span class="bg-emerald-500/90 backdrop-blur-md text-white px-3 py-1.5 md:px-4 md:py-2 rounded-full text-[9px] md:text-[10px] font-black uppercase tracking-widest shadow-sm">
                                <i class="fa fa-check-circle mr-1"></i> ว่าง
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-6 md:p-8 flex flex-col flex-grow text-left">
                        <div class="flex justify-between items-start mb-2 md:mb-3">
                            <h4 class="text-xl md:text-2xl font-black text-gray-800 group-hover:text-blue-600 transition truncate pr-2"><?php echo $raft_name; ?></h4>
                            <span class="text-emerald-600 font-black text-lg md:text-xl shrink-0">฿<?php echo number_format($row['price_per_day']); ?></span>
                        </div>
                        <p class="text-gray-400 text-xs md:text-sm mb-6 line-clamp-2 leading-relaxed h-10 md:h-11"><?php echo htmlspecialchars($row['description'] ?: 'ไม่มีรายละเอียดเพิ่มเติม'); ?></p>
                        
                        <div class="mt-auto pt-4 md:pt-6 border-t border-gray-50 flex items-center justify-between">
                            <div class="flex items-center space-x-3 md:space-x-4 text-gray-400 font-bold text-[10px] md:text-xs uppercase">
                                <span><i class="fa fa-users text-blue-400 mr-1"></i> <?php echo $row['capacity']; ?> ท่าน</span>
                                <span><i class="fa fa-star text-yellow-400 mr-1"></i> 4.9</span>
                            </div>
                            
                            <div class="bg-slate-900 text-white px-4 py-2.5 md:px-6 md:py-3 rounded-xl md:rounded-2xl font-black text-xs md:text-sm group-hover:bg-blue-600 transition shadow-lg shadow-gray-200 btn-animate">
                                จองแพ
                            </div>
                        </div>
                    </div>
                </a>
            <?php 
                    endwhile;
                else:
                    echo "<div class='col-span-full py-16 md:py-24 text-center bg-white rounded-[2rem] md:rounded-[3rem] border-2 border-dashed border-gray-200'>
                            <p class='text-gray-400 text-lg md:text-xl font-bold'>🏜️ ไม่พบแพว่างที่พร้อมให้บริการในขณะนี้ (หรือยังไม่ได้เพิ่มข้อมูลแพ)</p>
                            <a href='index.php' class='mt-4 inline-block text-blue-600 font-bold hover:underline italic'>ล้างการค้นหา</a>
                          </div>";
                endif; 
            } else {
                echo "<div class='col-span-full py-10 text-center text-red-500 font-bold'>ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตั้งค่า DATABASE_URL บน Render</div>";
            }
            ?>
        </div>
    </main>
    
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
                 สัมผัสธรรมชาติที่แตกต่าง
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        new QRCode(document.getElementById("index_qrcode_canvas"), {
            text: window.location.href,
            width: 160,
            height: 160,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });

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
