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

// Handle enrollment
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['enroll_student'])) {
    $student_id = (int)$_POST['student_id'];
    $course_id = (int)$_POST['course_id'];
    
    // Check if already enrolled
    $stmt = $pdo->prepare("SELECT * FROM student_courses WHERE student_id = ? AND course_id = ?");
    $stmt->execute([$student_id, $course_id]);
    
    if($stmt->fetch()) {
        $error = "Student is already enrolled in this course!";
    } else {
        $stmt = $pdo->prepare("INSERT INTO student_courses (student_id, course_id, enrolled_by, status) VALUES (?, ?, ?, 'active')");
        if($stmt->execute([$student_id, $course_id, $admin_id])) {
            $message = "Student enrolled successfully!";
            logActivity($pdo, $admin_id, 'student_enrolled', "Enrolled student $student_id in course $course_id");
        } else {
            $error = "Failed to enroll student.";
        }
    }
}

// Handle dropping course
if(isset($_GET['drop']) && isset($_GET['student_id']) && isset($_GET['course_id'])) {
    $student_id = (int)$_GET['student_id'];
    $course_id = (int)$_GET['course_id'];
    
    $stmt = $pdo->prepare("DELETE FROM student_courses WHERE student_id = ? AND course_id = ?");
    $stmt->execute([$student_id, $course_id]);
    $message = "Student removed from course successfully!";
    logActivity($pdo, $admin_id, 'student_dropped', "Dropped student $student_id from course $course_id");
}

// Get all students
$stmt = $pdo->prepare("SELECT id, username, full_name, email, student_id FROM users WHERE role = 'student' AND status = 'active' ORDER BY full_name");
$stmt->execute();
$students = $stmt->fetchAll();

// Get all active courses
$stmt = $pdo->prepare("SELECT id, course_code, course_name FROM courses WHERE status = 'active' ORDER BY course_code");
$stmt->execute();
$courses = $stmt->fetchAll();

// Get enrollments
$stmt = $pdo->prepare("
    SELECT sc.*, u.full_name as student_name, u.username, u.student_id as student_id_number,
           c.course_code, c.course_name
    FROM student_courses sc
    JOIN users u ON sc.student_id = u.id
    JOIN courses c ON sc.course_id = c.id
    WHERE sc.status = 'active'
    ORDER BY c.course_code, u.full_name
");
$stmt->execute();
$enrollments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Enroll Students - Admin</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn-primary { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-danger { background: #dc3545; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-block; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; width: 500px; max-width: 90%; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
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
                <li class="nav-item"><a href="manage_courses.php" class="nav-link"><span class="nav-icon">📚</span><span class="nav-text">Manage Courses</span></a></li>
                <li class="nav-item"><a href="manage_enrollments.php" class="nav-link active"><span class="nav-icon">📝</span><span class="nav-text">Enroll Students</span></a></li>
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
                <div class="page-title"><h1>Student Enrollment</h1><p>Enroll students in courses</p></div>
            </div>
            
            <div class="container">
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number"><?php echo count($students); ?></div><div class="stat-label">Total Students</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo count($courses); ?></div><div class="stat-label">Active Courses</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo count($enrollments); ?></div><div class="stat-label">Total Enrollments</div></div>
                </div>
                
                <button onclick="showEnrollModal()" class="btn-primary" style="margin-bottom: 20px;">+ Enroll Student in Course</button>
                
                <div class="card">
                    <h3>📋 Current Enrollments</h3>
                    <?php if(count($enrollments) > 0): ?>
                        <table>
                            <thead>
                                <tr><th>Student ID</th><th>Student Name</th><th>Course Code</th><th>Course Name</th><th>Enrolled Date</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($enrollments as $enrollment): ?>
                                <tr>
                                    <td><?php echo $enrollment['student_id_number'] ?: 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($enrollment['student_name']); ?></td>
                                    <td><strong><?php echo $enrollment['course_code']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($enrollment['course_name']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($enrollment['enrollment_date'])); ?></td>
                                    <td>
                                        <a href="?drop=1&student_id=<?php echo $enrollment['student_id']; ?>&course_id=<?php echo $enrollment['course_id']; ?>" 
                                           class="btn-danger" onclick="return confirm('Remove this student from the course?')">Remove</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No students enrolled in any courses yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enroll Modal -->
    <div id="enrollModal" class="modal">
        <div class="modal-content">
            <h3>Enroll Student in Course</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Select Student *</label>
                    <select name="student_id" required>
                        <option value="">-- Select Student --</option>
                        <?php foreach($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>">
                            <?php echo htmlspecialchars($student['full_name']); ?> (<?php echo $student['username']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Select Course *</label>
                    <select name="course_id" required>
                        <option value="">-- Select Course --</option>
                        <?php foreach($courses as $course): ?>
                        <option value="<?php echo $course['id']; ?>">
                            <?php echo $course['course_code']; ?> - <?php echo htmlspecialchars($course['course_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" name="enroll_student" class="btn-primary">Enroll Student</button>
                <button type="button" onclick="closeModal()" style="margin-left: 10px;">Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('active');
        }
        
        function showEnrollModal() {
            document.getElementById('enrollModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('enrollModal').style.display = 'none';
        }
    </script>
</body>
</html>