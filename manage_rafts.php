<?php
session_start();
require_once __DIR__ . '/db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 2. ระบบอัปเดตสถานะการจอง (พร้อมปรับสถานะแพ และสถานะ payment อัตโนมัติ)
if (isset($_GET['id']) && isset($_GET['status']) && $conn) {
    $id = intval($_GET['id']);
    $status = trim($_GET['status']);
    
    $status_map = [
        'pending'   => 1,
        'confirmed' => 2,
        'cancelled' => 3,
        'completed' => 4
    ];

    if (array_key_exists($status, $status_map)) {
        $status_id = $status_map[$status];
        @pg_query($conn, "BEGIN");
        try {
            // ดึง raft_id ของการจองนี้
            $stmt_raft = @pg_query_params($conn, "SELECT raft_id FROM bookings WHERE id = $1 LIMIT 1", array($id));
            $raft_res = $stmt_raft ? pg_fetch_assoc($stmt_raft) : null;
            $raft_id = intval($raft_res['raft_id'] ?? 0);

            // ตรวจสอบคอลัมน์ใน bookings
            $b_cols = [];
            $chk_cols = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
            if ($chk_cols) {
                while ($c = pg_fetch_assoc($chk_cols)) {
                    $b_cols[] = strtolower($c['column_name']);
                }
            }

            if (in_array('status_id', $b_cols)) {
                @pg_query_params($conn, "UPDATE bookings SET status_id = $1 WHERE id = $2", array($status_id, $id));
            }
            if (in_array('status', $b_cols)) {
                @pg_query_params($conn, "UPDATE bookings SET status = $1 WHERE id = $2", array($status, $id));
            }

            // จัดการตาราง payments (ถ้ามี)
            $chk_pay = @pg_query($conn, "SELECT to_regclass('public.payments')");
            $has_pay = ($chk_pay && ($r_tbl = pg_fetch_row($chk_pay)) && !empty($r_tbl[0]));
            if ($has_pay) {
                $admin_id = intval($_SESSION['user_id'] ?? 1);
                if ($status === 'confirmed') {
                    @pg_query_params($conn, "UPDATE payments SET status = 'confirmed', verified_at = NOW(), verified_by = $1 WHERE booking_id = $2", array($admin_id, $id));
                } elseif ($status === 'cancelled') {
                    @pg_query_params($conn, "UPDATE payments SET status = 'cancelled' WHERE booking_id = $1", array($id));
                }
            }

            // ปรับสถานะแพในตาราง rafts
            if ($raft_id > 0) {
                if ($status === 'confirmed') {
                    @pg_query_params($conn, "UPDATE rafts SET status = 'busy' WHERE id = $1", array($raft_id));
                } elseif ($status === 'cancelled' || $status === 'completed') {
                    @pg_query_params($conn, "UPDATE rafts SET status = 'available' WHERE id = $1", array($raft_id));
                }
            }

            @pg_query($conn, "COMMIT");

            // ตรวจสอบว่าเป็นการส่งแบบ AJAX หรือไม่
            if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
                echo json_encode(['status' => 'success']);
                exit();
            }

            header("Location: manage_bookings.php?msg=updated");
            exit();
        } catch (Exception $e) {
            @pg_query($conn, "ROLLBACK");
            
            if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
                echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
                exit();
            }
            
            echo "Error: " . $e->getMessage();
        }
    }
}

// 3. ระบบลบข้อมูลการจอง
if (isset($_GET['delete_id']) && $conn) {
    $delete_id = intval($_GET['delete_id']);
    
    $chk_pay = @pg_query($conn, "SELECT to_regclass('public.payments')");
    $has_pay = ($chk_pay && ($r_tbl = pg_fetch_row($chk_pay)) && !empty($r_tbl[0]));
    if ($has_pay) {
        $res_pay = @pg_query_params($conn, "SELECT slip_image FROM payments WHERE booking_id = $1", array($delete_id));
        if ($res_pay) {
            while ($p_data = pg_fetch_assoc($res_pay)) {
                if (!empty($p_data['slip_image'])) {
                    $file_path = "uploads/slips/" . $p_data['slip_image'];
                    if (file_exists($file_path)) { @unlink($file_path); }
                }
            }
        }
        @pg_query_params($conn, "DELETE FROM payments WHERE booking_id = $1", array($delete_id));
    }

    @pg_query_params($conn, "DELETE FROM bookings WHERE id = $1", array($delete_id));
    
    header("Location: manage_bookings.php?msg=deleted");
    exit();
}

// 4. รับค่าคำค้นหา (ปรับปรุงใหม่ รองรับการค้นหาหลายคำ / ค้นหาใกล้เคียงแบบแยกคำ)
$search_query = "";
$search_param = "";
$search_params = [];
if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $search_param = trim($_GET['search']);
    $words = preg_split('/\s+/', $search_param);
    $keyword_clauses = [];
    $p_idx = 1;

    foreach ($words as $word) {
        if ($word === '') continue;
        $keyword_clauses[] = "(b.booking_code ILIKE $" . $p_idx . " 
                              OR c.full_name ILIKE $" . $p_idx . " 
                              OR b.guest_name ILIKE $" . $p_idx . " 
                              OR c.phone ILIKE $" . $p_idx . " 
                              OR b.guest_tel ILIKE $" . $p_idx . ")";
        $search_params[] = '%' . $word . '%';
        $p_idx++;
    }

    if (!empty($keyword_clauses)) {
        $search_query = " WHERE " . implode(" AND ", $keyword_clauses) . " ";
    }
}

// 5. ระบบแบ่งหน้า (Pagination) หน้าละ 10 รายการ
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// นับจำนวนข้อมูลทั้งหมด
$total_rows = 0;
if ($conn) {
    $count_sql = "SELECT COUNT(b.id) as total_rows 
                  FROM bookings b 
                  LEFT JOIN customers c ON b.customer_id = c.id 
                  $search_query";
    $count_res = !empty($search_params) ? @pg_query_params($conn, $count_sql, $search_params) : @pg_query($conn, $count_sql);
    if ($count_res && $row = pg_fetch_assoc($count_res)) {
        $total_rows = intval($row['total_rows']);
    }
}
$total_pages = ceil($total_rows / $limit);

// 6. ดึงข้อมูลการจองเก็บใส่ Array
$bookings = [];
if ($conn) {
    $sql = "SELECT b.*, 
                   c.full_name AS customer_name, 
                   c.phone AS customer_phone,
                   r.name AS raft_name,
                   r.featured_image,
                   p.slip_image
            FROM bookings b
            LEFT JOIN customers c ON b.customer_id = c.id
            LEFT JOIN rafts r ON b.raft_id = r.id
            LEFT JOIN payments p ON b.id = p.booking_id
            $search_query
            ORDER BY b.id DESC 
            LIMIT $limit OFFSET $offset"; 
            
    $result = !empty($search_params) ? @pg_query_params($conn, $sql, $search_params) : @pg_query($conn, $sql);
    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $bookings[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการการจอง - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .sidebar-active { transform: translateX(0) !important; }
        @media (max-width: 768px) {
            .table-container { display: none; }
            .card-container { display: grid; }
        }
        @media (min-width: 769px) {
            .table-container { display: block; }
            .card-container { display: none; }
        }
    </style>
</head>
<body class="bg-slate-50 flex min-h-screen">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>

    <!-- เรียกใช้ Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 lg:px-10 sticky top-0 z-30">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="lg:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl lg:text-3xl font-black text-slate-800">รายการจองแพ</h1>
            </div>
            
            <?php if(isset($_GET['msg'])): ?>
                <div id="toast" class="bg-emerald-500 text-white px-4 lg:px-6 py-2 rounded-full shadow-lg text-xs lg:text-sm font-bold flex items-center gap-2">
                    <i class="fa fa-check-circle"></i> บันทึกสำเร็จ!
                </div>
                <script>setTimeout(() => { const t = document.getElementById('toast'); if(t) t.remove(); }, 3000);</script>
            <?php endif; ?>
        </header>

        <div class="p-4 lg:p-10 flex-grow">
            
            <!-- ส่วนของช่องค้นหา + ปุ่มเพิ่มข้อมูลการจอง -->
            <div class="mb-6 flex flex-col lg:flex-row justify-between items-stretch lg:items-center gap-4">

                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full lg:w-auto">
                    <!-- Search Box -->
                    <form method="GET" action="manage_bookings.php" class="w-full md:w-96 relative flex items-center">
                        <i class="fa fa-search absolute left-4 text-slate-400"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_param); ?>"
                               placeholder="ค้นหารหัสจอง, ชื่อลูกค้า, เบอร์โทร..."
                               class="w-full pl-10 pr-10 py-3 rounded-2xl border border-slate-200 bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none text-sm transition shadow-sm font-bold text-slate-700">

                        <?php if (!empty($search_param)): ?>
                            <a href="manage_bookings.php" class="absolute right-3 text-slate-400 hover:text-rose-500 transition" title="ล้างการค้นหา">
                                <i class="fa fa-times-circle"></i>
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="hidden">ค้นหา</button>
                    </form>

                    <!-- ปุ่มเพิ่มข้อมูลการจอง -->
                    <a href="add_booking.php" class="inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white px-5 py-3 rounded-2xl font-bold text-sm shadow-md hover:shadow-lg transition-all duration-200 whitespace-nowrap">
                        <i class="fa fa-plus text-sm"></i> เพิ่มข้อมูลการจอง
                    </a>
                </div>

                <div class="text-sm font-bold text-slate-600">
                    <?php if (!empty($search_param)): ?>
                        ผลการค้นหา: <span class="text-blue-600">"<?php echo htmlspecialchars($search_param); ?>"</span> 
                    <?php endif; ?>
                    พบข้อมูลทั้งหมด <?php echo $total_rows; ?> รายการ 
                    <span class="text-xs text-slate-400 font-normal">(หน้า <?php echo $page; ?>/<?php echo max(1, $total_pages); ?>)</span>
                </div>
            </div>

            <!-- Table View (Desktop) -->
            <div class="table-container bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden mb-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-left font-sans">
                        <thead class="bg-slate-50 text-slate-400 text-xs uppercase font-black tracking-widest border-b border-slate-100">
                            <tr>
                                <th class="p-6 text-left">วัน-เวลาเข้าพัก</th>
                                <th class="p-6 text-left">ข้อมูลลูกค้า / แพ</th>
                                <th class="p-6 text-left">ยอดชำระ</th>
                                <th class="p-6 text-center">สลิป</th>
                                <th class="p-6 text-center">สถานะ</th>
                                <th class="p-6 text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php 
                            if (!empty($bookings)): 
                                foreach($bookings as $row): 
                                    $booking_id = $row['id'];
                                    $booker = !empty($row['customer_name']) ? $row['customer_name'] : ($row['guest_name'] ?? 'ลูกค้าทั่วไป');
                                    $booker_phone = !empty($row['customer_phone']) ? $row['customer_phone'] : ($row['guest_tel'] ?? '-');
                                    $b_code = !empty($row['booking_code']) ? $row['booking_code'] : ('BK' . str_pad($booking_id, 6, '0', STR_PAD_LEFT));
                                    $raft_title = $row['raft_name'] ?? ('แพ #' . $row['raft_id']);
                                    $total_amount = floatval($row['total_amount'] ?? $row['raft_price'] ?? $row['total_price'] ?? 0);
                                    $slip_file = $row['slip_image'] ?? '';

                                    $raw_status_id = intval($row['status_id'] ?? 1);
                                    if ($raw_status_id == 2 || ($row['status'] ?? '') === 'confirmed') {
                                        $status_key = 'confirmed';
                                        $label = 'ยืนยันแล้ว';
                                        $badge_class = 'bg-emerald-100 text-emerald-700';
                                    } elseif ($raw_status_id == 3 || ($row['status'] ?? '') === 'cancelled') {
                                        $status_key = 'cancelled';
                                        $label = 'ยกเลิก';
                                        $badge_class = 'bg-rose-100 text-rose-700';
                                    } elseif ($raw_status_id == 4 || ($row['status'] ?? '') === 'completed') {
                                        $status_key = 'completed';
                                        $label = 'เสร็จสิ้น';
                                        $badge_class = 'bg-blue-100 text-blue-700';
                                    } else {
                                        $status_key = 'pending';
                                        $label = 'รอตรวจสอบ';
                                        $badge_class = 'bg-amber-100 text-amber-700';
                                    }

                                    $in_date = $row['check_in_date'] ?? date('Y-m-d');
                                    $in_time = !empty($row['check_in_time']) ? date('H:i', strtotime($row['check_in_time'])) : '09:00';
                                    $out_date = $row['check_out_date'] ?? $in_date;
                                    $out_time = !empty($row['check_out_time']) ? date('H:i', strtotime($row['check_out_time'])) : '17:30';
                            ?>
                            <tr class="hover:bg-blue-50/20 transition duration-150">
                                <td class="p-6 text-left">
                                    <div class="text-xs font-bold text-slate-700"><span class="text-blue-600">IN:</span> <?php echo date('d/m/Y', strtotime($in_date)); ?> (<?php echo $in_time; ?>)</div>
                                    <div class="text-xs font-bold text-slate-500 mt-1"><span class="text-rose-500">OUT:</span> <?php echo date('d/m/Y', strtotime($out_date)); ?> (<?php echo $out_time; ?>)</div>
                                </td>
                                <td class="p-6 text-left">
                                    <div class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars($booker); ?></div>
                                    <div class="text-slate-400 text-xs font-semibold"><?php echo htmlspecialchars($booker_phone); ?></div>
                                    <div class="text-blue-600 text-xs font-bold mt-1"><?php echo htmlspecialchars($raft_title); ?></div>
                                    <div class="text-[10px] font-mono text-slate-400">#<?php echo $b_code; ?></div>
                                </td>
                                <td class="p-6 text-left font-black text-slate-800">฿<?php echo number_format($total_amount); ?></td>
                                <td class="p-6 text-center">
                                    <?php if(!empty($slip_file)): ?>
                                         <button onclick="openSlipModal(this)" 
                                                 data-slip="uploads/slips/<?php echo htmlspecialchars($slip_file); ?>"
                                                 data-booking-id="<?php echo $booking_id; ?>"
                                                 data-booking-code="<?php echo htmlspecialchars($b_code); ?>"
                                                 data-guest-name="<?php echo htmlspecialchars($booker); ?>"
                                                 data-raft-name="<?php echo htmlspecialchars($raft_title); ?>"
                                                 data-total-price="<?php echo number_format($total_amount, 2); ?>"
                                                 class="bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white px-3.5 py-1.5 rounded-xl transition text-xs font-bold flex items-center justify-center gap-1.5 mx-auto shadow-sm">
                                            <i class="fa fa-image"></i> ตรวจสลิป
                                         </button>
                                    <?php else: ?>
                                         <span class="text-slate-300 text-[11px] font-bold">ยังไม่แนบ</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-6 text-center">
                                    <span class="<?php echo $badge_class; ?> px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-tighter">
                                        <?php echo $label; ?>
                                    </span>
                                </td>
                                <td class="p-6 text-center">
                                    <div class="flex justify-center items-center gap-1.5">
                                        <?php if($status_key === 'pending'): ?>
                                            <a href="?id=<?php echo $booking_id; ?>&status=confirmed" title="ยืนยันการจอง" class="bg-emerald-500 text-white px-3 py-1.5 rounded-xl text-xs font-bold hover:bg-emerald-600 shadow-md transition flex items-center gap-1"><i class="fa fa-check text-xs"></i> อนุมัติ</a>
                                        <?php elseif($status_key === 'confirmed'): ?>
                                            <button type="button" onclick="checkoutRaft(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars($raft_title); ?>')" title="ลูกค้านำแพมาคืน" class="bg-blue-600 text-white px-3.5 py-1.5 rounded-xl text-xs font-bold hover:bg-blue-700 shadow-md transition flex items-center gap-1.5">
                                                <i class="fa fa-undo text-xs"></i> คืนแพ / เสร็จสิ้น
                                            </button>
                                        <?php endif; ?>

                                        <?php if($status_key !== 'cancelled' && $status_key !== 'completed'): ?>
                                            <a href="?id=<?php echo $booking_id; ?>&status=cancelled" onclick="return confirm('ยืนยันยกเลิกการจองนี้?')" title="ยกเลิกการจอง" class="bg-rose-500 text-white w-8 h-8 rounded-lg flex items-center justify-center hover:bg-rose-600 shadow-md transition"><i class="fa fa-times text-xs"></i></a>
                                        <?php endif; ?>

                                        <a href="edit_booking.php?id=<?php echo $booking_id; ?>" title="แก้ไขข้อมูลการจอง" class="bg-amber-100 text-amber-600 w-8 h-8 rounded-lg flex items-center justify-center hover:bg-amber-500 hover:text-white transition shadow-sm">
                                            <i class="fa fa-edit text-xs"></i>
                                        </a>

                                        <a href="?delete_id=<?php echo $booking_id; ?>" onclick="return confirm('⚠️ ยืนยันการลบรายการจองนี้ออกจากระบบอย่างถาวร?')" title="ลบข้อมูล" class="bg-slate-100 text-slate-400 w-8 h-8 rounded-lg flex items-center justify-center hover:bg-rose-50 hover:text-rose-600 transition"><i class="fa fa-trash-alt text-xs"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td colspan="6" class="p-20 text-center text-slate-400 font-bold uppercase tracking-widest">
                                    <?php echo !empty($search_param) ? 'ไม่พบรายการจองที่ค้นหา' : 'ยังไม่มีรายการจองในระบบ'; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Card View (Mobile) -->
            <div class="card-container grid grid-cols-1 gap-4 lg:hidden mb-6">
                <?php 
                if (!empty($bookings)): 
                    foreach($bookings as $row): 
                        $booking_id = $row['id'];
                        $booker = !empty($row['customer_name']) ? $row['customer_name'] : ($row['guest_name'] ?? 'ลูกค้าทั่วไป');
                        $b_code = !empty($row['booking_code']) ? $row['booking_code'] : ('BK' . str_pad($booking_id, 6, '0', STR_PAD_LEFT));
                        $raft_title = $row['raft_name'] ?? ('แพ #' . $row['raft_id']);
                        $total_amount = floatval($row['total_amount'] ?? $row['raft_price'] ?? $row['total_price'] ?? 0);
                        $slip_file = $row['slip_image'] ?? '';

                        $raw_status_id = intval($row['status_id'] ?? 1);
                        if ($raw_status_id == 2 || ($row['status'] ?? '') === 'confirmed') {
                            $status_key = 'confirmed'; $label = 'ยืนยันแล้ว'; $badge_class = 'bg-emerald-100 text-emerald-700';
                        } elseif ($raw_status_id == 3 || ($row['status'] ?? '') === 'cancelled') {
                            $status_key = 'cancelled'; $label = 'ยกเลิก'; $badge_class = 'bg-rose-100 text-rose-700';
                        } elseif ($raw_status_id == 4 || ($row['status'] ?? '') === 'completed') {
                            $status_key = 'completed'; $label = 'เสร็จสิ้น'; $badge_class = 'bg-blue-100 text-blue-700';
                        } else {
                            $status_key = 'pending'; $label = 'รอตรวจสอบ'; $badge_class = 'bg-amber-100 text-amber-700';
                        }

                        $in_date = $row['check_in_date'] ?? date('Y-m-d');
                        $in_time = !empty($row['check_in_time']) ? date('H:i', strtotime($row['check_in_time'])) : '09:00';
                        $out_date = $row['check_out_date'] ?? $in_date;
                        $out_time = !empty($row['check_out_time']) ? date('H:i', strtotime($row['check_out_time'])) : '17:30';
                ?>
                <div class="bg-white p-6 rounded-[2rem] shadow-sm border border-slate-100">
                    <div class="flex justify-between items-start mb-4">
                        <div class="text-left">
                            <div class="font-black text-slate-800 text-base mb-0.5"><?php echo htmlspecialchars($booker); ?></div>
                            <div class="text-blue-600 font-bold text-xs"><?php echo htmlspecialchars($raft_title); ?></div>
                            <div class="text-[10px] font-mono text-slate-400 mt-1">#<?php echo $b_code; ?></div>
                        </div>
                        <span class="<?php echo $badge_class; ?> px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-tighter">
                            <?php echo $label; ?>
                        </span>
                    </div>
                    
                    <div class="bg-slate-50 p-4 rounded-2xl space-y-2 mb-4 text-left">
                        <div class="flex items-center text-xs font-bold text-slate-600">
                            <i class="fa fa-calendar-alt w-5 text-blue-500"></i> เช็คอิน: <?php echo date('d/m/Y', strtotime($in_date)); ?> (<?php echo $in_time; ?>)
                        </div>
                        <div class="flex items-center text-xs font-bold text-slate-600">
                            <i class="fa fa-calendar-check w-5 text-rose-500"></i> เช็คเอาท์: <?php echo date('d/m/Y', strtotime($out_date)); ?> (<?php echo $out_time; ?>)
                        </div>
                        <div class="flex items-center text-sm font-black text-slate-800 pt-2 border-t border-slate-200">
                            <i class="fa fa-wallet w-5 text-emerald-500"></i> ยอดชำระ: ฿<?php echo number_format($total_amount); ?>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <?php if(!empty($slip_file)): ?>
                            <button onclick="openSlipModal(this)" 
                                    data-slip="uploads/slips/<?php echo htmlspecialchars($slip_file); ?>"
                                    data-booking-id="<?php echo $booking_id; ?>"
                                    data-booking-code="<?php echo htmlspecialchars($b_code); ?>"
                                    data-guest-name="<?php echo htmlspecialchars($booker); ?>"
                                    data-raft-name="<?php echo htmlspecialchars($raft_title); ?>"
                                    data-total-price="<?php echo number_format($total_amount, 2); ?>"
                                    class="flex-1 bg-blue-50 text-blue-600 py-2.5 rounded-xl font-bold text-xs flex items-center justify-center gap-1">
                                <i class="fa fa-image"></i> ดูสลิป
                            </button>
                        <?php endif; ?>

                        <?php if($status_key === 'pending'): ?>
                            <a href="?id=<?php echo $booking_id; ?>&status=confirmed" class="flex-1 bg-emerald-500 text-white py-2.5 rounded-xl font-bold text-xs flex items-center justify-center hover:bg-emerald-600">อนุมัติ</a>
                        <?php elseif($status_key === 'confirmed'): ?>
                            <button type="button" onclick="checkoutRaft(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars($raft_title); ?>')" class="flex-1 bg-blue-600 text-white py-2.5 rounded-xl font-bold text-xs flex items-center justify-center hover:bg-blue-700 gap-1">
                                <i class="fa fa-undo"></i> คืนแพ / เสร็จสิ้น
                            </button>
                        <?php endif; ?>

                        <a href="edit_booking.php?id=<?php echo $booking_id; ?>" class="bg-amber-100 text-amber-700 px-3.5 py-2.5 rounded-xl font-bold text-xs flex items-center justify-center gap-1">
                            <i class="fa fa-edit"></i> แก้ไข
                        </a>

                        <?php if($status_key !== 'cancelled' && $status_key !== 'completed'): ?>
                            <a href="?id=<?php echo $booking_id; ?>&status=cancelled" onclick="return confirm('ยกเลิกรายการนี้?')" class="bg-rose-50 text-rose-600 px-3 py-2.5 rounded-xl font-bold text-xs flex items-center justify-center">ยกเลิก</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; else: ?>
                    <div class="p-10 text-center text-slate-400 font-bold">
                        <?php echo !empty($search_param) ? 'ไม่พบรายการจองที่ค้นหา' : 'ยังไม่มีรายการจองในระบบ'; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ระบบแบ่งหน้า Pagination (UI) -->
            <?php if ($total_pages > 1): ?>
            <div class="flex justify-center mt-4 mb-8">
                <nav class="inline-flex rounded-2xl shadow-sm bg-white overflow-hidden border border-slate-200">
                    <?php 
                    $q_search = !empty($search_param) ? "&search=".urlencode($search_param) : "";
                    ?>

                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1 . $q_search; ?>" class="px-4 py-2.5 text-sm font-bold text-blue-600 hover:bg-blue-50 border-r border-slate-100 transition">
                            <i class="fa fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="px-4 py-2.5 text-sm font-bold text-slate-300 border-r border-slate-100 bg-slate-50 cursor-not-allowed">
                            <i class="fa fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): 
                        $active_class = ($i == $page) ? "bg-blue-600 text-white" : "text-slate-600 hover:bg-slate-50 border-r border-slate-100";
                    ?>
                        <a href="?page=<?php echo $i . $q_search; ?>" class="px-4 py-2.5 text-sm font-bold transition <?php echo $active_class; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1 . $q_search; ?>" class="px-4 py-2.5 text-sm font-bold text-blue-600 hover:bg-blue-50 transition border-l border-slate-100" style="margin-left:-1px;">
                            <i class="fa fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="px-4 py-2.5 text-sm font-bold text-slate-300 bg-slate-50 cursor-not-allowed border-l border-slate-100" style="margin-left:-1px;">
                            <i class="fa fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </nav>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Modal ตรวจสลิป -->
    <div id="imageModal" class="modal fixed inset-0 flex items-center justify-center opacity-0 pointer-events-none z-[100] transition-all duration-300">
        <div class="absolute inset-0 bg-slate-900/80 backdrop-blur-md" onclick="closeModal()"></div>
        <div class="relative bg-white w-full max-w-lg mx-4 rounded-3xl shadow-2xl overflow-hidden transform scale-90 transition-all duration-300 border border-slate-100" id="modalContent">
            <div class="p-5 bg-slate-900 text-white flex justify-between items-center">
                <h3 class="font-bold text-base flex items-center gap-2">
                    <i class="fa fa-receipt text-amber-400"></i> ตรวจสอบหลักฐานการโอนเงิน
                </h3>
                <button onclick="closeModal()" class="text-slate-400 hover:text-white text-2xl leading-none">&times;</button>
            </div>
            
            <div class="p-6 bg-slate-50 space-y-4">
                <div class="bg-white p-3 rounded-2xl shadow-sm border border-slate-200 text-center">
                    <a id="modalImageLink" href="#" target="_blank">
                        <img id="modalImage" src="" class="w-full h-auto max-h-80 object-contain rounded-xl shadow-inner mx-auto">
                    </a>
                </div>

                <div class="bg-white p-4 rounded-2xl border border-slate-200 space-y-2 text-xs">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400 font-bold">รหัสการจอง:</span>
                        <span id="modalBookingId" class="font-mono font-bold text-blue-600"></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-400 font-bold">ผู้จอง / แพ:</span>
                        <span id="modalGuestRaft" class="font-bold text-slate-800"></span>
                    </div>
                    <div class="flex justify-between items-center pt-2 border-t border-slate-100">
                        <span class="text-slate-500 font-bold">ยอดเงินที่ต้องชำระ:</span>
                        <span id="modalTotalPrice" class="font-black text-emerald-600 text-sm"></span>
                    </div>
                </div>
            </div>

            <div class="p-4 bg-slate-100 border-t border-slate-200 flex justify-between items-center">
                <button onclick="closeModal()" class="px-4 py-2 bg-slate-200 text-slate-700 rounded-xl font-bold text-xs">
                    ปิด
                </button>
                <div class="flex gap-2">
                    <a id="modalConfirmBtn" href="#" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl font-bold text-xs flex items-center gap-1 shadow-md">
                        <i class="fa fa-check-circle"></i> ยืนยันการจอง (อนุมัติ)
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- สคริปต์การทำงาน -->
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

        function openSlipModal(btn) {
            const ds = btn.dataset;
            document.getElementById('modalImage').src = ds.slip;
            document.getElementById('modalImageLink').href = ds.slip;
            document.getElementById('modalBookingId').textContent = ds.bookingCode || ('#' + ds.bookingId);
            document.getElementById('modalGuestRaft').textContent = `${ds.guestName} (${ds.raftName})`;
            document.getElementById('modalTotalPrice').textContent = '฿' + ds.totalPrice;
            document.getElementById('modalConfirmBtn').href = `?id=${ds.bookingId}&status=confirmed`;

            const modal = document.getElementById('imageModal');
            const content = document.getElementById('modalContent');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            content.classList.remove('scale-90');
            content.classList.add('scale-100');
        }

        function closeModal() {
            const modal = document.getElementById('imageModal');
            const content = document.getElementById('modalContent');
            if (modal && content) {
                modal.classList.add('opacity-0', 'pointer-events-none');
                content.classList.remove('scale-100');
                content.classList.add('scale-90');
            }
        }

        function checkoutRaft(bookingId, raftName) {
            Swal.fire({
                title: 'ยืนยันการคืนแพ?',
                html: `กำลังทำการคืน <b>${raftName}</b><br>โปรดตรวจสอบสิ่งของบนแพว่าไม่มีความเสียหายก่อนกดยืนยัน`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: '<i class="fa fa-check mr-1"></i> ตรวจสอบแล้ว, คืนแพเลย!',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(`manage_bookings.php?id=${bookingId}&status=completed&ajax=1`)
                    .then(response => response.json())
                    .then(data => {
                        if(data.status === 'success') {
                            Swal.fire({
                                title: 'เสร็จสิ้น!',
                                text: 'ทำรายการคืนแพและเปลี่ยนสถานะเป็นว่างเรียบร้อยแล้ว',
                                icon: 'success',
                                confirmButtonColor: '#10b981'
                            }).then(() => {
                                window.location.reload(); 
                            });
                        } else {
                            Swal.fire('ข้อผิดพลาด!', data.msg || 'ไม่สามารถอัปเดตข้อมูลได้', 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('ข้อผิดพลาด!', 'เกิดปัญหาในการเชื่อมต่อกับระบบหลังบ้าน', 'error');
                    });
                }
            });
        }
    </script>
</body>
</html>
