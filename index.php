<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'config/multi_role.php';

// Check if user is already logged in
if(isset($_SESSION['user_id'])) {
    $current_role = getCurrentRole();
    $redirect_map = [
        'admin' => 'admin/dashboard.php',
        'exam_committee' => 'exam_committee/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        'student' => 'student/dashboard.php'
    ];
    header("Location: " . ($redirect_map[$current_role] ?? 'student/dashboard.php'));
    exit();
}

$error = '';
$success = '';
$reset_error = '';
$reset_success = '';
$reset_email = '';

// Handle login
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    
    if($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        
        $user_roles = getUserRoles($pdo, $user['id']);
        $roles = array_column($user_roles, 'role');
        $_SESSION['roles'] = $roles;
        
        $primary_role = getPrimaryRole($pdo, $user['id']);
        $_SESSION['primary_role'] = $primary_role;
        $_SESSION['current_role'] = $primary_role;
        
        $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $update->execute([$user['id']]);
        
        logActivity($pdo, $user['id'], 'login', "User logged in with roles: " . implode(', ', $roles));
        
        $redirect_map = [
            'admin' => 'admin/dashboard.php',
            'exam_committee' => 'exam_committee/dashboard.php',
            'teacher' => 'teacher/dashboard.php',
            'student' => 'student/dashboard.php'
        ];
        
        header("Location: " . ($redirect_map[$primary_role] ?? 'student/dashboard.php'));
        exit();
    } else {
        $error = "Invalid credentials or account inactive!";
    }
}

// ============= FORGOT PASSWORD - STEP 1: Verify Email/Username =============
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['forgot_password_step1'])) {
    $reset_identifier = trim($_POST['reset_identifier']);
    $reset_email = trim($_POST['reset_identifier']); // Store for step 2
    
    if(empty($reset_identifier)) {
        $reset_error = "Please enter your username or email address!";
    } else {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id, username, email, full_name FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->execute([$reset_identifier, $reset_identifier]);
        $user = $stmt->fetch();
        
        if($user) {
            // Store user info in session for password reset
            $_SESSION['reset_user_id'] = $user['id'];
            $_SESSION['reset_username'] = $user['username'];
            $_SESSION['reset_email'] = $user['email'];
            $_SESSION['reset_step'] = 2; // Move to step 2
            
            $reset_success = "✅ User verified! Please enter your new password.";
        } else {
            $reset_error = "❌ No active account found with this username or email!";
        }
    }
}

// ============= FORGOT PASSWORD - STEP 2: Reset Password =============
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['forgot_password_step2'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_new_password'];
    
    // Check if we have user info in session
    if(!isset($_SESSION['reset_user_id'])) {
        $reset_error = "Session expired. Please start the password reset process again.";
        $_SESSION['reset_step'] = 1;
    } elseif(empty($new_password) || strlen($new_password) < 6) {
        $reset_error = "Password must be at least 6 characters long!";
        $_SESSION['reset_step'] = 2;
    } elseif($new_password !== $confirm_password) {
        $reset_error = "Passwords do not match!";
        $_SESSION['reset_step'] = 2;
    } else {
        try {
            $user_id = $_SESSION['reset_user_id'];
            
            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password in database
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            // Log the password reset
            if(function_exists('logActivity')) {
                logActivity($pdo, $user_id, 'password_reset', "Password reset via forgot password feature");
            }
            
            // Clear reset session data
            $reset_username = $_SESSION['reset_username'] ?? 'User';
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_username']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_step']);
            
            $reset_success = "✅ Password reset successful! You can now login with your new password.";
            
            // Clear the form fields by setting a flag
            $password_reset_done = true;
            
        } catch (PDOException $e) {
            $reset_error = "Database error: " . $e->getMessage();
            error_log("Password reset error: " . $e->getMessage());
            $_SESSION['reset_step'] = 2;
        }
    }
}

// ============= REGISTRATION - ONLY ADMIN CAN CREATE ACCOUNTS =============
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $student_id = trim($_POST['student_id'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $admin_key = trim($_POST['admin_key'] ?? '');
    
    // Admin verification key
    $secret_key = 'ADMIN_SECRET_2026';
    
    // ========== SECURITY: ONLY ALLOW ADMIN REGISTRATION ==========
    $allowed_roles = ['admin']; // ONLY ADMIN CAN REGISTER
    
    // Check if role is admin
    if ($role !== 'admin') {
        $error = "🔒 <strong>Registration is restricted!</strong><br>Only Administrators can create accounts. Please contact the system administrator.";
    } 
    // Validate admin key
    elseif ($admin_key !== $secret_key) {
        $error = "⚠️ <strong>Invalid Admin Verification Key!</strong><br>Please enter the correct admin key to create an account.";
    }
    // Validate full name
    elseif (!preg_match("/^[a-zA-Z\s]+$/", $full_name)) {
        $error = "Full name can only contain letters and spaces!";
    } 
    elseif (strlen($full_name) < 3) {
        $error = "Full name must be at least 3 characters!";
    } 
    elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters!";
    }
    else {
        try {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if($stmt->rowCount() > 0) {
                $error = "Username or email already exists!";
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Check what columns exist in the table
                $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
                
                // Build insert query dynamically
                $insertFields = ['username', 'password', 'full_name', 'email', 'role', 'status'];
                $placeholders = ['?', '?', '?', '?', '?', '?'];
                $values = [$username, $hashed_password, $full_name, $email, 'admin', 'active'];
                
                // Add student_id if column exists
                if (in_array('student_id', $columns)) {
                    $insertFields[] = 'student_id';
                    $placeholders[] = '?';
                    $values[] = $student_id;
                }
                
                // Add created_at if column exists
                if (in_array('created_at', $columns)) {
                    $insertFields[] = 'created_at';
                    $placeholders[] = 'NOW()';
                }
                
                $sql = "INSERT INTO users (" . implode(', ', $insertFields) . ") 
                        VALUES (" . implode(', ', $placeholders) . ")";
                
                $stmt = $pdo->prepare($sql);
                
                if($stmt->execute($values)) {
                    $user_id = $pdo->lastInsertId();
                    
                    // Assign admin role
                    if (function_exists('assignRole')) {
                        try {
                            assignRole($pdo, $user_id, 'admin', $user_id);
                        } catch (Exception $e) {
                            // Role assignment failed but user was created
                            error_log("Role assignment failed: " . $e->getMessage());
                        }
                    }
                    
                    $success = "✅ <strong>Registration successful!</strong><br>You are registered as: <strong>Administrator</strong><br>Please login to access the admin dashboard.";
                    $_POST = array();
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
            error_log("Registration error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Examination System | Secure Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #0f0c29;
            background: linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
            animation: float 15s infinite ease-in-out;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) translateX(0) rotate(0deg); opacity: 0.3; }
            25% { transform: translateY(-50px) translateX(30px) rotate(90deg); opacity: 0.6; }
            50% { transform: translateY(-100px) translateX(-20px) rotate(180deg); opacity: 0.3; }
            75% { transform: translateY(-50px) translateX(-40px) rotate(270deg); opacity: 0.6; }
        }

        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
        }

        .orb-1 {
            width: 400px;
            height: 400px;
            background: rgba(102,126,234,0.3);
            top: -100px;
            left: -100px;
            animation: orbMove 20s infinite;
        }

        .orb-2 {
            width: 500px;
            height: 500px;
            background: rgba(118,75,162,0.3);
            bottom: -150px;
            right: -150px;
            animation: orbMove 25s infinite reverse;
        }

        .orb-3 {
            width: 300px;
            height: 300px;
            background: rgba(236,72,153,0.2);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation: orbMove 30s infinite;
        }

        @keyframes orbMove {
            0%, 100% { transform: translate(0, 0); }
            25% { transform: translate(50px, 50px); }
            50% { transform: translate(0, 100px); }
            75% { transform: translate(-50px, 50px); }
        }

        .container {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .glass-card {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(20px);
            border-radius: 32px;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            overflow: hidden;
            width: 100%;
            max-width: 480px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .glass-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 35px 60px -15px rgba(0,0,0,0.6);
        }

        .card-header {
            background: linear-gradient(135deg, rgba(102,126,234,0.2) 0%, rgba(118,75,162,0.2) 100%);
            padding: 40px 30px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
            animation: logoGlow 2s infinite;
        }

        @keyframes logoGlow {
            0%, 100% { box-shadow: 0 0 20px rgba(102,126,234,0.3); }
            50% { box-shadow: 0 0 40px rgba(102,126,234,0.6); }
        }

        .card-header h1 {
            color: white;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .card-header p {
            color: rgba(255,255,255,0.7);
            font-size: 14px;
        }

        .card-body {
            padding: 35px;
        }

        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
            background: rgba(255,255,255,0.05);
            padding: 5px;
            border-radius: 60px;
        }

        .tab-btn {
            flex: 1;
            background: transparent;
            border: none;
            padding: 12px 20px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 50px;
            transition: all 0.3s ease;
            color: rgba(255,255,255,0.6);
            font-family: 'Inter', sans-serif;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }

        .tab-btn:hover:not(.active) {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .tab-content {
            display: none;
            animation: fadeInUp 0.4s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
            letter-spacing: 0.3px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.5);
            font-size: 16px;
            z-index: 2;
        }

        .input-wrapper input,
        .input-wrapper select {
            width: 100%;
            padding: 14px 16px 14px 48px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 14px;
            font-size: 14px;
            color: white;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            appearance: none;
            -webkit-appearance: none;
        }

        .input-wrapper select {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='white' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }

        .input-wrapper select option {
            background: #1a1a2e;
            color: white;
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255,255,255,0.12);
            box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
        }

        .input-wrapper input::placeholder {
            color: rgba(255,255,255,0.4);
        }

        .input-wrapper input.error {
            border-color: #f56565;
        }

        .input-wrapper input.success {
            border-color: #48bb78;
        }

        .validation-message {
            font-size: 11px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .validation-message.error {
            color: #f56565;
        }

        .validation-message.success {
            color: #48bb78;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: rgba(255,255,255,0.5);
            font-size: 16px;
            transition: color 0.3s;
            z-index: 2;
        }

        .password-toggle:hover {
            color: rgba(255,255,255,0.8);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            font-family: 'Inter', sans-serif;
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102,126,234,0.4);
        }

        .btn-submit-secondary {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .btn-submit-secondary:hover {
            background: rgba(255,255,255,0.2);
            box-shadow: 0 10px 25px rgba(255,255,255,0.1);
        }

        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-error {
            background: rgba(245,101,101,0.15);
            border-left: 3px solid #f56565;
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(72,187,120,0.15);
            border-left: 3px solid #48bb78;
            color: #9ae6b4;
        }

        .alert-warning {
            background: rgba(251,191,36,0.15);
            border-left: 3px solid #fbbf24;
            color: #fcd34d;
        }

        .card-footer {
            padding: 20px 35px 35px;
            text-align: center;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 11px;
            color: rgba(255,255,255,0.5);
        }

        .security-badge i {
            font-size: 12px;
        }

        .copyright {
            font-size: 10px;
            color: rgba(255,255,255,0.3);
            margin-top: 15px;
        }

        .forgot-password-link {
            display: inline-block;
            margin-top: 15px;
            color: rgba(102,126,234,0.8);
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            transition: color 0.3s;
            font-weight: 500;
        }

        .forgot-password-link:hover {
            color: #667eea;
            text-decoration: underline;
        }

        /* Modal Styles for Forgot Password */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(10px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(30px);
            border-radius: 32px;
            border: 1px solid rgba(255,255,255,0.1);
            padding: 40px;
            max-width: 450px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.4s ease;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .modal-header h2 {
            color: white;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .modal-header p {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 20px;
            background: none;
            border: none;
            color: rgba(255,255,255,0.5);
            font-size: 24px;
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-close:hover {
            color: white;
        }

        .modal-content .form-group {
            margin-bottom: 18px;
        }

        .modal-content .btn-submit {
            margin-top: 5px;
        }

        .reset-step-indicator {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 25px;
        }

        .reset-step {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: rgba(255,255,255,0.3);
        }

        .reset-step.active {
            color: #667eea;
        }

        .reset-step .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.1);
            border: 2px solid rgba(255,255,255,0.2);
            font-weight: 600;
            font-size: 13px;
            color: rgba(255,255,255,0.5);
        }

        .reset-step.active .step-circle {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-color: #667eea;
            color: white;
        }

        .reset-step.done .step-circle {
            background: #48bb78;
            border-color: #48bb78;
            color: white;
        }

        .reset-step.done {
            color: #48bb78;
        }

        @media (max-width: 500px) {
            .card-body, .card-footer {
                padding: 25px;
            }
            .card-header {
                padding: 30px 25px;
            }
            .card-header h1 {
                font-size: 24px;
            }
            .tabs {
                gap: 8px;
            }
            .tab-btn {
                padding: 10px 15px;
                font-size: 13px;
            }
            .modal-content {
                padding: 25px;
            }
        }
    </style>
</head>
<body>
    <!-- Animated Particles -->
    <div class="particles">
        <div class="particle" style="width: 100px; height: 100px; top: 10%; left: 5%; animation-duration: 12s;"></div>
        <div class="particle" style="width: 150px; height: 150px; bottom: 15%; right: 8%; animation-duration: 18s;"></div>
        <div class="particle" style="width: 70px; height: 70px; top: 40%; left: 80%; animation-duration: 14s;"></div>
        <div class="particle" style="width: 120px; height: 120px; bottom: 30%; left: 10%; animation-duration: 22s;"></div>
        <div class="particle" style="width: 60px; height: 60px; top: 70%; right: 20%; animation-duration: 10s;"></div>
    </div>
    
    <!-- Gradient Orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <div class="container">
        <div class="glass-card">
            <div class="card-header">
                <div class="logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h1>ExamSphere</h1>
                <p>Next-Generation Online Examination Platform</p>
            </div>
            
            <div class="card-body">
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab('login')">
                        <i class="fas fa-key"></i> Sign In
                    </button>
                    <button class="tab-btn" onclick="switchTab('register')">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                </div>
                
                <!-- Messages -->
                <?php if($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Login Tab -->
                <div id="login-tab" class="tab-content active">
                    <form method="POST" onsubmit="return validateLogin()">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Username or Email</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="text" name="username" id="login_username" required placeholder="Enter your username or email">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-key"></i></span>
                                <input type="password" name="password" id="login_password" required placeholder="Enter your password">
                                <span class="password-toggle" onclick="togglePassword('login_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                        </div>
                        
                        <button type="submit" name="login" class="btn-submit">
                            <i class="fas fa-arrow-right"></i> Sign In
                        </button>
                        
                        <!-- Forgot Password Link -->
                        <div style="text-align: center;">
                            <a class="forgot-password-link" onclick="openResetModal()">
                                <i class="fas fa-key"></i> Forgot Password?
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Register Tab -->
                <div id="register-tab" class="tab-content">
                    <form method="POST" onsubmit="return validateRegistration()">
                        <div class="form-group">
                            <label><i class="fas fa-user-circle"></i> Full Name</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-signature"></i></span>
                                <input type="text" name="full_name" id="reg_full_name" required 
                                       placeholder="Enter your full name"
                                       onkeyup="validateNameLive()">
                            </div>
                            <div class="validation-message" id="name_validation"></div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-at"></i> Username</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-user"></i></span>
                                <input type="text" name="username" id="reg_username" required 
                                       placeholder="Choose a username">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email Address</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" id="reg_email" required 
                                       placeholder="Enter your email">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-user-tag"></i> Account Type</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-user-shield"></i></span>
                                <select name="role" id="reg_role" required>
                                    <option value="">-- Select Account Type --</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                            <div style="font-size:11px; color:rgba(255,255,255,0.5); margin-top:5px;">
                                <i class="fas fa-info-circle"></i> Only Administrator accounts can be created
                            </div>
                        </div>
                        
                        <div class="form-group" id="admin_key_group">
                            <label><i class="fas fa-key"></i> Admin Verification Key <span style="color:#ff6b6b;">*</span></label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="admin_key" id="reg_admin_key" required placeholder="Enter admin verification key">
                            </div>
                            <div style="font-size:11px; color:rgba(255,255,255,0.4); margin-top:5px;">
                                <i class="fas fa-info-circle"></i> Required for Administrator account creation
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> Student ID (Optional)</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-qrcode"></i></span>
                                <input type="text" name="student_id" placeholder="Enter your student ID">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-key"></i></span>
                                <input type="password" name="password" id="reg_password" required 
                                       placeholder="Create a password (min 6 characters)">
                                <span class="password-toggle" onclick="togglePassword('reg_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i> Confirm Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="confirm_password" id="reg_confirm_password" required 
                                       placeholder="Confirm your password">
                                <span class="password-toggle" onclick="togglePassword('reg_confirm_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                            <div class="validation-message" id="password_validation"></div>
                        </div>
                        
                        <button type="submit" name="register" class="btn-submit">
                            <i class="fas fa-user-plus"></i> Create Administrator Account
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="card-footer">
                <div class="security-badge">
                    <i class="fas fa-shield-alt"></i>
                    <span>256-bit SSL Encrypted</span>
                    <i class="fas fa-database"></i>
                    <span>Secure Database</span>
                </div>
                <div class="copyright">
                    <i class="far fa-copyright"></i> <?php echo date('Y'); ?> ExamSphere. All rights reserved.
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-content">
            <div style="position: relative;">
                <button class="modal-close" onclick="closeResetModal()">&times;</button>
            </div>
            
            <div class="modal-header">
                <h2><i class="fas fa-key" style="color: #667eea;"></i> Reset Password</h2>
                <p>Enter your username or email to reset your password</p>
            </div>

            <!-- Step Indicator -->
            <div class="reset-step-indicator">
                <div class="reset-step <?php echo (!isset($_SESSION['reset_step']) || $_SESSION['reset_step'] == 1) ? 'active' : 'done'; ?>" id="step1_indicator">
                    <div class="step-circle">1</div>
                    <span>Verify</span>
                </div>
                <div class="reset-step <?php echo (isset($_SESSION['reset_step']) && $_SESSION['reset_step'] == 2) ? 'active' : ''; ?>" id="step2_indicator">
                    <div class="step-circle">2</div>
                    <span>Reset</span>
                </div>
            </div>

            <?php if(isset($password_reset_done) && $password_reset_done): ?>
                <!-- Success Message after password reset -->
                <div class="alert alert-success" style="margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $reset_success; ?>
                </div>
                <button class="btn-submit" onclick="closeResetModal()">
                    <i class="fas fa-arrow-right"></i> Return to Login
                </button>
            <?php else: ?>
                <!-- Reset Error Messages -->
                <?php if($reset_error): ?>
                    <div class="alert alert-error" style="margin-bottom: 20px;">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $reset_error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if($reset_success && !isset($password_reset_done)): ?>
                    <div class="alert alert-success" style="margin-bottom: 20px;">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $reset_success; ?>
                    </div>
                <?php endif; ?>

                <!-- Step 1: Verify Identity -->
                <div id="reset_step_1" style="<?php echo (isset($_SESSION['reset_step']) && $_SESSION['reset_step'] == 2) ? 'display:none;' : ''; ?>">
                    <form method="POST" onsubmit="return validateResetStep1()">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Username or Email</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="text" name="reset_identifier" id="reset_identifier" required 
                                       placeholder="Enter your username or email" 
                                       value="<?php echo htmlspecialchars($reset_email ?? ''); ?>">
                            </div>
                            <div style="font-size:11px; color:rgba(255,255,255,0.4); margin-top:5px;">
                                <i class="fas fa-info-circle"></i> We'll verify your account and let you reset your password
                            </div>
                        </div>
                        <button type="submit" name="forgot_password_step1" class="btn-submit">
                            <i class="fas fa-search"></i> Verify Account
                        </button>
                    </form>
                </div>

                <!-- Step 2: Reset Password -->
                <div id="reset_step_2" style="<?php echo (!isset($_SESSION['reset_step']) || $_SESSION['reset_step'] != 2) ? 'display:none;' : ''; ?>">
                    <?php if(isset($_SESSION['reset_username'])): ?>
                        <div class="alert alert-success" style="margin-bottom: 20px; font-size: 13px;">
                            <i class="fas fa-user-check"></i>
                            Verified: <strong><?php echo htmlspecialchars($_SESSION['reset_username']); ?></strong>
                            (<?php echo htmlspecialchars($_SESSION['reset_email'] ?? ''); ?>)
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" onsubmit="return validateResetStep2()">
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> New Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-key"></i></span>
                                <input type="password" name="new_password" id="new_password" required 
                                       placeholder="Enter new password (min 6 characters)">
                                <span class="password-toggle" onclick="togglePassword('new_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i> Confirm New Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="confirm_new_password" id="confirm_new_password" required 
                                       placeholder="Confirm your new password">
                                <span class="password-toggle" onclick="togglePassword('confirm_new_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                            <div class="validation-message" id="reset_password_validation"></div>
                        </div>
                        
                        <button type="submit" name="forgot_password_step2" class="btn-submit">
                            <i class="fas fa-save"></i> Reset Password
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 15px;">
                <a style="color: rgba(255,255,255,0.5); font-size: 13px; cursor: pointer; text-decoration: none;" onclick="closeResetModal()">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            document.getElementById('login-tab').classList.remove('active');
            document.getElementById('register-tab').classList.remove('active');
            
            if(tab === 'login') {
                document.getElementById('login-tab').classList.add('active');
            } else {
                document.getElementById('register-tab').classList.add('active');
            }
        }
        
        function togglePassword(inputId, element) {
            const input = document.getElementById(inputId);
            const icon = element.querySelector('i');
            
            if(input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // ========== FORGOT PASSWORD FUNCTIONS ==========
        function openResetModal() {
            document.getElementById('resetModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeResetModal() {
            document.getElementById('resetModal').classList.remove('active');
            document.body.style.overflow = '';
            // Reload page to clear session state if needed
            if (window.location.search.indexOf('reset') === -1) {
                window.location.href = window.location.pathname + '?reset=close';
            }
        }

        // Close modal when clicking outside
        document.getElementById('resetModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeResetModal();
            }
        });

        function validateResetStep1() {
            const identifier = document.getElementById('reset_identifier').value.trim();
            if (identifier === '') {
                alert('Please enter your username or email address!');
                return false;
            }
            return true;
        }

        function validateResetStep2() {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_new_password').value;
            
            if (password.length < 6) {
                alert('Password must be at least 6 characters long!');
                return false;
            }
            
            if (password !== confirm) {
                alert('Passwords do not match!');
                return false;
            }
            
            return true;
        }

        // Real-time password validation for reset
        function validateResetPasswordMatch() {
            const password = document.getElementById('new_password');
            const confirm = document.getElementById('confirm_new_password');
            const validationDiv = document.getElementById('reset_password_validation');
            
            if (!password || !confirm || !validationDiv) return;
            
            if (confirm.value.length > 0) {
                if (password.value === confirm.value && password.value.length >= 6) {
                    validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                    validationDiv.className = 'validation-message success';
                } else if (password.value !== confirm.value) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Passwords do not match';
                    validationDiv.className = 'validation-message error';
                } else if (password.value.length < 6) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Password must be at least 6 characters';
                    validationDiv.className = 'validation-message error';
                }
            } else {
                validationDiv.innerHTML = '';
            }
        }

        // Add event listeners for reset password validation
        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_new_password');
            
            if (newPassword) {
                newPassword.addEventListener('keyup', validateResetPasswordMatch);
            }
            if (confirmPassword) {
                confirmPassword.addEventListener('keyup', validateResetPasswordMatch);
            }
        });

        // ========== REGISTRATION VALIDATION ==========
        function validateNameLive() {
            const input = document.getElementById('reg_full_name');
            const validationDiv = document.getElementById('name_validation');
            const nameValue = input.value.trim();
            const nameRegex = /^[a-zA-Z\s]*$/;
            
            if (nameValue.length > 0) {
                if (nameRegex.test(nameValue) && nameValue.length >= 3) {
                    input.classList.add('success');
                    input.classList.remove('error');
                    validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> Valid name';
                    validationDiv.className = 'validation-message success';
                } else if (!nameRegex.test(nameValue)) {
                    input.classList.add('error');
                    input.classList.remove('success');
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Only letters and spaces allowed';
                    validationDiv.className = 'validation-message error';
                } else if (nameValue.length < 3) {
                    input.classList.add('error');
                    input.classList.remove('success');
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Minimum 3 characters';
                    validationDiv.className = 'validation-message error';
                }
            } else {
                input.classList.remove('success', 'error');
                validationDiv.innerHTML = '';
            }
        }
        
        function validatePasswordMatch() {
            const password = document.getElementById('reg_password').value;
            const confirm = document.getElementById('reg_confirm_password').value;
            const validationDiv = document.getElementById('password_validation');
            
            if (confirm.length > 0) {
                if (password === confirm && password.length >= 6) {
                    validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                    validationDiv.className = 'validation-message success';
                    return true;
                } else if (password !== confirm) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Passwords do not match';
                    validationDiv.className = 'validation-message error';
                    return false;
                } else if (password.length < 6) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Password must be at least 6 characters';
                    validationDiv.className = 'validation-message error';
                    return false;
                }
            }
            return true;
        }
        
        function validateRegistration() {
            const fullName = document.getElementById('reg_full_name').value.trim();
            const nameRegex = /^[a-zA-Z\s]+$/;
            
            if (!nameRegex.test(fullName)) {
                alert("Full name can only contain letters and spaces!");
                return false;
            }
            
            if (fullName.length < 3) {
                alert("Full name must be at least 3 characters!");
                return false;
            }
            
            const password = document.getElementById('reg_password').value;
            if (password.length < 6) {
                alert("Password must be at least 6 characters!");
                return false;
            }
            
            const confirm = document.getElementById('reg_confirm_password').value;
            if (password !== confirm) {
                alert("Passwords do not match!");
                return false;
            }
            
            const username = document.getElementById('reg_username').value.trim();
            if (username.length < 3) {
                alert("Username must be at least 3 characters!");
                return false;
            }
            
            const email = document.getElementById('reg_email').value.trim();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                alert("Please enter a valid email address!");
                return false;
            }
            
            const adminKey = document.getElementById('reg_admin_key').value.trim();
            if (adminKey === '') {
                alert("Please enter the admin verification key!");
                return false;
            }
            
            return true;
        }
        
        function validateLogin() {
            const username = document.getElementById('login_username').value.trim();
            const password = document.getElementById('login_password').value.trim();
            
            if (username === '' || password === '') {
                alert("Please enter both username and password!");
                return false;
            }
            return true;
        }
        
        document.getElementById('reg_confirm_password')?.addEventListener('keyup', validatePasswordMatch);
        document.getElementById('reg_password')?.addEventListener('keyup', validatePasswordMatch);
        
        document.getElementById('reg_full_name')?.addEventListener('keyup', function() {
            if (this.value.startsWith(' ')) {
                this.value = this.value.trimStart();
            }
        });
    </script>
</body>
</html>
