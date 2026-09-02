<?php
require_once 'config/database.php';

echo "<h2>Direct Insert Test</h2>";

// Get committee ID
$stmt = $pdo->query("SELECT id FROM users WHERE role = 'exam_committee' LIMIT 1");
$committee = $stmt->fetch();
$committee_id = $committee ? $committee['id'] : 1;

// Get a course
$stmt = $pdo->query("SELECT id FROM courses WHERE status = 'active' LIMIT 1");
$course = $stmt->fetch();

if(!$course) {
    die("No courses found! Please create a course first.");
}

$course_id = $course['id'];

// Test insert with simple values
$exam_name = "Test Exam " . date('Y-m-d H:i:s');
$exam_date = date('Y-m-d', strtotime('+7 days'));
$start_time = "10:00:00";
$end_time = "12:00:00";
$duration = 120;
$total_q = 10;
$total_m = 10;
$passing = 40;
$instructions = "Test instructions";

$sql = "INSERT INTO exam_schedules (
    exam_name, course_id, exam_date, start_time, end_time, 
    duration_minutes, total_questions, total_marks, passing_percentage, 
    instructions, created_by, status
) VALUES (
    '$exam_name', $course_id, '$exam_date', '$start_time', '$end_time', 
    $duration, $total_q, $total_m, $passing, 
    '$instructions', $committee_id, 'upcoming'
)";

echo "<p>SQL Query:</p>";
echo "<pre style='background: #f4f4f4; padding: 10px; overflow-x: auto;'>$sql</pre>";

try {
    $result = $pdo->exec($sql);
    echo "<p style='color: green;'>✅ Insert successful! Result: $result</p>";
    
    // Show the inserted record
    $last_id = $pdo->lastInsertId();
    $stmt = $pdo->query("SELECT * FROM exam_schedules WHERE id = $last_id");
    $record = $stmt->fetch();
    echo "<h3>Inserted Record:</h3>";
    echo "<pre>";
    print_r($record);
    echo "</pre>";
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Insert failed: " . $e->getMessage() . "</p>";
}
?>