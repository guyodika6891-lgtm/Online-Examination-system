<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$result_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get result details
$stmt = $pdo->prepare("
    SELECT r.*, es.exam_name, es.passing_percentage, es.duration_minutes, es.total_questions as exam_total_questions,
           c.course_name, c.course_code
    FROM results r
    JOIN exam_schedules es ON r.exam_schedule_id = es.id
    JOIN courses c ON es.course_id = c.id
    WHERE r.id = ? AND r.student_id = ?
");
$stmt->execute([$result_id, $student_id]);
$result = $stmt->fetch();

if(!$result) {
    header("Location: my_results.php?error=not_found");
    exit();
}

// Get answers details
$answers = json_decode($result['answers'], true);
$question_details = [];

foreach($answers as $q_id => $user_answer) {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
    $stmt->execute([$q_id]);
    $q = $stmt->fetch();
    
    if($q) {
        $is_correct = ($user_answer == $q['correct_answer']);
        $question_details[] = [
            'id' => $q['id'],
            'text' => $q['question_text'],
            'options' => [
                'A' => $q['option_a'],
                'B' => $q['option_b'],
                'C' => $q['option_c'],
                'D' => $q['option_d']
            ],
            'correct_answer' => $q['correct_answer'],
            'user_answer' => $user_answer,
            'is_correct' => $is_correct,
            'marks' => $q['marks']
        ];
    }
}

$score_percentage = ($result['score'] / $result['total_marks']) * 100;
$passed = $score_percentage >= $result['passing_percentage'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Exam Result Details - <?php echo htmlspecialchars($result['exam_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        
        /* Result Header Card */
        .result-header-card { background: white; border-radius: 15px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .result-summary { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
        .result-score { text-align: center; }
        .score-circle { width: 120px; height: 120px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-size: 36px; font-weight: bold; }
        .score-circle.passed { background: linear-gradient(135deg, #28a745, #20c997); color: white; }
        .score-circle.failed { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
        .result-stats { display: flex; gap: 30px; flex-wrap: wrap; }
        .stat-box { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px; min-width: 100px; }
        .stat-value { font-size: 24px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
        
        /* Question Cards */
        .question-card { background: white; border-radius: 12px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .question-card:hover { transform: translateX(5px); }
        .question-header { padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; }
        .question-number { font-weight: 600; color: #333; }
        .question-status { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .question-status.correct { background: #d4edda; color: #155724; }
        .question-status.incorrect { background: #f8d7da; color: #721c24; }
        .question-status.unanswered { background: #fff3cd; color: #856404; }
        .question-body { padding: 20px; display: none; }
        .question-body.show { display: block; }
        .question-text { font-size: 16px; font-weight: 500; margin-bottom: 20px; }
        .options-list { margin-left: 20px; }
        .option-item { padding: 10px; margin: 5px 0; border-radius: 8px; display: flex; align-items: center; gap: 10px; }
        .option-correct { background: #d4edda; border-left: 3px solid #28a745; }
        .option-wrong { background: #f8d7da; border-left: 3px solid #dc3545; }
        .option-selected { font-weight: bold; }
        .option-marker { width: 30px; font-weight: bold; }
        .explanation-box { margin-top: 15px; padding: 12px; background: #e7f3ff; border-radius: 8px; font-size: 13px; }
        
        /* Buttons */
        .btn-back { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-print { background: #17a2b8; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        
        /* Progress Bar */
        .progress-container { margin: 20px 0; }
        .progress-label { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 12px; }
        .progress-bar-custom { width: 100%; height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #28a745, #20c997); transition: width 0.5s; }
        
        @media (max-width: 768px) {
            .result-summary { flex-direction: column; text-align: center; }
            .result-stats { justify-content: center; }
            .question-header { flex-direction: column; gap: 10px; text-align: center; }
        }
        
        @media print {
            .sidebar, .top-bar, .nav-menu, .btn-back, .btn-print, .role-switcher, .mobile-toggle { display: none; }
            .main-content { margin: 0; padding: 0; }
            .question-body { display: block !important; }
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
                <li class="nav-item"><a href="my_results.php" class="nav-link"><span class="nav-icon">📈</span><span class="nav-text">My Results</span></a></li>
                 <li class="nav-item"><a href="certificates.php" class="nav-link"><span class="nav-icon">🏆</span><span class="nav-text">Certificates</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>Exam Result Details</h1>
                    <p><?php echo htmlspecialchars($result['exam_name']); ?> - <?php echo htmlspecialchars($result['course_code']); ?></p>
                </div>
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
            
            <div class="container">
                <!-- Result Header -->
                <div class="result-header-card">
                    <div class="result-summary">
                        <div class="result-score">
                            <div class="score-circle <?php echo $passed ? 'passed' : 'failed'; ?>">
                                <?php echo round($score_percentage, 1); ?>%
                            </div>
                            <div style="font-size: 14px; margin-top: 10px;">
                                <?php echo $passed ? '🎉 PASSED' : '❌ FAILED'; ?>
                            </div>
                        </div>
                        
                        <div class="result-stats">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $result['score']; ?>/<?php echo $result['total_marks']; ?></div>
                                <div class="stat-label">Total Score</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $result['total_questions']; ?></div>
                                <div class="stat-label">Questions</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo $result['passing_percentage']; ?>%</div>
                                <div class="stat-label">Passing Mark</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo date('M d, Y', strtotime($result['submitted_at'])); ?></div>
                                <div class="stat-label">Date</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="progress-container">
                        <div class="progress-label">
                            <span>Your Performance</span>
                            <span><?php echo round($score_percentage, 1); ?>%</span>
                        </div>
                        <div class="progress-bar-custom">
                            <div class="progress-fill" style="width: <?php echo $score_percentage; ?>%"></div>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px; justify-content: center;">
                        <a href="my_results.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Results</a>
                        <button onclick="window.print()" class="btn-print"><i class="fas fa-print"></i> Print Result</button>
                    </div>
                </div>
                
                <!-- Questions Section -->
                <h3 style="margin-bottom: 15px;">📝 Detailed Answers</h3>
                
                <?php foreach($question_details as $index => $q): 
                    $is_correct = $q['is_correct'];
                    $status_class = $is_correct ? 'correct' : ($q['user_answer'] ? 'incorrect' : 'unanswered');
                    $status_text = $is_correct ? '✓ Correct' : ($q['user_answer'] ? '✗ Incorrect' : '⚡ Not Answered');
                ?>
                <div class="question-card">
                    <div class="question-header" onclick="toggleQuestion(<?php echo $index; ?>)">
                        <div>
                            <span class="question-number">Question <?php echo $index + 1; ?></span>
                            <span style="font-size: 12px; color: #666; margin-left: 10px;">(<?php echo $q['marks']; ?> mark)</span>
                        </div>
                        <div>
                            <span class="question-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            <i class="fas fa-chevron-down" style="margin-left: 10px;"></i>
                        </div>
                    </div>
                    <div class="question-body" id="question-<?php echo $index; ?>">
                        <div class="question-text"><?php echo nl2br(htmlspecialchars($q['text'])); ?></div>
                        
                        <div class="options-list">
                            <?php foreach(['A', 'B', 'C', 'D'] as $option): ?>
                                <?php 
                                    $is_correct_opt = ($option == $q['correct_answer']);
                                    $is_user_opt = ($option == $q['user_answer']);
                                    $opt_class = '';
                                    if($is_correct_opt) $opt_class = 'option-correct';
                                    elseif($is_user_opt && !$is_correct_opt) $opt_class = 'option-wrong';
                                ?>
                                <div class="option-item <?php echo $opt_class; ?>">
                                    <span class="option-marker"><strong><?php echo $option; ?>.</strong></span>
                                    <span class="<?php echo $is_user_opt ? 'option-selected' : ''; ?>">
                                        <?php echo htmlspecialchars($q['options'][$option]); ?>
                                    </span>
                                    <?php if($is_correct_opt): ?>
                                        <span style="color: #28a745; font-size: 12px;">✓ Correct Answer</span>
                                    <?php endif; ?>
                                    <?php if($is_user_opt && !$is_correct_opt): ?>
                                        <span style="color: #dc3545; font-size: 12px;">✗ Your Answer (Wrong)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="explanation-box">
                            <i class="fas fa-info-circle" style="color: #2196F3;"></i>
                            <strong>Explanation:</strong> The correct answer is <strong>Option <?php echo $q['correct_answer']; ?></strong>.
                            <?php if($q['user_answer']): ?>
                                <?php if($is_correct): ?>
                                    <span style="color: #28a745;">✓ You answered correctly and earned <?php echo $q['marks']; ?> mark(s).</span>
                                <?php else: ?>
                                    <span style="color: #dc3545;">✗ You answered incorrectly. The correct answer is shown above.</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #856404;">⚠️ You did not answer this question.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function toggleQuestion(index) {
            const questionBody = document.getElementById(`question-${index}`);
            const chevron = document.querySelector(`#question-${index}`).previousElementSibling.querySelector('.fa-chevron-down');
            
            if(questionBody.classList.contains('show')) {
                questionBody.classList.remove('show');
                if(chevron) chevron.classList.remove('fa-chevron-up');
                if(chevron) chevron.classList.add('fa-chevron-down');
            } else {
                questionBody.classList.add('show');
                if(chevron) chevron.classList.remove('fa-chevron-down');
                if(chevron) chevron.classList.add('fa-chevron-up');
            }
        }
        
        // Open first question by default
        document.addEventListener('DOMContentLoaded', function() {
            if(document.getElementById('question-0')) {
                toggleQuestion(0);
            }
        });
    </script>
</body>
</html>