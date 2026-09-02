<?php
require_once 'config/database.php';

echo "<h2>Testing Exam Schedule Insert</h2>";

// Get a course
$stmt = $pdo->query("SELECT id FROM courses WHERE status = 'active' LIMIT 1");
$course = $stmt->fetch();

if(!$course) {
    die("No courses found. Please create a course first.");
}

// Get committee member
$stmt = $pdo->query("SELECT id FROM users WHERE role = 'exam_committee' LIMIT 1");
$committee = $stmt->fetch();
$committee_id = $committee ? $committee['id'] : 1;

// Test insert
try {
    $sql = "INSERT INTO exam_schedules (exam_name, course_id, exam_date, start_time, end_time, duration_minutes, total_questions, total_marks, passing_percentage, created_by) 
            VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL 7 DAY), '10:00:00', '12:00:00', 120, 10, 10, 40, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['Test Exam', $course['id'], $committee_id]);
    
    $last_id = $pdo->lastInsertId();
    echo "<p style='color: green;'>✅ Success! Exam scheduled with ID: $last_id</p>";
    
    // Clean up
    $pdo->exec("DELETE FROM exam_schedules WHERE id = $last_id");
    echo "<p>✅ Test record removed</p>";
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Failed: " . $e->getMessage() . "</p>";
}
?>