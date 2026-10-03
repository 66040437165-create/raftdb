<?php
session_start();

if (file_exists(__DIR__ . '/db_config.php')) {
    require_once __DIR__ . '/db_config.php';
} else {
    require_once __DIR__ . '/../db_config.php';
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 🟢 อัปเกรดคอลัมน์รูปภาพเป็น TEXT อัตโนมัติ (ป้องกัน SQL Error เวลาบันทึก Base64)
if ($conn) {
    @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN featured_image TYPE TEXT;");
    for ($i = 1; $i <= 5; $i++) {
        @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN image_{$i} TYPE TEXT;");
    }
}

// 🟢 ฟังก์ชันจัดการ URL รูปภาพ
if (!function_exists('get_raft_image_url')) {
    function get_raft_image_url($image_path) {
        if (empty($image_path)) return '';
        $image_path = trim($image_path);
        if (preg_match('/^(https?:\/\/|data:image\/)/i', $image_path)) {
            return $image_path;
        }
        if (strpos($image_path, 'uploads/') === 0) {
            return $image_path;
        }
        return 'uploads/' . $image_path;
    }
}

// 🟢 ฟังก์ชันย่อขนาดรูปภาพและแปลงเป็น Base64
function convert_image_to_base64($tmp_file, $max_width = 1000) {
    if (!file_exists($tmp_file)) return '';

    $image_info = @getimagesize($tmp_file);
    if ($image_info && function_exists('imagecreatefromstring')) {
        $width  = $image_info[0];
        $height = $image_info[1];
        $mime   = $image_info['mime'];

        $data = file_get_contents($tmp_file);
        $src_img = @imagecreatefromstring($data);

        if ($src_img) {
            if ($width > $max_width) {
                $new_width  = $max_width;
                $new_height = intval($height * ($max_width / $width));
                $dst_img    = imagecreatetruecolor($new_width, $new_height);

                if ($mime === 'image/png' || $mime === 'image/webp') {
                    imagealphablending($dst_img, false);
                    imagesavealpha($dst_img, true);
                    $transparent = imagecolorallocatealpha($dst_img, 255, 255, 255, 127);
                    imagefilledrectangle($dst_img, 0, 0, $new_width, $new_height, $transparent);
                }

                imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                imagedestroy($src_img);
                $src_img = $dst_img;
            }

            ob_start();
            imagejpeg($src_img, null, 80);
            $compressed_data = ob_get_clean();
            imagedestroy($src_img);

            return 'data:image/jpeg;base64,' . base64_encode($compressed_data);
        }
    }

    $raw_data = file_get_contents($tmp_file);
    $mime_type = mime_content_type($tmp_file) ?: 'image/jpeg';
    return 'data:' . $mime_type . ';base64,' . base64_encode($raw_data);
}

// 2. ดึงข้อมูลแพตาม id
if (!isset($_GET['id'])) {
    header("Location: manage_rafts.php");
    exit();
}

$id = intval($_GET['id']);
$raft = null;

if ($conn) {
    $res = @pg_query_params($conn, "SELECT * FROM rafts WHERE id = $1 LIMIT 1", array($id));
    if ($res) {
        $raft = pg_fetch_assoc($res);
    }
}

if (!$raft) { 
    header("Location: manage_rafts.php"); 
    exit(); 
}

// ดึงรายการประเภทแพ
$raft_types = [];
if ($conn) {
    $res_types = @pg_query($conn, "SELECT * FROM raft_types ORDER BY id ASC");
    if ($res_types) {
        while ($t = pg_fetch_assoc($res_types)) {
            $raft_types[] = $t;
        }
    }
}

// 3. ระบบลบรูปภาพรายช่อง
if (isset($_GET['delete_slot'])) {
    $slot = intval($_GET['delete_slot']);
    if ($slot >= 1 && $slot <= 5) {
        $col_name = "image_" . $slot;
        $img_name = trim($raft[$col_name] ?? '');

        if (!empty($img_name)) {
            if (!preg_match('/^(https?:\/\/|data:image\/)/i', $img_name)) {
                $clean_name = basename($img_name);
                $chk_used = @pg_query_params($conn, "SELECT COUNT(*) as cnt FROM rafts WHERE id != $1 AND (image_1 = $2 OR image_2 = $2 OR image_3 = $2 OR image_4 = $2 OR image_5 = $2 OR featured_image = $2)", array($id, $clean_name));
                $row_used = $chk_used ? pg_fetch_assoc($chk_used) : null;
                if (intval($row_used['cnt'] ?? 0) === 0) {
                    $target_del = (strpos($img_name, 'uploads/') === 0) ? $img_name : "uploads/" . $img_name;
                    if (file_exists($target_del)) { @unlink($target_del); }
                }
            }

            if (($raft['featured_image'] ?? '') === $img_name) {
                @pg_query_params($conn, "UPDATE rafts SET $col_name = '', featured_image = '' WHERE id = $1", array($id));
            } else {
                @pg_query_params($conn, "UPDATE rafts SET $col_name = '' WHERE id = $1", array($id));
            }
        }
    }
    header("Location: edit_raft.php?id=" . $id);
    exit();
}

// 4. ตั้งรูปหลัก (Featured Image)
if (isset($_GET['set_featured_slot'])) {
    $slot = intval($_GET['set_featured_slot']);
    if ($slot >= 1 && $slot <= 5) {
        $col_name = "image_" . $slot;
        $img_val = $raft[$col_name] ?? '';

        if (!empty($img_val)) {
            @pg_query_params($conn, "UPDATE rafts SET featured_image = $1 WHERE id = $2", array($img_val, $id));
        }
    }
    header("Location: edit_raft.php?id=" . $id);
    exit();
}

// 5. บันทึกข้อมูลและอัปโหลดรูป
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $raft_id        = intval($_POST['raft_id'] ?? 0);
    $name           = trim($_POST['name'] ?? '');
    $raft_type_id   = intval($_POST['raft_type_id'] ?? 0);
    $capacity       = intval($_POST['capacity'] ?? 0);
    $price_per_day  = floatval($_POST['price_per_day'] ?? 0);
    $price_per_hour = isset($_POST['price_per_hour']) && $_POST['price_per_hour'] !== '' ? floatval($_POST['price_per_hour']) : 0;
    $description    = trim($_POST['description'] ?? '');
    $status         = $_POST['status'] ?? 'available';

    // จัดการอัปโหลดไฟล์รูป 5 ช่อง
    $image_updates = [];
    $target_dir = __DIR__ . '/uploads/';
    if (!is_dir($target_dir)) {
        @mkdir($target_dir, 0777, true);
    }

    for ($i = 1; $i <= 5; $i++) {
        $input_name = "image_" . $i;
        if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] === 0) {
            $tmp_file = $_FILES[$input_name]["tmp_name"];
            $image_base64 = convert_image_to_base64($tmp_file);

            if (!empty($image_base64)) {
                $image_updates[$input_name] = $image_base64;
                
                // เซฟไฟล์ลงดิสก์สำรองไว้ด้วย
                $file_ext = strtolower(pathinfo($_FILES[$input_name]["name"], PATHINFO_EXTENSION)) ?: 'jpg';
                $new_file_name = "raft_{$raft_id}_{$i}_" . time() . ".{$file_ext}";
                @copy($tmp_file, $target_dir . $new_file_name);
            }
        }
    }

    // อัปเดตข้อมูลทั่วไป
    $sql_update = "UPDATE rafts SET name=$1, raft_type_id=$2, capacity=$3, price_per_day=$4, price_per_hour=$5, description=$6, status=$7 WHERE id=$8";
    @pg_query_params($conn, $sql_update, array($name, $raft_type_id, $capacity, $price_per_day, $price_per_hour, $description, $status, $raft_id));

    // อัปเดตรูปภาพที่เลือกใหม่
    foreach ($image_updates as $col => $base64_data) {
        if (preg_match('/^image_[1-5]$/', $col)) {
            @pg_query_params($conn, "UPDATE rafts SET $col = $1 WHERE id = $2", array($base64_data, $raft_id));
        }
    }

    // ซิงค์รูปหลัก (Featured Image) อัตโนมัติ: ถ้ามีการเปลี่ยนรูปที่ 1 ให้เปลี่ยนรูปหลักตามทันที
    if (isset($image_updates['image_1'])) {
        @pg_query_params($conn, "UPDATE rafts SET featured_image = $1 WHERE id = $2", array($image_updates['image_1'], $raft_id));
    } else {
        // หากรูปหลักว่างอยู่ ให้หารูปแรกที่มีมาใส่
        $res_check = @pg_query_params($conn, "SELECT * FROM rafts WHERE id = $1", array($raft_id));
        $check_raft = ($res_check) ? pg_fetch_assoc($res_check) : [];
        if (empty($check_raft['featured_image'])) {
            for ($i = 1; $i <= 5; $i++) {
                if (!empty($check_raft['image_' . $i])) {
                    @pg_query_params($conn, "UPDATE rafts SET featured_image = $1 WHERE id = $2", array($check_raft['image_' . $i], $raft_id));
                    break;
                }
            }
        }
    }

    echo "<script>alert('บันทึกการแก้ไขสำเร็จ!'); window.location.href='manage_rafts.php';</script>";
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขข้อมูลแพ - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .sidebar-active { transform: translateX(0) !important; }
    </style>
</head>
<body class="bg-slate-50 flex min-h-screen">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- เรียกใช้งาน Sidebar -->
    <?php 
    if (file_exists(__DIR__ . '/sidebar.php')) {
        include __DIR__ . '/sidebar.php';
    } elseif (file_exists(__DIR__ . '/../sidebar.php')) {
        include __DIR__ . '/../sidebar.php';
    }
    ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 md:px-10 sticky top-0 z-30">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h1 class="text-lg md:text-2xl font-bold text-gray-800 tracking-tight">แก้ไขข้อมูลแพ</h1>
            </div>
            <a href="manage_rafts.php" class="text-gray-400 hover:text-gray-600 transition font-black text-xs uppercase tracking-widest flex items-center gap-1">
                <i class="fa fa-arrow-left"></i> กลับ
            </a>
        </header>

        <div class="p-4 md:p-10 flex-grow">
            <div class="max-w-6xl mx-auto bg-white rounded-[2.5rem] shadow-sm overflow-hidden border border-gray-100">
                <div class="p-6 md:p-12">
                    <form action="" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-16">
                        
                        <!-- Image Gallery Section (5 Slots) -->
                        <div class="order-2 lg:order-1">
                            <label class="block text-xs font-black text-slate-400 mb-6 uppercase tracking-widest border-l-4 border-blue-500 pl-3 italic">จัดการรูปภาพแพ (สูงสุด 5 รูป - รูปจะไม่หายแม้เซิร์ฟเวอร์ Restart)</label>
                            
                            <div class="space-y-4">
                                <?php for ($i = 1; $i <= 5; $i++): 
                                    $col = "image_" . $i;
                                    $img_val = $raft[$col] ?? '';
                                    $img_src = get_raft_image_url($img_val);
                                    $is_featured = (!empty($img_val) && ($raft['featured_image'] ?? '') === $img_val);
                                ?>
                                    <div class="p-4 bg-slate-50 border border-slate-200 rounded-2xl flex flex-col sm:flex-row items-center gap-4">
                                        <div class="w-full sm:w-28 h-20 shrink-0 bg-gray-200 rounded-xl overflow-hidden relative border border-slate-200">
                                            <?php if (!empty($img_src)): ?>
                                                <img src="<?php echo htmlspecialchars($img_src); ?>" 
                                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'w-full h-full flex flex-col items-center justify-center text-amber-600 text-[9px] font-bold\'><i class=\'fa fa-exclamation-triangle mb-1\'></i>รูปไม่พบ</div>';" 
                                                     class="w-full h-full object-cover">
                                                <?php if ($is_featured): ?>
                                                    <span class="absolute top-1 left-1 bg-amber-500 text-white text-[9px] font-black px-2 py-0.5 rounded-md shadow">รูปหลัก</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="w-full h-full flex flex-col items-center justify-center text-slate-400 text-[10px] font-bold">
                                                    <i class="fa fa-image text-lg mb-1"></i>
                                                    ว่าง (รูปที่ <?php echo $i; ?>)
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex-grow w-full">
                                            <div class="flex justify-between items-center mb-1">
                                                <span class="text-xs font-black text-slate-700">รูปที่ <?php echo $i; ?></span>
                                                <?php if (!empty($img_val)): ?>
                                                    <div class="flex gap-2">
                                                        <?php if (!$is_featured): ?>
                                                            <a href="?id=<?php echo $id; ?>&set_featured_slot=<?php echo $i; ?>" class="text-[10px] font-bold text-amber-600 hover:underline flex items-center gap-1">
                                                                <i class="fa fa-star"></i> ตั้งเป็นรูปหลัก
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="?id=<?php echo $id; ?>&delete_slot=<?php echo $i; ?>" onclick="return confirm('ยืนยันลบรูปภาพนี้?')" class="text-[10px] font-bold text-rose-500 hover:underline flex items-center gap-1">
                                                            <i class="fa fa-trash"></i> ลบ
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <input type="file" name="image_<?php echo $i; ?>" accept="image/*"
                                                   class="block w-full text-[10px] text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-[10px] file:font-bold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100 cursor-pointer">
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- Info Form Section -->
                        <div class="space-y-6 order-1 lg:order-2">
                            <input type="hidden" name="raft_id" value="<?php echo $raft['id']; ?>">
                            
                            <div class="space-y-5">
                                <div>
                                    <label class="block text-xs font-black text-slate-400 mb-2 uppercase tracking-widest">ชื่อแพ <span class="text-rose-500">*</span></label>
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($raft['name']); ?>" required 
                                           class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                                </div>

                                <div>
                                    <label class="block text-xs font-black text-slate-400 mb-2 uppercase tracking-widest">ประเภทแพ <span class="text-rose-500">*</span></label>
                                    <select name="raft_type_id" required class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                                        <option value="">-- เลือกประเภทแพ --</option>
                                        <?php foreach ($raft_types as $t): ?>
                                            <option value="<?php echo htmlspecialchars($t['id']); ?>" <?php if(($raft['raft_type_id'] ?? 0) == $t['id']) echo 'selected'; ?>>
                                                <?php echo htmlspecialchars($t['name'] ?? $t['type_name'] ?? ('ประเภทที่ ' . $t['id'])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-black text-slate-400 mb-2 uppercase tracking-widest text-left">ความจุ (คน) <span class="text-rose-500">*</span></label>
                                        <div class="relative">
                                            <input type="number" name="capacity" value="<?php echo $raft['capacity']; ?>" required min="1"
                                                   class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 outline-none font-bold text-slate-600">
                                            <i class="fa fa-users absolute right-4 top-4 text-slate-300"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-black text-slate-400 mb-2 uppercase tracking-widest text-left">ราคาเหมาต่อวัน (฿) <span class="text-rose-500">*</span></label>
                                        <div class="relative">
                                            <input type="number" name="price_per_day" value="<?php echo $raft['price_per_day']; ?>" required min="0" step="0.01"
                                                   class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 outline-none text-emerald-600 font-black">
                                            <i class="fa fa-tag absolute right-4 top-4 text-slate-300"></i>
                                        </div>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-black text-slate-400 mb-2 uppercase tracking-widest text-left">ราคาต่อชั่วโมง (฿) <span class="text-slate-300 font-bold">(ใส่ 0 หากไม่มี)</span></label>
                                        <div class="relative">
                                            <input type="number" name="price_per_hour" value="<?php echo isset($raft['price_per_hour']) ? $raft['price_per_hour'] : '0'; ?>" min="0" step="0.01"
                                                   class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 outline-none text-blue-600 font-black">
                                            <i class="fa fa-clock absolute right-4 top-4 text-slate-300"></i>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-black text-slate-400 mb-2 uppercase tracking-widest text-left">รายละเอียดแพ</label>
                                    <textarea name="description" rows="5" placeholder="ระบุสิ่งอำนวยความสะดวก..."
                                              class="w-full p-4 bg-slate-50 rounded-3xl border border-slate-200 outline-none text-slate-600 leading-relaxed font-bold text-sm"><?php echo htmlspecialchars($raft['description']); ?></textarea>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-black text-slate-400 mb-2 uppercase tracking-widest text-left">สถานะแพ</label>
                                    <div class="relative">
                                        <select name="status" class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 outline-none font-black text-slate-700 appearance-none cursor-pointer">
                                            <option value="available" <?php if($raft['status'] == 'available') echo 'selected'; ?>>✅ พร้อมเปิดให้จอง (ว่าง)</option>
                                            <option value="busy" <?php if($raft['status'] == 'busy') echo 'selected'; ?>>⏳ กำลังใช้งาน (ไม่ว่าง)</option>
                                            <option value="pending" <?php if($raft['status'] == 'pending') echo 'selected'; ?>>🟡 รอตรวจสอบการจอง</option>
                                            <option value="maintenance" <?php if($raft['status'] == 'maintenance') echo 'selected'; ?>>🛠️ ปิดปรับปรุงชั่วคราว</option>
                                        </select>
                                        <i class="fa fa-chevron-down absolute right-4 top-4 text-slate-400 pointer-events-none"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="pt-6">
                                <button type="submit" class="w-full bg-blue-600 text-white py-4 rounded-2xl font-black uppercase tracking-widest shadow-xl shadow-blue-100 hover:bg-blue-700 hover:scale-[1.01] active:scale-95 transition-all flex items-center justify-center gap-2">
                                    <i class="fa fa-save"></i> บันทึกการแก้ไข
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                sidebar.classList.toggle('sidebar-active');
                overlay.classList.toggle('hidden');
            }
        }
    </script>
</body>
</html>
