<?php
session_start();
require_once __DIR__ . '/db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 2. ดึงโครงสร้างคอลัมน์ของตาราง bookings (PostgreSQL Syntax)
$b_cols = [];
if ($conn) {
    $chk_cols = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
    if ($chk_cols) {
        while ($col = pg_fetch_assoc($chk_cols)) {
            $b_cols[] = strtolower($col['column_name']);
        }
    }
}

// เลือกว่าใช้คอลัมน์ราคาใด
$price_field = in_array('total_amount',$b_cols) ? 'b.total_amount' : (in_array('total_price', $b_cols) ? 'b.total_price' : (in_array('raft_price',$b_cols) ? 'b.raft_price' : '0'));

// ตรวจสอบว่ามีคอลัมน์ชื่อ/เบอร์ใน bookings หรือไม่
$has_guest_name  = in_array('guest_name',$b_cols);
$has_guest_tel   = in_array('guest_tel',$b_cols);
$has_guest_email = in_array('guest_email',$b_cols);

$phone_expr =$has_guest_tel ? "COALESCE(NULLIF(TRIM(b.guest_tel), ''), c.phone, '-')" : "COALESCE(c.phone, '-')";
$name_expr  =$has_guest_name ? "COALESCE(NULLIF(TRIM(b.guest_name), ''), c.full_name, 'ลูกค้าทั่วไป')" : "COALESCE(c.full_name, 'ลูกค้าทั่วไป')";
$email_expr =$has_guest_email ? "COALESCE(NULLIF(TRIM(b.guest_email), ''), c.email, '')" : "COALESCE(c.email, '')";

// 3. คำสั่ง SQL อิงจากตาราง bookings (รองรับ PostgreSQL GROUP BY)
$customers = [];
if ($conn) {$sql = "SELECT 
                $phone_expr AS client_phone,$name_expr AS client_name,
                MAX($email_expr) AS client_email,
                MAX(COALESCE(c.line_id, '')) AS client_line,
                COUNT(b.id) AS total_bookings,
                COALESCE(SUM($price_field), 0) AS total_spent,
                MAX(b.id) AS latest_booking_id
            FROM bookings b
            LEFT JOIN customers c ON b.customer_id = c.id
            GROUP BY $phone_expr,$name_expr
            ORDER BY latest_booking_id DESC";

    $customers_res = @pg_query($conn,$sql);
    if ($customers_res) {
        while ($c = pg_fetch_assoc($customers_res)) {
            $client_name  =$c['client_name'];
            $client_phone =$c['client_phone'];

            // ดึงประวัติการจองทั้งหมดของลูกค้ารายนี้
            $b_conds = [];
            $b_params = [];$p_idx = 1;

            if ($client_phone !== '-') {
                if ($has_guest_tel) { $b_conds[] = "b.guest_tel = $" . $p_idx; }$b_conds[] = "cust.phone = $" . $p_idx;
                $b_params[] =$client_phone;
                $p_idx++;
            }

            if (!empty($client_name) &&$client_name !== 'ลูกค้าทั่วไป') {
                if ($has_guest_name) { $b_conds[] = "b.guest_name = $" . $p_idx; }$b_conds[] = "cust.full_name = $" . $p_idx;
                $b_params[] =$client_name;
                $p_idx++;
            }

            $where_clause = !empty($b_conds) ? implode(" OR ", $b_conds) : "1=1";
            $b_sql = "SELECT b.*, r.name as raft_name 
                      FROM bookings b 
                      LEFT JOIN rafts r ON b.raft_id = r.id 
                      LEFT JOIN customers cust ON b.customer_id = cust.id
                      WHERE ($where_clause)
                      ORDER BY b.id DESC";

            $b_query = @pg_query_params($conn,$b_sql, $b_params);$booking_history = [];
            if ($b_query) {
                while ($b_row = pg_fetch_assoc($b_query)) {
                    $booking_history[] =$b_row;
                }
            }

            $c['booking_history'] =$booking_history;
            $c['latest_booking']  = !empty($booking_history) ?$booking_history[0] : null;
            $customers[] =$c;
        }
    }
}

// ฟังก์ชันแปลงวันที่ภาษาไทย
function thai_date_short($date_str) {
    if (!$date_str) return '-';$timestamp = strtotime($date_str);$thai_months = array(
        1 => "ม.ค.", 2 => "ก.พ.", 3 => "มี.ค.", 4 => "เม.ย.", 5 => "พ.ค.", 6 => "มิ.ย.",
        7 => "ก.ค.", 8 => "ส.ค.", 9 => "ก.ย.", 10 => "ต.ค.", 11 => "พ.ย.", 12 => "ธ.ค."
    );
    return date('j', $timestamp) . ' ' .$thai_months[(int)date('n', $timestamp)] . ' ' . (date('Y',$timestamp) + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลลูกค้า - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .sidebar-active { transform: translateX(0) !important; }
    </style>
</head>
<body class="bg-gray-100 flex min-h-screen">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- เรียกใช้ Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow p-4 md:p-8 min-w-0">
        <header class="flex justify-between items-center mb-8 bg-white p-6 rounded-3xl shadow-sm border border-slate-100">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="md:hidden p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <div>
                    <h2 class="text-xl md:text-2xl font-black text-slate-800">รายชื่อลูกค้า & ประวัติการจองแพ</h2>
                    <p class="text-gray-500 text-xs mt-1">รวบรวมข้อมูลผู้จองจากตารางรายการจองทั้งหมดโดยอัตโนมัติ</p>
                </div>
            </div>
            <a href="manage_bookings.php" class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-4 py-2.5 rounded-xl shadow-md text-sm flex items-center gap-2">
                <i class="fa fa-calendar-check"></i> ดูรายการจองทั้งหมด
            </a>
        </header>

        <!-- ตารางข้อมูลลูกค้า -->
        <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left font-sans">
                    <thead class="bg-slate-50 text-slate-400 text-xs uppercase font-black tracking-wider border-b border-slate-100">
                        <tr>
                            <th class="p-5">ชื่อผู้จอง / ลูกค้า</th>
                            <th class="p-5">เบอร์ติดต่อ</th>
                            <th class="p-5">แพและวันที่จองล่าสุด</th>
                            <th class="p-5 text-center">จองสะสม</th>
                            <th class="p-5 text-right">ยอดเงินสะสม</th>
                            <th class="p-5 text-center">ประวัติการจอง</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                        <?php if (!empty($customers)): foreach ($customers as$c): 
                            $client_name     =$c['client_name'];
                            $client_phone    =$c['client_phone'];
                            $booking_history =$c['booking_history'];
                            $latest_booking  =$c['latest_booking'];
                        ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="p-5">
                                <div class="font-bold text-slate-800 text-base flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs shrink-0">
                                        <i class="fa fa-user"></i>
                                    </div>
                                    <span><?php echo htmlspecialchars($client_name); ?></span>
                                </div>
                                <?php if(!empty($c['client_email'])): ?>
                                    <div class="text-xs text-slate-400 mt-1 pl-10"><?php echo htmlspecialchars($c['client_email']); ?></div>
                                <?php endif; ?>
                            </td>

                            <td class="p-5">
                                <div class="font-mono font-bold text-slate-700 bg-slate-100 px-3 py-1.5 rounded-xl inline-flex items-center gap-2">
                                    <i class="fa fa-phone text-blue-500 text-xs"></i> <?php echo htmlspecialchars($client_phone); ?>
                                </div>
                                <?php if(!empty($c['client_line'])): ?>
                                    <div class="text-xs text-emerald-600 font-bold mt-1.5"><i class="fab fa-line"></i> <?php echo htmlspecialchars($c['client_line']); ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- คอลัมน์แสดงแพล่าสุดที่จอง -->
                            <td class="p-5">
                                <?php if($latest_booking): ?>
                                    <div class="bg-blue-50/70 p-3 rounded-2xl border border-blue-100 text-xs space-y-1">
                                        <div class="font-extrabold text-blue-900 flex items-center gap-1.5">
                                            <i class="fa fa-ship text-blue-500"></i> <?php echo htmlspecialchars($latest_booking['raft_name'] ?? 'แพ #'.$latest_booking['raft_id']); ?>
                                        </div>
                                        <div class="text-slate-600 font-medium">
                                            📅 <?php echo thai_date_short($latest_booking['check_in_date'] ?? $latest_booking['check_in'] ?? ''); ?> 
                                            <span class="text-slate-400">(<?php echo date('H:i', strtotime($latest_booking['check_in_time'] ?? '09:00')); ?> น.)</span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-slate-300 text-xs italic font-medium">ยังไม่มีรายการจอง</span>
                                <?php endif; ?>
                            </td>

                            <td class="p-5 text-center">
                                <span class="bg-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-black">
                                    <?php echo count($booking_history); ?> ครั้ง
                                </span>
                            </td>

                            <td class="p-5 text-right font-black text-emerald-600 text-base">
                                ฿<?php echo number_format($c['total_spent']); ?>
                            </td>

                            <!-- ปุ่มเปิดดูประวัติการจองทั้งหมดแบบละเอียด -->
                            <td class="p-5 text-center">
                                <?php if(count($booking_history) > 0): ?>
                                    <button type="button" 
                                            onclick='openBookingHistory(<?php echo json_encode($client_name); ?>, <?php echo json_encode($client_phone); ?>, <?php echo json_encode($booking_history); ?>)'
                                            class="bg-slate-800 hover:bg-blue-600 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 mx-auto shadow-sm">
                                        <i class="fa fa-history"></i> ดูประวัติ (<?php echo count($booking_history); ?>)
                                    </button>
                                <?php else: ?>
                                    <span class="text-slate-300 text-xs font-bold">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="p-16 text-center text-slate-300 font-bold uppercase tracking-widest">ยังไม่มีข้อมูลการจองในระบบ</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal แสดงประวัติการจองทั้งหมด -->
    <div id="historyModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-2xl w-full max-h-[85vh] flex flex-col overflow-hidden shadow-2xl border border-gray-100 font-sans">
            <div class="bg-slate-900 p-6 text-white flex justify-between items-center shrink-0">
                <div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-blue-400">Booking History</span>
                    <h3 class="text-xl font-black mt-0.5 flex items-center gap-2">
                        <i class="fa fa-receipt text-blue-400"></i> ประวัติการจองของ <span id="histCustomerName" class="text-blue-300"></span>
                    </h3>
                    <div id="histCustomerPhone" class="text-xs text-slate-400 font-mono mt-0.5"></div>
                </div>
                <button type="button" onclick="closeHistoryModal()" class="w-8 h-8 rounded-full bg-slate-800 text-gray-400 hover:text-white flex items-center justify-center text-lg">&times;</button>
            </div>

            <div class="p-6 overflow-y-auto flex-grow space-y-4 bg-slate-50" id="histBookingList"></div>

            <div class="p-4 bg-white border-t border-slate-100 flex justify-end shrink-0">
                <button type="button" onclick="closeHistoryModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition">
                    ปิดหน้าต่าง
                </button>
            </div>
        </div>
    </div>

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

        function openBookingHistory(customerName, customerPhone, bookings) {
            document.getElementById('histCustomerName').textContent = customerName;
            document.getElementById('histCustomerPhone').textContent = '📞 เบอร์ติดต่อ: ' + customerPhone;
            const container = document.getElementById('histBookingList');
            container.innerHTML = '';

            if (!bookings || bookings.length === 0) {
                container.innerHTML = '<div class="p-8 text-center text-slate-400 font-bold text-xs">ไม่มีข้อมูลการจอง</div>';
            } else {
                bookings.forEach(b => {
                    const statusClass = (b.status_id == 2 || b.status === 'confirmed') ? 'bg-emerald-100 text-emerald-700' :
                                        ((b.status_id == 3 || b.status === 'cancelled') ? 'bg-rose-100 text-rose-700' : 
                                        ((b.status_id == 4 || b.status === 'completed') ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700'));
                    
                    const statusLabel = (b.status_id == 2 || b.status === 'confirmed') ? 'ยืนยันแล้ว' :
                                        ((b.status_id == 3 || b.status === 'cancelled') ? 'ยกเลิก' : 
                                        ((b.status_id == 4 || b.status === 'completed') ? 'เสร็จสิ้น' : 'รอตรวจสอบ'));

                    const bookingCode = b.booking_code || ('#' + b.id);
                    const raftName = b.raft_name || ('แพ #' + b.raft_id);
                    const price = Number(b.total_amount || b.total_price || b.raft_price || 0).toLocaleString();

                    const checkInDate = b.check_in_date || (b.check_in ? b.check_in.substring(0, 10) : '-');
                    const checkInTime = b.check_in_time ? b.check_in_time.substring(0, 5) : '09:00';
                    const checkOutTime = b.check_out_time ? b.check_out_time.substring(0, 5) : '17:30';

                    const div = document.createElement('div');
                    div.className = "bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3";
                    div.innerHTML = `
                        <div class="space-y-1 text-left">
                            <div class="flex items-center gap-2">
                                <span class="font-black text-slate-800 text-sm">⛵ ${raftName}</span>
                                <span class="text-[10px] font-mono font-bold text-slate-400">(${bookingCode})</span>
                            </div>
                            <div class="text-xs text-slate-500">
                                <span>📅 วันที่เช็คอิน: <b>${checkInDate}</b></span>
                                <span class="ml-2">⏰ เวลา: <b>${checkInTime} - ${checkOutTime} น.</b></span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between sm:justify-end gap-3 border-t sm:border-t-0 pt-2 sm:pt-0">
                            <span class="text-sm font-black text-emerald-600">฿${price}</span>
                            <span class="${statusClass} px-3 py-1 rounded-full text-[10px] font-black">${statusLabel}</span>
                        </div>
                    `;
                    container.appendChild(div);
                });
            }

            document.getElementById('historyModal').classList.remove('hidden');
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').classList.add('hidden');
        }
    </script>
</body>
</html>
