<?php
require_once '../config/database.php';
require_once '../config/permissions.php';
checkRole(['teacher']);

$teacher_id = $_SESSION['user_id'];

// Get teacher's courses
$stmt = $pdo->prepare("SELECT * FROM courses WHERE teacher_id = ?");
$stmt->execute([$teacher_id]);
$courses = $stmt->fetchAll();

$course_filter = $_GET['course'] ?? 0;

// Get results for teacher's courses
$query = "
    SELECT r.*, u.full_name as student_name, u.student_id, 
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

$query .= " ORDER BY r.submitted_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Calculate statistics
$total_students = count(array_unique(array_column($results, 'student_id')));
$avg_score = array_sum(array_column($results, 'percentage')) / (count($results) ?: 1);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Results - Teacher Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .export-btn {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <nav class="navbar">
            <div class="nav-brand"><h2>👨‍🏫 Teacher Portal</h2></div>
            <div class="nav-menu">
                <a href="dashboard.php">Dashboard</a>
                <a href="create_questions.php">Create Questions</a>
                <a href="question_bank.php">Question Bank</a>
                <a href="view_student_results.php" class="active">Student Results</a>
                <a href="../logout.php">Logout</a>
            </div>
        </nav>
        
        <div class="main-content">
            <h2>📊 Student Performance Results</h2>
            
            <div class="stats-row">
                <div class="stat-card">
                    <h4>Total Students</h4>
                    <div class="stat-number"><?php echo $total_students; ?></div>
                </div>
                <div class="stat-card">
                    <h4>Average Score</h4>
                    <div class="stat-number"><?php echo round($avg_score, 1); ?>%</div>
                </div>
                <div class="stat-card">
                    <h4>Total Exams Taken</h4>
                    <div class="stat-number"><?php echo count($results); ?></div>
                </div>
            </div>
            
            <div class="filter-bar">
                <label>Filter by Course:</label>
                <select onchange="location.href='?course='+this.value">
                    <option value="0">All Courses</option>
                    <?php foreach($courses as $course): ?>
                    <option value="<?php echo $course['id']; ?>" <?php echo $course_filter == $course['id'] ? 'selected' : ''; ?>>
                        <?php echo $course['course_code'] . ' - ' . $course['course_name']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                
                <button onclick="exportToCSV()" class="btn-info export-btn">📥 Export to CSV</button>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Exam</th>
                                <th>Course</th>
                                <th>Score</th>
                                <th>Percentage</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($results as $result): ?>
                            <tr>
                                <td><?php echo $result['student_id']; ?></td>
                                <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                <td><?php echo $result['course_code']; ?></td>
                                <td><?php echo $result['score'] . '/' . $result['total_marks']; ?></td>
                                <td><?php echo round($result['percentage'], 1); ?>%</td>
                                <td>
                                    <span class="badge <?php echo $result['percentage'] >= 40 ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $result['percentage'] >= 40 ? 'Passed' : 'Failed'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($result['submitted_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function exportToCSV() {
            let csv = [];
            let rows = document.querySelectorAll('.data-table tr');
            
            for(let row of rows) {
                let rowData = [];
                let cells = row.querySelectorAll('th, td');
                for(let cell of cells) {
                    rowData.push('"' + cell.innerText.replace(/"/g, '""') + '"');
                }
                csv.push(rowData.join(','));
            }
            
            let blob = new Blob([csv.join('\n')], {type: 'text/csv'});
            let url = URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = 'student_results.csv';
            a.click();
        }
    </script>
</body>
</html>