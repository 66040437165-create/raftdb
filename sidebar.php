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

        <!-- ปุ่มตอบลูกค้า LINE (เปิดไปยัง LINE OA Manager เพื่อตอบแชท) -->
        <a href="https://manager.line.biz/" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 px-4 py-3 rounded-xl text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500 hover:text-white transition font-bold mt-2 border border-emerald-500/20">
            <svg class="w-6 h-6 fill-current shrink-0" viewBox="0 0 24 24">
                <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08-.085.643-.388 2.527-.428 2.768-.073.426.335.792.733.522 3.208-2.17 8.652-5.182 11.83-8.871C23.364 14.502 24 12.518 24 10.314"/>
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
