// --- AJAX API ENDPOINT ---
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    
    $raft_filter   = isset($_GET['raft_id']) ? intval($_GET['raft_id']) : 0;
    $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

    $where = [];
    $params = [];
    $p_idx = 1;

    // กรองตามแพ
    if ($raft_filter > 0) {
        $where[] = "b.raft_id = $" . $p_idx++;
        $params[] = $raft_filter;
    }

    // กรองตามสถานะ (ปรับให้ยืดหยุ่นรองรับทั้งชื่อสถานะและ status_id)
    if (!empty($status_filter)) {
        if ($status_filter === 'active' || $status_filter === 'confirmed') {
            $where[] = "(LOWER(b.status) IN ('confirmed', 'active', 'ยืนยันแล้ว') OR b.status_id = 2)";
        } elseif ($status_filter === 'pending') {
            $where[] = "(LOWER(b.status) IN ('pending', 'รอตรวจสอบ') OR b.status_id = 1)";
        } elseif ($status_filter === 'completed') {
            $where[] = "(LOWER(b.status) IN ('completed', 'เสร็จสิ้น') OR b.status_id = 5)";
        } elseif ($status_filter === 'cancelled') {
            $where[] = "(LOWER(b.status) IN ('cancelled', 'rejected', 'cancel', 'ยกเลิก') OR b.status_id IN (3, 4))";
        }
    }

    // 🟢 เอาการกรองที่เข้มงวดออก เพื่อให้ดึงข้อมูลการจองทั้งหมดขึ้นมาแสดงก่อน
    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    $sql = "SELECT b.*, 
                   COALESCE(b.id, 0) AS booking_id_clean,
                   COALESCE(r.name, '') AS raft_name, 
                   COALESCE(r.featured_image, '') AS raft_img, 
                   COALESCE(r.capacity, 0) AS raft_capacity,
                   COALESCE(c.full_name, b.guest_name, 'ลูกค้าทั่วไป') AS display_name,
                   COALESCE(c.phone, b.guest_tel, '-') AS display_tel,
                   COALESCE(c.email, b.guest_email, '-') AS display_email,
                   COALESCE(b.total_amount, b.total_price, b.raft_price, 0) AS final_price,
                   COALESCE(b.check_in_date, CAST(b.check_in AS DATE)) AS valid_check_in,
                   COALESCE(b.check_out_date, CAST(b.check_out AS DATE)) AS valid_check_out
            FROM bookings b 
            LEFT JOIN rafts r ON b.raft_id = r.id 
            LEFT JOIN customers c ON b.customer_id = c.id
            $where_sql 
            ORDER BY valid_check_in ASC";

    $events = [];

    if ($conn) {
        $result = !empty($params) ? @pg_query_params($conn, $sql, $params) : @pg_query($conn, $sql);

        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $check_in_date = !empty($row['valid_check_in']) ? date('Y-m-d', strtotime($row['valid_check_in'])) : date('Y-m-d');
                $check_in_time = !empty($row['check_in_time']) ? date('H:i', strtotime($row['check_in_time'])) : '09:00';
                $start_iso     = "{$check_in_date}T{$check_in_time}:00";

                $check_out_date = !empty($row['valid_check_out']) ? date('Y-m-d', strtotime($row['valid_check_out'])) : $check_in_date;
                $check_out_time = !empty($row['check_out_time']) ? date('H:i', strtotime($row['check_out_time'])) : '17:30';
                $end_iso        = "{$check_out_date}T{$check_out_time}:00";

                $raw_status = strtolower(trim($row['status'] ?? ''));
                $st_id = intval($row['status_id'] ?? 0);

                $color = '#3b82f6'; 
                $border_color = '#2563eb';
                $status_th = 'รอดำเนินการ';
                $status_key = 'pending';

                if ($raw_status === 'confirmed' || $raw_status === 'active' || $st_id === 2 || $raw_status === 'ยืนยันแล้ว') {
                    $color = '#10b981'; 
                    $border_color = '#059669';
                    $status_th = 'ยืนยันแล้ว (ติดจอง)';
                    $status_key = 'confirmed';
                } elseif ($raw_status === 'completed' || $raw_status === 'เสร็จสิ้น') {
                    $color = '#3b82f6'; 
                    $border_color = '#1d4ed8';
                    $status_th = 'เสร็จสิ้น';
                    $status_key = 'completed';
                } elseif ($raw_status === 'pending' || $st_id === 1 || $raw_status === 'รอตรวจสอบ') {
                    $color = '#f59e0b'; 
                    $border_color = '#d97706';
                    $status_th = 'รอตรวจสอบ';
                    $status_key = 'pending';
                } elseif ($raw_status === 'cancelled' || $raw_status === 'rejected' || in_array($st_id, [3, 4]) || $raw_status === 'ยกเลิก') {
                    $color = '#ef4444'; 
                    $border_color = '#b91c1c';
                    $status_th = 'ยกเลิกการจอง';
                    $status_key = 'cancelled';
                }

                $guest_name = $row['display_name'];
                $tel = $row['display_tel'];

                if (!$is_admin && strlen($tel) >= 9) {
                    $tel = substr($tel, 0, 3) . '***' . substr($tel, -3);
                }

                $b_id = $row['id'] ?? $row['booking_id_clean'];
                $b_code = !empty($row['booking_code']) ? $row['booking_code'] : ('BK' . str_pad($b_id, 6, '0', STR_PAD_LEFT));

                $events[] = [
                    'id' => $b_id,
                    'title' => '⛵ ' . ($row['raft_name'] ?: 'แพ') . ' (' . $guest_name . ')',
                    'start' => $start_iso,
                    'end' => $end_iso,
                    'backgroundColor' => $color,
                    'borderColor' => $border_color,
                    'textColor' => '#ffffff',
                    'extendedProps' => [
                        'booking_id' => $b_id,
                        'booking_code' => $b_code,
                        'raft_id' => $row['raft_id'] ?? 0,
                        'raft_name' => $row['raft_name'] ?: 'แพ',
                        'capacity' => $row['raft_capacity'] ?? 0,
                        'guest_name' => $guest_name,
                        'guest_tel' => $tel,
                        'guest_email' => $row['display_email'],
                        'total_price' => number_format((float)$row['final_price'], 2),
                        'status' => $status_key,
                        'status_th' => $status_th,
                        'check_in_formatted' => date('d/m/Y', strtotime($check_in_date)) . " {$check_in_time} น.",
                        'check_out_formatted' => date('d/m/Y', strtotime($check_out_date)) . " {$check_out_time} น.",
                        'check_in_raw' => $check_in_date
                    ]
                ];
            }
        }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE);
    exit();
}
