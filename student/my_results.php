<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$highlight_result_id = isset($_GET['result_id']) ? (int)$_GET['result_id'] : 0;
$submitted = isset($_GET['submitted']) ? true : false;

// Get all results
$stmt = $pdo->prepare("
    SELECT r.*, es.exam_name, es.passing_percentage, c.course_name, c.course_code
    FROM results r
    JOIN exam_schedules es ON r.exam_schedule_id = es.id
    JOIN courses c ON es.course_id = c.id
    WHERE r.student_id = ?
    ORDER BY r.submitted_at DESC
");
$stmt->execute([$student_id]);
$results = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_exams,
        COALESCE(AVG(percentage), 0) as avg_percentage,
        MAX(percentage) as highest_score,
        MIN(percentage) as lowest_score,
        SUM(CASE WHEN percentage >= passing_percentage THEN 1 ELSE 0 END) as passed_count
    FROM results r
    JOIN exam_schedules es ON r.exam_schedule_id = es.id
    WHERE r.student_id = ?
");
$stmt->execute([$student_id]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Results - Student Portal</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #17a2b8; }
        .result-card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); transition: transform 0.3s; }
        .result-card:hover { transform: translateY(-3px); }
        .result-card.highlight { 
            border: 2px solid #28a745; 
            background: #f0fff4;
            animation: pulse 1s ease;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        .result-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; flex-wrap: wrap; gap: 10px; }
        .progress-bar { width: 100%; height: 10px; background: #e0e0e0; border-radius: 5px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #28a745, #20c997); transition: width 0.5s; }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-passed { background: #28a745; color: white; }
        .badge-failed { background: #dc3545; color: white; }
        .btn-details { background: #667eea; color: white; padding: 6px 15px; border: none; border-radius: 5px; cursor: pointer; font-size: 12px; }
        .alert-success { background: #d4edda; color: #155724; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; animation: slideIn 0.5s ease; }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        @media (max-width: 768px) {
            .result-header { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
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
                <li class="nav-item"><a href="take_exam.php" class="nav-link"><span class="nav-icon">📝</span><span class="nav-text">Take Exam</span></a></li>
                <li class="nav-item"><a href="my_results.php" class="nav-link active"><span class="nav-icon">📈</span><span class="nav-text">My Results</span></a></li>
                <li class="nav-item"><a href="certificates.php" class="nav-link"><span class="nav-icon">🏆</span><span class="nav-text">Certificates</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>My Results</h1><p>View your exam performance</p></div>
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
                <?php if($submitted): ?>
                    <div class="alert-success">
                        <span>✅</span>
                        <strong>Exam Submitted Successfully!</strong> Your result has been recorded. You can view your certificate below if you passed.
                    </div>
                <?php endif; ?>
                
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number"><?php echo $stats['total_exams']; ?></div><div class="stat-label">Exams Taken</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo round($stats['avg_percentage'], 1); ?>%</div><div class="stat-label">Average Score</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo round($stats['highest_score'], 1); ?>%</div><div class="stat-label">Highest Score</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $stats['passed_count']; ?>/<?php echo $stats['total_exams']; ?></div><div class="stat-label">Exams Passed</div></div>
                </div>
                
                <?php if(count($results) > 0): ?>
                    <?php foreach($results as $result): ?>
                    <div class="result-card <?php echo ($highlight_result_id == $result['id']) ? 'highlight' : ''; ?>" id="result-<?php echo $result['id']; ?>">
                        <div class="result-header">
                            <div>
                                <h3><?php echo htmlspecialchars($result['exam_name']); ?></h3>
                                <p style="font-size: 12px; color: #666;"><?php echo $result['course_code']; ?> - <?php echo htmlspecialchars($result['course_name']); ?></p>
                                <small>Submitted: <?php echo date('F j, Y g:i A', strtotime($result['submitted_at'])); ?></small>
                            </div>
                            <div class="stat-number" style="color: <?php echo $result['percentage'] >= $result['passing_percentage'] ? '#28a745' : '#dc3545'; ?>">
                                <?php echo round($result['percentage'], 1); ?>%
                            </div>
                        </div>
                        
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $result['percentage']; ?>%"></div>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; flex-wrap: wrap; gap: 10px;">
                            <div><strong>Score:</strong> <?php echo $result['score']; ?> / <?php echo $result['total_marks']; ?> marks</div>
                            <div><strong>Passing:</strong> <?php echo $result['passing_percentage']; ?>%</div>
                            <div><span class="badge <?php echo $result['percentage'] >= $result['passing_percentage'] ? 'badge-passed' : 'badge-failed'; ?>"><?php echo $result['percentage'] >= $result['passing_percentage'] ? '✓ PASSED' : '✗ FAILED'; ?></span></div>
                            <a href="view_results.php?id=<?php echo $result['id']; ?>" class="btn-details">View Details</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="background: white; border-radius: 10px; padding: 40px; text-align: center;">
                        <p>You haven't taken any exams yet.</p>
                        <a href="take_exam.php" class="btn-primary" style="display: inline-block; margin-top: 15px;">Browse Available Exams</a>
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
        
        // Scroll to highlighted result if exists
        <?php if($highlight_result_id): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const highlighted = document.getElementById('result-<?php echo $highlight_result_id; ?>');
            if(highlighted) {
                highlighted.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>