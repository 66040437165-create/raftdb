<?php
session_start();
require_once __DIR__ . '/db_config.php';

// ตั้งค่า Base URL และเชื่อมต่อ PostgreSQL (ใช้ตัวแปร $conn จาก db_config.php ที่รองรับ Render แล้ว)
// --- AJAX API ENDPOINT ---
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    
    $raft_filter = isset($_GET['raft_id']) ? intval($_GET['raft_id']) : 0;
    $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    $start = isset($_GET['start']) ? $_GET['start'] : '';
    $end = isset($_GET['end']) ? $_GET['end'] : '';

    $where = [];
    $params = [];
    $p_idx = 1;

    if ($raft_filter > 0) {
        $where[] = "b.raft_id = $" . $p_idx;
        $params[] = $raft_filter;
        $p_idx++;
    }
    if (!empty($status_filter)) {
        if ($status_filter === 'active') {
            $where[] = "b.status IN ('confirmed', 'completed')";
        } else {
            $where[] = "b.status = $" . $p_idx;
            $params[] = $status_filter;
            $p_idx++;
        }
    }
    if (!empty($start)) {
        $start_clean = substr($start, 0, 10);
        $where[] = "b.check_out >= ($" . $p_idx . " || ' 00:00:00')::timestamp";
        $params[] = $start_clean;
        $p_idx++;
    }
    if (!empty($end)) {
        $end_clean = substr($end, 0, 10);
        $where[] = "b.check_in <= ($" . $p_idx . " || ' 23:59:59')::timestamp";
        $params[] = $end_clean;
        $p_idx++;
    }

    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT b.*, r.name as raft_name, r.capacity, u.fullname as user_fullname 
            FROM bookings b 
            JOIN rafts r ON b.raft_id = r.id 
            LEFT JOIN users u ON b.user_id = u.id 
            $where_sql 
            ORDER BY b.check_in ASC";

    $result = !empty($params) ? @pg_query_params($conn, $sql, $params) : @pg_query($conn, $sql);
    $events = [];

    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $color = '#3b82f6'; 
            $border_color = '#2563eb';
            $status_th = 'รอดำเนินการ';

            if ($row['status'] == 'confirmed') {
                $color = '#10b981'; 
                $border_color = '#059669';
                $status_th = 'ยืนยันแล้ว (ติดจอง)';
            } elseif ($row['status'] == 'completed') {
                $color = '#3b82f6'; 
                $border_color = '#1d4ed8';
                $status_th = 'เสร็จสิ้น';
            } elseif ($row['status'] == 'pending') {
                $color = '#f59e0b'; 
                $border_color = '#d97706';
                $status_th = 'รอตรวจสอบ';
            } elseif ($row['status'] == 'cancelled') {
                $color = '#ef4444'; 
                $border_color = '#b91c1c';
                $status_th = 'ยกเลิกการจอง';
            }

            $display_name = !empty($row['guest_name']) ? $row['guest_name'] : (!empty($row['user_fullname']) ? $row['user_fullname'] : 'ลูกค้าทั่วไป');

            // ปิดบังเบอร์โทรเพื่อความเป็นส่วนตัวของลูกค้าทั่วไป
            $tel = !empty($row['guest_tel']) ? $row['guest_tel'] : '-';
            if (strlen($tel) >= 9 && !isset($_SESSION['role'])) {
                $tel = substr($tel, 0, 3) . '***' . substr($tel, -3);
            }

            $events[] = [
                'id' => $row['id'],
                'title' => '⛵ ' . $row['raft_name'] . ' (' . $display_name . ')',
                'start' => $row['check_in'],
                'end' => $row['check_out'],
                'backgroundColor' => $color,
                'borderColor' => $border_color,
                'textColor' => '#ffffff',
                'extendedProps' => [
                    'booking_id' => $row['id'],
                    'raft_id' => $row['raft_id'],
                    'raft_name' => $row['raft_name'],
                    'capacity' => $row['capacity'] ?? 0,
                    'guest_name' => $display_name,
                    'guest_tel' => $tel,
                    'total_price' => number_format($row['total_price'] ?? 0, 2),
                    'status' => $row['status'],
                    'status_th' => $status_th,
                    'check_in_formatted' => date('d/m/Y H:i', strtotime($row['check_in'])),
                    'check_out_formatted' => date('d/m/Y H:i', strtotime($row['check_out'])),
                    'check_in_raw' => date('Y-m-d', strtotime($row['check_in']))
                ]
            ];
        }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE);
    exit();
}

// Fetch rafts for dropdown filter
$rafts_res = @pg_query($conn, "SELECT id as raft_id, name as raft_name FROM rafts ORDER BY name ASC");
$rafts = [];
if ($rafts_res) {
    while ($r = pg_fetch_assoc($rafts_res)) {
        $rafts[] = $r;
    }
}

// Monthly stats
$this_month = date('Y-m');
$total_this_month = 0;
$confirmed_this_month = 0;
$pending_this_month = 0;

$res_stat1 = @pg_query_params($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE to_char(check_in, 'YYYY-MM') = $1 AND status != 'cancelled'", [$this_month]);
if ($res_stat1 && $row_s = pg_fetch_assoc($res_stat1)) { $total_this_month = intval($row_s['cnt']); }

$res_stat2 = @pg_query_params($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE to_char(check_in, 'YYYY-MM') = $1 AND status = 'confirmed'", [$this_month]);
if ($res_stat2 && $row_s = pg_fetch_assoc($res_stat2)) { $confirmed_this_month = intval($row_s['cnt']); }

$res_stat3 = @pg_query_params($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE to_char(check_in, 'YYYY-MM') = $1 AND status = 'pending'", [$this_month]);
if ($res_stat3 && $row_s = pg_fetch_assoc($res_stat3)) { $pending_this_month = intval($row_s['cnt']); }

$total_rafts_count = count($rafts);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ปฏิทินตารางการจองแพ - ล่องแพหนองกวาก</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FullCalendar v6 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core/locales/th.global.min.js"></script>

    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f8fafc; }
        .fc {
            --fc-border-color: #e2e8f0;
            --fc-button-bg-color: #2563eb;
            --fc-button-border-color: #2563eb;
            --fc-button-hover-bg-color: #1d4ed8;
            --fc-button-active-bg-color: #1e40af;
            --fc-today-bg-color: #eff6ff;
            border-radius: 1.25rem;
            overflow: hidden;
            background: white;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }
        .fc .fc-toolbar { padding: 1.25rem 1.5rem; margin-bottom: 0 !important; background: #ffffff; border-bottom: 1px solid #f1f5f9; }
        .fc .fc-toolbar-title { font-size: 1.35rem !important; font-weight: 800 !important; color: #0f172a; }
        .fc .fc-col-header-cell { padding: 12px 0; background-color: #f8fafc; color: #475569; font-weight: 700; font-size: 0.9rem; }
        .fc-daygrid-day-number { font-weight: 700; color: #334155; padding: 6px 10px !important; font-size: 0.9rem; }
        .fc-event { cursor: pointer; border-radius: 8px !important; padding: 3px 6px !important; font-weight: 600 !important; font-size: 0.82rem !important; margin: 2px 0 !important; }
        .fc-day-today { background-color: #f0fdf4 !important; }
        .btn-animate { transition: all 0.2s ease; }
        .btn-animate:hover { transform: translateY(-2px); }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(12px); }
    </style>
</head>
<body class="bg-slate-50 text-gray-800 min-h-screen flex flex-col">

    <!-- Top Navbar -->
    <nav class="glass-header shadow-sm border-b border-gray-100 sticky top-0 z-40">
        <div class="container mx-auto px-4 md:px-8 py-3.5 flex justify-between items-center">
            <a href="index.php" class="text-xl md:text-2xl font-black text-blue-600 flex items-center gap-2.5">
                <span class="text-2xl md:text-3xl">🌊</span>
                <div>
                    <span class="block leading-tight font-extrabold text-blue-600">ล่องแพหนองกวาก</span>
                    <span class="text-[10px] text-gray-400 font-medium block uppercase tracking-widest">ChillRaft NongKwak</span>
                </div>
            </a>

            <div class="hidden md:flex items-center space-x-6 font-bold text-gray-600 text-sm">
                <a href="index.php" class="hover:text-blue-600 transition">หน้าแรก</a>
                <a href="index.php#rafts" class="hover:text-blue-600 transition">รายการแพ</a>
                <a href="customer_calendar.php" class="text-blue-600 font-extrabold bg-blue-50 px-3.5 py-1.5 rounded-xl border border-blue-200 shadow-sm flex items-center gap-1.5">
                    <i class="fa fa-calendar-alt text-blue-500"></i> ปฏิทินการจอง
                </a>
            </div>

            <div class="flex items-center space-x-3">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <span class="hidden sm:inline text-xs md:text-sm font-bold text-gray-700">👤 <?php echo htmlspecialchars($_SESSION['fullname'] ?? ''); ?></span>
                    <a href="logout.php" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-3.5 py-1.5 rounded-xl text-xs font-bold transition">ออกจากระบบ</a>
                <?php else: ?>
                    <a href="login.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-xs md:text-sm font-bold transition shadow-md shadow-blue-200">
                        <i class="fa fa-user-circle mr-1"></i> เข้าสู่ระบบ
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <main class="flex-grow container mx-auto px-4 md:px-8 py-6 md:py-8 max-w-7xl">
        
        <!-- Header Title Banner -->
        <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-sky-500 rounded-3xl p-6 md:p-8 text-white shadow-xl mb-8 relative overflow-hidden">
            <div class="relative z-10 max-w-3xl">
                <div class="inline-flex items-center gap-2 px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-xs font-bold mb-3 border border-white/30">
                    <i class="fa fa-sparkles text-amber-300"></i> ระบบตรวจเช็คคิวแพแบบเรียลไทม์
                </div>
                <h1 class="text-2xl md:text-4xl font-extrabold mb-2 leading-tight">📅 ปฏิทินตารางการจองแพ</h1>
                <p class="text-blue-100 text-xs md:text-sm leading-relaxed">
                    ตรวจสอบวันที่แพว่างหรือถูกจองแล้วได้ทันทีแบบโปร่งใส สามารถกดดูรายละเอียดรายการจองหรือกดเลือกวันเพื่อจองแพที่ต้องการได้เลย
                </p>
            </div>

            <!-- Stats Bar -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6 pt-6 border-t border-white/20">
                <div class="bg-white/10 backdrop-blur-md rounded-2xl p-3 text-center border border-white/10">
                    <span class="block text-[11px] text-blue-100 font-medium">จองทั้งหมดเดือนนี้</span>
                    <span class="text-xl md:text-2xl font-black"><?php echo $total_this_month; ?> <span class="text-xs font-normal opacity-80">รายการ</span></span>
                </div>
                <div class="bg-emerald-500/20 backdrop-blur-md rounded-2xl p-3 text-center border border-emerald-300/30">
                    <span class="block text-[11px] text-emerald-100 font-medium">ยืนยัน/ติดจอง</span>
                    <span class="text-xl md:text-2xl font-black text-emerald-200"><?php echo $confirmed_this_month; ?> <span class="text-xs font-normal opacity-80">รายการ</span></span>
                </div>
                <div class="bg-amber-500/20 backdrop-blur-md rounded-2xl p-3 text-center border border-amber-300/30">
                    <span class="block text-[11px] text-amber-100 font-medium">รอการตรวจสอบ</span>
                    <span class="text-xl md:text-2xl font-black text-amber-200"><?php echo $pending_this_month; ?> <span class="text-xs font-normal opacity-80">รายการ</span></span>
                </div>
                <div class="bg-white/10 backdrop-blur-md rounded-2xl p-3 text-center border border-white/10">
                    <span class="block text-[11px] text-blue-100 font-medium">แพทั้งหมดในระบบ</span>
                    <span class="text-xl md:text-2xl font-black"><?php echo $total_rafts_count; ?> <span class="text-xs font-normal opacity-80">ลำ</span></span>
                </div>
            </div>
        </div>

        <!-- Filter Controls & Legend Bar -->
        <div class="bg-white rounded-2xl p-4 md:p-6 shadow-sm border border-slate-200/80 mb-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="w-full sm:w-auto">
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">กรองตามแพ</label>
                        <select id="raftFilter" onchange="refreshCalendar()" class="w-full sm:w-56 bg-slate-50 border border-slate-300 font-bold text-gray-700 text-sm rounded-xl p-2.5 outline-none focus:border-blue-500">
                            <option value="0">⛵ แพทั้งหมด (ทุกลำ)</option>
                            <?php foreach ($rafts as$r): ?>
                                <option value="<?php echo $r['raft_id']; ?>"><?php echo htmlspecialchars($r['raft_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="w-full sm:w-auto">
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">กรองสถานะ</label>
                        <select id="statusFilter" onchange="refreshCalendar()" class="w-full sm:w-48 bg-slate-50 border border-slate-300 font-bold text-gray-700 text-sm rounded-xl p-2.5 outline-none focus:border-blue-500">
                            <option value="">ทั้งหมด</option>
                            <option value="confirmed" selected>🟢 ยืนยันแล้ว (ติดจอง)</option>
                            <option value="pending">🟡 รอตรวจสอบ</option>
                            <option value="completed">🔵 บริการเสร็จสิ้น</option>
                            <option value="cancelled">🔴 ยกเลิก</option>
                        </select>
                    </div>

                    <div class="w-full sm:w-auto self-end">
                        <button onclick="resetFilters()" class="w-full sm:w-auto px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition border border-slate-300 flex items-center justify-center gap-1.5">
                            <i class="fa fa-rotate-left"></i> ล้างการกรอง
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-3 lg:pt-0 border-t lg:border-t-0 border-slate-100 text-xs font-bold">
                    <span class="text-gray-400 text-[11px] uppercase tracking-wider mr-1">สัญลักษณ์:</span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> ยืนยันแล้ว
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> รอตรวจสอบ
                    </span>
                </div>
            </div>
        </div>

        <!-- Calendar Container -->
        <div class="relative">
            <div id="loadingSpinner" class="hidden absolute inset-0 bg-white/70 backdrop-blur-sm z-30 flex items-center justify-center rounded-3xl transition">
                <div class="flex items-center gap-3 bg-slate-900 text-white px-5 py-3 rounded-2xl shadow-2xl font-bold text-sm">
                    <i class="fa fa-circle-notch fa-spin text-blue-400 text-lg"></i> กำลังโหลดข้อมูล...
                </div>
            </div>
            <div id="calendar" class="p-2 md:p-4"></div>
        </div>
    </main>

    <!-- Modal for Booking Details -->
    <div id="bookingModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full overflow-hidden shadow-2xl border border-slate-100">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-6 text-white relative">
                <button onclick="closeModal('bookingModal')" class="absolute top-4 right-4 text-white/80 hover:text-white bg-white/10 w-8 h-8 rounded-full flex items-center justify-center transition">
                    <i class="fa fa-times"></i>
                </button>
                <div class="flex items-center gap-2 text-xs font-bold text-blue-200 uppercase tracking-widest mb-1">
                    <i class="fa fa-info-circle"></i> รายละเอียดการจอง
                </div>
                <h3 id="modalRaftName" class="text-xl md:text-2xl font-black leading-tight"></h3>
                <span id="modalBookingId" class="inline-block mt-2 bg-white/20 px-3 py-1 rounded-full text-xs font-mono font-bold"></span>
            </div>

            <div class="p-6 space-y-4 text-sm text-gray-700">
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-500 font-bold text-xs">สถานะการจอง:</span>
                    <span id="modalStatusBadge" class="px-3 py-1 rounded-full text-xs font-bold"></span>
                </div>
                <div class="grid grid-cols-2 gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-100">
                    <div>
                        <span class="block text-[11px] font-bold text-gray-400 uppercase">ชื่อผู้จอง</span>
                        <span id="modalGuestName" class="font-bold text-gray-800 text-sm"></span>
                    </div>
                    <div>
                        <span class="block text-[11px] font-bold text-gray-400 uppercase">เบอร์โทรศัพท์</span>
                        <span id="modalGuestTel" class="font-bold text-gray-800 text-sm"></span>
                    </div>
                </div>
                <div class="space-y-2 bg-blue-50/60 p-4 rounded-2xl border border-blue-100 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-blue-700 font-bold"><i class="fa fa-calendar-check text-blue-500"></i> เช็คอิน:</span>
                        <span id="modalCheckIn" class="font-extrabold text-gray-800"></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-blue-700 font-bold"><i class="fa fa-calendar-minus text-blue-500"></i> เช็คเอาท์:</span>
                        <span id="modalCheckOut" class="font-extrabold text-gray-800"></span>
                    </div>
                </div>
                <div class="flex justify-between items-center pt-2">
                    <span class="text-gray-500 font-bold text-xs">ยอดรวมทั้งสิ้น:</span>
                    <span class="text-xl font-black text-emerald-600">฿<span id="modalTotalPrice"></span></span>
                </div>
            </div>

            <div class="bg-slate-50 p-4 px-6 border-t border-slate-100 flex justify-end gap-3">
                <button onclick="closeModal('bookingModal')" class="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-gray-700 font-bold text-xs rounded-xl transition">ปิด</button>
                <a id="modalActionBtn" href="index.php#rafts" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl transition shadow-md flex items-center gap-1.5">
                    <i class="fa fa-plus-circle"></i> จองแพนี้ในวันอื่น
                </a>
            </div>
        </div>
    </div>

    <!-- JavaScript logic -->
    <script>
        let calendar;

        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');

            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'th',
                firstDay: 0,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                },
                buttonText: { today: 'วันนี้', month: 'เดือน', week: 'สัปดาห์', list: 'รายการ' },
                events: function(fetchInfo, successCallback, failureCallback) {
                    showSpinner();
                    const raftId = document.getElementById('raftFilter').value;
                    const status = document.getElementById('statusFilter').value;
                    let url = `customer_calendar.php?api=1&start=${fetchInfo.startStr}&end=${fetchInfo.endStr}&raft_id=${raftId}&status=${status}`;

                    fetch(url)
                        .then(res => res.json())
                        .then(data => { hideSpinner(); successCallback(data); })
                        .catch(err => { hideSpinner(); failureCallback(err); });
                },
                eventClick: function(info) {
                    const props = info.event.extendedProps;
                    document.getElementById('modalRaftName').textContent = props.raft_name;
                    document.getElementById('modalBookingId').textContent = 'Booking #' + String(props.booking_id).padStart(6, '0');
                    document.getElementById('modalGuestName').textContent = props.guest_name;
                    document.getElementById('modalGuestTel').textContent = props.guest_tel;
                    document.getElementById('modalCheckIn').textContent = props.check_in_formatted;
                    document.getElementById('modalCheckOut').textContent = props.check_out_formatted;
                    document.getElementById('modalTotalPrice').textContent = props.total_price;

                    const statusBadge = document.getElementById('modalStatusBadge');
                    statusBadge.textContent = props.status_th;
                    statusBadge.className = 'px-3 py-1 rounded-full text-xs font-bold ' + 
                        (props.status === 'confirmed' ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-amber-100 text-amber-800');

                    document.getElementById('modalActionBtn').href = `index.php?checkin=${props.check_in_raw}#rafts`;
                    openModal('bookingModal');
                }
            });

            calendar.render();
        });

        function refreshCalendar() { if (calendar) calendar.refetchEvents(); }
        function resetFilters() { document.getElementById('raftFilter').value = '0'; document.getElementById('statusFilter').value = 'confirmed'; refreshCalendar(); }
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
        function showSpinner() { document.getElementById('loadingSpinner')?.classList.remove('hidden'); }
        function hideSpinner() { document.getElementById('loadingSpinner')?.classList.add('hidden'); }
    </script>
</body>
</html>
