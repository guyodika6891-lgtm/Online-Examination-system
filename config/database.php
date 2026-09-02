<?php
// Database configuration - UPDATED FOR PORT 3307
$host = 'localhost';
$port = '3307';  // Add this line - YOUR PORT NUMBER
$dbname = 'exam_system';
$username = 'root';
$password = '';  // Your MySQL password if any

// Create DSN with port number
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
    // Uncomment to test connection
    // echo "Connected successfully to database on port $port";
    
} catch(PDOException $e) {
    die("
    <div style='font-family: Arial; padding: 20px; max-width: 600px; margin: 50px auto; border: 1px solid #ddd; border-radius: 5px;'>
        <h2 style='color: #dc3545;'>Database Connection Error!</h2>
        <p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
        <hr>
        <h3>Port 3307 Configuration:</h3>
        <p>Make sure MySQL is running on port <strong>3307</strong></p>
        <p>Current settings:<br>
        Host: $host<br>
        Port: $port<br>
        Database: $dbname<br>
        Username: $username</p>
        <h3>How to Fix:</h3>
        <ol>
            <li>Check if MySQL is running on port 3307 in XAMPP/WAMP</li>
            <li>Or change the port number in this file to match your MySQL port (default is 3306)</li>
            <li>Verify MySQL service is running</li>
        </ol>
    </div>
    ");
}

session_start();

// Function to log activities
function logActivity($pdo, $user_id, $action, $description) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $description, $ip, $user_agent]);
    } catch(Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}
?>