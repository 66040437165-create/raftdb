<?php
session_start();
require_once __DIR__ . '/../db_config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$today = date('Y-m-d');
$this_month = date('Y-m');

$income_today = $conn->query("SELECT SUM(amount) as total FROM payments WHERE status = 'confirmed' AND DATE(paid_at) = '$today'")->fetch_assoc()['total'] ?? 0;
$expense_today = $conn->query("SELECT SUM(amount) as total FROM expenses WHERE expense_date = '$today'")->fetch_assoc()['total'] ?? 0;

$income_month = $conn->query("SELECT SUM(amount) as total FROM payments WHERE status = 'confirmed' AND paid_at LIKE '$this_month%'")->fetch_assoc()['total'] ?? 0;
$expense_month = $conn->query("SELECT SUM(amount) as total FROM expenses WHERE expense_date LIKE '$this_month%'")->fetch_assoc()['total'] ?? 0;

echo json_encode([
    'income_today' => number_format($income_today),
    'expense_today' => number_format($expense_today),
    'income_month' => number_format($income_month),
    'expense_month' => number_format($expense_month)
]);
?>