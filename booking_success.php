<?php
session_start();
require_once __DIR__ . '/db_config.php';

// 1. ตรวจสอบว่ามี ID ส่งมาไหม
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$booking_id = intval($_GET['id']);
$booking = null;

// 2. ดึงข้อมูลการจอง + ข้อมูลแพ (PostgreSQL Syntax)
if ($conn) {
    $sql = "
        SELECT b.*, 
               COALESCE(r.name, '') AS raft_name, 
               r.price_per_day, 
               r.price_per_hour, 
               r.featured_image,
               r.capacity,
               c.full_name AS customer_name,
               c.phone AS customer_phone,
               c.email AS customer_email
        FROM bookings b
        LEFT JOIN rafts r ON b.raft_id = r.id
        LEFT JOIN customers c ON b.customer_id = c.id
        WHERE b.id = $1
        LIMIT 1
    ";
    
    $res = @pg_query_params($conn, $sql, array($booking_id));
    if ($res && pg_num_rows($res) > 0) {
        $booking = pg_fetch_assoc($res);
    }
}

if (!$booking) {
    echo "<div style='text-align:center; padding:50px; font-family:sans-serif;'>
            <h2>ไม่พบข้อมูลการจอง</h2>
            <a href='index.php'>กลับหน้าหลัก</a>
          </div>";
    exit();
}

// 3. ดึงข้อมูลสลิปจากตาราง payments (ถ้ามีตาราง payments)
$payment = null;
if ($conn) {
    $chk_tbl = @pg_query($conn, "SELECT to_regclass('public.payments')");
    $has_tbl = ($chk_tbl && ($r_tbl = pg_fetch_row($chk_tbl)) && !empty($r_tbl[0]));
    
    if ($has_tbl) {
        $pay_res = @pg_query_params($conn, "SELECT * FROM payments WHERE booking_id = $1 ORDER BY id DESC LIMIT 1", array($booking_id));
        if ($pay_res && pg_num_rows($pay_res) > 0) {
            $payment = pg_fetch_assoc($pay_res);
        }
    }
}

// กำหนดตัวแปรข้อมูลสำหรับแสดงผล
$b_id = $booking['id'] ?? $booking_id;
$booking_code = !empty($booking['booking_code']) ? $booking['booking_code'] : ('BK' . str_pad($b_id, 6, '0', STR_PAD_LEFT));
$guest_name   = !empty($booking['customer_name']) ? $booking['customer_name'] : ($booking['guest_name'] ?? 'ลูกค้า');
$guest_tel    = !empty($booking['customer_phone']) ? $booking['customer_phone'] : ($booking['guest_tel'] ?? '-');
$guest_email  = !empty($booking['customer_email']) ? $booking['customer_email'] : ($booking['guest_email'] ?? '-');

$check_in_date = $booking['check_in_date'] ?? (!empty($booking['check_in']) ? date('Y-m-d', strtotime($booking['check_in'])) : date('Y-m-d'));
$check_in_time = !empty($booking['check_in_time']) ? date('H:i', strtotime($booking['check_in_time'])) : (!empty($booking['check_in']) ? date('H:i', strtotime($booking['check_in'])) : '09:00');

$check_out_date = $booking['check_out_date'] ?? (!empty($booking['check_out']) ? date('Y-m-d', strtotime($booking['check_out'])) : $check_in_date);
$check_out_time = !empty($booking['check_out_time']) ? date('H:i', strtotime($booking['check_out_time'])) : (!empty($booking['check_out']) ? date('H:i', strtotime($booking['check_out'])) : '17:30');

// ดึงยอดชำระเงิน (รองรับ total_amount, total_price, raft_price)
$total_price = floatval($booking['total_amount'] ?? $booking['total_price'] ?? $booking['raft_price'] ?? 0);
$slip_img = $payment['slip_image'] ?? $booking['slip_image'] ?? '';

// สถานะการจอง (รองรับทั้ง status_id = 2 หรือ status = 'confirmed')
$is_confirmed = (isset($booking['status_id']) && (int)$booking['status_id'] === 2) || (isset($booking['status']) && $booking['status'] === 'confirmed');

// ฟังก์ชันแปลงวันที่เป็นภาษาไทย
function thai_date_short($date_str) {
    if (!$date_str) return '-';
    $timestamp = strtotime($date_str);
    $thai_months = array(
        1 => "ม.ค.", 2 => "ก.พ.", 3 => "มี.ค.", 4 => "เม.ย.", 5 => "พ.ค.", 6 => "มิ.ย.",
        7 => "ก.ค.", 8 => "ส.ค.", 9 => "ก.ย.", 10 => "ต.ค.", 11 => "พ.ย.", 12 => "ธ.ค."
    );
    $d = date('j', $timestamp);
    $m = $thai_months[(int)date('n', $timestamp)];
    $y = date('Y', $timestamp) + 543;
    return "$d $m $y";
}

// -------------------------------------------------------------------------
// จัดเตรียมข้อความรายละเอียดเพื่อส่งเข้า LINE ร้านค้า (@906kkkfr)
$raft_display_name = !empty($booking['raft_name']) ? $booking['raft_name'] : ('แพ #' . ($booking['raft_id'] ?? ''));
$line_text = "สวัสดีครับ ขอแจ้งรายละเอียดการจองแพครับ 🛶\n";
$line_text .= "━━━━━━━━━━━━━━━━\n";
$line_text .= "📋 รหัสการจอง: {$booking_code}\n";
$line_text .= "👤 ชื่อผู้จอง: {$guest_name}\n";
$line_text .= "📞 เบอร์โทร: {$guest_tel}\n";
$line_text .= "⛵ แพที่จอง: {$raft_display_name}\n";
$line_text .= "📅 วันที่เข้าพัก: " . thai_date_short($check_in_date) . " ({$check_in_time} น.)\n";
$line_text .= "💰 ยอดรวมทั้งสิ้น: ฿" . number_format($total_price, 2) . "\n";
$line_text .= "━━━━━━━━━━━━━━━━\n";
$line_text .= "✨ รบกวนตรวจสอบและยืนยันการจองด้วยครับ";

$line_oa_id = "@906kkkfr"; 
$encoded_line_text = urlencode($line_text);
$line_redirect_url = "https://line.me/R/oaMessage/{$line_oa_id}/?{$encoded_line_text}";
// -------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จองสำเร็จ - ล่องแพหนองกวาก</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen py-10 px-4">

    <div class="max-w-3xl mx-auto">
        <div class="text-center mb-10">
            <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-4 shadow-sm animate-bounce">
                <i class="fa fa-check text-4xl text-emerald-500"></i>
            </div>
            <h1 class="text-3xl font-black text-gray-800">ส่งคำขอจองสำเร็จ!</h1>
            <p class="text-gray-500 mt-2 font-bold">ขอบคุณที่ไว้วางใจล่องแพหนองกวากกับเรา</p>
        </div>

        <div class="bg-white rounded-[2.5rem] shadow-xl overflow-hidden border border-gray-100">
            <!-- Header ส่วนแสดงรหัสจอง -->
            <div class="bg-slate-900 p-8 text-center text-white relative overflow-hidden">
                <div class="relative z-10">
                    <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mb-1">รหัสการจอง (Booking Code)</p>
                    <p class="text-4xl font-black text-blue-400 tracking-wider"><?php echo htmlspecialchars($booking_code); ?></p>
                    
                    <?php if (!empty($slip_img)): ?>
                        <?php if ($is_confirmed): ?>
                            <div class="inline-block bg-emerald-500/20 text-emerald-300 text-[11px] px-4 py-1.5 rounded-full font-bold uppercase mt-3 border border-emerald-500/30">
                                <i class="fa fa-check-circle mr-1"></i> ยืนยันการจองเรียบร้อยแล้ว
                            </div>
                        <?php else: ?>
                            <div class="inline-block bg-amber-500/20 text-amber-300 text-[11px] px-4 py-1.5 rounded-full font-bold uppercase mt-3 border border-amber-500/30">
                                <i class="fa fa-clock mr-1"></i> แนบสลิปแล้ว - รอเจ้าหน้าที่ตรวจสอบ
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="inline-block bg-rose-500/20 text-rose-300 text-[11px] px-4 py-1.5 rounded-full font-bold uppercase mt-3 border border-rose-500/30">
                            <i class="fa fa-exclamation-circle mr-1"></i> ยังไม่ได้แนบสลิปชำระเงิน
                        </div>
                    <?php endif; ?>
                </div>
                <div class="absolute top-0 right-0 -mr-10 -mt-10 w-40 h-40 bg-blue-600 rounded-full blur-3xl opacity-20"></div>
                <div class="absolute bottom-0 left-0 -ml-10 -mb-10 w-40 h-40 bg-emerald-600 rounded-full blur-3xl opacity-20"></div>
            </div>

            <div class="p-8 md:p-12">
                <!-- ข้อมูลลูกค้า -->
                <div class="mb-8 p-6 bg-slate-50 rounded-3xl border border-slate-100">
                    <h3 class="text-gray-800 font-bold mb-4 flex items-center">
                        <i class="fa fa-user-circle text-blue-500 mr-2"></i> ข้อมูลผู้จอง
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-gray-400 text-xs">ชื่อ-นามสกุล</p>
                            <p class="font-bold text-gray-700 text-base"><?php echo htmlspecialchars($guest_name); ?></p>
                        </div>
                        <div>
                            <p class="text-gray-400 text-xs">เบอร์โทรศัพท์</p>
                            <p class="font-bold text-gray-700 text-base"><?php echo htmlspecialchars($guest_tel); ?></p>
                        </div>
                        <div class="md:col-span-2">
                            <p class="text-gray-400 text-xs">อีเมล</p>
                            <p class="font-bold text-gray-700"><?php echo htmlspecialchars($guest_email); ?></p>
                        </div>
                    </div>
                </div>

                <!-- รายละเอียดการจอง -->
                <div class="mb-10">
                    <h3 class="text-gray-800 font-bold mb-4 flex items-center">
                        <i class="fa fa-ship text-blue-500 mr-2"></i> รายละเอียดแพที่จอง
                    </h3>
                    <div class="flex items-start gap-4 mb-6">
                        <div class="w-24 h-24 rounded-2xl overflow-hidden shadow-md shrink-0 bg-slate-100 border border-slate-200">
                            <?php if(!empty($booking['featured_image'])): ?>
                                <img src="uploads/<?php echo htmlspecialchars($booking['featured_image']); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-slate-400 text-2xl"><i class="fa fa-ship"></i></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h4 class="text-xl font-black text-gray-800"><?php echo htmlspecialchars($raft_display_name); ?></h4>
                            <p class="text-gray-500 text-xs mt-1"><i class="fa fa-users mr-1"></i> รองรับสูงสุด <?php echo $booking['capacity'] ?? '-'; ?> ท่าน</p>
                            <p class="text-blue-600 font-bold text-sm mt-1">ราคาเหมาวัน: ฿<?php echo number_format($booking['price_per_day'] ?? 0); ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-blue-50 p-4 rounded-2xl border border-blue-100">
                            <p class="text-blue-500 text-[10px] font-black uppercase">วันและเวลาเช็คอิน</p>
                            <p class="text-blue-950 font-bold text-lg"><?php echo thai_date_short($check_in_date); ?></p>
                            <p class="text-blue-600 text-sm font-semibold"><?php echo $check_in_time; ?> น.</p>
                        </div>
                        <div class="bg-rose-50 p-4 rounded-2xl border border-rose-100">
                            <p class="text-rose-500 text-[10px] font-black uppercase">วันและเวลาเช็คเอาท์</p>
                            <p class="text-rose-950 font-bold text-lg"><?php echo thai_date_short($check_out_date); ?></p>
                            <p class="text-rose-600 text-sm font-semibold"><?php echo $check_out_time; ?> น.</p>
                        </div>
                    </div>
                </div>

                <!-- ยอดชำระ -->
                <div class="border-t-2 border-dashed border-gray-100 pt-6 mb-8">
                    <div class="flex justify-between items-end">
                        <p class="text-gray-500 font-bold mb-1">ยอดชำระทั้งหมด</p>
                        <p class="text-4xl font-black text-blue-600">฿<?php echo number_format($total_price, 2); ?></p>
                    </div>
                </div>

                <!-- ช่องทางการชำระเงิน -->
                <div class="space-y-6 mb-8">
                    <h3 class="text-gray-800 font-bold flex items-center">
                        <i class="fa fa-wallet text-blue-500 mr-2"></i> ช่องทางการชำระเงิน
                    </h3>

                    <!-- 1. QR Code PromptPay -->
                    <div class="bg-white border-2 border-blue-100 rounded-3xl p-6 text-center shadow-sm relative overflow-hidden">
                        <div class="absolute top-0 right-0 bg-blue-600 text-white text-[10px] px-3 py-1 rounded-bl-xl font-bold uppercase">แนะนำ</div>
                        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mb-4">สแกน QR Code เพื่อชำระเงิน</p>
                        
                        <div class="bg-white p-2 inline-block rounded-xl border border-gray-100 shadow-inner mb-4">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=00020101021129370016A000000677010111011300668912345675802TH530376463041254" 
                                 alt="PromptPay QR Code" class="w-48 h-48 mx-auto opacity-90">
                        </div>
                        
                        <p class="font-bold text-blue-900 text-lg">ล่องแพหนองกวาก</p>
                        <p class="text-gray-400 text-sm">PromptPay ID: 089-123-4567</p>
                    </div>

                    <!-- 2. บัญชีธนาคาร -->
                    <div class="space-y-3">
                        <p class="text-gray-400 text-xs font-bold uppercase tracking-widest pl-2">หรือเลือกโอนผ่านบัญชีธนาคาร</p>
                        
                        <div class="bg-green-50 p-4 rounded-2xl border border-green-100 flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-green-600 rounded-full flex items-center justify-center text-white font-bold text-xs shadow-md">KBANK</div>
                                <div>
                                    <p class="text-xs text-green-800 font-bold uppercase">ธนาคารกสิกรไทย</p>
                                    <p class="font-black text-gray-700">012-3-45678-9</p>
                                </div>
                            </div>
                            <button onclick="navigator.clipboard.writeText('012-3-45678-9')" class="text-gray-400 hover:text-green-600 transition p-2"><i class="fa fa-copy text-lg"></i></button>
                        </div>

                        <div class="bg-purple-50 p-4 rounded-2xl border border-purple-100 flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-purple-600 rounded-full flex items-center justify-center text-white font-bold text-xs shadow-md">SCB</div>
                                <div>
                                    <p class="text-xs text-purple-800 font-bold uppercase">ธนาคารไทยพาณิชย์</p>
                                    <p class="font-black text-gray-700">987-6-54321-0</p>
                                </div>
                            </div>
                            <button onclick="navigator.clipboard.writeText('987-6-54321-0')" class="text-gray-400 hover:text-purple-600 transition p-2"><i class="fa fa-copy text-lg"></i></button>
                        </div>
                    </div>

                    <!-- 3. ส่วนแนบสลิป -->
                    <div class="border-t-2 border-dashed border-gray-100 pt-8">
                        <h3 class="text-gray-800 font-bold mb-4 flex items-center">
                            <i class="fa fa-file-invoice text-blue-500 mr-2"></i> หลักฐานการโอนเงิน
                        </h3>

                        <?php if (!empty($slip_img)): ?>
                            <div class="bg-emerald-50 border border-emerald-200 rounded-3xl p-6 mb-6 shadow-sm">
                                <div class="flex items-center justify-between border-b border-emerald-200/60 pb-3 mb-4">
                                    <span class="text-emerald-800 font-extrabold text-sm flex items-center gap-2">
                                        <i class="fa fa-check-circle text-emerald-500 text-lg"></i> แนบสลิปเรียบร้อยแล้ว
                                    </span>
                                    <span class="bg-emerald-200/60 text-emerald-800 text-xs px-3 py-1 rounded-full font-bold">
                                        <?php echo $is_confirmed ? 'อนุมัติแล้ว' : 'รอการตรวจสอบ'; ?>
                                    </span>
                                </div>
                                <div class="text-center">
                                    <img src="uploads/slips/<?php echo htmlspecialchars($slip_img); ?>" class="max-h-56 mx-auto rounded-2xl shadow-md border-2 border-white">
                                </div>
                            </div>
                        <?php else: ?>
                            <form action="save_payment.php" method="POST" enctype="multipart/form-data" class="mb-8 space-y-4 bg-slate-50 p-6 rounded-3xl border border-slate-200/80 shadow-inner">
                                <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                                
                                <div>
                                    <label class="block text-xs font-extrabold text-slate-700 mb-2 uppercase tracking-wide">
                                        <i class="fa fa-image text-blue-500 mr-1"></i> แนบรูปไฟล์สลิปโอนเงิน <span class="text-red-500">*</span>
                                    </label>
                                    <input type="file" name="payment_slip" accept="image/*" required
                                           class="block w-full text-sm text-slate-500
                                                 file:mr-4 file:py-2.5 file:px-5
                                                 file:rounded-xl file:border-0
                                                 file:text-xs file:font-bold
                                                 file:bg-blue-600 file:text-white
                                                 hover:file:bg-blue-700 cursor-pointer bg-white p-2 rounded-2xl border border-slate-200">
                                </div>
                                
                                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-extrabold py-3.5 rounded-2xl shadow-lg shadow-blue-200 transition text-sm flex items-center justify-center gap-2">
                                    <i class="fa fa-check-circle mr-1"></i> ยืนยันการแจ้งชำระเงิน
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- เมนูตัวเลือกด้านล่าง -->
                        <div class="flex flex-col gap-3">
                            <a href="<?php echo $line_redirect_url; ?>" target="_blank" class="w-full bg-[#06C755] hover:bg-[#05b34c] text-white py-4 rounded-2xl font-bold text-center shadow-lg shadow-emerald-100 transition flex items-center justify-center gap-2">
                                <i class="fab fa-line text-2xl"></i> ส่งรายละเอียดและแจ้งโอนเงินผ่าน LINE
                            </a>
                            
                            <a href="index.php" class="bg-gray-100 text-gray-600 w-full py-4 rounded-2xl font-bold text-center hover:bg-gray-200 transition">
                                <i class="fa fa-arrow-left mr-2"></i> กลับหน้าหลัก
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
