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

// Handle user creation
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    
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
            
            $message = "User created successfully!";
            logActivity($pdo, $admin_id, 'user_created', "Created user: $username");
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

// Handle user status update
if(isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $user_id = (int)$_GET['id'];
    
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

// Get all users
$stmt = $pdo->prepare("SELECT * FROM users ORDER BY created_at DESC");
$stmt->execute();
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn-primary { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-danger { background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-success { background: #28a745; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-warning { background: #ffc107; color: #333; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-edit { background: #17a2b8; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; width: 500px; max-width: 90%; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 11px; }
        .badge-active { background: #28a745; color: white; }
        .badge-suspended { background: #dc3545; color: white; }
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .name-preview { font-size: 12px; color: #666; margin-top: 5px; }
        .validation-error { border-color: #dc3545 !important; }
        .validation-success { border-color: #28a745 !important; }
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
                    <button onclick="showCreateModal()" class="btn-primary">+ Create New User</button>
                </div>
                
                <div class="card">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo ucfirst($user['role']); ?></td>
                                <td><span class="badge badge-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                                <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <button onclick="showEditModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>', '<?php echo htmlspecialchars($user['email']); ?>')" class="btn-edit">Edit</button>
                                    <?php if($user['status'] == 'active'): ?>
                                        <a href="?action=suspend&id=<?php echo $user['id']; ?>" class="btn-warning" onclick="return confirm('Suspend this user?')">Suspend</a>
                                    <?php else: ?>
                                        <a href="?action=activate&id=<?php echo $user['id']; ?>" class="btn-success" onclick="return confirm('Activate this user?')">Activate</a>
                                    <?php endif; ?>
                                    <a href="?action=delete&id=<?php echo $user['id']; ?>" class="btn-danger" onclick="return confirm('Delete this user permanently?')">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                    <label>Password *</label>
                    <input type="password" name="password" required>
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
                    <select name="role" required>
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="exam_committee">Exam Committee</option>
                        <option value="admin">Admin</option>
                    </select>
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
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function showCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
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
    </script>
</body>
</html>