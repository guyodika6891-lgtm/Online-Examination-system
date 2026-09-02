<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
$student_id = $_SESSION['user_id'];

// Verify enrollment and exam status
$stmt = $pdo->prepare("
    SELECT es.*, c.course_name, c.course_code,
           (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_schedule_id = es.id) as available_questions
    FROM exam_schedules es
    JOIN courses c ON es.course_id = c.id
    JOIN exam_enrollments ee ON ee.exam_schedule_id = es.id AND ee.student_id = ?
    WHERE es.id = ? AND ee.status = 'registered' AND es.status = 'ongoing'
");
$stmt->execute([$student_id, $schedule_id]);
$exam = $stmt->fetch();

if(!$exam) {
    header("Location: take_exam.php?error=not_enrolled_or_not_started");
    exit();
}

// Check if exam already submitted
$stmt = $pdo->prepare("SELECT * FROM results WHERE exam_schedule_id = ? AND student_id = ?");
$stmt->execute([$schedule_id, $student_id]);
if($stmt->fetch()) {
    header("Location: take_exam.php?error=already_submitted");
    exit();
}

// Get questions specifically assigned to this exam from exam_questions table
$stmt = $pdo->prepare("
    SELECT q.* 
    FROM exam_questions eq
    JOIN questions q ON eq.question_id = q.id
    WHERE eq.exam_schedule_id = ?
    ORDER BY RAND()
");
$stmt->execute([$schedule_id]);
$questions = $stmt->fetchAll();

// If no questions found in exam_questions, fallback to course questions (for backward compatibility)
if(empty($questions)) {
    $stmt = $pdo->prepare("
        SELECT * FROM questions 
        WHERE course_id = ? AND status = 'approved' 
        ORDER BY RAND() 
        LIMIT ?
    ");
    $stmt->execute([$exam['course_id'], $exam['total_questions']]);
    $questions = $stmt->fetchAll();
}

// Update enrollment status to started
$stmt = $pdo->prepare("UPDATE exam_enrollments SET status = 'started' WHERE exam_schedule_id = ? AND student_id = ?");
$stmt->execute([$schedule_id, $student_id]);

$total_questions = count($questions);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($exam['exam_name']); ?> - Online Exam</title>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; }
        
        /* Timer Box */
        .timer-box { 
            position: fixed; 
            top: 20px; 
            right: 20px; 
            background: linear-gradient(135deg, #667eea, #764ba2); 
            color: white; 
            padding: 15px 25px; 
            border-radius: 10px; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.2); 
            text-align: center; 
            z-index: 1000; 
        }
        .timer { font-size: 28px; font-weight: bold; font-family: monospace; }
        
        /* Question Panel */
        .question-panel { 
            background: white; 
            border-radius: 10px; 
            padding: 25px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        .question-number { 
            font-size: 18px; 
            font-weight: bold; 
            color: #667eea; 
            margin-bottom: 15px; 
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        .question-text { 
            font-size: 18px; 
            margin-bottom: 20px; 
            line-height: 1.5; 
        }
        .options { margin-left: 20px; }
        .option { 
            margin: 15px 0; 
            cursor: pointer; 
            padding: 10px; 
            border-radius: 5px; 
            transition: 0.3s; 
        }
        .option:hover { background: #f0f2f5; }
        .option input { margin-right: 10px; cursor: pointer; }
        
        /* Navigation */
        .navigation { 
            position: fixed; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            background: white; 
            padding: 15px; 
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1); 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            z-index: 999; 
        }
        .nav-buttons button { 
            padding: 10px 20px; 
            margin: 0 5px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            font-size: 16px; 
        }
        .btn-prev { background: #6c757d; color: white; }
        .btn-next { background: #667eea; color: white; }
        .btn-submit { background: #28a745; color: white; }
        
        /* Question Status Sidebar */
        .question-status { 
            position: fixed; 
            left: 20px; 
            top: 50%; 
            transform: translateY(-50%); 
            background: white; 
            padding: 15px; 
            border-radius: 10px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            max-height: 80vh; 
            overflow-y: auto; 
            z-index: 100;
        }
        .status-grid { 
            display: grid; 
            grid-template-columns: repeat(5, 35px); 
            gap: 10px; 
            margin-top: 10px; 
        }
        .status-btn { 
            width: 35px; 
            height: 35px; 
            border-radius: 5px; 
            border: none; 
            cursor: pointer; 
            font-weight: bold; 
        }
        .status-unanswered { background: #e0e0e0; color: #666; }
        .status-answered { background: #28a745; color: white; }
        .status-current { background: #ffc107; color: #333; border: 2px solid #667eea; }
        
        /* Main Container */
        .exam-container { max-width: 800px; margin: 20px auto; padding: 20px; }
        
        /* Exam Info Header */
        .exam-info {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .exam-title h2 { color: #333; margin-bottom: 5px; }
        .exam-title p { color: #666; font-size: 14px; }
        .exam-warning { color: #dc3545; font-size: 12px; }
        
        @media (max-width: 768px) { 
            .question-status { display: none; }
            .exam-container { margin: 20px 10px; padding: 10px; }
            .timer-box { padding: 10px 15px; }
            .timer { font-size: 20px; }
        }
    </style>
</head>
<body>
    <!-- Timer Box -->
    <div class="timer-box">
        <h3>⏰ Time Remaining</h3>
        <div class="timer" id="timer">00:00:00</div>
    </div>
    
    <!-- Question Status Sidebar -->
    <div class="question-status">
        <h4>Questions</h4>
        <div class="status-grid" id="questionStatus"></div>
    </div>
    
    <div class="exam-container">
        <!-- Exam Information -->
        <div class="exam-info">
            <div class="exam-title">
                <h2><?php echo htmlspecialchars($exam['exam_name']); ?></h2>
                <p><?php echo $exam['course_code']; ?> - <?php echo htmlspecialchars($exam['course_name']); ?></p>
            </div>
            <div class="exam-warning">
                <strong>⚠️ Note:</strong> Do not refresh the page during the exam
            </div>
        </div>
        
        <!-- Questions Form -->
        <form id="examForm" method="POST" action="submit_exam.php">
            <input type="hidden" name="schedule_id" value="<?php echo $schedule_id; ?>">
            <div id="questionsContainer"></div>
        </form>
    </div>
    
    <!-- Navigation Bar -->
    <div class="navigation">
        <div class="nav-buttons">
            <button onclick="previousQuestion()" class="btn-prev">← Previous</button>
            <button onclick="nextQuestion()" class="btn-next">Next →</button>
        </div>
        <button onclick="submitExam()" class="btn-submit">📝 Submit Exam</button>
    </div>
    
    <script>
        const questions = <?php echo json_encode($questions); ?>;
        const totalQuestions = questions.length;
        const durationMinutes = <?php echo $exam['duration_minutes']; ?>;
        let currentQuestion = 0;
        let answers = new Array(totalQuestions).fill(null);
        
        let timeLeft = durationMinutes * 60;
        const timerDisplay = document.getElementById('timer');
        
        // Timer function
        function updateTimer() {
            if(timeLeft <= 0) {
                submitExam();
                return;
            }
            const hours = Math.floor(timeLeft / 3600);
            const minutes = Math.floor((timeLeft % 3600) / 60);
            const seconds = timeLeft % 60;
            timerDisplay.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            timeLeft--;
        }
        setInterval(updateTimer, 1000);
        
        // Save answers to localStorage
        function saveAnswers() {
            localStorage.setItem(`exam_${<?php echo $schedule_id; ?>}`, JSON.stringify(answers));
        }
        
        // Load saved answers
        function loadSavedAnswers() {
            const saved = localStorage.getItem(`exam_${<?php echo $schedule_id; ?>}`);
            if(saved) {
                const savedAnswers = JSON.parse(saved);
                for(let i = 0; i < savedAnswers.length; i++) {
                    if(savedAnswers[i] !== null) {
                        answers[i] = savedAnswers[i];
                    }
                }
            }
        }
        
        // Render current question
        function renderQuestion() {
            if(totalQuestions === 0) {
                document.getElementById('questionsContainer').innerHTML = '<div class="question-panel"><p>No questions available for this exam.</p></div>';
                return;
            }
            
            const q = questions[currentQuestion];
            const container = document.getElementById('questionsContainer');
            container.innerHTML = `
                <div class="question-panel">
                    <div class="question-number">Question ${currentQuestion + 1} of ${totalQuestions}</div>
                    <div class="question-text">${escapeHtml(q.question_text)}</div>
                    <div class="options">
                        <div class="option" onclick="selectOption('A')">
                            <input type="radio" name="question" value="A" ${answers[currentQuestion] === 'A' ? 'checked' : ''}> 
                            <strong>A.</strong> ${escapeHtml(q.option_a)}
                        </div>
                        <div class="option" onclick="selectOption('B')">
                            <input type="radio" name="question" value="B" ${answers[currentQuestion] === 'B' ? 'checked' : ''}> 
                            <strong>B.</strong> ${escapeHtml(q.option_b)}
                        </div>
                        <div class="option" onclick="selectOption('C')">
                            <input type="radio" name="question" value="C" ${answers[currentQuestion] === 'C' ? 'checked' : ''}> 
                            <strong>C.</strong> ${escapeHtml(q.option_c)}
                        </div>
                        <div class="option" onclick="selectOption('D')">
                            <input type="radio" name="question" value="D" ${answers[currentQuestion] === 'D' ? 'checked' : ''}> 
                            <strong>D.</strong> ${escapeHtml(q.option_d)}
                        </div>
                    </div>
                </div>
                <input type="hidden" name="answers[${q.id}]" id="answer_${q.id}" value="${answers[currentQuestion] || ''}">
            `;
            updateStatusButtons();
        }
        
        function selectOption(value) {
            answers[currentQuestion] = value;
            const hiddenInput = document.getElementById(`answer_${questions[currentQuestion].id}`);
            if(hiddenInput) hiddenInput.value = value;
            saveAnswers();
            renderQuestion();
        }
        
        function previousQuestion() { 
            if(currentQuestion > 0) { 
                currentQuestion--; 
                renderQuestion(); 
            }
        }
        
        function nextQuestion() { 
            if(currentQuestion < totalQuestions - 1) { 
                currentQuestion++; 
                renderQuestion(); 
            }
        }
        
        function updateStatusButtons() {
            const statusGrid = document.getElementById('questionStatus');
            if(!statusGrid) return;
            
            statusGrid.innerHTML = '';
            for(let i = 0; i < totalQuestions; i++) {
                const btn = document.createElement('button');
                btn.textContent = i + 1;
                btn.className = 'status-btn';
                if(answers[i] !== null) btn.classList.add('status-answered');
                else btn.classList.add('status-unanswered');
                if(i === currentQuestion) btn.classList.add('status-current');
                btn.onclick = (function(index) { 
                    return function() { 
                        currentQuestion = index; 
                        renderQuestion(); 
                    }; 
                })(i);
                statusGrid.appendChild(btn);
            }
        }
        
        function escapeHtml(text) { 
            const div = document.createElement('div'); 
            div.textContent = text; 
            return div.innerHTML; 
        }
        
        function submitExam() {
            if(confirm('Are you sure you want to submit the exam? You cannot change answers after submission.')) {
                const form = document.getElementById('examForm');
                for(let i = 0; i < totalQuestions; i++) {
                    if(answers[i] !== null) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = `answers[${questions[i].id}]`;
                        input.value = answers[i];
                        form.appendChild(input);
                    }
                }
                // Clear saved answers from localStorage
                localStorage.removeItem(`exam_${<?php echo $schedule_id; ?>}`);
                form.submit();
            }
        }
        
        // Initialize
        loadSavedAnswers();
        renderQuestion();
        
        // Warn before leaving
        window.addEventListener('beforeunload', function(e) { 
            e.preventDefault(); 
            e.returnValue = 'You have not submitted your exam. Are you sure?'; 
        });
    </script>
</body>
</html>