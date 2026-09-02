<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'teacher')) {
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
    // Create default settings if not exists
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
    $department = trim($_POST['department']);
    
    // Validate full name - only letters and spaces
    if (!preg_match("/^[a-zA-Z\s]+$/", $full_name)) {
        $error = "Full name can only contain letters and spaces!";
    } elseif (strlen($full_name) < 3) {
        $error = "Full name must be at least 3 characters!";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, department = ? WHERE id = ?");
        if($stmt->execute([$full_name, $email, $phone, $department, $user_id])) {
            $_SESSION['full_name'] = $full_name;
            $success = "Profile updated successfully!";
            logActivity($pdo, $user_id, 'profile_updated', "Updated profile information");
            
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
                    logActivity($pdo, $user_id, 'password_changed', "Changed password");
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

// Get user roles
$user_roles = getUserRoles($pdo, $user_id);
$all_roles = array_column($user_roles, 'role');

// Get teacher statistics
$stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM courses WHERE teacher_id = ?) as my_courses,
        (SELECT COUNT(*) FROM questions WHERE created_by = ?) as total_questions,
        (SELECT COUNT(*) FROM questions WHERE created_by = ? AND status = 'approved') as approved_questions,
        (SELECT COUNT(*) FROM questions WHERE created_by = ? AND status = 'pending') as pending_questions,
        (SELECT COUNT(*) FROM questions WHERE created_by = ? AND status = 'rejected') as rejected_questions
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
$stats = $stmt->fetch();

// Get recent activity
$stmt = $pdo->prepare("
    SELECT action, description, created_at 
    FROM system_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_activities = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Settings - Teacher Portal</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .settings-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
        
        .profile-card { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .profile-header { background: linear-gradient(135deg, #28a745, #20c997); padding: 30px; text-align: center; color: white; }
        .profile-avatar { width: 100px; height: 100px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; font-size: 50px; border: 3px solid white; }
        .profile-name { font-size: 20px; font-weight: 600; margin-bottom: 5px; }
        .profile-role { font-size: 14px; opacity: 0.9; }
        .profile-stats { padding: 20px; background: #f8f9fa; display: flex; justify-content: space-around; text-align: center; flex-wrap: wrap; gap: 15px; }
        .stat-item { text-align: center; }
        .stat-value { font-size: 24px; font-weight: bold; color: #28a745; }
        .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
        
        .settings-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 25px; }
        .settings-card h3 { margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; display: flex; align-items: center; gap: 10px; }
        .settings-card h3 i { color: #28a745; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 14px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; transition: all 0.3s; }
        .form-group input:focus { outline: none; border-color: #28a745; box-shadow: 0 0 0 3px rgba(40,167,69,0.1); }
        
        .btn-primary { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(40,167,69,0.3); }
        
        .alert { padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        
        .role-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin: 2px; }
        .role-badge.admin { background: #dc3545; color: white; }
        .role-badge.exam_committee { background: #fd7e14; color: white; }
        .role-badge.teacher { background: #28a745; color: white; }
        .role-badge.student { background: #17a2b8; color: white; }
        
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .checkbox-group input { width: auto; margin-right: 5px; transform: scale(1.2); }
        .checkbox-group label { font-weight: normal; cursor: pointer; }
        
        .activity-list { list-style: none; }
        .activity-item { padding: 12px 0; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 12px; }
        .activity-icon { width: 35px; height: 35px; background: #f0f2f5; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .activity-action { font-weight: 600; color: #333; }
        .activity-time { font-size: 11px; color: #999; margin-top: 3px; }
        
        .info-box { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; border-radius: 8px; margin-top: 20px; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        
        hr { margin: 20px 0; border: none; border-top: 1px solid #e0e0e0; }
        
        @media (max-width: 768px) {
            .settings-grid { grid-template-columns: 1fr; }
            .profile-stats { flex-direction: column; align-items: center; }
        }
    </style>
</head>
<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <div class="dashboard-layout">
        <div class="sidebar">
            <div class="sidebar-header"><h2>📚 Exam System</h2><p>Teacher Portal</p></div>
            <div class="user-profile"><div class="user-avatar">👨‍🏫</div><div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div><div class="user-role">Teacher</div></div>
            <ul class="sidebar-nav">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="create_questions.php" class="nav-link"><span class="nav-icon">➕</span><span class="nav-text">Create Questions</span></a></li>
                <li class="nav-item"><a href="question_bank.php" class="nav-link"><span class="nav-icon">📚</span><span class="nav-text">Question Bank</span></a></li>
                <li class="nav-item"><a href="student_results.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Student Results</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link active"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>Settings</h1>
                    <p>Manage your profile and account settings</p>
                </div>
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
                                <option value="<?php echo $role; ?>" <?php echo $role == $current_role ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
                                </option>
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
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <div class="settings-grid">
                    <!-- Left Column - Profile Info -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <i class="fas fa-chalkboard-user"></i>
                            </div>
                            <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="profile-role">Teacher</div>
                        </div>
                        <div class="profile-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['my_courses']; ?></div>
                                <div class="stat-label">My Courses</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['total_questions']; ?></div>
                                <div class="stat-label">Total Questions</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['approved_questions']; ?></div>
                                <div class="stat-label">Approved</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['pending_questions']; ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                        </div>
                        <div style="padding: 20px; border-top: 1px solid #e0e0e0;">
                            <h4 style="margin-bottom: 10px;">Your Roles</h4>
                            <?php foreach($all_roles as $role): ?>
                                <span class="role-badge <?php echo $role; ?>">
                                    <i class="fas <?php echo $role == 'admin' ? 'fa-crown' : ($role == 'exam_committee' ? 'fa-clipboard-list' : ($role == 'teacher' ? 'fa-chalkboard-user' : 'fa-graduation-cap')); ?>"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
                                </span>
                            <?php endforeach; ?>
                            <div class="info-box">
                                <i class="fas fa-info-circle"></i>
                                You can switch between roles using the role switcher in the top bar.
                            </div>
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
                                    <label>Email Address *</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label>Phone Number</label>
                                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" placeholder="Optional">
                                </div>
                                
                                <div class="form-group">
                                    <label>Department</label>
                                    <input type="text" name="department" value="<?php echo htmlspecialchars($user['department']); ?>" placeholder="e.g., Computer Science">
                                </div>
                                
                                <button type="submit" name="update_profile" class="btn-primary">
                                    <i class="fas fa-save"></i> Update Profile
                                </button>
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
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" required>
                                </div>
                                
                                <button type="submit" name="change_password" class="btn-primary">
                                    <i class="fas fa-lock"></i> Change Password
                                </button>
                            </form>
                        </div>
                        
                        <!-- Notification Settings -->
                        <div class="settings-card">
                            <h3><i class="fas fa-bell"></i> Notification Settings</h3>
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
                                <button type="submit" name="update_notifications" class="btn-primary">
                                    <i class="fas fa-save"></i> Save Preferences
                                </button>
                            </form>
                        </div>
                        
                        <!-- Recent Activity -->
                        <div class="settings-card">
                            <h3><i class="fas fa-history"></i> Recent Activity</h3>
                            <?php if(count($recent_activities) > 0): ?>
                                <ul class="activity-list">
                                    <?php foreach($recent_activities as $activity): ?>
                                    <li class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                        <div class="activity-detail">
                                            <div class="activity-action"><?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?></div>
                                            <div class="activity-time"><?php echo date('M d, Y g:i A', strtotime($activity['created_at'])); ?></div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p style="color: #666;">No recent activity found.</p>
                            <?php endif; ?>
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
                            <div style="margin-bottom: 10px;">
                                <strong>Teacher ID:</strong> <?php echo $user['teacher_id'] ?: 'Not assigned'; ?>
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