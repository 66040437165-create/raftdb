<?php
session_start();
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// 🛠️ เช็คคอลัมน์สถานะที่มีอยู่ในตาราง bookings ป้องกัน SQL Error
$b_cols = [];
$chk_cols = $conn->query("SHOW COLUMNS FROM bookings");
if ($chk_cols) {
    while ($c = $chk_cols->fetch_assoc()) {
        $b_cols[] = strtolower($c['Field']);
    }
}
$has_sid = in_array('status_id', $b_cols);
$has_txt = in_array('status', $b_cols);

// --- AJAX API ENDPOINT สำหรับ FullCalendar ---
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    
    $raft_filter   = isset($_GET['raft_id']) ? intval($_GET['raft_id']) : 0;
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
    $start         = isset($_GET['start']) ? trim($_GET['start']) : '';
    $end           = isset($_GET['end']) ? trim($_GET['end']) : '';

    $where = [];
    if ($raft_filter > 0) {
        $where[] = "b.raft_id = $raft_filter";
    }
    if (!empty($status_filter)) {
        $sid = 1;
        if ($status_filter === 'confirmed') $sid = 2;
        elseif ($status_filter === 'cancelled') $sid = 3;
        elseif ($status_filter === 'completed') $sid = 4;
        
        $conds = [];
        if ($has_sid) $conds[] = "b.status_id = $sid";
        if ($has_txt) $conds[] = "b.status = '$status_filter'";
        if (!empty($conds)) $where[] = "(" . implode(" OR ", $conds) . ")";
    }
    if (!empty($start)) {
        $start_clean = $conn->real_escape_string(substr($start, 0, 10));
        $where[] = "b.check_out_date >= '$start_clean'";
    }
    if (!empty($end)) {
        $end_clean = $conn->real_escape_string(substr($end, 0, 10));
        $where[] = "b.check_in_date <= '$end_clean'";
    }

    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT b.*, 
                   r.name AS raft_name, 
                   r.capacity, 
                   c.full_name AS customer_name,
                   c.phone AS customer_phone,
                   p.slip_image
            FROM bookings b 
            LEFT JOIN rafts r ON b.raft_id = r.id 
            LEFT JOIN customers c ON b.customer_id = c.id
            LEFT JOIN payments p ON b.id = p.booking_id
            $where_sql 
            ORDER BY b.check_in_date ASC, b.check_in_time ASC";

    $result = $conn->query($sql);
    $events = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $raw_status = intval($row['status_id'] ?? 1);
            $status_str = $row['status'] ?? '';

            if ($raw_status == 2 || $status_str === 'confirmed') {
                $color = '#10b981'; // เขียว
                $border_color = '#059669';
                $status_th = 'ยืนยันแล้ว (อนุมัติแล้ว)';
                $status_code = 'confirmed';
            } elseif ($raw_status == 4 || $status_str === 'completed') {
                $color = '#6366f1'; // น้ำเงินอมม่วง
                $border_color = '#4f46e5';
                $status_th = 'เสร็จสิ้น';
                $status_code = 'completed';
            } elseif ($raw_status == 3 || $status_str === 'cancelled') {
                $color = '#ef4444'; // แดง
                $border_color = '#b91c1c';
                $status_th = 'ยกเลิกการจอง';
                $status_code = 'cancelled';
            } else {
                $color = '#f59e0b'; // ส้มเหลือง
                $border_color = '#d97706';
                $status_th = 'รอตรวจสอบ';
                $status_code = 'pending';
            }

            $display_name = !empty($row['customer_name']) ? $row['customer_name'] : (!empty($row['guest_name']) ? $row['guest_name'] : 'ลูกค้า');
            $raft_title = !empty($row['raft_name']) ? $row['raft_name'] : ('แพ #' . $row['raft_id']);
            $booking_code = !empty($row['booking_code']) ? $row['booking_code'] : ('BK' . str_pad($row['id'], 6, '0', STR_PAD_LEFT));

            $start_date = $row['check_in_date'] ?? date('Y-m-d');
            $start_time = !empty($row['check_in_time']) ? date('H:i:s', strtotime($row['check_in_time'])) : '09:00:00';
            $end_date   = $row['check_out_date'] ?? $start_date;
            $end_time   = !empty($row['check_out_time']) ? date('H:i:s', strtotime($row['check_out_time'])) : '17:30:00';

            $start_iso  = $start_date . 'T' . $start_time;
            $end_iso    = $end_date . 'T' . $end_time;

            $total_amount = floatval($row['raft_price'] ?? $row['total_price'] ?? 0);

            $events[] = [
                'id' => $row['id'],
                'title' => '⛵ ' . $raft_title . ' (' . $display_name . ')',
                'start' => $start_iso,
                'end' => $end_iso,
                'backgroundColor' => $color,
                'borderColor' => $border_color,
                'textColor' => '#ffffff',
                'extendedProps' => [
                    'booking_id' => $row['id'],
                    'booking_code' => $booking_code,
                    'raft_id' => $row['raft_id'],
                    'raft_name' => $raft_title,
                    'capacity' => $row['capacity'] ?? 0,
                    'guest_name' => $display_name,
                    'guest_tel' => $row['customer_phone'] ?? $row['guest_tel'] ?? '-',
                    'total_price' => number_format($total_amount, 2),
                    'status' => $status_code,
                    'status_th' => $status_th,
                    'check_in_formatted' => date('d/m/Y', strtotime($start_date)) . ' ' . date('H:i', strtotime($start_time)) . ' น.',
                    'check_out_formatted' => date('d/m/Y', strtotime($end_date)) . ' ' . date('H:i', strtotime($end_time)) . ' น.',
                    'slip_image' => $row['slip_image'] ?? ''
                ]
            ];
        }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. ดึงรายการแพสำหรับใส่ Dropdown กรองข้อมูล
$rafts_list = [];
$rafts_query = $conn->query("SELECT id, name FROM rafts ORDER BY id ASC");
if ($rafts_query && $rafts_query->num_rows > 0) {
    while ($rf_row = $rafts_query->fetch_assoc()) {
        $rafts_list[] = [
            'raft_id'   => $rf_row['id'],
            'raft_name' => $rf_row['name']
        ];
    }
}

// 3. สถิติด่วนด้านบน (จัดการเงื่อนไขตามคอลัมน์ที่มีให้ปลอดภัย 100%)
$this_month = date('Y-m');

$cond_conf = [];
if ($has_sid) $cond_conf[] = "status_id = 2";
if ($has_txt) $cond_conf[] = "status = 'confirmed'";
$str_conf = !empty($cond_conf) ? "(" . implode(" OR ", $cond_conf) . ")" : "1=0";

$cond_pend = [];
if ($has_sid) $cond_pend[] = "status_id = 1";
if ($has_txt) $cond_pend[] = "status = 'pending'";
$str_pend = !empty($cond_pend) ? "(" . implode(" OR ", $cond_pend) . ")" : "1=0";

$cond_not_cancel = [];
if ($has_sid) $cond_not_cancel[] = "status_id != 3";
if ($has_txt) $cond_not_cancel[] = "status != 'cancelled'";
$str_not_cancel = !empty($cond_not_cancel) ? "(" . implode(" AND ", $cond_not_cancel) . ")" : "1=1";

$res_conf = $conn->query("SELECT COUNT(*) as cnt FROM bookings WHERE check_in_date LIKE '$this_month%' AND $str_conf");
$count_confirmed = ($res_conf && $row = $res_conf->fetch_assoc()) ? intval($row['cnt']) : 0;

$res_pend = $conn->query("SELECT COUNT(*) as cnt FROM bookings WHERE $str_pend");
$count_pending = ($res_pend && $row = $res_pend->fetch_assoc()) ? intval($row['cnt']) : 0;

$res_tod = $conn->query("SELECT COUNT(*) as cnt FROM bookings WHERE check_in_date = CURDATE() AND $str_not_cancel");
$count_today = ($res_tod && $row = $res_tod->fetch_assoc()) ? intval($row['cnt']) : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ปฏิทินการจอง - ระบบหลังบ้าน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FullCalendar v6 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core/locales/th.global.min.js"></script>

    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .fc {
            --fc-border-color: #e2e8f0;
            --fc-button-bg-color: #2563eb;
            --fc-button-border-color: #2563eb;
            --fc-button-hover-bg-color: #1d4ed8;
            --fc-today-bg-color: #eff6ff;
            border-radius: 1.25rem;
            background: white;
        }
        .fc .fc-toolbar {
            padding: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .fc .fc-toolbar-title {
            font-size: 1.25rem !important;
            font-weight: 800 !important;
            color: #0f172a;
        }
        .fc-event {
            cursor: pointer;
            border-radius: 8px !important;
            padding: 3px 6px !important;
            font-weight: 600 !important;
            font-size: 0.82rem !important;
            transition: all 0.2s ease;
        }
        .fc-event:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body class="bg-gray-100 flex min-h-screen">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside <?php include 'sidebar.php'; ?>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-grow flex flex-col min-w-0">
        
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 md:px-8 sticky top-0 z-30">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h2 class="text-lg md:text-xl font-bold text-gray-800">📅 ปฏิทินการจองแพ (Admin View)</h2>
            </div>
            <div class="flex items-center space-x-3">
                <a href="../index.php" target="_blank" class="hidden sm:inline-flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold px-3 py-2 rounded-xl transition">
                    <i class="fa fa-external-link-alt"></i> หน้าเว็บลูกค้า
                </a>
            </div>
        </header>

        <div class="p-4 md:p-8 space-y-6">

            <!-- Stats Bar (คลิกเพื่อเลือกวันบนปฏิทินได้) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div onclick="goToToday()" class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4 cursor-pointer hover:border-blue-300 transition group">
                    <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center text-xl font-black group-hover:bg-blue-600 group-hover:text-white transition">
                        <i class="fa fa-calendar-day"></i>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 font-bold block uppercase">การจองวันนี้ (คลิกดู)</span>
                        <span class="text-2xl font-black text-gray-800"><?php echo $count_today; ?> <span class="text-xs font-normal text-gray-500">รายการ</span></span>
                    </div>
                </div>

                <div onclick="filterByStatus('pending')" class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4 cursor-pointer hover:border-amber-300 transition group">
                    <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center text-xl font-black group-hover:bg-amber-600 group-hover:text-white transition">
                        <i class="fa fa-clock"></i>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 font-bold block uppercase">รอตรวจสอบ (คลิกกรอง)</span>
                        <span class="text-2xl font-black text-amber-600"><?php echo $count_pending; ?> <span class="text-xs font-normal text-gray-500">รายการ</span></span>
                    </div>
                </div>

                <div onclick="filterByStatus('confirmed')" class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4 cursor-pointer hover:border-emerald-300 transition group">
                    <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center text-xl font-black group-hover:bg-emerald-600 group-hover:text-white transition">
                        <i class="fa fa-check-circle"></i>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 font-bold block uppercase">ยืนยันแล้วเดือนนี้ (คลิกกรอง)</span>
                        <span class="text-2xl font-black text-emerald-600"><?php echo $count_confirmed; ?> <span class="text-xs font-normal text-gray-500">รายการ</span></span>
                    </div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="bg-white p-4 md:p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">เลือกแพ:</label>
                        <select id="adminRaftFilter" onchange="refreshAdminCalendar()" class="bg-gray-50 border border-gray-300 text-gray-800 text-sm rounded-xl p-2.5 font-bold outline-none focus:border-blue-500">
                            <option value="0">⛵ แพทั้งหมด</option>
                            <?php foreach ($rafts_list as $rf): ?>
                                <option value="<?php echo $rf['raft_id']; ?>"><?php echo htmlspecialchars($rf['raft_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">สถานะ:</label>
                        <select id="adminStatusFilter" onchange="refreshAdminCalendar()" class="bg-gray-50 border border-gray-300 text-gray-800 text-sm rounded-xl p-2.5 font-bold outline-none focus:border-blue-500">
                            <option value="">ทั้งหมด</option>
                            <option value="pending">🟡 รอตรวจสอบ</option>
                            <option value="confirmed">🟢 ยืนยันแล้ว</option>
                            <option value="completed">🔵 เสร็จสิ้น</option>
                            <option value="cancelled">🔴 ยกเลิก</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">เจาะจงวันที่:</label>
                        <input type="date" id="adminDateJump" onchange="jumpToDate(this.value)" class="bg-gray-50 border border-gray-300 text-gray-800 text-sm rounded-xl p-2 font-bold outline-none focus:border-blue-500">
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <a href="manage_bookings.php" class="bg-slate-800 hover:bg-slate-900 text-white px-4 py-2.5 rounded-xl font-bold text-xs transition flex items-center gap-1.5 shadow-md">
                        <i class="fa fa-list"></i> ดูตารางรายการจอง
                    </a>
                </div>
            </div>

            <!-- Calendar Box -->
            <div class="bg-white p-4 md:p-6 rounded-2xl shadow-sm border border-gray-100">
                <div id="adminCalendar"></div>
            </div>

        </div>
    </main>

    <!-- Admin Booking Detail Modal -->
    <div id="adminBookingModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full overflow-hidden shadow-2xl border border-gray-100">
            <div class="bg-slate-900 p-6 text-white relative">
                <button onclick="closeAdminModal('adminBookingModal')" class="absolute top-4 right-4 text-gray-400 hover:text-white bg-slate-800 w-8 h-8 rounded-full flex items-center justify-center">
                    <i class="fa fa-times"></i>
                </button>
                <div class="text-xs font-bold text-blue-400 uppercase tracking-widest">ข้อมูลการจอง</div>
                <h3 id="admModalRaftName" class="text-2xl font-black mt-1"></h3>
                <span id="admModalBookingId" class="inline-block mt-2 bg-slate-800 px-3 py-1 rounded-full text-xs font-mono font-bold text-blue-300"></span>
            </div>

            <div class="p-6 space-y-4 text-sm text-gray-700">
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-500 font-bold text-xs">สถานะ:</span>
                    <span id="admModalStatusBadge" class="px-3 py-1 rounded-full text-xs font-bold"></span>
                </div>

                <div class="grid grid-cols-2 gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    <div>
                        <span class="block text-[11px] font-bold text-gray-400 uppercase">ชื่อลูกค้า</span>
                        <span id="admModalGuestName" class="font-bold text-gray-800 text-sm"></span>
                    </div>
                    <div>
                        <span class="block text-[11px] font-bold text-gray-400 uppercase">เบอร์โทรศัพท์</span>
                        <span id="admModalGuestTel" class="font-bold text-blue-600 text-sm"></span>
                    </div>
                </div>

                <div class="space-y-2 bg-blue-50/60 p-4 rounded-2xl border border-blue-100">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-blue-700 font-bold">วัน-เวลาเช็คอิน:</span>
                        <span id="admModalCheckIn" class="font-extrabold text-gray-800"></span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-blue-700 font-bold">วัน-เวลาเช็คเอาท์:</span>
                        <span id="admModalCheckOut" class="font-extrabold text-gray-800"></span>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2 border-t border-slate-100">
                    <span class="text-gray-500 font-bold text-xs">ยอดรวมทั้งสิ้น:</span>
                    <span class="text-xl font-black text-emerald-600">฿<span id="admModalTotalPrice"></span></span>
                </div>

                <div id="admSlipContainer" class="hidden pt-1">
                    <a id="admSlipLink" href="#" target="_blank" class="inline-flex items-center justify-center gap-1.5 text-xs font-bold text-blue-600 hover:text-blue-800 bg-blue-50 border border-blue-200 px-4 py-2.5 rounded-xl transition w-full">
                        <i class="fa fa-image"></i> คลิกดูสลิปการโอนเงิน (เปิดรูปใหญ่)
                    </a>
                </div>
            </div>

            <div class="bg-gray-50 p-4 px-6 border-t border-gray-100 flex justify-end gap-2">
                <button onclick="closeAdminModal('adminBookingModal')" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold text-xs rounded-xl transition">
                    ปิดหน้าต่าง
                </button>
            </div>
        </div>
    </div>

    <script>
        let adminCalendar;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.toggle('hidden');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('adminCalendar');

            adminCalendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'th',
                firstDay: 0,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                },
                buttonText: {
                    today: 'วันนี้',
                    month: 'เดือน',
                    week: 'สัปดาห์',
                    list: 'รายการ'
                },
                events: function(fetchInfo, successCallback, failureCallback) {
                    const raftId = document.getElementById('adminRaftFilter').value;
                    const status = document.getElementById('adminStatusFilter').value;

                    let url = `booking_calendar.php?api=1&start=${fetchInfo.startStr}&end=${fetchInfo.endStr}&raft_id=${raftId}&status=${status}`;

                    fetch(url)
                        .then(res => res.json())
                        .then(data => successCallback(data))
                        .catch(err => failureCallback(err));
                },
                eventClick: function(info) {
                    const props = info.event.extendedProps;
                    
                    document.getElementById('admModalRaftName').textContent = props.raft_name;
                    document.getElementById('admModalBookingId').textContent = '#' + props.booking_code;
                    document.getElementById('admModalGuestName').textContent = props.guest_name;
                    document.getElementById('admModalGuestTel').textContent = props.guest_tel;
                    document.getElementById('admModalCheckIn').textContent = props.check_in_formatted;
                    document.getElementById('admModalCheckOut').textContent = props.check_out_formatted;
                    document.getElementById('admModalTotalPrice').textContent = props.total_price;

                    const statusBadge = document.getElementById('admModalStatusBadge');
                    statusBadge.textContent = props.status_th;

                    if (props.status === 'confirmed') {
                        statusBadge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800';
                    } else if (props.status === 'completed') {
                        statusBadge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800';
                    } else if (props.status === 'pending') {
                        statusBadge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800';
                    } else {
                        statusBadge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800';
                    }

                    const slipContainer = document.getElementById('admSlipContainer');
                    if (props.slip_image) {
                        document.getElementById('admSlipLink').href = `../uploads/slips/${props.slip_image}`;
                        slipContainer.classList.remove('hidden');
                    } else {
                        slipContainer.classList.add('hidden');
                    }

                    openAdminModal('adminBookingModal');
                }
            });

            adminCalendar.render();
        });

        function refreshAdminCalendar() {
            if (adminCalendar) {
                adminCalendar.refetchEvents();
            }
        }

        function goToToday() {
            if (adminCalendar) {
                adminCalendar.today();
            }
        }

        function filterByStatus(statusVal) {
            const statusSelect = document.getElementById('adminStatusFilter');
            if (statusSelect) {
                statusSelect.value = statusVal;
                refreshAdminCalendar();
            }
        }

        function jumpToDate(dateStr) {
            if (dateStr && adminCalendar) {
                adminCalendar.gotoDate(dateStr);
            }
        }

        function openAdminModal(id) {
            document.getElementById(id).classList.remove('hidden');
        }

        function closeAdminModal(id) {
            document.getElementById(id).classList.add('hidden');
        }
    </script>
</body>
</html>