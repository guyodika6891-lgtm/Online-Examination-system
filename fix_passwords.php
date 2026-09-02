<?php
require_once 'config/database.php';

// Function to hash password properly
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

echo "<h2>Fixing User Passwords</h2>";

// First, clear existing users (optional - be careful!)
// $pdo->exec("DELETE FROM users WHERE role != 'admin'");

// Insert/Update users with correct passwords
$users = [
    ['admin', 'Admin@123', 'System Administrator', 'admin@examsystem.com', 'admin'],
    ['teacher1', 'Teacher@123', 'John Smith', 'teacher@examsystem.com', 'teacher'],
    ['exam_comm', 'Committee@123', 'Sarah Johnson', 'committee@examsystem.com', 'exam_committee'],
    ['student1', 'Student@123', 'Mike Wilson', 'student@examsystem.com', 'student']
];

foreach($users as $user) {
    $username = $user[0];
    $password = hashPassword($user[1]);
    $full_name = $user[2];
    $email = $user[3];
    $role = $user[4];
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    
    if($stmt->fetch()) {
        // Update existing user
        $stmt = $pdo->prepare("UPDATE users SET password = ?, full_name = ?, email = ? WHERE username = ?");
        $stmt->execute([$password, $full_name, $email, $username]);
        echo "✅ Updated user: $username<br>";
    } else {
        // Insert new user
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$username, $password, $full_name, $email, $role]);
        echo "✅ Created user: $username<br>";
    }
}

echo "<br><h3>All users have been fixed! You can now login with:</h3>";
echo "<ul>";
echo "<li><strong>Admin:</strong> admin / Admin@123</li>";
echo "<li><strong>Teacher:</strong> teacher1 / Teacher@123</li>";
echo "<li><strong>Exam Committee:</strong> exam_comm / Committee@123</li>";
echo "<li><strong>Student:</strong> student1 / Student@123</li>";
echo "</ul>";
echo "<a href='index.php'>Go to Login Page →</a>";
?>