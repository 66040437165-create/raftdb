<?php
session_start();
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$msg = '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 2. ดึงข้อมูลเดิม
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND role != 'admin'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();

if (!$user) {
    header("Location: manage_customers.php");
    exit();
}

// 3. ระบบอัปเดตข้อมูล
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = $conn->real_escape_string($_POST['fullname']);
    $username = $conn->real_escape_string($_POST['username']);
    $tel = $conn->real_escape_string($_POST['tel']);
    $role = $conn->real_escape_string($_POST['role']);
    $new_password = $_POST['new_password'];

    // เช็ค username ซ้ำ (ยกเว้นของตัวเอง)
    $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
    $check_stmt->bind_param("si", $username, $user_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        $msg = 'error_username_exists';
    } else {
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET fullname = ?, username = ?, tel = ?, role = ?, password = ? WHERE user_id = ?");
            $update_stmt->bind_param("sssssi", $fullname, $username, $tel, $role, $hashed_password, $user_id);
        } else {
            $update_stmt = $conn->prepare("UPDATE users SET fullname = ?, username = ?, tel = ?, role = ? WHERE user_id = ?");
            $update_stmt->bind_param("ssssi", $fullname, $username, $tel, $role, $user_id);
        }

        if ($update_stmt->execute()) {
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
    <title>แก้ไขข้อมูลสมาชิก - ChillRaft Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 flex min-h-screen">

    <!-- Sidebar -->
    <aside<?php include 'sidebar.php'; ?>
    </aside>

    <main class="flex-grow flex flex-col min-w-0">
        <header class="bg-white shadow-sm p-4 px-10 flex justify-between items-center sticky top-0 z-30">
            <h1 class="text-2xl font-bold text-gray-800">แก้ไขข้อมูลสมาชิก</h1>
        </header>

        <div class="p-10 max-w-4xl mx-auto w-full">
            <?php if($msg == 'error_username_exists'): ?>
                <div class="bg-rose-500 text-white p-4 rounded-2xl mb-6 shadow-md flex items-center gap-3">
                    <i class="fa fa-exclamation-circle text-xl"></i>
                    <span class="font-bold">ชื่อผู้ใช้งานนี้มีคนใช้แล้ว!</span>
                </div>
            <?php endif; ?>

            <form method="POST" class="bg-white rounded-[2.5rem] shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-10 space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <!-- Full Name -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1">ชื่อ-นามสกุล</label>
                            <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname']); ?>" required
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                        </div>

                        <!-- Username -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1">ชื่อผู้ใช้งาน (Username)</label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                        </div>

                        <!-- Telephone -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1">เบอร์โทรศัพท์</label>
                            <input type="tel" name="tel" value="<?php echo htmlspecialchars($user['tel']); ?>" required
                                   maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)"
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition">
                        </div>

                        <!-- Role -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1">บทบาท (Role)</label>
                            <select name="role" class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-blue-500 outline-none font-bold text-slate-800 transition appearance-none">
                                <option value="customer" <?php if($user['role'] == 'customer' || empty($user['role'])) echo 'selected'; ?>>ลูกค้า (Customer)</option>
                                <option value="employee" <?php if($user['role'] == 'employee') echo 'selected'; ?>>พนักงาน (Employee)</option>
                            </select>
                        </div>

                        <!-- Password (Optional) -->
                        <div class="space-y-2">
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest ml-1 text-rose-400">เปลี่ยนรหัสผ่าน (เว้นว่างได้)</label>
                            <input type="password" name="new_password" placeholder="ระบุรหัสผ่านใหม่..."
                                   class="w-full p-4 bg-slate-50 rounded-2xl border-none focus:ring-2 focus:ring-rose-500 outline-none font-bold text-slate-800 transition">
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 p-8 border-t border-gray-100 flex justify-between items-center">
                    <a href="manage_customers.php" class="text-slate-400 font-bold hover:text-slate-600 transition">ยกเลิก</a>
                    <button type="submit" class="bg-blue-600 text-white px-10 py-4 rounded-2xl font-bold shadow-lg hover:bg-blue-700 transition">
                        บันทึกการเปลี่ยนแปลง
                    </button>
                </div>
            </form>
        </div>
    </main>
</body>
</html>
