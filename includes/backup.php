<?php
require_once '../config/database.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if($action == 'create') {
    $backup_file = '../backups/backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    // Create backups directory if not exists
    if(!is_dir('../backups')) {
        mkdir('../backups', 0777, true);
    }
    
    // Get all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $sql = "";
    
    foreach($tables as $table) {
        $result = $pdo->query("SELECT * FROM $table");
        $num_fields = $result->columnCount();
        
        $sql .= "DROP TABLE IF EXISTS $table;\n";
        $row2 = $pdo->query("SHOW CREATE TABLE $table")->fetch();
        $sql .= $row2[1] . ";\n\n";
        
        while($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $sql .= "INSERT INTO $table VALUES(";
            for($j=0; $j<$num_fields; $j++) {
                $row_val = isset($row[$j]) ? addslashes($row[$j]) : '';
                $sql .= '"' . $row_val . '"';
                if($j < ($num_fields-1)) $sql .= ',';
            }
            $sql .= ");\n";
        }
        $sql .= "\n\n";
    }
    
    file_put_contents($backup_file, $sql);
    
    echo json_encode(['success' => true, 'filename' => basename($backup_file)]);
    
} elseif($action == 'download') {
    $backup_dir = '../backups/';
    $files = glob($backup_dir . '*.sql');
    if(!empty($files)) {
        $latest = max($files);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($latest) . '"');
        readfile($latest);
    }
}
?>