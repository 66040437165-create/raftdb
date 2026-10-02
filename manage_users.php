<?php
session_start();
require_once __DIR__ . '/../db_config.php';

// 1. ตรวจสอบสิทธิ์: ต้องล็อกอินและเป็น Admin (role_id = 1) เท่านั้น
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 1) {
    header("Location: admin_dashboard.php?msg=access_denied");
    exit();
}

// สร้าง CSRF Token หากยังไม่มี
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. ระบบเพิ่มพนักงานใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: manage_users.php?msg=error");
        exit();
    }

    $username  = trim($_POST['username'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $role_id   = intval($_POST['role_id'] ?? 2); // 1 = Admin, 2 = Staff
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

    if (empty($username) || empty($password) || empty($full_name)) {
        header("Location: manage_users.php?msg=error");
        exit();
    }

    // ตรวจสอบ username ซ้ำ
    $chk = $conn->prepare("SELECT id FROM employees WHERE username = ? LIMIT 1");
    $chk->bind_param("s", $username);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        header("Location: manage_users.php?msg=duplicate");
        exit();
    }
    $chk->close();

    // เข้ารหัสรหัสผ่าน (Hash)
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO employees (username, password, full_name, email, phone, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssii", $username, $hashed_password, $full_name, $email, $phone, $role_id, $is_active);
    
    if ($stmt->execute()) {
        header("Location: manage_users.php?msg=added");
    } else {
        header("Location: manage_users.php?msg=error");
    }
    $stmt->close();
    exit();
}

// 3. ระบบแก้ไขข้อมูลและบทบาทพนักงาน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_employee'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: manage_users.php?msg=error");
        exit();
    }

    $emp_id    = intval($_POST['emp_id']);
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $role_id   = isset($_POST['role_id']) ? intval($_POST['role_id']) : null;
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : null;
    $password  = trim($_POST['password'] ?? '');

    // หากแอดมินแก้ไขข้อมูลของตนเอง ป้องกันไม่ให้เปลี่ยนบทบาทและห้ามระงับบัญชีตนเอง
    if ($emp_id === (int)$_SESSION['user_id']) {
        $role_id   = 1;
        $is_active = 1;
    } else {
        // หากส่งค่า null มา ให้คงค่าเดิมในฐานข้อมูลไว้
        if ($role_id === null || $is_active === null) {
            $curr = $conn->prepare("SELECT role_id, is_active FROM employees WHERE id = ? LIMIT 1");
            $curr->bind_param("i", $emp_id);
            $curr->execute();
            $res = $curr->get_result()->fetch_assoc();
            $role_id   = $role_id ?? (int)$res['role_id'];
            $is_active = $is_active ?? (int)$res['is_active'];
            $curr->close();
        }
    }

    if (!empty($password)) {
        // กรณีมีการตั้งรหัสผ่านใหม่
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE employees SET full_name = ?, email = ?, phone = ?, role_id = ?, is_active = ?, password = ? WHERE id = ?");
        $stmt->bind_param("sssiisi", $full_name, $email, $phone, $role_id, $is_active, $hashed_password, $emp_id);
    } else {
        // กรณีไม่เปลี่ยนรหัสผ่าน
        $stmt = $conn->prepare("UPDATE employees SET full_name = ?, email = ?, phone = ?, role_id = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sssiii", $full_name, $email, $phone, $role_id, $is_active, $emp_id);
    }

    if ($stmt->execute()) {
        header("Location: manage_users.php?msg=updated");
    } else {
        header("Location: manage_users.php?msg=error");
    }
    $stmt->close();
    exit();
}

// 4. ระบบลบข้อมูลพนักงาน
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // ป้องกันแอดมินลบบัญชีของตัวเอง
    if ($delete_id === (int)$_SESSION['user_id']) {
        header("Location: manage_users.php?msg=error_self");
    } else {
        $stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            header("Location: manage_users.php?msg=deleted");
        } else {
            header("Location: manage_users.php?msg=error");
        }
        $stmt->close();
    }
    exit();
}

// 5. ดึงข้อมูลพนักงานทั้งหมด
$sql = "SELECT id, username, full_name, email, phone, role_id, is_active, last_login FROM employees ORDER BY role_id ASC, id DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการพนักงานและสิทธิ์ผู้ใช้งาน - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; }</style>
</head>
<body class="bg-slate-50 flex min-h-screen">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- แถบ Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-6 md:p-8 flex justify-between items-center px-6 md:px-10 sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <button onclick="toggleSidebar()" class="md:hidden p-2 text-gray-600 hover:bg-gray-100 rounded-lg">
                    <i class="fa fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-xl md:text-2xl font-black text-gray-800">จัดการพนักงานและสิทธิ์ผู้ใช้งาน</h1>
                    <p class="text-gray-500 text-xs mt-0.5">กำหนดบทบาท ตำแหน่ง และสถานะการเข้าใช้งานระบบหลังบ้าน</p>
                </div>
            </div>
            <button onclick="document.getElementById('addEmpModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2.5 rounded-xl shadow-md text-sm flex items-center gap-2 transition">
                <i class="fa fa-user-plus"></i> เพิ่มพนักงานใหม่
            </button>
        </header>

        <div class="p-4 md:p-10">
            <?php if(isset($_GET['msg'])): ?>
                <?php
                $msg_type = 'success';
                $msg_text = 'ดำเนินการสำเร็จ!';
                if($_GET['msg'] == 'added') $msg_text = 'เพิ่มพนักงานใหม่สำเร็จ!';
                elseif($_GET['msg'] == 'updated') $msg_text = 'บันทึกการแก้ไขข้อมูลเรียบร้อย!';
                elseif($_GET['msg'] == 'deleted') $msg_text = 'ลบพนักงานสำเร็จ!';
                elseif($_GET['msg'] == 'duplicate') { $msg_type = 'error'; $msg_text = 'Username นี้มีในระบบแล้ว!'; }
                elseif($_GET['msg'] == 'error_self') { $msg_type = 'error'; $msg_text = 'ไม่สามารถลบบัญชีของตนเองที่กำลังล็อกอินอยู่ได้!'; }
                elseif($_GET['msg'] == 'access_denied') { $msg_type = 'error'; $msg_text = 'คุณไม่มีสิทธิ์เข้าถึงส่วนนี้!'; }
                elseif($_GET['msg'] == 'error') { $msg_type = 'error'; $msg_text = 'เกิดข้อผิดพลาดในการดำเนินการ!'; }
                ?>
                <div class="<?php echo $msg_type == 'success' ? 'bg-emerald-500 shadow-emerald-100' : 'bg-rose-500 shadow-rose-100'; ?> text-white p-4 rounded-2xl mb-8 shadow-lg flex items-center gap-3">
                    <i class="fa <?php echo $msg_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> text-xl"></i>
                    <span class="font-bold"><?php echo $msg_text; ?></span>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-[2.5rem] shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left font-sans">
                        <thead class="bg-slate-50 border-b border-slate-100">
                            <tr>
                                <th class="p-6 text-[11px] font-black text-slate-400 uppercase tracking-widest pl-10">ชื่อ-นามสกุล / ข้อมูลติดต่อ</th>
                                <th class="p-6 text-[11px] font-black text-slate-400 uppercase tracking-widest">Username</th>
                                <th class="p-6 text-[11px] font-black text-slate-400 uppercase tracking-widest">บทบาท / ตำแหน่ง</th>
                                <th class="p-6 text-[11px] font-black text-slate-400 uppercase tracking-widest text-center">สถานะ</th>
                                <th class="p-6 text-[11px] font-black text-slate-400 uppercase tracking-widest text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50/50 transition duration-300">
                                <td class="p-6 pl-10">
                                    <div class="flex items-center gap-4">
                                        <div class="w-10 h-10 <?php echo $row['role_id'] == 1 ? 'bg-amber-100 text-amber-600' : 'bg-blue-100 text-blue-600'; ?> rounded-xl flex items-center justify-center font-bold">
                                            <i class="fa <?php echo $row['role_id'] == 1 ? 'fa-user-shield' : 'fa-user'; ?>"></i>
                                        </div>
                                        <div>
                                            <p class="font-black text-slate-800"><?php echo htmlspecialchars($row['full_name']); ?></p>
                                            <p class="text-[11px] text-slate-400 font-mono">
                                                <?php echo htmlspecialchars($row['phone'] ?: '-'); ?> 
                                                <?php echo !empty($row['email']) ? ' | ' . htmlspecialchars($row['email']) : ''; ?>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-6">
                                    <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-lg text-xs font-bold font-mono">@<?php echo htmlspecialchars($row['username']); ?></span>
                                </td>
                                <td class="p-6">
                                    <span class="inline-flex items-center gap-1.5 <?php echo $row['role_id'] == 1 ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-blue-700 bg-blue-50 border-blue-200'; ?> px-3 py-1 rounded-full text-[11px] font-bold border">
                                        <i class="fa <?php echo $row['role_id'] == 1 ? 'fa-star' : 'fa-briefcase'; ?> text-[10px]"></i>
                                        <?php echo $row['role_id'] == 1 ? 'Admin (ผู้จัดการ)' : 'Staff (พนักงาน)'; ?>
                                    </span>
                                </td>
                                <td class="p-6 text-center">
                                    <?php if((int)$row['is_active'] === 1): ?>
                                        <span class="inline-flex items-center gap-1 text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full text-[10px] font-bold border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> ใช้งานปกติ
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 text-rose-700 bg-rose-50 px-2.5 py-1 rounded-full text-[10px] font-bold border border-rose-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> ระงับการใช้งาน
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-6 text-center">
                                    <div class="flex justify-center items-center gap-2">
                                        <button type="button" 
                                                onclick='openEditModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8"); ?>)'
                                                title="แก้ไขข้อมูลและสิทธิ์" 
                                                class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-500 hover:text-white transition flex items-center justify-center shadow-sm">
                                            <i class="fa fa-edit text-xs"></i>
                                        </button>

                                        <?php if((int)$row['id'] !== (int)$_SESSION['user_id']): ?>
                                            <a href="?delete_id=<?php echo $row['id']; ?>" 
                                               onclick="return confirm('ยืนยันการลบผู้ใช้งานรายนี้?')"
                                               title="ลบพนักงาน" 
                                               class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white transition flex items-center justify-center shadow-sm">
                                                <i class="fa fa-trash-alt text-xs"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="w-8 h-8 flex items-center justify-center text-slate-300 text-xs italic" title="ไม่สามารถลบบัญชีที่กำลังล็อกอิน">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="5" class="p-16 text-center text-slate-300 font-bold uppercase tracking-widest">ไม่พบรายชื่อผู้ใช้งาน</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal เพิ่มพนักงานใหม่ -->
    <div id="addEmpModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl">
            <h3 class="text-lg font-black text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa fa-user-plus text-blue-500"></i> เพิ่มผู้ใช้งานระบบ
            </h3>
            <form action="manage_users.php" method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="add_employee" value="1">
                <div>
                    <label class="font-bold text-slate-600 block mb-1">ชื่อ-นามสกุล *</label>
                    <input type="text" name="full_name" required class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500 font-bold">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">ชื่อผู้ใช้ (Username) *</label>
                        <input type="text" name="username" required class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500 font-bold font-mono">
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">รหัสผ่าน (Password) *</label>
                        <input type="password" name="password" required class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500 font-bold">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">เบอร์โทรศัพท์</label>
                        <input type="text" name="phone" placeholder="08xxxxxxxx" class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500 font-mono">
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">อีเมล</label>
                        <input type="email" name="email" placeholder="example@email.com" class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">บทบาท / ตำแหน่ง *</label>
                        <select name="role_id" class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none font-bold">
                            <option value="2">Staff (พนักงานทั่วไป)</option>
                            <option value="1">Admin (ผู้จัดการ / แอดมิน)</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">สถานะใช้งาน *</label>
                        <select name="is_active" class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none font-bold">
                            <option value="1">ใช้งานปกติ</option>
                            <option value="0">ระงับการใช้งาน</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-4 border-t border-slate-100 mt-2">
                    <button type="button" onclick="document.getElementById('addEmpModal').classList.add('hidden')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow-md transition">บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal แก้ไขข้อมูลและสิทธิ์พนักงาน -->
    <div id="editEmpModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl">
            <h3 class="text-lg font-black text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa fa-edit text-amber-500"></i> แก้ไขข้อมูลและสิทธิ์พนักงาน
            </h3>
            <form action="manage_users.php" method="POST" class="space-y-3 text-xs">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="edit_employee" value="1">
                <input type="hidden" name="emp_id" id="edit_emp_id">

                <div>
                    <label class="font-bold text-slate-600 block mb-1">Username (ไม่สามารถเปลี่ยนได้)</label>
                    <input type="text" id="edit_username" disabled class="w-full p-2.5 bg-slate-100 border rounded-xl text-slate-400 font-bold font-mono cursor-not-allowed">
                </div>
                <div>
                    <label class="font-bold text-slate-600 block mb-1">ชื่อ-นามสกุล *</label>
                    <input type="text" name="full_name" id="edit_full_name" required class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500 font-bold text-slate-800">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">เบอร์โทรศัพท์</label>
                        <input type="text" name="phone" id="edit_phone" class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500 font-mono">
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">อีเมล</label>
                        <input type="email" name="email" id="edit_email" class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">บทบาท / ตำแหน่ง *</label>
                        <select name="role_id" id="edit_role_id" class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none font-bold text-slate-800">
                            <option value="2">Staff (พนักงานทั่วไป)</option>
                            <option value="1">Admin (ผู้จัดการ / แอดมิน)</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-600 block mb-1">สถานะใช้งาน *</label>
                        <select name="is_active" id="edit_is_active" class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none font-bold text-slate-800">
                            <option value="1">ใช้งานปกติ</option>
                            <option value="0">ระงับการใช้งาน</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="font-bold text-slate-600 block mb-1">เปลี่ยนรหัสผ่านใหม่ <span class="text-slate-400 font-normal">(เว้นว่างไว้ถ้าไม่ต้องการเปลี่ยน)</span></label>
                    <input type="password" name="password" placeholder="กรอกรหัสผ่านใหม่เมื่อต้องการเปลี่ยน..." class="w-full p-2.5 bg-slate-50 border rounded-xl outline-none focus:border-blue-500 font-bold">
                </div>
                <div class="flex justify-end gap-2 pt-4 border-t border-slate-100 mt-2">
                    <button type="button" onclick="document.getElementById('editEmpModal').classList.add('hidden')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xl shadow-md transition">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.toggle('-translate-x-full');
            document.getElementById('sidebarOverlay').classList.toggle('hidden');
        }

        function openEditModal(emp) {
            document.getElementById('edit_emp_id').value = emp.id;
            document.getElementById('edit_username').value = '@' + emp.username;
            document.getElementById('edit_full_name').value = emp.full_name;
            document.getElementById('edit_email').value = emp.email || '';
            document.getElementById('edit_phone').value = emp.phone || '';
            document.getElementById('edit_role_id').value = emp.role_id;
            document.getElementById('edit_is_active').value = emp.is_active;
            
            // ล็อกไม่ให้ Admin เปลี่ยน role_id และ is_active ของบัญชีที่ใช้อยู่ในปัจจุบัน
            const currentUserId = <?php echo (int)$_SESSION['user_id']; ?>;
            const roleSelect = document.getElementById('edit_role_id');
            const activeSelect = document.getElementById('edit_is_active');
            
            if (parseInt(emp.id) === currentUserId) {
                roleSelect.disabled = true;
                activeSelect.disabled = true;
            } else {
                roleSelect.disabled = false;
                activeSelect.disabled = false;
            }

            document.getElementById('editEmpModal').classList.remove('hidden');
        }
    </script>
</body>
</html>
