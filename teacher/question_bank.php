<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'teacher')) {
    header("Location: ../index.php");
    exit();
}

$teacher_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle delete
if(isset($_GET['delete']) && isset($_GET['id'])) {
    $question_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ? AND created_by = ?");
    if($stmt->execute([$question_id, $teacher_id])) {
        $message = "Question deleted successfully!";
        logActivity($pdo, $teacher_id, 'question_deleted', "Deleted question ID: $question_id");
    } else {
        $error = "Failed to delete question.";
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$course_filter = $_GET['course'] ?? 'all';

// Build query
$query = "
    SELECT q.*, c.course_name, c.course_code
    FROM questions q
    JOIN courses c ON q.course_id = c.id
    WHERE q.created_by = ?
";
$params = [$teacher_id];

if($status_filter != 'all') {
    $query .= " AND q.status = ?";
    $params[] = $status_filter;
}
if($course_filter != 'all') {
    $query .= " AND q.course_id = ?";
    $params[] = $course_filter;
}

$query .= " ORDER BY q.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$questions = $stmt->fetchAll();

// Get courses for filter
$stmt = $pdo->prepare("SELECT * FROM courses WHERE teacher_id = ?");
$stmt->execute([$teacher_id]);
$courses = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM questions WHERE created_by = ?
");
$stmt->execute([$teacher_id]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Question Bank - Teacher Portal</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 15px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .filter-bar { background: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .filter-bar select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; }
        .question-card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .question-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .question-meta { display: flex; gap: 15px; font-size: 12px; color: #666; margin-bottom: 10px; }
        .options { margin: 15px 0; padding: 10px; background: #f8f9fa; border-radius: 8px; }
        .badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #ffc107; color: #333; }
        .badge-approved { background: #28a745; color: white; }
        .badge-rejected { background: #dc3545; color: white; }
        .btn-edit { background: #17a2b8; color: white; padding: 4px 10px; border-radius: 3px; text-decoration: none; font-size: 11px; }
        .btn-delete { background: #dc3545; color: white; padding: 4px 10px; border-radius: 3px; text-decoration: none; font-size: 11px; }
        .btn-primary { background: #667eea; color: white; padding: 8px 15px; border-radius: 5px; text-decoration: none; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        .correct-option { background: #d4edda; }
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
                <li class="nav-item"><a href="question_bank.php" class="nav-link active"><span class="nav-icon">📚</span><span class="nav-text">Question Bank</span></a></li>
                <li class="nav-item"><a href="student_results.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Student Results</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Question Bank</h1><p>Manage your questions</p></div>
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
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number"><?php echo $stats['total']; ?></div><div class="stat-label">Total</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $stats['pending']; ?></div><div class="stat-label">Pending</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $stats['approved']; ?></div><div class="stat-label">Approved</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $stats['rejected']; ?></div><div class="stat-label">Rejected</div></div>
                </div>
                
                <div class="filter-bar">
                    <label>Status:</label>
                    <select onchange="location.href='?status='+this.value+'&course=<?php echo $course_filter; ?>'">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    
                    <label>Course:</label>
                    <select onchange="location.href='?status=<?php echo $status_filter; ?>&course='+this.value">
                        <option value="all" <?php echo $course_filter == 'all' ? 'selected' : ''; ?>>All Courses</option>
                        <?php foreach($courses as $course): ?>
                        <option value="<?php echo $course['id']; ?>" <?php echo $course_filter == $course['id'] ? 'selected' : ''; ?>><?php echo $course['course_code']; ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <a href="create_questions.php" class="btn-primary" style="margin-left: auto;">+ New Question</a>
                </div>
                
                <?php if(count($questions) > 0): ?>
                    <?php foreach($questions as $q): ?>
                    <div class="question-card">
                        <div class="question-header">
                            <strong>Q: <?php echo htmlspecialchars($q['question_text']); ?></strong>
                            <span class="badge badge-<?php echo $q['status']; ?>"><?php echo ucfirst($q['status']); ?></span>
                        </div>
                        <div class="question-meta">
                            <span><strong>Course:</strong> <?php echo $q['course_code']; ?></span>
                            <span><strong>Marks:</strong> <?php echo $q['marks']; ?></span>
                            <span><strong>Difficulty:</strong> <?php echo ucfirst($q['difficulty']); ?></span>
                            <span><strong>Created:</strong> <?php echo date('M d, Y', strtotime($q['created_at'])); ?></span>
                        </div>
                        <div class="options">
                            <div class="<?php echo $q['correct_answer'] == 'A' ? 'correct-option' : ''; ?>"><strong>A.</strong> <?php echo htmlspecialchars($q['option_a']); ?></div>
                            <div class="<?php echo $q['correct_answer'] == 'B' ? 'correct-option' : ''; ?>"><strong>B.</strong> <?php echo htmlspecialchars($q['option_b']); ?></div>
                            <div class="<?php echo $q['correct_answer'] == 'C' ? 'correct-option' : ''; ?>"><strong>C.</strong> <?php echo htmlspecialchars($q['option_c']); ?></div>
                            <div class="<?php echo $q['correct_answer'] == 'D' ? 'correct-option' : ''; ?>"><strong>D.</strong> <?php echo htmlspecialchars($q['option_d']); ?></div>
                        </div>
                        <div>
                            <a href="edit_question.php?id=<?php echo $q['id']; ?>" class="btn-edit">Edit</a>
                            <a href="?delete=1&id=<?php echo $q['id']; ?>" class="btn-delete" onclick="return confirm('Delete this question?')">Delete</a>
                            <?php if($q['status'] == 'rejected' && $q['rejection_reason']): ?>
                                <span style="font-size: 11px; color: #dc3545; margin-left: 10px;">Reason: <?php echo htmlspecialchars($q['rejection_reason']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="background: white; border-radius: 10px; padding: 40px; text-align: center;">
                        <p>No questions found.</p>
                        <a href="create_questions.php" class="btn-primary" style="margin-top: 15px;">Create Your First Question</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>function toggleSidebar(){document.querySelector('.sidebar').classList.toggle('open');document.querySelector('.sidebar-overlay').classList.toggle('active');}</script>
</body>
</html>