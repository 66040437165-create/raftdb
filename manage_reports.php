<?php
session_start();
require_once __DIR__ . '/../db_config.php';

// ตรวจสอบสิทธิ์การเข้าถึงของผู้ดูแลระบบ (Admin)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 1) {
    header("Location: admin_dashboard.php?msg=access_denied");
    exit();
}

// รับค่าฟิลเตอร์ Tab
$selected_tab = $_GET['tab'] ?? 'all'; // all, sales, popularity, status, customers

// ดึงปีที่มียอดการจองเพื่อสร้างดรอปดาวน์ฟิลเตอร์รายปี
$years_query = $conn->query("SELECT DISTINCT YEAR(check_in_date) as year FROM bookings ORDER BY year DESC");
$years = [];
if ($years_query) {
    while ($y = $years_query->fetch_assoc()) {
        if (!empty($y['year'])) {
            $years[] = $y['year'];
        }
    }
}
if (empty($years)) {
    $years[] = date('Y');
}

// ----------------------------------------------------
// 1. รายงานสรุปยอดการจองและรายได้ (สถานะ 2 = ยืนยันแล้ว, 4 = เสร็จสิ้น)
// ----------------------------------------------------
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filter_month_input = isset($_GET['filter_month']) ? $_GET['filter_month'] : '';

$sales_where = "WHERE (b.status_id = 2 OR b.status_id = 4)";
$filter_month = '';
$filter_year = date('Y');
$months = ["มกราคม","กุมภาพันธ์","มีนาคม","เมษายน","พฤษภาคม","มิถุนายน","กรกฎาคม","สิงหาคม","กันยายน","ตุลาคม","พฤศจิกายน","ธันวาคม"];

if ($filter_date !== '') {
    $escaped_date = $conn->real_escape_string($filter_date);
    $sales_where .= " AND DATE(b.check_in_date) = '$escaped_date'";
    $filter_month = date('m', strtotime($filter_date));
    $filter_year = date('Y', strtotime($filter_date));
} elseif ($filter_month_input !== '') {
    $parts = explode('-', $filter_month_input);
    if (count($parts) == 2) {
        $filter_year = intval($parts[0]);
        $filter_month = intval($parts[1]);
        $sales_where .= " AND YEAR(b.check_in_date) = $filter_year AND MONTH(b.check_in_date) = $filter_month";
    }
} else {
    $sales_where .= " AND YEAR(b.check_in_date) = $filter_year";
}

// คิวรีดึงข้อมูลสรุป
$summary_sql = "SELECT 
                    COUNT(b.id) as total_count,
                    SUM(b.raft_price) as total_revenue
                FROM bookings b
                $sales_where";
$summary_query = $conn->query($summary_sql);
$summary_res = ($summary_query) ? $summary_query->fetch_assoc() : [];
$total_bookings_count = $summary_res['total_count'] ?? 0;
$total_bookings_revenue = $summary_res['total_revenue'] ?? 0;

// คิวรีดึงประวัติการขายแยกตามวัน/เดือน
if ($filter_month !== '') {
    $breakdown_sql = "SELECT DATE(b.check_in_date) as label, COUNT(b.id) as count, SUM(b.raft_price) as revenue
                      FROM bookings b
                      $sales_where
                      GROUP BY DATE(b.check_in_date)
                      ORDER BY label ASC";
} else {
    $breakdown_sql = "SELECT MONTH(b.check_in_date) as label, COUNT(b.id) as count, SUM(b.raft_price) as revenue
                      FROM bookings b
                      $sales_where
                      GROUP BY MONTH(b.check_in_date)
                      ORDER BY label ASC";
}
$breakdown_result = $conn->query($breakdown_sql);

// เตรียมข้อมูลสำหรับกราฟ Sales
$chart_sales_labels = [];
$chart_sales_count = [];
$chart_sales_revenue = [];
if ($breakdown_result && $breakdown_result->num_rows > 0) {
    while ($row = $breakdown_result->fetch_assoc()) {
        $label_display = $row['label'];
        if ($filter_month === '') {
            $label_display = $months[intval($row['label']) - 1] . " " . ($filter_year + 543);
        } else {
            $label_display = date('d/m/Y', strtotime($row['label']));
        }
        $chart_sales_labels[] = $label_display;
        $chart_sales_count[] = (int)$row['count'];
        $chart_sales_revenue[] = (float)$row['revenue'];
    }
    $breakdown_result->data_seek(0);
}

// ----------------------------------------------------
// 2. รายงานสถิติแพที่ได้รับความนิยมสูงสุด
// ----------------------------------------------------
$popularity_sql = "SELECT r.id as raft_id, r.name as raft_name, r.capacity, r.price_per_day,
                          COUNT(b.id) as total_bookings,
                          COALESCE(SUM(b.raft_price), 0) as total_revenue
                   FROM rafts r
                   LEFT JOIN bookings b ON r.id = b.raft_id AND (b.status_id = 2 OR b.status_id = 4)
                   GROUP BY r.id
                   ORDER BY total_bookings DESC, total_revenue DESC";
$popularity_result = $conn->query($popularity_sql);

// เตรียมข้อมูลสำหรับกราฟ Popularity
$chart_pop_labels = [];
$chart_pop_count = [];
$chart_pop_revenue = [];
if ($popularity_result && $popularity_result->num_rows > 0) {
    while ($row = $popularity_result->fetch_assoc()) {
        $chart_pop_labels[] = $row['raft_name'];
        $chart_pop_count[] = (int)$row['total_bookings'];
        $chart_pop_revenue[] = (float)$row['total_revenue'];
    }
    $popularity_result->data_seek(0);
}

// ----------------------------------------------------
// 3. รายงานสถานะแพ
// ----------------------------------------------------
$status_sql = "SELECT * FROM rafts ORDER BY status ASC, id ASC";
$status_result = $conn->query($status_sql);

$q_av = $conn->query("SELECT COUNT(*) as total FROM rafts WHERE status = 'available'");
$stats_available = ($q_av && $r = $q_av->fetch_assoc()) ? intval($r['total']) : 0;

$q_bu = $conn->query("SELECT COUNT(*) as total FROM rafts WHERE status = 'busy'");
$stats_busy = ($q_bu && $r = $q_bu->fetch_assoc()) ? intval($r['total']) : 0;

$q_ma = $conn->query("SELECT COUNT(*) as total FROM rafts WHERE status = 'maintenance'");
$stats_maintenance = ($q_ma && $r = $q_ma->fetch_assoc()) ? intval($r['total']) : 0;

// ----------------------------------------------------
// 4. รายงานประวัติการใช้บริการของลูกค้า
// ----------------------------------------------------
$cust_search = isset($_GET['cust_search']) ? $conn->real_escape_string($_GET['cust_search']) : '';
$cust_where = "";
if (!empty($cust_search)) {
    $cust_where = "WHERE COALESCE(c.full_name, b.guest_name) LIKE '%$cust_search%' OR COALESCE(c.phone, b.guest_tel) LIKE '%$cust_search%'";
}

$customers_sql = "SELECT 
                    COALESCE(c.full_name, b.guest_name) as customer_name,
                    COALESCE(c.phone, b.guest_tel) as customer_tel,
                    COALESCE(c.email, b.guest_email) as customer_email,
                    COUNT(b.id) as total_bookings,
                    SUM(b.raft_price) as total_spent,
                    AVG(b.raft_price) as avg_spent,
                    MAX(b.check_in_date) as last_booking
                   FROM bookings b
                   LEFT JOIN customers c ON b.customer_id = c.id
                   $cust_where
                   GROUP BY customer_name, customer_tel
                   ORDER BY total_bookings DESC, total_spent DESC";
$customers_result = $conn->query($customers_sql);

// เตรียมข้อมูลสำหรับกราฟ Customers (Top 10)
$chart_cust_labels = [];
$chart_cust_spent = [];
if ($customers_result && $customers_result->num_rows > 0) {
    $count = 0;
    while ($row = $customers_result->fetch_assoc()) {
        if ($count < 10) {
            $chart_cust_labels[] = $row['customer_name'] ?: 'ลูกค้าทั่วไป';
            $chart_cust_spent[] = (float)($row['total_spent'] ?? 0);
        }
        $count++;
    }
    $customers_result->data_seek(0);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานวิเคราะห์ระบบ - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .sidebar-active { transform: translateX(0) !important; }
        
        @media print {
            body { background-color: white !important; color: black !important; }
            .no-print { display: none !important; }
            .print-area { display: block !important; width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .print-card { border: none !important; box-shadow: none !important; padding: 0 !important; }
        }
    </style>
</head>
<body class="bg-gray-50 flex min-h-screen">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside>
        <?php include 'sidebar.php'; ?>
    </aside>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 md:px-10 sticky top-0 z-30 no-print">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl md:text-2xl font-black text-gray-800">รายงานวิเคราะห์ & สถิติระบบ</h1>
            </div>
            <div>
                <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-xl shadow-lg transition flex items-center gap-2 text-sm">
                    <i class="fa fa-print"></i> พิมพ์รายงาน
                </button>
            </div>
        </header>

        <div class="p-4 md:p-10 flex-grow print-area">
            
            <!-- Tab Menu -->
            <div class="flex border-b border-gray-200 mb-8 overflow-x-auto no-print">
                <a href="?tab=all" class="py-4 px-6 font-bold text-sm border-b-2 transition flex items-center gap-2 whitespace-nowrap <?php echo $selected_tab === 'all' ? 'border-blue-600 text-blue-600 font-black bg-blue-50/50 rounded-t-2xl' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class="fa fa-chart-pie"></i> 📊 รวมกราฟทั้งหมด
                </a>
                <a href="?tab=sales" class="py-4 px-6 font-bold text-sm border-b-2 transition flex items-center gap-2 whitespace-nowrap <?php echo $selected_tab === 'sales' ? 'border-blue-600 text-blue-600 font-black bg-blue-50/50 rounded-t-2xl' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class="fa fa-wallet"></i> ยอดจองและรายได้
                </a>
                <a href="?tab=popularity" class="py-4 px-6 font-bold text-sm border-b-2 transition flex items-center gap-2 whitespace-nowrap <?php echo $selected_tab === 'popularity' ? 'border-blue-600 text-blue-600 font-black bg-blue-50/50 rounded-t-2xl' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class="fa fa-trophy"></i> แพยอดนิยมสูงสุด
                </a>
                <a href="?tab=status" class="py-4 px-6 font-bold text-sm border-b-2 transition flex items-center gap-2 whitespace-nowrap <?php echo $selected_tab === 'status' ? 'border-blue-600 text-blue-600 font-black bg-blue-50/50 rounded-t-2xl' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class="fa fa-clipboard-list"></i> สถานะการใช้งานแพ
                </a>
                <a href="?tab=customers" class="py-4 px-6 font-bold text-sm border-b-2 transition flex items-center gap-2 whitespace-nowrap <?php echo $selected_tab === 'customers' ? 'border-blue-600 text-blue-600 font-black bg-blue-50/50 rounded-t-2xl' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
                    <i class="fa fa-user-friends"></i> ประวัติลูกค้า & พฤติกรรม
                </a>
            </div>

            <!-- Print Header -->
            <div class="hidden print:block text-center mb-8 border-b-2 pb-6">
                <h1 class="text-3xl font-black uppercase tracking-wider text-slate-800">รายงานวิเคราะห์ระบบจองแพ</h1>
                <p class="text-xs text-gray-400 mt-2">พิมพ์โดยผู้ดูแลระบบ ณ วันที่: <?php echo date('d/m/Y H:i:s'); ?></p>
            </div>

            <!-- TAB ALL: OVERVIEW DASHBOARD -->
            <?php if ($selected_tab === 'all'): ?>
                <div class="space-y-8 mb-8">
                    <div class="bg-gradient-to-r from-slate-900 via-blue-900 to-indigo-900 rounded-3xl p-6 md:p-8 text-white shadow-xl relative overflow-hidden">
                        <div class="relative z-10">
                            <span class="inline-block bg-blue-500/20 text-blue-300 text-xs px-3.5 py-1.5 rounded-full font-bold uppercase mb-2 border border-blue-400/30">📊</span>
                            <h2 class="text-2xl md:text-3xl font-black mb-2">รวมภาพรวมสถิติและกราฟวิเคราะห์ทุกมิติ</h2>
                            <p class="text-blue-100 text-xs md:text-sm">รวบรวมกราฟยอดจอง, รายได้, สถิติความนิยมแพ, สถานะแพ และลูกค้าสูงสุด</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider block mb-1">ยอดจองแพสำเร็จ</span>
                                <span class="text-2xl font-black text-slate-800"><?php echo number_format($total_bookings_count); ?> ครั้ง</span>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center text-xl font-bold"><i class="fa fa-calendar-check"></i></div>
                        </div>

                        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider block mb-1">รายได้จากการจองรวม</span>
                                <span class="text-2xl font-black text-emerald-600">฿<?php echo number_format($total_bookings_revenue); ?></span>
                            </div>
                            <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center text-xl font-bold"><i class="fa fa-wallet"></i></div>
                        </div>

                        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider block mb-1">แพพร้อมให้บริการ</span>
                                <span class="text-2xl font-black text-indigo-600"><?php echo $stats_available; ?> / <?php echo ($stats_available + $stats_busy + $stats_maintenance); ?> ลำ</span>
                            </div>
                            <div class="w-12 h-12 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center text-xl font-bold"><i class="fa fa-ship"></i></div>
                        </div>

                        <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                            <div>
                                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider block mb-1">ลูกค้าในระบบ</span>
                                <span class="text-2xl font-black text-amber-600"><?php echo count($chart_cust_labels); ?> ราย</span>
                            </div>
                            <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center text-xl font-bold"><i class="fa fa-users"></i></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
                            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                                <h3 class="text-base font-bold text-gray-800 flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-emerald-500"></span> 1. สรุปยอดจองและรายได้</h3>
                                <a href="?tab=sales" class="text-xs text-blue-600 hover:underline font-bold">ดูตารางแจกแจง <i class="fa fa-arrow-right"></i></a>
                            </div>
                            <div class="relative h-72 w-full"><canvas id="salesChart"></canvas></div>
                        </div>

                        <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
                            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                                <h3 class="text-base font-bold text-gray-800 flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-blue-500"></span> 2. สถิติแพที่ได้รับความนิยมสูงสุด</h3>
                                <a href="?tab=popularity" class="text-xs text-blue-600 hover:underline font-bold">ดูเพิ่มเติม <i class="fa fa-arrow-right"></i></a>
                            </div>
                            <div class="relative h-72 w-full"><canvas id="popularityChart"></canvas></div>
                        </div>

                        <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
                            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                                <h3 class="text-base font-bold text-gray-800 flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-indigo-500"></span> 3. สัดส่วนสถานะการใช้งานแพ</h3>
                                <a href="?tab=status" class="text-xs text-blue-600 hover:underline font-bold">ดูรายชื่อแพ <i class="fa fa-arrow-right"></i></a>
                            </div>
                            <div class="relative h-72 w-full flex items-center justify-center"><canvas id="statusChart"></canvas></div>
                        </div>

                        <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
                            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                                <h3 class="text-base font-bold text-gray-800 flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-amber-500"></span> 4. สถิตียอดใช้จ่ายลูกค้าสูงสุด</h3>
                                <a href="?tab=customers" class="text-xs text-blue-600 hover:underline font-bold">ดูรายชื่อลูกค้า <i class="fa fa-arrow-right"></i></a>
                            </div>
                            <div class="relative h-72 w-full"><canvas id="customersChart"></canvas></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB 1: SALES & REVENUE REPORT -->
            <?php if ($selected_tab === 'sales'): ?>
                <div class="print-card bg-white p-6 rounded-[2rem] shadow-sm border border-gray-100 mb-8">
                    <div class="no-print bg-slate-50 p-6 rounded-2xl mb-8 border border-slate-100">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                            <input type="hidden" name="tab" value="sales">
                            <div>
                                <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">ดูรายงานประจำวัน</label>
                                <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 outline-none font-bold text-slate-700">
                            </div>
                            <div>
                                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl shadow-lg transition"><i class="fa fa-filter mr-1"></i> กรองข้อมูล</button>
                            </div>
                            <div>
                                <a href="manage_reports.php?tab=sales" class="w-full flex items-center justify-center bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2.5 rounded-xl transition">ล้างตัวกรอง</a>
                            </div>
                        </form>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <div class="bg-blue-50/50 p-6 rounded-3xl border border-blue-100 flex items-center justify-between">
                            <div>
                                <h3 class="text-xs font-black text-blue-500 uppercase tracking-wider mb-1">ยอดจองแพสำเร็จ</h3>
                                <p class="text-3xl font-black text-slate-800"><?php echo number_format($total_bookings_count); ?> ครั้ง</p>
                            </div>
                            <div class="p-4 bg-blue-500 text-white rounded-2xl shadow-lg"><i class="fa fa-calendar-check text-xl"></i></div>
                        </div>

                        <div class="bg-emerald-50/50 p-6 rounded-3xl border border-emerald-100 flex items-center justify-between">
                            <div>
                                <h3 class="text-xs font-black text-emerald-500 uppercase tracking-wider mb-1">รายได้จากการจองทั้งหมด</h3>
                                <p class="text-3xl font-black text-slate-800">฿<?php echo number_format($total_bookings_revenue); ?></p>
                            </div>
                            <div class="p-4 bg-emerald-500 text-white rounded-2xl shadow-lg"><i class="fa fa-wallet text-xl"></i></div>
                        </div>
                    </div>

                    <div class="mb-8 p-6 bg-white border border-gray-100 rounded-3xl shadow-sm">
                        <div class="relative h-72 w-full"><canvas id="salesChart"></canvas></div>
                    </div>

                    <h2 class="text-lg font-bold text-gray-800 mb-4 border-l-4 border-blue-500 pl-3">ตารางวิเคราะห์แจกแจงรายละเอียด</h2>
                    <div class="overflow-x-auto border border-gray-100 rounded-2xl">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 text-[10px] font-black text-slate-400 tracking-wider border-b border-slate-100">
                                <tr>
                                    <th class="p-4">ช่วงเวลา (วัน/เดือน)</th>
                                    <th class="p-4 text-center">จำนวนการจอง (ครั้ง)</th>
                                    <th class="p-4 text-right">ยอดรายได้รวม (บาท)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm font-bold text-slate-600">
                                <?php 
                                if ($breakdown_result && $breakdown_result->num_rows > 0): 
                                    while ($row = $breakdown_result->fetch_assoc()):
                                        $label_display = $row['label'];
                                        if ($filter_month === '') {
                                            $label_display = $months[intval($row['label']) - 1] . " " . ($filter_year + 543);
                                        } else {
                                            $label_display = date('d/m/Y', strtotime($row['label']));
                                        }
                                ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="p-4 text-slate-800"><?php echo $label_display; ?></td>
                                    <td class="p-4 text-center"><?php echo number_format($row['count']); ?></td>
                                    <td class="p-4 text-right text-emerald-600">฿<?php echo number_format($row['revenue']); ?></td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="3" class="p-10 text-center text-gray-400">ไม่พบข้อมูลการจองในช่วงเวลานี้</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB 2: POPULAR RAFTS REPORT -->
            <?php if ($selected_tab === 'popularity'): ?>
                <div class="print-card bg-white p-6 rounded-[2rem] shadow-sm border border-gray-100 mb-8">
                    <h2 class="text-lg font-bold text-gray-800 mb-6 border-l-4 border-yellow-500 pl-3">สถิติแพที่ได้รับความนิยมสูงสุด</h2>
                    <div class="mb-8 p-6 bg-white border border-gray-100 rounded-3xl shadow-sm">
                        <div class="relative h-[480px] w-full max-w-3xl mx-auto"><canvas id="popularityChart"></canvas></div>
                    </div>
                    <div class="overflow-x-auto border border-gray-100 rounded-2xl">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 text-[10px] font-black text-slate-400 tracking-wider border-b border-slate-100">
                                <tr>
                                    <th class="p-4 text-center">อันดับ</th>
                                    <th class="p-4">ชื่อแพ</th>
                                    <th class="p-4 text-center">ขนาด (ท่าน)</th>
                                    <th class="p-4 text-right">ราคา/วัน</th>
                                    <th class="p-4 text-center">ถูกจอง (ครั้ง)</th>
                                    <th class="p-4 text-right">รายได้สะสม</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm font-bold text-slate-600">
                                <?php 
                                $rank = 1;
                                if ($popularity_result && $popularity_result->num_rows > 0): 
                                    while ($row = $popularity_result->fetch_assoc()):
                                ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="p-4 text-center"><?php echo $rank; ?></td>
                                    <td class="p-4 text-slate-800"><?php echo htmlspecialchars($row['raft_name']); ?></td>
                                    <td class="p-4 text-center"><?php echo $row['capacity']; ?></td>
                                    <td class="p-4 text-right">฿<?php echo number_format($row['price_per_day']); ?></td>
                                    <td class="p-4 text-center text-blue-600 font-black"><?php echo number_format($row['total_bookings']); ?></td>
                                    <td class="p-4 text-right text-emerald-600">฿<?php echo number_format($row['total_revenue']); ?></td>
                                </tr>
                                <?php $rank++; endwhile; else: ?>
                                <tr><td colspan="6" class="p-10 text-center text-gray-400">ไม่พบสถิติการจองแพ</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB 3: RAFT STATUS REPORT -->
            <?php if ($selected_tab === 'status'): ?>
                <div class="print-card bg-white p-6 rounded-[2rem] shadow-sm border border-gray-100 mb-8">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 font-bold">
                        <div class="bg-emerald-50 border border-emerald-100 p-5 rounded-2xl flex items-center justify-between">
                            <div><h3 class="text-xs font-black text-emerald-500 uppercase">แพว่าง</h3><p class="text-2xl font-black text-slate-800"><?php echo $stats_available; ?> หลัง</p></div>
                            <div class="w-10 h-10 bg-emerald-500 text-white rounded-full flex items-center justify-center"><i class="fa fa-check"></i></div>
                        </div>
                        <div class="bg-blue-50 border border-blue-100 p-5 rounded-2xl flex items-center justify-between">
                            <div><h3 class="text-xs font-black text-blue-500 uppercase">ไม่ว่าง/ติดจอง</h3><p class="text-2xl font-black text-slate-800"><?php echo $stats_busy; ?> หลัง</p></div>
                            <div class="w-10 h-10 bg-blue-500 text-white rounded-full flex items-center justify-center"><i class="fa fa-clock"></i></div>
                        </div>
                        <div class="bg-rose-50 border border-rose-100 p-5 rounded-2xl flex items-center justify-between">
                            <div><h3 class="text-xs font-black text-rose-500 uppercase">ปิดปรับปรุง</h3><p class="text-2xl font-black text-slate-800"><?php echo $stats_maintenance; ?> หลัง</p></div>
                            <div class="w-10 h-10 bg-rose-500 text-white rounded-full flex items-center justify-center"><i class="fa fa-wrench"></i></div>
                        </div>
                    </div>

                    <div class="mb-8 p-6 bg-white border border-gray-100 rounded-3xl shadow-sm">
                        <div class="relative h-64 w-full max-w-sm mx-auto"><canvas id="statusChart"></canvas></div>
                    </div>

                    <h2 class="text-lg font-bold text-gray-800 mb-4 border-l-4 border-emerald-500 pl-3">รายละเอียดสถานะแพแต่ละหลัง</h2>
                    <div class="overflow-x-auto border border-gray-100 rounded-2xl">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 text-[10px] font-black text-slate-400 tracking-wider border-b border-slate-100">
                                <tr>
                                    <th class="p-4">ID</th>
                                    <th class="p-4">ชื่อแพ</th>
                                    <th class="p-4 text-center">ความจุ (คน)</th>
                                    <th class="p-4 text-right">ราคา/วัน</th>
                                    <th class="p-4 text-center">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm font-bold text-slate-600">
                                <?php 
                                if ($status_result && $status_result->num_rows > 0): 
                                    while ($row = $status_result->fetch_assoc()):
                                        $badge_class = $row['status'] == 'available' ? 'bg-emerald-100 text-emerald-700' : ($row['status'] == 'busy' ? 'bg-blue-100 text-blue-700' : 'bg-rose-100 text-rose-700');
                                        $badge_label = $row['status'] == 'available' ? 'ว่าง' : ($row['status'] == 'busy' ? 'ไม่ว่าง/ติดจอง' : 'ปิดปรับปรุง');
                                ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="p-4 text-slate-400">#<?php echo $row['id']; ?></td>
                                    <td class="p-4 text-slate-800"><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td class="p-4 text-center"><?php echo $row['capacity']; ?></td>
                                    <td class="p-4 text-right">฿<?php echo number_format($row['price_per_day']); ?></td>
                                    <td class="p-4 text-center">
                                        <span class="px-3 py-1 rounded-full text-xs font-black <?php echo $badge_class; ?>">
                                            <?php echo $badge_label; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="5" class="p-10 text-center text-gray-400">ไม่พบข้อมูลแพ</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TAB 4: CUSTOMERS HISTORY REPORT -->
            <?php if ($selected_tab === 'customers'): ?>
                <div class="print-card bg-white p-6 rounded-[2rem] shadow-sm border border-gray-100 mb-8">
                    <div class="no-print bg-slate-50 p-6 rounded-2xl mb-8 border border-slate-100">
                        <form method="GET" class="flex flex-col md:flex-row gap-4 items-end">
                            <input type="hidden" name="tab" value="customers">
                            <div class="flex-grow w-full">
                                <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">ค้นหาชื่อ หรือ เบอร์โทรลูกค้า</label>
                                <input type="text" name="cust_search" value="<?php echo htmlspecialchars($cust_search); ?>" placeholder="พิมพ์ชื่อหรือเบอร์โทรศัพท์..." class="w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 outline-none font-bold text-slate-700">
                            </div>
                            <div class="w-full md:w-auto flex gap-2">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-6 rounded-xl shadow-lg transition"><i class="fa fa-search mr-1"></i> ค้นหา</button>
                                <a href="manage_reports.php?tab=customers" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2.5 px-4 rounded-xl transition flex items-center justify-center">ล้าง</a>
                            </div>
                        </form>
                    </div>

                    <div class="mb-8 p-6 bg-white border border-gray-100 rounded-3xl shadow-sm">
                        <h3 class="text-sm font-bold text-gray-700 mb-4">กราฟแสดง 10 อันดับลูกค้าที่มียอดใช้จ่ายสะสมสูงสุด</h3>
                        <div class="relative h-72 w-full"><canvas id="customersChart"></canvas></div>
                    </div>

                    <h2 class="text-lg font-bold text-gray-800 mb-4 border-l-4 border-amber-500 pl-3">ประวัติพฤติกรรมการใช้บริการของลูกค้า</h2>
                    <div class="overflow-x-auto border border-gray-100 rounded-2xl">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 text-[10px] font-black text-slate-400 tracking-wider border-b border-slate-100">
                                <tr>
                                    <th class="p-4">ชื่อ-นามสกุล</th>
                                    <th class="p-4">เบอร์โทรศัพท์</th>
                                    <th class="p-4">อีเมล</th>
                                    <th class="p-4 text-center">จองสะสม (ครั้ง)</th>
                                    <th class="p-4 text-right">ยอดรวม (บาท)</th>
                                    <th class="p-4 text-right">เฉลี่ย/ครั้ง</th>
                                    <th class="p-4 text-center">จองล่าสุด</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm font-bold text-slate-600">
                                <?php 
                                if ($customers_result && $customers_result->num_rows > 0): 
                                    while ($row = $customers_result->fetch_assoc()):
                                ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="p-4 text-slate-800"><?php echo htmlspecialchars($row['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></td>
                                    <td class="p-4 text-slate-500"><?php echo htmlspecialchars($row['customer_tel'] ?: '-'); ?></td>
                                    <td class="p-4 text-slate-500"><?php echo htmlspecialchars($row['customer_email'] ?: '-'); ?></td>
                                    <td class="p-4 text-center text-blue-600 font-black"><?php echo number_format($row['total_bookings']); ?></td>
                                    <td class="p-4 text-right text-emerald-600">฿<?php echo number_format($row['total_spent']); ?></td>
                                    <td class="p-4 text-right text-slate-700">฿<?php echo number_format($row['avg_spent']); ?></td>
                                    <td class="p-4 text-center text-xs text-slate-400"><?php echo $row['last_booking'] ? date('d/m/Y', strtotime($row['last_booking'])) : '-'; ?></td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="7" class="p-10 text-center text-gray-400">ไม่พบประวัติลูกค้า</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('aside');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar) {
                sidebar.classList.toggle('sidebar-active');
                if (overlay) overlay.classList.toggle('hidden');
            }
        }

        const chartSalesLabels = <?php echo json_encode($chart_sales_labels); ?>;
        const chartSalesCount = <?php echo json_encode($chart_sales_count); ?>;
        const chartSalesRevenue = <?php echo json_encode($chart_sales_revenue); ?>;

        const chartPopLabels = <?php echo json_encode($chart_pop_labels); ?>;
        const chartPopCount = <?php echo json_encode($chart_pop_count); ?>;
        const chartPopRevenue = <?php echo json_encode($chart_pop_revenue); ?>;

        const statsAvailable = <?php echo $stats_available; ?>;
        const statsBusy = <?php echo $stats_busy; ?>;
        const statsMaintenance = <?php echo $stats_maintenance; ?>;

        const chartCustLabels = <?php echo json_encode($chart_cust_labels); ?>;
        const chartCustSpent = <?php echo json_encode($chart_cust_spent); ?>;

        document.addEventListener("DOMContentLoaded", function () {
            
            // 1. Sales Chart
            const ctxSales = document.getElementById('salesChart');
            if (ctxSales) {
                new Chart(ctxSales.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: chartSalesLabels,
                        datasets: [
                            {
                                label: 'รายได้ (บาท)',
                                data: chartSalesRevenue,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                fill: true,
                                yAxisID: 'y1',
                                tension: 0.3
                            },
                            {
                                label: 'จำนวนการจอง (ครั้ง)',
                                data: chartSalesCount,
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                fill: true,
                                yAxisID: 'y',
                                tension: 0.3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: {
                            y: { type: 'linear', position: 'left', title: { display: true, text: 'จำนวนครั้ง' }, grid: { display: false } },
                            y1: { type: 'linear', position: 'right', title: { display: true, text: 'บาท' }, grid: { drawOnChartArea: false } }
                        }
                    }
                });
            }

            // 2. Popularity Chart
            const ctxPop = document.getElementById('popularityChart');
            if (ctxPop) {
                new Chart(ctxPop.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: chartPopLabels,
                        datasets: [
                            {
                                label: 'จำนวนการจอง (ครั้ง)',
                                data: chartPopCount,
                                backgroundColor: '#3b82f6',
                                borderRadius: 8
                            },
                            {
                                label: 'รายได้สะสม (บาท)',
                                data: chartPopRevenue,
                                backgroundColor: '#10b981',
                                borderRadius: 8
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        plugins: { legend: { position: 'top' } }
                    }
                });
            }

            // 3. Status Chart
            const ctxStatus = document.getElementById('statusChart');
            if (ctxStatus) {
                new Chart(ctxStatus.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['แพว่าง', 'ไม่ว่าง/ติดจอง', 'ปิดปรับปรุง'],
                        datasets: [{
                            data: [statsAvailable, statsBusy, statsMaintenance],
                            backgroundColor: ['#10b981', '#3b82f6', '#f43f5e'],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }

            // 4. Customers Chart
            const ctxCust = document.getElementById('customersChart');
            if (ctxCust) {
                new Chart(ctxCust.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: chartCustLabels,
                        datasets: [{
                            label: 'ยอดใช้จ่ายรวม (บาท)',
                            data: chartCustSpent,
                            backgroundColor: '#f59e0b',
                            borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }
        });
    </script>
</body>
</html>
