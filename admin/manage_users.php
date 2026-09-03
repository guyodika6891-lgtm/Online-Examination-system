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
$import_success = 0;
$import_failed = 0;

// Default passwords by role
$default_passwords = [
    'student' => 'student123',
    'teacher' => 'teacher123',
    'exam_committee' => 'committee123',
    'admin' => 'admin123'
];

// Handle user creation
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $role = $_POST['role'];
    
    // Set default password based on role
    $plain_password = $default_passwords[$role] ?? 'password123';
    $password = password_hash($plain_password, PASSWORD_DEFAULT);
    
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    
    // Validate full name - only letters and spaces allowed
    if (!preg_match("/^[a-zA-Z\s]+$/", $full_name)) {
        $error = "Full name can only contain letters and spaces!";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$username, $password, $full_name, $email, $role]);
            $user_id = $pdo->lastInsertId();
            
            // Assign role in user_roles table
            assignRole($pdo, $user_id, $role, $admin_id);
            
            $message = "✅ User created successfully!<br>🔑 Default password: <strong style='color:#667eea;'>{$plain_password}</strong>";
            logActivity($pdo, $admin_id, 'user_created', "Created user: $username with role: $role");
        } catch(PDOException $e) {
            $error = "Username or email already exists!";
        }
    }
}

// Handle user update
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $user_id = $_POST['user_id'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    
    // Validate full name - only letters and spaces allowed
    if (!preg_match("/^[a-zA-Z\s]+$/", $full_name)) {
        $error = "Full name can only contain letters and spaces!";
    } else {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->execute([$full_name, $email, $user_id]);
        $message = "User updated successfully!";
        logActivity($pdo, $admin_id, 'user_updated', "Updated user ID: $user_id");
    }
}

// Handle reset password
if(isset($_GET['action']) && $_GET['action'] == 'reset_password' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    
    // Prevent modifying admin users
    $check_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $check_stmt->execute([$user_id]);
    $user_role = $check_stmt->fetchColumn();
    
    if($user_role == 'admin') {
        $error = "Cannot reset password for admin users!";
    } else {
        $new_password = $default_passwords[$user_role] ?? 'password123';
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_password, $user_id]);
        $message = "✅ Password reset to: <strong style='color:#667eea;'>{$new_password}</strong>";
        logActivity($pdo, $admin_id, 'password_reset', "Reset password for user ID: $user_id");
    }
}

// Handle user status update
if(isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $user_id = (int)$_GET['id'];
    
    // Skip if action is reset_password (already handled above)
    if($action == 'reset_password') {
        // Already handled above
    } else {
        // Prevent modifying admin users
        $check_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $check_stmt->execute([$user_id]);
        $user_role = $check_stmt->fetchColumn();
        
        if($user_role == 'admin') {
            $error = "Cannot modify admin users!";
        } else {
            if($action == 'activate') {
                $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = "User activated!";
                logActivity($pdo, $admin_id, 'user_activated', "Activated user ID: $user_id");
            } elseif($action == 'suspend') {
                $stmt = $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = "User suspended!";
                logActivity($pdo, $admin_id, 'user_suspended', "Suspended user ID: $user_id");
            } elseif($action == 'delete') {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = "User deleted!";
                logActivity($pdo, $admin_id, 'user_deleted', "Deleted user ID: $user_id");
            }
        }
    }
}

// ============= BULK IMPORT FUNCTION =============
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_users'])) {
    // Check if file was uploaded
    if(isset($_FILES['user_file']) && $_FILES['user_file']['error'] == 0) {
        $file = $_FILES['user_file'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Allowed file types
        $allowed_ext = ['csv'];
        
        if(!in_array($file_ext, $allowed_ext)) {
            $error = "Please upload CSV files only!";
        } else {
            try {
                // Read CSV file
                $handle = fopen($file_tmp, 'r');
                $headers = fgetcsv($handle);
                
                // Validate headers - password column is OPTIONAL
                $expected_headers = ['username', 'full_name', 'email', 'role'];
                $header_valid = true;
                foreach($expected_headers as $h) {
                    if(!in_array($h, array_map('strtolower', $headers))) {
                        $header_valid = false;
                        break;
                    }
                }
                
                // Check if password column exists (optional)
                $has_password_column = in_array('password', array_map('strtolower', $headers));
                
                if(!$header_valid) {
                    $error = "Invalid CSV headers. Expected: username, full_name, email, role (password is optional)";
                } else {
                    $import_success = 0;
                    $import_failed = 0;
                    $imported_users = [];
                    $failed_users = [];
                    
                    while(($row = fgetcsv($handle)) !== false) {
                        $user_data = array_combine(array_map('strtolower', $headers), $row);
                        
                        // Get role and set default password
                        $role = strtolower(trim($user_data['role'] ?? 'student'));
                        $allowed_roles = ['student', 'teacher', 'exam_committee', 'admin'];
                        if(!in_array($role, $allowed_roles)) {
                            $role = 'student';
                        }
                        
                        // Use provided password if exists, otherwise use default
                        if($has_password_column && !empty($user_data['password'])) {
                            $plain_password = trim($user_data['password']);
                        } else {
                            $plain_password = $default_passwords[$role] ?? 'password123';
                        }
                        $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);
                        
                        // Validate data
                        $username = trim($user_data['username'] ?? '');
                        $full_name = trim($user_data['full_name'] ?? '');
                        $email = trim($user_data['email'] ?? '');
                        
                        if(empty($username) || empty($full_name) || empty($email)) {
                            $import_failed++;
                            $failed_users[] = "Row: " . ($import_success + $import_failed + 1) . " - Missing required fields";
                        } elseif(!preg_match("/^[a-zA-Z\s]+$/", $full_name)) {
                            $import_failed++;
                            $failed_users[] = "Row: " . ($import_success + $import_failed + 1) . " - Invalid name format for: $username";
                        } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $import_failed++;
                            $failed_users[] = "Row: " . ($import_success + $import_failed + 1) . " - Invalid email for: $username";
                        } else {
                            try {
                                // Check if user exists
                                $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                                $check_stmt->execute([$username, $email]);
                                
                                if($check_stmt->rowCount() == 0) {
                                    $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, status) 
                                                          VALUES (?, ?, ?, ?, ?, 'active')");
                                    $stmt->execute([
                                        $username,
                                        $hashed_password,
                                        $full_name,
                                        $email,
                                        $role
                                    ]);
                                    
                                    $user_id = $pdo->lastInsertId();
                                    assignRole($pdo, $user_id, $role, $admin_id);
                                    $import_success++;
                                    
                                    // Store imported user info for summary
                                    $imported_users[] = [
                                        'username' => $username,
                                        'password' => $plain_password,
                                        'role' => $role
                                    ];
                                } else {
                                    $import_failed++;
                                    $failed_users[] = "Row: " . ($import_success + $import_failed + 1) . " - Duplicate username/email: $username";
                                }
                            } catch(PDOException $e) {
                                $import_failed++;
                                $failed_users[] = "Row: " . ($import_success + $import_failed + 1) . " - Database error: " . $e->getMessage();
                            }
                        }
                    }
                    fclose($handle);
                    
                    if($import_success > 0) {
                        $message = "✅ <strong>$import_success users imported successfully!</strong><br>";
                        
                        // Show imported users with their passwords
                        if(count($imported_users) > 0) {
                            $message .= "<div style='margin-top: 10px; max-height: 250px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px; border: 1px solid #ddd;'>";
                            $message .= "<strong>📋 Imported Users (Default Passwords):</strong><br>";
                            $message .= "<table style='width: 100%; font-size: 13px; border-collapse: collapse; margin-top: 5px;'>";
                            $message .= "<tr style='background: #e9ecef;'><th style='padding: 5px; text-align: left;'>Username</th><th style='padding: 5px; text-align: left;'>Password</th><th style='padding: 5px; text-align: left;'>Role</th></tr>";
                            foreach($imported_users as $user) {
                                $message .= "<tr>";
                                $message .= "<td style='padding: 5px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($user['username']) . "</td>";
                                $message .= "<td style='padding: 5px; border-bottom: 1px solid #eee;'><code style='background: #e9ecef; padding: 2px 6px; border-radius: 3px;'>" . htmlspecialchars($user['password']) . "</code></td>";
                                $message .= "<td style='padding: 5px; border-bottom: 1px solid #eee;'>" . ucfirst($user['role']) . "</td>";
                                $message .= "</tr>";
                            }
                            $message .= "</table></div>";
                        }
                        
                        logActivity($pdo, $admin_id, 'bulk_import', "Imported $import_success users");
                    }
                    if($import_failed > 0) {
                        $error = "⚠️ <strong>$import_failed users failed to import</strong><br>";
                        if(count($failed_users) > 0) {
                            $error .= "<div style='margin-top: 5px; max-height: 150px; overflow-y: auto; font-size: 13px;'>";
                            $error .= implode("<br>", array_slice($failed_users, 0, 10));
                            if(count($failed_users) > 10) {
                                $error .= "<br>... and " . (count($failed_users) - 10) . " more errors";
                            }
                            $error .= "</div>";
                        }
                    }
                }
            } catch(Exception $e) {
                $error = "Import error: " . $e->getMessage();
            }
        }
    } else {
        $error = "Please select a file to upload!";
    }
}

// Get all users
$stmt = $pdo->prepare("SELECT * FROM users ORDER BY created_at DESC");
$stmt->execute();
$users = $stmt->fetchAll();

// Get all roles for filter
$roles = ['student', 'teacher', 'exam_committee', 'admin'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn-primary { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary:hover { background: #5a6fd6; }
        .btn-success { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-success:hover { background: #218838; }
        .btn-danger { background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-danger:hover { background: #c82333; }
        .btn-warning { background: #ffc107; color: #333; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-warning:hover { background: #e0a800; }
        .btn-edit { background: #17a2b8; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block; }
        .btn-edit:hover { background: #138496; }
        .btn-secondary { background: #6c757d; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-info { background: #17a2b8; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-info:hover { background: #138496; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; width: 550px; max-width: 90%; max-height: 90vh; overflow-y: auto; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        .form-group input[type="file"] { padding: 8px 0; border: none; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 11px; }
        .badge-active { background: #28a745; color: white; }
        .badge-suspended { background: #dc3545; color: white; }
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .name-preview { font-size: 12px; color: #666; margin-top: 5px; }
        .admin-row { background: #fff3cd !important; border-left: 4px solid #ffc107; }
        .admin-badge { background: #ffc107; color: #000; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .protected-badge { color: #666; font-size: 12px; font-style: italic; }
        .action-btns { display: flex; gap: 5px; flex-wrap: wrap; }
        .import-section { border-top: 2px dashed #ddd; padding-top: 20px; margin-top: 20px; }
        .import-section h4 { margin: 0 0 10px 0; color: #333; }
        .import-info { background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 13px; margin-bottom: 10px; }
        .import-info code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; }
        .default-pass-display { background: #e8f4fd; padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .default-pass-display strong { color: #667eea; }
        .password-reset-btn { background: #fd7e14; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .password-reset-btn:hover { background: #e06b00; }
        @media (max-width: 768px) { .header-actions { flex-direction: column; align-items: stretch; } }
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
                <li class="nav-item"><a href="manage_users.php" class="nav-link active"><span class="nav-icon">👥</span><span class="nav-text">Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_roles.php" class="nav-link"><span class="nav-icon">🎭</span><span class="nav-text">Manage Roles</span></a></li>
                <li class="nav-item"><a href="manage_courses.php" class="nav-link"><span class="nav-icon">📚</span><span class="nav-text">Manage Courses</span></a></li>
                <li class="nav-item"><a href="manage_enrollments.php" class="nav-link"><span class="nav-icon">📝</span><span class="nav-text">Enroll Students</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">👤</span><span class="nav-text">My Profile</span></a></li>
                <li class="nav-item"><a href="system_settings.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Manage Users</h1><p>Add, edit, or remove users</p></div>
            </div>
            
            <div class="container">
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="header-actions">
                    <div class="btn-group">
                        <button onclick="showCreateModal()" class="btn-primary">+ Create New User</button>
                        <button onclick="showImportModal()" class="btn-success">📤 Import Users</button>
                    </div>
                    <div>
                        <span style="color: #666; font-size: 14px;">Total Users: <strong><?php echo count($users); ?></strong></span>
                    </div>
                </div>
                
                <div class="card">
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($users as $user): ?>
                                <tr class="<?php if($user['role'] == 'admin') echo 'admin-row'; ?>">
                                    <td><?php echo $user['id']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($user['username']); ?>
                                        <?php if($user['role'] == 'admin'): ?>
                                            <span class="admin-badge">👑 Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php if($user['role'] == 'admin'): ?>
                                            <span style="background: #ffc107; color: #000; padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: bold;">👑 Admin</span>
                                        <?php else: ?>
                                            <?php echo ucfirst($user['role']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                                    <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <?php if($user['role'] == 'admin'): ?>
                                            <span class="protected-badge">🔒 Protected</span>
                                        <?php else: ?>
                                            <div class="action-btns">
                                                <button onclick="showEditModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>', '<?php echo htmlspecialchars($user['email']); ?>')" class="btn-edit">Edit</button>
                                                <a href="?action=reset_password&id=<?php echo $user['id']; ?>" class="password-reset-btn" onclick="return confirm('Reset password for this user?')">🔄 Reset Pass</a>
                                                <?php if($user['status'] == 'active'): ?>
                                                    <a href="?action=suspend&id=<?php echo $user['id']; ?>" class="btn-warning" onclick="return confirm('Suspend this user?')">Suspend</a>
                                                <?php else: ?>
                                                    <a href="?action=activate&id=<?php echo $user['id']; ?>" class="btn-success" onclick="return confirm('Activate this user?')">Activate</a>
                                                <?php endif; ?>
                                                <a href="?action=delete&id=<?php echo $user['id']; ?>" class="btn-danger" onclick="return confirm('Delete this user permanently?')">Delete</a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create User Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <h3>Create New User</h3>
            <form method="POST" onsubmit="return validateName('create_full_name')">
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" id="create_full_name" required 
                           onkeyup="validateNameLive('create_full_name')"
                           placeholder="e.g., John Smith">
                    <div class="name-preview" id="create_full_name_preview"></div>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Role *</label>
                    <select name="role" id="create_role" onchange="showDefaultPassword()" required>
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="exam_committee">Exam Committee</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="default-pass-display">
                    <strong>🔑 Default Password:</strong> 
                    <span id="default_password_display" style="color: #667eea; font-weight: bold;">student123</span>
                    <br><small style="color: #666;">User should change password after first login</small>
                </div>
                <button type="submit" name="create_user" class="btn-primary">Create User</button>
                <button type="button" onclick="closeModal()" style="margin-left: 10px;">Cancel</button>
            </form>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h3>Edit User</h3>
            <form method="POST" onsubmit="return validateName('edit_full_name')">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" id="edit_full_name" required 
                           onkeyup="validateNameLive('edit_full_name')">
                    <div class="name-preview" id="edit_full_name_preview"></div>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" id="edit_email" required>
                </div>
                <button type="submit" name="update_user" class="btn-primary">Update User</button>
                <button type="button" onclick="closeEditModal()" style="margin-left: 10px;">Cancel</button>
            </form>
        </div>
    </div>
    
    <!-- Import Users Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <h3>📤 Import Users from CSV</h3>
            <div class="import-info">
                <strong>📋 CSV Format Required:</strong><br>
                <code>username, full_name, email, role</code><br>
                <small>Password column is optional - uses role-based defaults</small><br><br>
                <strong>📝 Example:</strong><br>
                <code>john_doe, John Doe, john@example.com, student</code><br>
                <code>jane_smith, Jane Smith, jane@example.com, teacher</code><br><br>
                <strong>🔑 Default Passwords:</strong><br>
                <span style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">student → student123</span>
                <span style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">teacher → teacher123</span>
                <span style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">exam_committee → committee123</span>
                <span style="background: #e9ecef; padding: 2px 6px; border-radius: 3px;">admin → admin123</span>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Select CSV File *</label>
                    <input type="file" name="user_file" accept=".csv" required>
                </div>
                <button type="submit" name="import_users" class="btn-success">📤 Import Users</button>
                <button type="button" onclick="closeImportModal()" style="margin-left: 10px;">Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        // Default passwords by role
        const defaultPasswords = {
            'student': 'student123',
            'teacher': 'teacher123',
            'exam_committee': 'committee123',
            'admin': 'admin123'
        };
        
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function showCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
            showDefaultPassword();
        }
        
        function closeModal() {
            document.getElementById('createModal').style.display = 'none';
        }
        
        function showEditModal(id, name, email) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('edit_full_name').value = name;
            document.getElementById('edit_email').value = email;
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function showImportModal() {
            document.getElementById('importModal').style.display = 'flex';
        }
        
        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
        }
        
        // Show default password based on selected role
        function showDefaultPassword() {
            const role = document.getElementById('create_role').value;
            document.getElementById('default_password_display').textContent = defaultPasswords[role] || 'password123';
        }
        
        // Name validation - only letters and spaces allowed
        function validateName(inputId) {
            const input = document.getElementById(inputId);
            const nameValue = input.value.trim();
            const nameRegex = /^[a-zA-Z\s]+$/;
            
            if (!nameRegex.test(nameValue)) {
                alert("Full name can only contain letters and spaces!\nExample: John Smith");
                input.style.borderColor = '#dc3545';
                return false;
            }
            input.style.borderColor = '#28a745';
            return true;
        }
        
        // Live validation as user types
        function validateNameLive(inputId) {
            const input = document.getElementById(inputId);
            const preview = document.getElementById(inputId + '_preview');
            const nameValue = input.value.trim();
            const nameRegex = /^[a-zA-Z\s]*$/;
            
            if (nameValue.length > 0) {
                if (nameRegex.test(nameValue)) {
                    input.style.borderColor = '#28a745';
                    if (preview) {
                        preview.innerHTML = '✓ Valid name';
                        preview.style.color = '#28a745';
                    }
                } else {
                    input.style.borderColor = '#dc3545';
                    if (preview) {
                        preview.innerHTML = '✗ Only letters and spaces allowed';
                        preview.style.color = '#dc3545';
                    }
                }
            } else {
                input.style.borderColor = '#ddd';
                if (preview) {
                    preview.innerHTML = '';
                }
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Initialize default password display on page load
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('create_role')) {
                showDefaultPassword();
            }
        });
    </script>
</body>
</html>
