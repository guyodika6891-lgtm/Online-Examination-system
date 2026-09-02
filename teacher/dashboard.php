<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

// Check if user has teacher role
if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'teacher')) {
    header("Location: ../index.php");
    exit();
}

$teacher_id = $_SESSION['user_id'];

// Get assigned courses
$stmt = $pdo->prepare("
    SELECT c.*, d.dept_name,
           COUNT(DISTINCT q.id) as total_questions,
           SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END) as pending_questions,
           SUM(CASE WHEN q.status = 'approved' THEN 1 ELSE 0 END) as approved_questions,
           SUM(CASE WHEN q.status = 'rejected' THEN 1 ELSE 0 END) as rejected_questions
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.id
    LEFT JOIN questions q ON q.course_id = c.id
    WHERE c.teacher_id = ? AND c.status = 'active'
    GROUP BY c.id
");
$stmt->execute([$teacher_id]);
$my_courses = $stmt->fetchAll();

// Get question statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM questions WHERE created_by = ?
");
$stmt->execute([$teacher_id]);
$q_stats = $stmt->fetch();

// Get recent questions
$stmt = $pdo->prepare("
    SELECT q.*, c.course_code 
    FROM questions q
    JOIN courses c ON q.course_id = c.id
    WHERE q.created_by = ? 
    ORDER BY q.created_at DESC 
    LIMIT 5
");
$stmt->execute([$teacher_id]);
$recent_questions = $stmt->fetchAll();

$total_courses = count($my_courses);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Teacher Dashboard</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; margin-top: 20px; }
        .course-card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #28a745; }
        .course-code { font-size: 18px; font-weight: bold; color: #667eea; }
        .course-name { margin: 5px 0; color: #333; }
        .course-stats { display: flex; gap: 15px; margin: 15px 0; padding: 10px 0; border-top: 1px solid #eee; border-bottom: 1px solid #eee; }
        .btn-primary { background: #667eea; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none; display: inline-block; font-size: 13px; }
        .btn-sm { background: #28a745; color: white; padding: 4px 10px; border-radius: 3px; text-decoration: none; font-size: 11px; }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #ffc107; color: #333; }
        .badge-approved { background: #28a745; color: white; }
        .badge-rejected { background: #dc3545; color: white; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
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
                <li class="nav-item"><a href="dashboard.php" class="nav-link active"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="create_questions.php" class="nav-link"><span class="nav-icon">➕</span><span class="nav-text">Create Questions</span></a></li>
                <li class="nav-item"><a href="question_bank.php" class="nav-link"><span class="nav-icon">📚</span><span class="nav-text">Question Bank</span></a></li>
                <li class="nav-item"><a href="student_results.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Student Results</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Teacher Dashboard</h1><p>Manage your courses and questions</p></div>
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
            
            <?php if($total_courses == 0): ?>
            <div class="warning-box">
                <strong>⚠️ No Courses Assigned Yet!</strong><br>
                You haven't been assigned any courses by the administrator. Once courses are assigned, you'll be able to create questions.
            </div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-number"><?php echo $total_courses; ?></div><div class="stat-label">My Courses</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $q_stats['total']; ?></div><div class="stat-label">Total Questions</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $q_stats['pending']; ?></div><div class="stat-label">Pending Approval</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $q_stats['approved']; ?></div><div class="stat-label">Approved</div></div>
            </div>
            
            <div style="background: white; border-radius: 10px; padding: 20px;">
                <h3>📖 My Assigned Courses</h3>
                <div class="course-grid">
                    <?php foreach($my_courses as $course): ?>
                    <div class="course-card">
                        <div class="course-code"><?php echo $course['course_code']; ?></div>
                        <div class="course-name"><?php echo htmlspecialchars($course['course_name']); ?></div>
                        <div class="course-stats">
                            <div><strong><?php echo $course['total_questions']; ?></strong><br>Total</div>
                            <div><strong><?php echo $course['pending_questions']; ?></strong><br>Pending</div>
                            <div><strong><?php echo $course['approved_questions']; ?></strong><br>Approved</div>
                        </div>
                        <a href="create_questions.php?course_id=<?php echo $course['id']; ?>" class="btn-primary">+ Add Questions</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <?php if(count($recent_questions) > 0): ?>
            <div style="background: white; border-radius: 10px; padding: 20px; margin-top: 25px;">
                <h3>📝 Recently Created Questions</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead><tr><th style="text-align: left; padding: 10px;">Question</th><th>Course</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($recent_questions as $q): ?>
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo substr(htmlspecialchars($q['question_text']), 0, 60); ?>...</td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo $q['course_code']; ?></td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><span class="badge badge-<?php echo $q['status']; ?>"><?php echo ucfirst($q['status']); ?></span></td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo date('M d, Y', strtotime($q['created_at'])); ?></td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><a href="edit_question.php?id=<?php echo $q['id']; ?>" class="btn-sm">Edit</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>function toggleSidebar(){document.querySelector('.sidebar').classList.toggle('open');document.querySelector('.sidebar-overlay').classList.toggle('active');}</script>
</body>
</html>