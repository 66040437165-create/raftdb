<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($conn)) {
    require_once __DIR__ . '/db_config.php';
}

$current_page = basename($_SERVER['PHP_SELF']);

// คำนวณจำนวนรายการที่ "รอตรวจสอบ" สำหรับ PostgreSQL เพื่อแสดง Badge แจ้งเตือน
$pending_count = 0;

if ($conn) {
    // ดึงรายชื่อคอลัมน์จากตาราง bookings (PostgreSQL Syntax)
    $res_cols = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'bookings'");
    $b_cols_sb = [];
    if ($res_cols) {
        while ($c = pg_fetch_assoc($res_cols)) {
            $b_cols_sb[] = strtolower($c['column_name']);
        }
    }

    $has_sid_sb = in_array('status_id', $b_cols_sb);
    $has_txt_sb = in_array('status', $b_cols_sb);

    $cond_sb = [];
    if ($has_sid_sb) $cond_sb[] = "status_id = 1";
    if ($has_txt_sb) $cond_sb[] = "status = 'pending'";

    $where_sb = !empty($cond_sb) ? implode(" OR ", $cond_sb) : "1=0";

    $res_badge = @pg_query($conn, "SELECT COUNT(*) as cnt FROM bookings WHERE $where_sb");
    if ($res_badge && $r = pg_fetch_assoc($res_badge)) {
        $pending_count = intval($r['cnt']);
    }
}

function navClass($page_name, $current_page) {
    return ($current_page === $page_name) 
        ? "bg-blue-600 text-white font-bold shadow-lg" 
        : "hover:bg-slate-800 text-slate-300 hover:text-white transition";
}
?>
<!-- Sidebar -->
<aside id="sidebar" class="w-64 bg-slate-900 text-white shadow-xl fixed md:sticky top-0 h-screen z-50 -translate-x-full md:translate-x-0 transition-transform duration-300 flex flex-col font-sans">
    <div class="p-6 text-center border-b border-slate-800 flex justify-between items-center">
        <h1 class="text-2xl font-black text-white tracking-tighter w-full">ระบบหลังบ้าน</h1>
        <button class="md:hidden text-gray-400" onclick="toggleSidebar()"><i class="fa fa-times"></i></button>
    </div>
    
    <nav class="flex-grow p-4 space-y-2 mt-2 overflow-y-auto">
        <!-- เมนูทั่วไป: Admin และ Staff เข้าถึงได้ทุกคน -->
        <a href="admin_dashboard.php" class="flex items-center p-3 rounded-xl <?php echo navClass('admin_dashboard.php', $current_page); ?>">
            <i class="fa fa-home w-6 text-center"></i> <span class="ml-2">หน้าแรก</span>
        </a>
        <a href="manage_rafts.php" class="flex items-center p-3 rounded-xl <?php echo navClass('manage_rafts.php', $current_page); ?>">
            <i class="fa fa-ship w-6 text-center"></i> <span class="ml-2">จัดการแพ</span>
        </a>
        <a href="manage_bookings.php" class="flex items-center p-3 rounded-xl <?php echo navClass('manage_bookings.php', $current_page); ?>">
            <i class="fa fa-calendar-check w-6 text-center"></i> <span class="ml-2 flex-grow">รายการจอง</span>
            <?php if ($pending_count > 0): ?>
                <span class="bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full animate-pulse shadow-lg ml-auto"><?php echo $pending_count; ?></span>
            <?php endif; ?>
        </a>
        <a href="booking_calendar.php" class="flex items-center p-3 rounded-xl <?php echo navClass('booking_calendar.php', $current_page); ?>">
            <i class="fa fa-calendar-alt w-6 text-center"></i> <span class="ml-2">ปฏิทินการจอง</span>
        </a>
        <a href="manage_customers.php" class="flex items-center p-3 rounded-xl <?php echo navClass('manage_customers.php', $current_page); ?>">
            <i class="fa fa-users w-6 text-center"></i> <span class="ml-2">จัดการสมาชิก</span>
        </a>

        <!-- เมนูเฉพาะ Admin (role_id = 1) เท่านั้น -->
        <?php if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1): ?>
            <div class="pt-4 pb-1 px-3 text-[10px] font-bold uppercase text-slate-500 tracking-wider">
                สำหรับผู้ดูแลระบบ / ผู้จัดการ
            </div>
            
            <a href="manage_users.php" class="flex items-center p-3 rounded-xl <?php echo navClass('manage_users.php', $current_page); ?>">
                <i class="fa fa-user-tie w-6 text-center"></i> <span class="ml-2">จัดการพนักงาน</span>
            </a>
            <a href="manage_finances.php" class="flex items-center p-3 rounded-xl <?php echo navClass('manage_finances.php', $current_page); ?>">
                <i class="fa fa-wallet w-6 text-center"></i> <span class="ml-2">บัญชี-รายจ่าย</span>
            </a>
            <a href="manage_reports.php" class="flex items-center p-3 rounded-xl <?php echo navClass('manage_reports.php', $current_page); ?>">
                <i class="fa fa-chart-line w-6 text-center"></i> <span class="ml-2">รายงานวิเคราะห์</span>
            </a>
            <a href="manage_settings.php" class="flex items-center p-3 rounded-xl <?php echo navClass('manage_settings.php', $current_page); ?>">
                <i class="fa fa-cog w-6 text-center"></i> <span class="ml-2">ตั้งค่าระบบ</span>
            </a>
        <?php endif; ?>

        <!-- ปุ่มตอบลูกค้า LINE (ใช้ SVG โดยตรง) -->
<a href="https://lin.ee/YOUR_LINE_ID" target="_blank" class="flex items-center gap-3 px-4 py-3 rounded-xl text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500 hover:text-white transition font-bold mt-2 border border-emerald-500/20">
    <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24">
        <path d="M12 2C6.48 2 2 5.82 2 10.5c0 2.93 1.81 5.5 4.58 7.02-.2.74-.73 2.68-.78 2.89-.07.31.14.3.3.19.12-.08 1.95-1.33 2.74-1.87.71.18 1.44.27 2.16.27 5.52 0 10-3.82 10-8.5S17.52 2 12 2z"/>
    </svg>
    <span>ตอบลูกค้า (LINE)</span>
</a>
    </nav>

    <div class="p-4 border-t border-slate-800">
        <a href="logout.php" onclick="return confirm('คุณต้องการออกจากระบบหรือไม่?')" class="flex items-center p-3 text-red-400 hover:bg-red-900/20 rounded-xl transition">
            <i class="fa fa-sign-out-alt w-6 text-center"></i> <span class="ml-2">ออกจากระบบ</span>
        </a>
    </div>
</aside>
