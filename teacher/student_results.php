<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'teacher')) {
    header("Location: ../index.php");
    exit();
}

$teacher_id = $_SESSION['user_id'];

// Get teacher's courses
$stmt = $pdo->prepare("SELECT * FROM courses WHERE teacher_id = ?");
$stmt->execute([$teacher_id]);
$courses = $stmt->fetchAll();

$course_filter = isset($_GET['course']) ? (int)$_GET['course'] : 0;
$exam_filter = isset($_GET['exam']) ? (int)$_GET['exam'] : 0;

// Get results
$query = "
    SELECT r.*, u.full_name as student_name, u.username, u.student_id, 
           es.exam_name, c.course_name, c.course_code
    FROM results r
    JOIN exam_schedules es ON r.exam_schedule_id = es.id
    JOIN courses c ON es.course_id = c.id
    JOIN users u ON r.student_id = u.id
    WHERE c.teacher_id = ?
";
$params = [$teacher_id];

if($course_filter > 0) {
    $query .= " AND c.id = ?";
    $params[] = $course_filter;
}
if($exam_filter > 0) {
    $query .= " AND es.id = ?";
    $params[] = $exam_filter;
}

$query .= " ORDER BY r.submitted_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Get exams for selected course
$exams = [];
if($course_filter > 0) {
    $stmt = $pdo->prepare("SELECT id, exam_name FROM exam_schedules WHERE course_id = ?");
    $stmt->execute([$course_filter]);
    $exams = $stmt->fetchAll();
}

// Calculate statistics
$total_students = count(array_unique(array_column($results, 'student_id')));
$avg_score = count($results) > 0 ? array_sum(array_column($results, 'percentage')) / count($results) : 0;
$pass_count = 0;
foreach($results as $r) {
    if($r['percentage'] >= 40) $pass_count++;
}
$pass_rate = count($results) > 0 ? ($pass_count / count($results)) * 100 : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Results - Teacher Portal</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .filter-bar { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .filter-bar select, .filter-bar input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; }
        .btn-primary { background: #667eea; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-export { background: #28a745; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .card { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e0e0e0; }
        .card-body { padding: 20px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e0e0e0; }
        th { background: #f8f9fa; font-weight: 600; }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-passed { background: #28a745; color: white; }
        .badge-failed { background: #dc3545; color: white; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
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
                <li class="nav-item"><a href="student_results.php" class="nav-link active"><span class="nav-icon">📊</span><span class="nav-text">Student Results</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Student Results</h1><p>View student performance in your courses</p></div>
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
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number"><?php echo $total_students; ?></div><div class="stat-label">Students</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo round($avg_score, 1); ?>%</div><div class="stat-label">Average Score</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo count($results); ?></div><div class="stat-label">Total Attempts</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo round($pass_rate, 1); ?>%</div><div class="stat-label">Pass Rate</div></div>
                </div>
                
                <div class="filter-bar">
                    <label>Course:</label>
                    <select id="courseSelect" onchange="location.href='?course='+this.value">
                        <option value="0">All Courses</option>
                        <?php foreach($courses as $course): ?>
                        <option value="<?php echo $course['id']; ?>" <?php echo $course_filter == $course['id'] ? 'selected' : ''; ?>><?php echo $course['course_code']; ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label>Exam:</label>
                    <select id="examSelect" onchange="location.href='?course=<?php echo $course_filter; ?>&exam='+this.value">
                        <option value="0">All Exams</option>
                        <?php foreach($exams as $exam): ?>
                        <option value="<?php echo $exam['id']; ?>" <?php echo $exam_filter == $exam['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($exam['exam_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button class="btn-export" onclick="exportResults()">📥 Export to CSV</button>
                </div>
                
                <div class="card">
                    <div class="card-header"><h3>📊 Student Performance</h3></div>
                    <div class="card-body">
                        <?php if(count($results) > 0): ?>
                        <table id="resultsTable">
                            <thead>
                                <tr><th>Student ID</th><th>Student Name</th><th>Exam</th><th>Course</th><th>Score</th><th>Percentage</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($results as $result): ?>
                                <tr>
                                    <td><?php echo $result['student_id'] ?: 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                    <td><?php echo $result['course_code']; ?></td>
                                    <td><?php echo $result['score'] . '/' . $result['total_marks']; ?></td>
                                    <td><?php echo round($result['percentage'], 1); ?>%</td>
                                    <td><span class="badge badge-<?php echo $result['percentage'] >= 40 ? 'passed' : 'failed'; ?>"><?php echo $result['percentage'] >= 40 ? 'Passed' : 'Failed'; ?></span></td>
                                    <td><?php echo date('Y-m-d', strtotime($result['submitted_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px;">
                                <p>No results found for the selected filters.</p>
                            </div>
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
        
        function exportResults() {
            const table = document.getElementById('resultsTable');
            if(!table) {
                alert('No data to export');
                return;
            }
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            for(let row of rows) {
                let rowData = [];
                const cells = row.querySelectorAll('th, td');
                for(let cell of cells) {
                    rowData.push('"' + cell.innerText.replace(/"/g, '""') + '"');
                }
                csv.push(rowData.join(','));
            }
            
            const blob = new Blob([csv.join('\n')], {type: 'text/csv'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'student_results.csv';
            a.click();
        }
    </script>
</body>
</html>