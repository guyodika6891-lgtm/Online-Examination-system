<?php
require_once 'config/database.php';

echo "<h2>Complete Exam Schedules Fix</h2>";

try {
    // Step 1: Disable foreign key checks
    echo "<h3>Step 1: Disabling foreign key checks...</h3>";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✅ Foreign key checks disabled<br>";
    
    // Step 2: Check what tables reference exam_schedules
    echo "<h3>Step 2: Checking dependent tables...</h3>";
    
    $dependent_tables = [];
    $tables_to_check = ['exam_enrollments', 'results', 'exam_schedules_backup'];
    
    foreach($tables_to_check as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if($stmt->rowCount() > 0) {
            $dependent_tables[] = $table;
            echo "Found table: $table<br>";
        }
    }
    
    // Step 3: Backup existing data
    echo "<h3>Step 3: Creating backup...</h3>";
    
    // Check if backup already exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'exam_schedules_backup'");
    if($stmt->rowCount() > 0) {
        $pdo->exec("DROP TABLE exam_schedules_backup");
        echo "✅ Old backup removed<br>";
    }
    
    // Create backup of current data if exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'exam_schedules'");
    if($stmt->rowCount() > 0) {
        $pdo->exec("CREATE TABLE exam_schedules_backup AS SELECT * FROM exam_schedules");
        echo "✅ Data backed up to exam_schedules_backup<br>";
    }
    
    // Step 4: Drop dependent tables first
    echo "<h3>Step 4: Dropping dependent tables...</h3>";
    
    // Drop exam_enrollments if exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'exam_enrollments'");
    if($stmt->rowCount() > 0) {
        $pdo->exec("DROP TABLE IF EXISTS exam_enrollments");
        echo "✅ Dropped exam_enrollments table<br>";
    }
    
    // Drop results table if exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'results'");
    if($stmt->rowCount() > 0) {
        // Just remove foreign key constraint instead of dropping
        try {
            $pdo->exec("ALTER TABLE results DROP FOREIGN KEY results_ibfk_1");
            echo "✅ Removed foreign key from results table<br>";
        } catch(Exception $e) {
            echo "Note: " . $e->getMessage() . "<br>";
        }
    }
    
    // Step 5: Drop the exam_schedules table
    echo "<h3>Step 5: Dropping exam_schedules table...</h3>";
    $pdo->exec("DROP TABLE IF EXISTS exam_schedules");
    echo "✅ exam_schedules table dropped<br>";
    
    // Step 6: Create new exam_schedules table
    echo "<h3>Step 6: Creating new exam_schedules table...</h3>";
    
    $sql = "CREATE TABLE exam_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_name VARCHAR(200) NOT NULL,
        course_id INT NOT NULL,
        exam_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        duration_minutes INT DEFAULT 60,
        total_questions INT DEFAULT 0,
        total_marks INT DEFAULT 0,
        passing_percentage DECIMAL(5,2) DEFAULT 40.00,
        instructions TEXT,
        status VARCHAR(20) DEFAULT 'upcoming',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_course (course_id),
        INDEX idx_status (status),
        INDEX idx_date (exam_date)
    )";
    
    $pdo->exec($sql);
    echo "✅ New exam_schedules table created<br>";
    
    // Step 7: Restore data from backup if exists
    echo "<h3>Step 7: Restoring data from backup...</h3>";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'exam_schedules_backup'");
    if($stmt->rowCount() > 0) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM exam_schedules_backup");
        $backup_count = $stmt->fetch()['count'];
        
        if($backup_count > 0) {
            // Get columns from backup to avoid structure mismatch
            $stmt = $pdo->query("SHOW COLUMNS FROM exam_schedules_backup");
            $backup_columns = $stmt->fetchAll();
            
            $column_names = [];
            foreach($backup_columns as $col) {
                $column_names[] = $col['Field'];
            }
            
            // Only restore if columns match our new structure
            $common_columns = array_intersect($column_names, ['exam_name', 'course_id', 'exam_date', 'start_time', 'end_time', 'duration_minutes', 'total_questions', 'total_marks', 'passing_percentage', 'instructions', 'created_by']);
            
            if(!empty($common_columns)) {
                $columns_str = implode(', ', $common_columns);
                $pdo->exec("INSERT INTO exam_schedules ($columns_str) SELECT $columns_str FROM exam_schedules_backup");
                echo "✅ Restored $backup_count records from backup<br>";
            }
        }
    }
    
    // Step 8: Re-enable foreign key checks
    echo "<h3>Step 8: Re-enabling foreign key checks...</h3>";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✅ Foreign key checks re-enabled<br>";
    
    // Step 9: Verify the table
    echo "<h3>Step 9: Verifying table...</h3>";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'exam_schedules'");
    if($stmt->rowCount() > 0) {
        echo "✅ exam_schedules table exists<br>";
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM exam_schedules");
        $count = $stmt->fetch()['count'];
        echo "✅ Table has $count records<br>";
    }
    
    echo "<br><span style='color: green; font-weight: bold;'>✅ Complete fix successful!</span>";
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    
    // Emergency recovery
    echo "<h3>Emergency Recovery:</h3>";
    echo "<p>If the above failed, run this SQL manually in phpMyAdmin:</p>";
    
    $emergency_sql = "
    SET FOREIGN_KEY_CHECKS = 0;
    DROP TABLE IF EXISTS exam_enrollments;
    DROP TABLE IF EXISTS exam_schedules;
    CREATE TABLE exam_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_name VARCHAR(200) NOT NULL,
        course_id INT NOT NULL,
        exam_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        duration_minutes INT DEFAULT 60,
        total_questions INT DEFAULT 0,
        total_marks INT DEFAULT 0,
        passing_percentage DECIMAL(5,2) DEFAULT 40.00,
        instructions TEXT,
        status VARCHAR(20) DEFAULT 'upcoming',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    SET FOREIGN_KEY_CHECKS = 1;
    ";
    
    echo "<pre style='background: #f4f4f4; padding: 10px; overflow-x: auto;'>";
    echo htmlspecialchars($emergency_sql);
    echo "</pre>";
}

echo "<br><hr>";
echo "<a href='exam_committee/schedule_exams.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-right: 10px;'>Go to Schedule Exams →</a>";
echo "<a href='exam_committee/manage_exams.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Manage Exams →</a>";
?>