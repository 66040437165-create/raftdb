<?php
include 'db_config.php';

echo "--- TABLES ---\n";
$res = $conn->query("SHOW TABLES");
while($row = $res->fetch_array()) {
    $table = $row[0];
    echo "Table: $table\n";
    $res2 = $conn->query("DESCRIBE $table");
    while($row2 = $res2->fetch_assoc()) {
        echo "  " . $row2['Field'] . " (" . $row2['Type'] . ")\n";
    }
}
?>
