<?php
require_once '../config/database.php';
require_once '../config/permissions.php';
checkRole(['teacher']);

$question_id = $_GET['id'] ?? 0;
$teacher_id = $_SESSION['user_id'];

// Verify ownership
$stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ? AND created_by = ?");
$stmt->execute([$question_id, $teacher_id]);
$question = $stmt->fetch();

if(!$question) {
    header("Location: question_bank.php?error=not_found");
    exit();
}

$message = '';
$error = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $question_text = $_POST['question_text'];
    $option_a = $_POST['option_a'];
    $option_b = $_POST['option_b'];
    $option_c = $_POST['option_c'];
    $option_d = $_POST['option_d'];
    $correct_answer = $_POST['correct_answer'];
    $marks = $_POST['marks'];
    $difficulty = $_POST['difficulty'];
    
    // Reset status to pending for re-approval
    $stmt = $pdo->prepare("
        UPDATE questions 
        SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, 
            correct_answer = ?, marks = ?, difficulty = ?, status = 'pending', 
            approved_by = NULL, approved_at = NULL
        WHERE id = ? AND created_by = ?
    ");
    
    if($stmt->execute([$question_text, $option_a, $option_b, $option_c, $option_d, 
                      $correct_answer, $marks, $difficulty, $question_id, $teacher_id])) {
        $message = "Question updated and submitted for re-approval!";
        logActivity($pdo, $teacher_id, 'question_updated', "Updated question ID: $question_id");
        
        // Refresh question data
        $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch();
    } else {
        $error = "Failed to update question.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Question - Teacher Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
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
                <a href="question_bank.php" class="active">Question Bank</a>
                <a href="../logout.php">Logout</a>
            </div>
        </nav>
        
        <div class="main-content">
            <div class="form-container">
                <h2>✏️ Edit Question</h2>
                
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea name="question_text" rows="4" required><?php echo htmlspecialchars($question['question_text']); ?></textarea>
                    </div>
                    
                    <div class="options-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Option A *</label>
                            <input type="text" name="option_a" value="<?php echo htmlspecialchars($question['option_a']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Option B *</label>
                            <input type="text" name="option_b" value="<?php echo htmlspecialchars($question['option_b']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Option C *</label>
                            <input type="text" name="option_c" value="<?php echo htmlspecialchars($question['option_c']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Option D *</label>
                            <input type="text" name="option_d" value="<?php echo htmlspecialchars($question['option_d']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Correct Answer *</label>
                        <select name="correct_answer" required>
                            <option value="A" <?php echo $question['correct_answer'] == 'A' ? 'selected' : ''; ?>>Option A</option>
                            <option value="B" <?php echo $question['correct_answer'] == 'B' ? 'selected' : ''; ?>>Option B</option>
                            <option value="C" <?php echo $question['correct_answer'] == 'C' ? 'selected' : ''; ?>>Option C</option>
                            <option value="D" <?php echo $question['correct_answer'] == 'D' ? 'selected' : ''; ?>>Option D</option>
                        </select>
                    </div>
                    
                    <div class="options-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Marks *</label>
                            <input type="number" name="marks" value="<?php echo $question['marks']; ?>" min="1" required>
                        </div>
                        <div class="form-group">
                            <label>Difficulty Level</label>
                            <select name="difficulty">
                                <option value="easy" <?php echo $question['difficulty'] == 'easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="medium" <?php echo $question['difficulty'] == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="hard" <?php echo $question['difficulty'] == 'hard' ? 'selected' : ''; ?>>Hard</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Note:</strong> After editing, this question will require re-approval from the Exam Committee.
                    </div>
                    
                    <button type="submit" class="btn-primary">Update Question</button>
                    <a href="question_bank.php" class="btn-secondary">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>