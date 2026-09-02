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

// Handle role assignment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_role'])) {
    $user_id = (int)$_POST['user_id'];
    $role = $_POST['role'];
    
    if(assignRole($pdo, $user_id, $role, $admin_id)) {
        $message = "Role '$role' assigned successfully!";
        logActivity($pdo, $admin_id, 'role_assigned', "Assigned $role role to user ID: $user_id");
    } else {
        $error = "Failed to assign role.";
    }
}

// Handle role removal
if(isset($_GET['remove_role']) && isset($_GET['user_id']) && isset($_GET['role'])) {
    $user_id = (int)$_GET['user_id'];
    $role = $_GET['role'];
    
    // Prevent removing last role
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $active_roles = $stmt->fetchColumn();
    
    if($active_roles <= 1) {
        $error = "User must have at least one role. Cannot remove the last role.";
    } else {
        if(removeRole($pdo, $user_id, $role, $admin_id)) {
            $message = "Role '$role' removed successfully!";
            logActivity($pdo, $admin_id, 'role_removed', "Removed $role role from user ID: $user_id");
        } else {
            $error = "Failed to remove role.";
        }
    }
}

// Get all users with their roles
$stmt = $pdo->prepare("
    SELECT u.*, 
           GROUP_CONCAT(DISTINCT ur.role ORDER BY FIELD(ur.role, 'admin', 'exam_committee', 'teacher', 'student')) as roles,
           COUNT(DISTINCT ur.role) as role_count
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id AND ur.status = 'active'
    GROUP BY u.id
    ORDER BY u.created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll();

$available_roles = ['student', 'teacher', 'exam_committee', 'admin'];

// Get statistics
$total_users = count($users);
$users_with_multiple_roles = 0;
foreach($users as $user) {
    $role_count = $user['role_count'];
    if($role_count > 1) $users_with_multiple_roles++;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Roles - Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn-primary { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-danger { background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; width: 500px; max-width: 90%; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .role-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; margin: 2px; }
        .role-badge.admin { background: #dc3545; color: white; }
        .role-badge.exam_committee { background: #fd7e14; color: white; }
        .role-badge.teacher { background: #28a745; color: white; }
        .role-badge.student { background: #17a2b8; color: white; }
        .btn-remove { background: none; border: none; color: #dc3545; cursor: pointer; font-size: 14px; margin-left: 5px; }
        .btn-remove:hover { color: #c82333; }
        .role-select { padding: 5px 10px; border-radius: 5px; border: 1px solid #ddd; background: white; cursor: pointer; }
        .info-text { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin-bottom: 20px; border-radius: 5px; font-size: 14px; }
        .role-switch-example { background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 10px; font-size: 13px; }
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
                <li class="nav-item"><a href="manage_roles.php" class="nav-link active"><span class="nav-icon">🎭</span><span class="nav-text">Manage Roles</span></a></li>
                <li class="nav-item"><a href="manage_courses.php" class="nav-link"><span class="nav-icon">📚</span><span class="nav-text">Manage Courses</span></a></li>
                <li class="nav-item"><a href="manage_enrollments.php" class="nav-link"><span class="nav-icon">📝</span><span class="nav-text">Enroll Students</span></a></li>
                <li class="nav-item">
                    <a href="system_settings.php" class="nav-link">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-text">Settings</span>
                    </a>
                </li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>🎭 Role Management</h1>
                    <p>Assign multiple roles to users (e.g., a teacher can also be exam committee member)</p>
                </div>
            </div>
            
            <div class="container">
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_users; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $users_with_multiple_roles; ?></div>
                        <div class="stat-label">Users with Multiple Roles</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">4</div>
                        <div class="stat-label">Available Roles</div>
                    </div>
                </div>
                
                <!-- Info Box -->
                <div class="info-text">
                    <strong>💡 Multi-Role Feature:</strong>
                    <p>A user can have multiple roles simultaneously. For example:</p>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <li>A teacher can also be an exam committee member</li>
                        <li>An exam committee member can also be a teacher</li>
                        <li>Admin can have all roles</li>
                    </ul>
                    <div class="role-switch-example">
                        <strong>🔄 Role Switching:</strong> Users with multiple roles can switch between roles using the role selector in their dashboard header.
                    </div>
                </div>
                
                <!-- Assign Role Button -->
                <button onclick="showAssignModal()" class="btn-primary" style="margin-bottom: 20px;">+ Assign New Role</button>
                
                <!-- Users Table -->
                <div class="card">
                    <h3>👥 Users & Their Roles</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Username</th>
                                <th>Assigned Roles</th>
                                <th>Role Count</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $user): 
                                $user_roles = $user['roles'] ? explode(',', $user['roles']) : [];
                            ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo $user['username']; ?></td>
                                <td>
                                    <?php foreach($user_roles as $role): ?>
                                        <?php if($role): ?>
                                        <span class="role-badge <?php echo $role; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
                                            <?php if(count($user_roles) > 1): ?>
                                            <button onclick="removeRole(<?php echo $user['id']; ?>, '<?php echo $role; ?>')" 
                                                    class="btn-remove" title="Remove role">×</button>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if(empty($user_roles)): ?>
                                        <span style="color: #999;">No roles assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge" style="background: #667eea; color: white; padding: 3px 8px; border-radius: 20px;">
                                        <?php echo count($user_roles); ?> role(s)
                                    </span>
                                </td>
                                <td>
                                    <select onchange="quickAssign(this, <?php echo $user['id']; ?>)" class="role-select">
                                        <option value="">+ Add role</option>
                                        <option value="student">+ Student</option>
                                        <option value="teacher">+ Teacher</option>
                                        <option value="exam_committee">+ Exam Committee</option>
                                        <option value="admin">+ Admin</option>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Role Permissions Section -->
                <div class="card">
                    <h3>🔐 Role Permissions Overview</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 15px;">
                        <div style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 15px;">
                            <h4 style="color: #17a2b8;">🎓 Student</h4>
                            <ul style="margin-top: 10px; margin-left: 20px; font-size: 13px;">
                                <li>View Dashboard</li>
                                <li>Take Exam</li>
                                <li>View Own Results</li>
                                <li>Update Profile</li>
                            </ul>
                        </div>
                        <div style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 15px;">
                            <h4 style="color: #28a745;">👨‍🏫 Teacher</h4>
                            <ul style="margin-top: 10px; margin-left: 20px; font-size: 13px;">
                                <li>View Dashboard</li>
                                <li>Create/Edit/Delete Questions</li>
                                <li>View Question Bank</li>
                                <li>View Student Results</li>
                            </ul>
                        </div>
                        <div style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 15px;">
                            <h4 style="color: #fd7e14;">📋 Exam Committee</h4>
                            <ul style="margin-top: 10px; margin-left: 20px; font-size: 13px;">
                                <li>View Dashboard</li>
                                <li>Approve/Reject Questions</li>
                                <li>Schedule Exams</li>
                                <li>Manage Exams</li>
                                <li>Generate Reports</li>
                                <li>View All Results</li>
                            </ul>
                        </div>
                        <div style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 15px;">
                            <h4 style="color: #dc3545;">👨‍💼 Admin</h4>
                            <ul style="margin-top: 10px; margin-left: 20px; font-size: 13px;">
                                <li>View Dashboard</li>
                                <li>Manage Users</li>
                                <li>Manage Roles</li>
                                <li>Manage Courses</li>
                                <li>Manage Enrollments</li>
                                <li>System Settings</li>
                                <li>View Logs</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assign Role Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <h3>➕ Assign New Role</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Select User *</label>
                    <select name="user_id" required>
                        <option value="">-- Select User --</option>
                        <?php foreach($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>">
                            <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo $user['username']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Select Role to Assign *</label>
                    <select name="role" required>
                        <option value="">-- Select Role --</option>
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="exam_committee">Exam Committee</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <button type="submit" name="assign_role" class="btn-primary">Assign Role</button>
                <button type="button" onclick="closeModal()" style="margin-left: 10px; padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer;">Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function showAssignModal() {
            document.getElementById('assignModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('assignModal').style.display = 'none';
        }
        
        function removeRole(userId, role) {
            if(confirm(`Remove ${role} role from this user? The user will keep their other roles.`)) {
                window.location.href = `?remove_role=1&user_id=${userId}&role=${role}`;
            }
        }
        
        function quickAssign(select, userId) {
            const role = select.value;
            if(role) {
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                const roleInput = document.createElement('input');
                roleInput.type = 'hidden';
                roleInput.name = 'role';
                roleInput.value = role;
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'assign_role';
                submitInput.value = '1';
                form.appendChild(userIdInput);
                form.appendChild(roleInput);
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
            select.value = '';
        }
    </script>
</body>
</html>