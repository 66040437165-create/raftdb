<?php
require_once __DIR__ . '/db_config.php';

if (!$conn) {
    die("<h2 style='color:red;text-align:center;'>❌ ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบ DATABASE_URL บน Render</h2>");
}

// ชุดคำสั่ง SQL ที่แปลงเป็น PostgreSQL แล้ว (รองรับข้อมูลทั้งหมดจากระบบเก่า)
$sql = <<<SQL

-- ลบตารางเก่าทิ้งทั้งหมด (ถ้ามี) เพื่อสร้างใหม่ให้สมบูรณ์
DROP TABLE IF EXISTS payments, expenses, bookings, customers, rafts, raft_types, employees, payment_methods, settings CASCADE;

-- 1. สร้างตารางและข้อมูลประเภทแพ
CREATE TABLE raft_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO raft_types (id, name, description, created_at, updated_at) VALUES
(1, 'แพใหญ่มีห้องน้ำ', 'แพขนาดเล็ก รองรับ 5-10 คน', '2026-01-31 15:06:02', '2026-09-06 19:18:32'),
(2, 'แพใหญ่ไม่มีห้องน้ำ', 'แพขนาดกลาง รองรับ 10-20 คน', '2026-01-31 15:06:02', '2026-09-06 19:19:14');

-- 2. สร้างตารางและข้อมูลแพ
CREATE TABLE rafts (
    id SERIAL PRIMARY KEY,
    raft_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    raft_type_id INT NOT NULL REFERENCES raft_types(id),
    capacity INT NOT NULL DEFAULT 10,
    price_per_day DECIMAL(10,2) NOT NULL,
    price_per_hour DECIMAL(10,2) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'available',
    is_active SMALLINT DEFAULT 1,
    featured_image VARCHAR(255) DEFAULT NULL,
    image_1 VARCHAR(255) DEFAULT NULL,
    image_2 VARCHAR(255) DEFAULT NULL,
    image_3 VARCHAR(255) DEFAULT NULL,
    image_4 VARCHAR(255) DEFAULT NULL,
    image_5 VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO rafts (id, raft_code, name, raft_type_id, capacity, price_per_day, price_per_hour, description, status, is_active, featured_image, image_1, image_2, image_3, image_4, image_5, created_at, updated_at) VALUES
(11, 'RAFT-2609309', 'แพ1ปะโคใต้1', 1, 15, 1200.00, 200.00, '1.มีอุปกรณ์ชาร์จโทรศัพท์ 2.สระสำหรับเด็กบนแพ 3.โต๊ะทานข้าว 4.ห้องนํ้าในตัว 5.เสื้อชูชีพ', 'maintenance', 1, 'raft_1789207742_116.jpg', 'raft_11_img1_1789209692.jpg', 'raft_11_img2_1789209692.jpg', NULL, NULL, NULL, '2026-09-12 10:09:02', '2026-09-12 15:54:13'),
(12, 'RAFT-2609227', 'แพ3โคกปาฝาง1', 1, 15, 1200.00, 200.00, '1.มีอุปกรณ์ชาร์จโทรศัพท์ 2.สระสำหรับเด็กบนแพ 3.โต๊ะทานข้าว 4.ห้องนํ้าในตัว 5.เสื้อชูชีพ', 'available', 1, 'raft_1789228109_529.jpg', 'raft_12_img1_1789228300.jpg', 'raft_12_img2_1789228300.jpg', NULL, NULL, NULL, '2026-09-12 15:48:29', '2026-09-14 04:39:26'),
(13, 'RAFT-2609493', 'แพ8ปะโคใต้2', 1, 15, 1200.00, 200.00, '1.มีอุปกรณ์ชาร์จโทรศัพท์ 2.สระสำหรับเด็กบนแพ 3.โต๊ะทานข้าว 4.ห้องนํ้าในตัว 5.เสื้อชูชีพ', 'busy', 1, 'raft_1789228188_429.jpg', 'raft_13_img1_1789228337.jpg', 'raft_13_img2_1789228337.jpg', NULL, NULL, NULL, '2026-09-12 15:49:48', '2026-09-14 04:43:43'),
(14, 'RAFT-2609630', 'แพ9อุสาบารส2', 1, 15, 1200.00, 200.00, '1.มีอุปกรณ์ชาร์จโทรศัพท์ 2.สระสำหรับเด็กบนแพ 3.โต๊ะทานข้าว 4.ห้องนํ้าในตัว 5.เสื้อชูชีพ', 'available', 1, 'raft_1789228253_724.jpg', 'raft_14_img1_1789228357.jpg', 'raft_14_img2_1789228357.jpg', NULL, NULL, NULL, '2026-09-12 15:50:53', '2026-09-12 15:52:37'),
(15, 'RAFT-2609974', 'แพ 20 โคกป่าฝาง1', 1, 15, 1200.00, 200.00, 'มีอุปกรณ์ชาร์จโทรศัพท์,สระสำหรับเด็กบนแพ,โต๊ะทานข้าว,ห้องน้ำส่วนตัว,เสื้อชูชีพ,ชุดจานชามช้อนสำหรับใส่อาหาร,ถังน้ำแข็ง,เตาปิ้งย่าง,ครกสาก,แก้ว', 'available', 1, 'raft_1789300258_290.jpg', 'raft_15_img1_1789300300.jpg', 'raft_15_img2_1789300300.jpg', 'raft_15_img3_1789300300.jpg', NULL, NULL, '2026-09-13 11:50:58', '2026-09-13 11:54:16'),
(16, 'RAFT-2609991', 'แพ 10 โคกป่าฝาง2', 1, 15, 1200.00, 200.00, 'มีอุปกรณ์ชาร์จโทรศัพท์,สระสำหรับเด็กบนแพ,โต๊ะทานข้าว,ห้องน้ำส่วนตัว,เสื้อชูชีพ,ชุดจานชามช้อนสำหรับใส่อาหาร,ถังน้ำแข็ง,เตาปิ้งย่าง,ครกสาก,แก้ว', 'available', 1, 'raft_1789300443_428.jpg', 'raft_16_img1_1789300497.jpg', 'raft_16_img2_1789300497.jpg', 'raft_16_img3_1789300497.jpg', NULL, NULL, '2026-09-13 11:54:03', '2026-09-13 11:54:57'),
(17, 'RAFT-2609450', 'แพ 12 ไรเฟิล1', 1, 15, 1200.00, 200.00, 'มีอุปกรณ์ชาร์จโทรศัพท์,สระสำหรับเด็กบนแพ,โต๊ะทานข้าว,ห้องน้ำส่วนตัว,เสื้อชูชีพ,ชุดจานชามช้อนสำหรับใส่อาหาร,ถังน้ำแข็ง,เตาปิ้งย่าง,ครกสาก,แก้ว', 'available', 1, 'raft_1789300560_505.jpg', 'raft_17_img1_1789300587.jpg', 'raft_17_img2_1789300587.jpg', 'raft_17_img3_1789300587.jpg', NULL, NULL, '2026-09-13 11:56:00', '2026-09-13 11:56:27'),
(18, 'RAFT-2609971', 'แพ 13 ล่องแพหนองกวาก2', 1, 15, 1200.00, 200.00, 'มีอุปกรณ์ชาร์จโทรศัพท์,สระสำหรับเด็กบนแพ,โต๊ะทานข้าว,ห้องน้ำส่วนตัว,เสื้อชูชีพ,ชุดจานชามช้อนสำหรับใส่อาหาร,ถังน้ำแข็ง,เตาปิ้งย่าง,ครกสาก,แก้ว', 'available', 1, 'raft_1789300647_155.jpg', 'raft_18_img1_1789300672.jpg', 'raft_18_img2_1789300672.jpg', 'raft_18_img3_1789300672.jpg', NULL, NULL, '2026-09-13 11:57:27', '2026-09-13 11:57:52'),
(19, 'RAFT-2609796', 'แพ 17 นำโชค', 1, 15, 1200.00, 200.00, 'มีอุปกรณ์ชาร์จโทรศัพท์,สระสำหรับเด็กบนแพ,โต๊ะทานข้าว,ห้องน้ำส่วนตัว,เสื้อชูชีพ,ชุดจานชามช้อนสำหรับใส่อาหาร,ถังน้ำแข็ง,เตาปิ้งย่าง,ครกสาก,แก้ว', 'available', 1, 'raft_1789300737_761.jpg', 'raft_19_img1_1789300815.jpg', 'raft_19_img2_1789300815.jpg', 'raft_19_img3_1789300815.jpg', NULL, NULL, '2026-09-13 11:58:57', '2026-09-13 12:11:14'),
(20, 'RAFT-2609259', 'แพ 19 บ้านไร่2', 1, 15, 1200.00, 200.00, 'มีอุปกรณ์ชาร์จโทรศัพท์,สระสำหรับเด็กบนแพ,โต๊ะทานข้าว,ห้องน้ำส่วนตัว,เสื้อชูชีพ,ชุดจานชามช้อนสำหรับใส่อาหาร,ถังน้ำแข็ง,เตาปิ้งย่าง,ครกสาก,แก้ว', 'pending', 1, 'raft_1789300870_876.jpg', 'raft_20_img1_1789300895.jpg', 'raft_20_img2_1789300895.jpg', 'raft_20_img3_1789300895.jpg', NULL, NULL, '2026-09-13 12:01:10', '2026-09-14 05:05:50');

-- 3. สร้างตารางและข้อมูลแอดมิน
CREATE TABLE employees (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(150) UNIQUE DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    full_name VARCHAR(150) NOT NULL,
    role_id INT NOT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    line_id VARCHAR(100) DEFAULT NULL,
    is_active SMALLINT DEFAULT 1,
    last_login TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO employees (id, username, password, email, phone, full_name, role_id, avatar, line_id, is_active, last_login, created_at, updated_at) VALUES
(1, 'admin', '1234', NULL, NULL, 'ผู้ดูแลระบบ', 1, NULL, NULL, 1, '2026-09-29 14:24:38', '2026-09-06 10:58:23', '2026-09-29 14:24:38'),
(2, 'fern', '12345', NULL, '0923447772', 'เฟิร์น', 1, NULL, NULL, 1, '2026-09-26 10:55:49', '2026-09-06 15:37:53', '2026-09-26 10:55:49'),
(3, 'staff_new', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, '0899999999', 'พนักงานใหม่', 2, NULL, NULL, 1, NULL, '2026-09-26 10:54:16', '2026-09-26 10:54:16'),
(4, 'staff', '$2y$10$F9VhFrI3wA7hhPBiB4L9tO20p.5zrd6Q3VokoF/oES5G/YssLF.Hm', '', '55555555555', 'staff', 2, NULL, NULL, 1, '2026-09-26 10:57:21', '2026-09-26 10:57:08', '2026-09-26 10:57:21');

-- 4. สร้างตารางและข้อมูลลูกค้า
CREATE TABLE customers (
    id SERIAL PRIMARY KEY,
    user_id INT UNIQUE DEFAULT NULL REFERENCES employees(id) ON DELETE SET NULL,
    customer_code VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    line_id VARCHAR(100) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    id_card_number VARCHAR(20) DEFAULT NULL,
    total_bookings INT DEFAULT 0,
    total_spent DECIMAL(12,2) DEFAULT 0.00,
    membership_level VARCHAR(50) DEFAULT 'normal',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO customers (id, customer_code, full_name, phone, email, total_bookings, created_at, updated_at) VALUES
(1, 'CUST260906712', 'ก้อง', '0923477223', 'ddaa27735@gmail.com', 4, '2026-09-06 15:02:49', '2026-09-06 15:14:59'),
(2, 'CUST260907821', 'โคล', '1646854646', '4254274@5345345', 1, '2026-09-06 18:21:12', '2026-09-06 18:21:12'),
(3, 'CUST260907557', 'โร่', '5764564645', 'kjhkhjkhj@hfghf', 1, '2026-09-06 18:32:43', '2026-09-06 18:32:43'),
(4, 'CUST260907289', 'ดเ้ด', '5464645645', '', 1, '2026-09-06 18:34:50', '2026-09-06 18:34:50'),
(5, 'CUST260907291', 'า่า้่า่้', '4142453434', '', 1, '2026-09-06 18:36:45', '2026-09-06 18:36:45'),
(6, 'CUST260907384', '้เ้ด้ด้', '0542457565', '', 1, '2026-09-06 18:42:28', '2026-09-06 18:42:28'),
(7, 'CUST260907811', 'kk', '0123654899', '', 1, '2026-09-06 19:30:39', '2026-09-06 19:30:39'),
(8, 'CUST260912185', 'pp', '0810384818', '', 1, '2026-09-12 09:29:21', '2026-09-12 09:29:21'),
(10, 'CUST260912669', 'เฟิร์น', '0810325646', '', 1, '2026-09-12 15:55:09', '2026-09-12 15:55:09'),
(11, 'CUST260912965', 'ก้อง', '0211156666', '', 1, '2026-09-12 15:59:57', '2026-09-12 15:59:57'),
(12, 'CUST260913576', 'คุณศิ', '0641410753', 'sasithon041261111@gmail.com', 1, '2026-09-13 12:07:25', '2026-09-13 12:07:25'),
(15, 'CUST260914849', 'แต๋ม', '0235565556', '', 1, '2026-09-14 04:18:42', '2026-09-14 04:18:42'),
(16, 'CUST260914824', 'นน', '0234554455', '', 1, '2026-09-14 05:05:50', '2026-09-14 05:05:50');

-- 5. สร้างตารางการจอง
CREATE TABLE bookings (
    id SERIAL PRIMARY KEY,
    booking_code VARCHAR(30) NOT NULL UNIQUE,
    customer_id INT DEFAULT NULL,
    guest_name VARCHAR(255) DEFAULT NULL,
    guest_tel VARCHAR(50) DEFAULT NULL,
    guest_email VARCHAR(255) DEFAULT NULL,
    raft_id INT NOT NULL,
    status_id INT NOT NULL DEFAULT 1,
    booking_date DATE NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    check_in_time TIME DEFAULT NULL,
    check_out_time TIME DEFAULT NULL,
    total_guests INT NOT NULL DEFAULT 1,
    raft_price DECIMAL(10,2) NOT NULL,
    service_total DECIMAL(10,2) DEFAULT 0.00,
    discount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL,
    special_requests TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    confirmed_at TIMESTAMP NULL DEFAULT NULL,
    confirmed_by INT DEFAULT NULL,
    actual_check_in TIMESTAMP NULL DEFAULT NULL,
    actual_check_out TIMESTAMP NULL DEFAULT NULL,
    closed_at TIMESTAMP NULL DEFAULT NULL,
    closed_by INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO bookings (id, booking_code, customer_id, guest_name, guest_tel, guest_email, raft_id, status_id, booking_date, check_in_date, check_out_date, check_in_time, check_out_time, total_guests, raft_price, service_total, discount, total_amount, created_at, updated_at) VALUES
(12, 'BK260912513', 10, 'เฟิร์น', '0810325646', '', 12, 4, '2026-09-12', '2026-09-13', '2026-09-13', '09:00:00', '17:30:00', 15, 1200.00, 0.00, 0.00, 0.00, '2026-09-12 15:55:09', '2026-09-12 16:06:21'),
(13, 'BK260912781', 11, 'ก้อง', '0211156666', '', 13, 2, '2026-09-12', '2026-09-12', '2026-09-12', '09:00:00', '15:00:00', 15, 1200.00, 0.00, 0.00, 0.00, '2026-09-12 15:59:57', '2026-09-14 04:43:43'),
(14, 'BK260913740', 12, 'คุณศิ', '0641410753', 'sasithon041261111@gmail.com', 19, 4, '2026-09-13', '2026-09-27', '2026-09-27', '09:00:00', '14:00:00', 2, 1000.00, 0.00, 0.00, 0.00, '2026-09-13 12:07:25', '2026-09-13 12:11:14'),
(17, 'BK260914738', 15, 'แต๋ม', '0235565556', '', 12, 4, '2026-09-14', '2026-09-14', '2026-09-14', '09:00:00', '15:00:00', 2, 1200.00, 0.00, 0.00, 0.00, '2026-09-14 04:18:42', '2026-09-14 04:39:26'),
(18, 'BK260914337', 16, 'นน', '0234554455', '', 20, 1, '2026-09-14', '2026-09-14', '2026-09-14', '09:00:00', '15:00:00', 2, 1200.00, 0.00, 0.00, 0.00, '2026-09-14 05:05:50', '2026-09-14 05:05:50');

-- 6. สร้างตารางและข้อมูลรายจ่าย
CREATE TABLE expenses (
    expense_id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    expense_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO expenses (expense_id, title, amount, expense_date, created_at) VALUES
(2, 'ค่าห้องน้ำ ค่าล้างแพ', 50.00, '2026-09-12', '2026-09-12 15:31:32'),
(3, 'ค่าเรือลาก', 100.00, '2026-09-12', '2026-09-12 15:31:42'),
(4, 'ค่าไฟ', 200.00, '2026-09-12', '2026-09-12 15:32:12'),
(6, 'ค่าเรือลาก', 100.00, '2026-09-13', '2026-09-13 12:14:22'),
(7, 'ค่าดูแลรักษาท่าแพ', 200.00, '2026-09-13', '2026-09-13 12:14:48');

-- 7. สร้างตารางและข้อมูลช่องทางชำระเงิน
CREATE TABLE payment_methods (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) DEFAULT NULL,
    is_active SMALLINT DEFAULT 1,
    sort_order INT DEFAULT 0
);
INSERT INTO payment_methods (id, name, description, is_active, sort_order) VALUES
(1, 'cash', 'เงินสด', 1, 1),
(2, 'bank_transfer', 'โอนเงินผ่านธนาคาร', 1, 2),
(3, 'credit_card', 'บัตรเครดิต', 1, 3),
(4, 'promptpay', 'พร้อมเพย์', 1, 4),
(5, 'line_pay', 'LINE Pay', 1, 5);

-- 8. สร้างตารางและข้อมูลการชำระเงิน
CREATE TABLE payments (
    id SERIAL PRIMARY KEY,
    payment_code VARCHAR(50) DEFAULT NULL,
    booking_id INT NOT NULL,
    payment_method_id INT DEFAULT 1,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_type VARCHAR(50) DEFAULT 'full',
    status VARCHAR(50) DEFAULT 'pending',
    transaction_ref VARCHAR(100) DEFAULT NULL,
    slip_image VARCHAR(255) DEFAULT NULL,
    paid_at TIMESTAMP DEFAULT NULL,
    verified_at TIMESTAMP DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO payments (id, payment_code, booking_id, payment_method_id, amount, payment_type, status, slip_image, paid_at, verified_at, verified_by, notes, created_at, updated_at) VALUES
(1, 'PAY202609070005', 5, 1, 4000.00, 'full', 'confirmed', 'slip_5_1788719569.png', '2026-09-07 01:32:49', '2026-09-07 01:33:46', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-06 18:32:49', '2026-09-06 18:33:46'),
(2, 'PAY202609070006', 6, 1, 2000.00, 'full', 'confirmed', 'slip_6_1788719695.png', '2026-09-07 01:34:55', '2026-09-07 01:36:32', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-06 18:34:55', '2026-09-06 18:36:32'),
(3, 'PAY202609070007', 7, 1, 2000.00, 'full', 'confirmed', 'slip_7_1788719811.png', '2026-09-07 01:36:51', '2026-09-07 01:42:17', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-06 18:36:51', '2026-09-06 18:42:17'),
(4, 'PAY202609070008', 8, 1, 2000.00, 'full', 'pending', 'slip_8_1788720155.png', '2026-09-07 01:42:35', NULL, NULL, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-06 18:42:35', '2026-09-06 18:42:35'),
(5, 'PAY202609070009', 9, 1, 1200.00, 'full', 'confirmed', 'slip_9_1788723052.jpg', '2026-09-07 02:30:52', '2026-09-07 02:31:44', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-06 19:30:52', '2026-09-06 19:31:44'),
(6, 'PAY202609120010', 10, 1, 600.00, 'full', 'confirmed', 'slip_10_1789205376.jpg', '2026-09-12 16:29:36', '2026-09-12 16:30:37', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-12 09:29:36', '2026-09-12 09:30:37'),
(7, 'PAY202609120012', 12, 1, 1200.00, 'full', 'confirmed', 'slip_12_1789228523.jpg', '2026-09-12 22:55:23', '2026-09-12 23:03:19', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-12 15:55:23', '2026-09-12 16:03:19'),
(8, 'PAY202609120013', 13, 1, 1200.00, 'full', 'confirmed', 'slip_13_1789228807.jpg', '2026-09-12 23:00:07', '2026-09-14 11:43:43', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-12 16:00:07', '2026-09-14 04:43:43'),
(9, 'PAY202609130014', 14, 1, 1000.00, 'full', 'confirmed', 'slip_14_1789301263.jpg', '2026-09-13 19:07:43', '2026-09-13 19:08:10', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-13 12:07:43', '2026-09-13 12:08:10'),
(10, 'PAY202609140017', 17, 1, 1200.00, 'full', 'confirmed', 'slip_17_1789359549.jpg', '2026-09-14 11:19:09', '2026-09-14 11:19:58', 1, 'ธนาคาร/ช่องทางโอน: โอนเงิน/PromptPay', '2026-09-14 04:19:09', '2026-09-14 04:19:58');

-- 9. สร้างตารางและข้อมูลการตั้งค่าระบบ
CREATE TABLE settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO settings (setting_key, setting_value, updated_at) VALUES
('business_name', 'ล่องแพหนองกวาก', '2026-09-06 19:02:49'),
('close_time', '17:30', '2026-09-06 19:02:49'),
('open_time', '09:00', '2026-09-06 19:02:49')
ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value;

-- 10. อัปเดตตัวเลข Auto Increment ให้เป็นค่าล่าสุด เพื่อป้องกันปัญหา Insert ในอนาคต
SELECT setval('raft_types_id_seq', (SELECT MAX(id) FROM raft_types));
SELECT setval('rafts_id_seq', (SELECT MAX(id) FROM rafts));
SELECT setval('employees_id_seq', (SELECT MAX(id) FROM employees));
SELECT setval('customers_id_seq', (SELECT MAX(id) FROM customers));
SELECT setval('bookings_id_seq', (SELECT MAX(id) FROM bookings));
SELECT setval('expenses_expense_id_seq', (SELECT MAX(expense_id) FROM expenses));
SELECT setval('payment_methods_id_seq', (SELECT MAX(id) FROM payment_methods));
SELECT setval('payments_id_seq', (SELECT MAX(id) FROM payments));

SQL;

// รันคำสั่ง SQL ทีเดียวทั้งหมด
$result = @pg_query($conn, $sql);

if ($result) {
    echo "<div style='font-family:sans-serif; text-align:center; margin-top:50px; background-color:#e6ffe6; padding:30px; border-radius:10px; border:2px solid green; max-width: 600px; margin-left: auto; margin-right: auto;'>";
    echo "<h1 style='color:green;'>✅ นำเข้าข้อมูลระบบล่องแพสำเร็จ 100%!</h1>";
    echo "<p>โครงสร้างฐานข้อมูลและข้อมูลเดิมทั้งหมดของคุณถูกนำขึ้นเซิร์ฟเวอร์ Render เรียบร้อยแล้ว</p>";
    echo "<a href='login.php' style='display:inline-block; margin-top:20px; padding:15px 30px; background:#2563eb; color:white; text-decoration:none; border-radius:8px; font-weight:bold;'>กลับไปหน้าเข้าสู่ระบบ</a>";
    echo "</div>";
} else {
    echo "<div style='font-family:sans-serif; text-align:center; margin-top:50px; color:red;'>";
    echo "<h2>❌ เกิดข้อผิดพลาดในการสร้างตาราง</h2>";
    echo "<p>" . pg_last_error($conn) . "</p>";
    echo "</div>";
}
?>
