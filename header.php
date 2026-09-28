<?php
// เริ่มต้น Session เพื่อใช้เก็บข้อมูลการ Login (ถ้ามี)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db_config.php'; 
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .nav-link { transition: all 0.3s; border-bottom: 2px solid transparent; }
        .nav-link:hover { border-bottom: 2px solid #fbbf24; color: #fbbf24; }
    </style>
</head>
<body class="bg-gray-50">

    <nav class="bg-blue-600 text-white p-4 shadow-lg sticky top-0 z-50">
        <div class="container mx-auto flex justify-between items-center">
            <a href="index.php" class="text-2xl font-bold flex items-center hover:scale-105 transition-transform">
                <span class="mr-2 text-3xl">🌊</span> ChillRaft
            </a>

            <ul class="hidden md:flex space-x-8 font-medium">
                <li><a href="index.php" class="nav-link py-2">หน้าแรก</a></li>
                <li><a href="index.php#rafts" class="nav-link py-2">รายการแพ</a></li>
                <li><a href="booking_calendar.php" class="nav-link py-2">ปฏิทินการจอง</a></li>
                <li><a href="check_status.php" class="nav-link py-2">เช็คสถานะการจอง</a></li>
            </ul>

            <div class="flex items-center space-x-4">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <span class="text-sm border-r pr-4 border-blue-400">สวัสดี, <?php echo $_SESSION['fullname']; ?></span>
                    <a href="logout.php" class="text-sm hover:text-red-300">ออกจากระบบ</a>
                <?php else: ?>
                    <a href="login.php" class="bg-white text-blue-600 px-5 py-2 rounded-xl font-bold hover:bg-yellow-400 hover:text-white transition shadow-md">
                        <i class="fa fa-user-circle mr-1"></i> เข้าสู่ระบบ
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>