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

// Get assigned courses
$stmt = $pdo->prepare("
    SELECT c.*, COUNT(q.id) as existing_questions 
    FROM courses c
    LEFT JOIN questions q ON q.course_id = c.id
    WHERE c.teacher_id = ? AND c.status = 'active'
    GROUP BY c.id
    ORDER BY c.course_code
");
$stmt->execute([$teacher_id]);
$my_courses = $stmt->fetchAll();

// Pre-select course if passed
$selected_course = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Create uploads directory
$upload_dir = '../uploads/questions/';
if(!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ========== HANDLE MANUAL QUESTION CREATION ==========
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_question'])) {
    $course_id = (int)$_POST['course_id'];
    $question_text = trim($_POST['question_text']);
    $option_a = trim($_POST['option_a']);
    $option_b = trim($_POST['option_b']);
    $option_c = trim($_POST['option_c']);
    $option_d = trim($_POST['option_d']);
    $correct_answer = $_POST['correct_answer'];
    $marks = (int)$_POST['marks'];
    $difficulty = $_POST['difficulty'];
    
    if(empty($question_text)) {
        $error = "Question text is required!";
    } elseif(empty($option_a) || empty($option_b) || empty($option_c) || empty($option_d)) {
        $error = "All options are required!";
    } elseif(empty($correct_answer)) {
        $error = "Please select the correct answer!";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$course_id, $teacher_id]);
        
        if($stmt->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO questions (course_id, question_text, option_a, option_b, option_c, option_d, 
                                      correct_answer, marks, difficulty, created_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            
            if($stmt->execute([$course_id, $question_text, $option_a, $option_b, $option_c, $option_d, 
                              $correct_answer, $marks, $difficulty, $teacher_id])) {
                $message = "✅ Question created successfully! It will be reviewed by the exam committee.";
                logActivity($pdo, $teacher_id, 'question_created', "Created question for course ID: $course_id");
                $_POST = array();
            } else {
                $error = "Failed to create question.";
            }
        } else {
            $error = "Invalid course selected.";
        }
    }
}

// ========== HANDLE FILE UPLOAD ==========
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_questions'])) {
    $course_id = (int)$_POST['course_id'];
    $marks_per_question = (int)$_POST['marks_per_question'];
    $difficulty = $_POST['difficulty'];
    $upload_type = $_POST['upload_type'];
    
    // Verify teacher owns this course
    $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$course_id, $teacher_id]);
    
    if(!$stmt->fetch()) {
        $error = "Invalid course selected.";
    } elseif(isset($_FILES['question_file']) && $_FILES['question_file']['error'] == 0) {
        $file = $_FILES['question_file'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $questions_data = [];
        
        // Process based on file type
        if($file_ext == 'csv') {
            $questions_data = processCSVFile($file_tmp);
        }
        elseif($file_ext == 'txt') {
            $questions_data = processTXTFile($file_tmp);
        }
        elseif(in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
            $questions_data = processImageFile($file_tmp, $file_name, $upload_dir);
        }
        elseif($file_ext == 'pdf') {
            $questions_data = processPDFFile($file_tmp);
        }
        else {
            $error = "Unsupported file format. Please upload CSV, TXT, PDF, or Image files.";
        }
        
        // Insert questions into database
        if(!empty($questions_data) && is_array($questions_data)) {
            $success_count = 0;
            foreach($questions_data as $q_data) {
                $question_text = !empty($q_data['question']) ? $q_data['question'] : 'Question from file';
                $option_a = !empty($q_data['a']) ? $q_data['a'] : 'Option A';
                $option_b = !empty($q_data['b']) ? $q_data['b'] : 'Option B';
                $option_c = !empty($q_data['c']) ? $q_data['c'] : 'Option C';
                $option_d = !empty($q_data['d']) ? $q_data['d'] : 'Option D';
                $correct_answer = !empty($q_data['correct']) ? strtoupper($q_data['correct']) : 'A';
                
                $stmt = $pdo->prepare("
                    INSERT INTO questions (course_id, question_text, option_a, option_b, option_c, option_d, 
                                          correct_answer, marks, difficulty, created_by, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                
                if($stmt->execute([$course_id, $question_text, $option_a, $option_b, 
                                  $option_c, $option_d, $correct_answer, 
                                  $marks_per_question, $difficulty, $teacher_id])) {
                    $success_count++;
                }
            }
            $message = "✅ $success_count questions uploaded successfully! They will be reviewed by the exam committee.";
            logActivity($pdo, $teacher_id, 'questions_uploaded', "Uploaded $success_count questions to course ID: $course_id");
        } elseif(empty($questions_data) && empty($error)) {
            $error = "No valid questions found in the file. Please check the format.";
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// ========== CSV PROCESSING ==========
function processCSVFile($file_tmp) {
    $questions = [];
    if(($handle = fopen($file_tmp, "r")) !== false) {
        $first_row = fgetcsv($handle);
        $is_header = (strpos(strtolower($first_row[0]), 'question') !== false);
        
        if($is_header) {
            while(($data = fgetcsv($handle)) !== false) {
                if(count($data) >= 6 && !empty(trim($data[0]))) {
                    $questions[] = [
                        'question' => trim($data[0]),
                        'a' => trim($data[1]),
                        'b' => trim($data[2]),
                        'c' => trim($data[3]),
                        'd' => trim($data[4]),
                        'correct' => strtoupper(trim($data[5]))
                    ];
                }
            }
        } else {
            if(count($first_row) >= 6 && !empty(trim($first_row[0]))) {
                $questions[] = [
                    'question' => trim($first_row[0]),
                    'a' => trim($first_row[1]),
                    'b' => trim($first_row[2]),
                    'c' => trim($first_row[3]),
                    'd' => trim($first_row[4]),
                    'correct' => strtoupper(trim($first_row[5]))
                ];
            }
            while(($data = fgetcsv($handle)) !== false) {
                if(count($data) >= 6 && !empty(trim($data[0]))) {
                    $questions[] = [
                        'question' => trim($data[0]),
                        'a' => trim($data[1]),
                        'b' => trim($data[2]),
                        'c' => trim($data[3]),
                        'd' => trim($data[4]),
                        'correct' => strtoupper(trim($data[5]))
                    ];
                }
            }
        }
        fclose($handle);
    }
    return $questions;
}

// ========== TXT PROCESSING ==========
function processTXTFile($file_tmp) {
    $questions = [];
    $content = file_get_contents($file_tmp);
    $lines = explode("\n", $content);
    
    $current_question = null;
    $options = [];
    $correct_answer = null;
    
    foreach($lines as $line) {
        $line = trim($line);
        if(empty($line)) {
            if($current_question && count($options) >= 4) {
                $questions[] = [
                    'question' => $current_question,
                    'a' => isset($options[0]) ? $options[0] : 'Option A',
                    'b' => isset($options[1]) ? $options[1] : 'Option B',
                    'c' => isset($options[2]) ? $options[2] : 'Option C',
                    'd' => isset($options[3]) ? $options[3] : 'Option D',
                    'correct' => $correct_answer ? strtoupper($correct_answer) : 'A'
                ];
                $current_question = null;
                $options = [];
                $correct_answer = null;
            }
            continue;
        }
        
        if(preg_match('/^(?:Q?(\d+)[\.\)]\s*)(.+)$/i', $line, $matches)) {
            if($current_question && count($options) >= 4) {
                $questions[] = [
                    'question' => $current_question,
                    'a' => isset($options[0]) ? $options[0] : 'Option A',
                    'b' => isset($options[1]) ? $options[1] : 'Option B',
                    'c' => isset($options[2]) ? $options[2] : 'Option C',
                    'd' => isset($options[3]) ? $options[3] : 'Option D',
                    'correct' => $correct_answer ? strtoupper($correct_answer) : 'A'
                ];
            }
            $current_question = trim($matches[2]);
            $options = [];
            $correct_answer = null;
        }
        elseif(preg_match('/^([A-D])[\.\)]\s+(.+)$/i', $line, $matches)) {
            $options[] = trim($matches[2]);
        }
        elseif(preg_match('/(?:Answer|Ans|Correct)[:\s]+([A-D])/i', $line, $matches)) {
            $correct_answer = strtoupper($matches[1]);
        }
        elseif($current_question && !preg_match('/^[A-D]/i', $line)) {
            $current_question .= ' ' . $line;
        }
    }
    
    if($current_question && count($options) >= 4) {
        $questions[] = [
            'question' => $current_question,
            'a' => isset($options[0]) ? $options[0] : 'Option A',
            'b' => isset($options[1]) ? $options[1] : 'Option B',
            'c' => isset($options[2]) ? $options[2] : 'Option C',
            'd' => isset($options[3]) ? $options[3] : 'Option D',
            'correct' => $correct_answer ? strtoupper($correct_answer) : 'A'
        ];
    }
    
    return $questions;
}

// ========== IMAGE PROCESSING ==========
function processImageFile($file_tmp, $file_name, $upload_dir) {
    $questions = [];
    $new_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '', $file_name);
    $destination = $upload_dir . $new_filename;
    
    if(move_uploaded_file($file_tmp, $destination)) {
        $questions[] = [
            'question' => '<img src="../uploads/questions/' . $new_filename . '" alt="Question Image" style="max-width: 100%; border: 1px solid #ddd; border-radius: 5px; padding: 5px;"><br><br>Please analyze the image and select the correct option.',
            'a' => 'Option A',
            'b' => 'Option B',
            'c' => 'Option C',
            'd' => 'Option D',
            'correct' => 'A'
        ];
    }
    return $questions;
}

// ========== PDF PROCESSING (IMPROVED) ==========
function processPDFFile($file_tmp) {
    $questions = [];
    $content = '';
    
    // Method 1: Try pdftotext command line tool
    if(function_exists('shell_exec')) {
        $output_file = tempnam(sys_get_temp_dir(), 'pdf');
        @shell_exec('pdftotext "' . $file_tmp . '" "' . $output_file . '" 2>/dev/null');
        if(file_exists($output_file) && filesize($output_file) > 0) {
            $content = file_get_contents($output_file);
            unlink($output_file);
        }
    }
    
    // Method 2: Extract text from PDF binary
    if(empty($content)) {
        $raw = file_get_contents($file_tmp);
        preg_match_all('/BT(.*?)ET/s', $raw, $matches);
        if(!empty($matches[1])) {
            foreach($matches[1] as $match) {
                preg_match_all('/\(([^)]*)\)/', $match, $text_matches);
                if(!empty($text_matches[1])) {
                    $content .= implode(' ', $text_matches[1]) . "\n";
                }
            }
        }
    }
    
    // Method 3: Simple text extraction
    if(empty($content)) {
        $content = preg_replace('/[^\x20-\x7E\n\r]/', ' ', $raw);
    }
    
    // Clean content
    $content = preg_replace('/\s+/', ' ', $content);
    $lines = explode("\n", $content);
    
    $current_question = null;
    $options = [];
    $correct_answer = null;
    
    foreach($lines as $line) {
        $line = trim($line);
        if(empty($line)) continue;
        
        // Match numbered question
        if(preg_match('/^(\d+)[\.\)]\s+(.+)$/', $line, $matches)) {
            if($current_question && !empty($options)) {
                while(count($options) < 4) $options[] = 'Option';
                $questions[] = [
                    'question' => trim($current_question),
                    'a' => isset($options[0]) ? trim($options[0]) : 'Option A',
                    'b' => isset($options[1]) ? trim($options[1]) : 'Option B',
                    'c' => isset($options[2]) ? trim($options[2]) : 'Option C',
                    'd' => isset($options[3]) ? trim($options[3]) : 'Option D',
                    'correct' => $correct_answer ? strtoupper($correct_answer) : 'A'
                ];
            }
            $current_question = $matches[2];
            $options = [];
            $correct_answer = null;
        }
        // Match options
        elseif(preg_match('/^([A-D])[\.\)]\s*(.+)$/i', $line, $matches)) {
            $options[] = $matches[2];
        }
        // Match answer
        elseif(preg_match('/Answer\s*:\s*([A-D])/i', $line, $matches)) {
            $correct_answer = $matches[1];
        }
        // Continue question text
        elseif($current_question && strlen($line) > 5 && !preg_match('/^[A-D]/i', $line)) {
            $current_question .= ' ' . $line;
        }
    }
    
    // Save last question
    if($current_question && !empty($options)) {
        while(count($options) < 4) $options[] = 'Option';
        $questions[] = [
            'question' => trim($current_question),
            'a' => isset($options[0]) ? trim($options[0]) : 'Option A',
            'b' => isset($options[1]) ? trim($options[1]) : 'Option B',
            'c' => isset($options[2]) ? trim($options[2]) : 'Option C',
            'd' => isset($options[3]) ? trim($options[3]) : 'Option D',
            'correct' => $correct_answer ? strtoupper($correct_answer) : 'A'
        ];
    }
    
    return $questions;
}

// Helper function to clean text
function cleanText($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Questions - Teacher Portal</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .upload-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e0e0e0; flex-wrap: wrap; }
        .upload-tab { padding: 10px 20px; background: none; border: none; cursor: pointer; font-size: 16px; font-weight: 600; color: #666; transition: 0.3s; }
        .upload-tab.active { color: #28a745; border-bottom: 2px solid #28a745; margin-bottom: -2px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        .form-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .options-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .btn-primary { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .file-upload-area { border: 2px dashed #ddd; border-radius: 10px; padding: 30px; text-align: center; cursor: pointer; transition: 0.3s; margin-bottom: 20px; }
        .file-upload-area:hover { border-color: #28a745; background: #f8f9fa; }
        .file-upload-area i { font-size: 48px; color: #28a745; margin-bottom: 10px; }
        .format-options { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 15px; }
        .format-badge { background: #f0f2f5; padding: 8px 15px; border-radius: 20px; font-size: 12px; display: inline-flex; align-items: center; gap: 8px; }
        .preview-area { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 15px; max-height: 300px; overflow-y: auto; font-size: 13px; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @media (max-width: 768px) { .options-grid { grid-template-columns: 1fr; } }
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
                <li class="nav-item"><a href="create_questions.php" class="nav-link active"><span class="nav-icon">➕</span><span class="nav-text">Create Questions</span></a></li>
                <li class="nav-item"><a href="question_bank.php" class="nav-link"><span class="nav-icon">📚</span><span class="nav-text">Question Bank</span></a></li>
                <li class="nav-item"><a href="student_results.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Student Results</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Create Questions</h1><p>Add questions manually or upload via file (CSV, TXT, PDF, Images)</p></div>
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
                <?php if(count($my_courses) == 0): ?>
                <div class="form-card" style="text-align: center;">
                    <h3>⚠️ No Courses Assigned</h3>
                    <p>You haven't been assigned any courses yet. Please contact the administrator.</p>
                    <a href="dashboard.php" class="btn-primary">Back to Dashboard</a>
                </div>
                <?php else: ?>
                
                <!-- Upload Tabs -->
                <div class="upload-tabs">
                    <button class="upload-tab active" data-tab="manual"><i class="fas fa-keyboard"></i> Manual Entry</button>
                    <button class="upload-tab" data-tab="bulk"><i class="fas fa-upload"></i> Bulk Upload</button>
                </div>
                
                <!-- TAB 1: MANUAL ENTRY -->
                <div id="manual-tab" class="tab-content active">
                    <div class="form-card">
                        <?php if($message): ?>
                            <div class="alert alert-success"><?php echo $message; ?></div>
                        <?php endif; ?>
                        <?php if($error): ?>
                            <div class="alert alert-error"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="form-group">
                                <label>Select Course *</label>
                                <select name="course_id" required>
                                    <option value="">-- Select Course --</option>
                                    <?php foreach($my_courses as $course): ?>
                                    <option value="<?php echo $course['id']; ?>" <?php echo $selected_course == $course['id'] ? 'selected' : ''; ?>>
                                        <?php echo $course['course_code'] . ' - ' . htmlspecialchars($course['course_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Question Text *</label>
                                <textarea name="question_text" rows="4" required placeholder="Enter your question here..."></textarea>
                            </div>
                            
                            <div class="options-grid">
                                <div class="form-group"><label>Option A *</label><input type="text" name="option_a" required placeholder="Option A"></div>
                                <div class="form-group"><label>Option B *</label><input type="text" name="option_b" required placeholder="Option B"></div>
                                <div class="form-group"><label>Option C *</label><input type="text" name="option_c" required placeholder="Option C"></div>
                                <div class="form-group"><label>Option D *</label><input type="text" name="option_d" required placeholder="Option D"></div>
                            </div>
                            
                            <div class="options-grid">
                                <div class="form-group">
                                    <label>Correct Answer *</label>
                                    <select name="correct_answer" required>
                                        <option value="">Select Correct Answer</option>
                                        <option value="A">Option A</option>
                                        <option value="B">Option B</option>
                                        <option value="C">Option C</option>
                                        <option value="D">Option D</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Marks *</label>
                                    <input type="number" name="marks" value="1" min="1" required>
                                </div>
                                <div class="form-group">
                                    <label>Difficulty Level</label>
                                    <select name="difficulty">
                                        <option value="easy">Easy</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="hard">Hard</option>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" name="create_question" class="btn-primary">Create Question</button>
                        </form>
                    </div>
                </div>
                
                <!-- TAB 2: BULK UPLOAD -->
                <div id="bulk-tab" class="tab-content">
                    <div class="form-card">
                        <?php if($message): ?>
                            <div class="alert alert-success"><?php echo $message; ?></div>
                        <?php endif; ?>
                        <?php if($error): ?>
                            <div class="alert alert-error"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="form-group">
                                <label>Select Course *</label>
                                <select name="course_id" required>
                                    <option value="">-- Select Course --</option>
                                    <?php foreach($my_courses as $course): ?>
                                    <option value="<?php echo $course['id']; ?>"><?php echo $course['course_code'] . ' - ' . htmlspecialchars($course['course_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="options-grid">
                                <div class="form-group">
                                    <label>Marks Per Question</label>
                                    <input type="number" name="marks_per_question" value="1" min="1">
                                </div>
                                <div class="form-group">
                                    <label>Difficulty Level</label>
                                    <select name="difficulty">
                                        <option value="easy">Easy</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="hard">Hard</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>File Type *</label>
                                <select name="upload_type" id="upload_type" required>
                                    <option value="">Select File Type</option>
                                    <option value="csv">CSV File (Recommended)</option>
                                    <option value="txt">Text File (TXT)</option>
                                    <option value="image">Image (JPG, PNG, GIF)</option>
                                    <option value="pdf">PDF Document</option>
                                </select>
                            </div>
                            
                            <div class="file-upload-area" onclick="document.getElementById('question_file').click()">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Click to upload your file</p>
                                <input type="file" name="question_file" id="question_file" style="display: none;" accept=".csv,.txt,.jpg,.jpeg,.png,.gif,.pdf">
                            </div>
                            
                            <div id="file_preview" class="preview-area" style="display: none;">
                                <strong>Selected File:</strong> <span id="file_name"></span>
                            </div>
                            
                            <div class="format-options">
                                <div class="format-badge"><i class="fas fa-file-csv"></i> CSV</div>
                                <div class="format-badge"><i class="fas fa-file-alt"></i> TXT</div>
                                <div class="format-badge"><i class="fas fa-image"></i> JPG/PNG/GIF</div>
                                <div class="format-badge"><i class="fas fa-file-pdf"></i> PDF</div>
                            </div>
                            
                            <div id="format_instructions" class="preview-area">
                                <strong>📋 CSV Format (Recommended):</strong><br>
                                Create a CSV file with these columns:<br>
                                <code>Question,Option A,Option B,Option C,Option D,Correct Answer</code><br><br>
                                <strong>Example:</strong><br>
                                <code>"What is PHP?","Personal Home Page","Pre Hypertext Processor","PHP: Hypertext Preprocessor","Public Host Protocol","C"</code>
                                <hr>
                                <strong>📝 TXT Format:</strong><br>
                                <code>1. What is PHP?<br>
                                A. Personal Home Page<br>
                                B. Pre Hypertext Processor<br>
                                C. PHP: Hypertext Preprocessor<br>
                                D. Public Host Protocol<br>
                                Answer: C</code><br><br>
                                Separate each question with a blank line.
                                <hr>
                                <strong>🖼️ Image Format:</strong><br>
                                The image will be embedded in the question. After upload, edit the question to add proper options.
                                <hr>
                                <strong>📄 PDF Format:</strong><br>
                                Text will be extracted from the PDF. Format your PDF like the TXT format above.
                            </div>
                            
                            <button type="submit" name="upload_questions" class="btn-primary"><i class="fas fa-upload"></i> Upload Questions</button>
                        </form>
                    </div>
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
        
        // Tab switching
        document.querySelectorAll('.upload-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                document.querySelectorAll('.upload-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(tabId + '-tab').classList.add('active');
            });
        });
        
        // File preview and update instructions
        document.getElementById('question_file').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            if(fileName) {
                document.getElementById('file_name').textContent = fileName;
                document.getElementById('file_preview').style.display = 'block';
            }
        });
        
        // Update file accept based on selected type
        document.getElementById('upload_type').addEventListener('change', function() {
            const type = this.value;
            const fileInput = document.getElementById('question_file');
            const instructions = document.getElementById('format_instructions');
            
            if(type === 'csv') {
                fileInput.setAttribute('accept', '.csv');
                instructions.innerHTML = '<strong>📋 CSV Format (Recommended):</strong><br>Create a CSV file with:<br><code>Question,Option A,Option B,Option C,Option D,Correct Answer</code><br><br>Example:<br><code>"What is PHP?","Personal Home Page","Pre Hypertext Processor","PHP: Hypertext Preprocessor","Public Host Protocol","C"</code>';
            } else if(type === 'txt') {
                fileInput.setAttribute('accept', '.txt');
                instructions.innerHTML = '<strong>📝 TXT Format:</strong><br><code>1. What is PHP?<br>A. Personal Home Page<br>B. Pre Hypertext Processor<br>C. PHP: Hypertext Preprocessor<br>D. Public Host Protocol<br>Answer: C</code><br><br>Separate each question with a blank line.';
            } else if(type === 'image') {
                fileInput.setAttribute('accept', '.jpg,.jpeg,.png,.gif');
                instructions.innerHTML = '<strong>🖼️ Image Format:</strong><br>The image will be embedded in the question. After upload, edit the question to add proper options.';
            } else if(type === 'pdf') {
                fileInput.setAttribute('accept', '.pdf');
                instructions.innerHTML = '<strong>📄 PDF Format:</strong><br>Text will be extracted from the PDF. For best results, use CSV or TXT format instead.';
            }
        });
    </script>
</body>
</html>