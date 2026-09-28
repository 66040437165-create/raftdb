<?php
// บังคับแสดง Error เพื่อความสะดวกในการตรวจสอบ
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/db_config.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        // ดึงข้อมูลพนักงานจากตาราง employees ตาม username
        $stmt = $conn->prepare("SELECT id, username, password, full_name, role_id, is_active FROM employees WHERE username = ? LIMIT 1");
        
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $user = $result->fetch_assoc()) {
                // เช็คสถานะการใช้งาน (0 = ระงับการใช้งาน)
                if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                    $error = "บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ";
                } else {
                    // ตรวจสอบรหัสผ่าน รองรับทั้งรหัสผ่านธรรมดา (plain text) และ password_hash
                    $is_valid_pw = ($password === $user['password']) || password_verify($password, $user['password']);

                    if ($is_valid_pw) {
                        // กำหนดค่าลง Session
                        $_SESSION['user_id']   = (int)$user['id'];
                        $_SESSION['username']  = $user['username'];
                        $_SESSION['fullname']  = $user['full_name'];
                        $_SESSION['role_id']   = (int)$user['role_id'];
                        $_SESSION['role']      = ((int)$user['role_id'] === 1) ? 'admin' : 'staff';

                        // บันทึกเวลาเข้าสู่ระบบล่าสุด (last_login)
                        $update_stmt = $conn->prepare("UPDATE employees SET last_login = NOW() WHERE id = ?");
                        if ($update_stmt) {
                            $update_stmt->bind_param("i", $user['id']);
                            $update_stmt->execute();
                            $update_stmt->close();
                        }

                        // ทั้ง Admin และ Staff ให้เข้าสู่ Dashboard หลังบ้าน
                        header("Location: backend/admin_dashboard.php");
                        exit();
                    } else {
                        $error = "รหัสผ่านไม่ถูกต้อง!";
                    }
                }
            } else {
                $error = "ไม่พบชื่อผู้ใช้งานนี้ในระบบ!";
            }
            $stmt->close();
        } else {
            $error = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $conn->error;
        }
    } else {
        $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน!";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ChillRaft</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; }</style>
</head>
<body class="bg-gradient-to-br from-blue-500 to-blue-700 min-h-screen flex items-center justify-center p-4">

    <div class="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-4xl mb-2">🌊</h1>
            <h2 class="text-2xl font-bold text-gray-800">เข้าสู่ระบบจัดการ</h2>
        </div>

        <?php if(!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-6 rounded text-sm font-bold">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="space-y-6">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อผู้ใช้งาน</label>
                <input type="text" name="username" required autocomplete="username" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-500 transition">
            </div>

            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">รหัสผ่าน</label>
                <input type="password" name="password" required autocomplete="current-password" class="w-full px-4 py-3 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-500 transition">
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl shadow-lg transition duration-300">
                เข้าสู่ระบบ
            </button>
        </form>

        <div class="relative flex py-5 items-center">
            <div class="flex-grow border-t border-gray-200"></div>
            <span class="flex-shrink mx-4 text-gray-400 text-xs">หรือ</span>
            <div class="flex-grow border-t border-gray-200"></div>
        </div>

        <a href="line_login.php" class="w-full bg-[#06C755] hover:bg-[#05b04b] text-white font-bold py-3.5 rounded-xl shadow-lg transition duration-300 flex items-center justify-center gap-2">
            <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24"><path d="M24 10.3c0-4.8-5.4-8.8-12-8.8S0 5.5 0 10.3c0 4.3 4.3 8 10.1 8.7.4.1.9.4.8.8l-.2 1.5c0 .3-.2.8.4.8.5 0 2.5-1.5 3.5-2.6 4.3-.5 9.4-4.2 9.4-8.7zm-14.7 3.5H7.7c-.4 0-.8-.4-.8-.8V8.7c0-.4.4-.8.8-.8s.8.4.8.8v2.7h1.6c.4 0 .8.4.8.8s-.4.8-.8.8zm3.2-.8c0 .4-.4.8-.8.8s-.8-.4-.8-.8V8.7c0-.4.4-.8.8-.8s.8.4.8.8v4.3zm5 0c0 .3-.1.5-.3.6-.1.1-.3.2-.5.2H15c-.4 0-.8-.4-.8-.8V8.7c0-.4.4-.8.8-.8s.8.4.8.8v3.5h1.7c.4 0 .8.4.8.8zm4-2.5c0 .4-.4.8-.8.8h-1.6v.9h1.6c.4 0 .8.4.8.8s-.4.8-.8.8h-2.4c-.4 0-.8-.4-.8-.8V8.7c0-.4.4-.8.8-.8h2.4c.4 0 .8.4.8.8s-.4.8-.8.8h-1.6V10h1.6c.4.1.8.5.8.5z"/></svg>
            เข้าสู่ระบบด้วย LINE
        </a>

        <div class="mt-8 text-center text-sm">
            <a href="index.php" class="text-gray-400 hover:text-gray-600">← กลับไปหน้าแรก</a>
        </div>
    </div>

</body>
</html>