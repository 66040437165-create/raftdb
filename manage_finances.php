<?php
session_start();
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 1) {
    header("Location: admin_dashboard.php?msg=access_denied");
    exit();
}

// สร้างตาราง expenses อัตโนมัติสำหรับ PostgreSQL หากยังไม่มี
if ($conn) {
    @pg_query($conn, "CREATE TABLE IF NOT EXISTS expenses (
        expense_id SERIAL PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        amount NUMERIC(10,2) NOT NULL,
        expense_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

$today = date('Y-m-d');
$this_month = date('Y-m');

// 2. ระบบเพิ่มค่าใช้จ่าย (PostgreSQL Prepared Statement)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_expense'])) {
    $title = trim($_POST['title']);
    $amount = floatval($_POST['amount']);
    $date = !empty($_POST['expense_date']) ? $_POST['expense_date'] : $today;
    
    $res = @pg_query_params($conn, "INSERT INTO expenses (title, amount, expense_date) VALUES ($1, $2, $3)", array($title, $amount, $date));
    
    if ($res) {
        header("Location: manage_finances.php?msg=success");
    } else {
        header("Location: manage_finances.php?msg=error");
    }
    exit();
}

// 3. ระบบลบค่าใช้จ่าย
if (isset($_GET['delete_expense'])) {
    $id = intval($_GET['delete_expense']);
    @pg_query_params($conn, "DELETE FROM expenses WHERE expense_id = $1", array($id));
    
    header("Location: manage_finances.php?msg=deleted");
    exit();
}

// 4. คำนวณสรุปยอดรายรับ และรายจ่าย (PostgreSQL Syntax)
$income_where = "(status_id IN (2, 4) OR status IN ('confirmed', 'completed'))";

// รายได้รวมค่าแพวันนี้
$res_raft_today = @pg_query_params($conn, "SELECT COALESCE(SUM(raft_price), 0) AS total FROM bookings WHERE $income_where AND check_in_date = $1::date", array($today));
$raft_income_today = ($res_raft_today && $row = pg_fetch_assoc($res_raft_today)) ? floatval($row['total']) : 0;

// รายได้ชมรมวันนี้ (10%)
$res_inc_today = @pg_query_params($conn, "SELECT COALESCE(SUM(raft_price * 0.10), 0) AS total FROM bookings WHERE $income_where AND check_in_date = $1::date", array($today));
$income_today = ($res_inc_today && $row = pg_fetch_assoc($res_inc_today)) ? floatval($row['total']) : 0;

// รายได้ชมรมเดือนนี้ (10%)
$res_inc_month = @pg_query_params($conn, "SELECT COALESCE(SUM(raft_price * 0.10), 0) AS total FROM bookings WHERE $income_where AND TO_CHAR(check_in_date, 'YYYY-MM') = $1", array($this_month));
$income_month = ($res_inc_month && $row = pg_fetch_assoc($res_inc_month)) ? floatval($row['total']) : 0;

// รายได้ชมรมสะสมทั้งหมด (10%)
$res_inc_all = @pg_query($conn, "SELECT COALESCE(SUM(raft_price * 0.10), 0) AS total FROM bookings WHERE $income_where");
$income_all = ($res_inc_all && $row = pg_fetch_assoc($res_inc_all)) ? floatval($row['total']) : 0;

// รายจ่ายวันนี้
$res_exp_today = @pg_query_params($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date = $1::date", array($today));
$expense_today = ($res_exp_today && $row = pg_fetch_assoc($res_exp_today)) ? floatval($row['total']) : 0;

// รายจ่ายเดือนนี้
$res_exp_month = @pg_query_params($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE TO_CHAR(expense_date, 'YYYY-MM') = $1", array($this_month));
$expense_month = ($res_exp_month && $row = pg_fetch_assoc($res_exp_month)) ? floatval($row['total']) : 0;

// 5. ดึงรายการรายรับจากตาราง bookings (ค่าคิว 10%)
$booking_items = [];
$res_b = @pg_query($conn, "
    SELECT b.id, b.booking_code, b.raft_price, b.check_in_date, b.status_id, b.status, r.name as raft_name 
    FROM bookings b 
    LEFT JOIN rafts r ON b.raft_id = r.id 
    WHERE b.status_id IN (2, 4) OR b.status IN ('confirmed', 'completed')
    ORDER BY b.id DESC
");

if ($res_b) {
    while ($r = pg_fetch_assoc($res_b)) {
        $raft_price = floatval($r['raft_price']);
        $queue_fee = $raft_price * 0.10; // คิดค่าคิว 10%
        
        $status_text = ((intval($r['status_id']) == 4) || ($r['status'] === 'completed')) ? 'เสร็จสิ้น' : 'ยืนยันแล้ว';
        
        $booking_items[] = [
            'type'       => 'income',
            'amount'     => $queue_fee,
            'raft_total' => $raft_price,
            'date'       => !empty($r['check_in_date']) ? $r['check_in_date'] : $today,
            'note'       => 'ค่าคิวแพ (10%): ' . (!empty($r['raft_name']) ? $r['raft_name'] : 'แพทั่วไป'),
            'ref_id'     => $r['id'],
            'code'       => !empty($r['booking_code']) ? $r['booking_code'] : ('BK' . $r['id']),
            'status'     => $status_text
        ];
    }
}

// 6. ดึงรายการรายจ่ายจากตาราง expenses
$expense_items = [];
$res_e = @pg_query($conn, "SELECT * FROM expenses ORDER BY expense_id DESC");
if ($res_e) {
    while ($r = pg_fetch_assoc($res_e)) {
        $expense_items[] = [
            'type'   => 'expense',
            'amount' => floatval($r['amount']),
            'date'   => $r['expense_date'],
            'note'   => $r['title'],
            'ref_id' => $r['expense_id'],
            'code'   => '-',
            'status' => 'จ่ายแล้ว'
        ];
    }
}

// รวมรายการทั้งหมดและเรียงลำดับตามวันที่ล่าสุด
$all_transactions = array_merge($booking_items, $expense_items);
usort($all_transactions, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// 7. ระบบกรองข้อมูล (Filters)
$filter = $_GET['filter'] ?? 'all';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_title = "รายการการเงินทั้งหมด (" . count($all_transactions) . " รายการ)";

if (!empty($start_date) && !empty($end_date)) {
    $all_transactions = array_filter($all_transactions, function($item) use ($start_date, $end_date) {
        return $item['date'] >= $start_date && $item['date'] <= $end_date;
    });
    $filter_title = "รายการช่วงวันที่ " . date('d/m/Y', strtotime($start_date)) . " ถึง " . date('d/m/Y', strtotime($end_date));
} else {
    if ($filter == 'income_today') {
        $all_transactions = array_filter($all_transactions, fn($item) => $item['type'] == 'income' && $item['date'] == $today);
        $filter_title = "รายได้ค่าคิววันนี้";
    } elseif ($filter == 'expense_today') {
        $all_transactions = array_filter($all_transactions, fn($item) => $item['type'] == 'expense' && $item['date'] == $today);
        $filter_title = "รายจ่ายวันนี้";
    } elseif ($filter == 'income_month') {
        $all_transactions = array_filter($all_transactions, fn($item) => $item['type'] == 'income' && strpos($item['date'], $this_month) === 0);
        $filter_title = "รายได้ค่าคิวเดือนนี้";
    } elseif ($filter == 'expense_month') {
        $all_transactions = array_filter($all_transactions, fn($item) => $item['type'] == 'expense' && strpos($item['date'], $this_month) === 0);
        $filter_title = "รายจ่ายเดือนนี้";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บัญชี - รายรับ รายจ่าย</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; }</style>
</head>
<body class="bg-gray-50 flex min-h-screen">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 md:px-8 sticky top-0 z-30">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h2 class="text-xl font-black text-slate-800">บัญชี รายรับ - รายจ่าย</h2>
            </div>
            <div class="text-xs font-bold text-slate-500">
                รายได้ค่าคิวสะสมทั้งหมด: <span class="text-emerald-600 font-black text-base">฿<?php echo number_format($income_all, 2); ?></span>
            </div>
        </header>

        <div class="p-4 md:p-8 space-y-8">
            <!-- การ์ดสรุป 5 กล่อง -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 md:gap-6">
                <a href="?filter=income_today" class="bg-white p-6 rounded-[2rem] shadow-sm border-l-8 border-teal-500 hover:scale-[1.02] transition block">
                    <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1">รายได้ค่าแพวันนี้</p>
                    <h3 class="text-2xl font-black text-teal-600">฿<?php echo number_format($raft_income_today, 2); ?></h3>
                </a>

                <a href="?filter=income_today" class="bg-white p-6 rounded-[2rem] shadow-sm border-l-8 border-emerald-500 hover:scale-[1.02] transition block">
                    <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1">ค่าคิว 10% วันนี้</p>
                    <h3 class="text-2xl font-black text-emerald-600">฿<?php echo number_format($income_today, 2); ?></h3>
                </a>

                <a href="?filter=expense_today" class="bg-white p-6 rounded-[2rem] shadow-sm border-l-8 border-rose-500 hover:scale-[1.02] transition block">
                    <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1">รายจ่ายวันนี้</p>
                    <h3 class="text-2xl font-black text-rose-600">฿<?php echo number_format($expense_today, 2); ?></h3>
                </a>

                <a href="?filter=income_month" class="bg-white p-6 rounded-[2rem] shadow-sm border-l-8 border-indigo-500 hover:scale-[1.02] transition block">
                    <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1">ค่าคิวเดือนนี้</p>
                    <h3 class="text-2xl font-black text-indigo-600">฿<?php echo number_format($income_month, 2); ?></h3>
                </a>

                <a href="?filter=expense_month" class="bg-white p-6 rounded-[2rem] shadow-sm border-l-8 border-slate-800 hover:scale-[1.02] transition block">
                    <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1">รายจ่ายเดือนนี้</p>
                    <h3 class="text-2xl font-black text-slate-800">฿<?php echo number_format($expense_month, 2); ?></h3>
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- ฟอร์มบันทึกรายจ่าย -->
                <div class="lg:col-span-1">
                    <div class="bg-white p-6 md:p-8 rounded-[2.5rem] shadow-sm border border-gray-100">
                        <h3 class="text-xl font-black text-slate-800 mb-6 flex items-center gap-3">
                            <i class="fa fa-plus-circle text-rose-500 text-2xl"></i> บันทึกรายจ่าย
                        </h3>
                        <form action="manage_finances.php" method="POST" class="space-y-4">
                            <input type="hidden" name="add_expense" value="1">
                            <div>
                                <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-2">ชื่อรายการค่าใช้จ่าย *</label>
                                <input type="text" id="expense_title" name="title" list="expense_suggestions" required placeholder="เช่น ค่าน้ำมัน, ค่าล้างแพ..." 
                                       class="w-full px-5 py-3 rounded-2xl bg-gray-50 border border-gray-200 focus:border-rose-400 outline-none text-sm font-bold">
                                
                                <datalist id="expense_suggestions">
                                    <option value="ค่าเรือลาก" label="100 บาท"></option>
                                    <option value="ค่าล้างทำความสะอาดแพ" label="100 บาท"></option>
                                    <option value="ค่าน้ำมันเรือ" label="300 บาท"></option>
                                    <option value="ค่าซ่อมบำรุงแพ" label="500 บาท"></option>
                                    <option value="ค่าอุปกรณ์ / เสื้อชูชีพ" label="200 บาท"></option>
                                    <option value="ค่าแรง / ค่าจ้างพนักงาน" label="300 บาท"></option>
                                    <option value="ค่าน้ำ-ค่าไฟฟ้า" label="200 บาท"></option>
                                    <option value="ค่าอาหาร / เครื่องดื่ม" label="150 บาท"></option>
                                    <option value="ค่าดูแลรักษาท่าแพ" label="200 บาท"></option>
                                    <option value="ค่าห้องน้ำ" label="50 บาท"></option>
                                    <option value="ค่าเบ็ดเตล็ด" label="100 บาท"></option>
                                </datalist>
                            </div>
                            <div>
                                <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-2">จำนวนเงิน (บาท) *</label>
                                <input type="number" step="0.01" min="0" name="amount" id="expense_amount" required placeholder="0.00" 
                                       class="w-full px-5 py-3 rounded-2xl bg-gray-50 border border-gray-200 focus:border-rose-400 outline-none text-sm font-black text-rose-600">
                            </div>
                            <div>
                                <label class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-2">วันที่จ่ายเงิน *</label>
                                <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required 
                                       class="w-full px-5 py-3 rounded-2xl bg-gray-50 border border-gray-200 focus:border-rose-400 outline-none text-sm font-bold">
                            </div>
                            <button type="submit" class="w-full bg-rose-500 text-white font-black py-4 rounded-2xl shadow-lg shadow-rose-200 hover:bg-rose-600 transition flex items-center justify-center gap-2 mt-4">
                                <i class="fa fa-save"></i> บันทึกรายการรายจ่าย
                            </button>
                        </form>
                    </div>
                </div>

                <!-- ตารางสรุปรายการล่าสุด -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-[2.5rem] shadow-sm border border-gray-100 overflow-hidden">
                        
                        <!-- Header พร้อมฟอร์มเลือกช่วงวันที่ และปุ่มปริ้นท์ -->
                        <div class="p-6 md:p-8 border-b border-gray-50 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                            <div class="flex items-center gap-3">
                                <h3 class="text-xl font-black text-slate-800 flex items-center gap-3">
                                    <i class="fa fa-history text-blue-500 text-2xl"></i> <?php echo $filter_title; ?>
                                </h3>
                                <!-- ปุ่มพิมพ์รายการที่เลือก -->
                                <button type="button" onclick="printSelectedItems()" class="bg-slate-900 hover:bg-black text-white px-4 py-2.5 rounded-2xl font-bold text-xs transition shadow-sm flex items-center gap-1.5">
                                    <i class="fa fa-print"></i> พิมพ์รายการที่เลือก
                                </button>
                            </div>
                            
                            <!-- ช่องเลือกช่วงวันที่ -->
                            <form method="GET" action="manage_finances.php" class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                                <div class="flex items-center gap-1 bg-gray-50 p-1.5 rounded-2xl border border-gray-200">
                                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                                           class="bg-transparent text-xs font-bold text-gray-700 outline-none px-2 py-1">
                                    <span class="text-gray-400 text-xs">ถึง</span>
                                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                                           class="bg-transparent text-xs font-bold text-gray-700 outline-none px-2 py-1">
                                </div>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-2xl font-bold text-xs transition shadow-sm">
                                    <i class="fa fa-search mr-1"></i> กรอง
                                </button>
                                <?php if($filter != 'all' || !empty($start_date)): ?>
                                    <a href="manage_finances.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-2.5 rounded-2xl font-bold text-xs transition" title="ล้างตัวกรอง">
                                        <i class="fa fa-redo"></i>
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left font-sans">
                                <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">
                                    <tr>
                                        <th class="p-4 text-center w-12">
                                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 cursor-pointer">
                                        </th>
                                        <th class="p-6">วันที่</th>
                                        <th class="p-6">ประเภท</th>
                                        <th class="p-6">รายละเอียด</th>
                                        <th class="p-6 text-right">จำนวนเงิน</th>
                                        <th class="p-6 text-center">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <?php if (!empty($all_transactions)): ?>
                                        <?php foreach($all_transactions as $row): 
                                            $type_text = ($row['type'] == 'income') ? 'ค่าคิว 10% (' . $row['status'] . ')' : 'รายจ่าย';
                                            $formatted_amount = ($row['type'] == 'income' ? '+' : '-') . number_format($row['amount'], 2);
                                        ?>
                                        <tr class="hover:bg-gray-50/50 transition duration-150">
                                            <td class="p-4 text-center">
                                                <input type="checkbox" class="row-checkbox w-4 h-4 rounded text-blue-600 focus:ring-blue-500 cursor-pointer"
                                                       data-date="<?php echo date('d/m/Y', strtotime($row['date'])); ?>"
                                                       data-type="<?php echo htmlspecialchars($type_text); ?>"
                                                       data-note="<?php echo htmlspecialchars($row['note']); ?>"
                                                       data-amount="<?php echo $formatted_amount; ?>"
                                                       data-raw-amount="<?php echo ($row['type'] == 'income' ? $row['amount'] : -$row['amount']); ?>">
                                            </td>
                                            <td class="p-6 text-xs font-bold text-gray-500 whitespace-nowrap">
                                                <?php echo date('d/m/Y', strtotime($row['date'])); ?>
                                            </td>
                                            <td class="p-6">
                                                <?php if($row['type'] == 'income'): ?>
                                                    <span class="bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-[10px] font-black uppercase">ค่าคิว 10% (<?php echo $row['status']; ?>)</span>
                                                <?php else: ?>
                                                    <span class="bg-rose-100 text-rose-700 px-3 py-1 rounded-full text-[10px] font-black uppercase">รายจ่าย</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-6">
                                                <div class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($row['note']); ?></div>
                                                <?php if($row['type'] == 'income'): ?>
                                                    <div class="text-[10px] font-mono text-slate-400">
                                                        Booking #<?php echo $row['code']; ?> | ยอดจองแพ: ฿<?php echo number_format($row['raft_total'], 2); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-6 text-right font-black text-lg <?php echo $row['type'] == 'income' ? 'text-emerald-600' : 'text-rose-600'; ?>">
                                                <?php echo $formatted_amount; ?>
                                            </td>
                                            <td class="p-6 text-center">
                                                <?php if($row['type'] == 'expense'): ?>
                                                    <a href="?delete_expense=<?php echo $row['ref_id']; ?>" 
                                                       onclick="return confirm('ยืนยันลบรายการใช้จ่ายนี้?')"
                                                       class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center hover:bg-rose-500 hover:text-white transition mx-auto">
                                                        <i class="fa fa-trash-alt text-xs"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="edit_booking.php?id=<?php echo $row['ref_id']; ?>" class="text-blue-500 hover:underline text-xs font-bold">ดูการจอง</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="p-16 text-center text-gray-400 font-bold">ไม่พบข้อมูลรายการเงินในช่วงนี้</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // ฟังก์ชันเลือกทั้งหมด / ยกเลิกเลือกทั้งหมด
        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }

        // ฟังก์ชันพิมพ์รายการที่เลือก
        function printSelectedItems() {
            const selected = document.querySelectorAll('.row-checkbox:checked');
            if (selected.length === 0) {
                alert('กรุณาเลือกรายการที่ต้องการพิมพ์อย่างน้อย 1 รายการครับ');
                return;
            }

            let rowsHtml = '';
            let totalIncome = 0;
            let totalExpense = 0;

            selected.forEach((cb, index) => {
                const date = cb.dataset.date;
                const type = cb.dataset.type;
                const note = cb.dataset.note;
                const amount = cb.dataset.amount;
                const rawAmount = parseFloat(cb.dataset.rawAmount) || 0;

                if (rawAmount >= 0) {
                    totalIncome += rawAmount;
                } else {
                    totalExpense += Math.abs(rawAmount);
                }

                rowsHtml += `
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: center;">${index + 1}</td>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: center;">${date}</td>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd;">${type}</td>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd;">${note}</td>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd; text-align: right; font-weight: bold; color: ${rawAmount >= 0 ? '#059669' : '#e11d48'};">${amount}</td>
                    </tr>
                `;
            });

            const netTotal = totalIncome - totalExpense;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>รายงานสรุปรายการการเงิน - ล่องแพหนองกวาก</title>
                    <style>
                        body { font-family: 'Sarabun', sans-serif; margin: 30px; color: #1e293b; }
                        h2 { text-align: center; margin-bottom: 5px; color: #0f172a; }
                        p { text-align: center; margin-top: 0; color: #64748b; font-size: 13px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 25px; font-size: 13px; }
                        th { background-color: #f1f5f9; padding: 12px; border-bottom: 2px solid #cbd5e1; text-align: left; color: #475569; }
                        .text-right { text-align: right; }
                        .text-center { text-align: center; }
                        .summary-box { margin-top: 25px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px 20px; border-radius: 12px; font-size: 14px; }
                    </style>
                </head>
                <body>
                    <h2>รายงานสรุปรายการการเงิน (เฉพาะรายการที่เลือก)</h2>
                    <p>ล่องแพหนองกวาก จ.หนองคาย | พิมพ์เมื่อ: ${new Date().toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>
                    <table>
                        <thead>
                            <tr>
                                <th class="text-center" style="width: 50px;">ลำดับ</th>
                                <th class="text-center" style="width: 100px;">วันที่</th>
                                <th style="width: 140px;">ประเภท</th>
                                <th>รายละเอียด</th>
                                <th class="text-right" style="width: 120px;">จำนวนเงิน</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                    </table>
                    
                    <div class="summary-box">
                        <div style="margin-bottom: 5px;"><strong>รวมรายรับทั้งหมด:</strong> <span style="color: #059669;">+฿${totalIncome.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span></div>
                        <div style="margin-bottom: 5px;"><strong>รวมรายจ่ายทั้งหมด:</strong> <span style="color: #e11d48;">-฿${totalExpense.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span></div>
                        <div style="border-top: 1px solid #cbd5e1; margin-top: 8px; padding-top: 8px;"><strong>คงเหลือสุทธิ:</strong> <span style="color: ${netTotal >= 0 ? '#059669' : '#e11d48'};">฿${netTotal.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span></div>
                    </div>

                    <script>
                        window.onload = function() {
                            window.print();
                        }
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }

        const expensePriceMap = {
            'ค่าเรือลาก': 100,
            'ค่าล้างทำความสะอาดแพ': 100,
            'ค่าน้ำมันเรือ': 300,
            'ค่าซ่อมบำรุงแพ': 500,
            'ค่าอุปกรณ์ / เสื้อชูชีพ': 200,
            'ค่าแรง / ค่าจ้างพนักงาน': 300,
            'ค่าน้ำ-ค่าไฟฟ้า': 200,
            'ค่าอาหาร / เครื่องดื่ม': 150,
            'ค่าดูแลรักษาท่าแพ': 200,
            'ค่าห้องน้ำ': 50,
            'ค่าเบ็ดเตล็ด': 100
        };

        const expenseTitleEl = document.getElementById('expense_title');
        const expenseAmountEl = document.getElementById('expense_amount');

        if (expenseTitleEl && expenseAmountEl) {
            function checkAndFillPrice() {
                const val = expenseTitleEl.value.trim();
                if (expensePriceMap[val] !== undefined) {
                    expenseAmountEl.value = expensePriceMap[val].toFixed(2);
                } else {
                    for (const [key, price] of Object.entries(expensePriceMap)) {
                        if (val === key || (val.length >= 4 && key.includes(val))) {
                            expenseAmountEl.value = price.toFixed(2);
                            break;
                        }
                    }
                }
            }
            expenseTitleEl.addEventListener('input', checkAndFillPrice);
            expenseTitleEl.addEventListener('change', checkAndFillPrice);
        }

        const expenseForm = document.querySelector('form[action="manage_finances.php"]');
        if (expenseForm) {
            expenseForm.addEventListener('submit', function(e) {
                if (expenseAmountEl && parseFloat(expenseAmountEl.value) <= 0) {
                    alert('กรุณากรอกจำนวนเงินให้มากกว่า 0 ครับ');
                    e.preventDefault();
                }
            });
        }

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
