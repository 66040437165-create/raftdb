<?php
session_start();
require_once __DIR__ . '/db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$msg = '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ตรวจสอบชื่อคอลัมน์ Primary Key และชื่อคอลัมน์อื่นๆ เพื่อความยืดหยุ่น
$pk_col = 'id';
$name_col = 'fullname';
$tel_col = 'tel';

if ($conn) {
    $chk_col = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
    if ($chk_col) {
        $u_cols = [];
        while ($r = pg_fetch_assoc($chk_col)) {
            $u_cols[] = strtolower($r['column_name']);
        }
        if (in_array('user_id', $u_cols)) { $pk_col = 'user_id'; }
        if (in_array('full_name', $u_cols)) { $name_col = 'full_name'; }
        if (in_array('phone', $u_cols) && !in_array('tel', $u_cols)) { $tel_col = 'phone'; }
    }
}

// 2. ดึงข้อมูลสมาชิกเดิม (PostgreSQL Parameterized Query)
$user = null;
if ($conn && $user_id > 0) {
    $sql_user = "SELECT * FROM users WHERE $pk_col = $1 AND role != 'admin' LIMIT 1";
    $res = @pg_query_params($conn, $sql_user, array($user_id));
    if ($res) {
        $user = pg_fetch_assoc($res);
    }
}

if (!$user) {
    header("Location: manage_customers.php");
    exit();
}

// 3. ระบบอัปเดตข้อมูล
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullname     = trim($_POST['fullname'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $tel          = trim($_POST['tel'] ?? '');
    $role         = trim($_POST['role'] ?? 'customer');
    $new_password = $_POST['new_password'] ?? '';

    // เช็ค username ซ้ำ (ยกเว้นของตัวเอง)
    $sql_check = "SELECT $pk_col FROM users WHERE username = $1 AND $pk_col != $2 LIMIT 1";
    $check_res = @pg_query_params($conn, $sql_check, array($username, $user_id));

    if ($check_res && pg_num_rows($check_res) > 0) {
        $msg = 'error_username_exists';
    } else {
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql_update = "UPDATE users SET $name_col = $1, username = $2, $tel_col = $3, role = $4, password = $5 WHERE $pk_col = $6";
            $update_res = @pg_query_params($conn, $sql_update, array($fullname, $username, $tel, $role, $hashed_password, $user_id));
        } else {
            $sql_update = "UPDATE users SET $name_col = $1, username = $2, $tel_col = $3, role = $4 WHERE $pk_col = $5";
            $update_res = @pg_query_params($conn, $sql_update, array($fullname, $username, $tel, $role, $user_id));
        }

        if ($update_res) {
            header("Location: manage_customers.php?msg=updated");
            exit();
        } else {
            $msg = 'error_update';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขข้อมูลสมาชิก - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .sidebar-active { transform: translateX(0) !important; }
    </style>
</head>
<body class="bg-gray-50 flex min-h-screen">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- เรียกใช้ Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 px-6 md:px-10 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="md:hidden p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl md:text-2xl font-bold text-gray-800">แก้ไขข้อมูลสมาชิก</h1>
            </div>
            <a href="manage_customers.php" class="text-gray-400 hover:text-gray-600 transition font-black text-xs uppercase tracking-widest flex items-center gap-1">
                <i class="fa fa-arrow-left"></i> กลับ
            </a>
        </header>

        <div class="p-6 md:p-10 max-w-4xl mx-auto w-full">
            <?php if ($msg === 'error_username_exists'): ?>
                <div class="bg-rose-500 text-white p-4 rounded-2xl mb-6 shadow-md flex items-center gap-3">
                    <i class="fa fa-exclamation-circle text-xl"></i>
                    <span class="font-bold">ชื่อผู้ใช้งานนี้มีคนใช้แล้ว! กรุณาใช้ชื่ออื่น</span>
                </div>
            <?php elseif ($msg === 'error_update'): ?>
                <div class="bg-rose-500 text-white p-4 rounded-2xl mb-6 shadow-md flex items-center gap-3">
                    <i class="fa fa-exclamation-circle text-xl"></i>
                    <span class="font-bold">เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง</span>
                </div>
            <?php endif; ?>

            <form method="POST" class="bg-white rounded-[2.5rem] shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 md:p-10 space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8">
                        <!-- Full Name -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1">ชื่อ-นามสกุล</label>
                            <input type="text" name="fullname" value="<?php echo htmlspecialchars($user[$name_col] ?? $user['fullname'] ?? ''); ?>" required
                                   class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                        </div>

                        <!-- Username -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1">ชื่อผู้ใช้งาน (Username)</label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required
                                   class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                        </div>

                        <!-- Telephone -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1">เบอร์โทรศัพท์</label>
                            <input type="tel" name="tel" value="<?php echo htmlspecialchars($user[$tel_col] ?? $user['tel'] ?? ''); ?>" required
                                   maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                        </div>

                        <!-- Role -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1">บทบาท (Role)</label>
                            <div class="relative">
                                <select name="role" class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition appearance-none cursor-pointer">
                                    <option value="customer" <?php if (($user['role'] ?? '') === 'customer' || empty($user['role'])) echo 'selected'; ?>>ลูกค้า (Customer)</option>
                                    <option value="employee" <?php if (($user['role'] ?? '') === 'employee' || ($user['role'] ?? '') === 'staff') echo 'selected'; ?>>พนักงาน (Employee)</option>
                                </select>
                                <i class="fa fa-chevron-down absolute right-4 top-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <!-- Password (Optional) -->
                        <div class="space-y-2 md:col-span-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1 text-rose-400">เปลี่ยนรหัสผ่าน (เว้นว่างได้หากไม่ต้องการเปลี่ยน)</label>
                            <input type="password" name="new_password" placeholder="ระบุรหัสผ่านใหม่..."
                                   class="w-full p-4 bg-slate-50 rounded-2xl border border-slate-200 focus:ring-2 focus:ring-rose-500 outline-none font-bold text-slate-800 transition">
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 p-6 md:p-8 border-t border-gray-100 flex justify-between items-center">
                    <a href="manage_customers.php" class="text-slate-400 font-bold hover:text-slate-600 transition">ยกเลิก</a>
                    <button type="submit" class="bg-blue-600 text-white px-8 md:px-10 py-4 rounded-2xl font-bold shadow-lg hover:bg-blue-700 transition">
                        บันทึกการเปลี่ยนแปลง
                    </button>
                </div>
            </form>
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
