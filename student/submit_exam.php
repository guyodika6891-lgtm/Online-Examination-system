<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$schedule_id = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
$student_id = $_SESSION['user_id'];
$answers = isset($_POST['answers']) ? $_POST['answers'] : [];

if(empty($answers)) {
    header("Location: take_exam.php?error=no_answers");
    exit();
}

// Get exam details
$stmt = $pdo->prepare("SELECT * FROM exam_schedules WHERE id = ?");
$stmt->execute([$schedule_id]);
$exam = $stmt->fetch();

if(!$exam) {
    header("Location: take_exam.php?error=exam_not_found");
    exit();
}

// Get questions specifically assigned to this exam
$stmt = $pdo->prepare("
    SELECT q.* 
    FROM exam_questions eq
    JOIN questions q ON eq.question_id = q.id
    WHERE eq.exam_schedule_id = ?
");
$stmt->execute([$schedule_id]);
$questions = $stmt->fetchAll();

if(empty($questions)) {
    // Fallback to course questions
    $stmt = $pdo->prepare("
        SELECT * FROM questions 
        WHERE course_id = ? AND status = 'approved' 
        ORDER BY RAND() 
        LIMIT ?
    ");
    $stmt->execute([$exam['course_id'], $exam['total_questions']]);
    $questions = $stmt->fetchAll();
}

$score = 0;
$total_marks = 0;
$answers_json = [];

foreach($questions as $q) {
    $total_marks += $q['marks'];
    $user_answer = isset($answers[$q['id']]) ? $answers[$q['id']] : null;
    $answers_json[$q['id']] = $user_answer;
    
    if($user_answer && $user_answer == $q['correct_answer']) {
        $score += $q['marks'];
    }
}

$percentage = ($total_marks > 0) ? ($score / $total_marks) * 100 : 0;
$status = $percentage >= $exam['passing_percentage'] ? 'passed' : 'failed';
$passed = ($percentage >= $exam['passing_percentage']);

// Check if student already submitted this exam
$stmt = $pdo->prepare("SELECT id FROM results WHERE exam_schedule_id = ? AND student_id = ?");
$stmt->execute([$schedule_id, $student_id]);
$existing_result = $stmt->fetch();

if($existing_result) {
    // Already submitted, redirect to my_results page
    header("Location: my_results.php?error=already_submitted");
    exit();
}

// Save result for THIS student only
$stmt = $pdo->prepare("
    INSERT INTO results (exam_schedule_id, student_id, score, total_questions, total_marks, percentage, answers, submitted_at, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
");
$stmt->execute([$schedule_id, $student_id, $score, count($questions), $total_marks, $percentage, json_encode($answers_json), $status]);

// Get the inserted result ID
$result_id = $pdo->lastInsertId();

// Update enrollment status for THIS student only to 'completed'
$stmt = $pdo->prepare("UPDATE exam_enrollments SET status = 'completed' WHERE exam_schedule_id = ? AND student_id = ?");
$stmt->execute([$schedule_id, $student_id]);

logActivity($pdo, $student_id, 'exam_submitted', "Submitted exam: {$exam['exam_name']} - Score: $score/$total_marks ($percentage%)");

// Generate certificate if passed
if($passed) {
    try {
        // Get student details
        $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch();
        
        // Get course details
        $stmt = $pdo->prepare("
            SELECT c.course_name, c.course_code 
            FROM exam_schedules es
            JOIN courses c ON es.course_id = c.id
            WHERE es.id = ?
        ");
        $stmt->execute([$schedule_id]);
        $course = $stmt->fetch();
        
        // Create certificates directory
        $cert_dir = '../certificates/';
        if(!is_dir($cert_dir)) {
            mkdir($cert_dir, 0777, true);
        }
        
        // Generate certificate number
        $certificate_no = 'CERT-' . date('Y') . '-' . str_pad($student_id, 6, '0', STR_PAD_LEFT) . '-' . rand(1000, 9999);
        $verification_code = strtoupper(substr(md5($certificate_no . time()), 0, 10));
        
        // Check if certificate already exists
        $stmt = $pdo->prepare("SELECT id FROM certificates WHERE student_id = ? AND exam_schedule_id = ?");
        $stmt->execute([$student_id, $schedule_id]);
        
        if(!$stmt->fetch()) {
            // Save certificate to database
            $stmt = $pdo->prepare("
                INSERT INTO certificates (student_id, exam_schedule_id, certificate_no, verification_code, status, issue_date)
                VALUES (?, ?, ?, ?, 'issued', NOW())
            ");
            $stmt->execute([$student_id, $schedule_id, $certificate_no, $verification_code]);
        }
        
    } catch(Exception $e) {
        error_log("Certificate generation failed: " . $e->getMessage());
    }
}

// Auto-redirect to my_results page with the specific result ID
header("Location: my_results.php?submitted=1&result_id=" . $result_id);
exit();
?>