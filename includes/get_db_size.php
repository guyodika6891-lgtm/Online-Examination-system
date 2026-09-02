<?php
require_once '../config/database.php';

header('Content-Type: application/json');

$result = $pdo->query("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = DATABASE()");
$row = $result->fetch();
$size = round($row['size'] / 1024 / 1024, 2);

echo json_encode(['size' => $size . ' MB']);
?>