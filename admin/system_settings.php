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

// Get current settings (you can create a settings table or use config file)
$settings = [
    'system_name' => 'Online Examination System',
    'system_email' => 'admin@examsystem.com',
    'timezone' => 'Asia/Kolkata',
    'exam_timezone' => 'Asia/Kolkata',
    'default_pass_percentage' => 40,
    'default_duration' => 60,
    'max_questions_per_exam' => 100,
    'allow_student_registration' => true,
    'enable_email_notification' => true,
    'maintenance_mode' => false,
    'session_timeout' => 30,
    'max_login_attempts' => 5,
    'backup_auto' => true,
    'backup_frequency' => 'daily',
    'default_language' => 'en',
    'logo_path' => '../assets/images/logo.png'
];

// Handle settings update
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['save_general'])) {
        // Save general settings to session or database
        $_SESSION['settings']['system_name'] = $_POST['system_name'];
        $_SESSION['settings']['system_email'] = $_POST['system_email'];
        $_SESSION['settings']['timezone'] = $_POST['timezone'];
        $message = "General settings saved successfully!";
        logActivity($pdo, $admin_id, 'settings_updated', 'Updated general settings');
        
    } elseif(isset($_POST['save_exam'])) {
        $_SESSION['settings']['default_pass_percentage'] = $_POST['default_pass_percentage'];
        $_SESSION['settings']['default_duration'] = $_POST['default_duration'];
        $_SESSION['settings']['max_questions_per_exam'] = $_POST['max_questions_per_exam'];
        $message = "Exam settings saved successfully!";
        logActivity($pdo, $admin_id, 'settings_updated', 'Updated exam settings');
        
    } elseif(isset($_POST['save_security'])) {
        $_SESSION['settings']['session_timeout'] = $_POST['session_timeout'];
        $_SESSION['settings']['max_login_attempts'] = $_POST['max_login_attempts'];
        $message = "Security settings saved successfully!";
        logActivity($pdo, $admin_id, 'settings_updated', 'Updated security settings');
        
    } elseif(isset($_POST['save_backup'])) {
        $_SESSION['settings']['backup_auto'] = isset($_POST['backup_auto']);
        $_SESSION['settings']['backup_frequency'] = $_POST['backup_frequency'];
        $message = "Backup settings saved successfully!";
        logActivity($pdo, $admin_id, 'settings_updated', 'Updated backup settings');
        
    } elseif(isset($_POST['maintenance_mode'])) {
        $mode = isset($_POST['maintenance_mode']) ? 1 : 0;
        $_SESSION['settings']['maintenance_mode'] = $mode;
        $message = $mode ? "Maintenance mode enabled!" : "Maintenance mode disabled!";
        logActivity($pdo, $admin_id, 'maintenance_toggled', "Maintenance mode set to: $mode");
    }
}

// Load saved settings from session if exists
if(isset($_SESSION['settings'])) {
    $settings = array_merge($settings, $_SESSION['settings']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>System Settings - Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn-primary { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-danger { background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-success { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-secondary { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .alert-warning { background: #fff3cd; color: #856404; }
        .settings-tabs { display: flex; gap: 5px; margin-bottom: 20px; flex-wrap: wrap; }
        .settings-tab { background: #f0f2f5; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; transition: 0.3s; }
        .settings-tab.active { background: #667eea; color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .toggle-switch { position: relative; display: inline-block; width: 50px; height: 24px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: 0.3s; border-radius: 24px; }
        .toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: 0.3s; border-radius: 50%; }
        input:checked + .toggle-slider { background-color: #28a745; }
        input:checked + .toggle-slider:before { transform: translateX(26px); }
        .info-text { font-size: 12px; color: #666; margin-top: 5px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .action-buttons { display: flex; gap: 10px; margin-top: 20px; }
        hr { margin: 20px 0; border: none; border-top: 1px solid #e0e0e0; }
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
                <li class="nav-item"><a href="system_settings.php" class="nav-link active"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>⚙️ System Settings</h1>
                    <p>Configure your examination system</p>
                </div>
            </div>
            
            <div class="container">
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <!-- Maintenance Mode Warning -->
                <?php if($settings['maintenance_mode']): ?>
                <div class="alert alert-warning">
                    ⚠️ <strong>Maintenance Mode is ACTIVE!</strong> Only administrators can access the system. 
                    <a href="?disable_maintenance=1" style="color: #856404;">Click here to disable</a>
                </div>
                <?php endif; ?>
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo date('Y-m-d H:i:s'); ?></div>
                        <div class="stat-label">System Time</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $settings['timezone']; ?></div>
                        <div class="stat-label">Time Zone</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $settings['session_timeout']; ?> min</div>
                        <div class="stat-label">Session Timeout</div>
                    </div>
                </div>
                
                <!-- Settings Tabs -->
                <div class="settings-tabs">
                    <button class="settings-tab active" data-tab="general">🏠 General</button>
                    <button class="settings-tab" data-tab="exam">📝 Exam Settings</button>
                    <button class="settings-tab" data-tab="security">🔒 Security</button>
                    <button class="settings-tab" data-tab="backup">💾 Backup</button>
                    <button class="settings-tab" data-tab="advanced">🚀 Advanced</button>
                </div>
                
                <!-- Tab 1: General Settings -->
                <div id="tab-general" class="tab-content active">
                    <div class="card">
                        <h3>🏠 General Settings</h3>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>System Name</label>
                                    <input type="text" name="system_name" value="<?php echo htmlspecialchars($settings['system_name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>System Email</label>
                                    <input type="email" name="system_email" value="<?php echo htmlspecialchars($settings['system_email']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Default Timezone</label>
                                    <select name="timezone">
                                        <option value="Asia/Kolkata" <?php echo $settings['timezone'] == 'Asia/Kolkata' ? 'selected' : ''; ?>>Asia/Kolkata (IST)</option>
                                        <option value="Asia/Dubai" <?php echo $settings['timezone'] == 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai</option>
                                        <option value="America/New_York" <?php echo $settings['timezone'] == 'America/New_York' ? 'selected' : ''; ?>>America/New_York</option>
                                        <option value="Europe/London" <?php echo $settings['timezone'] == 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                                        <option value="UTC" <?php echo $settings['timezone'] == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Default Language</label>
                                    <select name="default_language">
                                        <option value="en" <?php echo $settings['default_language'] == 'en' ? 'selected' : ''; ?>>English</option>
                                        <option value="es" <?php echo $settings['default_language'] == 'es' ? 'selected' : ''; ?>>Spanish</option>
                                        <option value="fr" <?php echo $settings['default_language'] == 'fr' ? 'selected' : ''; ?>>French</option>
                                        <option value="de" <?php echo $settings['default_language'] == 'de' ? 'selected' : ''; ?>>German</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Logo Path</label>
                                <input type="text" name="logo_path" value="<?php echo htmlspecialchars($settings['logo_path']); ?>" placeholder="../assets/images/logo.png">
                                <div class="info-text">Path to your logo image file</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <span>Allow Student Registration</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="allow_student_registration" <?php echo $settings['allow_student_registration'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="info-text">Allow new students to register themselves</div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <span>Enable Email Notifications</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="enable_email_notification" <?php echo $settings['enable_email_notification'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="info-text">Send email notifications for exam results and announcements</div>
                            </div>
                            
                            <button type="submit" name="save_general" class="btn-primary">Save General Settings</button>
                        </form>
                    </div>
                </div>
                
                <!-- Tab 2: Exam Settings -->
                <div id="tab-exam" class="tab-content">
                    <div class="card">
                        <h3>📝 Exam Configuration</h3>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Default Passing Percentage (%)</label>
                                    <input type="number" name="default_pass_percentage" value="<?php echo $settings['default_pass_percentage']; ?>" min="0" max="100" required>
                                    <div class="info-text">Minimum percentage required to pass an exam</div>
                                </div>
                                <div class="form-group">
                                    <label>Default Exam Duration (minutes)</label>
                                    <input type="number" name="default_duration" value="<?php echo $settings['default_duration']; ?>" min="15" max="180" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Maximum Questions Per Exam</label>
                                <input type="number" name="max_questions_per_exam" value="<?php echo $settings['max_questions_per_exam']; ?>" min="1" max="500" required>
                                <div class="info-text">Maximum number of questions that can be added to a single exam</div>
                            </div>
                            
                            <div class="form-group">
                                <label>Exam Timezone</label>
                                <select name="exam_timezone">
                                    <option value="Asia/Kolkata" <?php echo $settings['exam_timezone'] == 'Asia/Kolkata' ? 'selected' : ''; ?>>Asia/Kolkata (IST)</option>
                                    <option value="Asia/Dubai" <?php echo $settings['exam_timezone'] == 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai</option>
                                    <option value="America/New_York" <?php echo $settings['exam_timezone'] == 'America/New_York' ? 'selected' : ''; ?>>America/New_York</option>
                                </select>
                                <div class="info-text">Timezone used for exam scheduling</div>
                            </div>
                            
                            <button type="submit" name="save_exam" class="btn-primary">Save Exam Settings</button>
                        </form>
                    </div>
                </div>
                
                <!-- Tab 3: Security Settings -->
                <div id="tab-security" class="tab-content">
                    <div class="card">
                        <h3>🔒 Security Configuration</h3>
                        <form method="POST">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Session Timeout (minutes)</label>
                                    <input type="number" name="session_timeout" value="<?php echo $settings['session_timeout']; ?>" min="5" max="120" required>
                                    <div class="info-text">Time after which inactive users are logged out</div>
                                </div>
                                <div class="form-group">
                                    <label>Max Login Attempts</label>
                                    <input type="number" name="max_login_attempts" value="<?php echo $settings['max_login_attempts']; ?>" min="3" max="10" required>
                                    <div class="info-text">Maximum failed login attempts before account lockout</div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <span>Maintenance Mode</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="maintenance_mode" <?php echo $settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="info-text">When enabled, only administrators can access the system</div>
                            </div>
                            
                            <button type="submit" name="save_security" class="btn-primary">Save Security Settings</button>
                        </form>
                    </div>
                    
                    <div class="card">
                        <h3>🔄 System Actions</h3>
                        <div class="action-buttons">
                            <button onclick="clearCache()" class="btn-secondary">Clear System Cache</button>
                            <button onclick="repairDatabase()" class="btn-secondary">Repair Database</button>
                            <button onclick="optimizeDatabase()" class="btn-secondary">Optimize Database</button>
                        </div>
                    </div>
                </div>
                
                <!-- Tab 4: Backup Settings -->
                <div id="tab-backup" class="tab-content">
                    <div class="card">
                        <h3>💾 Database Backup</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px;">
                                    <span>Automatic Backup</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="backup_auto" <?php echo $settings['backup_auto'] ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </label>
                                <div class="info-text">Automatically backup database on schedule</div>
                            </div>
                            
                            <div class="form-group">
                                <label>Backup Frequency</label>
                                <select name="backup_frequency">
                                    <option value="daily" <?php echo $settings['backup_frequency'] == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                    <option value="weekly" <?php echo $settings['backup_frequency'] == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="monthly" <?php echo $settings['backup_frequency'] == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                </select>
                            </div>
                            
                            <button type="submit" name="save_backup" class="btn-primary">Save Backup Settings</button>
                        </form>
                    </div>
                    
                    <div class="card">
                        <h3>📦 Manual Backup</h3>
                        <div class="action-buttons">
                            <button onclick="createBackup()" class="btn-success">Create Backup Now</button>
                            <button onclick="downloadBackup()" class="btn-primary">Download Latest Backup</button>
                            <button onclick="restoreBackup()" class="btn-secondary">Restore from Backup</button>
                        </div>
                        <div id="backupStatus" class="info-text" style="margin-top: 15px;"></div>
                    </div>
                </div>
                
                <!-- Tab 5: Advanced Settings -->
                <div id="tab-advanced" class="tab-content">
                    <div class="card">
                        <h3>🚀 Advanced Configuration</h3>
                        
                        <div class="form-group">
                            <label>System URL</label>
                            <input type="text" value="http://localhost/online_exam_system/" readonly disabled>
                            <div class="info-text">Your system's base URL</div>
                        </div>
                        
                        <div class="form-group">
                            <label>PHP Version</label>
                            <input type="text" value="<?php echo phpversion(); ?>" readonly disabled>
                        </div>
                        
                        <div class="form-group">
                            <label>Database Size</label>
                            <input type="text" id="dbSize" value="Calculating..." readonly disabled>
                        </div>
                        
                        <hr>
                        
                        <h3>⚠️ Danger Zone</h3>
                        <div class="action-buttons">
                            <button onclick="resetSystem()" class="btn-danger" onclick="return confirm('WARNING: This will reset all data! Continue?')">Reset All Data</button>
                            <button onclick="clearAllLogs()" class="btn-danger" onclick="return confirm('Clear all system logs?')">Clear All Logs</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching
        document.querySelectorAll('.settings-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Update active tab
                document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Update active content
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                document.getElementById(`tab-${tabId}`).classList.add('active');
            });
        });
        
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        // Backup functions
        function createBackup() {
            document.getElementById('backupStatus').innerHTML = 'Creating backup...';
            fetch('../includes/backup.php?action=create')
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('backupStatus').innerHTML = '✅ Backup created successfully! File: ' + data.filename;
                    } else {
                        document.getElementById('backupStatus').innerHTML = '❌ Backup failed: ' + data.error;
                    }
                })
                .catch(error => {
                    document.getElementById('backupStatus').innerHTML = '❌ Backup failed!';
                });
        }
        
        function downloadBackup() {
            window.location.href = '../includes/backup.php?action=download';
        }
        
        function restoreBackup() {
            if(confirm('WARNING: Restoring will overwrite current data. Continue?')) {
                window.location.href = '../includes/backup.php?action=restore';
            }
        }
        
        function clearCache() {
            if(confirm('Clear system cache?')) {
                fetch('../includes/clear_cache.php')
                    .then(() => alert('Cache cleared successfully!'))
                    .catch(() => alert('Failed to clear cache'));
            }
        }
        
        function repairDatabase() {
            if(confirm('Repair database tables?')) {
                fetch('../includes/repair_db.php')
                    .then(response => response.json())
                    .then(data => alert(data.message));
            }
        }
        
        function optimizeDatabase() {
            if(confirm('Optimize database tables?')) {
                fetch('../includes/optimize_db.php')
                    .then(response => response.json())
                    .then(data => alert(data.message));
            }
        }
        
        function resetSystem() {
            if(confirm('⚠️ WARNING: This will DELETE ALL DATA! This action cannot be undone. Type "RESET" to confirm.')) {
                const confirmation = prompt('Type "RESET" to confirm:');
                if(confirmation === 'RESET') {
                    window.location.href = '../includes/reset_system.php';
                }
            }
        }
        
        function clearAllLogs() {
            if(confirm('Clear all system logs?')) {
                fetch('../includes/clear_logs.php')
                    .then(() => alert('Logs cleared!'))
                    .catch(() => alert('Failed to clear logs'));
            }
        }
        
        // Calculate database size
        fetch('../includes/get_db_size.php')
            .then(response => response.json())
            .then(data => {
                document.getElementById('dbSize').value = data.size;
            })
            .catch(() => {
                document.getElementById('dbSize').value = 'Unknown';
            });
    </script>
</body>
</html>