<?php
include 'db_config.php';

$sql1 = "ALTER TABLE bookings MODIFY COLUMN check_in DATETIME";
$sql2 = "ALTER TABLE bookings MODIFY COLUMN check_out DATETIME";

if ($conn->query($sql1) && $conn->query($sql2)) {
    echo "Database updated successfully: check_in and check_out changed to DATETIME.\n";
} else {
    echo "Error updating database: " . $conn->error . "\n";
}
?>
