<?php
session_start();
require_once 'config/database.php';

if(isset($_SESSION['user_id'])) {
    logActivity($pdo, $_SESSION['user_id'], 'logout', 'User logged out');
}

session_destroy();
header("Location: index.php");
exit();
?>