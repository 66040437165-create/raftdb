<?php
session_start();
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_bookings.php");
    exit();
}

$msg = '';
$booking_id = intval($_GET['id']);

// ตรวจสอบคอลัมน์ที่มีอยู่จริงในตาราง bookings
$b_cols = [];
$chk_cols = $conn->query("SHOW COLUMNS FROM bookings");
if ($chk_cols) {
    while ($c = $chk_cols->fetch_assoc()) {
        $b_cols[] = strtolower($c['Field']);
    }
}

// 2. จัดการบันทึกการแก้ไขข้อมูล (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $guest_name     = trim($_POST['guest_name'] ?? '');
    $guest_tel      = trim($_POST['guest_tel'] ?? '');
    $guest_email    = trim($_POST['guest_email'] ?? '');
    $raft_id        = intval($_POST['raft_id'] ?? 0);
    $check_in_date  = trim($_POST['check_in_date'] ?? '');
    $check_in_time  = trim($_POST['check_in_time'] ?? '09:00');
    $check_out_date = trim($_POST['check_out_date'] ?? $check_in_date);
    $check_out_time = trim($_POST['check_out_time'] ?? '17:30');
    $total_price    = floatval($_POST['total_price'] ?? 0);
    $status         = trim($_POST['status'] ?? 'pending');

    $status_map = [
        'pending'   => 1,
        'confirmed' => 2,
        'cancelled' => 3,
        'completed' => 4
    ];
    $status_id = $status_map[$status] ?? 1;

    // อัปเดตตาราง customers ถ้ามีการเชื่อม customer_id
    $cust_check = $conn->query("SELECT customer_id FROM bookings WHERE id = $booking_id");
    if ($cust_check && $crow = $cust_check->fetch_assoc()) {
        $cid = intval($crow['customer_id'] ?? 0);
        if ($cid > 0) {
            $stmt_u_cust = $conn->prepare("UPDATE customers SET full_name = ?, phone = ?, email = ? WHERE id = ?");
            if ($stmt_u_cust) {
                $stmt_u_cust->bind_param("sssi", $guest_name, $guest_tel, $guest_email, $cid);
                $stmt_u_cust->execute();
                $stmt_u_cust->close();
            }
        }
    }

    // เตรียมฟิลด์สำหรับ UPDATE ในตาราง bookings
    $updates = [];
    $params = [];
    $types = "";

    if (in_array('raft_id', $b_cols)) {
        $updates[] = "raft_id = ?";
        $params[] = $raft_id;
        $types .= "i";
    }
    if (in_array('guest_name', $b_cols)) {
        $updates[] = "guest_name = ?";
        $params[] = $guest_name;
        $types .= "s";
    }
    if (in_array('guest_tel', $b_cols)) {
        $updates[] = "guest_tel = ?";
        $params[] = $guest_tel;
        $types .= "s";
    }
    if (in_array('guest_email', $b_cols)) {
        $updates[] = "guest_email = ?";
        $params[] = $guest_email;
        $types .= "s";
    }

    // วันและเวลา
    if (in_array('check_in_date', $b_cols)) {
        $updates[] = "check_in_date = ?";
        $params[] = $check_in_date;
        $types .= "s";
    }
    if (in_array('check_in_time', $b_cols)) {
        $updates[] = "check_in_time = ?";
        $params[] = $check_in_time;
        $types .= "s";
    }
    if (in_array('check_out_date', $b_cols)) {
        $updates[] = "check_out_date = ?";
        $params[] = $check_out_date;
        $types .= "s";
    }
    if (in_array('check_out_time', $b_cols)) {
        $updates[] = "check_out_time = ?";
        $params[] = $check_out_time;
        $types .= "s";
    }

    // ราคา
    if (in_array('raft_price', $b_cols)) {
        $updates[] = "raft_price = ?";
        $params[] = $total_price;
        $types .= "d";
    } elseif (in_array('total_price', $b_cols)) {
        $updates[] = "total_price = ?";
        $params[] = $total_price;
        $types .= "d";
    }

    // สถานะ
    if (in_array('status_id', $b_cols)) {
        $updates[] = "status_id = ?";
        $params[] = $status_id;
        $types .= "i";
    }
    if (in_array('status', $b_cols)) {
        $updates[] = "status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if (!empty($updates)) {
        $sql_up = "UPDATE bookings SET " . implode(", ", $updates) . " WHERE id = ?";
        $params[] = $booking_id;
        $types .= "i";

        $stmt_up = $conn->prepare($sql_up);
        if ($stmt_up) {
            $stmt_up->bind_param($types, ...$params);
            if ($stmt_up->execute()) {
                // อัปเดตสถานะแพในตาราง rafts
                if ($raft_id > 0) {
                    if ($status === 'confirmed') {
                        $conn->query("UPDATE rafts SET status = 'busy' WHERE id = $raft_id");
                    } elseif ($status === 'cancelled' || $status === 'completed') {
                        $conn->query("UPDATE rafts SET status = 'available' WHERE id = $raft_id");
                    }
                }
                header("Location: manage_bookings.php?msg=updated");
                exit();
            } else {
                $msg = 'error_update';
            }
            $stmt_up->close();
        } else {
            $msg = 'error_update';
        }
    }
}

// 3. ดึงข้อมูลการจองปัจจุบัน
$stmt = $conn->prepare("
    SELECT b.*, 
           c.full_name as customer_name, 
           c.phone as customer_phone, 
           c.email as customer_email,
           r.name as raft_name
    FROM bookings b 
    LEFT JOIN customers c ON b.customer_id = c.id
    LEFT JOIN rafts r ON b.raft_id = r.id 
    WHERE b.id = ?
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: manage_bookings.php");
    exit();
}

// ดึงข้อมูลแพทั้งหมดเพื่อใส่ Dropdown (ใช้ id, name)
$rafts_result = $conn->query("SELECT id, name, price_per_day FROM rafts ORDER BY id ASC");

// สรุปข้อมูลผู้จอง
$val_name  = !empty($booking['customer_name']) ? $booking['customer_name'] : ($booking['guest_name'] ?? '');
$val_phone = !empty($booking['customer_phone']) ? $booking['customer_phone'] : ($booking['guest_tel'] ?? '');
$val_email = !empty($booking['customer_email']) ? $booking['customer_email'] : ($booking['guest_email'] ?? '');
$val_price = floatval($booking['raft_price'] ?? $booking['total_price'] ?? 0);

// วันและเวลา
$val_in_date  = $booking['check_in_date'] ?? date('Y-m-d');
$val_in_time  = !empty($booking['check_in_time']) ? date('H:i', strtotime($booking['check_in_time'])) : '09:00';
$val_out_date = $booking['check_out_date'] ?? $val_in_date;
$val_out_time = !empty($booking['check_out_time']) ? date('H:i', strtotime($booking['check_out_time'])) : '17:30';

// แปลงสถานะ
$val_status = 'pending';
$raw_sid = intval($booking['status_id'] ?? 0);
if ($raw_sid == 2 || ($booking['status'] ?? '') === 'confirmed') {
    $val_status = 'confirmed';
} elseif ($raw_sid == 3 || ($booking['status'] ?? '') === 'cancelled') {
    $val_status = 'cancelled';
} elseif ($raw_sid == 4 || ($booking['status'] ?? '') === 'completed') {
    $val_status = 'completed';
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขข้อมูลการจอง - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 flex min-h-screen">

    <!-- Sidebar -->
    <aside class="w-64 bg-slate-900 text-white p-6 sticky top-0 h-screen hidden md:block shadow-2xl shrink-0 font-sans">
        <div class="mb-10 text-center">
            <h2 class="text-2xl font-black text-blue-400 tracking-tighter uppercase w-full">CHILLRAFT</h2>
            <p class="text-xs text-slate-400 font-bold mt-1">แก้ไขข้อมูลการจอง</p>
        </div>
        <nav class="space-y-2">
            <a href="manage_bookings.php" class="flex items-center space-x-3 p-3 rounded-xl bg-blue-600 shadow-lg text-white hover:bg-blue-700 transition font-bold">
                <i class="fa fa-arrow-left w-5"></i> <span>กลับหน้ารายการจอง</span>
            </a>
            <a href="admin_dashboard.php" class="flex items-center space-x-3 p-3 rounded-xl text-slate-400 hover:bg-slate-800 transition">
                <i class="fa fa-home w-5"></i> <span>หน้าหลัก</span>
            </a>
        </nav>
    </aside>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 px-6 md:px-10 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <a href="manage_bookings.php" class="md:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-lg">
                    <i class="fa fa-arrow-left text-lg"></i>
                </a>
                <h1 class="text-xl md:text-2xl font-black text-gray-800">
                    แก้ไขข้อมูลการจอง <span class="text-blue-600 font-mono">#<?php echo $booking['booking_code'] ?? ('BK'.str_pad($booking_id, 6, '0', STR_PAD_LEFT)); ?></span>
                </h1>
            </div>
        </header>

        <div class="p-4 md:p-10 max-w-4xl mx-auto w-full">
            <?php if($msg === 'error_update'): ?>
                <div class="bg-rose-500 text-white p-4 rounded-2xl mb-6 shadow-md flex items-center gap-3">
                    <i class="fa fa-exclamation-circle text-xl"></i>
                    <span class="font-bold">เกิดข้อผิดพลาด ไม่สามารถบันทึกข้อมูลได้!</span>
                </div>
            <?php endif; ?>

            <form method="POST" class="bg-white rounded-[2.5rem] shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 md:p-10 space-y-6">
                    
                    <!-- ส่วนข้อมูลผู้จอง -->
                    <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 space-y-4">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center gap-2">
                            <i class="fa fa-user-circle text-blue-500"></i> ข้อมูลผู้ติดต่อ
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">ชื่อผู้จอง <span class="text-rose-500">*</span></label>
                                <input type="text" name="guest_name" value="<?php echo htmlspecialchars($val_name); ?>" required
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">เบอร์โทรศัพท์ <span class="text-rose-500">*</span></label>
                                <input type="tel" name="guest_tel" value="<?php echo htmlspecialchars($val_phone); ?>" required
                                       maxlength="10" placeholder="08xxxxxxxx"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-bold font-mono text-slate-800 transition">
                            </div>

                            <div class="md:col-span-2 space-y-1">
                                <label class="block text-xs font-bold text-slate-600">อีเมล</label>
                                <input type="email" name="guest_email" value="<?php echo htmlspecialchars($val_email); ?>" placeholder="example@email.com"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-medium text-slate-800 transition">
                            </div>
                        </div>
                    </div>

                    <!-- ส่วนแพและช่วงเวลา -->
                    <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 space-y-4">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center gap-2">
                            <i class="fa fa-ship text-blue-500"></i> แพและช่วงเวลาที่เข้าพัก
                        </h3>
                        
                        <div class="space-y-1">
                            <label class="block text-xs font-bold text-slate-600">แพที่จอง <span class="text-rose-500">*</span></label>
                            <select name="raft_id" required class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                                <?php if($rafts_result && $rafts_result->num_rows > 0): while($r = $rafts_result->fetch_assoc()): ?>
                                    <option value="<?php echo $r['id']; ?>" <?php echo ($booking['raft_id'] == $r['id']) ? 'selected' : ''; ?>>
                                        ⛵ <?php echo htmlspecialchars($r['name']); ?> (฿<?php echo number_format($r['price_per_day']); ?>/วัน)
                                    </option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">วันที่เช็คอิน <span class="text-rose-500">*</span></label>
                                <input type="date" name="check_in_date" value="<?php echo $val_in_date; ?>" required
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">เวลาเช็คอิน <span class="text-rose-500">*</span></label>
                                <input type="time" name="check_in_time" value="<?php echo $val_in_time; ?>" required
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">วันที่เช็คเอาท์ <span class="text-rose-500">*</span></label>
                                <input type="date" name="check_out_date" value="<?php echo $val_out_date; ?>" required
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">เวลาเช็คเอาท์ <span class="text-rose-500">*</span></label>
                                <input type="time" name="check_out_time" value="<?php echo $val_out_time; ?>" required
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>
                        </div>
                    </div>

                    <!-- ส่วนยอดเงินและสถานะ -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="block text-xs font-bold text-slate-600">ยอดชำระทั้งหมด (บาท) <span class="text-rose-500">*</span></label>
                            <input type="number" step="0.01" name="total_price" value="<?php echo $val_price; ?>" required
                                   class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-black text-emerald-600 text-lg transition">
                        </div>

                        <div class="space-y-1">
                            <label class="block text-xs font-bold text-slate-600">สถานะการจอง <span class="text-rose-500">*</span></label>
                            <select name="status" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 outline-none font-black text-slate-800 transition">
                                <option value="pending" <?php echo ($val_status === 'pending') ? 'selected' : ''; ?>>🟡 รอตรวจสอบ (Pending)</option>
                                <option value="confirmed" <?php echo ($val_status === 'confirmed') ? 'selected' : ''; ?>>🟢 ยืนยันแล้ว (Confirmed)</option>
                                <option value="completed" <?php echo ($val_status === 'completed') ? 'selected' : ''; ?>>🔵 เสร็จสิ้น (Completed)</option>
                                <option value="cancelled" <?php echo ($val_status === 'cancelled') ? 'selected' : ''; ?>>🔴 ยกเลิก (Cancelled)</option>
                            </select>
                        </div>
                    </div>

                </div>

                <div class="bg-gray-50 p-6 md:p-8 border-t border-gray-100 flex justify-between items-center">
                    <a href="manage_bookings.php" class="text-slate-400 font-bold hover:text-slate-600 transition text-sm">
                        ยกเลิก
                    </a>
                    <button type="submit" class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-bold shadow-lg shadow-blue-100 hover:bg-blue-700 transition text-sm flex items-center gap-2">
                        <i class="fa fa-save"></i> บันทึกการแก้ไข
                    </button>
                </div>
            </form>
        </div>
    </main>
</body>
</html>