<?php
session_start();
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error']);
    exit();
}

$result = $conn->query("SELECT MAX(booking_id) as last_id, COUNT(*) as pending_count FROM bookings");
$data = $result->fetch_assoc();

echo json_encode([
    'status' => 'success',
    'last_id' => $data['last_id'] ?? 0,
    'pending_count' => $data['pending_count'] ?? 0
]);
?>
