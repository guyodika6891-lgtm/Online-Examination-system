<?php
require_once 'config/database.php';

echo "<h2>One-Time Database Fix</h2>";

// Drop and recreate the exam_schedules table with correct structure
try {
    // Backup existing data if any
    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_schedules_backup AS SELECT * FROM exam_schedules");
    echo "✅ Backup created<br>";
    
    // Drop the table
    $pdo->exec("DROP TABLE IF EXISTS exam_schedules");
    echo "✅ Old table dropped<br>";
    
    // Create fresh table
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
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    )";
    
    $pdo->exec($sql);
    echo "✅ Fresh table created<br>";
    
    echo "<p style='color: green; font-weight: bold;'>✅ Database fix complete! You can now schedule exams.</p>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

echo "<br><a href='exam_committee/schedule_exams.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Schedule Exams →</a>";
?>