<?php
include 'db_config.php';
$res = $conn->query("DESCRIBE rafts");
while($row = $res->fetch_assoc()) {
    if($row['Field'] == 'status') {
        echo $row['Type'];
    }
}
?>
