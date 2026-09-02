<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$course_filter = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Get all enrolled courses for filter
$stmt = $pdo->prepare("
    SELECT c.id, c.course_code, c.course_name
    FROM student_courses sc
    JOIN courses c ON sc.course_id = c.id
    WHERE sc.student_id = ? AND sc.status = 'active'
    ORDER BY c.course_code
");
$stmt->execute([$student_id]);
$my_courses = $stmt->fetchAll();

// Get available exams - EXCLUDE completed exams (where student already has a result)
$query = "
    SELECT es.*, c.course_name, c.course_code,
           CASE WHEN ee.id IS NOT NULL THEN 'registered' ELSE 'not_registered' END as registration_status,
           (SELECT COUNT(*) FROM exam_questions WHERE exam_schedule_id = es.id) as actual_questions_count,
           CASE 
               WHEN es.exam_date > CURDATE() THEN 'upcoming'
               WHEN es.exam_date = CURDATE() AND CURTIME() < es.start_time THEN 'upcoming'
               WHEN es.exam_date = CURDATE() AND CURTIME() BETWEEN es.start_time AND es.end_time THEN 'available'
               WHEN es.exam_date = CURDATE() AND CURTIME() > es.end_time THEN 'expired'
               WHEN es.exam_date < CURDATE() THEN 'expired'
               ELSE 'upcoming'
           END as time_status
    FROM exam_schedules es
    JOIN courses c ON es.course_id = c.id
    JOIN student_courses sc ON sc.course_id = c.id AND sc.student_id = ? AND sc.status = 'active'
    LEFT JOIN exam_enrollments ee ON ee.exam_schedule_id = es.id AND ee.student_id = ?
    WHERE es.status IN ('upcoming', 'ongoing')
      AND NOT EXISTS (
          SELECT 1 FROM results r 
          WHERE r.exam_schedule_id = es.id AND r.student_id = ?
      )
";
$params = [$student_id, $student_id, $student_id];

if($course_filter > 0) {
    $query .= " AND c.id = ?";
    $params[] = $course_filter;
}

$query .= " ORDER BY es.exam_date ASC, es.start_time ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$exams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Take Exam - Student Portal</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .filter-bar { background: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .filter-bar select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; }
        .exam-card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); transition: transform 0.3s; }
        .exam-card:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.15); }
        .exam-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; }
        .exam-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 15px; }
        .exam-details p { margin: 5px 0; }
        .btn-start { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-register { background: #17a2b8; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-expired { background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: not-allowed; display: inline-block; }
        .btn-registered-waiting { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: not-allowed; display: inline-block; }
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-upcoming { background: #17a2b8; color: white; }
        .badge-available { background: #28a745; color: white; }
        .badge-expired { background: #dc3545; color: white; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        .no-exams { text-align: center; padding: 50px; background: white; border-radius: 10px; }
        .completed-badge { background: #6c757d; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
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
                <li class="nav-item"><a href="take_exam.php" class="nav-link active"><span class="nav-icon">📝</span><span class="nav-text">Take Exam</span></a></li>
                <li class="nav-item"><a href="my_results.php" class="nav-link"><span class="nav-icon">📈</span><span class="nav-text">My Results</span></a></li>
                <li class="nav-item"><a href="certificates.php" class="nav-link"><span class="nav-icon">🏆</span><span class="nav-text">Certificates</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Available Examinations</h1><p>View and register for exams</p></div>
                <div class="top-bar-right">
                    <?php
                    $available_roles = getAvailableRoles($pdo, $_SESSION['user_id']);
                    $current_role = getCurrentRole();
                    if(count($available_roles) > 1): ?>
                    <div class="role-switcher">
                        <span>🎭</span>
                        <form method="POST" action="../includes/switch_role.php">
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
                <div class="filter-bar">
                    <label>Filter by Course:</label>
                    <select onchange="location.href='?course_id='+this.value">
                        <option value="0">All Courses</option>
                        <?php foreach($my_courses as $course): ?>
                        <option value="<?php echo $course['id']; ?>" <?php echo $course_filter == $course['id'] ? 'selected' : ''; ?>>
                            <?php echo $course['course_code']; ?> - <?php echo htmlspecialchars($course['course_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if(count($exams) > 0): ?>
                    <?php foreach($exams as $exam): ?>
                    <div class="exam-card">
                        <div class="exam-header">
                            <h3><?php echo htmlspecialchars($exam['exam_name']); ?></h3>
                            <?php if($exam['time_status'] == 'available'): ?>
                                <span class="badge badge-available">Available Now</span>
                            <?php elseif($exam['time_status'] == 'expired'): ?>
                                <span class="badge badge-expired">Expired</span>
                            <?php else: ?>
                                <span class="badge badge-upcoming">Upcoming</span>
                            <?php endif; ?>
                        </div>
                        <div class="exam-details">
                            <p><strong>Course:</strong> <?php echo $exam['course_code']; ?> - <?php echo htmlspecialchars($exam['course_name']); ?></p>
                            <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($exam['exam_date'])); ?></p>
                            <p><strong>Time:</strong> <?php echo date('g:i A', strtotime($exam['start_time'])); ?> - <?php echo date('g:i A', strtotime($exam['end_time'])); ?></p>
                            <p><strong>Duration:</strong> <?php echo $exam['duration_minutes']; ?> minutes</p>
                            <p><strong>Questions:</strong> <?php echo $exam['actual_questions_count'] > 0 ? $exam['actual_questions_count'] : $exam['total_questions']; ?></p>
                            <p><strong>Passing:</strong> <?php echo $exam['passing_percentage']; ?>%</p>
                        </div>
                        <div style="text-align: right;">
                            <?php if($exam['time_status'] == 'expired'): ?>
                                <button class="btn-expired" disabled>Exam Expired</button>
                            <?php elseif($exam['time_status'] == 'available' && $exam['registration_status'] == 'registered'): ?>
                                <a href="exam_interface.php?schedule_id=<?php echo $exam['id']; ?>" class="btn-start">Start Exam</a>
                            <?php elseif($exam['time_status'] == 'available' && $exam['registration_status'] == 'not_registered'): ?>
                                <a href="register_exam.php?id=<?php echo $exam['id']; ?>" class="btn-register">Register Now</a>
                            <?php elseif($exam['registration_status'] == 'registered'): ?>
                                <button class="btn-registered-waiting" disabled>✓ Registered - Awaiting Start Time</button>
                            <?php else: ?>
                                <a href="register_exam.php?id=<?php echo $exam['id']; ?>" class="btn-register">Register for Exam</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-exams">
                        <p>No exams available for your enrolled courses at this time.</p>
                        <a href="my_results.php" style="display: inline-block; margin-top: 15px; color: #667eea;">View Your Completed Exams →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>function toggleSidebar(){document.querySelector('.sidebar').classList.toggle('open');document.querySelector('.sidebar-overlay').classList.toggle('active');}</script>
</body>
</html>