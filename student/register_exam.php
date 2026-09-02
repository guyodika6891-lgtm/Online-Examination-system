<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($exam_id) {
    // Check if already registered
    $stmt = $pdo->prepare("SELECT * FROM exam_enrollments WHERE exam_schedule_id = ? AND student_id = ?");
    $stmt->execute([$exam_id, $student_id]);
    
    if(!$stmt->fetch()) {
        // Register student
        $stmt = $pdo->prepare("INSERT INTO exam_enrollments (exam_schedule_id, student_id, status) VALUES (?, ?, 'registered')");
        $stmt->execute([$exam_id, $student_id]);
        logActivity($pdo, $student_id, 'exam_registered', "Registered for exam ID: $exam_id");
        
        // Check if registration was successful
        $stmt = $pdo->prepare("SELECT * FROM exam_enrollments WHERE exam_schedule_id = ? AND student_id = ?");
        $stmt->execute([$exam_id, $student_id]);
        if($stmt->fetch()) {
            $_SESSION['registration_success'] = "Successfully registered for the exam!";
        }
    } else {
        $_SESSION['registration_error'] = "You are already registered for this exam!";
    }
}

header("Location: take_exam.php");
exit();
?>