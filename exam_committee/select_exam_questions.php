<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'exam_committee')) {
    header("Location: ../index.php");
    exit();
}

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$message = '';
$error = '';

// Get exam details
$stmt = $pdo->prepare("
    SELECT es.*, c.course_name, c.course_code 
    FROM exam_schedules es
    JOIN courses c ON es.course_id = c.id
    WHERE es.id = ?
");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if(!$exam) {
    header("Location: schedule_exams.php");
    exit();
}

// Handle question selection
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $selected_questions = isset($_POST['questions']) ? $_POST['questions'] : [];
    
    if(empty($selected_questions)) {
        $error = "Please select at least one question for the exam.";
    } else {
        try {
            // Clear existing questions for this exam
            $stmt = $pdo->prepare("DELETE FROM exam_questions WHERE exam_schedule_id = ?");
            $stmt->execute([$exam_id]);
            
            // Add selected questions
            $stmt = $pdo->prepare("INSERT INTO exam_questions (exam_schedule_id, question_id) VALUES (?, ?)");
            foreach($selected_questions as $q_id) {
                $stmt->execute([$exam_id, $q_id]);
            }
            
            $message = "✅ " . count($selected_questions) . " questions selected for this exam!";
            
            // Redirect back to manage exams after 2 seconds
            header("refresh:2;url=manage_exams.php");
            
        } catch(PDOException $e) {
            $error = "Error saving questions: " . $e->getMessage();
        }
    }
}

// Get all approved questions for this course
$stmt = $pdo->prepare("
    SELECT q.*, 
           CASE WHEN eq.id IS NOT NULL THEN 1 ELSE 0 END as is_selected
    FROM questions q
    LEFT JOIN exam_questions eq ON eq.question_id = q.id AND eq.exam_schedule_id = ?
    WHERE q.course_id = ? AND q.status = 'approved'
    ORDER BY q.created_at DESC
");
$stmt->execute([$exam_id, $exam['course_id']]);
$questions = $stmt->fetchAll();

// Get already selected questions count
$selected_count = 0;
foreach($questions as $q) {
    if($q['is_selected']) $selected_count++;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Select Questions for Exam</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; }
        .card-body { padding: 25px; }
        .exam-info { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .question-list { max-height: 500px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 10px; }
        .question-item { padding: 15px; margin: 5px; border: 1px solid #e0e0e0; border-radius: 8px; background: #f8f9fa; transition: 0.3s; }
        .question-item.selected { background: #d4edda; border-color: #28a745; }
        .question-item:hover { background: #e8f4e8; }
        .question-text { font-weight: 500; margin-bottom: 10px; }
        .options-preview { font-size: 12px; color: #666; margin-left: 25px; }
        .options-preview span { display: inline-block; margin-right: 15px; }
        .select-all { margin-bottom: 15px; padding: 12px; background: #e7f3ff; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .btn-primary { background: #28a745; color: white; padding: 12px 30px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
        .btn-secondary { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-block; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .alert-info { background: #d1ecf1; color: #0c5460; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        .checkbox-custom { width: 20px; height: 20px; cursor: pointer; }
        @media (max-width: 768px) {
            .question-item { padding: 10px; }
            .options-preview { margin-left: 10px; }
            .options-preview span { display: block; margin: 3px 0; }
        }
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
                <li class="nav-item"><a href="generate_reports.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Reports</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Select Questions for Exam</h1><p>Choose questions to include in this examination</p></div>
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
                <div class="card">
                    <div class="card-header">
                        <h2>📝 <?php echo htmlspecialchars($exam['exam_name']); ?></h2>
                        <p><?php echo $exam['course_code']; ?> - <?php echo htmlspecialchars($exam['course_name']); ?></p>
                    </div>
                    <div class="card-body">
                        <?php if($message): ?>
                            <div class="alert alert-success"><?php echo $message; ?> Redirecting to Manage Exams...</div>
                        <?php endif; ?>
                        <?php if($error): ?>
                            <div class="alert alert-error"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <div class="exam-info">
                            <div><strong>📅 Exam Date:</strong> <?php echo date('F j, Y', strtotime($exam['exam_date'])); ?></div>
                            <div><strong>⏰ Duration:</strong> <?php echo $exam['duration_minutes']; ?> minutes</div>
                            <div><strong>📊 Passing:</strong> <?php echo $exam['passing_percentage']; ?>%</div>
                            <div><strong>❓ Available Questions:</strong> <?php echo count($questions); ?></div>
                        </div>
                        
                        <form method="POST" id="questionForm">
                            <div class="select-all">
                                <div>
                                    <label>
                                        <input type="checkbox" id="selectAll" class="checkbox-custom">
                                        <strong>✓ Select All Questions</strong>
                                    </label>
                                    <span style="margin-left: 20px;">Selected: <span id="selectedCount"><?php echo $selected_count; ?></span> / <?php echo count($questions); ?></span>
                                </div>
                                <div>
                                    <button type="submit" class="btn-primary">✓ Save Selected Questions</button>
                                    <a href="manage_exams.php" class="btn-secondary">Skip for Now</a>
                                </div>
                            </div>
                            
                            <div class="question-list">
                                <?php if(count($questions) > 0): ?>
                                    <?php foreach($questions as $index => $q): ?>
                                    <div class="question-item <?php echo $q['is_selected'] ? 'selected' : ''; ?>" data-id="<?php echo $q['id']; ?>">
                                        <label style="display: flex; align-items: flex-start; gap: 15px; cursor: pointer;">
                                            <input type="checkbox" name="questions[]" value="<?php echo $q['id']; ?>" 
                                                   class="question-checkbox"
                                                   <?php echo $q['is_selected'] ? 'checked' : ''; ?>
                                                   style="margin-top: 3px;">
                                            <div style="flex: 1;">
                                                <div class="question-text">
                                                    <strong>Q<?php echo $index + 1; ?>.</strong> <?php echo htmlspecialchars($q['question_text']); ?>
                                                    <span style="font-size: 11px; color: #888; margin-left: 10px;">(<?php echo ucfirst($q['difficulty']); ?> | <?php echo $q['marks']; ?> marks)</span>
                                                </div>
                                                <div class="options-preview">
                                                    <span>A) <?php echo htmlspecialchars(substr($q['option_a'], 0, 50)); ?></span>
                                                    <span>B) <?php echo htmlspecialchars(substr($q['option_b'], 0, 50)); ?></span>
                                                    <span>C) <?php echo htmlspecialchars(substr($q['option_c'], 0, 50)); ?></span>
                                                    <span>D) <?php echo htmlspecialchars(substr($q['option_d'], 0, 50)); ?></span>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="alert alert-info" style="margin: 20px;">
                                        No approved questions available for this course. 
                                        <a href="../teacher/create_questions.php">Create questions first</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </form>
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
        
        // Select All functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        const questionCheckboxes = document.querySelectorAll('.question-checkbox');
        
        function updateSelectedCount() {
            const checked = document.querySelectorAll('.question-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = checked;
        }
        
        function updateQuestionItemStyle() {
            document.querySelectorAll('.question-item').forEach(item => {
                const checkbox = item.querySelector('.question-checkbox');
                if(checkbox && checkbox.checked) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
        }
        
        if(selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                questionCheckboxes.forEach(cb => {
                    cb.checked = selectAllCheckbox.checked;
                });
                updateSelectedCount();
                updateQuestionItemStyle();
            });
        }
        
        questionCheckboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                updateSelectedCount();
                updateQuestionItemStyle();
                
                // Check if all are checked
                const allChecked = questionCheckboxes.length === document.querySelectorAll('.question-checkbox:checked').length;
                if(selectAllCheckbox) selectAllCheckbox.checked = allChecked;
            });
        });
        
        updateSelectedCount();
    </script>
</body>
</html>