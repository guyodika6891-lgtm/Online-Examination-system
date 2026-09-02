<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'exam_committee')) {
    header("Location: ../index.php");
    exit();
}

$committee_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Get courses with approved questions
$stmt = $pdo->prepare("
    SELECT c.*, COUNT(q.id) as approved_questions 
    FROM courses c
    LEFT JOIN questions q ON q.course_id = c.id AND q.status = 'approved'
    WHERE c.status = 'active'
    GROUP BY c.id
    HAVING approved_questions > 0
");
$stmt->execute();
$courses = $stmt->fetchAll();

// Handle form submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['schedule_exam'])) {
    $exam_name = trim($_POST['exam_name']);
    $course_id = (int)$_POST['course_id'];
    $exam_date = $_POST['exam_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $duration_minutes = (int)$_POST['duration_minutes'];
    $total_questions = (int)$_POST['total_questions'];
    $passing_percentage = (float)$_POST['passing_percentage'];
    $instructions = trim($_POST['instructions']);
    $device_datetime = $_POST['device_datetime']; // Get device time from hidden field
    
    if(empty($exam_name) || empty($course_id) || empty($exam_date) || empty($start_time) || empty($end_time)) {
        $error = "Please fill in all required fields.";
    } elseif($start_time >= $end_time) {
        $error = "❌ Start time must be before end time!";
    } elseif($duration_minutes < 15) {
        $error = "❌ Exam duration must be at least 15 minutes.";
    } elseif($total_questions < 1) {
        $error = "❌ At least 1 question is required.";
    } else {
        // Check if there's already an exam for this course on the same date and overlapping time
        $stmt = $pdo->prepare("
            SELECT id FROM exam_schedules 
            WHERE course_id = ? 
            AND exam_date = ? 
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
            AND status != 'cancelled'
        ");
        $stmt->execute([$course_id, $exam_date, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time]);
        
        if($stmt->rowCount() > 0) {
            $error = "❌ Another exam is already scheduled for this course at the same time! Please choose a different time.";
        } else {
            try {
                // Insert exam schedule
                $sql = "INSERT INTO exam_schedules (
                    exam_name, course_id, exam_date, start_time, end_time, 
                    duration_minutes, total_questions, passing_percentage, 
                    instructions, created_by, status, created_at
                ) VALUES (
                    :exam_name, :course_id, :exam_date, :start_time, :end_time, 
                    :duration, :total_q, :passing, 
                    :instructions, :created_by, 'upcoming', NOW()
                )";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':exam_name' => $exam_name,
                    ':course_id' => $course_id,
                    ':exam_date' => $exam_date,
                    ':start_time' => $start_time,
                    ':end_time' => $end_time,
                    ':duration' => $duration_minutes,
                    ':total_q' => $total_questions,
                    ':passing' => $passing_percentage,
                    ':instructions' => $instructions,
                    ':created_by' => $committee_id
                ]);
                
                $exam_id = $pdo->lastInsertId();
                $message = "✅ Exam created successfully! Now select questions for this exam.";
                
                // Log activity
                logActivity($pdo, $committee_id, 'exam_scheduled', "Scheduled exam: $exam_name for course ID: $course_id on $exam_date");
                
                // Redirect to question selection page
                header("refresh:2;url=select_exam_questions.php?exam_id=$exam_id");
                
            } catch(PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get upcoming exams count for info
$upcoming_count = $pdo->query("SELECT COUNT(*) FROM exam_schedules WHERE exam_date >= CURDATE() AND status = 'upcoming'")->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Schedule Exam - Exam Committee</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .form-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .btn-primary { background: #667eea; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
        .btn-secondary { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-block; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .alert-info { background: #d1ecf1; color: #0c5460; }
        .help-text { font-size: 12px; color: #666; margin-top: 5px; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        .current-time { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
        .current-time strong { font-size: 18px; }
        .time-warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin-top: 10px; font-size: 12px; }
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
                <li class="nav-item"><a href="schedule_exams.php" class="nav-link active"><span class="nav-icon">📅</span><span class="nav-text">Schedule Exams</span></a></li>
                <li class="nav-item"><a href="manage_exams.php" class="nav-link"><span class="nav-icon">📋</span><span class="nav-text">Manage Exams</span></a></li>
                <li class="nav-item"><a href="generate_reports.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Reports</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Schedule New Exam</h1><p>Create a new examination schedule</p></div>
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
                <!-- Current Device Time Display -->
                <div class="current-time" id="currentTimeDisplay">
                    <strong>🕐 Your Device Time:</strong> <span id="deviceTime">Loading...</span>
                    <div class="time-warning" id="timeWarning" style="display: none;">
                        ⚠️ Warning: Your device time appears to be different from server time. Please ensure your device time is correct.
                    </div>
                </div>
                
                <div class="form-card">
                    <?php if($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    <?php if($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if($upcoming_count > 0): ?>
                    <div class="alert alert-info">
                        ℹ️ There are <?php echo $upcoming_count; ?> upcoming exam(s) scheduled. You can schedule multiple exams for the same course on different dates/times.
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="scheduleForm" onsubmit="return validateBeforeSubmit()">
                        <input type="hidden" name="device_datetime" id="device_datetime">
                        
                        <div class="form-group">
                            <label>Exam Name *</label>
                            <input type="text" name="exam_name" required placeholder="e.g., Mid-Term Examination 2024">
                        </div>
                        
                        <div class="form-group">
                            <label>Select Course *</label>
                            <select name="course_id" id="course_id" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach($courses as $course): ?>
                                <option value="<?php echo $course['id']; ?>" data-questions="<?php echo $course['approved_questions']; ?>">
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
                                <input type="date" name="exam_date" id="exam_date" required>
                                <div class="help-text">Select a future date (cannot be in the past)</div>
                            </div>
                            <div class="form-group">
                                <label>Duration (minutes) *</label>
                                <input type="number" name="duration_minutes" id="duration" value="60" min="15" max="180" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Start Time *</label>
                                <input type="time" name="start_time" id="start_time" required value="09:00">
                                <div class="help-text" id="start_time_help"></div>
                            </div>
                            <div class="form-group">
                                <label>End Time *</label>
                                <input type="time" name="end_time" id="end_time" required value="11:00">
                                <div class="help-text" id="end_time_help"></div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Total Questions *</label>
                                <input type="number" name="total_questions" id="total_questions" value="10" min="1" required>
                                <div class="help-text">Max available: <span id="maxQuestions">-</span></div>
                            </div>
                            <div class="form-group">
                                <label>Passing Percentage *</label>
                                <input type="number" name="passing_percentage" value="40" min="0" max="100" step="5" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Instructions (Optional)</label>
                            <textarea name="instructions" rows="4" placeholder="Enter exam instructions for students..."></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" name="schedule_exam" class="btn-primary" id="submitBtn">📅 Schedule Exam</button>
                            <a href="manage_exams.php" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        // Get current device time
        function updateDeviceTime() {
            const now = new Date();
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            };
            document.getElementById('deviceTime').innerHTML = now.toLocaleString('en-US', options);
            document.getElementById('device_datetime').value = now.toISOString();
            
            // Get current date and time components
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            
            window.currentDeviceDate = `${year}-${month}-${day}`;
            window.currentDeviceTime = `${hours}:${minutes}`;
            
            // Set min date to today
            const examDateInput = document.getElementById('exam_date');
            if(examDateInput) {
                examDateInput.min = window.currentDeviceDate;
            }
        }
        
        // Update time every second
        setInterval(updateDeviceTime, 1000);
        updateDeviceTime();
        
        // Course selection handler
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
        }
        
        // Date and Time Validation using device time
        const examDateInput = document.getElementById('exam_date');
        const startTimeInput = document.getElementById('start_time');
        const endTimeInput = document.getElementById('end_time');
        const durationInput = document.getElementById('duration');
        
        function validateDateTime() {
            const examDate = examDateInput.value;
            const startTime = startTimeInput.value;
            const endTime = endTimeInput.value;
            const duration = parseInt(durationInput.value);
            
            let isValid = true;
            let errorMessage = '';
            
            // Reset styles
            examDateInput.style.borderColor = '#ddd';
            startTimeInput.style.borderColor = '#ddd';
            endTimeInput.style.borderColor = '#ddd';
            durationInput.style.borderColor = '#ddd';
            
            // Check if date is selected
            if(!examDate) {
                isValid = false;
                errorMessage = 'Please select an exam date';
            }
            // Check if date is in the past
            else if(examDate < window.currentDeviceDate) {
                isValid = false;
                errorMessage = '❌ Exam date cannot be in the past! Please select a future date.';
                examDateInput.style.borderColor = '#dc3545';
            } else {
                examDateInput.style.borderColor = '#28a745';
            }
            
            // Check if date is today and time is in the past
            if(examDate === window.currentDeviceDate && startTime && startTime < window.currentDeviceTime) {
                isValid = false;
                errorMessage = '❌ Start time cannot be in the past! Please select a future time.';
                startTimeInput.style.borderColor = '#dc3545';
            } else if(startTime) {
                startTimeInput.style.borderColor = '#28a745';
            }
            
            // Check if start time is before end time
            if(startTime && endTime && startTime >= endTime) {
                isValid = false;
                errorMessage = '❌ Start time must be before end time!';
                startTimeInput.style.borderColor = '#dc3545';
                endTimeInput.style.borderColor = '#dc3545';
            } else if(startTime && endTime) {
                startTimeInput.style.borderColor = '#28a745';
                endTimeInput.style.borderColor = '#28a745';
            }
            
            // Check duration
            if(duration && (duration < 15 || duration > 180)) {
                isValid = false;
                errorMessage = '❌ Duration must be between 15 and 180 minutes!';
                durationInput.style.borderColor = '#dc3545';
            } else if(duration) {
                durationInput.style.borderColor = '#28a745';
            }
            
            // Calculate if duration matches time difference
            if(startTime && endTime && duration) {
                const startParts = startTime.split(':');
                const endParts = endTime.split(':');
                const startMinutes = parseInt(startParts[0]) * 60 + parseInt(startParts[1]);
                const endMinutes = parseInt(endParts[0]) * 60 + parseInt(endParts[1]);
                const calculatedDuration = endMinutes - startMinutes;
                
                const endTimeHelp = document.getElementById('end_time_help');
                if(Math.abs(calculatedDuration - duration) > 15) {
                    endTimeHelp.innerHTML = '⚠️ Warning: Time difference (' + calculatedDuration + ' min) differs from duration (' + duration + ' min)';
                    endTimeHelp.style.color = '#ffc107';
                } else {
                    endTimeHelp.innerHTML = '✓ Duration matches time difference';
                    endTimeHelp.style.color = '#28a745';
                }
            }
            
            if(!isValid && errorMessage) {
                alert(errorMessage);
                return false;
            }
            return true;
        }
        
        // Update help text for start time
        function updateTimeHelp() {
            const examDate = examDateInput.value;
            const startTimeHelp = document.getElementById('start_time_help');
            
            if(examDate === window.currentDeviceDate) {
                startTimeHelp.innerHTML = '⚠️ Current device time: ' + window.currentDeviceTime;
                startTimeHelp.style.color = '#ffc107';
            } else {
                startTimeHelp.innerHTML = '';
            }
        }
        
        // Validate before form submission
        function validateBeforeSubmit() {
            if(!validateDateTime()) {
                return false;
            }
            
            // Double check with user
            const examDate = examDateInput.value;
            const startTime = startTimeInput.value;
            const endTime = endTimeInput.value;
            
            const confirmMsg = `Please confirm exam details:\n\n` +
                `📚 Exam: ${document.querySelector('[name="exam_name"]').value}\n` +
                `📅 Date: ${examDate}\n` +
                `⏰ Time: ${startTime} - ${endTime}\n` +
                `⏱️ Duration: ${durationInput.value} minutes\n\n` +
                `⚠️ Once scheduled, this exam cannot be rescheduled!\n\n` +
                `Do you want to proceed?`;
            
            return confirm(confirmMsg);
        }
        
        // Add event listeners
        if(examDateInput) {
            examDateInput.addEventListener('change', function() {
                validateDateTime();
                updateTimeHelp();
            });
        }
        if(startTimeInput) startTimeInput.addEventListener('change', validateDateTime);
        if(endTimeInput) endTimeInput.addEventListener('change', validateDateTime);
        if(durationInput) durationInput.addEventListener('change', validateDateTime);
        
        // Initial validation
        setTimeout(() => {
            updateTimeHelp();
        }, 100);
    </script>
</body>
</html>