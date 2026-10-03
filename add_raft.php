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

$error_msg = "";

// 🟢 ฟังก์ชันย่อขนาดรูปภาพและแปลงเป็น Base64 อัตโนมัติ (ป้องกันรูปหายบน Render 100%)
function convert_image_to_base64($tmp_file, $max_width = 1000) {
    if (!file_exists($tmp_file)) return '';

    // ตรวจสอบข้อมูลรูปภาพ
    $image_info = @getimagesize($tmp_file);
    if ($image_info && function_exists('imagecreatefromstring')) {
        $width  = $image_info[0];
        $height = $image_info[1];
        $mime   = $image_info['mime'];

        $data = file_get_contents($tmp_file);
        $src_img = @imagecreatefromstring($data);

        if ($src_img) {
            // ย่อขนาดถ้ารูปกว้างเกิน $max_width
            if ($width > $max_width) {
                $new_width  = $max_width;
                $new_height = intval($height * ($max_width / $width));
                $dst_img    = imagecreatetruecolor($new_width, $new_height);

                // รองรับพื้นหลังโปร่งใส (PNG / WEBP)
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

            // บีบอัดเป็น JPG คุณภาพ 80% เพื่อให้ไฟล์เบา
            ob_start();
            imagejpeg($src_img, null, 80);
            $compressed_data = ob_get_clean();
            imagedestroy($src_img);

            return 'data:image/jpeg;base64,' . base64_encode($compressed_data);
        }
    }

    // กรณีเซิร์ฟเวอร์ไม่มี GD ให้แปลงตรงๆ
    $raw_data = file_get_contents($tmp_file);
    $mime_type = mime_content_type($tmp_file) ?: 'image/jpeg';
    return 'data:' . $mime_type . ';base64,' . base64_encode($raw_data);
}

// ดึงรายการประเภทแพสำหรับใส่ Dropdown (PostgreSQL)
$raft_types = [];
if ($conn) {
    $res_types = @pg_query($conn, "SELECT * FROM raft_types ORDER BY id ASC");
    if ($res_types) {
        while ($t = pg_fetch_assoc($res_types)) {
            $raft_types[] = $t;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name          = trim($_POST['name'] ?? '');
    $raft_type_id  = intval($_POST['raft_type_id'] ?? 0);
    $capacity      = intval($_POST['capacity'] ?? 0);
    $price_day     = floatval($_POST['price_per_day'] ?? 0);
    $price_hour    = isset($_POST['price_per_hour']) && $_POST['price_per_hour'] !== '' ? floatval($_POST['price_per_hour']) : 0;
    
    $status        = trim($_POST['status'] ?? 'available');
    $desc          = trim($_POST['description'] ?? '');
    $image_url_opt = trim($_POST['image_url'] ?? ''); // รองรับการแปะ URL รูปตรงๆ

    // สร้างรหัสแพอัตโนมัติ
    $raft_code     = 'RAFT-' . date('ym') . rand(100, 999);

    if (!empty($name) && $raft_type_id > 0 && $capacity > 0 && $price_day > 0) {
        
        $featured_image = "";
        $images = ['', '', '', '', '']; // เตรียมพื้นที่สำหรับ image_1 ถึง image_5
        $img_index = 0;

        // 1. ตรวจสอบกรณีผู้ใช้แปะเป็น URL ลิงก์รูปภาพ
        if (!empty($image_url_opt)) {
            $featured_image = $image_url_opt;
            $images[0] = $image_url_opt;
            $img_index = 1;
        }

        // 2. จัดการรูปภาพที่อัปโหลดจากเครื่อง -> แปลงเป็น Base64 เก็บลง DB ถาวร
        if (!empty($_FILES['raft_images']['name'][0])) {
            foreach ($_FILES['raft_images']['name'] as $key => $val) {
                if ($img_index >= 5) break; // จำกัดสูงสุด 5 รูป
                
                if (isset($_FILES['raft_images']['error'][$key]) && $_FILES['raft_images']['error'][$key] === 0) {
                    $tmp_file = $_FILES["raft_images"]["tmp_name"][$key];
                    $image_base64 = convert_image_to_base64($tmp_file);

                    if (!empty($image_base64)) {
                        if (empty($featured_image)) {
                            $featured_image = $image_base64; // รูปแรกเป็นรูปหลัก
                        }
                        $images[$img_index] = $image_base64;
                        $img_index++;
                    }
                }
            }
        }

        // 3. บันทึกข้อมูลลงตาราง rafts (PostgreSQL syntax)
        $sql_insert = "INSERT INTO rafts (
            raft_code, name, raft_type_id, capacity, price_per_day, price_per_hour, 
            description, status, is_active, featured_image, image_1, image_2, image_3, image_4, image_5
        ) VALUES (
            $1, $2, $3, $4, $5, $6, $7, $8, 1, $9, $10, $11, $12, $13, $14
        )";

        $params = array(
            $raft_code,$name,
            $raft_type_id,$capacity,
            $price_day,$price_hour,
            $desc,$status,
            $featured_image,$images[0],
            $images[1],$images[2],
            $images[3],$images[4]
        );

        $result_insert = @pg_query_params($conn, $sql_insert,$params);

        if ($result_insert) {
            header("Location: manage_rafts.php?msg=added");
            exit();
        } else {
            $error_msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . pg_last_error($conn);
        }

    } else {
        $error_msg = "กรุณากรอกข้อมูลและเลือกประเภทแพให้ครบถ้วน";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เพิ่มแพใหม่ - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; }</style>
</head>
<body class="bg-gray-100 flex min-h-screen">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>

    <!-- เรียกใช้ Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 md:px-10 sticky top-0 z-30">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="lg:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h1 class="text-lg md:text-2xl font-bold text-gray-800">จัดการข้อมูลแพ</h1>
            </div>
            <a href="manage_rafts.php" class="text-gray-400 hover:text-gray-600 transition font-black text-xs uppercase tracking-widest flex items-center gap-1">
                <i class="fa fa-arrow-left"></i> กลับ
            </a>
        </header>

        <div class="p-4 md:p-10 flex-grow">
            <div class="max-w-2xl mx-auto bg-white p-6 md:p-10 rounded-[2.5rem] shadow-sm border border-gray-100">
                
                <?php if(!empty($error_msg)): ?>
                    <div class="mb-6 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-2xl text-sm font-bold flex items-center gap-2">
                        <i class="fa fa-exclamation-circle text-lg"></i> <?php echo htmlspecialchars($error_msg); ?>
                    </div>
                <?php endif; ?>

                <h2 class="text-2xl font-black mb-8 text-slate-800 italic uppercase tracking-tighter border-b pb-4 flex items-center gap-3">
                    <div class="bg-blue-500 text-white w-10 h-10 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-100">
                        <i class="fa fa-plus text-lg"></i>
                    </div>
                    เพิ่มแพใหม่
                </h2>
                
                <form action="" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <div>
                        <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">ชื่อแพ <span class="text-rose-500">*</span></label>
                        <input type="text" name="name" required placeholder="เช่น แพริมน้ำ 101" 
                               class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-800 font-bold placeholder:text-slate-300 transition">
                    </div>

                    <div>
                        <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">ประเภทแพ <span class="text-rose-500">*</span></label>
                        <select name="raft_type_id" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-800 font-bold transition">
                            <option value="">-- เลือกประเภทแพ --</option>
                            <?php foreach ($raft_types as$t): ?>
                                <option value="<?php echo htmlspecialchars($t['id']); ?>">
                                    <?php echo htmlspecialchars($t['name'] ?? $t['type_name'] ?? ('ประเภทที่ ' . $t['id'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">ความจุ (คน) <span class="text-rose-500">*</span></label>
                            <input type="number" name="capacity" required min="1" placeholder="เช่น 4"
                                   class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-800 font-bold placeholder:text-slate-300 transition">
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">ราคาต่อวัน (บาท) <span class="text-rose-500">*</span></label>
                            <input type="number" name="price_per_day" required min="0" step="0.01" placeholder="เช่น 2500"
                                   class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-800 font-bold placeholder:text-slate-300 transition">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">ราคาต่อชั่วโมง (บาท) <span class="text-[10px] text-slate-300 select-none">(ทิ้งว่างหากไม่มี)</span></label>
                            <input type="number" name="price_per_hour" min="0" step="0.01" placeholder="เช่น 500"
                                   class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-800 font-bold placeholder:text-slate-300 transition">
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">สถานะเริ่มต้น <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <select name="status" required class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-800 font-bold transition appearance-none cursor-pointer">
                                    <option value="available">✅ พร้อมเปิดให้จอง (ว่าง)</option>
                                    <option value="busy">⏳ กำลังใช้งาน (ไม่ว่าง)</option>
                                    <option value="pending">🟡 รอตรวจสอบการจอง</option>
                                    <option value="maintenance">🛠️ ปิดปรับปรุงชั่วคราว</option>
                                </select>
                                <i class="fa fa-chevron-down absolute right-4 top-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>
                    </div>

                    <!-- ส่วนอัปโหลดรูปภาพ -->
                    <div class="border-t border-slate-100 pt-4">
                        <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">1. ลิงก์ URL รูปภาพหลัก (ถ้ามี)</label>
                        <input type="url" name="image_url" placeholder="https://example.com/image.jpg หรือลิงก์รูปภาพ"
                               class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-800 font-bold placeholder:text-slate-300 transition text-sm mb-4">

                        <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">2. เลือกอัปโหลดไฟล์จากเครื่อง (สูงสุด 5 รูป - รูปจะไม่หายแม้เซิร์ฟเวอร์ Restart)</label>
                        <div class="flex items-center justify-center w-full">
                            <label class="flex flex-col items-center justify-center w-full h-36 border-2 border-slate-200 border-dashed rounded-[2rem] cursor-pointer bg-slate-50 hover:bg-blue-50 transition p-6 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="bg-white w-10 h-10 rounded-2xl flex items-center justify-center shadow-sm mb-2">
                                        <i class="fa fa-cloud-upload-alt text-blue-500 text-lg"></i>
                                    </div>
                                    <p class="text-xs text-slate-500 font-bold"><span class="text-blue-600">คลิกเพื่ออัปโหลด</span> หรือลากไฟล์มาวาง</p>
                                    <p class="text-[10px] text-slate-400 mt-1 uppercase tracking-tighter">PNG, JPG, WEBP</p>
                                </div>
                                <input type="file" name="raft_images[]" accept="image/*" multiple class="hidden" id="img-input" onchange="previewImages(event)" />
                            </label>
                        </div>
                        <div id="preview-container" class="mt-4 hidden">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">ตัวอย่างรูปภาพที่เลือก:</p>
                            <div id="img-previews" class="grid grid-cols-2 sm:grid-cols-4 gap-4"></div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-black uppercase tracking-widest mb-2 text-slate-400">รายละเอียดเพิ่มเติม</label>
                        <textarea name="description" rows="4" placeholder="ระบุสิ่งอำนวยความสะดวก เช่น แอร์, ทีวี, คาราโอเกะ..."
                                  class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none text-slate-800 font-bold placeholder:text-slate-300 transition"></textarea>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3 pt-4">
                        <button type="submit" class="flex-grow-[2] bg-blue-600 text-white p-4 rounded-2xl hover:bg-blue-700 font-black uppercase tracking-widest transition shadow-xl shadow-blue-100 flex items-center justify-center gap-2">
                            <i class="fa fa-save"></i> บันทึกข้อมูล
                        </button>
                        <a href="manage_rafts.php" class="flex-grow bg-slate-100 text-slate-500 p-4 rounded-2xl hover:bg-slate-200 font-black uppercase tracking-widest transition text-center flex items-center justify-center">
                            ยกเลิก
                        </a>
                    </div>
                </form>
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

        function previewImages(event) {
            const input = event.target;
            const container = document.getElementById('preview-container');
            const previewWrapper = document.getElementById('img-previews');
            previewWrapper.innerHTML = '';
            
            if (input.files && input.files.length > 0) {
                container.classList.remove('hidden');
                Array.from(input.files).slice(0, 5).forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = "relative aspect-[4/3] rounded-2xl overflow-hidden border-2 border-white shadow-md";
                        div.innerHTML = `
                            <img src="${e.target.result}" class="w-full h-full object-cover">
                            ${index === 0 ? '<span class="absolute top-2 left-2 bg-blue-600 text-white text-[8px] px-2 py-0.5 rounded-full font-black uppercase shadow">รูปหลัก</span>' : ''}
                            <span class="absolute bottom-2 right-2 bg-black/60 backdrop-blur-sm text-white text-[9px] px-2 py-0.5 rounded-full font-bold">#${index + 1}</span>
                        `;
                        previewWrapper.appendChild(div);
                    }
                    reader.readAsDataURL(file);
                });
            } else {
                container.classList.add('hidden');
            }
        }
    </script>
</body>
</html>
