<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

// Check if user has exam_committee role
if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'exam_committee')) {
    header("Location: ../index.php");
    exit();
}

$committee_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle single question approval/rejection
if(isset($_GET['action']) && isset($_GET['id'])) {
    $question_id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    if($action == 'approve') {
        $stmt = $pdo->prepare("
            UPDATE questions 
            SET status = 'approved', approved_by = ?, approved_at = NOW() 
            WHERE id = ?
        ");
        if($stmt->execute([$committee_id, $question_id])) {
            $message = "Question approved successfully!";
            logActivity($pdo, $committee_id, 'question_approved', "Approved question ID: $question_id");
        } else {
            $error = "Failed to approve question.";
        }
    } elseif($action == 'reject') {
        $reason = $_GET['reason'] ?? 'No reason provided';
        $stmt = $pdo->prepare("UPDATE questions SET status = 'rejected', rejection_reason = ? WHERE id = ?");
        if($stmt->execute([$reason, $question_id])) {
            $message = "Question rejected!";
            logActivity($pdo, $committee_id, 'question_rejected', "Rejected question ID: $question_id");
        } else {
            $error = "Failed to reject question.";
        }
    }
}

// Handle bulk approval
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_approve'])) {
    $question_ids = $_POST['question_ids'] ?? [];
    if(!empty($question_ids)) {
        $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
        $stmt = $pdo->prepare("
            UPDATE questions 
            SET status = 'approved', approved_by = ?, approved_at = NOW() 
            WHERE id IN ($placeholders)
        ");
        $params = array_merge([$committee_id], $question_ids);
        if($stmt->execute($params)) {
            $message = count($question_ids) . " questions approved successfully!";
            logActivity($pdo, $committee_id, 'bulk_approved', "Approved " . count($question_ids) . " questions");
        } else {
            $error = "Failed to approve questions.";
        }
    } else {
        $error = "No questions selected.";
    }
}

// Get all pending questions
$stmt = $pdo->prepare("
    SELECT q.*, u.full_name as teacher_name, u.username, c.course_name, c.course_code
    FROM questions q
    JOIN users u ON q.created_by = u.id
    JOIN courses c ON q.course_id = c.id
    WHERE q.status = 'pending'
    ORDER BY q.created_at ASC
");
$stmt->execute();
$pending_questions = $stmt->fetchAll();

// Get statistics
$total_pending = count($pending_questions);
$total_approved = $pdo->query("SELECT COUNT(*) FROM questions WHERE status = 'approved'")->fetchColumn();
$total_rejected = $pdo->query("SELECT COUNT(*) FROM questions WHERE status = 'rejected'")->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Approve Questions - Exam Committee</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
        .question-card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .question-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0; }
        .question-meta { display: flex; gap: 20px; font-size: 12px; color: #666; margin-bottom: 15px; }
        .options { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .option { padding: 8px; margin: 5px 0; }
        .correct-option { background: #d4edda; border-left: 3px solid #28a745; }
        .btn-approve { background: #28a745; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-reject { background: #dc3545; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-secondary { background: #6c757d; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .alert { padding: 12px; border-radius: 5px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .bulk-actions { background: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; }
        .select-all { margin-right: 15px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; width: 400px; max-width: 90%; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
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
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="approve_questions.php" class="nav-link active"><span class="nav-icon">✅</span><span class="nav-text">Approve Questions</span></a></li>
                <li class="nav-item"><a href="schedule_exams.php" class="nav-link"><span class="nav-icon">📅</span><span class="nav-text">Schedule Exams</span></a></li>
                <li class="nav-item"><a href="manage_exams.php" class="nav-link"><span class="nav-icon">📋</span><span class="nav-text">Manage Exams</span></a></li>
                <li class="nav-item"><a href="generate_reports.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Reports</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Approve Questions</h1><p>Review and approve questions submitted by teachers</p></div>
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
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number"><?php echo $total_pending; ?></div><div class="stat-label">Pending Approval</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $total_approved; ?></div><div class="stat-label">Approved</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $total_rejected; ?></div><div class="stat-label">Rejected</div></div>
                </div>
                
                <?php if(count($pending_questions) > 0): ?>
                    <form method="POST" id="bulkForm">
                        <div class="bulk-actions">
                            <label class="select-all">
                                <input type="checkbox" id="selectAll"> Select All
                            </label>
                            <button type="submit" name="bulk_approve" class="btn-approve" onclick="return confirm('Approve selected questions?')">Approve Selected</button>
                        </div>
                        
                        <?php foreach($pending_questions as $q): ?>
                        <div class="question-card">
                            <div class="question-header">
                                <div>
                                    <input type="checkbox" name="question_ids[]" value="<?php echo $q['id']; ?>" class="question-checkbox">
                                    <strong>Question #<?php echo $q['id']; ?></strong>
                                </div>
                                <span class="badge badge-pending">Pending</span>
                            </div>
                            
                            <div class="question-meta">
                                <span><strong>Course:</strong> <?php echo $q['course_code']; ?></span>
                                <span><strong>Teacher:</strong> <?php echo $q['teacher_name']; ?></span>
                                <span><strong>Created:</strong> <?php echo date('M d, Y g:i A', strtotime($q['created_at'])); ?></span>
                                <span><strong>Difficulty:</strong> <?php echo ucfirst($q['difficulty']); ?></span>
                                <span><strong>Marks:</strong> <?php echo $q['marks']; ?></span>
                            </div>
                            
                            <div class="question-text">
                                <strong>Question:</strong>
                                <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($q['question_text'])); ?></p>
                            </div>
                            
                            <div class="options">
                                <div class="option <?php echo $q['correct_answer'] == 'A' ? 'correct-option' : ''; ?>">
                                    <strong>A.</strong> <?php echo htmlspecialchars($q['option_a']); ?>
                                    <?php if($q['correct_answer'] == 'A'): ?> <span style="color: #28a745;">✓ Correct Answer</span><?php endif; ?>
                                </div>
                                <div class="option <?php echo $q['correct_answer'] == 'B' ? 'correct-option' : ''; ?>">
                                    <strong>B.</strong> <?php echo htmlspecialchars($q['option_b']); ?>
                                    <?php if($q['correct_answer'] == 'B'): ?> <span style="color: #28a745;">✓ Correct Answer</span><?php endif; ?>
                                </div>
                                <div class="option <?php echo $q['correct_answer'] == 'C' ? 'correct-option' : ''; ?>">
                                    <strong>C.</strong> <?php echo htmlspecialchars($q['option_c']); ?>
                                    <?php if($q['correct_answer'] == 'C'): ?> <span style="color: #28a745;">✓ Correct Answer</span><?php endif; ?>
                                </div>
                                <div class="option <?php echo $q['correct_answer'] == 'D' ? 'correct-option' : ''; ?>">
                                    <strong>D.</strong> <?php echo htmlspecialchars($q['option_d']); ?>
                                    <?php if($q['correct_answer'] == 'D'): ?> <span style="color: #28a745;">✓ Correct Answer</span><?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <button type="button" onclick="approveQuestion(<?php echo $q['id']; ?>)" class="btn-approve">✓ Approve</button>
                                <button type="button" onclick="showRejectModal(<?php echo $q['id']; ?>)" class="btn-reject">✗ Reject</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </form>
                <?php else: ?>
                    <div class="alert alert-success" style="text-align: center;">
                        <i class="fas fa-check-circle"></i> No pending questions! All questions have been reviewed.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <h3>Reject Question</h3>
            <form method="GET" action="">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="reject_question_id">
                <div class="form-group">
                    <label>Reason for Rejection</label>
                    <textarea name="reason" rows="4" required placeholder="Please provide feedback to the teacher..."></textarea>
                </div>
                <button type="submit" class="btn-reject">Submit Rejection</button>
                <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function approveQuestion(questionId) {
            if(confirm('Approve this question?')) {
                window.location.href = `?action=approve&id=${questionId}`;
            }
        }
        
        function showRejectModal(questionId) {
            document.getElementById('reject_question_id').value = questionId;
            document.getElementById('rejectModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }
        
        // Select all functionality
        const selectAll = document.getElementById('selectAll');
        if(selectAll) {
            selectAll.addEventListener('change', function() {
                document.querySelectorAll('.question-checkbox').forEach(cb => {
                    cb.checked = this.checked;
                });
            });
        }
    </script>
</body>
</html>