<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'exam_committee')) {
    header("Location: ../index.php");
    exit();
}

$report_type = $_GET['type'] ?? 'overall';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');

// Get report data
$data = [];
$course_data = [];

if($report_type == 'overall') {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT u.id) as total_students,
            COUNT(DISTINCT q.id) as total_questions,
            COUNT(DISTINCT es.id) as total_exams,
            COUNT(r.id) as total_exams_taken,
            COALESCE(AVG(r.percentage), 0) as avg_percentage,
            SUM(CASE WHEN r.percentage >= es.passing_percentage THEN 1 ELSE 0 END) as passed_count
        FROM users u
        CROSS JOIN exam_schedules es
        LEFT JOIN questions q ON 1=1
        LEFT JOIN results r ON r.student_id = u.id AND r.exam_schedule_id = es.id
        WHERE u.role = 'student'
    ");
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif($report_type == 'course') {
    $stmt = $pdo->prepare("
        SELECT c.course_code, c.course_name,
               COUNT(DISTINCT es.id) as exams_count,
               COUNT(r.id) as attempts,
               COALESCE(AVG(r.percentage), 0) as avg_score,
               COUNT(DISTINCT r.student_id) as students_count
        FROM courses c
        LEFT JOIN exam_schedules es ON es.course_id = c.id
        LEFT JOIN results r ON r.exam_schedule_id = es.id
        GROUP BY c.id
        ORDER BY avg_score DESC
    ");
    $stmt->execute();
    $course_data = $stmt->fetchAll();
} elseif($report_type == 'student') {
    $stmt = $pdo->prepare("
        SELECT u.full_name, u.username, u.student_id,
               COUNT(r.id) as exams_taken,
               COALESCE(AVG(r.percentage), 0) as avg_percentage,
               MAX(r.percentage) as highest_score,
               MIN(r.percentage) as lowest_score
        FROM users u
        LEFT JOIN results r ON r.student_id = u.id
        WHERE u.role = 'student'
        GROUP BY u.id
        ORDER BY avg_percentage DESC
    ");
    $stmt->execute();
    $student_data = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Generate Reports - Exam Committee</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
        .filter-bar { background: white; padding: 20px; border-radius: 10px; margin-bottom: 25px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .filter-bar select, .filter-bar input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; }
        .btn-primary { background: #667eea; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-export { background: #28a745; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .card-header { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        canvas { max-height: 300px; }
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
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="approve_questions.php" class="nav-link"><span class="nav-icon">✅</span><span class="nav-text">Approve Questions</span></a></li>
                <li class="nav-item"><a href="schedule_exams.php" class="nav-link"><span class="nav-icon">📅</span><span class="nav-text">Schedule Exams</span></a></li>
                <li class="nav-item"><a href="manage_exams.php" class="nav-link"><span class="nav-icon">📋</span><span class="nav-text">Manage Exams</span></a></li>
                <li class="nav-item"><a href="generate_reports.php" class="nav-link active"><span class="nav-icon">📊</span><span class="nav-text">Reports</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Generate Reports</h1><p>View examination analytics and performance reports</p></div>
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
            
            <div class="container">
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <label>Report Type:</label>
                    <select name="type" id="reportType" onchange="changeReportType()">
                        <option value="overall" <?php echo $report_type == 'overall' ? 'selected' : ''; ?>>Overall Statistics</option>
                        <option value="course" <?php echo $report_type == 'course' ? 'selected' : ''; ?>>Course-wise Performance</option>
                        <option value="student" <?php echo $report_type == 'student' ? 'selected' : ''; ?>>Student Performance</option>
                    </select>
                    
                    <label>From:</label>
                    <input type="date" id="date_from" value="<?php echo $date_from; ?>">
                    <label>To:</label>
                    <input type="date" id="date_to" value="<?php echo $date_to; ?>">
                    <button class="btn-primary" onclick="applyFilter()">Apply Filter</button>
                    <button class="btn-export" onclick="exportReport()">📥 Export to CSV</button>
                </div>
                
                <?php if($report_type == 'overall'): ?>
                    <!-- Overall Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card"><div class="stat-number"><?php echo $data['total_students'] ?? 0; ?></div><div class="stat-label">Total Students</div></div>
                        <div class="stat-card"><div class="stat-number"><?php echo $data['total_questions'] ?? 0; ?></div><div class="stat-label">Total Questions</div></div>
                        <div class="stat-card"><div class="stat-number"><?php echo $data['total_exams'] ?? 0; ?></div><div class="stat-label">Total Exams</div></div>
                        <div class="stat-card"><div class="stat-number"><?php echo $data['total_exams_taken'] ?? 0; ?></div><div class="stat-label">Exams Taken</div></div>
                        <div class="stat-card"><div class="stat-number"><?php echo round($data['avg_percentage'] ?? 0, 1); ?>%</div><div class="stat-label">Average Score</div></div>
                        <div class="stat-card"><div class="stat-number"><?php echo $data['total_exams_taken'] > 0 ? round(($data['passed_count'] / $data['total_exams_taken']) * 100, 1) : 0; ?>%</div><div class="stat-label">Pass Rate</div></div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header"><h3>Performance Chart</h3></div>
                        <canvas id="performanceChart" width="400" height="200"></canvas>
                    </div>
                    
                <?php elseif($report_type == 'course'): ?>
                    <!-- Course-wise Performance -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Course-wise Performance</h3>
                        </div>
                        <table id="reportTable">
                            <thead>
                                <tr><th>Course Code</th><th>Course Name</th><th>Exams</th><th>Attempts</th><th>Avg Score</th><th>Students</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($course_data as $course): ?>
                                <tr>
                                    <td><?php echo $course['course_code']; ?></td>
                                    <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                    <td><?php echo $course['exams_count']; ?></td>
                                    <td><?php echo $course['attempts']; ?></td>
                                    <td><?php echo round($course['avg_score'], 1); ?>%</td>
                                    <td><?php echo $course['students_count']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                <?php elseif($report_type == 'student'): ?>
                    <!-- Student Performance -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Student Performance</h3>
                        </div>
                        <table id="reportTable">
                            <thead>
                                <tr><th>Student Name</th><th>Username</th><th>Student ID</th><th>Exams Taken</th><th>Avg Score</th><th>Highest</th><th>Lowest</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($student_data as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td><?php echo $student['username']; ?></td>
                                    <td><?php echo $student['student_id'] ?? 'N/A'; ?></td>
                                    <td><?php echo $student['exams_taken']; ?></td>
                                    <td><?php echo round($student['avg_percentage'], 1); ?>%</td>
                                    <td><?php echo round($student['highest_score'], 1); ?>%</td>
                                    <td><?php echo round($student['lowest_score'], 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function changeReportType() {
            const type = document.getElementById('reportType').value;
            const from = document.getElementById('date_from').value;
            const to = document.getElementById('date_to').value;
            window.location.href = `?type=${type}&date_from=${from}&date_to=${to}`;
        }
        
        function applyFilter() {
            changeReportType();
        }
        
        function exportReport() {
            const table = document.getElementById('reportTable');
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
            a.download = `exam_report_${new Date().toISOString().slice(0,19)}.csv`;
            a.click();
        }
        
        <?php if($report_type == 'overall' && isset($data)): ?>
        const ctx = document.getElementById('performanceChart')?.getContext('2d');
        if(ctx) {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Passed', 'Failed'],
                    datasets: [{
                        label: 'Exam Results',
                        data: [<?php echo $data['passed_count']; ?>, <?php echo ($data['total_exams_taken'] - $data['passed_count']); ?>],
                        backgroundColor: ['#28a745', '#dc3545'],
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'top' },
                        title: { display: true, text: 'Student Performance Overview' }
                    }
                }
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>