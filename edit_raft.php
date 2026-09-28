<?php
session_start();
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// 2. ดึงข้อมูลแพตาม id
if (!isset($_GET['id'])) {
    header("Location: manage_rafts.php");
    exit();
}

$id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT * FROM rafts WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$raft = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$raft) { 
    header("Location: manage_rafts.php"); 
    exit(); 
}

// 3. ระบบลบรูปภาพรายช่อง (image_1 - image_5)
if (isset($_GET['delete_slot'])) {
    $slot = intval($_GET['delete_slot']);
    if ($slot >= 1 && $slot <= 5) {
        $col_name = "image_" . $slot;
        $img_name = $raft[$col_name];

        if (!empty($img_name)) {
            // ลบไฟล์จริงออกจาก Folder
            $full_path = "../uploads/" . $img_name;
            if (file_exists($full_path)) { @unlink($full_path); }

            // ถ้ารูปที่ลบตรงกับ featured_image ให้เคลียร์ featured_image ด้วย
            $update_featured = "";
            if ($raft['featured_image'] === $img_name) {
                $update_featured = ", featured_image = ''";
            }

            // เคลียร์ค่าใน Database
            $conn->query("UPDATE rafts SET $col_name = '' $update_featured WHERE id = $id");
        }
    }
    header("Location: edit_raft.php?id=" . $id);
    exit();
}

// 4. ระบบตั้งรูปช่องนั้นๆ เป็นรูปหลัก (Featured Image)
if (isset($_GET['set_featured_slot'])) {
    $slot = intval($_GET['set_featured_slot']);
    if ($slot >= 1 && $slot <= 5) {
        $col_name = "image_" . $slot;
        $img_name = $raft[$col_name];

        if (!empty($img_name)) {
            $stmt = $conn->prepare("UPDATE rafts SET featured_image = ? WHERE id = ?");
            $stmt->bind_param("si", $img_name, $id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: edit_raft.php?id=" . $id);
    exit();
}

// 5. บันทึกการแก้ไขข้อมูลและอัปโหลดรูปภาพใหม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $raft_id = intval($_POST['raft_id']);
    $name = trim($_POST['name'] ?? '');
    $capacity = intval($_POST['capacity'] ?? 0);
    $price_per_day = floatval($_POST['price_per_day'] ?? 0);
    $price_per_hour = isset($_POST['price_per_hour']) && $_POST['price_per_hour'] !== '' ? floatval($_POST['price_per_hour']) : 0;
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'available';

    // ดึงข้อมูลรูปภาพปัจจุบันก่อนอัปเดต
    $current_raft = $conn->query("SELECT * FROM rafts WHERE id = $raft_id")->fetch_assoc();
    $image_updates = [];

    $target_dir = "../uploads/";
    if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }

    // วนลูปจัดการไฟล์รูปภาพ 5 ช่อง (image_1 - image_5)
    for ($i = 1; $i <= 5; $i++) {
        $input_name = "image_" . $i;
        if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == 0) {
            $file_ext = strtolower(pathinfo($_FILES[$input_name]["name"], PATHINFO_EXTENSION));
            if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                
                // ถ้ามีรูปเก่าในช่องนี้ ให้ลบไฟล์เก่าก่อน
                if (!empty($current_raft[$input_name])) {
                    @unlink($target_dir . $current_raft[$input_name]);
                }

                $new_file_name = "raft_" . $raft_id . "_img" . $i . "_" . time() . "." . $file_ext;
                if (move_uploaded_file($_FILES[$input_name]["tmp_name"], $target_dir . $new_file_name)) {
                    $image_updates[$input_name] = $new_file_name;
                }
            }
        }
    }

    // อัปเดตข้อมูลทั่วไป
    $sql_update = "UPDATE rafts SET name=?, capacity=?, price_per_day=?, price_per_hour=?, description=?, status=? WHERE id=?";
    $stmt = $conn->prepare($sql_update);
    $stmt->bind_param("siddssi", $name, $capacity, $price_per_day, $price_per_hour, $description, $status, $raft_id);
    $stmt->execute();
    $stmt->close();

    // อัปเดตชื่อไฟล์รูปภาพลงคอลัมน์ image_1 - image_5
    foreach ($image_updates as $col => $filename) {
        $conn->query("UPDATE rafts SET $col = '$filename' WHERE id = $raft_id");
    }

    // ตรวจสอบรูปหลัก หากยังไม่มี หรือรูปหลักถูกลบไป ให้ดึงรูปแรกที่มีอยู่ตั้งเป็นรูปหลักอัตโนมัติ
    $check_raft = $conn->query("SELECT * FROM rafts WHERE id = $raft_id")->fetch_assoc();
    if (empty($check_raft['featured_image'])) {
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($check_raft['image_' . $i])) {
                $first_img = $check_raft['image_' . $i];
                $conn->query("UPDATE rafts SET featured_image = '$first_img' WHERE id = $raft_id");
                break;
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

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- เรียกใช้งาน Sidebar -->
    <?php include 'sidebar.php'; ?>

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
                            <label class="block text-xs font-black text-slate-400 mb-6 uppercase tracking-widest border-l-4 border-blue-500 pl-3 italic">จัดการรูปภาพแพ (สูงสุด 5 รูป)</label>
                            
                            <div class="space-y-4">
                                <?php for ($i = 1; $i <= 5; $i++): 
                                    $col = "image_" . $i;
                                    $img_name = $raft[$col] ?? '';
                                    $is_featured = (!empty($img_name) && $raft['featured_image'] === $img_name);
                                ?>
                                    <div class="p-4 bg-slate-50 border border-slate-200 rounded-2xl flex flex-col sm:flex-row items-center gap-4">
                                        <div class="w-full sm:w-28 h-20 shrink-0 bg-gray-200 rounded-xl overflow-hidden relative border border-slate-200">
                                            <?php if (!empty($img_name)): ?>
                                                <img src="../uploads/<?php echo htmlspecialchars($img_name); ?>" class="w-full h-full object-cover">
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
                                                <?php if (!empty($img_name)): ?>
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
                                            <!-- 🟢 เพิ่มสถานะ รอตรวจสอบการจอง เข้าไปในฟอร์มแก้ไข -->
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
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('sidebar-active');
            overlay.classList.toggle('hidden');
        }
    </script>
</body>
</html>