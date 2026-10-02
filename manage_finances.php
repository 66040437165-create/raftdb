<?php
session_start();
require_once __DIR__ . '/db_config.php';

// 1. ตรวจสอบสิทธิ์การเข้าใช้งาน (เฉพาะ Admin เท่านั้น)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$is_admin = isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1;
if (!$is_admin) {
    header("Location: admin_dashboard.php");
    exit();
}

$today = date('Y-m-d');
$this_month = date('Y-m');

// 2. จัดการบันทึกรายจ่ายใหม่ (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    $title = trim($_POST['title'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $expense_date = trim($_POST['expense_date'] ?? $today);
    $note = trim($_POST['note'] ?? '');

    if (!empty($title) && $amount > 0) {
        $stmt = pg_prepare($conn, "add_exp", "INSERT INTO expenses (title, amount, expense_date, note, created_at) VALUES ($1, $2, $3, $4, NOW())");
        @pg_execute($conn, "add_exp", array($title, $amount, $expense_date, $note));
        header("Location: manage_finances.php?success=1");
        exit();
    }
}

// 3. จัดการลบรายจ่าย
if (isset($_GET['delete_expense'])) {
    $exp_id = intval($_GET['delete_expense']);
    if ($exp_id > 0) {
        pg_query_params($conn, "DELETE FROM expenses WHERE id = $1", array($exp_id));
        header("Location: manage_finances.php?deleted=1");
        exit();
    }
}

// 4. คำนวณสรุปยอดรายรับ - รายจ่าย (PostgreSQL Syntax)
$income_today = 0;
$res = @pg_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'confirmed' AND DATE(paid_at) = '$today'");
if ($res && $row = pg_fetch_assoc($res)) { $income_today = floatval($row['total']); }

$expense_today = 0;
$res = @pg_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date = '$today'");
if ($res && $row = pg_fetch_assoc($res)) { $expense_today = floatval($row['total']); }

$income_month = 0;
$res = @pg_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'confirmed' AND TO_CHAR(paid_at, 'YYYY-MM') = '$this_month'");
if ($res && $row = pg_fetch_assoc($res)) { $income_month = floatval($row['total']); }

$expense_month = 0;
$res = @pg_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE TO_CHAR(expense_date, 'YYYY-MM') = '$this_month'");
if ($res && $row = pg_fetch_assoc($res)) { $expense_month = floatval($row['total']); }

// 5. ดึงรายการรายรับล่าสุด (จาก Payments)
$recent_incomes = @pg_query($conn, "
    SELECT p.*, b.booking_id, COALESCE(c.customer_name, c.full_name, 'ลูกค้าทั่วไป') as customer_name
    FROM payments p
    LEFT JOIN bookings b ON p.booking_id = b.booking_id
    LEFT JOIN customers c ON b.customer_id = c.id
    WHERE p.status = 'confirmed'
    ORDER BY p.paid_at DESC LIMIT 10
");

// 6. ดึงรายการรายจ่ายล่าสุด (จาก Expenses)
$recent_expenses = @pg_query($conn, "SELECT * FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 10");
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการบัญชีและรายจ่าย - ChillRaft</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="bg-slate-50 flex min-h-screen">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- แถบ Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 md:px-8 sticky top-0 z-30">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <div>
                    <h2 class="text-lg md:text-xl font-bold text-gray-800">ระบบจัดการบัญชีและรายจ่าย</h2>
                    <p class="text-xs text-gray-400">บันทึกและตรวจสอบการเงินย้อนหลัง</p>
                </div>
            </div>
        </header>

        <div class="p-4 md:p-8 space-y-8">
            
            <!-- การ์ดสรุปการเงิน -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
                <div class="bg-white p-5 rounded-3xl shadow-sm border-b-4 border-emerald-500">
                    <p class="text-xs text-gray-400 font-bold uppercase">รายได้วันนี้</p>
                    <h3 class="text-2xl font-black text-emerald-600 mt-1">฿<?php echo number_format($income_today, 2); ?></h3>
                </div>
                <div class="bg-white p-5 rounded-3xl shadow-sm border-b-4 border-rose-500">
                    <p class="text-xs text-gray-400 font-bold uppercase">รายจ่ายวันนี้</p>
                    <h3 class="text-2xl font-black text-rose-600 mt-1">฿<?php echo number_format($expense_today, 2); ?></h3>
                </div>
                <div class="bg-white p-5 rounded-3xl shadow-sm border-b-4 border-indigo-500">
                    <p class="text-xs text-gray-400 font-bold uppercase">รายได้เดือนนี้</p>
                    <h3 class="text-2xl font-black text-indigo-600 mt-1">฿<?php echo number_format($income_month, 2); ?></h3>
                </div>
                <div class="bg-white p-5 rounded-3xl shadow-sm border-b-4 border-slate-700">
                    <p class="text-xs text-gray-400 font-bold uppercase">รายจ่ายเดือนนี้</p>
                    <h3 class="text-2xl font-black text-slate-700 mt-1">฿<?php echo number_format($expense_month, 2); ?></h3>
                </div>
            </div>

            <!-- ฟอร์มบันทึกรายจ่าย -->
            <div class="bg-white rounded-3xl shadow-sm p-6 md:p-8">
                <h3 class="text-base md:text-lg font-bold text-gray-800 border-l-4 border-rose-500 pl-3 mb-6">บันทึกรายจ่ายใหม่</h3>
                <form action="manage_finances.php" method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <input type="hidden" name="action" value="add_expense">
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">รายการรายจ่าย *</label>
                        <input type="text" name="title" required placeholder="เช่น ค่าค่าน้ำมัน, ค่าซ่อมแพ" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-rose-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">จำนวนเงิน (บาท) *</label>
                        <input type="number" step="0.01" name="amount" required placeholder="0.00" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-rose-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">วันที่จ่าย</label>
                        <input type="date" name="expense_date" value="<?php echo $today; ?>" class="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-rose-500">
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-rose-500 hover:bg-rose-600 text-white font-bold p-3 rounded-xl transition shadow-sm">
                            <i class="fa fa-plus-circle mr-1"></i> บันทึกรายจ่าย
                        </button>
                    </div>
                </form>
            </div>

            <!-- ตารางแสดงรายการรายรับและรายจ่าย -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                
                <!-- ตารางรายรับ -->
                <div class="bg-white rounded-3xl shadow-sm p-6">
                    <h3 class="text-base font-bold text-gray-800 border-l-4 border-emerald-500 pl-3 mb-4">ประวัติรายรับล่าสุด (การชำระเงิน)</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left font-sans text-sm">
                            <thead class="bg-slate-50 text-slate-400 text-[11px] uppercase font-black">
                                <tr>
                                    <th class="p-3">วันที่</th>
                                    <th class="p-3">ผู้ชำระ</th>
                                    <th class="p-3 text-right">จำนวนเงิน</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php if ($recent_incomes && pg_num_rows($recent_incomes) > 0): ?>
                                    <?php while($inc = pg_fetch_assoc($recent_incomes)): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 text-slate-500 text-xs"><?php echo date('d/m/Y H:i', strtotime($inc['paid_at'])); ?></td>
                                        <td class="p-3 font-bold text-slate-700"><?php echo htmlspecialchars($inc['customer_name']); ?></td>
                                        <td class="p-3 text-right font-bold text-emerald-600">+฿<?php echo number_format($inc['amount'], 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="p-6 text-center text-slate-400">ยังไม่มีข้อมูลรายรับ</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ตารางรายจ่าย -->
                <div class="bg-white rounded-3xl shadow-sm p-6">
                    <h3 class="text-base font-bold text-gray-800 border-l-4 border-rose-500 pl-3 mb-4">รายการรายจ่าย</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left font-sans text-sm">
                            <thead class="bg-slate-50 text-slate-400 text-[11px] uppercase font-black">
                                <tr>
                                    <th class="p-3">วันที่</th>
                                    <th class="p-3">รายการ</th>
                                    <th class="p-3 text-right">จำนวนเงิน</th>
                                    <th class="p-3 text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php if ($recent_expenses && pg_num_rows($recent_expenses) > 0): ?>
                                    <?php while($exp = pg_fetch_assoc($recent_expenses)): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 text-slate-500 text-xs"><?php echo date('d/m/Y', strtotime($exp['expense_date'])); ?></td>
                                        <td class="p-3 font-bold text-slate-700"><?php echo htmlspecialchars($exp['title']); ?></td>
                                        <td class="p-3 text-right font-bold text-rose-600">-฿<?php echo number_format($exp['amount'], 2); ?></td>
                                        <td class="p-3 text-center">
                                            <a href="manage_finances.php?delete_expense=<?php echo $exp['id']; ?>" onclick="return confirm('ยืนยันลบรายการนี้?')" class="text-slate-400 hover:text-rose-600 p-1">
                                                <i class="fa fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="p-6 text-center text-slate-400">ยังไม่มีข้อมูลรายจ่าย</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>
    </main>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
    </script>
</body>
</html>
