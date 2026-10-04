<?php
session_start();

if (file_exists(__DIR__ . '/db_config.php')) {
    require_once __DIR__ . '/db_config.php';
} else {
    require_once __DIR__ . '/../db_config.php';
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 🟢 ปรับโครงสร้างตาราง PostgreSQL อัตโนมัติเพื่อความสมบูรณ์ของระบบ
if ($conn) {
    @pg_query($conn, "ALTER TABLE rafts ADD COLUMN IF NOT EXISTS raft_code VARCHAR(50);");
    @pg_query($conn, "ALTER TABLE rafts ADD COLUMN IF NOT EXISTS price_per_hour NUMERIC(10,2) DEFAULT 0;");
    @pg_query($conn, "ALTER TABLE rafts ADD COLUMN IF NOT EXISTS is_active INT DEFAULT 1;");
    @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN featured_image TYPE TEXT;");
    for ($i = 1; $i <= 5; $i++) {
        @pg_query($conn, "ALTER TABLE rafts ALTER COLUMN image_{$i} TYPE TEXT;");
    }
}

// 🟢 ฟังก์ชันจัดการ URL รูปภาพ
if (!function_exists('get_raft_image_url')) {
    function get_raft_image_url($input) {
        $img = '';
        if (is_array($input)) {
            if (!empty($input['featured_image'])) {
                $img = trim($input['featured_image']);
            } else {
                for ($i = 1; $i <= 5; $i++) {
                    if (!empty($input['image_' . $i])) {
                        $img = trim($input['image_' . $i]);
                        break;
                    }
                }
            }
        } else {
            $img = trim((string)$input);
        }

        if (empty($img)) return '';

        if (preg_match('/^(https?:\/\/|data:image\/)/i', $img)) {
            return $img;
        }

        $clean_img = ltrim(preg_replace('/^(\.\.\/|\.\/|uploads\/|\/uploads\/)+/i', '', $img), '/');
        return 'uploads/' . $clean_img;
    }
}

// 2. ระบบสลับสถานะ
if (isset($_GET['change_status']) && isset($_GET['new_val'])) {
    $id = intval($_GET['change_status']);
    $val = trim($_GET['new_val']);
    if ($conn) {
        @pg_query_params($conn, "UPDATE rafts SET status = $1 WHERE id = $2", array($val, $id));
    }
    header("Location: manage_rafts.php");
    exit();
}

// 3. ระบบลบข้อมูลแพ
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    if ($conn && $delete_id > 0) {
        $r_cols = [];
        $res_rc = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'rafts'");
        if ($res_rc) {
            while ($c = pg_fetch_assoc($res_rc)) {
                $r_cols[] = strtolower($c['column_name']);
            }
        }

        $b_cols = [];
        $res_bc = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
        if ($res_bc) {
            while ($c = pg_fetch_assoc($res_bc)) {
                $b_cols[] = strtolower($c['column_name']);
            }
        }

        $chk_pay = @pg_query($conn, "SELECT to_regclass('public.payments')");
        $has_pay = ($chk_pay && ($r_tbl = pg_fetch_row($chk_pay)) && !empty($r_tbl[0]));
        $pay_cols = [];
        if ($has_pay) {
            $res_pc = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'payments'");
            if ($res_pc) {
                while ($c = pg_fetch_assoc($res_pc)) {
                    $pay_cols[] = strtolower($c['column_name']);
                }
            }
        }

        $chk_r_imgs = @pg_query($conn, "SELECT to_regclass('public.raft_images')");
        $has_r_imgs = ($chk_r_imgs && ($r_tbl = pg_fetch_row($chk_r_imgs)) && !empty($r_tbl[0]));

        @pg_query($conn, "BEGIN");

        if ($has_pay && in_array('booking_id', $pay_cols)) {
            if (in_array('slip_image', $pay_cols)) {
                $res_pay_slips = @pg_query_params($conn, "SELECT slip_image FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE raft_id = $1)", array($delete_id));
                if ($res_pay_slips) {
                    while ($ps = pg_fetch_assoc($res_pay_slips)) {
                        $slip_file = __DIR__ . "/uploads/slips/" . $ps['slip_image'];
                        if (!empty($ps['slip_image']) && file_exists($slip_file)) { 
                            @unlink($slip_file); 
                        }
                    }
                }
            }
            $del_p = @pg_query_params($conn, "DELETE FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE raft_id = $1)", array($delete_id));
            if ($del_p === false) {
                $err = pg_last_error($conn);
                @pg_query($conn, "ROLLBACK");
                header("Location: manage_rafts.php?msg=error&err=" . urlencode($err));
                exit();
            }
        }

        if (in_array('slip_image', $b_cols)) {
            $res_slips = @pg_query_params($conn, "SELECT slip_image FROM bookings WHERE raft_id = $1", array($delete_id));
            if ($res_slips) {
                while ($slip = pg_fetch_assoc($res_slips)) {
                    $slip_file = __DIR__ . "/uploads/slips/" . $slip['slip_image'];
                    if (!empty($slip['slip_image']) && file_exists($slip_file)) { 
                        @unlink($slip_file); 
                    }
                }
            }
        }

        $img_cols_to_select = [];
        if (in_array('featured_image', $r_cols)) $img_cols_to_select[] = 'featured_image';
        for ($i = 1; $i <= 5; $i++) {
            if (in_array("image_$i", $r_cols)) $img_cols_to_select[] = "image_$i";
        }

        if (!empty($img_cols_to_select)) {
            $res_raft_imgs = @pg_query_params($conn, "SELECT " . implode(", ", $img_cols_to_select) . " FROM rafts WHERE id = $1", array($delete_id));
            if ($res_raft_imgs && $r_img = pg_fetch_assoc($res_raft_imgs)) {
                $all_available_img_cols = array_intersect(
                    ['featured_image', 'image_1', 'image_2', 'image_3', 'image_4', 'image_5'],
                    $r_cols
                );

                foreach ($img_cols_to_select as $col) {
                    $img_val = trim($r_img[$col] ?? '');

                    if (empty($img_val) || preg_match('/^(https?:\/\/|data:image\/)/i', $img_val)) {
                        continue;
                    }

                    $clean_file_name = basename($img_val);
                    $where_conds = [];
                    foreach ($all_available_img_cols as $c_name) {
                        $where_conds[] = "{$c_name} = $2 OR {$c_name} = $3 OR {$c_name} = 'uploads/' || $3";
                    }

                    $in_use_count = 0;
                    if (!empty($where_conds)) {
                        $chk_sql = "SELECT COUNT(*) as cnt FROM rafts WHERE id != $1 AND (" . implode(" OR ", $where_conds) . ")";
                        $chk_res = @pg_query_params($conn, $chk_sql, array($delete_id, $img_val, $clean_file_name));
                        if ($chk_res && $chk_row = pg_fetch_assoc($chk_res)) {
                            $in_use_count = intval($chk_row['cnt']);
                        }
                    }

                    if ($in_use_count === 0) {
                        $target_img = __DIR__ . '/uploads/' . $clean_file_name;
                        if (file_exists($target_img)) {
                            @unlink($target_img);
                        }
                    }
                }
            }
        }

        if ($has_r_imgs) {
            $res_imgs = @pg_query_params($conn, "SELECT image_path FROM raft_images WHERE raft_id = $1", array($delete_id));
            if ($res_imgs) {
                while ($img = pg_fetch_assoc($res_imgs)) {
                    $sub_val = trim($img['image_path'] ?? '');
                    if (!empty($sub_val) && !preg_match('/^(https?:\/\/|data:image\/)/i', $sub_val)) {
                        $sub_clean = basename($sub_val);
                        $chk_sub = @pg_query_params($conn, "SELECT COUNT(*) as cnt FROM raft_images WHERE raft_id != $1 AND (image_path = $2 OR image_path = $3)", array($delete_id, $sub_val, $sub_clean));
                        $sub_count = 0;
                        if ($chk_sub && $srow = pg_fetch_assoc($chk_sub)) {
                            $sub_count = intval($srow['cnt']);
                        }
                        if ($sub_count === 0) {
                            $sub_img = __DIR__ . '/uploads/' . $sub_clean;
                            if (file_exists($sub_img)) {
                                @unlink($sub_img);
                            }
                        }
                    }
                }
            }
            @pg_query_params($conn, "DELETE FROM raft_images WHERE raft_id = $1", array($delete_id));
        }

        $del_b = @pg_query_params($conn, "DELETE FROM bookings WHERE raft_id = $1", array($delete_id));
        if ($del_b === false) {
            $err = pg_last_error($conn);
            @pg_query($conn, "ROLLBACK");
            header("Location: manage_rafts.php?msg=error&err=" . urlencode($err));
            exit();
        }

        $del_raft = @pg_query_params($conn, "DELETE FROM rafts WHERE id = $1", array($delete_id));
        if ($del_raft === false) {
            $err = pg_last_error($conn);
            @pg_query($conn, "ROLLBACK");
            header("Location: manage_rafts.php?msg=error&err=" . urlencode($err));
            exit();
        }

        @pg_query($conn, "COMMIT");
        header("Location: manage_rafts.php?msg=deleted");
        exit();
    }
}

// 4. รับค่าคำค้นหา
$search_query = "";
$search_param = "";
$search_params = [];
if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $search_param = trim($_GET['search']);
    $search_query = " WHERE (r.name ILIKE $1 OR COALESCE(r.raft_code, '') ILIKE $1) ";
    $search_params[] = '%' . $search_param . '%';
}

// 5. ระบบแบ่งหน้า Pagination
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$total_rows = 0;
if ($conn) {
    $count_sql = "SELECT COUNT(r.id) as total_rows FROM rafts r $search_query";
    $count_res = !empty($search_params) ? @pg_query_params($conn, $count_sql, $search_params) : @pg_query($conn, $count_sql);
    if ($count_res && $crow = pg_fetch_assoc($count_res)) {
        $total_rows = intval($crow['total_rows']);
    }
}
$total_pages = ceil($total_rows / $limit);

// 6. ดึงข้อมูลแพ
$rafts = [];
if ($conn) {
    // 🟢 เช็คก่อนว่ามีตาราง raft_types หรือไม่
    $chk_types = @pg_query($conn, "SELECT to_regclass('public.raft_types')");
    $has_types = ($chk_types && ($r_tbl = pg_fetch_row($chk_types)) && !empty($r_tbl[0]));

    if ($has_types) {
        $base_sql = "SELECT r.*, COALESCE(t.name, t.type_name, 'ประเภท #' || r.raft_type_id) as type_name 
                     FROM rafts r 
                     LEFT JOIN raft_types t ON r.raft_type_id = t.id";
    } else {
        $base_sql = "SELECT r.*, 'แพมาตรฐาน' as type_name FROM rafts r";
    }

    if (!empty($search_params)) {
        // ใช้ $2 และ $3 สำหรับ LIMIT และ OFFSET
        $sql = "$base_sql WHERE (r.name ILIKE $1 OR COALESCE(r.raft_code, '') ILIKE $1) ORDER BY r.id DESC LIMIT $2 OFFSET $3";
        $params_with_limit = array_merge($search_params, [$limit, $offset]);
        $result = @pg_query_params($conn, $sql, $params_with_limit);
    } else {
        // ถ้าไม่มีการค้นหา ใช้แค่ $1 และ $2
        $sql = "$base_sql ORDER BY r.id DESC LIMIT $1 OFFSET $2";
        $result = @pg_query_params($conn, $sql, [$limit, $offset]);
    }

    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            $rafts[] = $row;
        }
    } else {
        // Fallback: ถ้า Query หลักพัง ให้ลองดึงแบบธรรมดา
        $fallback_sql = "SELECT * FROM rafts ORDER BY id DESC LIMIT $1 OFFSET $2";
        $result_fb = @pg_query_params($conn, $fallback_sql, [$limit, $offset]);
        if ($result_fb) {
            while ($row = pg_fetch_assoc($result_fb)) {
                $row['type_name'] = 'แพมาตรฐาน';
                $rafts[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลแพ - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
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
<body class="bg-gray-50 flex min-h-screen">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- เรียกใช้ Sidebar -->
    <?php 
    if (file_exists(__DIR__ . '/sidebar.php')) {
        include __DIR__ . '/sidebar.php';
    } elseif (file_exists(__DIR__ . '/../sidebar.php')) {
        include __DIR__ . '/../sidebar.php';
    }
    ?>

    <main class="flex-grow flex flex-col min-w-0">
        
        <header class="bg-white shadow-sm p-4 flex justify-between items-center px-4 md:px-10 sticky top-0 z-30">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="md:hidden mr-4 p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl md:text-2xl font-bold text-gray-800">จัดการข้อมูลแพ</h1>
            </div>
            
            <?php if(isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
                <div id="toast" class="bg-emerald-500 text-white px-4 lg:px-6 py-2 rounded-full shadow-lg text-xs lg:text-sm font-bold flex items-center gap-2">
                    <i class="fa fa-check-circle"></i> ลบข้อมูลแพสำเร็จ!
                </div>
                <script>setTimeout(() => { const t = document.getElementById('toast'); if(t) t.remove(); }, 3000);</script>
            <?php elseif(isset($_GET['msg']) && $_GET['msg'] === 'added'): ?>
                <div id="toast" class="bg-emerald-500 text-white px-4 lg:px-6 py-2 rounded-full shadow-lg text-xs lg:text-sm font-bold flex items-center gap-2">
                    <i class="fa fa-check-circle"></i> เพิ่มข้อมูลแพสำเร็จ!
                </div>
                <script>setTimeout(() => { const t = document.getElementById('toast'); if(t) t.remove(); }, 3000);</script>
            <?php elseif(isset($_GET['msg']) && $_GET['msg'] === 'error'): ?>
                <div id="toast" class="bg-rose-500 text-white px-4 lg:px-6 py-2 rounded-full shadow-lg text-xs lg:text-sm font-bold flex items-center gap-2">
                    <i class="fa fa-exclamation-circle"></i> ไม่สามารถลบแพได้: <?php echo htmlspecialchars($_GET['err'] ?? 'ติดข้อมูลผูกพัน'); ?>
                </div>
                <script>setTimeout(() => { const t = document.getElementById('toast'); if(t) t.remove(); }, 6000);</script>
            <?php endif; ?>
        </header>

        <div class="p-4 md:p-10 flex-grow">
            
            <!-- ส่วนของช่องค้นหา + ปุ่มเพิ่มข้อมูลแพ -->
            <div class="mb-6 flex flex-col lg:flex-row justify-between items-stretch lg:items-center gap-4">

                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full lg:w-auto">
                    <!-- Search Box -->
                    <form method="GET" action="manage_rafts.php" class="w-full md:w-96 relative flex items-center">
                        <i class="fa fa-search absolute left-4 text-slate-400"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_param); ?>" 
                               placeholder="ค้นหาชื่อแพ หรือ รหัสแพ..." 
                               class="w-full pl-10 pr-10 py-3 rounded-2xl border border-slate-200 bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none text-sm transition shadow-sm font-bold text-slate-700">
                        
                        <?php if (!empty($search_param)): ?>
                            <a href="manage_rafts.php" class="absolute right-3 text-slate-400 hover:text-rose-500 transition" title="ล้างการค้นหา">
                                <i class="fa fa-times-circle"></i>
                            </a>
                        <?php endif; ?>
                        <button type="submit" class="hidden">ค้นหา</button>
                    </form>

                    <!-- ปุ่มเพิ่มแพ -->
                    <a href="add_raft.php" class="inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white px-5 py-3 rounded-2xl font-bold text-sm shadow-md hover:shadow-lg transition-all duration-200 whitespace-nowrap">
                        <i class="fa fa-plus text-sm"></i> เพิ่มแพใหม่
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

            <!-- Desktop Table View -->
            <div class="table-container bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden mb-6">
                <table class="w-full text-left font-sans">
                    <thead class="bg-gray-50 border-b border-gray-100 uppercase text-[11px] font-black text-gray-400 tracking-widest">
                        <tr>
                            <th class="p-6">รูปแพ</th>
                            <th class="p-6">ชื่อแพ / รหัสแพ</th>
                            <th class="p-6">ประเภทแพ</th>
                            <th class="p-6">ความจุ</th>
                            <th class="p-6">ราคา/วัน</th>
                            <th class="p-6 text-center">สถานะ</th>
                            <th class="p-6 text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php if (!empty($rafts)): foreach($rafts as $row): ?>
                        <tr class="hover:bg-blue-50/20 transition">
                            <td class="p-6">
                                <?php $img_src = get_raft_image_url($row); ?>
                                <?php if(!empty($img_src)): ?>
                                    <img src="<?php echo htmlspecialchars($img_src); ?>" 
                                         alt="<?php echo htmlspecialchars($row['name']); ?>"
                                         onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'w-20 h-14 bg-amber-50 border border-amber-200 rounded-xl flex flex-col items-center justify-center text-[9px] text-amber-600 font-bold text-center leading-tight p-1\'><i class=\'fa fa-image mb-0.5\'></i>รูปไม่พบ</div>';"
                                         class="w-20 h-14 object-cover rounded-xl shadow-sm border border-slate-100">
                                <?php else: ?>
                                    <div class="w-20 h-14 bg-gray-100 rounded-xl flex items-center justify-center text-[10px] text-gray-400 uppercase font-black">
                                        no image
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="p-6">
                                <div class="font-bold text-gray-800 text-base"><?php echo htmlspecialchars($row['name']); ?></div>
                                <div class="text-[11px] font-mono text-blue-500 font-bold"><?php echo htmlspecialchars($row['raft_code'] ?? 'RAFT-'.$row['id']); ?></div>
                            </td>
                            <td class="p-6 font-bold text-slate-600">
                                <?php echo htmlspecialchars($row['type_name'] ?? '-'); ?>
                            </td>
                            <td class="p-6 font-bold text-slate-500"><?php echo $row['capacity']; ?> ท่าน</td>
                            <td class="p-6 font-black text-emerald-600">฿<?php echo number_format($row['price_per_day']); ?></td>
                            
                            <td class="p-6 text-center">
                                <?php if($row['status'] == 'available'): ?>
                                    <a href="?change_status=<?php echo $row['id']; ?>&new_val=maintenance" 
                                       title="คลิกเพื่อปรับเป็นปิดปรับปรุง"
                                       class="inline-flex items-center gap-2 bg-emerald-100 text-emerald-700 px-4 py-1.5 rounded-full text-[11px] font-black hover:bg-emerald-200 transition">
                                        <span class="relative flex h-2 w-2">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                        </span>
                                        ว่าง
                                    </a>
                                <?php elseif($row['status'] == 'busy'): ?>
                                    <div class="inline-flex items-center gap-2 bg-blue-100 text-blue-700 px-4 py-1.5 rounded-full text-[11px] font-black">
                                        <i class="fa fa-user-clock text-[10px]"></i> ไม่ว่าง
                                    </div>
                                <?php elseif($row['status'] == 'pending'): ?>
                                    <div class="inline-flex items-center gap-2 bg-amber-100 text-amber-700 px-4 py-1.5 rounded-full text-[11px] font-black">
                                        <i class="fa fa-clock text-[10px]"></i> รอตรวจสอบ
                                    </div>
                                <?php else: ?>
                                    <a href="?change_status=<?php echo $row['id']; ?>&new_val=available" 
                                       title="คลิกเพื่อเปิดให้บริการ"
                                       class="inline-flex items-center gap-2 bg-rose-100 text-rose-700 px-4 py-1.5 rounded-full text-[11px] font-black hover:bg-rose-200 transition">
                                        <i class="fa fa-wrench text-[10px]"></i> ปิดปรับปรุง
                                    </a>
                                <?php endif; ?>
                            </td>

                            <td class="p-6 text-center">
                                <div class="flex justify-center gap-2">
                                    <a href="edit_raft.php?id=<?php echo $row['id']; ?>" class="bg-slate-100 text-slate-600 w-9 h-9 rounded-lg flex items-center justify-center hover:bg-blue-600 hover:text-white transition shadow-sm" title="แก้ไข">
                                        <i class="fa fa-edit text-sm"></i>
                                    </a>
                                    <a href="?delete_id=<?php echo $row['id']; ?>" 
                                       onclick="return confirm('⚠️ ยืนยันการลบแพนี้ออกจากระบบ? (รายการจองที่ผูกกับแพนี้จะถูกลบออกด้วย)')" 
                                       class="bg-rose-50 text-rose-600 w-9 h-9 rounded-lg flex items-center justify-center hover:bg-rose-600 hover:text-white transition shadow-sm" title="ลบ">
                                        <i class="fa fa-trash text-sm"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr>
                                <td colspan="7" class="p-20 text-center text-slate-400 font-bold uppercase tracking-widest">
                                    <?php echo !empty($search_param) ? 'ไม่พบข้อมูลแพที่ค้นหา' : 'ไม่พบข้อมูลแพในระบบ'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="card-container grid grid-cols-1 gap-4 md:hidden mb-6">
                <?php if (!empty($rafts)): foreach($rafts as $row): ?>
                <div class="bg-white p-5 rounded-[2rem] shadow-sm border border-gray-100">
                    <div class="flex gap-4 mb-4">
                        <div class="shrink-0">
                            <?php $img_src_m = get_raft_image_url($row); ?>
                            <?php if(!empty($img_src_m)): ?>
                                <img src="<?php echo htmlspecialchars($img_src_m); ?>" 
                                     alt="<?php echo htmlspecialchars($row['name']); ?>"
                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'w-24 h-24 bg-amber-50 border border-amber-200 rounded-2xl flex flex-col items-center justify-center text-[10px] text-amber-600 font-bold text-center leading-tight p-2\'><i class=\'fa fa-image text-base mb-1\'></i>รูปไม่พบ</div>';"
                                     class="w-24 h-24 object-cover rounded-2xl shadow-md border-2 border-white">
                            <?php else: ?>
                                <div class="w-24 h-24 bg-gray-100 rounded-2xl flex items-center justify-center text-[10px] text-gray-400 font-black uppercase text-center p-2">
                                    No Image
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow flex flex-col justify-center text-left">
                            <h3 class="font-black text-slate-800 text-lg leading-tight mb-1"><?php echo htmlspecialchars($row['name']); ?></h3>
                            <div class="text-[11px] font-mono text-blue-500 font-bold mb-1"><?php echo htmlspecialchars($row['raft_code'] ?? 'RAFT-'.$row['id']); ?></div>
                            <div class="flex items-center gap-2 text-slate-500 font-bold text-xs">
                                <i class="fa fa-user-friends text-blue-400"></i> <?php echo $row['capacity']; ?> ท่าน
                            </div>
                            <div class="text-emerald-600 font-black text-lg mt-1">฿<?php echo number_format($row['price_per_day']); ?> <span class="text-[10px] text-slate-400 font-bold italic">/วัน</span></div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-slate-50">
                        <div>
                            <?php if($row['status'] == 'available'): ?>
                                <a href="?change_status=<?php echo $row['id']; ?>&new_val=maintenance" 
                                   class="bg-emerald-100 text-emerald-700 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-tighter flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span> ว่าง
                                </a>
                            <?php elseif($row['status'] == 'busy'): ?>
                                <div class="bg-blue-100 text-blue-700 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-tighter flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-blue-500"></span> ไม่ว่าง
                                </div>
                            <?php elseif($row['status'] == 'pending'): ?>
                                <div class="bg-amber-100 text-amber-700 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-tighter flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-amber-500"></span> รอตรวจสอบ
                                </div>
                            <?php else: ?>
                                <a href="?change_status=<?php echo $row['id']; ?>&new_val=available" 
                                   class="bg-rose-100 text-rose-700 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-tighter flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-rose-500"></span> ปรับปรุง
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="flex gap-2">
                            <a href="edit_raft.php?id=<?php echo $row['id']; ?>" class="bg-slate-100 text-slate-600 w-10 h-10 rounded-xl flex items-center justify-center shadow-sm">
                                <i class="fa fa-edit"></i>
                            </a>
                            <a href="?delete_id=<?php echo $row['id']; ?>" 
                               onclick="return confirm('ยืนยันลบข้อมูลแพนี้? (รายการจองที่ผูกกับแพนี้จะถูกลบออกด้วย)')" 
                               class="bg-rose-50 text-rose-600 w-10 h-10 rounded-xl flex items-center justify-center shadow-sm">
                                <i class="fa fa-trash"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; else: ?>
                    <div class="p-10 text-center text-slate-400 font-bold">
                        <?php echo !empty($search_param) ? 'ไม่พบข้อมูลแพที่ค้นหา' : 'ไม่พบข้อมูลแพในระบบ'; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ระบบแบ่งหน้า Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="flex justify-center mt-4 mb-8">
                <nav class="inline-flex rounded-2xl shadow-sm bg-white overflow-hidden border border-slate-200">
                    <?php 
                    $q_search = !empty($search_param) ? "&search=".urlencode($search_param) : "";
                    ?>

                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo ($page - 1) . $q_search; ?>" class="px-4 py-2.5 text-sm font-bold text-blue-600 hover:bg-blue-50 border-r border-slate-100 transition">
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
                        <a href="?page=<?php echo ($page + 1) . $q_search; ?>" class="px-4 py-2.5 text-sm font-bold text-blue-600 hover:bg-blue-50 transition border-l border-slate-100" style="margin-left:-1px;">
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
    </script>
</body>
</html>
