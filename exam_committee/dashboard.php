<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

$committee_id = $_SESSION['user_id'];
$current_role = getCurrentRole();
$available_roles = getAvailableRoles($pdo, $committee_id);

// Get statistics
$stats = [
    'total_exams' => $pdo->query("SELECT COUNT(*) FROM exam_schedules")->fetchColumn(),
    'upcoming_exams' => $pdo->query("SELECT COUNT(*) FROM exam_schedules WHERE status = 'upcoming' AND exam_date >= CURDATE()")->fetchColumn(),
    'ongoing_exams' => $pdo->query("SELECT COUNT(*) FROM exam_schedules WHERE status = 'ongoing'")->fetchColumn(),
    'pending_questions' => $pdo->query("SELECT COUNT(*) FROM questions WHERE status = 'pending'")->fetchColumn(),
    'total_courses' => $pdo->query("SELECT COUNT(*) FROM courses WHERE status = 'active'")->fetchColumn(),
];

// Get upcoming exams
$stmt = $pdo->query("SELECT es.*, c.course_code FROM exam_schedules es JOIN courses c ON es.course_id = c.id WHERE es.status IN ('upcoming', 'ongoing') ORDER BY es.exam_date ASC LIMIT 5");
$upcoming_exams = $stmt->fetchAll();

// Get pending questions
$stmt = $pdo->query("SELECT q.*, u.full_name as teacher_name, c.course_code FROM questions q JOIN users u ON q.created_by = u.id JOIN courses c ON q.course_id = c.id WHERE q.status = 'pending' LIMIT 5");
$pending_questions = $stmt->fetchAll();
?>
<?php
// Add this at the top of each dashboard file, after session start
require_once '../config/multi_role.php';
$current_role = getCurrentRole();
$available_roles = getAvailableRoles($pdo, $_SESSION['user_id']);
?>

<!-- Add this where you want the role switcher (top bar) -->
<?php if(count($available_roles) > 1): ?>
<div class="role-switcher">
    <span>🎭 Switch Role:</span>
    <form method="POST" action="../includes/switch_role.php" style="display: inline;">
        <select name="new_role" onchange="this.form.submit()" style="padding: 5px 10px; border-radius: 5px;">
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
<!DOCTYPE html>
<html>
<head>
    <title>Exam Committee Dashboard</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; transition: transform 0.3s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-upcoming { background: #17a2b8; color: white; }
        .badge-ongoing { background: #28a745; color: white; }
        .btn-success { background: #28a745; color: white; padding: 4px 10px; border-radius: 3px; text-decoration: none; font-size: 11px; }
        .btn-danger { background: #dc3545; color: white; padding: 4px 10px; border-radius: 3px; text-decoration: none; font-size: 11px; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
    </style>
</head>
<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <div class="dashboard-layout">
        <div class="sidebar">
            <div class="sidebar-header"><h2>📚 Exam System</h2><p>Exam Committee Portal</p></div>
            <div class="user-profile"><div class="user-avatar">📋</div><div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div><div class="user-role">Exam Committee</div></div>
            <ul class="sidebar-nav">
                <li class="nav-item"><a href="dashboard.php" class="nav-link active"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="approve_questions.php" class="nav-link"><span class="nav-icon">✅</span><span class="nav-text">Approve Questions</span></a></li>
                <li class="nav-item"><a href="schedule_exams.php" class="nav-link"><span class="nav-icon">📅</span><span class="nav-text">Schedule Exams</span></a></li>
                <li class="nav-item"><a href="manage_exams.php" class="nav-link"><span class="nav-icon">📋</span><span class="nav-text">Manage Exams</span></a></li>
                <li class="nav-item"><a href="generate_reports.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Reports</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Exam Committee Dashboard</h1><p>Manage examinations and approve questions</p></div>
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
            
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-number"><?php echo $stats['total_exams']; ?></div><div class="stat-label">Total Exams</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $stats['upcoming_exams']; ?></div><div class="stat-label">Upcoming</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $stats['ongoing_exams']; ?></div><div class="stat-label">Ongoing</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $stats['pending_questions']; ?></div><div class="stat-label">Pending Questions</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $stats['total_courses']; ?></div><div class="stat-label">Active Courses</div></div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                <!-- Upcoming Exams -->
                <div style="background: white; border-radius: 10px; padding: 20px;">
                    <h3 style="margin-bottom: 15px;">📅 Upcoming Exams</h3>
                    <?php if(count($upcoming_exams) > 0): ?>
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <?php foreach($upcoming_exams as $exam): ?>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                                <div><strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong><br><small><?php echo $exam['course_code']; ?> | <?php echo date('M d, Y', strtotime($exam['exam_date'])); ?></small></div>
                                <span class="badge badge-<?php echo $exam['status']; ?>"><?php echo ucfirst($exam['status']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>No upcoming exams.</p>
                    <?php endif; ?>
                    <a href="schedule_exams.php" style="display: inline-block; margin-top: 15px; color: #667eea;">+ Schedule New Exam</a>
                </div>
                
                <!-- Pending Questions -->
                <div style="background: white; border-radius: 10px; padding: 20px;">
                    <h3 style="margin-bottom: 15px;">⏳ Pending Questions</h3>
                    <?php if(count($pending_questions) > 0): ?>
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <?php foreach($pending_questions as $q): ?>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                                <div><strong><?php echo substr(htmlspecialchars($q['question_text']), 0, 60); ?>...</strong></div>
                                <div style="font-size: 12px; color: #666; margin-top: 5px;"><?php echo $q['course_code']; ?> | By: <?php echo $q['teacher_name']; ?></div>
                                <div style="margin-top: 10px;">
                                    <a href="approve_questions.php?id=<?php echo $q['id']; ?>&action=approve" class="btn-success" onclick="return confirm('Approve?')">Approve</a>
                                    <a href="approve_questions.php?id=<?php echo $q['id']; ?>&action=reject" class="btn-danger" onclick="return confirm('Reject?')">Reject</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>No pending questions.</p>
                    <?php endif; ?>
                    <a href="approve_questions.php" style="display: inline-block; margin-top: 15px; color: #667eea;">View All →</a>
                </div>
            </div>
        </div>
    </div>
    
    <script>function toggleSidebar(){document.querySelector('.sidebar').classList.toggle('open');document.querySelector('.sidebar-overlay').classList.toggle('active');}</script>
</body>
</html>