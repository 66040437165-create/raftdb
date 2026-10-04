<?php
session_start();
require_once __DIR__ . '/db_config.php';

// ตรวจสอบสิทธิ์ผู้ดูแลระบบ (Admin Check)
$is_admin = (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') || 
            (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);

// --- AJAX API ENDPOINT ---
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    
    $raft_filter   = isset($_GET['raft_id']) ? intval($_GET['raft_id']) : 0;
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

    $where = [];
    $params = [];
    $p_idx = 1;

    // กรองตามแพ
    if ($raft_filter > 0) {
        $where[] = "b.raft_id = $" . $p_idx++;
        $params[] = $raft_filter;
    }

    // กรองตามสถานะ (ปรับให้ยืดหยุ่นรองรับทั้งชื่อสถานะและ status_id)
    if (!empty($status_filter)) {
        if ($status_filter === 'active' || $status_filter === 'confirmed') {
            $where[] = "(LOWER(b.status) IN ('confirmed', 'active', 'ยืนยันแล้ว') OR b.status_id = 2)";
        } elseif ($status_filter === 'pending') {
            $where[] = "(LOWER(b.status) IN ('pending', 'รอตรวจสอบ') OR b.status_id = 1)";
        } elseif ($status_filter === 'completed') {
            $where[] = "(LOWER(b.status) IN ('completed', 'เสร็จสิ้น') OR b.status_id = 5)";
        } elseif ($status_filter === 'cancelled') {
            $where[] = "(LOWER(b.status) IN ('cancelled', 'rejected', 'cancel', 'ยกเลิก') OR b.status_id IN (3, 4))";
        }
    }

    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT b.*, 
                   COALESCE(b.id, 0) AS booking_id_clean,
                   COALESCE(r.name, '') AS raft_name, 
                   COALESCE(r.featured_image, '') AS raft_img, 
                   COALESCE(r.capacity, 0) AS raft_capacity,
                   COALESCE(c.full_name, b.guest_name, 'ลูกค้าทั่วไป') AS display_name,
                   COALESCE(c.phone, b.guest_tel, '-') AS display_tel,
                   COALESCE(c.email, b.guest_email, '-') AS display_email,
                   COALESCE(b.total_amount, b.total_price, b.raft_price, 0) AS final_price,
                   COALESCE(b.check_in_date, CAST(b.check_in AS DATE)) AS valid_check_in,
                   COALESCE(b.check_out_date, CAST(b.check_out AS DATE)) AS valid_check_out
            FROM bookings b 
            LEFT JOIN rafts r ON b.raft_id = r.id 
            LEFT JOIN customers c ON b.customer_id = c.id
            $where_sql 
            ORDER BY valid_check_in ASC";

    $events = [];

    if ($conn) {
        $result = !empty($params) ? @pg_query_params($conn, $sql, $params) : @pg_query($conn, $sql);

        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $check_in_date = !empty($row['valid_check_in']) ? date('Y-m-d', strtotime($row['valid_check_in'])) : date('Y-m-d');
                $check_in_time = !empty($row['check_in_time']) ? date('H:i', strtotime($row['check_in_time'])) : '09:00';
                $start_iso     = "{$check_in_date}T{$check_in_time}:00";

                $check_out_date = !empty($row['valid_check_out']) ? date('Y-m-d', strtotime($row['valid_check_out'])) : $check_in_date;
                $check_out_time = !empty($row['check_out_time']) ? date('H:i', strtotime($row['check_out_time'])) : '17:30';
                $end_iso        = "{$check_out_date}T{$check_out_time}:00";

                $raw_status = strtolower(trim($row['status'] ?? ''));
                $st_id = intval($row['status_id'] ?? 0);

                $color = '#3b82f6'; 
                $border_color = '#2563eb';
                $status_th = 'รอดำเนินการ';
                $status_key = 'pending';

                if ($raw_status === 'confirmed' || $raw_status === 'active' || $st_id === 2 || $raw_status === 'ยืนยันแล้ว') {
                    $color = '#10b981'; 
                    $border_color = '#059669';
                    $status_th = 'ยืนยันแล้ว (ติดจอง)';
                    $status_key = 'confirmed';
                } elseif ($raw_status === 'completed' || $raw_status === 'เสร็จสิ้น') {
                    $color = '#3b82f6'; 
                    $border_color = '#1d4ed8';
                    $status_th = 'เสร็จสิ้น';
                    $status_key = 'completed';
                } elseif ($raw_status === 'pending' || $st_id === 1 || $raw_status === 'รอตรวจสอบ') {
                    $color = '#f59e0b'; 
                    $border_color = '#d97706';
                    $status_th = 'รอตรวจสอบ';
                    $status_key = 'pending';
                } elseif ($raw_status === 'cancelled' || $raw_status === 'rejected' || in_array($st_id, [3, 4]) || $raw_status === 'ยกเลิก') {
                    $color = '#ef4444'; 
                    $border_color = '#b91c1c';
                    $status_th = 'ยกเลิกการจอง';
                    $status_key = 'cancelled';
                }

                $guest_name = $row['display_name'];
                $tel = $row['display_tel'];

                // ซ่อนเบอร์เฉพาะลูกค้าทั่วไป แต่แอดมินจะเห็นเบอร์จริงเต็มจำนวน
                if (!$is_admin && strlen($tel) >= 9) {
                    $tel = substr($tel, 0, 3) . '***' . substr($tel, -3);
                }

                $b_id = $row['id'] ?? $row['booking_id_clean'];
                $b_code = !empty($row['booking_code']) ? $row['booking_code'] : ('BK' . str_pad($b_id, 6, '0', STR_PAD_LEFT));

                $events[] = [
                    'id' => $b_id,
                    'title' => '⛵ ' . ($row['raft_name'] ?: 'แพ') . ' (' . $guest_name . ')',
                    'start' => $start_iso,
                    'end' => $end_iso,
                    'backgroundColor' => $color,
                    'borderColor' => $border_color,
                    'textColor' => '#ffffff',
                    'extendedProps' => [
                        'booking_id' => $b_id,
                        'booking_code' => $b_code,
                        'raft_id' => $row['raft_id'] ?? 0,
                        'raft_name' => $row['raft_name'] ?: 'แพ',
                        'capacity' => $row['raft_capacity'] ?? 0,
                        'guest_name' => $guest_name,
                        'guest_tel' => $tel,
                        'guest_email' => $row['display_email'],
                        'total_price' => number_format((float)$row['final_price'], 2),
                        'status' => $status_key,
                        'status_th' => $status_th,
                        'check_in_formatted' => date('d/m/Y', strtotime($check_in_date)) . " {$check_in_time} น.",
                        'check_out_formatted' => date('d/m/Y', strtotime($check_out_date)) . " {$check_out_time} น.",
                        'check_in_raw' => $check_in_date
                    ]
                ];
            }
        }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE);
    exit();
}

// 1. ดึงรายชื่อแพสำหรับ Dropdown Filter (PostgreSQL)
$rafts = [];
if ($conn) {
    $rafts_res = @pg_query($conn, "SELECT id, name FROM rafts ORDER BY id ASC");
    if ($rafts_res) {
        while ($r = pg_fetch_assoc($rafts_res)) {
            $rafts[] = [
                'raft_id'   => $r['id'],
                'raft_name' => $r['name']
            ];
        }
    }
}

// 2. ดึงสถิติและยอดเงินรวมประจำเดือน (PostgreSQL)
$this_month = date('Y-m');
$total_this_month = 0;
$confirmed_this_month = 0;
$pending_this_month = 0;
$revenue_confirmed = 0.0;
$revenue_pending = 0.0;

if ($conn) {
    // 2.1 จำนวนการจองทั้งหมด
    $q_total = @pg_query_params($conn, "
        SELECT COUNT(*) as cnt FROM bookings 
        WHERE (check_in_date::text LIKE $1 OR check_in::text LIKE $1)
          AND (status NOT IN ('cancelled', 'rejected') OR status IS NULL)
          AND COALESCE(status_id, 0) NOT IN (3, 4)
    ", array($this_month . '%'));
    if ($q_total && $r = pg_fetch_assoc($q_total)) $total_this_month = (int)$r['cnt'];

    // 2.2 คิวที่ยืนยันแล้ว + ยอดเงินที่ได้รับจริง
    $q_conf = @pg_query_params($conn, "
        SELECT COUNT(*) as cnt, 
               COALESCE(SUM(COALESCE(total_amount, total_price, raft_price, 0)), 0) as total_money 
        FROM bookings 
        WHERE (check_in_date::text LIKE $1 OR check_in::text LIKE $1)
          AND (status = 'confirmed' OR status_id = 2)
    ", array($this_month . '%'));
    if ($q_conf && $r = pg_fetch_assoc($q_conf)) {
        $confirmed_this_month = (int)$r['cnt'];
        $revenue_confirmed    = (float)$r['total_money'];
    }

    // 2.3 คิวรอตรวจสอบ + ยอดเงินที่รอยืนยัน
    $q_pend = @pg_query_params($conn, "
        SELECT COUNT(*) as cnt, 
               COALESCE(SUM(COALESCE(total_amount, total_price, raft_price, 0)), 0) as total_money 
        FROM bookings 
        WHERE (check_in_date::text LIKE $1 OR check_in::text LIKE $1)
          AND (status = 'pending' OR status_id = 1)
    ", array($this_month . '%'));
    if ($q_pend && $r = pg_fetch_assoc($q_pend)) {
        $pending_this_month = (int)$r['cnt'];
        $revenue_pending    = (float)$r['total_money'];
    }
}

$total_rafts_count = count($rafts);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ปฏิทินตารางการจองแพหนองกวาก</title>
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
            border-radius: 1.5rem;
            overflow: hidden;
            background: white;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
        }
        .fc .fc-toolbar {
            padding: 1.25rem 1.5rem;
            margin-bottom: 0 !important;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
        }
        .fc .fc-toolbar-title {
            font-size: 1.35rem !important;
            font-weight: 800 !important;
            color: #0f172a;
        }
        .fc .fc-col-header-cell {
            padding: 12px 0;
            background-color: #f8fafc;
            color: #475569;
            font-weight: 700;
            font-size: 0.9rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .fc-daygrid-day-number {
            font-weight: 700;
            color: #334155;
            padding: 6px 10px !important;
            font-size: 0.9rem;
        }
        .fc-event {
            cursor: pointer;
            border-radius: 8px !important;
            padding: 3px 6px !important;
            font-weight: 600 !important;
            font-size: 0.82rem !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            transition: all 0.2s ease;
            margin-top: 2px !important;
            margin-bottom: 2px !important;
        }
        .fc-event:hover {
            transform: translateY(-1px) scale(1.01);
            filter: brightness(1.08);
            box-shadow: 0 4px 10px rgba(0,0,0,0.12);
        }
        .fc-day-today {
            background-color: #f0fdf4 !important;
        }
        .fc-day-today .fc-daygrid-day-number {
            background: #16a34a;
            color: white !important;
            border-radius: 9999px;
            width: 26px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 4px;
        }
        .btn-animate { transition: all 0.2s ease; }
        .btn-animate:hover { transform: translateY(-2px); }
        .btn-animate:active { transform: scale(0.97); }
    </style>
</head>
<body class="bg-slate-50 text-gray-800 min-h-screen flex flex-col">

    <!-- Top Navbar -->
    <nav class="bg-white/95 backdrop-blur-md shadow-sm border-b border-gray-100 sticky top-0 z-40">
        <div class="container mx-auto px-4 md:px-8 py-3.5 flex justify-between items-center">
            <a href="index.php" class="text-xl md:text-2xl font-black text-blue-600 flex items-center gap-2.5">
                <span class="text-2xl md:text-3xl"></span>
                <div>
                    <span class="block leading-tight font-extrabold text-blue-600">ล่องแพหนองกวาก</span>
                    <span class="text-[10px] text-gray-400 font-medium block uppercase tracking-widest"></span>
                </div>
            </a>

            <div class="hidden md:flex items-center space-x-6 font-bold text-gray-600 text-sm">
                <a href="index.php" class="hover:text-blue-600 transition">หน้าแรก</a>
                <a href="index.php#rafts" class="hover:text-blue-600 transition">รายการแพ</a>
                <a href="booking_calendar.php" class="text-blue-600 font-extrabold bg-blue-50 px-3.5 py-1.5 rounded-xl border border-blue-200 shadow-sm flex items-center gap-1.5">
                    <i class="fa fa-calendar-alt text-blue-500"></i> ปฏิทินการจอง
                </a>
                <?php if ($is_admin): ?>
                    <a href="admin_dashboard.php" class="text-amber-800 bg-amber-100 hover:bg-amber-200 px-3 py-1.5 rounded-xl border border-amber-300 transition flex items-center gap-1.5 font-black">
                        <i class="fa fa-user-shield text-amber-600"></i> ระบบหลังบ้าน
                    </a>
                <?php endif; ?>
            </div>

            <div class="flex items-center space-x-3">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <span class="hidden sm:inline text-xs md:text-sm font-bold text-gray-700">👤 <?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
                    <a href="logout.php" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-3.5 py-1.5 rounded-xl text-xs font-bold transition">ออกจากระบบ</a>
                <?php else: ?>
                    <a href="line_login.php" class="bg-[#06C755] hover:bg-[#05b04b] text-white px-3.5 py-2 rounded-xl text-xs md:text-sm font-bold transition flex items-center gap-1 shadow-sm">
                        <i class="fab fa-line text-lg"></i> เข้าสู่ระบบด้วย LINE
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <main class="flex-grow container mx-auto px-4 md:px-8 py-6 md:py-8 max-w-7xl">
        
        <!-- 🟢 ส่วนสำหรับแอดมิน: แผงสรุปยอดเงินและคิวงาน (Admin Revenue & Operations Dashboard) -->
        <?php if ($is_admin): ?>
            <div class="mb-8 bg-slate-900 rounded-[2rem] p-6 md:p-8 text-white shadow-2xl border border-slate-800 relative overflow-hidden">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-6 border-b border-slate-800">
                    <div>
                        <div class="inline-flex items-center gap-1.5 bg-amber-500/20 text-amber-300 border border-amber-500/30 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-widest mb-2">
                            <i class="fa fa-shield-alt"></i> แดชบอร์ดสรุปยอดหลังบ้าน (Admin View)
                        </div>
                        <h2 class="text-2xl md:text-3xl font-black">สรุปยอดรายได้และการจองประจำเดือน</h2>
                        <p class="text-xs text-slate-400 mt-1">ข้อมูลยอดเงินสด คิวการจอง และรายการรอตรวจสอบของเดือน <?php echo date('m/Y'); ?></p>
                    </div>
                    <a href="admin_dashboard.php" class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-3 rounded-2xl font-bold text-xs md:text-sm shadow-lg shadow-blue-600/30 flex items-center gap-2 self-start sm:self-auto transition btn-animate">
                        <i class="fa fa-external-link-alt"></i> ไปที่ระบบจัดการหลังบ้าน
                    </a>
                </div>

                <!-- 4 การ์ดสรุปยอดเงินและคิวจอง -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- 1. ยอดรายได้อนุมัติแล้ว -->
                    <div class="bg-gradient-to-br from-emerald-950/60 to-emerald-900/40 p-5 rounded-2xl border border-emerald-500/30">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-emerald-300">รายได้ที่อนุมัติแล้ว</span>
                            <span class="w-8 h-8 rounded-xl bg-emerald-500/20 flex items-center justify-center text-emerald-400"><i class="fa fa-wallet"></i></span>
                        </div>
                        <p class="text-2xl md:text-3xl font-black text-emerald-300">฿<?php echo number_format($revenue_confirmed, 2); ?></p>
                        <p class="text-[11px] text-emerald-400/80 mt-1"><i class="fa fa-check-circle mr-1"></i> ได้รับเงินแล้ว (<?php echo $confirmed_this_month; ?> คิว)</p>
                    </div>

                    <!-- 2. ยอดเงินรอตรวจสอบ -->
                    <div class="bg-gradient-to-br from-amber-950/60 to-amber-900/40 p-5 rounded-2xl border border-amber-500/30">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-amber-300">ยอดเงินรอตรวจสอบ</span>
                            <span class="w-8 h-8 rounded-xl bg-amber-500/20 flex items-center justify-center text-amber-400"><i class="fa fa-clock"></i></span>
                        </div>
                        <p class="text-2xl md:text-3xl font-black text-amber-300">฿<?php echo number_format($revenue_pending, 2); ?></p>
                        <p class="text-[11px] text-amber-400/80 mt-1"><i class="fa fa-receipt mr-1"></i> รอตรวจสลิป (<?php echo $pending_this_month; ?> คิว)</p>
                    </div>

                    <!-- 3. คิวจองทั้งหมด -->
                    <div class="bg-slate-800/60 p-5 rounded-2xl border border-slate-700/60">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-slate-300">จองทั้งหมดเดือนนี้</span>
                            <span class="w-8 h-8 rounded-xl bg-blue-500/20 flex items-center justify-center text-blue-400"><i class="fa fa-calendar-check"></i></span>
                        </div>
                        <p class="text-2xl md:text-3xl font-black text-white"><?php echo $total_this_month; ?> <span class="text-sm font-normal text-slate-400">รายการ</span></p>
                        <p class="text-[11px] text-slate-400 mt-1">ยอดรวมคิวจองทุกลำ</p>
                    </div>

                    <!-- 4. แพพร้อมให้บริการ -->
                    <div class="bg-slate-800/60 p-5 rounded-2xl border border-slate-700/60">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-slate-300">จำนวนแพในระบบ</span>
                            <span class="w-8 h-8 rounded-xl bg-purple-500/20 flex items-center justify-center text-purple-400"><i class="fa fa-ship"></i></span>
                        </div>
                        <p class="text-2xl md:text-3xl font-black text-white"><?php echo $total_rafts_count; ?> <span class="text-sm font-normal text-slate-400">ลำ</span></p>
                        <p class="text-[11px] text-slate-400 mt-1">พร้อมเปิดให้บริการล่องแพ</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Header Title Banner สำหรับลูกค้าทั่วไป -->
            <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-sky-500 rounded-3xl p-6 md:p-8 text-white shadow-xl mb-8 relative overflow-hidden">
                <div class="relative z-10 max-w-3xl">
                    <div class="inline-flex items-center gap-2 px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-xs font-bold mb-3 border border-white/30">
                        <i class="fa fa-sparkles text-yellow-300"></i> ตรวจเช็คคิวแพแบบเรียลไทม์
                    </div>
                    <h1 class="text-2xl md:text-4xl font-extrabold mb-2 leading-tight">📅 ปฏิทินตารางการจองแพ</h1>
                    <p class="text-blue-100 text-xs md:text-sm leading-relaxed">
                        ตรวจสอบวันที่แพว่างหรือถูกจองแล้วได้ทันทีแบบโปร่งใส สามารถกดดูรายละเอียดรายการจองหรือกดเลือกวันเพื่อจองแพที่ต้องการได้เลย
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter Controls & Legend Bar -->
        <div class="bg-white rounded-2xl p-4 md:p-6 shadow-sm border border-slate-200/80 mb-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                
                <!-- Filters -->
                <div class="flex flex-wrap items-center gap-3">
                    <div class="w-full sm:w-auto">
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">
                            <i class="fa fa-filter text-blue-500 mr-1"></i> กรองตามแพ
                        </label>
                        <select id="raftFilter" onchange="refreshCalendar()" class="w-full sm:w-56 bg-slate-50 border border-slate-300 font-bold text-gray-700 text-sm rounded-xl p-2.5 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                            <option value="0">⛵ แพทั้งหมด (ทุกลำ)</option>
                            <?php foreach ($rafts as $r): ?>
                                <option value="<?php echo $r['raft_id']; ?>"><?php echo htmlspecialchars($r['raft_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="w-full sm:w-auto">
                        <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">
                            <i class="fa fa-tasks text-blue-500 mr-1"></i> กรองสถานะ
                        </label>
                        <select id="statusFilter" onchange="refreshCalendar()" class="w-full sm:w-48 bg-slate-50 border border-slate-300 font-bold text-gray-700 text-sm rounded-xl p-2.5 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                            <option value="">ทั้งหมด</option>
                            <option value="confirmed" selected>🟢 ยืนยันแล้ว (ติดจอง)</option>
                            <option value="pending">🟡 รอตรวจสอบ</option>
                            <option value="completed">🔵 บริการเสร็จสิ้น</option>
                            <option value="cancelled">🔴 ยกเลิก</option>
                        </select>
                    </div>

                    <div class="w-full sm:w-auto self-end">
                        <button onclick="resetFilters()" class="w-full sm:w-auto px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition border border-slate-300/80 flex items-center justify-center gap-1.5">
                            <i class="fa fa-rotate-left"></i> ล้างการกรอง
                        </button>
                    </div>
                </div>

                <!-- Status Legend -->
                <div class="flex flex-wrap items-center gap-3 pt-3 lg:pt-0 border-t lg:border-t-0 border-slate-100 text-xs font-bold">
                    <span class="text-gray-400 text-[11px] uppercase tracking-wider mr-1">สัญลักษณ์:</span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> ยืนยันแล้ว (ติดจอง)
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> รอการตรวจสอบ
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-blue-50 text-blue-700 border border-blue-200 rounded-full">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span> บริการเสร็จสิ้น
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

        <!-- Quick Callout Banner -->
        <div class="mt-8 bg-gradient-to-r from-slate-900 to-blue-950 text-white rounded-2xl p-6 md:p-8 flex flex-col md:flex-row items-center justify-between gap-6 shadow-lg border border-slate-800">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 bg-blue-600/30 border border-blue-500/40 rounded-2xl flex items-center justify-center text-blue-400 text-2xl shrink-0">
                    <i class="fa fa-ship"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold">ต้องการจองแพเพิ่ม หรือสอบถามรายละเอียด?</h3>
                    <p class="text-slate-400 text-xs md:text-sm">สามารถเลือกแพที่ชอบและทำรายการจองผ่านหน้าเว็บไซต์ได้ตลอด 24 ชั่วโมง</p>
                </div>
            </div>
            <a href="index.php#rafts" class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-3 rounded-xl font-bold text-sm transition shadow-lg shadow-blue-600/30 whitespace-nowrap btn-animate">
                <i class="fa fa-plus-circle mr-1.5"></i> เลือกจองแพทันที
            </a>
        </div>

    </main>

    <!-- Modal for Booking Details -->
    <div id="bookingModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 transition-all">
        <div class="bg-white rounded-3xl max-w-lg w-full overflow-hidden shadow-2xl border border-slate-100 transform transition-all">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-6 text-white relative">
                <button onclick="closeModal('bookingModal')" class="absolute top-4 right-4 text-white/80 hover:text-white bg-white/10 hover:bg-white/20 w-8 h-8 rounded-full flex items-center justify-center transition">
                    <i class="fa fa-times"></i>
                </button>
                <div class="flex items-center gap-2 text-xs font-bold text-blue-200 uppercase tracking-widest mb-1">
                    <i class="fa fa-info-circle"></i> รายละเอียดการจอง
                </div>
                <h3 id="modalRaftName" class="text-xl md:text-2xl font-black leading-tight"></h3>
                <span id="modalBookingId" class="inline-block mt-2 bg-white/20 backdrop-blur-md px-3 py-1 rounded-full text-xs font-mono font-bold"></span>
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
                    <?php if ($is_admin): ?>
                        <div class="col-span-2 pt-1 border-t border-slate-200/60">
                            <span class="block text-[11px] font-bold text-gray-400 uppercase">อีเมลลูกค้า</span>
                            <span id="modalGuestEmail" class="font-bold text-gray-700 text-xs"></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="space-y-2 bg-blue-50/60 p-4 rounded-2xl border border-blue-100">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-blue-700 font-bold flex items-center gap-1.5"><i class="fa fa-calendar-check text-blue-500"></i> เวลาเช็คอิน:</span>
                        <span id="modalCheckIn" class="font-extrabold text-gray-800"></span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-blue-700 font-bold flex items-center gap-1.5"><i class="fa fa-calendar-minus text-blue-500"></i> เวลาเช็คเอาท์:</span>
                        <span id="modalCheckOut" class="font-extrabold text-gray-800"></span>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <span class="text-gray-500 font-bold text-xs">ยอดรวมทั้งสิ้น:</span>
                    <span class="text-xl font-black text-emerald-600">฿<span id="modalTotalPrice"></span></span>
                </div>
            </div>

            <div class="bg-slate-50 p-4 px-6 border-t border-slate-100 flex justify-end gap-3">
                <button onclick="closeModal('bookingModal')" class="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-gray-700 font-bold text-xs rounded-xl transition">
                    ปิดหน้าต่าง
                </button>
                <?php if ($is_admin): ?>
                    <a id="modalAdminActionBtn" href="admin_dashboard.php" class="px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs rounded-xl transition shadow-md flex items-center gap-1.5">
                        <i class="fa fa-tasks"></i> จัดการในระบบหลังบ้าน
                    </a>
                <?php else: ?>
                    <a id="modalActionBtn" href="index.php#rafts" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl transition shadow-md flex items-center gap-1.5">
                        <i class="fa fa-plus-circle"></i> จองแพนี้ในวันอื่น
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal for Date Click -->
    <div id="dateModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4 transition-all">
        <div class="bg-white rounded-3xl max-w-lg w-full overflow-hidden shadow-2xl border border-slate-100">
            <div class="bg-slate-900 p-5 text-white relative">
                <button onclick="closeModal('dateModal')" class="absolute top-4 right-4 text-gray-400 hover:text-white bg-slate-800 w-8 h-8 rounded-full flex items-center justify-center transition">
                    <i class="fa fa-times"></i>
                </button>
                <div class="text-xs font-bold text-blue-400 uppercase tracking-widest">
                    <i class="fa fa-calendar-day mr-1"></i> รายการจองประจำวัน
                </div>
                <h3 id="dateModalTitle" class="text-xl font-black mt-1"></h3>
            </div>

            <div class="p-6 max-h-[60vh] overflow-y-auto">
                <div id="dateBookingsList" class="space-y-3"></div>
            </div>

            <div class="bg-slate-50 p-4 px-6 border-t border-slate-100 flex justify-between items-center">
                <button onclick="closeModal('dateModal')" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-gray-700 font-bold text-xs rounded-xl transition">
                    ปิด
                </button>
                <a id="dateBookActionBtn" href="index.php#rafts" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl transition shadow-md shadow-emerald-200 flex items-center gap-1.5">
                    <i class="fa fa-calendar-plus"></i> จองแพประจำวันนี้
                </a>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 py-8 border-t border-slate-800 mt-12 text-center text-xs">
        <div class="container mx-auto px-4">
            <p class="font-medium text-slate-300">ล่องแพหนองกวาก</p>
            <p class="mt-1 text-slate-500">© <?php echo date('Y'); ?> ChillRaft. All rights reserv</p>
        </div>
    </footer>

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
                buttonText: {
                    today: 'วันนี้',
                    month: 'เดือน',
                    week: 'สัปดาห์',
                    list: 'รายการ'
                },
                events: function(fetchInfo, successCallback, failureCallback) {
                    showSpinner();
                    const raftId = document.getElementById('raftFilter').value;
                    const status = document.getElementById('statusFilter').value;

                    let url = `booking_calendar.php?api=1&raft_id=${raftId}&status=${status}`;

                    fetch(url)
                        .then(res => res.json())
                        .then(data => {
                            hideSpinner();
                            successCallback(data);
                        })
                        .catch(err => {
                            hideSpinner();
                            console.error("Error fetching calendar data:", err);
                            failureCallback(err);
                        });
                },
                eventClick: function(info) {
                    const props = info.event.extendedProps;
                    
                    document.getElementById('modalRaftName').textContent = props.raft_name;
                    document.getElementById('modalBookingId').textContent = props.booking_code;
                    document.getElementById('modalGuestName').textContent = props.guest_name;
                    document.getElementById('modalGuestTel').textContent = props.guest_tel;
                    
                    const emailElem = document.getElementById('modalGuestEmail');
                    if (emailElem) {
                        emailElem.textContent = props.guest_email || '-';
                    }

                    document.getElementById('modalCheckIn').textContent = props.check_in_formatted;
                    document.getElementById('modalCheckOut').textContent = props.check_out_formatted;
                    document.getElementById('modalTotalPrice').textContent = props.total_price;

                    const statusBadge = document.getElementById('modalStatusBadge');
                    statusBadge.textContent = props.status_th;
                    statusBadge.className = 'px-3 py-1 rounded-full text-xs font-bold ';

                    if (props.status === 'confirmed') {
                        statusBadge.className += 'bg-emerald-100 text-emerald-800 border border-emerald-300';
                    } else if (props.status === 'completed') {
                        statusBadge.className += 'bg-blue-100 text-blue-800 border border-blue-300';
                    } else if (props.status === 'pending') {
                        statusBadge.className += 'bg-amber-100 text-amber-800 border border-amber-300';
                    } else {
                        statusBadge.className += 'bg-red-100 text-red-800 border border-red-300';
                    }

                    const actionBtn = document.getElementById('modalActionBtn');
                    if (actionBtn) {
                        actionBtn.href = `index.php?checkin=${props.check_in_raw}#rafts`;
                    }

                    openModal('bookingModal');
                },
                dateClick: function(info) {
                    const clickedDate = info.dateStr;
                    const dateObj = new Date(clickedDate);
                    const formattedDate = dateObj.toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' });

                    document.getElementById('dateModalTitle').textContent = formattedDate;
                    document.getElementById('dateBookActionBtn').href = `index.php?checkin=${clickedDate}#rafts`;

                    const allEvents = calendar.getEvents();
                    const dayEvents = allEvents.filter(ev => {
                        const start = ev.startStr.substr(0, 10);
                        const end = ev.endStr ? ev.endStr.substr(0, 10) : start;
                        return clickedDate >= start && clickedDate <= end;
                    });

                    const listContainer = document.getElementById('dateBookingsList');
                    listContainer.innerHTML = '';

                    if (dayEvents.length === 0) {
                        listContainer.innerHTML = `
                            <div class="text-center py-8 bg-emerald-50 rounded-2xl border border-emerald-100">
                                <div class="text-4xl mb-2">⛵</div>
                                <h4 class="font-bold text-emerald-800 text-base">ยังไม่มีการจองในวันนี้</h4>
                                <p class="text-xs text-emerald-600 mt-1">แพทุกลำว่าง พร้อมให้บริการในวันที่เลือก</p>
                            </div>
                        `;
                    } else {
                        dayEvents.forEach(ev => {
                            const p = ev.extendedProps;
                            const card = document.createElement('div');
                            card.className = 'p-3.5 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between hover:bg-blue-50/50 transition';
                            card.innerHTML = `
                                <div>
                                    <div class="font-bold text-slate-800 text-sm">⛵ ${p.raft_name}</div>
                                    <div class="text-xs text-slate-500 mt-0.5">👤 ผู้จอง: ${p.guest_name} | 💰 ฿${p.total_price} | 🕒 ${p.check_in_formatted}</div>
                                </div>
                                <span class="text-xs font-bold px-2.5 py-1 rounded-full ${p.status === 'confirmed' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}">
                                    ${p.status_th}
                                </span>
                            `;
                            listContainer.appendChild(card);
                        });
                    }

                    openModal('dateModal');
                }
            });

            calendar.render();
        });

        function refreshCalendar() {
            if (calendar) {
                calendar.refetchEvents();
            }
        }

        function resetFilters() {
            document.getElementById('raftFilter').value = '0';
            document.getElementById('statusFilter').value = '';
            refreshCalendar();
        }

        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        }

        function showSpinner() {
            const spinner = document.getElementById('loadingSpinner');
            if (spinner) spinner.classList.add('hidden');
        }

        function hideSpinner() {
            const spinner = document.getElementById('loadingSpinner');
            if (spinner) spinner.classList.add('hidden');
        }
    </script>
</body>
</html>
