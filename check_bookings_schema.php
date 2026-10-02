<?php
include 'db_config.php';
$res = $conn->query("DESCRIBE bookings");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . ': ' . $row['Type'] . "\n";
}
?>
