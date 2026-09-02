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

// Handle exam status changes
if(isset($_GET['action']) && isset($_GET['id'])) {
    $exam_id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    $new_status = '';
    if($action == 'cancel') $new_status = 'cancelled';
    elseif($action == 'start') $new_status = 'ongoing';
    elseif($action == 'complete') $new_status = 'completed';
    
    if($new_status) {
        try {
            $stmt = $pdo->prepare("UPDATE exam_schedules SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $exam_id]);
            $message = "✅ Exam status updated to: " . ucfirst($new_status);
            logActivity($pdo, $committee_id, 'exam_status_updated', "Updated exam ID: $exam_id to $new_status");
        } catch(PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Handle exam deletion
if(isset($_GET['delete']) && isset($_GET['id'])) {
    $exam_id = (int)$_GET['id'];
    
    // Check if exam has any results
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE exam_schedule_id = ?");
    $stmt->execute([$exam_id]);
    $result_count = $stmt->fetchColumn();
    
    if($result_count > 0) {
        $error = "Cannot delete exam because it has $result_count results. Archive instead.";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM exam_schedules WHERE id = ?");
            $stmt->execute([$exam_id]);
            $message = "✅ Exam deleted successfully!";
            logActivity($pdo, $committee_id, 'exam_deleted', "Deleted exam ID: $exam_id");
        } catch(PDOException $e) {
            $error = "Error deleting exam: " . $e->getMessage();
        }
    }
}

// Handle exam edit
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_exam'])) {
    $exam_id = (int)$_POST['exam_id'];
    $exam_name = trim($_POST['exam_name']);
    $exam_date = $_POST['exam_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $duration_minutes = (int)$_POST['duration_minutes'];
    $total_questions = (int)$_POST['total_questions'];
    $passing_percentage = (float)$_POST['passing_percentage'];
    $instructions = trim($_POST['instructions']);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE exam_schedules 
            SET exam_name = ?, exam_date = ?, start_time = ?, end_time = ?, 
                duration_minutes = ?, total_questions = ?, passing_percentage = ?, 
                instructions = ?
            WHERE id = ?
        ");
        $stmt->execute([$exam_name, $exam_date, $start_time, $end_time, 
                       $duration_minutes, $total_questions, $passing_percentage, 
                       $instructions, $exam_id]);
        $message = "✅ Exam updated successfully!";
        logActivity($pdo, $committee_id, 'exam_updated', "Updated exam ID: $exam_id");
    } catch(PDOException $e) {
        $error = "Error updating exam: " . $e->getMessage();
    }
}

// Get all exams with details - INCLUDING completed and expired
$stmt = $pdo->prepare("
    SELECT es.*, c.course_name, c.course_code,
           COUNT(DISTINCT ee.id) as registered_students,
           COUNT(DISTINCT r.id) as completed_exams,
           ROUND(AVG(r.percentage), 2) as avg_percentage,
           (SELECT COUNT(*) FROM exam_questions WHERE exam_schedule_id = es.id) as actual_questions,
           CASE 
               WHEN es.status = 'completed' THEN 'completed'
               WHEN es.status = 'cancelled' THEN 'cancelled'
               WHEN es.exam_date < CURDATE() THEN 'expired'
               WHEN es.exam_date = CURDATE() AND CURTIME() > es.end_time THEN 'expired'
               WHEN es.status = 'ongoing' THEN 'ongoing'
               WHEN es.exam_date = CURDATE() AND CURTIME() BETWEEN es.start_time AND es.end_time THEN 'ongoing'
               ELSE 'upcoming'
           END as current_status
    FROM exam_schedules es
    JOIN courses c ON es.course_id = c.id
    LEFT JOIN exam_enrollments ee ON ee.exam_schedule_id = es.id
    LEFT JOIN results r ON r.exam_schedule_id = es.id
    GROUP BY es.id
    ORDER BY es.exam_date DESC, es.start_time DESC
");
$stmt->execute();
$exams = $stmt->fetchAll();

// Get exam for editing
$edit_exam = null;
if(isset($_GET['edit']) && isset($_GET['id'])) {
    $edit_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM exam_schedules WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_exam = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Exams - Exam Committee</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 15px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .card { background: white; border-radius: 10px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .card-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .card-body { padding: 20px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e0e0e0; }
        th { background: #f8f9fa; font-weight: 600; }
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-upcoming { background: #17a2b8; color: white; }
        .badge-ongoing { background: #28a745; color: white; }
        .badge-completed { background: #6c757d; color: white; }
        .badge-cancelled { background: #dc3545; color: white; }
        .badge-expired { background: #dc3545; color: white; }
        .btn-small { padding: 4px 10px; font-size: 11px; border-radius: 4px; text-decoration: none; display: inline-block; margin: 2px; border: none; cursor: pointer; }
        .btn-success { background: #28a745; color: white; }
        .btn-primary { background: #667eea; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; width: 600px; max-width: 90%; max-height: 80vh; overflow-y: auto; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .alert { padding: 12px; border-radius: 5px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .filter-bar { background: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .filter-bar select, .filter-bar input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; }
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
                <li class="nav-item"><a href="approve_questions.php" class="nav-link"><span class="nav-icon">✅</span><span class="nav-text">Approve Questions</span></a></li>
                <li class="nav-item"><a href="schedule_exams.php" class="nav-link"><span class="nav-icon">📅</span><span class="nav-text">Schedule Exams</span></a></li>
                <li class="nav-item"><a href="manage_exams.php" class="nav-link active"><span class="nav-icon">📋</span><span class="nav-text">Manage Exams</span></a></li>
                <li class="nav-item"><a href="generate_reports.php" class="nav-link"><span class="nav-icon">📊</span><span class="nav-text">Reports</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title"><h1>Manage Examinations</h1><p>View and manage all scheduled exams</p></div>
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
                <?php if($message): ?>
                    <div class="alert alert-success"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <!-- Statistics -->
                <?php
                $total_exams = count($exams);
                $upcoming_count = 0;
                $ongoing_count = 0;
                $completed_count = 0;
                $expired_count = 0;
                foreach($exams as $e) {
                    if($e['current_status'] == 'upcoming') $upcoming_count++;
                    elseif($e['current_status'] == 'ongoing') $ongoing_count++;
                    elseif($e['current_status'] == 'completed') $completed_count++;
                    elseif($e['current_status'] == 'expired') $expired_count++;
                }
                ?>
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number"><?php echo $total_exams; ?></div><div class="stat-label">Total Exams</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $upcoming_count; ?></div><div class="stat-label">Upcoming</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $ongoing_count; ?></div><div class="stat-label">Ongoing</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $completed_count; ?></div><div class="stat-label">Completed</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $expired_count; ?></div><div class="stat-label">Expired</div></div>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <label>Filter by Status:</label>
                    <select id="statusFilter" onchange="filterTable()">
                        <option value="all">All Exams</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                        <option value="expired">Expired</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <input type="text" id="searchInput" placeholder="Search exam name..." onkeyup="filterTable()">
                    <a href="schedule_exams.php" class="btn-primary" style="text-decoration: none; margin-left: auto;">+ Schedule New Exam</a>
                </div>
                
                <!-- Exams Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>📋 All Examinations</h3>
                    </div>
                    <div class="card-body">
                        <div style="overflow-x: auto;">
                            <table id="examsTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Exam Name</th>
                                        <th>Course</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Questions</th>
                                        <th>Status</th>
                                        <th>Registered</th>
                                        <th>Completed</th>
                                        <th>Avg Score</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($exams as $exam): ?>
                                    <tr data-status="<?php echo $exam['current_status']; ?>">
                                        <td><?php echo $exam['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong></td>
                                        <td><?php echo $exam['course_code']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($exam['exam_date'])); ?></td>
                                        <td><?php echo date('g:i A', strtotime($exam['start_time'])); ?></td>
                                        <td><?php echo $exam['actual_questions'] ?: $exam['total_questions']; ?></td>
                                        <td><span class="badge badge-<?php echo $exam['current_status']; ?>"><?php echo ucfirst($exam['current_status']); ?></span></td>
                                        <td><?php echo $exam['registered_students']; ?></td>
                                        <td><?php echo $exam['completed_exams']; ?></td>
                                        <td><?php echo $exam['avg_percentage'] ? $exam['avg_percentage'] . '%' : '-'; ?></td>
                                        <td class="action-buttons" style="white-space: nowrap;">
                                            <button onclick="viewExam(<?php echo $exam['id']; ?>)" class="btn-small btn-info" title="View Details">👁️</button>
                                            <?php if($exam['current_status'] == 'upcoming'): ?>
                                                <button onclick="editExam(<?php echo $exam['id']; ?>)" class="btn-small btn-primary" title="Edit Exam">✏️</button>
                                                <button onclick="changeStatus(<?php echo $exam['id']; ?>, 'start')" class="btn-small btn-success" title="Start Exam">▶️ Start</button>
                                                <button onclick="changeStatus(<?php echo $exam['id']; ?>, 'cancel')" class="btn-small btn-danger" title="Cancel Exam">❌ Cancel</button>
                                            <?php elseif($exam['current_status'] == 'ongoing'): ?>
                                                <button onclick="changeStatus(<?php echo $exam['id']; ?>, 'complete')" class="btn-small btn-success" title="Complete Exam">✓ Complete</button>
                                            <?php endif; ?>
                                            <?php if($exam['completed_exams'] == 0 && $exam['current_status'] == 'upcoming'): ?>
                                                <button onclick="deleteExam(<?php echo $exam['id']; ?>)" class="btn-small btn-danger" title="Delete Exam">🗑️ Delete</button>
                                            <?php endif; ?>
                                            <a href="select_exam_questions.php?exam_id=<?php echo $exam['id']; ?>" class="btn-small btn-info" title="Manage Questions">📝 Questions</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if(count($exams) == 0): ?>
                            <div style="text-align: center; padding: 40px;">
                                <p>No exams scheduled yet.</p>
                                <a href="schedule_exams.php" class="btn-primary">Schedule Your First Exam →</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Exam Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <h3>📋 Exam Details</h3>
            <div id="viewDetails"></div>
            <button onclick="closeViewModal()" class="btn-secondary">Close</button>
        </div>
    </div>
    
    <!-- Edit Exam Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h3>✏️ Edit Exam</h3>
            <form method="POST">
                <input type="hidden" name="exam_id" id="edit_exam_id">
                <div class="form-group">
                    <label>Exam Name</label>
                    <input type="text" name="exam_name" id="edit_exam_name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Exam Date</label>
                        <input type="date" name="exam_date" id="edit_exam_date" required>
                    </div>
                    <div class="form-group">
                        <label>Duration (minutes)</label>
                        <input type="number" name="duration_minutes" id="edit_duration" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Start Time</label>
                        <input type="time" name="start_time" id="edit_start_time" required>
                    </div>
                    <div class="form-group">
                        <label>End Time</label>
                        <input type="time" name="end_time" id="edit_end_time" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Total Questions</label>
                        <input type="number" name="total_questions" id="edit_total_questions" required>
                    </div>
                    <div class="form-group">
                        <label>Passing Percentage</label>
                        <input type="number" name="passing_percentage" id="edit_passing" step="5" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Instructions</label>
                    <textarea name="instructions" id="edit_instructions" rows="3"></textarea>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="update_exam" class="btn-primary">Save Changes</button>
                    <button type="button" onclick="closeEditModal()" class="btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function toggleSidebar() { document.querySelector('.sidebar').classList.toggle('open'); document.querySelector('.sidebar-overlay').classList.toggle('active'); }
        
        function changeStatus(examId, action) {
            let message = '';
            if(action == 'start') message = 'Start this exam?';
            else if(action == 'cancel') message = 'Cancel this exam?';
            else if(action == 'complete') message = 'Mark this exam as completed?';
            
            if(confirm(message)) {
                window.location.href = `?action=${action}&id=${examId}`;
            }
        }
        
        function deleteExam(examId) {
            if(confirm('⚠️ WARNING: This will permanently delete the exam. Continue?')) {
                window.location.href = `?delete=1&id=${examId}`;
            }
        }
        
        function viewExam(examId) {
            fetch(`get_exam_details.php?id=${examId}`)
                .then(response => response.json())
                .then(data => {
                    let html = `<table style="width:100%">`;
                    html += `<tr><th>Exam Name:</th><td>${data.exam_name}</td></tr>`;
                    html += `<tr><th>Course:</th><td>${data.course_code} - ${data.course_name}</td></tr>`;
                    html += `<tr><th>Date:</th><td>${data.exam_date}</td></tr>`;
                    html += `<tr><th>Time:</th><td>${data.start_time} - ${data.end_time}</td></tr>`;
                    html += `<tr><th>Duration:</th><td>${data.duration_minutes} minutes</td></tr>`;
                    html += `<tr><th>Total Questions:</th><td>${data.total_questions}</td></tr>`;
                    html += `<tr><th>Passing Percentage:</th><td>${data.passing_percentage}%</td></tr>`;
                    html += `<tr><th>Status:</th><td><span class="badge badge-${data.status}">${data.status}</span></td></tr>`;
                    html += `<tr><th>Registered Students:</th><td>${data.registered_students}</td></tr>`;
                    html += `<tr><th>Completed Exams:</th><td>${data.completed_exams}</td></tr>`;
                    html += `<tr><th>Instructions:</th><td>${data.instructions || 'No instructions'}</td></tr>`;
                    html += `</table>`;
                    document.getElementById('viewDetails').innerHTML = html;
                    document.getElementById('viewModal').style.display = 'flex';
                });
        }
        
        function editExam(examId) {
            fetch(`get_exam_details.php?id=${examId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_exam_id').value = data.id;
                    document.getElementById('edit_exam_name').value = data.exam_name;
                    document.getElementById('edit_exam_date').value = data.exam_date;
                    document.getElementById('edit_start_time').value = data.start_time;
                    document.getElementById('edit_end_time').value = data.end_time;
                    document.getElementById('edit_duration').value = data.duration_minutes;
                    document.getElementById('edit_total_questions').value = data.total_questions;
                    document.getElementById('edit_passing').value = data.passing_percentage;
                    document.getElementById('edit_instructions').value = data.instructions || '';
                    document.getElementById('editModal').style.display = 'flex';
                });
        }
        
        function closeViewModal() { document.getElementById('viewModal').style.display = 'none'; }
        function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
        
        function filterTable() {
            const status = document.getElementById('statusFilter').value;
            const search = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('#examsTable tbody tr');
            
            rows.forEach(row => {
                let show = true;
                const rowStatus = row.getAttribute('data-status');
                const examName = row.cells[1]?.innerText.toLowerCase() || '';
                
                if(status !== 'all' && rowStatus !== status) show = false;
                if(search && !examName.includes(search)) show = false;
                
                row.style.display = show ? '' : 'none';
            });
        }
    </script>
</body>
</html>