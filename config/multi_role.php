<?php
require_once 'database.php';

// Get all roles for a user
function getUserRoles($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT ur.role, ur.status, ur.assigned_at,
               u.full_name as assigned_by_name
        FROM user_roles ur
        LEFT JOIN users u ON ur.assigned_by = u.id
        WHERE ur.user_id = ? AND ur.status = 'active'
        ORDER BY FIELD(ur.role, 'admin', 'exam_committee', 'teacher', 'student')
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Check if user has a specific role
function hasRole($pdo, $user_id, $role) {
    $stmt = $pdo->prepare("
        SELECT id FROM user_roles 
        WHERE user_id = ? AND role = ? AND status = 'active'
    ");
    $stmt->execute([$user_id, $role]);
    return $stmt->rowCount() > 0;
}

// Get user's current active role from session
function getCurrentRole() {
    return isset($_SESSION['current_role']) ? $_SESSION['current_role'] : (isset($_SESSION['primary_role']) ? $_SESSION['primary_role'] : 'student');
}

// Switch user's active role
function switchRole($pdo, $user_id, $new_role) {
    if(hasRole($pdo, $user_id, $new_role)) {
        $old_role = isset($_SESSION['current_role']) ? $_SESSION['current_role'] : null;
        $_SESSION['current_role'] = $new_role;
        
        // Log role switching
        $stmt = $pdo->prepare("
            INSERT INTO role_switching_log (user_id, previous_role, new_role, ip_address) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $old_role, $new_role, $_SERVER['REMOTE_ADDR'] ?? null]);
        
        return true;
    }
    return false;
}

// Get user's primary role for display
function getPrimaryRole($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT role FROM user_roles 
        WHERE user_id = ? AND status = 'active'
        ORDER BY FIELD(role, 'admin', 'exam_committee', 'teacher', 'student')
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result ? $result['role'] : 'student';
}

// Get available roles for user to switch to
function getAvailableRoles($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT role FROM user_roles 
        WHERE user_id = ? AND status = 'active'
        ORDER BY FIELD(role, 'admin', 'exam_committee', 'teacher', 'student')
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Assign a role to a user
function assignRole($pdo, $user_id, $role, $assigned_by) {
    // Check if role already exists
    $stmt = $pdo->prepare("SELECT id FROM user_roles WHERE user_id = ? AND role = ?");
    $stmt->execute([$user_id, $role]);
    
    if($stmt->rowCount() > 0) {
        // Reactivate if inactive
        $stmt = $pdo->prepare("UPDATE user_roles SET status = 'active', deactivated_at = NULL, deactivated_by = NULL WHERE user_id = ? AND role = ?");
        return $stmt->execute([$user_id, $role]);
    } else {
        // Assign new role
        $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role, assigned_by) VALUES (?, ?, ?)");
        return $stmt->execute([$user_id, $role, $assigned_by]);
    }
}

// Remove a role from a user
function removeRole($pdo, $user_id, $role, $deactivated_by) {
    $stmt = $pdo->prepare("
        UPDATE user_roles 
        SET status = 'inactive', deactivated_at = NOW(), deactivated_by = ? 
        WHERE user_id = ? AND role = ?
    ");
    return $stmt->execute([$deactivated_by, $user_id, $role]);
}
?>