<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';

// ตรวจสอบสิทธิ์ (ต้องล็อกอินและเป็น Admin หรือมีสิทธิ์จัดการหลังบ้าน)
$is_admin = (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') ||
            (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1);

if (!isset($_SESSION['user_id']) || !$is_admin) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$last_id = 0;
$pending_count = 0;

if ($conn) {
    // 1. ดึง ID ล่าสุดของรายการจอง (รองรับทั้งคอลัมน์ id หรือ booking_id)
    $res_last = @pg_query($conn, "SELECT COALESCE(MAX(id), 0) as last_id FROM bookings");
    if ($res_last && $row_last = pg_fetch_assoc($res_last)) {
        $last_id = intval($row_last['last_id']);
    }

    // 2. ดึงจำนวนรายการที่รอตรวจสอบ (status_id = 1 หรือ status = 'pending')
    $sql_pending = "SELECT COUNT(*) as pending_count FROM bookings WHERE status_id = 1 OR status = 'pending'";
    $res_pending = @pg_query($conn, $sql_pending);
    if ($res_pending && $row_pending = pg_fetch_assoc($res_pending)) {
        $pending_count = intval($row_pending['pending_count']);
    }

    echo json_encode([
        'status' => 'success',
        'last_id' => $last_id,
        'pending_count' => $pending_count
    ]);
    exit();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}
