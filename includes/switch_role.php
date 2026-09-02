<?php
session_start();
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(isset($_POST['switch_role']) && isset($_POST['new_role'])) {
    $user_id = $_SESSION['user_id'];
    $new_role = $_POST['new_role'];
    
    if(switchRole($pdo, $user_id, $new_role)) {
        // Redirect based on new role
        $redirect_map = [
            'admin' => '../admin/dashboard.php',
            'exam_committee' => '../exam_committee/dashboard.php',
            'teacher' => '../teacher/dashboard.php',
            'student' => '../student/dashboard.php'
        ];
        
        header("Location: " . ($redirect_map[$new_role] ?? '../index.php'));
        exit();
    }
}

header("Location: ../index.php");
exit();
?>