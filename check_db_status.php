<?php
require_once 'db_config.php';

// ตารางที่ระบบต้องการ
$required_tables = [
    'users'    => ['id', 'username', 'password', 'email', 'role', 'line_id'],
    'bookings' => ['id', 'user_id', 'raft_id', 'booking_date', 'status', 'total_price'],
    'rafts'    => ['id', 'name', 'capacity', 'price_per_person', 'status'],
    'settings' => ['setting_key', 'setting_value'],
];

$results = [];

foreach ($required_tables as $table => $expected_cols) {
    $row = ['table' => $table, 'exists' => false, 'columns' => [], 'missing_cols' => [], 'row_count' => 0];

    // ตรวจว่าตารางมีอยู่ไหม
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    if ($res && $res->num_rows > 0) {
        $row['exists'] = true;

        // ดึงคอลัมน์จริงจาก DB
        $cols_res = $conn->query("SHOW COLUMNS FROM `$table`");
        $actual_cols = [];
        while ($c = $cols_res->fetch_assoc()) {
            $actual_cols[] = $c['Field'];
        }
        $row['columns'] = $actual_cols;
        $row['missing_cols'] = array_diff($expected_cols, $actual_cols);

        // นับจำนวนแถว
        $cnt = $conn->query("SELECT COUNT(*) as c FROM `$table`");
        $row['row_count'] = $cnt ? (int)$cnt->fetch_assoc()['c'] : 0;
    }
    $results[] = $row;
}

// ดึงรายชื่อตารางทั้งหมดใน DB จริง
$all_tables = [];
$res_all = $conn->query("SHOW TABLES");
while ($t = $res_all->fetch_array()) {
    $all_tables[] = $t[0];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ตรวจสอบ Database</title>
<style>
  body { font-family: 'Sarabun', sans-serif; background: #0f172a; color: #e2e8f0; padding: 30px; }
  h1 { color: #60a5fa; margin-bottom: 5px; }
  .subtitle { color: #94a3b8; margin-bottom: 30px; }
  .card { background: #1e293b; border-radius: 12px; padding: 20px; margin-bottom: 16px; border: 1px solid #334155; }
  .ok   { border-left: 4px solid #22c55e; }
  .warn { border-left: 4px solid #f59e0b; }
  .err  { border-left: 4px solid #ef4444; }
  .badge { display:inline-block; padding:2px 10px; border-radius:99px; font-size:12px; font-weight:bold; }
  .badge-ok   { background:#14532d; color:#86efac; }
  .badge-warn { background:#451a03; color:#fcd34d; }
  .badge-err  { background:#450a0a; color:#fca5a5; }
  .table-name { font-size:18px; font-weight:bold; color:#f1f5f9; }
  .cols { font-size:13px; color:#94a3b8; margin-top:8px; }
  .col-chip { display:inline-block; background:#0f172a; border:1px solid #475569; border-radius:6px; padding:1px 8px; margin:2px; font-size:12px; }
  .missing-chip { background:#450a0a; border-color:#ef4444; color:#fca5a5; }
  .all-tables { background:#1e293b; border-radius:12px; padding:20px; margin-top:20px; border:1px solid #334155; }
  .db-info { background:#1e293b; border-radius:12px; padding:16px; margin-bottom:20px; border:1px solid #334155; display:flex; gap:30px; }
  .info-item { text-align:center; }
  .info-val { font-size:28px; font-weight:bold; color:#60a5fa; }
  .info-label { font-size:12px; color:#94a3b8; }
</style>
</head>
<body>
<h1>🔍 ตรวจสอบสถานะ Database</h1>
<p class="subtitle">ฐานข้อมูล: <code style="color:#60a5fa"><?= htmlspecialchars($dbname) ?></code> | เซิร์ฟเวอร์: <code style="color:#60a5fa">localhost</code></p>

<?php
$total_ok = 0; $total_warn = 0; $total_err = 0;
foreach ($results as $r) {
    if (!$r['exists']) $total_err++;
    elseif (!empty($r['missing_cols'])) $total_warn++;
    else $total_ok++;
}
?>
<div class="db-info">
  <div class="info-item"><div class="info-val" style="color:#22c55e"><?= $total_ok ?></div><div class="info-label">ตารางครบ ✓</div></div>
  <div class="info-item"><div class="info-val" style="color:#f59e0b"><?= $total_warn ?></div><div class="info-label">คอลัมน์ขาด ⚠</div></div>
  <div class="info-item"><div class="info-val" style="color:#ef4444"><?= $total_err ?></div><div class="info-label">ตารางหาย ✗</div></div>
  <div class="info-item"><div class="info-val" style="color:#a78bfa"><?= count($all_tables) ?></div><div class="info-label">ตารางทั้งหมดใน DB</div></div>
</div>

<?php foreach ($results as $r): 
    $cls = !$r['exists'] ? 'err' : (!empty($r['missing_cols']) ? 'warn' : 'ok');
?>
<div class="card <?= $cls ?>">
  <div style="display:flex; justify-content:space-between; align-items:center;">
    <span class="table-name">📋 <?= $r['table'] ?></span>
    <div style="display:flex;gap:8px;align-items:center;">
      <?php if ($r['exists']): ?>
        <span style="font-size:13px;color:#94a3b8"><?= $r['row_count'] ?> แถว</span>
        <span class="badge <?= empty($r['missing_cols']) ? 'badge-ok' : 'badge-warn' ?>">
          <?= empty($r['missing_cols']) ? '✓ ครบถ้วน' : '⚠ คอลัมน์ขาด' ?>
        </span>
      <?php else: ?>
        <span class="badge badge-err">✗ ไม่พบตาราง</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($r['exists']): ?>
  <div class="cols">
    <strong style="color:#cbd5e1">คอลัมน์:</strong><br>
    <?php foreach ($r['columns'] as $col): ?>
      <span class="col-chip <?= in_array($col, $r['missing_cols'] ?? []) ? 'missing-chip' : '' ?>"><?= $col ?></span>
    <?php endforeach; ?>
    <?php foreach ($r['missing_cols'] as $mc): ?>
      <span class="col-chip missing-chip">⚠ <?= $mc ?> (ขาด)</span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="all-tables">
  <strong style="color:#f1f5f9">📦 ตารางทั้งหมดที่มีใน Database `<?= $dbname ?>`:</strong><br><br>
  <?php foreach ($all_tables as $t): ?>
    <span class="col-chip"><?= $t ?></span>
  <?php endforeach; ?>
</div>

<p style="margin-top:20px;color:#475569;font-size:12px;">
  ⏰ ตรวจสอบเมื่อ: <?= date('d/m/Y H:i:s') ?> | 
  <a href="check_db_status.php" style="color:#60a5fa">🔄 รีเฟรช</a>
</p>
</body>
</html>
