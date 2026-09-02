<?php
$host = 'localhost';
$port = '3307';
$username = 'root';
$password = '';
$dbname = 'exam_system';

echo "<h2>Multi-Role System Installation</h2>";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 1: Create user_roles table
    echo "<p>1. Creating user_roles table...</p>";
    $sql = "CREATE TABLE IF NOT EXISTS user_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        role ENUM('student', 'teacher', 'exam_committee', 'admin') NOT NULL,
        assigned_by INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('active', 'inactive') DEFAULT 'active',
        deactivated_at TIMESTAMP NULL,
        deactivated_by INT NULL,
        UNIQUE KEY unique_user_role (user_id, role)
    )";
    $pdo->exec($sql);
    echo "<span style='color:green'>✓ user_roles table created</span><br>";
    
    // Step 2: Migrate existing users
    echo "<p>2. Migrating existing users...</p>";
    $pdo->exec("INSERT IGNORE INTO user_roles (user_id, role, assigned_by, assigned_at)
                SELECT id, role, 1, created_at FROM users WHERE role IS NOT NULL");
    echo "<span style='color:green'>✓ Existing roles migrated</span><br>";
    
    // Step 3: Add primary_role column to users
    echo "<p>3. Adding primary_role column...</p>";
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS primary_role VARCHAR(50) NULL");
    echo "<span style='color:green'>✓ primary_role column added</span><br>";
    
    // Step 4: Set primary roles
    echo "<p>4. Setting primary roles...</p>";
    $pdo->exec("UPDATE users u SET u.primary_role = (
        SELECT role FROM user_roles ur 
        WHERE ur.user_id = u.id 
        ORDER BY FIELD(role, 'admin', 'exam_committee', 'teacher', 'student') 
        LIMIT 1
    )");
    echo "<span style='color:green'>✓ Primary roles set</span><br>";
    
    // Step 5: Create role_permissions table
    echo "<p>5. Creating role_permissions table...</p>";
    $sql2 = "CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role VARCHAR(50) NOT NULL,
        permission VARCHAR(100) NOT NULL,
        UNIQUE KEY unique_role_permission (role, permission)
    )";
    $pdo->exec($sql2);
    echo "<span style='color:green'>✓ role_permissions table created</span><br>";
    
    // Step 6: Insert permissions
    echo "<p>6. Inserting role permissions...</p>";
    $permissions = [
        'student' => ['view_dashboard', 'take_exam', 'view_own_results', 'update_profile'],
        'teacher' => ['view_dashboard', 'create_questions', 'edit_questions', 'delete_questions', 'view_question_bank', 'view_student_results'],
        'exam_committee' => ['view_dashboard', 'approve_questions', 'schedule_exams', 'manage_exams', 'generate_reports', 'view_all_results'],
        'admin' => ['view_dashboard', 'manage_users', 'manage_roles', 'manage_courses', 'manage_enrollments', 'system_settings', 'view_logs', 'backup_database']
    ];
    
    foreach($permissions as $role => $perms) {
        foreach($perms as $perm) {
            try {
                $stmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)");
                $stmt->execute([$role, $perm]);
            } catch(Exception $e) {}
        }
    }
    echo "<span style='color:green'>✓ Permissions inserted</span><br>";
    
    // Step 7: Create role_switching_log table
    echo "<p>7. Creating role switching log table...</p>";
    $sql3 = "CREATE TABLE IF NOT EXISTS role_switching_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        previous_role VARCHAR(50),
        new_role VARCHAR(50),
        switched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45)
    )";
    $pdo->exec($sql3);
    echo "<span style='color:green'>✓ Role switching log created</span><br>";
    
    echo "<hr>";
    echo "<h3 style='color:green'>✅ Multi-Role System Installed Successfully!</h3>";
    echo "<p>Now you can:</p>";
    echo "<ul>";
    echo "<li>Assign multiple roles to users</li>";
    echo "<li>Users can switch between roles</li>";
    echo "<li>Each role has specific permissions</li>";
    echo "</ul>";
    
    echo "<br><a href='admin/manage_roles.php' style='background:#28a745;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>→ Go to Role Management</a>";
    echo "&nbsp;&nbsp;&nbsp;";
    echo "<a href='index.php' style='background:#667eea;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>→ Go to Login</a>";
    
} catch(PDOException $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>