<?php
session_start();
require_once __DIR__ . '/../db_config.php';

// 1. ตรวจสอบว่าล็อกอินแล้วหรือยัง (ทั้ง Admin และ Staff เข้าใช้งาน Dashboard ได้)
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$is_admin = isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1;

// 2. ดึงข้อมูลสถิติพื้นฐานสำหรับการดำเนินงาน (Admin และ Staff ดูได้)
$res_rafts = $conn->query("SELECT COUNT(*) as total FROM rafts");
$total_rafts = ($res_rafts && $row = $res_rafts->fetch_assoc()) ? intval($row['total']) : 0;

$res_users = $conn->query("SELECT COUNT(*) as total FROM customers");
$total_users = ($res_users && $row = $res_users->fetch_assoc()) ? intval($row['total']) : 0;

$res_pending = $conn->query("SELECT COUNT(*) as total FROM bookings WHERE status = 'pending'");
$pending_bookings = ($res_pending && $row = $res_pending->fetch_assoc()) ? intval($row['total']) : 0;

// 3. คำนวณรายได้และรายจ่าย (ดึงเฉพาะเมื่อเป็น Admin เท่านั้น)
$income_today  = 0;
$income_month  = 0;
$expense_today = 0;
$expense_month = 0;

if ($is_admin) {
    $today = date('Y-m-d');
    $this_month = date('Y-m');

    // คำนวณรายได้
    $res_inc_today = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'confirmed' AND DATE(paid_at) = '$today'");
    $income_today = ($res_inc_today && $row = $res_inc_today->fetch_assoc()) ? floatval($row['total']) : 0;

    $res_inc_month = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'confirmed' AND paid_at LIKE '$this_month%'");
    $income_month = ($res_inc_month && $row = $res_inc_month->fetch_assoc()) ? floatval($row['total']) : 0;

    // คำนวณรายจ่าย
    $res_exp_today = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date = '$today'");
    $expense_today = ($res_exp_today && $row = $res_exp_today->fetch_assoc()) ? floatval($row['total']) : 0;

    $res_exp_month = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date LIKE '$this_month%'");
    $expense_month = ($res_exp_month && $row = $res_exp_month->fetch_assoc()) ? floatval($row['total']) : 0;
}

// 4. ดึงรายการจอง 5 รายการล่าสุด
$recent_bookings = $conn->query("
    SELECT b.*, r.raft_name, 
           COALESCE(c.customer_name, c.full_name, 'ลูกค้าทั่วไป') as customer_name, 
           p.amount as paid_amount, p.status as payment_status
    FROM bookings b
    LEFT JOIN rafts r ON b.raft_id = r.raft_id
    LEFT JOIN customers c ON b.customer_id = c.id
    LEFT JOIN payments p ON b.booking_id = p.booking_id
    ORDER BY b.booking_id DESC LIMIT 5
");
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบหลังบ้าน - ChillRaft</title>
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

    <!-- แถบ Sidebar เมนูข้าง -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 md:px-8 sticky top-0 z-30">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <div>
                    <h2 class="text-lg md:text-xl font-bold text-gray-800">ภาพรวมระบบ</h2>
                    <p class="text-xs text-gray-400">ยินดีต้อนรับสู่ระบบบริหารจัดการแพ</p>
                </div>
            </div>
            <div class="flex items-center space-x-3">
                <div class="text-right hidden sm:block">
                    <p class="text-xs md:text-sm font-bold text-gray-700"><?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']); ?></p>
                    <span class="inline-block text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo $is_admin ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'; ?>">
                        <?php echo $is_admin ? 'ผู้ดูแลระบบ (Admin)' : 'พนักงาน (Staff)'; ?>
                    </span>
                </div>
                <div class="w-9 h-9 md:w-10 md:h-10 <?php echo $is_admin ? 'bg-amber-500' : 'bg-blue-500'; ?> rounded-full flex items-center justify-center text-white font-bold text-base shadow-sm">
                    <?php echo mb_substr($_SESSION['fullname'] ?? 'U', 0, 1); ?>
                </div>
            </div>
        </header>

        <div class="p-4 md:p-8 space-y-8">
            
            <!-- การ์ดตัวเลขสถิติภาพรวม -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
                <a href="manage_rafts.php" class="bg-white p-5 md:p-6 rounded-3xl shadow-sm border-b-4 border-blue-500 hover:shadow-md transition block">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-2xl text-blue-600 mr-4">
                            <i class="fa fa-ship text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-[10px] md:text-xs text-gray-400 font-bold uppercase tracking-wider">แพทั้งหมด</p>
                            <h3 class="text-xl md:text-2xl font-black text-slate-800"><?php echo $total_rafts; ?> หลัง</h3>
                        </div>
                    </div>
                </a>

                <a href="manage_bookings.php" class="bg-white p-5 md:p-6 rounded-3xl shadow-sm border-b-4 border-amber-500 hover:shadow-md transition block">
                    <div class="flex items-center">
                        <div class="p-3 bg-amber-100 rounded-2xl text-amber-600 mr-4">
                            <i class="fa fa-clock text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-[10px] md:text-xs text-gray-400 font-bold uppercase tracking-wider">รอตรวจสอบการจอง</p>
                            <h3 class="text-xl md:text-2xl font-black text-slate-800"><?php echo $pending_bookings; ?> รายการ</h3>
                        </div>
                    </div>
                </a>

                <a href="manage_customers.php" class="bg-white p-5 md:p-6 rounded-3xl shadow-sm border-b-4 border-emerald-500 hover:shadow-md transition block">
                    <div class="flex items-center">
                        <div class="p-3 bg-emerald-100 rounded-2xl text-emerald-600 mr-4">
                            <i class="fa fa-user-friends text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-[10px] md:text-xs text-gray-400 font-bold uppercase tracking-wider">สมาชิก / ลูกค้า</p>
                            <h3 class="text-xl md:text-2xl font-black text-slate-800"><?php echo $total_users; ?> ท่าน</h3>
                        </div>
                    </div>
                </a>
            </div>

            <!-- ส่วนแสดงตัวเลขการเงิน (แสดงเฉพาะสิทธิ์ Admin เท่านั้น) -->
            <?php if ($is_admin): ?>
            <div>
                <h3 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <i class="fa fa-wallet text-slate-400"></i> ข้อมูลสรุปการเงิน (เฉพาะผู้ดูแลระบบ)
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
                    <a href="manage_finances.php?filter=income_today" class="bg-white p-5 rounded-3xl shadow-sm border-b-4 border-emerald-500 hover:shadow-md transition block">
                        <p class="text-xs text-gray-400 font-bold uppercase">รายได้วันนี้</p>
                        <h3 class="text-xl font-black text-emerald-600 mt-1">฿<span id="income_today"><?php echo number_format($income_today, 2); ?></span></h3>
                    </a>

                    <a href="manage_finances.php?filter=expense_today" class="bg-white p-5 rounded-3xl shadow-sm border-b-4 border-rose-500 hover:shadow-md transition block">
                        <p class="text-xs text-gray-400 font-bold uppercase">รายจ่ายวันนี้</p>
                        <h3 class="text-xl font-black text-rose-600 mt-1">฿<span id="expense_today"><?php echo number_format($expense_today, 2); ?></span></h3>
                    </a>

                    <a href="manage_finances.php?filter=income_month" class="bg-white p-5 rounded-3xl shadow-sm border-b-4 border-indigo-500 hover:shadow-md transition block">
                        <p class="text-xs text-gray-400 font-bold uppercase">รายได้เดือนนี้</p>
                        <h3 class="text-xl font-black text-indigo-600 mt-1">฿<span id="income_month"><?php echo number_format($income_month, 2); ?></span></h3>
                    </a>

                    <a href="manage_finances.php?filter=expense_month" class="bg-white p-5 rounded-3xl shadow-sm border-b-4 border-slate-700 hover:shadow-md transition block">
                        <p class="text-xs text-gray-400 font-bold uppercase">รายจ่ายเดือนนี้</p>
                        <h3 class="text-xl font-black text-slate-700 mt-1">฿<span id="expense_month"><?php echo number_format($expense_month, 2); ?></span></h3>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- เมนูจัดการด่วน -->
            <div class="bg-white rounded-3xl shadow-sm p-6 md:p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-base md:text-lg font-bold text-gray-800 border-l-4 border-blue-600 pl-3">เมนูจัดการด่วน</h3>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <a href="add_raft.php" class="flex items-center p-5 border-2 border-dashed border-gray-100 rounded-2xl hover:border-blue-300 hover:bg-blue-50/50 transition group">
                        <div class="bg-blue-500 text-white p-3 rounded-xl mr-4 group-hover:scale-105 transition shadow-sm">
                            <i class="fa fa-plus text-lg"></i>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-800 text-sm md:text-base">เพิ่มแพใหม่</h4>
                            <p class="text-xs text-gray-400">ใส่รูปภาพ ราคา และรายละเอียดแพ</p>
                        </div>
                    </a>

                    <a href="manage_rafts.php" class="flex items-center p-5 border-2 border-dashed border-gray-100 rounded-2xl hover:border-slate-300 hover:bg-slate-50/50 transition group">
                        <div class="bg-slate-700 text-white p-3 rounded-xl mr-4 group-hover:scale-105 transition shadow-sm">
                            <i class="fa fa-ship text-lg"></i>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-800 text-sm md:text-base">จัดการแพทั้งหมด</h4>
                            <p class="text-xs text-gray-400">ตรวจสอบสถานะ แก้ไข หรือลบแพ</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- ตารางรายการจองล่าสุด -->
            <div class="bg-white rounded-3xl shadow-sm p-6 md:p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-base md:text-lg font-bold text-gray-800 border-l-4 border-blue-600 pl-3">รายการจองล่าสุด 5 รายการ</h3>
                    <a href="manage_bookings.php" class="text-blue-600 hover:text-blue-700 font-bold text-xs md:text-sm">ดูรายการจองทั้งหมด →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left font-sans">
                        <thead class="bg-slate-50 text-slate-400 text-[11px] uppercase font-black tracking-widest border-b border-slate-100">
                            <tr>
                                <th class="p-4">รหัสจอง</th>
                                <th class="p-4">ผู้จอง</th>
                                <th class="p-4">แพ</th>
                                <th class="p-4">วันเช็คอิน</th>
                                <th class="p-4">ยอดรวม</th>
                                <th class="p-4 text-center">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50 text-sm">
                            <?php if ($recent_bookings && $recent_bookings->num_rows > 0): ?>
                                <?php while($row = $recent_bookings->fetch_assoc()): 
                                    $booker = !empty($row['guest_name']) ? $row['guest_name'] : ($row['customer_name'] ?? 'ลูกค้าทั่วไป');
                                    $status_map = [
                                        'pending'   => ['bg-amber-100 text-amber-700', 'รอตรวจสอบ'],
                                        'confirmed' => ['bg-emerald-100 text-emerald-700', 'ยืนยันแล้ว'],
                                        'completed' => ['bg-blue-100 text-blue-700', 'เสร็จสิ้น'],
                                        'cancelled' => ['bg-rose-100 text-rose-700', 'ยกเลิก']
                                    ];
                                    $badge = $status_map[$row['status']] ?? ['bg-gray-100 text-gray-700', $row['status']];
                                    $checkin_display = !empty($row['check_in']) ? date('d/m/Y H:i', strtotime($row['check_in'])) : '-';
                                ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-4 font-mono font-bold text-blue-600">#<?php echo str_pad($row['booking_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                    <td class="p-4 font-bold text-slate-700"><?php echo htmlspecialchars($booker); ?></td>
                                    <td class="p-4 text-slate-600"><?php echo htmlspecialchars($row['raft_name'] ?? '-'); ?></td>
                                    <td class="p-4 text-slate-500"><?php echo $checkin_display; ?></td>
                                    <td class="p-4 font-bold text-emerald-600">฿<?php echo number_format($row['total_price'] ?? 0); ?></td>
                                    <td class="p-4 text-center">
                                        <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $badge[0]; ?>"><?php echo $badge[1]; ?></span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="p-8 text-center text-slate-400">ยังไม่มีรายการจองในระบบ</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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

        <?php if ($is_admin): ?>
        // อัปเดตสถิติการเงิน Real-time เฉพาะผู้ใช้งานที่เป็น Admin
        function updateStats() {
            fetch('get_finance_stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.error) return;
                    if (document.getElementById('income_today')) document.getElementById('income_today').innerText = data.income_today;
                    if (document.getElementById('expense_today')) document.getElementById('expense_today').innerText = data.expense_today;
                    if (document.getElementById('income_month')) document.getElementById('income_month').innerText = data.income_month;
                    if (document.getElementById('expense_month')) document.getElementById('expense_month').innerText = data.expense_month;
                })
                .catch(err => console.error('Error fetching stats:', err));
        }

        setInterval(updateStats, 10000);
        <?php endif; ?>
    </script>
</body>
</html>
