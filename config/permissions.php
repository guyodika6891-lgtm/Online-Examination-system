<?php
// Role-based access control
function checkRole($allowed_roles) {
    if(!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: ../index.php");
        exit();
    }
    
    if(!in_array($_SESSION['role'], $allowed_roles)) {
        header("Location: ../dashboard.php?error=unauthorized");
        exit();
    }
}

function hasPermission($pdo, $user_id, $permission) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    $permissions = [
        'student' => ['view_exam', 'take_exam', 'view_own_results'],
        'teacher' => ['create_questions', 'edit_questions', 'delete_questions', 'view_results', 'grade_exams'],
        'exam_committee' => ['approve_questions', 'schedule_exams', 'generate_reports', 'manage_exams', 'view_all_results'],
        'admin' => ['manage_roles', 'manage_users', 'system_settings', 'view_logs', 'backup_data', 'all']
    ];
    
    return in_array($permission, $permissions[$user['role']] ?? []) || $user['role'] == 'admin';
}
?>