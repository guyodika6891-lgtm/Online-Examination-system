<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

// Check if admin
if(!isset($_SESSION['user_id']) || $_SESSION['primary_role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$message = '';
$error = '';
$success = '';

// Get admin current data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

// Handle profile update
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate full name
    if (!preg_match("/^[a-zA-Z\s]+$/", $full_name)) {
        $error = "Full name can only contain letters and spaces!";
    } elseif (strlen($full_name) < 3) {
        $error = "Full name must be at least 3 characters!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address!";
    } else {
        try {
            // Check if email already used by another user
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_stmt->execute([$email, $admin_id]);
            if($check_stmt->rowCount() > 0) {
                $error = "Email already in use by another account!";
            } else {
                // Start building update query
                $update_fields = ["full_name = ?", "email = ?", "phone = ?", "updated_at = NOW()"];
                $update_params = [$full_name, $email, $phone];
                
                // Handle password change if requested
                if(!empty($current_password) && !empty($new_password)) {
                    // Verify current password
                    if(password_verify($current_password, $admin['password'])) {
                        if(strlen($new_password) < 8) {
                            $error = "New password must be at least 8 characters!";
                        } elseif($new_password !== $confirm_password) {
                            $error = "Passwords do not match!";
                        } elseif(password_verify($new_password, $admin['password'])) {
                            $error = "New password cannot be the same as current password!";
                        } else {
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            $update_fields[] = "password = ?";
                            $update_params[] = $hashed_password;
                        }
                    } else {
                        $error = "Current password is incorrect!";
                    }
                } elseif(!empty($new_password) || !empty($confirm_password)) {
                    $error = "Please enter your current password to change it!";
                }
                
                if(empty($error)) {
                    $update_params[] = $admin_id;
                    $sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    
                    if($stmt->execute($update_params)) {
                        // Update session
                        $_SESSION['full_name'] = $full_name;
                        
                        // Log activity
                        logActivity($pdo, $admin_id, 'profile_updated', "Admin updated profile");
                        
                        $success = "Profile updated successfully!";
                        
                        // Refresh admin data
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                        $stmt->execute([$admin_id]);
                        $admin = $stmt->fetch();
                    } else {
                        $error = "Failed to update profile. Please try again.";
                    }
                }
            }
        } catch(PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Profile</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 10px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .profile-header { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #f0f0f0; }
        .profile-avatar { width: 100px; height: 100px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; color: white; }
        .profile-info h2 { margin: 0; color: #333; }
        .profile-info p { margin: 5px 0 0 0; color: #666; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; transition: all 0.3s; }
        .form-group input:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.2); outline: none; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 12px 30px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        .btn-secondary { background: #6c757d; color: white; padding: 12px 30px; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .password-section { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px; border: 1px solid #e9ecef; }
        .password-section h4 { margin-top: 0; color: #333; }
        .security-badge { display: inline-block; background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; margin-left: 10px; }
        .field-disabled { background: #e9ecef; cursor: not-allowed; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 11px; }
        .badge-active { background: #28a745; color: white; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header"><h2>📚 Exam System</h2><p>Admin Portal</p></div>
            <div class="user-profile"><div class="user-avatar">👨‍💼</div><div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div><div class="user-role">Administrator</div></div>
            <ul class="sidebar-nav">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><span class="nav-icon">👥</span><span class="nav-text">Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_roles.php" class="nav-link"><span class="nav-icon">🎭</span><span class="nav-text">Manage Roles</span></a></li>
                <li class="nav-item"><a href="manage_courses.php" class="nav-link"><span class="nav-icon">📚</span><span class="nav-text">Manage Courses</span></a></li>
                <li class="nav-item"><a href="manage_enrollments.php" class="nav-link"><span class="nav-icon">📝</span><span class="nav-text">Enroll Students</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link active"><span class="nav-icon">👤</span><span class="nav-text">My Profile</span></a></li>
                <li class="nav-item"><a href="system_settings.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>My Profile</h1><p>Manage your account information</p></div>
            </div>
            
            <div class="container">
                <?php if($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="profile-header">
                        <div class="profile-avatar">👨‍💼</div>
                        <div class="profile-info">
                            <h2><?php echo htmlspecialchars($admin['full_name']); ?></h2>
                            <p>👑 Administrator <span class="security-badge">🔒 Secure</span></p>
                            <p style="color: #666; font-size: 14px;"><?php echo htmlspecialchars($admin['username']); ?> • <?php echo htmlspecialchars($admin['email']); ?></p>
                        </div>
                    </div>
                    
                    <form method="POST" onsubmit="return validateForm()">
                        <div class="form-group">
                            <label>👤 Username <span style="color: #999; font-weight: normal;">(Cannot be changed)</span></label>
                            <input type="text" value="<?php echo htmlspecialchars($admin['username']); ?>" disabled class="field-disabled">
                            <small style="color: #666;">Username is permanent for system reference</small>
                        </div>
                        
                        <div class="form-group">
                            <label>📛 Full Name *</label>
                            <input type="text" name="full_name" id="full_name" required 
                                   value="<?php echo htmlspecialchars($admin['full_name']); ?>"
                                   onkeyup="validateName()">
                            <div id="name_validation" style="font-size: 12px; margin-top: 5px;"></div>
                        </div>
                        
                        <div class="form-group">
                            <label>📧 Email Address *</label>
                            <input type="email" name="email" required value="<?php echo htmlspecialchars($admin['email']); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>📱 Phone Number</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>" placeholder="e.g., +1234567890">
                        </div>
                        
                        <div class="password-section">
                            <h4>🔑 Change Password <span style="font-weight: normal; font-size: 14px; color: #666;">(Optional)</span></h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Current Password</label>
                                    <input type="password" name="current_password" id="current_password" placeholder="Enter current password">
                                </div>
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" id="new_password" placeholder="Min 8 characters" onkeyup="validatePassword()">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" onkeyup="validatePasswordMatch()">
                                </div>
                                <div class="form-group" style="display: flex; align-items: flex-end;">
                                    <div id="password_strength" style="font-size: 12px; padding: 5px; border-radius: 4px; width: 100%;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" name="update_profile" class="btn-primary">💾 Update Profile</button>
                            <a href="dashboard.php" class="btn-secondary">← Back to Dashboard</a>
                        </div>
                    </form>
                </div>
                
                <div class="card" style="background: #f8f9fa; border: 1px solid #dee2e6;">
                    <h4>📋 Account Information</h4>
                    <table style="width: 100%; margin-top: 10px;">
                        <tr><td style="padding: 8px 0; color: #666;"><strong>Account Created:</strong></td><td><?php echo date('F d, Y h:i A', strtotime($admin['created_at'])); ?></td></tr>
                        <tr><td style="padding: 8px 0; color: #666;"><strong>Last Login:</strong></td><td><?php echo $admin['last_login'] ? date('F d, Y h:i A', strtotime($admin['last_login'])) : 'Never'; ?></td></tr>
                        <tr><td style="padding: 8px 0; color: #666;"><strong>Account Status:</strong></td><td><span class="badge badge-active">✅ Active</span></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function validateName() {
            const input = document.getElementById('full_name');
            const validationDiv = document.getElementById('name_validation');
            const nameValue = input.value.trim();
            const nameRegex = /^[a-zA-Z\s]*$/;
            
            if (nameValue.length > 0) {
                if (nameRegex.test(nameValue) && nameValue.length >= 3) {
                    input.style.borderColor = '#28a745';
                    validationDiv.innerHTML = '✅ Valid name';
                    validationDiv.style.color = '#28a745';
                    return true;
                } else if (!nameRegex.test(nameValue)) {
                    input.style.borderColor = '#dc3545';
                    validationDiv.innerHTML = '❌ Only letters and spaces allowed';
                    validationDiv.style.color = '#dc3545';
                    return false;
                } else if (nameValue.length < 3) {
                    input.style.borderColor = '#dc3545';
                    validationDiv.innerHTML = '❌ Minimum 3 characters';
                    validationDiv.style.color = '#dc3545';
                    return false;
                }
            } else {
                input.style.borderColor = '#ddd';
                validationDiv.innerHTML = '';
            }
            return true;
        }
        
        function validatePassword() {
            const password = document.getElementById('new_password').value;
            const strengthDiv = document.getElementById('password_strength');
            
            if (password.length > 0) {
                if (password.length < 8) {
                    strengthDiv.innerHTML = '❌ Password must be at least 8 characters';
                    strengthDiv.style.color = '#dc3545';
                    return false;
                } else if (password.length >= 8 && password.length < 12) {
                    strengthDiv.innerHTML = '⚠️ Medium - Add more characters for strength';
                    strengthDiv.style.color = '#ffc107';
                    return true;
                } else {
                    strengthDiv.innerHTML = '✅ Strong password';
                    strengthDiv.style.color = '#28a745';
                    return true;
                }
            } else {
                strengthDiv.innerHTML = '';
            }
            return true;
        }
        
        function validatePasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const confirmInput = document.getElementById('confirm_password');
            
            if (confirm.length > 0 && password.length > 0) {
                if (password === confirm) {
                    confirmInput.style.borderColor = '#28a745';
                    return true;
                } else {
                    confirmInput.style.borderColor = '#dc3545';
                    return false;
                }
            }
            return true;
        }
        
        function validateForm() {
            // Validate name
            if (!validateName()) {
                alert('Please enter a valid name!');
                return false;
            }
            
            // Validate password if provided
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword.length > 0 || confirmPassword.length > 0 || currentPassword.length > 0) {
                if (currentPassword.length === 0) {
                    alert('Please enter your current password to change it!');
                    return false;
                }
                if (newPassword.length < 8) {
                    alert('New password must be at least 8 characters!');
                    return false;
                }
                if (newPassword !== confirmPassword) {
                    alert('Passwords do not match!');
                    return false;
                }
            }
            
            return true;
        }
        
        // Prevent spaces at beginning of name
        document.getElementById('full_name')?.addEventListener('keyup', function() {
            if (this.value.startsWith(' ')) {
                this.value = this.value.trimStart();
            }
        });
    </script>
</body>
</html>
