<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/db_config.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        if ($conn) {
            $query = "SELECT id, username, password, full_name, role_id, is_active FROM employees WHERE username = $1 LIMIT 1";
            $result = @pg_query_params($conn, $query, array($username));

            if ($result) {
                if ($user = pg_fetch_assoc($result)) {
                    if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
                        $error = "บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ";
                    } else {
                        $is_valid_pw = ($password === $user['password']) || password_verify($password, $user['password']);

                        if ($is_valid_pw) {
                            $_SESSION['user_id']   = (int)$user['id'];
                            $_SESSION['username']  = $user['username'];
                            $_SESSION['fullname']  = $user['full_name'];
                            $_SESSION['role_id']   = (int)$user['role_id'];
                            $_SESSION['role']      = ((int)$user['role_id'] === 1) ? 'admin' : 'staff';

                            $update_query = "UPDATE employees SET last_login = NOW() WHERE id = $1";
                            @pg_query_params($conn, $update_query, array($user['id']));

                            header("Location: admin_dashboard.php");
                            exit();
                        } else {
                            $error = "รหัสผ่านไม่ถูกต้อง!";
                        }
                    }
                } else {
                    $error = "ไม่พบชื่อผู้ใช้งานนี้ในระบบ!";
                }
            } else {
                $error = "เกิดข้อผิดพลาดในการรันคำสั่ง: " . pg_last_error($conn);
            }
        } else {
            $error = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล";
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
    <script src="https://cdn.tailwindcss.com"></script&gt;
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css&quot; rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap&quot; rel="stylesheet">
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

        <div class="mt-8 text-center text-sm">
            <a href="index.php" class="text-gray-400 hover:text-gray-600">← กลับไปหน้าแรก</a>
        </div>
    </div>

</body>
</html>
