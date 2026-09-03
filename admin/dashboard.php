<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

// Check if admin
if(!isset($_SESSION['user_id']) || $_SESSION['primary_role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$current_role = getCurrentRole();
$available_roles = getAvailableRoles($pdo, $admin_id);

// Get statistics
$stats = [
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'total_students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
    'total_teachers' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn(),
    'total_courses' => $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
    'total_questions' => $pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn(),
    'total_exams' => $pdo->query("SELECT COUNT(*) FROM exam_schedules")->fetchColumn(),
];

// Get recent activities
$recent_activities = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $recent_activities = $stmt->fetchAll();
} catch(PDOException $e) {
    // Table might not exist
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; transition: transform 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.15); }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
        .action-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .action-card { background: white; padding: 25px; border-radius: 10px; text-align: center; text-decoration: none; color: #333; transition: transform 0.3s, box-shadow 0.3s; display: block; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .action-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15); }
        .action-icon { font-size: 48px; display: block; margin-bottom: 15px; }
        .action-title { font-size: 18px; font-weight: 600; margin-bottom: 5px; }
        .action-desc { font-size: 12px; color: #666; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        .role-switcher select { padding: 5px 10px; border: 1px solid #ddd; border-radius: 5px; background: white; cursor: pointer; }
        .activity-list { list-style: none; padding: 0; }
        .activity-list li { padding: 10px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .activity-list li:last-child { border-bottom: none; }
        .activity-time { color: #999; font-size: 12px; }
        .activity-icon { margin-right: 10px; }
        .section-title { font-size: 18px; font-weight: 600; margin-bottom: 15px; color: #333; }
        .welcome-section { background: linear-gradient(135deg, #667eea, #764ba2); padding: 30px; border-radius: 10px; color: white; margin-bottom: 30px; }
        .welcome-section h2 { margin: 0; font-size: 24px; }
        .welcome-section p { margin: 5px 0 0 0; opacity: 0.9; }
        .two-col { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .two-col { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <div class="dashboard-layout">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>📚 Exam System</h2>
                <p>Admin Portal</p>
            </div>
            
            <div class="user-profile">
                <div class="user-avatar">👨‍💼</div>
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="user-role">Administrator</div>
            </div>
            
            <ul class="sidebar-nav">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link active">
                        <span class="nav-icon">📊</span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_users.php" class="nav-link">
                        <span class="nav-icon">👥</span>
                        <span class="nav-text">Manage Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_roles.php" class="nav-link">
                        <span class="nav-icon">🎭</span>
                        <span class="nav-text">Manage Roles</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_courses.php" class="nav-link">
                        <span class="nav-icon">📚</span>
                        <span class="nav-text">Manage Courses</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage_enrollments.php" class="nav-link">
                        <span class="nav-icon">📝</span>
                        <span class="nav-text">Enroll Students</span>
                    </a>
                </li>
                <!-- 👇 PROFILE LINK ADDED HERE -->
                <li class="nav-item">
                    <a href="profile.php" class="nav-link">
                        <span class="nav-icon">👤</span>
                        <span class="nav-text">My Profile</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="system_settings.php" class="nav-link">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-text">Settings</span>
                    </a>
                </li>
            </ul>
            
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn">
                    <span class="nav-icon">🚪</span>
                    <span class="nav-text">Logout</span>
                </a>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>Admin Dashboard</h1>
                    <p>System Overview & Management</p>
                </div>
                <div class="top-bar-right">
                    <?php if(count($available_roles) > 1): ?>
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
            
            <!-- Welcome Section -->
            <div class="welcome-section">
                <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 👋</h2>
                <p>Here's what's happening with your exam system today.</p>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">👥 Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">🎓 Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_teachers']; ?></div>
                    <div class="stat-label">👨‍🏫 Teachers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_courses']; ?></div>
                    <div class="stat-label">📚 Courses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_questions']; ?></div>
                    <div class="stat-label">❓ Questions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_exams']; ?></div>
                    <div class="stat-label">📝 Exams</div>
                </div>
            </div>
            
            <!-- Two Column Layout -->
            <div class="two-col">
                <!-- Quick Actions -->
                <div>
                    <div class="section-title">🚀 Quick Actions</div>
                    <div class="action-grid" style="grid-template-columns: 1fr 1fr;">
                        <a href="manage_users.php" class="action-card">
                            <span class="action-icon">👥</span>
                            <span class="action-title">Manage Users</span>
                            <span class="action-desc">Add, edit, or remove users</span>
                        </a>
                        <a href="manage_roles.php" class="action-card">
                            <span class="action-icon">🎭</span>
                            <span class="action-title">Manage Roles</span>
                            <span class="action-desc">Assign multiple roles to users</span>
                        </a>
                        <a href="manage_courses.php" class="action-card">
                            <span class="action-icon">📚</span>
                            <span class="action-title">Manage Courses</span>
                            <span class="action-desc">Create and assign courses</span>
                        </a>
                        <a href="manage_enrollments.php" class="action-card">
                            <span class="action-icon">📝</span>
                            <span class="action-title">Enroll Students</span>
                            <span class="action-desc">Enroll students in courses</span>
                        </a>
                        <a href="profile.php" class="action-card">
                            <span class="action-icon">👤</span>
                            <span class="action-title">My Profile</span>
                            <span class="action-desc">Update your account information</span>
                        </a>
                        <a href="system_settings.php" class="action-card">
                            <span class="action-icon">⚙️</span>
                            <span class="action-title">System Settings</span>
                            <span class="action-desc">Configure system preferences</span>
                        </a>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div>
                    <div class="section-title">📋 Recent Activity</div>
                    <div class="card" style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                        <?php if(!empty($recent_activities)): ?>
                        <ul class="activity-list">
                            <?php foreach($recent_activities as $activity): ?>
                            <li>
                                <span>
                                    <span class="activity-icon">📌</span>
                                    <?php echo htmlspecialchars($activity['action'] ?? 'Activity'); ?>
                                    <?php if(isset($activity['details'])): ?>
                                        <span style="color: #666; font-size: 12px;"><?php echo htmlspecialchars($activity['details']); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="activity-time"><?php echo isset($activity['created_at']) ? date('M d, h:i A', strtotime($activity['created_at'])) : ''; ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p style="color: #999; text-align: center; padding: 20px 0;">No recent activity found.</p>
                        <?php endif; ?>
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
        
        // Close sidebar on window resize (desktop)
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.querySelector('.sidebar').classList.remove('open');
                document.querySelector('.sidebar-overlay').classList.remove('active');
            }
        });
    </script>
</body>
</html>
