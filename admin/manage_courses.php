<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

// Check if admin
if(!isset($_SESSION['user_id']) || $_SESSION['primary_role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$admin_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle course creation/assignment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_course'])) {
    $course_code = trim($_POST['course_code']);
    $course_name = trim($_POST['course_name']);
    $department_id = (int)$_POST['department_id'];
    $credits = (int)$_POST['credits'];
    $semester = (int)$_POST['semester'];
    $teacher_id = (int)$_POST['teacher_id'];
    
    try {
        // Check if course exists
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = ?");
        $stmt->execute([$course_code]);
        
        if($stmt->fetch()) {
            $error = "Course code already exists!";
        } else {
            // Insert course
            $stmt = $pdo->prepare("
                INSERT INTO courses (course_code, course_name, department_id, credits, semester, teacher_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$course_code, $course_name, $department_id, $credits, $semester, $teacher_id]);
            
            $message = "Course assigned to teacher successfully!";
            logActivity($pdo, $admin_id, 'course_assigned', "Assigned course $course_code to teacher ID: $teacher_id");
        }
    } catch(PDOException $e) {
        $error = "Failed to assign course: " . $e->getMessage();
    }
}

// Handle CSV import
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_csv'])) {
    if(isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        $file_extension = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        
        if($file_extension != 'csv') {
            $error = "Please upload a CSV file!";
        } else {
            $csv_data = array_map('str_getcsv', file($file));
            $headers = array_shift($csv_data);
            
            // Expected headers: course_code, course_name, department_name, credits, semester, teacher_username
            $expected_headers = ['course_code', 'course_name', 'department_name', 'credits', 'semester', 'teacher_username'];
            
            // Normalize headers to lowercase and remove whitespace
            $normalized_headers = array_map('strtolower', array_map('trim', $headers));
            
            // Check if headers match expected format
            $headers_match = true;
            foreach($expected_headers as $expected) {
                if(!in_array($expected, $normalized_headers)) {
                    $headers_match = false;
                    break;
                }
            }
            
            if(!$headers_match) {
                $error = "CSV must have columns: course_code, course_name, department_name, credits, semester, teacher_username";
            } else {
                $success_count = 0;
                $error_count = 0;
                $errors = [];
                
                try {
                    $pdo->beginTransaction();
                    
                    foreach($csv_data as $row_index => $row) {
                        // Map row data to headers
                        $row_data = [];
                        foreach($normalized_headers as $idx => $header) {
                            $row_data[$header] = isset($row[$idx]) ? trim($row[$idx]) : '';
                        }
                        
                        $course_code = $row_data['course_code'];
                        $course_name = $row_data['course_name'];
                        $department_name = trim($row_data['department_name']);
                        $credits = (int)$row_data['credits'];
                        $semester = (int)$row_data['semester'];
                        $teacher_username = trim($row_data['teacher_username']);
                        
                        // Validate required fields
                        if(empty($course_code) || empty($course_name) || empty($department_name) || empty($teacher_username)) {
                            $error_count++;
                            $errors[] = "Row " . ($row_index + 2) . ": Missing required fields";
                            continue;
                        }
                        
                        // Check if course already exists
                        $stmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = ?");
                        $stmt->execute([$course_code]);
                        if($stmt->fetch()) {
                            $error_count++;
                            $errors[] = "Row " . ($row_index + 2) . ": Course code '$course_code' already exists";
                            continue;
                        }
                        
                        // Find department by name
                        $stmt = $pdo->prepare("SELECT id FROM departments WHERE dept_name = ? AND status = 'active'");
                        $stmt->execute([$department_name]);
                        $department = $stmt->fetch();
                        
                        if(!$department) {
                            $error_count++;
                            $errors[] = "Row " . ($row_index + 2) . ": Department '$department_name' not found";
                            continue;
                        }
                        
                        // Find teacher by username
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND role = 'teacher' AND status = 'active'");
                        $stmt->execute([$teacher_username]);
                        $teacher = $stmt->fetch();
                        
                        if(!$teacher) {
                            $error_count++;
                            $errors[] = "Row " . ($row_index + 2) . ": Teacher '$teacher_username' not found or not active";
                            continue;
                        }
                        
                        // Insert course
                        $stmt = $pdo->prepare("
                            INSERT INTO courses (course_code, course_name, department_id, credits, semester, teacher_id, status) 
                            VALUES (?, ?, ?, ?, ?, ?, 'active')
                        ");
                        $stmt->execute([$course_code, $course_name, $department_id, $credits, $semester, $teacher['id']]);
                        $success_count++;
                    }
                    
                    $pdo->commit();
                    
                    if($success_count > 0) {
                        $message = "Successfully imported $success_count course(s)!";
                        logActivity($pdo, $admin_id, 'csv_import', "Imported $success_count courses via CSV");
                    }
                    
                    if($error_count > 0) {
                        $error = "Imported $success_count courses. " . $error_count . " errors occurred:<br>" . implode("<br>", $errors);
                    }
                    
                } catch(PDOException $e) {
                    $pdo->rollBack();
                    $error = "Failed to import courses: " . $e->getMessage();
                }
            }
        }
    } else {
        $error = "Please select a CSV file to upload.";
    }
}

// Handle course removal
if(isset($_GET['remove']) && isset($_GET['course_id'])) {
    $course_id = (int)$_GET['course_id'];
    
    $stmt = $pdo->prepare("UPDATE courses SET status = 'inactive', teacher_id = NULL WHERE id = ?");
    $stmt->execute([$course_id]);
    $message = "Course removed successfully!";
    logActivity($pdo, $admin_id, 'course_removed', "Removed course ID: $course_id");
}

// Get all teachers
$stmt = $pdo->prepare("SELECT id, username, full_name, department FROM users WHERE role = 'teacher' AND status = 'active'");
$stmt->execute();
$teachers = $stmt->fetchAll();

// Get all departments
$stmt = $pdo->prepare("SELECT * FROM departments WHERE status = 'active'");
$stmt->execute();
$departments = $stmt->fetchAll();

// Get all courses
$stmt = $pdo->prepare("
    SELECT c.*, d.dept_name, u.full_name as teacher_name, u.username as teacher_username
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.id
    LEFT JOIN users u ON c.teacher_id = u.id
    ORDER BY c.course_code
");
$stmt->execute();
$courses = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Courses - Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn-primary { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-success { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-danger { background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block; }
        .btn-info { background: #17a2b8; color: white; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; width: 600px; max-width: 90%; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        .btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
        .csv-format { background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0; font-size: 14px; }
        .csv-format code { background: #e9ecef; padding: 2px 5px; border-radius: 3px; }
    </style>
</head>
<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <div class="dashboard-layout">
        <div class="sidebar">
            <div class="sidebar-header"><h2>📚 Exam System</h2><p>Admin Portal</p></div>
            <div class="user-profile"><div class="user-avatar">👨‍💼</div><div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div><div class="user-role">Administrator</div></div>
            <ul class="sidebar-nav">
                <li class="nav-item"><a href="dashboard.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><span class="nav-icon">👥</span><span class="nav-text">Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_roles.php" class="nav-link"><span class="nav-icon">🎭</span><span class="nav-text">Manage Roles</span></a></li>
                <li class="nav-item"><a href="manage_courses.php" class="nav-link active"><span class="nav-icon">📚</span><span class="nav-text">Manage Courses</span></a></li>
                <li class="nav-item"><a href="manage_enrollments.php" class="nav-link"><span class="nav-icon">📝</span><span class="nav-text">Enroll Students</span></a></li>
                <li class="nav-item">
                    <a href="system_settings.php" class="nav-link">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-text">Settings</span>
                    </a>
                </li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Manage Courses</h1><p>Create and assign courses to teachers</p></div>
            </div>
            
            <div class="container">
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number"><?php echo count($courses); ?></div><div class="stat-label">Total Courses</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo count($teachers); ?></div><div class="stat-label">Active Teachers</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo count($departments); ?></div><div class="stat-label">Departments</div></div>
                </div>
                
                <div class="btn-group">
                    <button onclick="showAssignModal()" class="btn-primary">+ Assign New Course</button>
                    <button onclick="showImportModal()" class="btn-success">📥 Import CSV</button>
                </div>
                
                <div class="card">
                    <h3>📚 Course List</h3>
                    <table>
                        <thead>
                            <tr><th>Course Code</th><th>Course Name</th><th>Department</th><th>Teacher</th><th>Credits</th><th>Semester</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($courses as $course): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                <td><?php echo $course['dept_name']; ?></td>
                                <td><?php echo $course['teacher_name'] ? htmlspecialchars($course['teacher_name']) : '<span style="color:red;">Not Assigned</span>'; ?></td>
                                <td><?php echo $course['credits']; ?></td>
                                <td><?php echo $course['semester']; ?></td>
                                <td><span class="badge badge-<?php echo $course['status']; ?>"><?php echo ucfirst($course['status']); ?></span></td>
                                <td>
                                    <?php if($course['teacher_id']): ?>
                                        <a href="?remove=1&course_id=<?php echo $course['id']; ?>" class="btn-danger" onclick="return confirm('Remove this course?')">Remove</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assign Course Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <h3>Assign New Course</h3>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Course Code *</label>
                        <input type="text" name="course_code" required placeholder="e.g., CS101">
                    </div>
                    <div class="form-group">
                        <label>Course Name *</label>
                        <input type="text" name="course_name" required placeholder="e.g., Programming">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Department *</label>
                        <select name="department_id" required>
                            <option value="">Select Department</option>
                            <?php foreach($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['dept_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Assign to Teacher *</label>
                        <select name="teacher_id" required>
                            <option value="">Select Teacher</option>
                            <?php foreach($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Credits</label>
                        <input type="number" name="credits" value="3" min="1" max="6">
                    </div>
                    <div class="form-group">
                        <label>Semester</label>
                        <select name="semester">
                            <option value="1">Semester 1</option>
                            <option value="2">Semester 2</option>
                            <option value="3">Semester 3</option>
                            <option value="4">Semester 4</option>
                            <option value="5">Semester 5</option>
                            <option value="6">Semester 6</option>
                            <option value="7">Semester 7</option>
                            <option value="8">Semester 8</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" name="assign_course" class="btn-primary">Assign Course</button>
                <button type="button" onclick="closeModal()" style="margin-left: 10px;">Cancel</button>
            </form>
        </div>
    </div>
    
    <!-- Import CSV Modal -->
    <div id="importModal" class="modal">
        <div class="modal-content">
            <h3>📥 Import Courses from CSV</h3>
            <p style="color: #666; margin-bottom: 15px;">Upload a CSV file with course data to bulk assign courses.</p>
            
            <div class="csv-format">
                <strong>CSV Format Required:</strong><br>
                <code>course_code, course_name, department_name, credits, semester, teacher_username</code><br>
                <strong>Example:</strong><br>
                <code>CS101, Introduction to Computer Science, Computer Science, 3, 1, john_doe</code><br>
                <code>MATH201, Calculus II, Mathematics, 4, 2, jane_smith</code>
                <br><br>
                <small style="color: #666;">Note: Department names must match existing departments. Teacher usernames must exist and be active.</small>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Select CSV File *</label>
                    <input type="file" name="csv_file" accept=".csv" required>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="import_csv" class="btn-success">Import CSV</button>
                    <button type="button" onclick="closeImportModal()" style="padding: 10px 20px;">Cancel</button>
                </div>
            </form>
            
            <hr style="margin: 20px 0;">
            
            <div style="margin-top: 10px;">
                <a href="#" onclick="downloadSampleCSV(); return false;" style="color: #667eea; text-decoration: none;">
                    📄 Download Sample CSV Template
                </a>
            </div>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function showAssignModal() {
            document.getElementById('assignModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('assignModal').style.display = 'none';
        }
        
        function showImportModal() {
            document.getElementById('importModal').style.display = 'flex';
        }
        
        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
        }
        
        function downloadSampleCSV() {
            const headers = 'course_code,course_name,department_name,credits,semester,teacher_username\n';
            const sample1 = 'CS101,Introduction to Computer Science,Computer Science,3,1,john_doe\n';
            const sample2 = 'MATH201,Calculus II,Mathematics,4,2,jane_smith\n';
            const sample3 = 'ENG101,English Composition,English,3,1,robert_johnson\n';
            const csvContent = headers + sample1 + sample2 + sample3;
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.href = url;
            link.download = 'sample_courses.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>
