<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'exam_committee')) {
    header("Location: ../index.php");
    exit();
}

$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

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
    header("Location: manage_exams.php");
    exit();
}

// Handle question selection update
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $selected_questions = isset($_POST['questions']) ? $_POST['questions'] : [];
    
    // Clear existing
    $stmt = $pdo->prepare("DELETE FROM exam_questions WHERE exam_schedule_id = ?");
    $stmt->execute([$exam_id]);
    
    // Add new selection
    $stmt = $pdo->prepare("INSERT INTO exam_questions (exam_schedule_id, question_id) VALUES (?, ?)");
    foreach($selected_questions as $q_id) {
        $stmt->execute([$exam_id, $q_id]);
    }
    
    // Update total questions count
    $stmt = $pdo->prepare("UPDATE exam_schedules SET total_questions = ? WHERE id = ?");
    $stmt->execute([count($selected_questions), $exam_id]);
    
    $message = "✅ Questions updated successfully!";
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Exam Questions</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .question-list { max-height: 500px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 15px; }
        .question-item { padding: 12px; margin-bottom: 10px; border: 1px solid #e0e0e0; border-radius: 8px; }
        .question-item.selected { background: #d4edda; border-color: #28a745; }
        .btn-primary { background: #667eea; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; }
        .alert-success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>Edit Questions for: <?php echo htmlspecialchars($exam['exam_name']); ?></h2>
            <p>Course: <?php echo $exam['course_code']; ?> - <?php echo htmlspecialchars($exam['course_name']); ?></p>
            
            <?php if($message): ?>
                <div class="alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="select-all" style="margin-bottom: 15px;">
                    <label><input type="checkbox" id="selectAll"> Select All Questions</label>
                    <span style="margin-left: 20px;">Selected: <span id="selectedCount">0</span></span>
                </div>
                
                <div class="question-list">
                    <?php foreach($questions as $q): ?>
                    <div class="question-item <?php echo $q['is_selected'] ? 'selected' : ''; ?>">
                        <label style="display: flex; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="questions[]" value="<?php echo $q['id']; ?>" 
                                   class="question-checkbox"
                                   <?php echo $q['is_selected'] ? 'checked' : ''; ?>>
                            <div>
                                <strong><?php echo htmlspecialchars($q['question_text']); ?></strong>
                                <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                    A) <?php echo htmlspecialchars($q['option_a']); ?> |
                                    B) <?php echo htmlspecialchars($q['option_b']); ?> |
                                    C) <?php echo htmlspecialchars($q['option_c']); ?> |
                                    D) <?php echo htmlspecialchars($q['option_d']); ?>
                                </div>
                            </div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <button type="submit" class="btn-primary" style="margin-top: 20px;">Save Questions</button>
                <a href="manage_exams.php" class="btn-secondary">Back</a>
            </form>
        </div>
    </div>
    
    <script>
        document.getElementById('selectAll').addEventListener('change', function(e) {
            document.querySelectorAll('.question-checkbox').forEach(cb => {
                cb.checked = e.target.checked;
                cb.closest('.question-item').classList.toggle('selected', e.target.checked);
            });
            updateCount();
        });
        
        document.querySelectorAll('.question-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                this.closest('.question-item').classList.toggle('selected', this.checked);
                updateCount();
            });
        });
        
        function updateCount() {
            const count = document.querySelectorAll('.question-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = count;
        }
        updateCount();
    </script>
</body>
</html>