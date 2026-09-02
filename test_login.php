<?php
require_once 'config/database.php';

echo "<h2>Login System Debugger</h2>";

// Check if users exist
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$userCount = $stmt->fetchColumn();

echo "<h3>Database Status:</h3>";
echo "Total users in database: " . $userCount . "<br>";

if($userCount == 0) {
    echo "<span style='color: red;'>❌ No users found! Please run fix_passwords.php first.</span><br>";
} else {
    echo "<span style='color: green;'>✅ Users exist in database</span><br>";
}

// List all users (without showing passwords)
$stmt = $pdo->query("SELECT id, username, role, status FROM users");
$users = $stmt->fetchAll();

echo "<h3>Users in Database:</h3>";
echo "<table border='1' cellpadding='8'>";
echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Status</th></tr>";
foreach($users as $user) {
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['username']}</td>";
    echo "<td>{$user['role']}</td>";
    echo "<td>{$user['status']}</td>";
    echo "</tr>";
}
echo "</table>";

// Test login functionality
echo "<h3>Testing Login:</h3>";

$test_credentials = [
    ['admin', 'Admin@123'],
    ['teacher1', 'Teacher@123'],
    ['exam_comm', 'Committee@123'],
    ['student1', 'Student@123']
];

foreach($test_credentials as $cred) {
    $username = $cred[0];
    $password = $cred[1];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if($user) {
        if(password_verify($password, $user['password'])) {
            echo "<span style='color: green;'>✅ SUCCESS: $username login works!</span><br>";
        } else {
            echo "<span style='color: red;'>❌ FAILED: $username - Password doesn't match!</span><br>";
            echo "Stored hash: " . substr($user['password'], 0, 30) . "...<br>";
        }
    } else {
        echo "<span style='color: red;'>❌ FAILED: User $username not found or inactive!</span><br>";
    }
}

// Option to reset all passwords
if(isset($_GET['reset'])) {
    echo "<h3>Resetting all passwords...</h3>";
    
    $new_users = [
        ['admin', 'Admin@123', 'System Administrator', 'admin@examsystem.com', 'admin'],
        ['teacher1', 'Teacher@123', 'John Smith', 'teacher@examsystem.com', 'teacher'],
        ['exam_comm', 'Committee@123', 'Sarah Johnson', 'committee@examsystem.com', 'exam_committee'],
        ['student1', 'Student@123', 'Mike Wilson', 'student@examsystem.com', 'student']
    ];
    
    foreach($new_users as $user) {
        $hashed = password_hash($user[1], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
        $stmt->execute([$hashed, $user[0]]);
        echo "Reset password for: {$user[0]}<br>";
    }
    
    echo "<span style='color: green;'>✅ All passwords reset! <a href='test_login.php'>Refresh page</a></span>";
}

echo "<br><hr>";
echo "<a href='?reset=1' style='background: #dc3545; color: white; padding: 10px; text-decoration: none;' onclick='return confirm(\"Reset all passwords?\")'>🔧 Reset All Passwords</a>";
echo "&nbsp;&nbsp;&nbsp;";
echo "<a href='index.php' style='background: #28a745; color: white; padding: 10px; text-decoration: none;'>🔐 Go to Login Page</a>";
?>