<?php
require_once '../config/database.php';
require_once '../config/permissions.php';
checkRole(['exam_committee']);

$committee_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get all courses with approved questions count
try {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(q.id) as approved_questions 
        FROM courses c
        LEFT JOIN questions q ON q.course_id = c.id AND q.status = 'approved'
        WHERE c.status = 'active'
        GROUP BY c.id
    ");
    $stmt->execute();
    $courses = $stmt->fetchAll();
} catch(PDOException $e) {
    $courses = [];
    $error = "Error loading courses: " . $e->getMessage();
}

// Handle form submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['schedule_exam'])) {
    // Get form data and ensure proper types
    $exam_name = trim($_POST['exam_name']);
    $course_id = (int)$_POST['course_id'];
    $exam_date = $_POST['exam_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $duration_minutes = (int)$_POST['duration_minutes'];
    $total_questions = (int)$_POST['total_questions'];
    $passing_percentage = (float)$_POST['passing_percentage'];
    $instructions = trim($_POST['instructions']);
    
    // Validation
    if(empty($exam_name)) {
        $error = "Exam name is required";
    } elseif(empty($course_id)) {
        $error = "Course is required";
    } elseif(empty($exam_date)) {
        $error = "Exam date is required";
    } elseif(empty($start_time)) {
        $error = "Start time is required";
    } elseif(empty($end_time)) {
        $error = "End time is required";
    } else {
        try {
            // Get total marks
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(marks), 0) as total_marks FROM questions WHERE course_id = ? AND status = 'approved' LIMIT ?");
            $stmt->execute([$course_id, $total_questions]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_marks = $row['total_marks'] > 0 ? $row['total_marks'] : $total_questions;
            
            // SIMPLE INSERT - Using direct values to avoid parameter issues
            $sql = "INSERT INTO exam_schedules (
                exam_name, 
                course_id, 
                exam_date, 
                start_time, 
                end_time, 
                duration_minutes, 
                total_questions, 
                total_marks, 
                passing_percentage, 
                instructions, 
                created_by
            ) VALUES (
                '" . addslashes($exam_name) . "', 
                " . $course_id . ", 
                '" . $exam_date . "', 
                '" . $start_time . "', 
                '" . $end_time . "', 
                " . $duration_minutes . ", 
                " . $total_questions . ", 
                " . $total_marks . ", 
                " . $passing_percentage . ", 
                '" . addslashes($instructions) . "', 
                " . $committee_id . "
            )";
            
            // Debug - echo the SQL (uncomment to see the query)
            // echo "<pre>$sql</pre>";
            
            $result = $pdo->exec($sql);
            
            if($result !== false) {
                $message = "✅ Exam scheduled successfully!";
                // Clear form
                $_POST = array();
            } else {
                $error = "Failed to schedule exam. Please try again.";
            }
        } catch(PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Schedule Exam - Exam Committee</title>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 800px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
        }
        .card-header h2 { margin-bottom: 5px; }
        .card-body { padding: 25px; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-secondary {
            background: #6c757d;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            margin-left: 10px;
        }
        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2>📅 Schedule New Examination</h2>
                <p>Create and schedule exams for students</p>
            </div>
            <div class="card-body">
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Exam Name *</label>
                        <input type="text" name="exam_name" required 
                               placeholder="e.g., Mid-Term Examination 2024"
                               value="<?php echo isset($_POST['exam_name']) ? htmlspecialchars($_POST['exam_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Course *</label>
                        <select name="course_id" id="course_id" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach($courses as $course): ?>
                            <option value="<?php echo $course['id']; ?>" 
                                    data-questions="<?php echo $course['approved_questions']; ?>"
                                    <?php echo (isset($_POST['course_id']) && $_POST['course_id'] == $course['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?> 
                                (<?php echo $course['approved_questions']; ?> questions)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Only courses with approved questions can have exams</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Exam Date *</label>
                            <input type="date" name="exam_date" required 
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo isset($_POST['exam_date']) ? htmlspecialchars($_POST['exam_date']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>Duration (minutes) *</label>
                            <input type="number" name="duration_minutes" 
                                   value="<?php echo isset($_POST['duration_minutes']) ? (int)$_POST['duration_minutes'] : 60; ?>" 
                                   min="15" max="180" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Time *</label>
                            <input type="time" name="start_time" required
                                   value="<?php echo isset($_POST['start_time']) ? htmlspecialchars($_POST['start_time']) : '09:00'; ?>">
                        </div>
                        <div class="form-group">
                            <label>End Time *</label>
                            <input type="time" name="end_time" required
                                   value="<?php echo isset($_POST['end_time']) ? htmlspecialchars($_POST['end_time']) : '11:00'; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Questions *</label>
                            <input type="number" name="total_questions" id="total_questions" 
                                   value="<?php echo isset($_POST['total_questions']) ? (int)$_POST['total_questions'] : 10; ?>" 
                                   min="1" required>
                            <div class="help-text">Maximum available: <span id="maxQuestions">-</span></div>
                        </div>
                        <div class="form-group">
                            <label>Passing Percentage *</label>
                            <input type="number" name="passing_percentage" 
                                   value="<?php echo isset($_POST['passing_percentage']) ? (float)$_POST['passing_percentage'] : 40; ?>" 
                                   min="0" max="100" step="5" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Instructions (Optional)</label>
                        <textarea name="instructions" rows="4" 
                                  placeholder="Enter any special instructions for students..."><?php echo isset($_POST['instructions']) ? htmlspecialchars($_POST['instructions']) : ''; ?></textarea>
                    </div>
                    
                    <div style="margin-top: 25px;">
                        <button type="submit" name="schedule_exam" class="btn-primary">📅 Schedule Exam</button>
                        <a href="manage_exams.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        const courseSelect = document.getElementById('course_id');
        const totalQuestions = document.getElementById('total_questions');
        const maxQuestionsSpan = document.getElementById('maxQuestions');
        
        if(courseSelect) {
            courseSelect.addEventListener('change', function() {
                const selected = this.options[this.selectedIndex];
                const maxQ = selected.getAttribute('data-questions');
                
                if(maxQ && maxQ > 0) {
                    maxQuestionsSpan.textContent = maxQ;
                    totalQuestions.max = maxQ;
                    if(parseInt(totalQuestions.value) > parseInt(maxQ)) {
                        totalQuestions.value = maxQ;
                    }
                } else {
                    maxQuestionsSpan.textContent = '0';
                }
            });
            
            if(courseSelect.value) {
                courseSelect.dispatchEvent(new Event('change'));
            }
        }
        
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const start = document.querySelector('[name="start_time"]').value;
            const end = document.querySelector('[name="end_time"]').value;
            
            if(start >= end) {
                e.preventDefault();
                alert('End time must be after start time!');
                return false;
            }
        });
    </script>
</body>
</html>