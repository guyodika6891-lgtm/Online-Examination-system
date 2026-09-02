<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';
$success = '';

// Get current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get or create notification settings
$stmt = $pdo->prepare("SELECT * FROM notification_settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$notification_settings = $stmt->fetch();

if(!$notification_settings) {
    // Create default settings
    $stmt = $pdo->prepare("
        INSERT INTO notification_settings (user_id, email_notifications, exam_reminders, result_notifications, certificate_available) 
        VALUES (?, 1, 1, 1, 1)
    ");
    $stmt->execute([$user_id]);
    
    // Fetch again
    $stmt = $pdo->prepare("SELECT * FROM notification_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $notification_settings = $stmt->fetch();
}

// Handle profile update
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    if (!preg_match("/^[a-zA-Z\s]+$/", $full_name)) {
        $error = "Full name can only contain letters and spaces!";
    } elseif (strlen($full_name) < 3) {
        $error = "Full name must be at least 3 characters!";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
        if($stmt->execute([$full_name, $email, $phone, $user_id])) {
            $_SESSION['full_name'] = $full_name;
            $success = "Profile updated successfully!";
            
            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        } else {
            $error = "Failed to update profile.";
        }
    }
}

// Handle password change
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if(password_verify($current_password, $user['password'])) {
        if($new_password === $confirm_password) {
            if(strlen($new_password) >= 6) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                if($stmt->execute([$hashed_password, $user_id])) {
                    $success = "Password changed successfully!";
                } else {
                    $error = "Failed to change password.";
                }
            } else {
                $error = "New password must be at least 6 characters!";
            }
        } else {
            $error = "New passwords do not match!";
        }
    } else {
        $error = "Current password is incorrect!";
    }
}

// Handle notification settings update
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_notifications'])) {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $exam_reminders = isset($_POST['exam_reminders']) ? 1 : 0;
    $result_notifications = isset($_POST['result_notifications']) ? 1 : 0;
    $certificate_available = isset($_POST['certificate_available']) ? 1 : 0;
    
    $stmt = $pdo->prepare("
        UPDATE notification_settings 
        SET email_notifications = ?, exam_reminders = ?, result_notifications = ?, certificate_available = ?
        WHERE user_id = ?
    ");
    if($stmt->execute([$email_notifications, $exam_reminders, $result_notifications, $certificate_available, $user_id])) {
        $success = "Notification settings updated successfully!";
        
        // Refresh settings
        $stmt = $pdo->prepare("SELECT * FROM notification_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $notification_settings = $stmt->fetch();
    } else {
        $error = "Failed to update notification settings.";
    }
}

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM student_courses WHERE student_id = ? AND status = 'active') as enrolled_courses,
        (SELECT COUNT(*) FROM results WHERE student_id = ?) as exams_taken,
        (SELECT COALESCE(AVG(percentage), 0) FROM results WHERE student_id = ?) as avg_score,
        (SELECT MAX(percentage) FROM results WHERE student_id = ?) as best_score,
        (SELECT COUNT(*) FROM certificates WHERE student_id = ?) as certificates_count
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Settings - Student Portal</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .settings-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
        .profile-card { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .profile-header { background: linear-gradient(135deg, #17a2b8, #138496); padding: 30px; text-align: center; color: white; }
        .profile-avatar { width: 100px; height: 100px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; font-size: 50px; border: 3px solid white; }
        .profile-stats { padding: 20px; background: #f8f9fa; display: flex; justify-content: space-around; flex-wrap: wrap; gap: 15px; }
        .stat-value { font-size: 24px; font-weight: bold; color: #17a2b8; }
        .settings-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 25px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .checkbox-group input { width: auto; margin-right: 10px; }
        .btn-primary { background: linear-gradient(135deg, #17a2b8, #138496); color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; }
        .btn-secondary { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .role-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin: 2px; }
        .role-badge.student { background: #17a2b8; color: white; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        hr { margin: 20px 0; border: none; border-top: 1px solid #e0e0e0; }
        @media (max-width: 768px) { .settings-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <div class="dashboard-layout">
        <div class="sidebar">
            <div class="sidebar-header"><h2>📚 Exam System</h2><p>Student Portal</p></div>
            <div class="user-profile"><div class="user-avatar">👨‍🎓</div><div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div><div class="user-role">Student</div></div>
            <ul class="sidebar-nav">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="take_exam.php" class="nav-link"><span class="nav-icon">📝</span><span class="nav-text">Take Exam</span></a></li>
                <li class="nav-item"><a href="my_results.php" class="nav-link"><span class="nav-icon">📈</span><span class="nav-text">My Results</span></a></li>
                <li class="nav-item"><a href="certificates.php" class="nav-link"><span class="nav-icon">🏆</span><span class="nav-text">Certificates</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link active"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Settings</h1><p>Manage your profile and preferences</p></div>
                <div class="top-bar-right">
                    <?php
                    $available_roles = getAvailableRoles($pdo, $_SESSION['user_id']);
                    $current_role = getCurrentRole();
                    if(count($available_roles) > 1): ?>
                    <div class="role-switcher">
                        <span>🎭</span>
                        <form method="POST" action="../includes/switch_role.php" style="display: inline;">
                            <select name="new_role" onchange="this.form.submit()">
                                <?php foreach($available_roles as $role): ?>
                                <option value="<?php echo $role; ?>" <?php echo $role == $current_role ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $role)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="switch_role" value="1">
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="container">
                <?php if($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="settings-grid">
                    <!-- Left Column - Profile Info -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar"><i class="fas fa-user-graduate"></i></div>
                            <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="profile-role">Student</div>
                        </div>
                        <div class="profile-stats">
                            <div>
                                <div class="stat-value"><?php echo $stats['enrolled_courses']; ?></div>
                                <div class="stat-label">Enrolled Courses</div>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $stats['exams_taken']; ?></div>
                                <div class="stat-label">Exams Taken</div>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo round($stats['avg_score'], 1); ?>%</div>
                                <div class="stat-label">Average Score</div>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo round($stats['best_score'], 1); ?>%</div>
                                <div class="stat-label">Best Score</div>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $stats['certificates_count']; ?></div>
                                <div class="stat-label">Certificates</div>
                            </div>
                        </div>
                        <div style="padding: 20px; border-top: 1px solid #e0e0e0;">
                            <h4 style="margin-bottom: 10px;">Your Roles</h4>
                            <?php
                            $user_roles = getUserRoles($pdo, $user_id);
                            $all_roles = array_column($user_roles, 'role');
                            foreach($all_roles as $role): ?>
                                <span class="role-badge <?php echo $role; ?>">
                                    <i class="fas <?php echo $role == 'admin' ? 'fa-crown' : ($role == 'exam_committee' ? 'fa-clipboard-list' : ($role == 'teacher' ? 'fa-chalkboard-user' : 'fa-graduation-cap')); ?>"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Right Column - Settings Forms -->
                    <div>
                        <!-- Profile Settings -->
                        <div class="settings-card">
                            <h3><i class="fas fa-user-edit"></i> Profile Information</h3>
                            <form method="POST">
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                                    <small style="color: #666;">Username cannot be changed</small>
                                </div>
                                <div class="form-group">
                                    <label>Full Name *</label>
                                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Email *</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Student ID</label>
                                    <input type="text" value="<?php echo htmlspecialchars($user['student_id']); ?>" disabled>
                                </div>
                                <button type="submit" name="update_profile" class="btn-primary">Update Profile</button>
                            </form>
                        </div>
                        
                        <!-- Change Password -->
                        <div class="settings-card">
                            <h3><i class="fas fa-key"></i> Change Password</h3>
                            <form method="POST">
                                <div class="form-group">
                                    <label>Current Password</label>
                                    <input type="password" name="current_password" required>
                                </div>
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" required>
                                    <small style="color: #666;">Minimum 6 characters</small>
                                </div>
                                <div class="form-group">
                                    <label>Confirm Password</label>
                                    <input type="password" name="confirm_password" required>
                                </div>
                                <button type="submit" name="change_password" class="btn-primary">Change Password</button>
                            </form>
                        </div>
                        
                        <!-- Notification Settings -->
                        <div class="settings-card">
                            <h3><i class="fas fa-bell"></i> Notification Preferences</h3>
                            <form method="POST">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="email_notifications" id="email_notifications" <?php echo ($notification_settings && $notification_settings['email_notifications']) ? 'checked' : ''; ?>>
                                    <label for="email_notifications">Receive Email Notifications</label>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="exam_reminders" id="exam_reminders" <?php echo ($notification_settings && $notification_settings['exam_reminders']) ? 'checked' : ''; ?>>
                                    <label for="exam_reminders">Exam Reminders</label>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="result_notifications" id="result_notifications" <?php echo ($notification_settings && $notification_settings['result_notifications']) ? 'checked' : ''; ?>>
                                    <label for="result_notifications">Result Notifications</label>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="certificate_available" id="certificate_available" <?php echo ($notification_settings && $notification_settings['certificate_available']) ? 'checked' : ''; ?>>
                                    <label for="certificate_available">Certificate Available Notifications</label>
                                </div>
                                <button type="submit" name="update_notifications" class="btn-primary">Save Preferences</button>
                            </form>
                        </div>
                        
                        <!-- Account Information -->
                        <div class="settings-card">
                            <h3><i class="fas fa-info-circle"></i> Account Information</h3>
                            <div style="margin-bottom: 10px;">
                                <strong>Account Created:</strong> <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <strong>Last Login:</strong> <?php echo $user['last_login'] ? date('F j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?>
                            </div>
                            <div>
                                <strong>Account Status:</strong> 
                                <span class="badge" style="background: <?php echo $user['status'] == 'active' ? '#28a745' : '#dc3545'; ?>; color: white; padding: 3px 10px; border-radius: 20px;">
                                    <?php echo ucfirst($user['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
    </script>
</body>
</html>