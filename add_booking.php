<?php
session_start();

// รองรับทั้งกรณีไฟล์อยู่ใน root หรืออยู่ในโฟลเดอร์ย่อย (เช่น admin/)
if (file_exists(__DIR__ . '/db_config.php')) {
    require_once __DIR__ . '/db_config.php';
} else {
    require_once __DIR__ . '/../db_config.php';
}

if (!isset($_SESSION['user_id'])) {
    $login_path = file_exists(__DIR__ . '/login.php') ? 'login.php' : '../login.php';
    header("Location: " . $login_path);
    exit();
}

$msg = '';

// 1. ตรวจสอบคอลัมน์ที่มีอยู่จริงในตาราง bookings (PostgreSQL Syntax)
$b_cols = [];
if ($conn) {
    $chk_cols = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
    if ($chk_cols) {
        while ($c = pg_fetch_assoc($chk_cols)) {
            $b_cols[] = strtolower($c['column_name']);
        }
    }
}

// ตรวจสอบคอลัมน์ที่มีอยู่จริงในตาราง customers (PostgreSQL Syntax)
$c_cols = [];
if ($conn) {
    $chk_c_cols = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'customers'");
    if ($chk_c_cols) {
        while ($c = pg_fetch_assoc($chk_c_cols)) {
            $c_cols[] = strtolower($c['column_name']);
        }
    }
}

// ดึงเวลาเปิด-ปิดจากตั้งค่าระบบ
$open_time = '09:00';
$close_time = '17:30';
if ($conn) {
    $res_settings = @pg_query($conn, "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('open_time','close_time')");
    if ($res_settings) {
        while ($s = pg_fetch_assoc($res_settings)) {
            if ($s['setting_key'] === 'open_time') $open_time = $s['setting_value'];
            if ($s['setting_key'] === 'close_time') $close_time = $s['setting_value'];
        }
    }
}

// 2. จัดการบันทึกข้อมูลใหม่ (POST)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $guest_name     = trim($_POST['guest_name'] ?? '');
    $guest_tel      = trim($_POST['guest_tel'] ?? '');
    $guest_email    = trim($_POST['guest_email'] ?? '');
    $raft_id        = intval($_POST['raft_id'] ?? 0);
    $check_in_date  = trim($_POST['check_in_date'] ?? '');
    $check_in_time  = trim($_POST['check_in_time'] ?? $open_time);
    $check_out_date = trim($_POST['check_out_date'] ?? $check_in_date);
    $check_out_time = trim($_POST['check_out_time'] ?? $close_time);
    $total_price    = floatval($_POST['total_price'] ?? 0);
    $status         = trim($_POST['status'] ?? 'pending');
    $num_guests     = intval($_POST['num_guests'] ?? 1);

    $status_map = [
        'pending'   => 1,
        'confirmed' => 2,
        'cancelled' => 3,
        'completed' => 4
    ];
    $status_id = $status_map[$status] ?? 1;

    // เริ่มต้น Transaction บน PostgreSQL
    @pg_query($conn, "BEGIN");

    try {
        // สร้างหรือค้นหา customer
        $customer_id = 0;
        if (!empty($guest_name) && !empty($guest_tel)) {
            // ตรวจสอบว่ามีลูกค้าเบอร์นี้แล้วหรือยัง
            $res_find = @pg_query_params($conn, "SELECT id FROM customers WHERE phone = $1 LIMIT 1", array($guest_tel));
            
            if ($res_find && pg_num_rows($res_find) > 0) {
                $cust = pg_fetch_assoc($res_find);
                $customer_id = intval($cust['id']);
                // อัปเดตชื่อลูกค้า
                @pg_query_params($conn, "UPDATE customers SET full_name = $1 WHERE id = $2", array($guest_name, $customer_id));
            } else {
                // สร้างลูกค้าใหม่ และใช้ RETURNING id เพื่อดึง id ล่าสุด
                $ins_fields = ["full_name", "phone"];
                $ins_placeholders = ["$1", "$2"];
                $ins_vals = [$guest_name, $guest_tel];
                $p_idx = 3;

                if (!empty($guest_email) && in_array('email', $c_cols)) {
                    $ins_fields[] = "email";
                    $ins_placeholders[] = "$" . $p_idx++;
                    $ins_vals[] = $guest_email;
                }

                $sql_ins_c = "INSERT INTO customers (" . implode(", ", $ins_fields) . ") VALUES (" . implode(", ", $ins_placeholders) . ") RETURNING id";
                $res_ins_c = @pg_query_params($conn, $sql_ins_c, $ins_vals);
                if ($res_ins_c && $row_ins_c = pg_fetch_assoc($res_ins_c)) {
                    $customer_id = intval($row_ins_c['id']);
                }
            }
        }

        // สร้าง booking code อัตโนมัติ
        $booking_code = 'BK' . date('ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // เตรียมฟิลด์สำหรับ INSERT bookings แบบระบุ Parameter ($1, $2, ...)
        $fields = [];
        $placeholders = [];
        $params = [];
        $param_idx = 1;

        if (in_array('booking_code', $b_cols)) {
            $fields[] = "booking_code";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $booking_code;
        }
        if (in_array('customer_id', $b_cols) && $customer_id > 0) {
            $fields[] = "customer_id";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $customer_id;
        }
        if (in_array('raft_id', $b_cols)) {
            $fields[] = "raft_id";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $raft_id;
        }
        if (in_array('guest_name', $b_cols)) {
            $fields[] = "guest_name";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $guest_name;
        }
        if (in_array('guest_tel', $b_cols)) {
            $fields[] = "guest_tel";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $guest_tel;
        }
        if (in_array('guest_email', $b_cols)) {
            $fields[] = "guest_email";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $guest_email;
        }
        if (in_array('check_in_date', $b_cols)) {
            $fields[] = "check_in_date";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $check_in_date;
        }
        if (in_array('check_in_time', $b_cols)) {
            $fields[] = "check_in_time";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $check_in_time;
        }
        if (in_array('check_out_date', $b_cols)) {
            $fields[] = "check_out_date";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $check_out_date;
        }
        if (in_array('check_out_time', $b_cols)) {
            $fields[] = "check_out_time";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $check_out_time;
        }
        if (in_array('num_guests', $b_cols)) {
            $fields[] = "num_guests";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $num_guests;
        }

        // ราคา
        if (in_array('raft_price', $b_cols)) {
            $fields[] = "raft_price";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $total_price;
        } elseif (in_array('total_price', $b_cols)) {
            $fields[] = "total_price";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $total_price;
        }

        // สถานะ
        if (in_array('status_id', $b_cols)) {
            $fields[] = "status_id";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $status_id;
        }
        if (in_array('status', $b_cols)) {
            $fields[] = "status";
            $placeholders[] = '$' . $param_idx++;
            $params[] = $status;
        }

        // วันที่สร้าง
        if (in_array('created_at', $b_cols)) {
            $fields[] = "created_at";
            $placeholders[] = "NOW()";
        }

        if (!empty($fields)) {
            $sql_ins = "INSERT INTO bookings (" . implode(", ", $fields) . ") VALUES (" . implode(", ", $placeholders) . ")";
            $result_ins = @pg_query_params($conn, $sql_ins, $params);

            if ($result_ins) {
                // อัปเดตสถานะแพเมื่อสถานะเป็นการยืนยันแล้ว
                if ($raft_id > 0 && $status === 'confirmed') {
                    @pg_query_params($conn, "UPDATE rafts SET status = 'busy' WHERE id = $1", array($raft_id));
                }

                @pg_query($conn, "COMMIT");
                header("Location: manage_bookings.php?msg=added");
                exit();
            } else {
                @pg_query($conn, "ROLLBACK");
                $msg = 'error_insert';
            }
        } else {
            @pg_query($conn, "ROLLBACK");
            $msg = 'error_insert';
        }

    } catch (Exception $e) {
        @pg_query($conn, "ROLLBACK");
        $msg = 'error_insert';
    }
}

// 3. ดึงข้อมูลแพทั้งหมดเพื่อใส่ Dropdown (PostgreSQL)
$rafts = [];
if ($conn) {
    $res_rafts = @pg_query($conn, "SELECT id, name, price_per_day, status FROM rafts ORDER BY id ASC");
    if ($res_rafts) {
        while ($r = pg_fetch_assoc($res_rafts)) {
            $rafts[] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เพิ่มข้อมูลการจอง - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .sidebar-active { transform: translateX(0) !important; }
    </style>
</head>
<body class="bg-slate-50 flex min-h-screen">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>

    <!-- เรียกใช้ Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 px-4 lg:px-10 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="lg:hidden mr-2 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <a href="manage_bookings.php" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition" title="กลับหน้ารายการจอง">
                    <i class="fa fa-arrow-left text-lg"></i>
                </a>
                <h1 class="text-xl lg:text-2xl font-black text-slate-800">
                    <i class="fa fa-plus-circle text-blue-500 mr-1"></i> เพิ่มข้อมูลการจอง
                </h1>
            </div>
        </header>

        <div class="p-4 lg:p-10 max-w-4xl mx-auto w-full flex-grow">

            <?php if($msg === 'error_insert'): ?>
                <div class="bg-rose-500 text-white p-4 rounded-2xl mb-6 shadow-md flex items-center gap-3">
                    <i class="fa fa-exclamation-circle text-xl"></i>
                    <span class="font-bold">เกิดข้อผิดพลาด ไม่สามารถบันทึกข้อมูลได้!</span>
                </div>
            <?php endif; ?>

            <form method="POST" class="bg-white rounded-[2.5rem] shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 lg:p-10 space-y-6">

                    <!-- ส่วนข้อมูลผู้จอง -->
                    <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 space-y-4">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center gap-2">
                            <i class="fa fa-user-circle text-blue-500"></i> ข้อมูลผู้ติดต่อ
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">ชื่อผู้จอง <span class="text-rose-500">*</span></label>
                                <input type="text" name="guest_name" required placeholder="ชื่อ-นามสกุล ผู้จอง"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">เบอร์โทรศัพท์ <span class="text-rose-500">*</span></label>
                                <input type="tel" name="guest_tel" required
                                       maxlength="10" placeholder="08xxxxxxxx"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold font-mono text-slate-800 transition">
                            </div>

                            <div class="md:col-span-2 space-y-1">
                                <label class="block text-xs font-bold text-slate-600">อีเมล</label>
                                <input type="email" name="guest_email" placeholder="example@email.com"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-medium text-slate-800 transition">
                            </div>
                        </div>
                    </div>

                    <!-- ส่วนแพและช่วงเวลา -->
                    <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 space-y-4">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center gap-2">
                            <i class="fa fa-ship text-blue-500"></i> แพและช่วงเวลาที่เข้าพัก
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1 md:col-span-2">
                                <label class="block text-xs font-bold text-slate-600">แพที่จอง <span class="text-rose-500">*</span></label>
                                <select name="raft_id" id="raftSelect" required onchange="updatePrice()"
                                        class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold text-slate-800 transition">
                                    <option value="">-- เลือกแพ --</option>
                                    <?php foreach ($rafts as $r): ?>
                                        <option value="<?php echo htmlspecialchars($r['id']); ?>"
                                                data-price="<?php echo htmlspecialchars($r['price_per_day']); ?>"
                                                <?php echo ($r['status'] ?? '') === 'busy' ? 'class="text-rose-400"' : ''; ?>>
                                            ⛵ <?php echo htmlspecialchars($r['name']); ?>
                                            (฿<?php echo number_format($r['price_per_day']); ?>/วัน)
                                            <?php echo ($r['status'] ?? '') === 'busy' ? ' — 🔴 ไม่ว่าง' : ' — 🟢 ว่าง'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if(in_array('num_guests', $b_cols)): ?>
                            <div class="space-y-1 md:col-span-2">
                                <label class="block text-xs font-bold text-slate-600">จำนวนผู้เข้าพัก (คน)</label>
                                <input type="number" name="num_guests" min="1" value="1"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>
                            <?php endif; ?>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">วันที่เช็คอิน <span class="text-rose-500">*</span></label>
                                <input type="date" name="check_in_date" id="checkInDate" required
                                       value="<?php echo date('Y-m-d'); ?>" onchange="syncDates()"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">เวลาเช็คอิน <span class="text-rose-500">*</span></label>
                                <input type="time" name="check_in_time" required
                                       value="<?php echo htmlspecialchars($open_time); ?>"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">วันที่เช็คเอาท์ <span class="text-rose-500">*</span></label>
                                <input type="date" name="check_out_date" id="checkOutDate" required
                                       value="<?php echo date('Y-m-d'); ?>" onchange="calcPrice()"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>

                            <div class="space-y-1">
                                <label class="block text-xs font-bold text-slate-600">เวลาเช็คเอาท์ <span class="text-rose-500">*</span></label>
                                <input type="time" name="check_out_time" required
                                       value="<?php echo htmlspecialchars($close_time); ?>"
                                       class="w-full p-3.5 bg-white border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-bold text-slate-800 transition">
                            </div>
                        </div>
                    </div>

                    <!-- ส่วนยอดเงินและสถานะ -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="block text-xs font-bold text-slate-600">ยอดชำระทั้งหมด (บาท) <span class="text-rose-500">*</span></label>
                            <input type="number" step="0.01" name="total_price" id="totalPrice" value="0" required
                                   class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-black text-emerald-600 text-lg transition">
                        </div>

                        <div class="space-y-1">
                            <label class="block text-xs font-bold text-slate-600">สถานะการจอง <span class="text-rose-500">*</span></label>
                            <select name="status" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none font-black text-slate-800 transition">
                                <option value="pending" selected>🟡 รอตรวจสอบ (Pending)</option>
                                <option value="confirmed">🟢 ยืนยันแล้ว (Confirmed)</option>
                                <option value="completed">🔵 เสร็จสิ้น (Completed)</option>
                                <option value="cancelled">🔴 ยกเลิก (Cancelled)</option>
                            </select>
                        </div>
                    </div>

                </div>

                <div class="bg-gray-50 p-6 lg:p-8 border-t border-gray-100 flex justify-between items-center">
                    <a href="manage_bookings.php" class="text-slate-400 font-bold hover:text-slate-600 transition text-sm flex items-center gap-2">
                        <i class="fa fa-arrow-left"></i> ยกเลิก
                    </a>
                    <button type="submit" class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-bold shadow-lg shadow-blue-100 hover:bg-blue-700 active:bg-blue-800 transition text-sm flex items-center gap-2">
                        <i class="fa fa-save"></i> บันทึกข้อมูลการจอง
                    </button>
                </div>
            </form>
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

        // ซิงค์วันเช็คเอาท์ไม่ให้น้อยกว่าเช็คอิน
        function syncDates() {
            const inDate = document.getElementById('checkInDate').value;
            const outDate = document.getElementById('checkOutDate');
            if (outDate.value < inDate) {
                outDate.value = inDate;
            }
            outDate.min = inDate;
            calcPrice();
        }

        function updatePrice() {
            calcPrice();
        }

        // คำนวณราคาอัตโนมัติจากแพที่เลือก x จำนวนวัน (แก้ไขจุดคำนวณวัน)
        function calcPrice() {
            const select = document.getElementById('raftSelect');
            const option = select.options[select.selectedIndex];
            if (!option || !option.value) return;

            const pricePerDay = parseFloat(option.dataset.price || 0);
            const inDateVal = document.getElementById('checkInDate').value;
            const outDateVal = document.getElementById('checkOutDate').value;

            if (!inDateVal || !outDateVal) return;

            const inDate = new Date(inDateVal);
            const outDate = new Date(outDateVal);

            const diffTime = outDate.getTime() - inDate.getTime();
            let days = Math.round(diffTime / (1000 * 60 * 60 * 24));
            if (isNaN(days) || days < 1) {
                days = 1;
            }

            const total = pricePerDay * days;
            document.getElementById('totalPrice').value = total.toFixed(2);
        }

        document.addEventListener('DOMContentLoaded', function() {
            syncDates();
        });
    </script>
</body>
</html>
