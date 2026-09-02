<?php
require_once 'config/database.php';

echo "<h2>Complete Exam Schedule Fix</h2>";

// First, check and fix the status enum
try {
    echo "<h3>1. Fixing Status Field...</h3>";
    
    // Check current enum values
    $stmt = $pdo->query("SHOW COLUMNS FROM exam_schedules LIKE 'status'");
    $column = $stmt->fetch();
    
    if($column) {
        echo "Current status field: " . $column['Type'] . "<br>";
        
        // Modify enum to ensure correct values
        $pdo->exec("ALTER TABLE exam_schedules 
                    MODIFY COLUMN status ENUM('upcoming', 'ongoing', 'completed', 'cancelled') 
                    DEFAULT 'upcoming'");
        echo "✅ Status field fixed<br>";
    }
    
} catch(PDOException $e) {
    echo "Error fixing status: " . $e->getMessage() . "<br>";
}

// Step 2: Test insert with proper values
echo "<h3>2. Testing Insert with Correct Format...</h3>";

// Get a valid course
$stmt = $pdo->query("SELECT id, course_code FROM courses WHERE status = 'active' LIMIT 1");
$course = $stmt->fetch();

if(!$course) {
    echo "❌ No active courses found!<br>";
    echo "Please create a course first in admin/manage_courses.php<br>";
} else {
    echo "Using course: {$course['course_code']} (ID: {$course['id']})<br>";
    
    // Get committee member
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'exam_committee' LIMIT 1");
    $committee = $stmt->fetch();
    $committee_id = $committee ? $committee['id'] : 1;
    
    // Test insert with properly quoted values
    $exam_name = "Test Exam " . date('Y-m-d H:i:s');
    $course_id = $course['id'];
    $exam_date = date('Y-m-d', strtotime('+7 days'));
    $start_time = "10:00:00";
    $end_time = "12:00:00";
    $duration = 120;
    $total_questions = 10;
    $total_marks = 10;
    $passing = 40;
    $instructions = "This is a test exam";
    $status = "upcoming";
    
    // Method 1: Using prepared statement (SAFEST)
    try {
        $sql = "INSERT INTO exam_schedules (
            exam_name, course_id, exam_date, start_time, end_time, 
            duration_minutes, total_questions, total_marks, passing_percentage, 
            instructions, created_by, status
        ) VALUES (
            :exam_name, :course_id, :exam_date, :start_time, :end_time, 
            :duration, :total_q, :total_m, :passing, 
            :instructions, :created_by, :status
        )";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':exam_name' => $exam_name,
            ':course_id' => $course_id,
            ':exam_date' => $exam_date,
            ':start_time' => $start_time,
            ':end_time' => $end_time,
            ':duration' => $duration,
            ':total_q' => $total_questions,
            ':total_m' => $total_marks,
            ':passing' => $passing,
            ':instructions' => $instructions,
            ':created_by' => $committee_id,
            ':status' => $status
        ]);
        
        if($result) {
            $last_id = $pdo->lastInsertId();
            echo "✅ <span style='color: green;'>Test insert successful! ID: $last_id</span><br>";
            
            // Clean up test
            $pdo->exec("DELETE FROM exam_schedules WHERE id = $last_id");
            echo "✅ Test record removed<br>";
        } else {
            echo "❌ Insert failed<br>";
        }
    } catch(PDOException $e) {
        echo "❌ Insert failed: " . $e->getMessage() . "<br>";
    }
}

echo "<br><hr>";
echo "<a href='exam_committee/schedule_exams.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Schedule Exams →</a>";
?>