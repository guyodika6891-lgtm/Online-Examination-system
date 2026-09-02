<?php
require_once 'config/database.php';

echo "<h2>Exam Schedule Table Debug</h2>";

// Check if table exists
$stmt = $pdo->query("SHOW TABLES LIKE 'exam_schedules'");
if($stmt->rowCount() == 0) {
    echo "<p style='color: red;'>❌ Table 'exam_schedules' does NOT exist!</p>";
    echo "<h3>Creating table...</h3>";
    
    // Create the table
    $sql = "CREATE TABLE IF NOT EXISTS exam_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_name VARCHAR(200) NOT NULL,
        course_id INT NOT NULL,
        exam_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        duration_minutes INT DEFAULT 60,
        total_questions INT DEFAULT 0,
        total_marks INT DEFAULT 0,
        passing_percentage DECIMAL(5,2) DEFAULT 40,
        instructions TEXT,
        status VARCHAR(20) DEFAULT 'upcoming',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    $pdo->exec($sql);
    echo "<p style='color: green;'>✅ Table created successfully!</p>";
}

// Show table structure
$stmt = $pdo->query("DESCRIBE exam_schedules");
$columns = $stmt->fetchAll();

echo "<h3>Current Table Structure:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
foreach($columns as $col) {
    echo "<tr>";
    echo "<td>{$col['Field']}</td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "<td>{$col['Default']}</td>";
    echo "</tr>";
}
echo "</table>";

// Test a simple insert
echo "<h3>Testing Simple Insert:</h3>";

try {
    $test_sql = "INSERT INTO exam_schedules (exam_name, course_id, exam_date, start_time, end_time, duration_minutes, total_questions, total_marks, passing_percentage, created_by) 
                 VALUES ('Test Exam', 1, CURDATE(), '10:00', '12:00', 120, 10, 10, 40, 1)";
    
    $pdo->exec($test_sql);
    $last_id = $pdo->lastInsertId();
    echo "<p style='color: green;'>✅ Test insert successful! Inserted ID: $last_id</p>";
    
    // Clean up test
    $pdo->exec("DELETE FROM exam_schedules WHERE id = $last_id");
    echo "<p>✅ Test record removed</p>";
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Test insert failed: " . $e->getMessage() . "</p>";
}

echo "<br><a href='exam_committee/schedule_exams.php' class='btn-primary'>Go back to Schedule Exams</a>";
?>