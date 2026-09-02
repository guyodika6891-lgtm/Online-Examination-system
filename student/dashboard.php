<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$student_id = $_SESSION['user_id'];

// Get enrolled courses
$stmt = $pdo->prepare("
    SELECT c.*, d.dept_name, u.full_name as teacher_name,
           (SELECT COUNT(*) FROM exam_schedules WHERE course_id = c.id AND status IN ('upcoming', 'ongoing')) as active_exams
    FROM student_courses sc
    JOIN courses c ON sc.course_id = c.id
    JOIN departments d ON c.department_id = d.id
    LEFT JOIN users u ON c.teacher_id = u.id
    WHERE sc.student_id = ? AND sc.status = 'active' AND c.status = 'active'
    ORDER BY c.course_code
");
$stmt->execute([$student_id]);
$my_courses = $stmt->fetchAll();

// Get upcoming exams
$stmt = $pdo->prepare("
    SELECT es.*, c.course_name, c.course_code,
           CASE WHEN ee.id IS NOT NULL THEN 'registered' ELSE 'not_registered' END as registration_status
    FROM exam_schedules es
    JOIN courses c ON es.course_id = c.id
    JOIN student_courses sc ON sc.course_id = c.id AND sc.student_id = ? AND sc.status = 'active'
    LEFT JOIN exam_enrollments ee ON ee.exam_schedule_id = es.id AND ee.student_id = ?
    WHERE es.exam_date >= CURDATE() AND es.status IN ('upcoming', 'ongoing')
    ORDER BY es.exam_date ASC, es.start_time ASC
    LIMIT 5
");
$stmt->execute([$student_id, $student_id]);
$upcoming_exams = $stmt->fetchAll();

// Get recent results
$stmt = $pdo->prepare("
    SELECT r.*, es.exam_name, c.course_name, c.course_code
    FROM results r
    JOIN exam_schedules es ON r.exam_schedule_id = es.id
    JOIN courses c ON es.course_id = c.id
    WHERE r.student_id = ?
    ORDER BY r.submitted_at DESC
    LIMIT 5
");
$stmt->execute([$student_id]);
$recent_results = $stmt->fetchAll();

// Get statistics
$total_courses = count($my_courses);
$total_exams = $pdo->prepare("SELECT COUNT(*) FROM results WHERE student_id = ?");
$total_exams->execute([$student_id]);
$total_exams_taken = $total_exams->fetchColumn();

$avg_score = $pdo->prepare("SELECT COALESCE(AVG(percentage), 0) FROM results WHERE student_id = ?");
$avg_score->execute([$student_id]);
$average_score = round($avg_score->fetchColumn(), 1);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #17a2b8; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 15px; }
        .course-card { background: linear-gradient(135deg, #17a2b8, #138496); color: white; padding: 20px; border-radius: 10px; cursor: pointer; transition: transform 0.3s; }
        .course-card:hover { transform: translateY(-3px); }
        .course-code { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
        .course-name { font-size: 14px; opacity: 0.9; margin-bottom: 10px; }
        .exam-list { display: flex; flex-direction: column; gap: 15px; }
        .exam-item { background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .btn-register { background: #28a745; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none; font-size: 12px; }
        .btn-view { background: #17a2b8; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none; font-size: 12px; }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-passed { background: #28a745; color: white; }
        .badge-failed { background: #dc3545; color: white; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
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
                <li class="nav-item"><a href="dashboard.php" class="nav-link active"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="take_exam.php" class="nav-link"><span class="nav-icon">📝</span><span class="nav-text">Take Exam</span></a></li>
                <li class="nav-item"><a href="my_results.php" class="nav-link"><span class="nav-icon">📈</span><span class="nav-text">My Results</span></a></li>
                 <li class="nav-item"><a href="certificates.php" class="nav-link"><span class="nav-icon">🏆</span><span class="nav-text">Certificates</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Student Dashboard</h1><p>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</p></div>
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
            
            <?php if($total_courses == 0): ?>
            <div class="warning-box">
                <strong>⚠️ No Courses Enrolled Yet!</strong><br>
                You haven't been enrolled in any courses. Please contact the administrator.
            </div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-number"><?php echo $total_courses; ?></div><div class="stat-label">My Courses</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $total_exams_taken; ?></div><div class="stat-label">Exams Taken</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $average_score; ?>%</div><div class="stat-label">Average Score</div></div>
            </div>
            
            <div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 25px;">
                <h3>📖 My Enrolled Courses</h3>
                <div class="course-grid">
                    <?php foreach($my_courses as $course): ?>
                    <div class="course-card" onclick="location.href='take_exam.php?course_id=<?php echo $course['id']; ?>'">
                        <div class="course-code"><?php echo $course['course_code']; ?></div>
                        <div class="course-name"><?php echo htmlspecialchars($course['course_name']); ?></div>
                        <div style="font-size: 11px; opacity: 0.8;">Teacher: <?php echo $course['teacher_name']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <?php if(count($upcoming_exams) > 0): ?>
            <div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 25px;">
                <h3>⏰ Upcoming Exams</h3>
                <div class="exam-list">
                    <?php foreach($upcoming_exams as $exam): ?>
                    <div class="exam-item">
                        <div>
                            <strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong><br>
                            <small><?php echo $exam['course_code']; ?> | <?php echo date('M d, Y g:i A', strtotime($exam['exam_date'] . ' ' . $exam['start_time'])); ?></small>
                        </div>
                        <?php if($exam['registration_status'] == 'registered'): ?>
                            <button class="btn-register" style="background:#6c757d;" disabled>✓ Registered</button>
                        <?php else: ?>
                            <a href="register_exam.php?id=<?php echo $exam['id']; ?>" class="btn-register">Register</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if(count($recent_results) > 0): ?>
            <div style="background: white; border-radius: 10px; padding: 20px;">
                <h3>📊 Recent Results</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead><tr><th style="text-align: left; padding: 10px;">Exam</th><th>Score</th><th>Percentage</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($recent_results as $result): ?>
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($result['exam_name']); ?><br><small><?php echo $result['course_code']; ?></small></td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo $result['score'] . '/' . $result['total_marks']; ?></td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo round($result['percentage'], 1); ?>%</td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><span class="badge <?php echo $result['percentage'] >= 40 ? 'badge-passed' : 'badge-failed'; ?>"><?php echo $result['percentage'] >= 40 ? 'Passed' : 'Failed'; ?></span></td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><a href="view_results.php?id=<?php echo $result['id']; ?>" class="btn-view">View Details</a></td>
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