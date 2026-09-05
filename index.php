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
    $reset_email = trim($_POST['reset_identifier']);
    
    if(empty($reset_identifier)) {
        $reset_error = "Please enter your username or email address!";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, email, full_name FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->execute([$reset_identifier, $reset_identifier]);
        $user = $stmt->fetch();
        
        if($user) {
            $_SESSION['reset_user_id'] = $user['id'];
            $_SESSION['reset_username'] = $user['username'];
            $_SESSION['reset_email'] = $user['email'];
            $_SESSION['reset_step'] = 2;
            
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
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            if(function_exists('logActivity')) {
                logActivity($pdo, $user_id, 'password_reset', "Password reset via forgot password feature");
            }
            
            $reset_username = $_SESSION['reset_username'] ?? 'User';
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_username']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_step']);
            
            $reset_success = "✅ Password reset successful! You can now login with your new password.";
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
    
    $secret_key = 'ADMIN_SECRET_2026';
    
    if ($role !== 'admin') {
        $error = "🔒 <strong>Registration is restricted!</strong><br>Only Administrators can create accounts. Please contact the system administrator.";
    } 
    elseif ($admin_key !== $secret_key) {
        $error = "⚠️ <strong>Invalid Admin Verification Key!</strong><br>Please enter the correct admin key to create an account.";
    }
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
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if($stmt->rowCount() > 0) {
                $error = "Username or email already exists!";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
                
                $insertFields = ['username', 'password', 'full_name', 'email', 'role', 'status'];
                $placeholders = ['?', '?', '?', '?', '?', '?'];
                $values = [$username, $hashed_password, $full_name, $email, 'admin', 'active'];
                
                if (in_array('student_id', $columns)) {
                    $insertFields[] = 'student_id';
                    $placeholders[] = '?';
                    $values[] = $student_id;
                }
                
                if (in_array('created_at', $columns)) {
                    $insertFields[] = 'created_at';
                    $placeholders[] = 'NOW()';
                }
                
                $sql = "INSERT INTO users (" . implode(', ', $insertFields) . ") 
                        VALUES (" . implode(', ', $placeholders) . ")";
                
                $stmt = $pdo->prepare($sql);
                
                if($stmt->execute($values)) {
                    $user_id = $pdo->lastInsertId();
                    
                    if (function_exists('assignRole')) {
                        try {
                            assignRole($pdo, $user_id, 'admin', $user_id);
                        } catch (Exception $e) {
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
    <title>ExamSphere | Premium Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================
                   CSS VARIABLES & THEMING
                ============================================ */
        :root {
            /* Light Theme (Default) */
            --bg-primary: #0f0c29;
            --bg-secondary: #302b63;
            --bg-tertiary: #24243e;
            --bg-card: rgba(255,255,255,0.03);
            --bg-card-hover: rgba(255,255,255,0.06);
            --text-primary: #ffffff;
            --text-secondary: rgba(255,255,255,0.7);
            --text-muted: rgba(255,255,255,0.4);
            --border-color: rgba(255,255,255,0.1);
            --input-bg: rgba(255,255,255,0.08);
            --input-border: rgba(255,255,255,0.15);
            --input-focus: rgba(102,126,234,0.3);
            --gradient-1: #667eea;
            --gradient-2: #764ba2;
            --shadow-color: rgba(102,126,234,0.4);
            --orb-1: rgba(102,126,234,0.3);
            --orb-2: rgba(118,75,162,0.3);
            --orb-3: rgba(236,72,153,0.2);
            --particle-color: rgba(255,255,255,0.08);
            --success-color: #48bb78;
            --error-color: #f56565;
            --warning-color: #fbbf24;
            --glass-border: rgba(255,255,255,0.1);
            --glass-backdrop: blur(20px);
            --transition-speed: 0.4s;
        }

        /* Dark Theme */
        [data-theme="dark"] {
            --bg-primary: #0a0a0f;
            --bg-secondary: #151520;
            --bg-tertiary: #1a1a2e;
            --bg-card: rgba(255,255,255,0.02);
            --bg-card-hover: rgba(255,255,255,0.04);
            --text-primary: #e8e8f0;
            --text-secondary: rgba(255,255,255,0.6);
            --text-muted: rgba(255,255,255,0.3);
            --border-color: rgba(255,255,255,0.05);
            --input-bg: rgba(255,255,255,0.04);
            --input-border: rgba(255,255,255,0.08);
            --input-focus: rgba(102,126,234,0.2);
            --orb-1: rgba(102,126,234,0.15);
            --orb-2: rgba(118,75,162,0.15);
            --orb-3: rgba(236,72,153,0.1);
            --particle-color: rgba(255,255,255,0.04);
            --glass-border: rgba(255,255,255,0.04);
            --shadow-color: rgba(102,126,234,0.2);
        }

        /* Eye Care Mode - Warm Tint */
        [data-eyecare="true"] {
            --bg-primary: #1a1410;
            --bg-secondary: #2a1f18;
            --bg-tertiary: #1f1712;
            --text-primary: #f0e6d8;
            --text-secondary: rgba(240,230,216,0.7);
            --text-muted: rgba(240,230,216,0.4);
            --orb-1: rgba(200,150,100,0.2);
            --orb-2: rgba(180,130,80,0.2);
            --orb-3: rgba(160,110,70,0.15);
            --particle-color: rgba(240,230,216,0.04);
            --border-color: rgba(200,170,140,0.1);
            --input-bg: rgba(200,170,140,0.06);
            --input-border: rgba(200,170,140,0.12);
            --glass-border: rgba(200,170,140,0.08);
            --gradient-1: #c4956a;
            --gradient-2: #a87d5a;
            --shadow-color: rgba(200,150,100,0.3);
        }

        /* Focus Mode - Dim Background */
        [data-focus="true"] .container .glass-card {
            box-shadow: 0 0 0 2px rgba(102,126,234,0.3), 0 25px 50px -12px rgba(0,0,0,0.8);
        }

        [data-focus="true"] .particles,
        [data-focus="true"] .orb {
            opacity: 0.3;
            transition: opacity 0.6s ease;
        }

        /* ============================================
                   BASE STYLES
                ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 50%, var(--bg-tertiary) 100%);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
            transition: background var(--transition-speed) ease, color var(--transition-speed) ease;
        }

        /* ============================================
                   ANIMATED PARTICLES
                ============================================ */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
            transition: opacity 0.6s ease;
        }

        .particle {
            position: absolute;
            background: var(--particle-color);
            border-radius: 50%;
            animation: float 15s infinite ease-in-out;
            transition: transform 0.1s ease;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) translateX(0) rotate(0deg); opacity: 0.3; }
            25% { transform: translateY(-50px) translateX(30px) rotate(90deg); opacity: 0.6; }
            50% { transform: translateY(-100px) translateX(-20px) rotate(180deg); opacity: 0.3; }
            75% { transform: translateY(-50px) translateX(-40px) rotate(270deg); opacity: 0.6; }
        }

        /* ============================================
                   GRADIENT ORBS
                ============================================ */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
            transition: all 0.6s ease;
            pointer-events: none;
        }

        .orb-1 {
            width: 400px;
            height: 400px;
            background: var(--orb-1);
            top: -100px;
            left: -100px;
            animation: orbMove 20s infinite;
        }

        .orb-2 {
            width: 500px;
            height: 500px;
            background: var(--orb-2);
            bottom: -150px;
            right: -150px;
            animation: orbMove 25s infinite reverse;
        }

        .orb-3 {
            width: 300px;
            height: 300px;
            background: var(--orb-3);
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

        /* ============================================
                   CONTAINER & GLASS CARD
                ============================================ */
        .container {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: all var(--transition-speed) ease;
        }

        .glass-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-backdrop);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            overflow: hidden;
            width: 100%;
            max-width: 480px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, all var(--transition-speed) ease;
            position: relative;
        }

        .glass-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 35px 60px -15px rgba(0,0,0,0.6);
        }

        /* ============================================
                   CARD HEADER
                ============================================ */
        .card-header {
            background: linear-gradient(135deg, rgba(102,126,234,0.15) 0%, rgba(118,75,162,0.15) 100%);
            padding: 35px 30px 25px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
            position: relative;
            transition: all var(--transition-speed) ease;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 40px;
            animation: logoGlow 3s infinite;
            transition: all var(--transition-speed) ease;
            position: relative;
        }

        .logo::after {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 27px;
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            opacity: 0.3;
            filter: blur(10px);
            z-index: -1;
            animation: logoPulse 2s infinite;
        }

        @keyframes logoPulse {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.05); }
        }

        @keyframes logoGlow {
            0%, 100% { box-shadow: 0 0 20px rgba(102,126,234,0.3); }
            50% { box-shadow: 0 0 50px rgba(102,126,234,0.6); }
        }

        .card-header h1 {
            color: var(--text-primary);
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
            transition: color var(--transition-speed) ease;
        }

        .card-header p {
            color: var(--text-secondary);
            font-size: 14px;
            transition: color var(--transition-speed) ease;
        }

        /* ============================================
                   CARD BODY
                ============================================ */
        .card-body {
            padding: 30px 30px 20px;
        }

        /* ============================================
                   TABS
                ============================================ */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: var(--input-bg);
            padding: 5px;
            border-radius: 60px;
            border: 1px solid var(--border-color);
            transition: all var(--transition-speed) ease;
        }

        .tab-btn {
            flex: 1;
            background: transparent;
            border: none;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 50px;
            transition: all 0.3s ease;
            color: var(--text-secondary);
            font-family: 'Inter', sans-serif;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            color: white;
            box-shadow: 0 5px 20px var(--shadow-color);
        }

        .tab-btn:hover:not(.active) {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        .tab-content {
            display: none;
            animation: fadeInUp 0.4s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ============================================
                   FORM ELEMENTS
                ============================================ */
        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 500;
            color: var(--text-secondary);
            font-size: 13px;
            letter-spacing: 0.3px;
            transition: color var(--transition-speed) ease;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            z-index: 2;
            transition: color var(--transition-speed) ease;
        }

        .input-wrapper input,
        .input-wrapper select {
            width: 100%;
            padding: 13px 16px 13px 46px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 14px;
            font-size: 14px;
            color: var(--text-primary);
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
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus {
            outline: none;
            border-color: var(--gradient-1);
            background: var(--input-bg);
            box-shadow: 0 0 0 4px var(--input-focus);
        }

        .input-wrapper input::placeholder {
            color: var(--text-muted);
        }

        .input-wrapper input.error {
            border-color: var(--error-color);
        }

        .input-wrapper input.success {
            border-color: var(--success-color);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-muted);
            font-size: 16px;
            transition: color 0.3s;
            z-index: 2;
        }

        .password-toggle:hover {
            color: var(--text-secondary);
        }

        /* ============================================
                   PASSWORD STRENGTH METER
                ============================================ */
        .password-strength {
            margin-top: 8px;
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .strength-bar {
            flex: 1;
            height: 4px;
            background: var(--input-border);
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .strength-bar .fill {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.4s ease, background 0.4s ease;
        }

        .strength-text {
            font-size: 11px;
            color: var(--text-muted);
            min-width: 60px;
            text-align: right;
            transition: color var(--transition-speed) ease;
        }

        /* ============================================
                   BUTTONS
                ============================================ */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
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
            transition: left 0.6s;
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px var(--shadow-color);
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        .btn-submit-secondary {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
        }

        .btn-submit-secondary:hover {
            background: var(--bg-card-hover);
            box-shadow: none;
        }

        /* ============================================
                   ALERTS
                ============================================ */
        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            animation: slideIn 0.3s ease;
            transition: all var(--transition-speed) ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .alert-error {
            background: rgba(245,101,101,0.12);
            border-left: 3px solid var(--error-color);
            color: var(--error-color);
        }

        .alert-success {
            background: rgba(72,187,120,0.12);
            border-left: 3px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-warning {
            background: rgba(251,191,36,0.12);
            border-left: 3px solid var(--warning-color);
            color: var(--warning-color);
        }

        /* ============================================
                   FORGOT PASSWORD LINK
                ============================================ */
        .forgot-password-link {
            display: inline-block;
            margin-top: 14px;
            color: var(--gradient-1);
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
            background: none;
            border: none;
            font-family: 'Inter', sans-serif;
        }

        .forgot-password-link:hover {
            text-decoration: underline;
            opacity: 0.8;
        }

        .login-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }

        /* ============================================
                   CARD FOOTER
                ============================================ */
        .card-footer {
            padding: 16px 30px 25px;
            text-align: center;
            border-top: 1px solid var(--border-color);
            transition: all var(--transition-speed) ease;
        }

        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            font-size: 11px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }

        .security-badge i {
            font-size: 12px;
        }

        .copyright {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 12px;
            opacity: 0.6;
        }

        /* ============================================
                   CONTROL BAR (Theme, Eye Care, Focus)
                ============================================ */
        .control-bar {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
            padding: 12px 20px;
            background: var(--bg-card);
            backdrop-filter: var(--glass-backdrop);
            border-radius: 60px;
            border: 1px solid var(--border-color);
            position: fixed;
            bottom: 25px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
            transition: all var(--transition-speed) ease;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .control-bar button {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 18px;
            padding: 8px 12px;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

        .control-bar button:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        .control-bar button.active {
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            color: white;
            box-shadow: 0 5px 20px var(--shadow-color);
        }

        .control-divider {
            width: 1px;
            height: 25px;
            background: var(--border-color);
        }

        /* ============================================
                   MODAL (Forgot Password)
                ============================================ */
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
            background: var(--bg-card);
            backdrop-filter: var(--glass-backdrop);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            padding: 35px;
            max-width: 450px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.4s ease;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            transition: all var(--transition-speed) ease;
            position: relative;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .modal-header h2 {
            color: var(--text-primary);
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
            transition: color var(--transition-speed) ease;
        }

        .modal-header p {
            color: var(--text-secondary);
            font-size: 14px;
            transition: color var(--transition-speed) ease;
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 20px;
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .reset-step-indicator {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 22px;
        }

        .reset-step {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            transition: color var(--transition-speed) ease;
        }

        .reset-step.active {
            color: var(--gradient-1);
        }

        .reset-step .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--input-bg);
            border: 2px solid var(--border-color);
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
            transition: all var(--transition-speed) ease;
        }

        .reset-step.active .step-circle {
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            border-color: var(--gradient-1);
            color: white;
            box-shadow: 0 5px 20px var(--shadow-color);
        }

        .reset-step.done .step-circle {
            background: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }

        .reset-step.done {
            color: var(--success-color);
        }

        /* ============================================
                   VALIDATION MESSAGES
                ============================================ */
        .validation-message {
            font-size: 11px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .validation-message.error {
            color: var(--error-color);
        }

        .validation-message.success {
            color: var(--success-color);
        }

        .validation-message .fa-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ============================================
                   RESPONSIVE
                ============================================ */
        @media (max-width: 500px) {
            .card-body, .card-footer {
                padding: 20px;
            }
            .card-header {
                padding: 25px 20px;
            }
            .card-header h1 {
                font-size: 22px;
            }
            .tabs {
                gap: 6px;
            }
            .tab-btn {
                padding: 9px 12px;
                font-size: 12px;
            }
            .control-bar {
                bottom: 15px;
                padding: 8px 14px;
                gap: 4px;
                flex-wrap: wrap;
                justify-content: center;
            }
            .control-bar button {
                font-size: 12px;
                padding: 6px 10px;
            }
            .modal-content {
                padding: 25px 20px;
            }
            .logo {
                width: 65px;
                height: 65px;
                font-size: 32px;
            }
            .reset-step-indicator {
                gap: 8px;
            }
            .reset-step {
                font-size: 10px;
            }
            .reset-step .step-circle {
                width: 25px;
                height: 25px;
                font-size: 11px;
            }
        }

        @media (max-width: 380px) {
            .control-bar button span {
                display: none;
            }
            .control-bar button {
                font-size: 16px;
                padding: 6px 10px;
            }
            .login-options {
                flex-direction: column;
                align-items: stretch;
            }
            .forgot-password-link {
                text-align: center;
            }
        }

        /* ============================================
                   UTILITY
                ============================================ */
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .gap-1 { gap: 4px; }
        .gap-2 { gap: 8px; }
        .flex { display: flex; }
        .flex-center { display: flex; align-items: center; justify-content: center; }
        .flex-col { flex-direction: column; }
        .items-center { align-items: center; }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: var(--gradient-1);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: var(--gradient-2);
        }

        /* Selection */
        ::selection {
            background: var(--gradient-1);
            color: white;
        }
    </style>
</head>
<body>
    <!-- ============================================
    ANIMATED PARTICLES
    ============================================ -->
    <div class="particles" id="particles">
        <div class="particle" style="width: 100px; height: 100px; top: 10%; left: 5%; animation-duration: 12s;"></div>
        <div class="particle" style="width: 150px; height: 150px; bottom: 15%; right: 8%; animation-duration: 18s;"></div>
        <div class="particle" style="width: 70px; height: 70px; top: 40%; left: 80%; animation-duration: 14s;"></div>
        <div class="particle" style="width: 120px; height: 120px; bottom: 30%; left: 10%; animation-duration: 22s;"></div>
        <div class="particle" style="width: 60px; height: 60px; top: 70%; right: 20%; animation-duration: 10s;"></div>
        <div class="particle" style="width: 90px; height: 90px; top: 20%; right: 40%; animation-duration: 16s;"></div>
        <div class="particle" style="width: 50px; height: 50px; bottom: 40%; right: 60%; animation-duration: 13s;"></div>
    </div>
    
    <!-- ============================================
    GRADIENT ORBS
    ============================================ -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <!-- ============================================
    MAIN CONTAINER
    ============================================ -->
    <div class="container">
        <div class="glass-card">
            <!-- Card Header -->
            <div class="card-header">
                <div class="logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h1>ExamSphere</h1>
                <p>Next-Generation Online Examination Platform</p>
            </div>
            
            <!-- Card Body -->
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
                                <input type="text" name="username" id="login_username" required 
                                       placeholder="Enter your username or email"
                                       autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-key"></i></span>
                                <input type="password" name="password" id="login_password" required 
                                       placeholder="Enter your password"
                                       autocomplete="current-password">
                                <span class="password-toggle" onclick="togglePassword('login_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="login-options">
                            <button type="submit" name="login" class="btn-submit" style="flex:1;">
                                <i class="fas fa-arrow-right"></i> Sign In
                            </button>
                        </div>
                        
                        <div class="text-center mt-2">
                            <button type="button" class="forgot-password-link" onclick="openResetModal()">
                                <i class="fas fa-key"></i> Forgot Password?
                            </button>
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
                                       placeholder="Choose a username"
                                       autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email Address</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" id="reg_email" required 
                                       placeholder="Enter your email"
                                       autocomplete="email">
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
                            <div style="font-size:11px; color:var(--text-muted); margin-top:5px;">
                                <i class="fas fa-info-circle"></i> Only Administrator accounts can be created
                            </div>
                        </div>
                        
                        <div class="form-group" id="admin_key_group">
                            <label><i class="fas fa-key"></i> Admin Verification Key <span style="color:var(--error-color);">*</span></label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="admin_key" id="reg_admin_key" required 
                                       placeholder="Enter admin verification key">
                            </div>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:5px;">
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
                                       placeholder="Create a password (min 6 characters)"
                                       onkeyup="updatePasswordStrength()">
                                <span class="password-toggle" onclick="togglePassword('reg_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                            <!-- Password Strength Meter -->
                            <div class="password-strength" id="strength_meter">
                                <div class="strength-bar">
                                    <div class="fill" id="strength_fill"></div>
                                </div>
                                <span class="strength-text" id="strength_text">Weak</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i> Confirm Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="confirm_password" id="reg_confirm_password" required 
                                       placeholder="Confirm your password"
                                       onkeyup="validatePasswordMatch()">
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
            
            <!-- Card Footer -->
            <div class="card-footer">
                <div class="security-badge">
                    <i class="fas fa-shield-alt"></i>
                    <span>256-bit SSL Encrypted</span>
                    <i class="fas fa-database"></i>
                    <span>Secure Database</span>
                    <i class="fas fa-clock"></i>
                    <span id="live_time">--:--:--</span>
                </div>
                <div class="copyright">
                    <i class="far fa-copyright"></i> <?php echo date('Y'); ?> ExamSphere. All rights reserved.
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    CONTROL BAR - Theme, Eye Care, Focus
    ============================================ -->
    <div class="control-bar" id="controlBar">
        <button onclick="toggleTheme()" id="themeBtn" title="Toggle Dark/Light Mode">
            <i class="fas fa-moon"></i>
            <span>Theme</span>
        </button>
        
        <div class="control-divider"></div>
        
        <button onclick="toggleEyeCare()" id="eyeCareBtn" title="Eye Care Mode - Reduce Eye Strain">
            <i class="fas fa-eye"></i>
            <span>Eye Care</span>
        </button>
        
        <div class="control-divider"></div>
        
        <button onclick="toggleFocus()" id="focusBtn" title="Focus Mode - Dim Distractions">
            <i class="fas fa-crosshairs"></i>
            <span>Focus</span>
        </button>
        
        <div class="control-divider"></div>
        
        <button onclick="toggleParticles()" id="particleBtn" title="Toggle Particle Animation">
            <i class="fas fa-circle"></i>
            <span>Particles</span>
        </button>
        
        <div class="control-divider"></div>
        
        <button onclick="resetSettings()" title="Reset All Settings">
            <i class="fas fa-undo"></i>
            <span>Reset</span>
        </button>
    </div>

    <!-- ============================================
    FORGOT PASSWORD MODAL
    ============================================ -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeResetModal()">&times;</button>
            
            <div class="modal-header">
                <h2><i class="fas fa-key" style="color: var(--gradient-1);"></i> Reset Password</h2>
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
                <div class="alert alert-success" style="margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $reset_success; ?>
                </div>
                <button class="btn-submit" onclick="closeResetModal()">
                    <i class="fas fa-arrow-right"></i> Return to Login
                </button>
            <?php else: ?>
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
                            <div style="font-size:11px; color:var(--text-muted); margin-top:5px;">
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
                                       placeholder="Enter new password (min 6 characters)"
                                       onkeyup="validateResetPasswordMatch(); updateResetPasswordStrength();">
                                <span class="password-toggle" onclick="togglePassword('new_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                            <!-- Reset Password Strength -->
                            <div class="password-strength" id="reset_strength_meter">
                                <div class="strength-bar">
                                    <div class="fill" id="reset_strength_fill"></div>
                                </div>
                                <span class="strength-text" id="reset_strength_text">Weak</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i> Confirm New Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="confirm_new_password" id="confirm_new_password" required 
                                       placeholder="Confirm your new password"
                                       onkeyup="validateResetPasswordMatch()">
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
            
            <div class="text-center mt-2">
                <button style="color:var(--text-muted); font-size:13px; cursor:pointer; background:none; border:none; font-family:'Inter',sans-serif;" onclick="closeResetModal()">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================
    JAVASCRIPT
    ============================================ -->
    <script>
        // ============================================
        // THEME MANAGEMENT
        // ============================================
        function toggleTheme() {
            const html = document.documentElement;
            const btn = document.getElementById('themeBtn');
            const currentTheme = html.getAttribute('data-theme');
            
            if (currentTheme === 'dark') {
                html.removeAttribute('data-theme');
                btn.innerHTML = '<i class="fas fa-moon"></i><span>Theme</span>';
                localStorage.setItem('theme', 'light');
            } else {
                html.setAttribute('data-theme', 'dark');
                btn.innerHTML = '<i class="fas fa-sun"></i><span>Theme</span>';
                localStorage.setItem('theme', 'dark');
            }
        }

        // ============================================
        // EYE CARE MODE
        // ============================================
        function toggleEyeCare() {
            const html = document.documentElement;
            const btn = document.getElementById('eyeCareBtn');
            const current = html.getAttribute('data-eyecare');
            
            if (current === 'true') {
                html.removeAttribute('data-eyecare');
                btn.innerHTML = '<i class="fas fa-eye"></i><span>Eye Care</span>';
                btn.classList.remove('active');
                localStorage.setItem('eyecare', 'false');
            } else {
                html.setAttribute('data-eyecare', 'true');
                btn.innerHTML = '<i class="fas fa-eye"></i><span>Eye Care</span>';
                btn.classList.add('active');
                localStorage.setItem('eyecare', 'true');
            }
        }

        // ============================================
        // FOCUS MODE
        // ============================================
        function toggleFocus() {
            const html = document.documentElement;
            const btn = document.getElementById('focusBtn');
            const current = html.getAttribute('data-focus');
            
            if (current === 'true') {
                html.removeAttribute('data-focus');
                btn.innerHTML = '<i class="fas fa-crosshairs"></i><span>Focus</span>';
                btn.classList.remove('active');
                localStorage.setItem('focus', 'false');
            } else {
                html.setAttribute('data-focus', 'true');
                btn.innerHTML = '<i class="fas fa-crosshairs"></i><span>Focus</span>';
                btn.classList.add('active');
                localStorage.setItem('focus', 'true');
            }
        }

        // ============================================
        // PARTICLES TOGGLE
        // ============================================
        function toggleParticles() {
            const particles = document.getElementById('particles');
            const btn = document.getElementById('particleBtn');
            
            if (particles.style.display === 'none') {
                particles.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-circle"></i><span>Particles</span>';
                btn.classList.remove('active');
                localStorage.setItem('particles', 'true');
            } else {
                particles.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-circle"></i><span>Particles</span>';
                btn.classList.add('active');
                localStorage.setItem('particles', 'false');
            }
        }

        // ============================================
        // RESET SETTINGS
        // ============================================
        function resetSettings() {
            const html = document.documentElement;
            html.removeAttribute('data-theme');
            html.removeAttribute('data-eyecare');
            html.removeAttribute('data-focus');
            
            document.getElementById('themeBtn').innerHTML = '<i class="fas fa-moon"></i><span>Theme</span>';
            document.getElementById('themeBtn').classList.remove('active');
            
            document.getElementById('eyeCareBtn').innerHTML = '<i class="fas fa-eye"></i><span>Eye Care</span>';
            document.getElementById('eyeCareBtn').classList.remove('active');
            
            document.getElementById('focusBtn').innerHTML = '<i class="fas fa-crosshairs"></i><span>Focus</span>';
            document.getElementById('focusBtn').classList.remove('active');
            
            document.getElementById('particles').style.display = 'block';
            document.getElementById('particleBtn').innerHTML = '<i class="fas fa-circle"></i><span>Particles</span>';
            document.getElementById('particleBtn').classList.remove('active');
            
            localStorage.removeItem('theme');
            localStorage.removeItem('eyecare');
            localStorage.removeItem('focus');
            localStorage.removeItem('particles');
        }

        // ============================================
        // LOAD SAVED SETTINGS
        // ============================================
        function loadSettings() {
            const theme = localStorage.getItem('theme');
            const eyecare = localStorage.getItem('eyecare');
            const focus = localStorage.getItem('focus');
            const particles = localStorage.getItem('particles');
            
            if (theme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
                document.getElementById('themeBtn').innerHTML = '<i class="fas fa-sun"></i><span>Theme</span>';
            }
            
            if (eyecare === 'true') {
                document.documentElement.setAttribute('data-eyecare', 'true');
                document.getElementById('eyeCareBtn').classList.add('active');
            }
            
            if (focus === 'true') {
                document.documentElement.setAttribute('data-focus', 'true');
                document.getElementById('focusBtn').classList.add('active');
            }
            
            if (particles === 'false') {
                document.getElementById('particles').style.display = 'none';
                document.getElementById('particleBtn').classList.add('active');
            }
        }

        // ============================================
        // MOUSE PARALLAX FOR PARTICLES
        // ============================================
        document.addEventListener('mousemove', function(e) {
            const particles = document.querySelectorAll('.particle');
            const x = (e.clientX / window.innerWidth - 0.5) * 20;
            const y = (e.clientY / window.innerHeight - 0.5) * 20;
            
            particles.forEach((p, i) => {
                const speed = 1 + (i % 3) * 0.5;
                p.style.transform = `translate(${x * speed * 0.5}px, ${y * speed * 0.5}px)`;
            });
        });

        // ============================================
        // LIVE CLOCK
        // ============================================
        function updateClock() {
            const now = new Date();
            const time = now.toLocaleTimeString('en-US', { 
                hour12: false, 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
            const el = document.getElementById('live_time');
            if (el) el.textContent = time;
        }
        updateClock();
        setInterval(updateClock, 1000);

        // ============================================
        // TAB SWITCHING
        // ============================================
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

        // ============================================
        // PASSWORD TOGGLE
        // ============================================
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

        // ============================================
        // PASSWORD STRENGTH METER
        // ============================================
        function updatePasswordStrength() {
            const password = document.getElementById('reg_password');
            if (!password) return;
            
            const fill = document.getElementById('strength_fill');
            const text = document.getElementById('strength_text');
            const val = password.value;
            
            let strength = 0;
            let label = 'Weak';
            let color = '#f56565';
            
            if (val.length >= 1) {
                strength += 10;
            }
            if (val.length >= 6) {
                strength += 20;
            }
            if (val.length >= 10) {
                strength += 10;
            }
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) {
                strength += 15;
            }
            if (/[0-9]/.test(val)) {
                strength += 15;
            }
            if (/[^a-zA-Z0-9]/.test(val)) {
                strength += 20;
            }
            if (val.length >= 12) {
                strength += 10;
            }
            
            strength = Math.min(strength, 100);
            
            if (strength < 30) {
                label = 'Weak';
                color = '#f56565';
            } else if (strength < 50) {
                label = 'Fair';
                color = '#ed8936';
            } else if (strength < 70) {
                label = 'Good';
                color = '#ecc94b';
            } else if (strength < 85) {
                label = 'Strong';
                color = '#48bb78';
            } else {
                label = 'Very Strong';
                color = '#38a169';
            }
            
            fill.style.width = strength + '%';
            fill.style.background = color;
            text.textContent = label;
            text.style.color = color;
        }

        function updateResetPasswordStrength() {
            const password = document.getElementById('new_password');
            if (!password) return;
            
            const fill = document.getElementById('reset_strength_fill');
            const text = document.getElementById('reset_strength_text');
            const val = password.value;
            
            let strength = 0;
            let label = 'Weak';
            let color = '#f56565';
            
            if (val.length >= 1) strength += 10;
            if (val.length >= 6) strength += 20;
            if (val.length >= 10) strength += 10;
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) strength += 15;
            if (/[0-9]/.test(val)) strength += 15;
            if (/[^a-zA-Z0-9]/.test(val)) strength += 20;
            if (val.length >= 12) strength += 10;
            
            strength = Math.min(strength, 100);
            
            if (strength < 30) { label = 'Weak'; color = '#f56565'; }
            else if (strength < 50) { label = 'Fair'; color = '#ed8936'; }
            else if (strength < 70) { label = 'Good'; color = '#ecc94b'; }
            else if (strength < 85) { label = 'Strong'; color = '#48bb78'; }
            else { label = 'Very Strong'; color = '#38a169'; }
            
            fill.style.width = strength + '%';
            fill.style.background = color;
            text.textContent = label;
            text.style.color = color;
        }

        // ============================================
        // VALIDATION FUNCTIONS
        // ============================================
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
            const password = document.getElementById('reg_password');
            const confirm = document.getElementById('reg_confirm_password');
            const validationDiv = document.getElementById('password_validation');
            
            if (!password || !confirm || !validationDiv) return;
            
            if (confirm.value.length > 0) {
                if (password.value === confirm.value && password.value.length >= 6) {
                    validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                    validationDiv.className = 'validation-message success';
                    return true;
                } else if (password.value !== confirm.value) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Passwords do not match';
                    validationDiv.className = 'validation-message error';
                    return false;
                } else if (password.value.length < 6) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Password must be at least 6 characters';
                    validationDiv.className = 'validation-message error';
                    return false;
                }
            } else {
                validationDiv.innerHTML = '';
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

        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        function openResetModal() {
            document.getElementById('resetModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeResetModal() {
            document.getElementById('resetModal').classList.remove('active');
            document.body.style.overflow = '';
            if (window.location.search.indexOf('reset') === -1) {
                window.location.href = window.location.pathname + '?reset=close';
            }
        }

        document.getElementById('resetModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeResetModal();
            }
        });

        // ============================================
        // KEYBOARD SHORTCUTS
        // ============================================
        document.addEventListener('keydown', function(e) {
            // Ctrl + D: Toggle Dark Mode
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                toggleTheme();
            }
            // Ctrl + E: Toggle Eye Care
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                toggleEyeCare();
            }
            // Ctrl + F: Toggle Focus
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                toggleFocus();
            }
            // Escape: Close Modal
            if (e.key === 'Escape') {
                if (document.getElementById('resetModal').classList.contains('active')) {
                    closeResetModal();
                }
            }
        });

        // ============================================
        // INITIALIZE
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            
            // Add event listeners for password validation
            const regPassword = document.getElementById('reg_password');
            const regConfirm = document.getElementById('reg_confirm_password');
            
            if (regPassword) {
                regPassword.addEventListener('keyup', function() {
                    validatePasswordMatch();
                    updatePasswordStrength();
                });
            }
            if (regConfirm) {
                regConfirm.addEventListener('keyup', validatePasswordMatch);
            }
            
            // Full name trim on space
            const fullName = document.getElementById('reg_full_name');
            if (fullName) {
                fullName.addEventListener('keyup', function() {
                    if (this.value.startsWith(' ')) {
                        this.value = this.value.trimStart();
                    }
                });
            }
        });

        // Console welcome message
        console.log('%c ExamSphere v2.0 ', 'background: linear-gradient(135deg, #667eea, #764ba2); color: white; font-size: 20px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
        console.log('%c 🔒 Secure Login | 🌙 Dark Mode | 👁️ Eye Care | 🎯 Focus Mode ', 'background: #1a1a2e; color: #a8a8d0; font-size: 13px; padding: 8px 16px; border-radius: 8px;');
    </script>
</body>
</html><?php
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
    $reset_email = trim($_POST['reset_identifier']);
    
    if(empty($reset_identifier)) {
        $reset_error = "Please enter your username or email address!";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, email, full_name FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->execute([$reset_identifier, $reset_identifier]);
        $user = $stmt->fetch();
        
        if($user) {
            $_SESSION['reset_user_id'] = $user['id'];
            $_SESSION['reset_username'] = $user['username'];
            $_SESSION['reset_email'] = $user['email'];
            $_SESSION['reset_step'] = 2;
            
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
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            if(function_exists('logActivity')) {
                logActivity($pdo, $user_id, 'password_reset', "Password reset via forgot password feature");
            }
            
            $reset_username = $_SESSION['reset_username'] ?? 'User';
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_username']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_step']);
            
            $reset_success = "✅ Password reset successful! You can now login with your new password.";
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
    
    $secret_key = 'ADMIN_SECRET_2026';
    
    if ($role !== 'admin') {
        $error = "🔒 <strong>Registration is restricted!</strong><br>Only Administrators can create accounts. Please contact the system administrator.";
    } 
    elseif ($admin_key !== $secret_key) {
        $error = "⚠️ <strong>Invalid Admin Verification Key!</strong><br>Please enter the correct admin key to create an account.";
    }
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
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if($stmt->rowCount() > 0) {
                $error = "Username or email already exists!";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
                
                $insertFields = ['username', 'password', 'full_name', 'email', 'role', 'status'];
                $placeholders = ['?', '?', '?', '?', '?', '?'];
                $values = [$username, $hashed_password, $full_name, $email, 'admin', 'active'];
                
                if (in_array('student_id', $columns)) {
                    $insertFields[] = 'student_id';
                    $placeholders[] = '?';
                    $values[] = $student_id;
                }
                
                if (in_array('created_at', $columns)) {
                    $insertFields[] = 'created_at';
                    $placeholders[] = 'NOW()';
                }
                
                $sql = "INSERT INTO users (" . implode(', ', $insertFields) . ") 
                        VALUES (" . implode(', ', $placeholders) . ")";
                
                $stmt = $pdo->prepare($sql);
                
                if($stmt->execute($values)) {
                    $user_id = $pdo->lastInsertId();
                    
                    if (function_exists('assignRole')) {
                        try {
                            assignRole($pdo, $user_id, 'admin', $user_id);
                        } catch (Exception $e) {
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
    <title>ExamSphere | Premium Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================
                   CSS VARIABLES & THEMING
                ============================================ */
        :root {
            /* Light Theme (Default) */
            --bg-primary: #0f0c29;
            --bg-secondary: #302b63;
            --bg-tertiary: #24243e;
            --bg-card: rgba(255,255,255,0.03);
            --bg-card-hover: rgba(255,255,255,0.06);
            --text-primary: #ffffff;
            --text-secondary: rgba(255,255,255,0.7);
            --text-muted: rgba(255,255,255,0.4);
            --border-color: rgba(255,255,255,0.1);
            --input-bg: rgba(255,255,255,0.08);
            --input-border: rgba(255,255,255,0.15);
            --input-focus: rgba(102,126,234,0.3);
            --gradient-1: #667eea;
            --gradient-2: #764ba2;
            --shadow-color: rgba(102,126,234,0.4);
            --orb-1: rgba(102,126,234,0.3);
            --orb-2: rgba(118,75,162,0.3);
            --orb-3: rgba(236,72,153,0.2);
            --particle-color: rgba(255,255,255,0.08);
            --success-color: #48bb78;
            --error-color: #f56565;
            --warning-color: #fbbf24;
            --glass-border: rgba(255,255,255,0.1);
            --glass-backdrop: blur(20px);
            --transition-speed: 0.4s;
        }

        /* Dark Theme */
        [data-theme="dark"] {
            --bg-primary: #0a0a0f;
            --bg-secondary: #151520;
            --bg-tertiary: #1a1a2e;
            --bg-card: rgba(255,255,255,0.02);
            --bg-card-hover: rgba(255,255,255,0.04);
            --text-primary: #e8e8f0;
            --text-secondary: rgba(255,255,255,0.6);
            --text-muted: rgba(255,255,255,0.3);
            --border-color: rgba(255,255,255,0.05);
            --input-bg: rgba(255,255,255,0.04);
            --input-border: rgba(255,255,255,0.08);
            --input-focus: rgba(102,126,234,0.2);
            --orb-1: rgba(102,126,234,0.15);
            --orb-2: rgba(118,75,162,0.15);
            --orb-3: rgba(236,72,153,0.1);
            --particle-color: rgba(255,255,255,0.04);
            --glass-border: rgba(255,255,255,0.04);
            --shadow-color: rgba(102,126,234,0.2);
        }

        /* Eye Care Mode - Warm Tint */
        [data-eyecare="true"] {
            --bg-primary: #1a1410;
            --bg-secondary: #2a1f18;
            --bg-tertiary: #1f1712;
            --text-primary: #f0e6d8;
            --text-secondary: rgba(240,230,216,0.7);
            --text-muted: rgba(240,230,216,0.4);
            --orb-1: rgba(200,150,100,0.2);
            --orb-2: rgba(180,130,80,0.2);
            --orb-3: rgba(160,110,70,0.15);
            --particle-color: rgba(240,230,216,0.04);
            --border-color: rgba(200,170,140,0.1);
            --input-bg: rgba(200,170,140,0.06);
            --input-border: rgba(200,170,140,0.12);
            --glass-border: rgba(200,170,140,0.08);
            --gradient-1: #c4956a;
            --gradient-2: #a87d5a;
            --shadow-color: rgba(200,150,100,0.3);
        }

        /* Focus Mode - Dim Background */
        [data-focus="true"] .container .glass-card {
            box-shadow: 0 0 0 2px rgba(102,126,234,0.3), 0 25px 50px -12px rgba(0,0,0,0.8);
        }

        [data-focus="true"] .particles,
        [data-focus="true"] .orb {
            opacity: 0.3;
            transition: opacity 0.6s ease;
        }

        /* ============================================
                   BASE STYLES
                ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 50%, var(--bg-tertiary) 100%);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
            transition: background var(--transition-speed) ease, color var(--transition-speed) ease;
        }

        /* ============================================
                   ANIMATED PARTICLES
                ============================================ */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
            transition: opacity 0.6s ease;
        }

        .particle {
            position: absolute;
            background: var(--particle-color);
            border-radius: 50%;
            animation: float 15s infinite ease-in-out;
            transition: transform 0.1s ease;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) translateX(0) rotate(0deg); opacity: 0.3; }
            25% { transform: translateY(-50px) translateX(30px) rotate(90deg); opacity: 0.6; }
            50% { transform: translateY(-100px) translateX(-20px) rotate(180deg); opacity: 0.3; }
            75% { transform: translateY(-50px) translateX(-40px) rotate(270deg); opacity: 0.6; }
        }

        /* ============================================
                   GRADIENT ORBS
                ============================================ */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
            transition: all 0.6s ease;
            pointer-events: none;
        }

        .orb-1 {
            width: 400px;
            height: 400px;
            background: var(--orb-1);
            top: -100px;
            left: -100px;
            animation: orbMove 20s infinite;
        }

        .orb-2 {
            width: 500px;
            height: 500px;
            background: var(--orb-2);
            bottom: -150px;
            right: -150px;
            animation: orbMove 25s infinite reverse;
        }

        .orb-3 {
            width: 300px;
            height: 300px;
            background: var(--orb-3);
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

        /* ============================================
                   CONTAINER & GLASS CARD
                ============================================ */
        .container {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: all var(--transition-speed) ease;
        }

        .glass-card {
            background: var(--bg-card);
            backdrop-filter: var(--glass-backdrop);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            overflow: hidden;
            width: 100%;
            max-width: 480px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, all var(--transition-speed) ease;
            position: relative;
        }

        .glass-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 35px 60px -15px rgba(0,0,0,0.6);
        }

        /* ============================================
                   CARD HEADER
                ============================================ */
        .card-header {
            background: linear-gradient(135deg, rgba(102,126,234,0.15) 0%, rgba(118,75,162,0.15) 100%);
            padding: 35px 30px 25px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
            position: relative;
            transition: all var(--transition-speed) ease;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 40px;
            animation: logoGlow 3s infinite;
            transition: all var(--transition-speed) ease;
            position: relative;
        }

        .logo::after {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 27px;
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            opacity: 0.3;
            filter: blur(10px);
            z-index: -1;
            animation: logoPulse 2s infinite;
        }

        @keyframes logoPulse {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.05); }
        }

        @keyframes logoGlow {
            0%, 100% { box-shadow: 0 0 20px rgba(102,126,234,0.3); }
            50% { box-shadow: 0 0 50px rgba(102,126,234,0.6); }
        }

        .card-header h1 {
            color: var(--text-primary);
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
            transition: color var(--transition-speed) ease;
        }

        .card-header p {
            color: var(--text-secondary);
            font-size: 14px;
            transition: color var(--transition-speed) ease;
        }

        /* ============================================
                   CARD BODY
                ============================================ */
        .card-body {
            padding: 30px 30px 20px;
        }

        /* ============================================
                   TABS
                ============================================ */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: var(--input-bg);
            padding: 5px;
            border-radius: 60px;
            border: 1px solid var(--border-color);
            transition: all var(--transition-speed) ease;
        }

        .tab-btn {
            flex: 1;
            background: transparent;
            border: none;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 50px;
            transition: all 0.3s ease;
            color: var(--text-secondary);
            font-family: 'Inter', sans-serif;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            color: white;
            box-shadow: 0 5px 20px var(--shadow-color);
        }

        .tab-btn:hover:not(.active) {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        .tab-content {
            display: none;
            animation: fadeInUp 0.4s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ============================================
                   FORM ELEMENTS
                ============================================ */
        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 500;
            color: var(--text-secondary);
            font-size: 13px;
            letter-spacing: 0.3px;
            transition: color var(--transition-speed) ease;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            z-index: 2;
            transition: color var(--transition-speed) ease;
        }

        .input-wrapper input,
        .input-wrapper select {
            width: 100%;
            padding: 13px 16px 13px 46px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 14px;
            font-size: 14px;
            color: var(--text-primary);
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
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus {
            outline: none;
            border-color: var(--gradient-1);
            background: var(--input-bg);
            box-shadow: 0 0 0 4px var(--input-focus);
        }

        .input-wrapper input::placeholder {
            color: var(--text-muted);
        }

        .input-wrapper input.error {
            border-color: var(--error-color);
        }

        .input-wrapper input.success {
            border-color: var(--success-color);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-muted);
            font-size: 16px;
            transition: color 0.3s;
            z-index: 2;
        }

        .password-toggle:hover {
            color: var(--text-secondary);
        }

        /* ============================================
                   PASSWORD STRENGTH METER
                ============================================ */
        .password-strength {
            margin-top: 8px;
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .strength-bar {
            flex: 1;
            height: 4px;
            background: var(--input-border);
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .strength-bar .fill {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.4s ease, background 0.4s ease;
        }

        .strength-text {
            font-size: 11px;
            color: var(--text-muted);
            min-width: 60px;
            text-align: right;
            transition: color var(--transition-speed) ease;
        }

        /* ============================================
                   BUTTONS
                ============================================ */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
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
            transition: left 0.6s;
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px var(--shadow-color);
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        .btn-submit-secondary {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
        }

        .btn-submit-secondary:hover {
            background: var(--bg-card-hover);
            box-shadow: none;
        }

        /* ============================================
                   ALERTS
                ============================================ */
        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            animation: slideIn 0.3s ease;
            transition: all var(--transition-speed) ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .alert-error {
            background: rgba(245,101,101,0.12);
            border-left: 3px solid var(--error-color);
            color: var(--error-color);
        }

        .alert-success {
            background: rgba(72,187,120,0.12);
            border-left: 3px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-warning {
            background: rgba(251,191,36,0.12);
            border-left: 3px solid var(--warning-color);
            color: var(--warning-color);
        }

        /* ============================================
                   FORGOT PASSWORD LINK
                ============================================ */
        .forgot-password-link {
            display: inline-block;
            margin-top: 14px;
            color: var(--gradient-1);
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
            background: none;
            border: none;
            font-family: 'Inter', sans-serif;
        }

        .forgot-password-link:hover {
            text-decoration: underline;
            opacity: 0.8;
        }

        .login-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }

        /* ============================================
                   CARD FOOTER
                ============================================ */
        .card-footer {
            padding: 16px 30px 25px;
            text-align: center;
            border-top: 1px solid var(--border-color);
            transition: all var(--transition-speed) ease;
        }

        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            font-size: 11px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }

        .security-badge i {
            font-size: 12px;
        }

        .copyright {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 12px;
            opacity: 0.6;
        }

        /* ============================================
                   CONTROL BAR (Theme, Eye Care, Focus)
                ============================================ */
        .control-bar {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
            padding: 12px 20px;
            background: var(--bg-card);
            backdrop-filter: var(--glass-backdrop);
            border-radius: 60px;
            border: 1px solid var(--border-color);
            position: fixed;
            bottom: 25px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
            transition: all var(--transition-speed) ease;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .control-bar button {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 18px;
            padding: 8px 12px;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

        .control-bar button:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        .control-bar button.active {
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            color: white;
            box-shadow: 0 5px 20px var(--shadow-color);
        }

        .control-divider {
            width: 1px;
            height: 25px;
            background: var(--border-color);
        }

        /* ============================================
                   MODAL (Forgot Password)
                ============================================ */
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
            background: var(--bg-card);
            backdrop-filter: var(--glass-backdrop);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            padding: 35px;
            max-width: 450px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.4s ease;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            transition: all var(--transition-speed) ease;
            position: relative;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .modal-header h2 {
            color: var(--text-primary);
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
            transition: color var(--transition-speed) ease;
        }

        .modal-header p {
            color: var(--text-secondary);
            font-size: 14px;
            transition: color var(--transition-speed) ease;
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 20px;
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
            transition: color 0.3s;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .reset-step-indicator {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 22px;
        }

        .reset-step {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            transition: color var(--transition-speed) ease;
        }

        .reset-step.active {
            color: var(--gradient-1);
        }

        .reset-step .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--input-bg);
            border: 2px solid var(--border-color);
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
            transition: all var(--transition-speed) ease;
        }

        .reset-step.active .step-circle {
            background: linear-gradient(135deg, var(--gradient-1), var(--gradient-2));
            border-color: var(--gradient-1);
            color: white;
            box-shadow: 0 5px 20px var(--shadow-color);
        }

        .reset-step.done .step-circle {
            background: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }

        .reset-step.done {
            color: var(--success-color);
        }

        /* ============================================
                   VALIDATION MESSAGES
                ============================================ */
        .validation-message {
            font-size: 11px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .validation-message.error {
            color: var(--error-color);
        }

        .validation-message.success {
            color: var(--success-color);
        }

        .validation-message .fa-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ============================================
                   RESPONSIVE
                ============================================ */
        @media (max-width: 500px) {
            .card-body, .card-footer {
                padding: 20px;
            }
            .card-header {
                padding: 25px 20px;
            }
            .card-header h1 {
                font-size: 22px;
            }
            .tabs {
                gap: 6px;
            }
            .tab-btn {
                padding: 9px 12px;
                font-size: 12px;
            }
            .control-bar {
                bottom: 15px;
                padding: 8px 14px;
                gap: 4px;
                flex-wrap: wrap;
                justify-content: center;
            }
            .control-bar button {
                font-size: 12px;
                padding: 6px 10px;
            }
            .modal-content {
                padding: 25px 20px;
            }
            .logo {
                width: 65px;
                height: 65px;
                font-size: 32px;
            }
            .reset-step-indicator {
                gap: 8px;
            }
            .reset-step {
                font-size: 10px;
            }
            .reset-step .step-circle {
                width: 25px;
                height: 25px;
                font-size: 11px;
            }
        }

        @media (max-width: 380px) {
            .control-bar button span {
                display: none;
            }
            .control-bar button {
                font-size: 16px;
                padding: 6px 10px;
            }
            .login-options {
                flex-direction: column;
                align-items: stretch;
            }
            .forgot-password-link {
                text-align: center;
            }
        }

        /* ============================================
                   UTILITY
                ============================================ */
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .gap-1 { gap: 4px; }
        .gap-2 { gap: 8px; }
        .flex { display: flex; }
        .flex-center { display: flex; align-items: center; justify-content: center; }
        .flex-col { flex-direction: column; }
        .items-center { align-items: center; }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: var(--gradient-1);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: var(--gradient-2);
        }

        /* Selection */
        ::selection {
            background: var(--gradient-1);
            color: white;
        }
    </style>
</head>
<body>
    <!-- ============================================
    ANIMATED PARTICLES
    ============================================ -->
    <div class="particles" id="particles">
        <div class="particle" style="width: 100px; height: 100px; top: 10%; left: 5%; animation-duration: 12s;"></div>
        <div class="particle" style="width: 150px; height: 150px; bottom: 15%; right: 8%; animation-duration: 18s;"></div>
        <div class="particle" style="width: 70px; height: 70px; top: 40%; left: 80%; animation-duration: 14s;"></div>
        <div class="particle" style="width: 120px; height: 120px; bottom: 30%; left: 10%; animation-duration: 22s;"></div>
        <div class="particle" style="width: 60px; height: 60px; top: 70%; right: 20%; animation-duration: 10s;"></div>
        <div class="particle" style="width: 90px; height: 90px; top: 20%; right: 40%; animation-duration: 16s;"></div>
        <div class="particle" style="width: 50px; height: 50px; bottom: 40%; right: 60%; animation-duration: 13s;"></div>
    </div>
    
    <!-- ============================================
    GRADIENT ORBS
    ============================================ -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <!-- ============================================
    MAIN CONTAINER
    ============================================ -->
    <div class="container">
        <div class="glass-card">
            <!-- Card Header -->
            <div class="card-header">
                <div class="logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h1>ExamSphere</h1>
                <p>Next-Generation Online Examination Platform</p>
            </div>
            
            <!-- Card Body -->
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
                                <input type="text" name="username" id="login_username" required 
                                       placeholder="Enter your username or email"
                                       autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-key"></i></span>
                                <input type="password" name="password" id="login_password" required 
                                       placeholder="Enter your password"
                                       autocomplete="current-password">
                                <span class="password-toggle" onclick="togglePassword('login_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="login-options">
                            <button type="submit" name="login" class="btn-submit" style="flex:1;">
                                <i class="fas fa-arrow-right"></i> Sign In
                            </button>
                        </div>
                        
                        <div class="text-center mt-2">
                            <button type="button" class="forgot-password-link" onclick="openResetModal()">
                                <i class="fas fa-key"></i> Forgot Password?
                            </button>
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
                                       placeholder="Choose a username"
                                       autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email Address</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" id="reg_email" required 
                                       placeholder="Enter your email"
                                       autocomplete="email">
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
                            <div style="font-size:11px; color:var(--text-muted); margin-top:5px;">
                                <i class="fas fa-info-circle"></i> Only Administrator accounts can be created
                            </div>
                        </div>
                        
                        <div class="form-group" id="admin_key_group">
                            <label><i class="fas fa-key"></i> Admin Verification Key <span style="color:var(--error-color);">*</span></label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="admin_key" id="reg_admin_key" required 
                                       placeholder="Enter admin verification key">
                            </div>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:5px;">
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
                                       placeholder="Create a password (min 6 characters)"
                                       onkeyup="updatePasswordStrength()">
                                <span class="password-toggle" onclick="togglePassword('reg_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                            <!-- Password Strength Meter -->
                            <div class="password-strength" id="strength_meter">
                                <div class="strength-bar">
                                    <div class="fill" id="strength_fill"></div>
                                </div>
                                <span class="strength-text" id="strength_text">Weak</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i> Confirm Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="confirm_password" id="reg_confirm_password" required 
                                       placeholder="Confirm your password"
                                       onkeyup="validatePasswordMatch()">
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
            
            <!-- Card Footer -->
            <div class="card-footer">
                <div class="security-badge">
                    <i class="fas fa-shield-alt"></i>
                    <span>256-bit SSL Encrypted</span>
                    <i class="fas fa-database"></i>
                    <span>Secure Database</span>
                    <i class="fas fa-clock"></i>
                    <span id="live_time">--:--:--</span>
                </div>
                <div class="copyright">
                    <i class="far fa-copyright"></i> <?php echo date('Y'); ?> ExamSphere. All rights reserved.
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    CONTROL BAR - Theme, Eye Care, Focus
    ============================================ -->
    <div class="control-bar" id="controlBar">
        <button onclick="toggleTheme()" id="themeBtn" title="Toggle Dark/Light Mode">
            <i class="fas fa-moon"></i>
            <span>Theme</span>
        </button>
        
        <div class="control-divider"></div>
        
        <button onclick="toggleEyeCare()" id="eyeCareBtn" title="Eye Care Mode - Reduce Eye Strain">
            <i class="fas fa-eye"></i>
            <span>Eye Care</span>
        </button>
        
        <div class="control-divider"></div>
        
        <button onclick="toggleFocus()" id="focusBtn" title="Focus Mode - Dim Distractions">
            <i class="fas fa-crosshairs"></i>
            <span>Focus</span>
        </button>
        
        <div class="control-divider"></div>
        
        <button onclick="toggleParticles()" id="particleBtn" title="Toggle Particle Animation">
            <i class="fas fa-circle"></i>
            <span>Particles</span>
        </button>
        
        <div class="control-divider"></div>
        
        <button onclick="resetSettings()" title="Reset All Settings">
            <i class="fas fa-undo"></i>
            <span>Reset</span>
        </button>
    </div>

    <!-- ============================================
    FORGOT PASSWORD MODAL
    ============================================ -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeResetModal()">&times;</button>
            
            <div class="modal-header">
                <h2><i class="fas fa-key" style="color: var(--gradient-1);"></i> Reset Password</h2>
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
                <div class="alert alert-success" style="margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $reset_success; ?>
                </div>
                <button class="btn-submit" onclick="closeResetModal()">
                    <i class="fas fa-arrow-right"></i> Return to Login
                </button>
            <?php else: ?>
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
                            <div style="font-size:11px; color:var(--text-muted); margin-top:5px;">
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
                                       placeholder="Enter new password (min 6 characters)"
                                       onkeyup="validateResetPasswordMatch(); updateResetPasswordStrength();">
                                <span class="password-toggle" onclick="togglePassword('new_password', this)">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                            <!-- Reset Password Strength -->
                            <div class="password-strength" id="reset_strength_meter">
                                <div class="strength-bar">
                                    <div class="fill" id="reset_strength_fill"></div>
                                </div>
                                <span class="strength-text" id="reset_strength_text">Weak</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i> Confirm New Password</label>
                            <div class="input-wrapper">
                                <span class="input-icon"><i class="fas fa-shield-alt"></i></span>
                                <input type="password" name="confirm_new_password" id="confirm_new_password" required 
                                       placeholder="Confirm your new password"
                                       onkeyup="validateResetPasswordMatch()">
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
            
            <div class="text-center mt-2">
                <button style="color:var(--text-muted); font-size:13px; cursor:pointer; background:none; border:none; font-family:'Inter',sans-serif;" onclick="closeResetModal()">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================
    JAVASCRIPT
    ============================================ -->
    <script>
        // ============================================
        // THEME MANAGEMENT
        // ============================================
        function toggleTheme() {
            const html = document.documentElement;
            const btn = document.getElementById('themeBtn');
            const currentTheme = html.getAttribute('data-theme');
            
            if (currentTheme === 'dark') {
                html.removeAttribute('data-theme');
                btn.innerHTML = '<i class="fas fa-moon"></i><span>Theme</span>';
                localStorage.setItem('theme', 'light');
            } else {
                html.setAttribute('data-theme', 'dark');
                btn.innerHTML = '<i class="fas fa-sun"></i><span>Theme</span>';
                localStorage.setItem('theme', 'dark');
            }
        }

        // ============================================
        // EYE CARE MODE
        // ============================================
        function toggleEyeCare() {
            const html = document.documentElement;
            const btn = document.getElementById('eyeCareBtn');
            const current = html.getAttribute('data-eyecare');
            
            if (current === 'true') {
                html.removeAttribute('data-eyecare');
                btn.innerHTML = '<i class="fas fa-eye"></i><span>Eye Care</span>';
                btn.classList.remove('active');
                localStorage.setItem('eyecare', 'false');
            } else {
                html.setAttribute('data-eyecare', 'true');
                btn.innerHTML = '<i class="fas fa-eye"></i><span>Eye Care</span>';
                btn.classList.add('active');
                localStorage.setItem('eyecare', 'true');
            }
        }

        // ============================================
        // FOCUS MODE
        // ============================================
        function toggleFocus() {
            const html = document.documentElement;
            const btn = document.getElementById('focusBtn');
            const current = html.getAttribute('data-focus');
            
            if (current === 'true') {
                html.removeAttribute('data-focus');
                btn.innerHTML = '<i class="fas fa-crosshairs"></i><span>Focus</span>';
                btn.classList.remove('active');
                localStorage.setItem('focus', 'false');
            } else {
                html.setAttribute('data-focus', 'true');
                btn.innerHTML = '<i class="fas fa-crosshairs"></i><span>Focus</span>';
                btn.classList.add('active');
                localStorage.setItem('focus', 'true');
            }
        }

        // ============================================
        // PARTICLES TOGGLE
        // ============================================
        function toggleParticles() {
            const particles = document.getElementById('particles');
            const btn = document.getElementById('particleBtn');
            
            if (particles.style.display === 'none') {
                particles.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-circle"></i><span>Particles</span>';
                btn.classList.remove('active');
                localStorage.setItem('particles', 'true');
            } else {
                particles.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-circle"></i><span>Particles</span>';
                btn.classList.add('active');
                localStorage.setItem('particles', 'false');
            }
        }

        // ============================================
        // RESET SETTINGS
        // ============================================
        function resetSettings() {
            const html = document.documentElement;
            html.removeAttribute('data-theme');
            html.removeAttribute('data-eyecare');
            html.removeAttribute('data-focus');
            
            document.getElementById('themeBtn').innerHTML = '<i class="fas fa-moon"></i><span>Theme</span>';
            document.getElementById('themeBtn').classList.remove('active');
            
            document.getElementById('eyeCareBtn').innerHTML = '<i class="fas fa-eye"></i><span>Eye Care</span>';
            document.getElementById('eyeCareBtn').classList.remove('active');
            
            document.getElementById('focusBtn').innerHTML = '<i class="fas fa-crosshairs"></i><span>Focus</span>';
            document.getElementById('focusBtn').classList.remove('active');
            
            document.getElementById('particles').style.display = 'block';
            document.getElementById('particleBtn').innerHTML = '<i class="fas fa-circle"></i><span>Particles</span>';
            document.getElementById('particleBtn').classList.remove('active');
            
            localStorage.removeItem('theme');
            localStorage.removeItem('eyecare');
            localStorage.removeItem('focus');
            localStorage.removeItem('particles');
        }

        // ============================================
        // LOAD SAVED SETTINGS
        // ============================================
        function loadSettings() {
            const theme = localStorage.getItem('theme');
            const eyecare = localStorage.getItem('eyecare');
            const focus = localStorage.getItem('focus');
            const particles = localStorage.getItem('particles');
            
            if (theme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
                document.getElementById('themeBtn').innerHTML = '<i class="fas fa-sun"></i><span>Theme</span>';
            }
            
            if (eyecare === 'true') {
                document.documentElement.setAttribute('data-eyecare', 'true');
                document.getElementById('eyeCareBtn').classList.add('active');
            }
            
            if (focus === 'true') {
                document.documentElement.setAttribute('data-focus', 'true');
                document.getElementById('focusBtn').classList.add('active');
            }
            
            if (particles === 'false') {
                document.getElementById('particles').style.display = 'none';
                document.getElementById('particleBtn').classList.add('active');
            }
        }

        // ============================================
        // MOUSE PARALLAX FOR PARTICLES
        // ============================================
        document.addEventListener('mousemove', function(e) {
            const particles = document.querySelectorAll('.particle');
            const x = (e.clientX / window.innerWidth - 0.5) * 20;
            const y = (e.clientY / window.innerHeight - 0.5) * 20;
            
            particles.forEach((p, i) => {
                const speed = 1 + (i % 3) * 0.5;
                p.style.transform = `translate(${x * speed * 0.5}px, ${y * speed * 0.5}px)`;
            });
        });

        // ============================================
        // LIVE CLOCK
        // ============================================
        function updateClock() {
            const now = new Date();
            const time = now.toLocaleTimeString('en-US', { 
                hour12: false, 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
            const el = document.getElementById('live_time');
            if (el) el.textContent = time;
        }
        updateClock();
        setInterval(updateClock, 1000);

        // ============================================
        // TAB SWITCHING
        // ============================================
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

        // ============================================
        // PASSWORD TOGGLE
        // ============================================
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

        // ============================================
        // PASSWORD STRENGTH METER
        // ============================================
        function updatePasswordStrength() {
            const password = document.getElementById('reg_password');
            if (!password) return;
            
            const fill = document.getElementById('strength_fill');
            const text = document.getElementById('strength_text');
            const val = password.value;
            
            let strength = 0;
            let label = 'Weak';
            let color = '#f56565';
            
            if (val.length >= 1) {
                strength += 10;
            }
            if (val.length >= 6) {
                strength += 20;
            }
            if (val.length >= 10) {
                strength += 10;
            }
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) {
                strength += 15;
            }
            if (/[0-9]/.test(val)) {
                strength += 15;
            }
            if (/[^a-zA-Z0-9]/.test(val)) {
                strength += 20;
            }
            if (val.length >= 12) {
                strength += 10;
            }
            
            strength = Math.min(strength, 100);
            
            if (strength < 30) {
                label = 'Weak';
                color = '#f56565';
            } else if (strength < 50) {
                label = 'Fair';
                color = '#ed8936';
            } else if (strength < 70) {
                label = 'Good';
                color = '#ecc94b';
            } else if (strength < 85) {
                label = 'Strong';
                color = '#48bb78';
            } else {
                label = 'Very Strong';
                color = '#38a169';
            }
            
            fill.style.width = strength + '%';
            fill.style.background = color;
            text.textContent = label;
            text.style.color = color;
        }

        function updateResetPasswordStrength() {
            const password = document.getElementById('new_password');
            if (!password) return;
            
            const fill = document.getElementById('reset_strength_fill');
            const text = document.getElementById('reset_strength_text');
            const val = password.value;
            
            let strength = 0;
            let label = 'Weak';
            let color = '#f56565';
            
            if (val.length >= 1) strength += 10;
            if (val.length >= 6) strength += 20;
            if (val.length >= 10) strength += 10;
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) strength += 15;
            if (/[0-9]/.test(val)) strength += 15;
            if (/[^a-zA-Z0-9]/.test(val)) strength += 20;
            if (val.length >= 12) strength += 10;
            
            strength = Math.min(strength, 100);
            
            if (strength < 30) { label = 'Weak'; color = '#f56565'; }
            else if (strength < 50) { label = 'Fair'; color = '#ed8936'; }
            else if (strength < 70) { label = 'Good'; color = '#ecc94b'; }
            else if (strength < 85) { label = 'Strong'; color = '#48bb78'; }
            else { label = 'Very Strong'; color = '#38a169'; }
            
            fill.style.width = strength + '%';
            fill.style.background = color;
            text.textContent = label;
            text.style.color = color;
        }

        // ============================================
        // VALIDATION FUNCTIONS
        // ============================================
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
            const password = document.getElementById('reg_password');
            const confirm = document.getElementById('reg_confirm_password');
            const validationDiv = document.getElementById('password_validation');
            
            if (!password || !confirm || !validationDiv) return;
            
            if (confirm.value.length > 0) {
                if (password.value === confirm.value && password.value.length >= 6) {
                    validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                    validationDiv.className = 'validation-message success';
                    return true;
                } else if (password.value !== confirm.value) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Passwords do not match';
                    validationDiv.className = 'validation-message error';
                    return false;
                } else if (password.value.length < 6) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Password must be at least 6 characters';
                    validationDiv.className = 'validation-message error';
                    return false;
                }
            } else {
                validationDiv.innerHTML = '';
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

        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        function openResetModal() {
            document.getElementById('resetModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeResetModal() {
            document.getElementById('resetModal').classList.remove('active');
            document.body.style.overflow = '';
            if (window.location.search.indexOf('reset') === -1) {
                window.location.href = window.location.pathname + '?reset=close';
            }
        }

        document.getElementById('resetModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeResetModal();
            }
        });

        // ============================================
        // KEYBOARD SHORTCUTS
        // ============================================
        document.addEventListener('keydown', function(e) {
            // Ctrl + D: Toggle Dark Mode
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                toggleTheme();
            }
            // Ctrl + E: Toggle Eye Care
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                toggleEyeCare();
            }
            // Ctrl + F: Toggle Focus
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                toggleFocus();
            }
            // Escape: Close Modal
            if (e.key === 'Escape') {
                if (document.getElementById('resetModal').classList.contains('active')) {
                    closeResetModal();
                }
            }
        });

        // ============================================
        // INITIALIZE
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            
            // Add event listeners for password validation
            const regPassword = document.getElementById('reg_password');
            const regConfirm = document.getElementById('reg_confirm_password');
            
            if (regPassword) {
                regPassword.addEventListener('keyup', function() {
                    validatePasswordMatch();
                    updatePasswordStrength();
                });
            }
            if (regConfirm) {
                regConfirm.addEventListener('keyup', validatePasswordMatch);
            }
            
            // Full name trim on space
            const fullName = document.getElementById('reg_full_name');
            if (fullName) {
                fullName.addEventListener('keyup', function() {
                    if (this.value.startsWith(' ')) {
                        this.value = this.value.trimStart();
                    }
                });
            }
        });

        // Console welcome message
        console.log('%c ExamSphere v2.0 ', 'background: linear-gradient(135deg, #667eea, #764ba2); color: white; font-size: 20px; font-weight: bold; padding: 10px 20px; border-radius: 8px;');
        console.log('%c 🔒 Secure Login | 🌙 Dark Mode | 👁️ Eye Care | 🎯 Focus Mode ', 'background: #1a1a2e; color: #a8a8d0; font-size: 13px; padding: 8px 16px; border-radius: 8px;');
    </script>
</body>
</html>
