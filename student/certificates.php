<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$student_id = $_SESSION['user_id'];

// Get all certificates - FIXED: Changed second 'c' alias to 'co' for courses
$stmt = $pdo->prepare("
    SELECT cert.*, 
           es.exam_name, 
           es.passing_percentage, 
           co.course_name, 
           co.course_code,
           r.percentage, 
           r.score, 
           r.total_marks
    FROM certificates cert
    JOIN exam_schedules es ON cert.exam_schedule_id = es.id
    JOIN courses co ON es.course_id = co.id
    JOIN results r ON r.exam_schedule_id = es.id AND r.student_id = cert.student_id
    WHERE cert.student_id = ?
    ORDER BY cert.issue_date DESC
");
$stmt->execute([$student_id]);
$certificates = $stmt->fetchAll();

// Get statistics
$total_certificates = count($certificates);
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Certificates - Student Portal</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 32px; font-weight: bold; color: #c9a83b; }
        .certificate-card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); transition: transform 0.3s; }
        .certificate-card:hover { transform: translateY(-3px); }
        .certificate-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; flex-wrap: wrap; gap: 10px; }
        .certificate-icon { font-size: 40px; color: #c9a83b; }
        .btn-download { background: #28a745; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-verify { background: #17a2b8; color: white; padding: 8px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .verification-code { font-family: monospace; font-size: 12px; background: #f8f9fa; padding: 5px; border-radius: 5px; display: inline-block; }
        .role-switcher { display: flex; align-items: center; gap: 10px; background: #f0f2f5; padding: 5px 15px; border-radius: 20px; }
        .badge-success { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
        .certificate-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
        .certificate-info { margin: 10px 0; }
        .certificate-info p { margin: 8px 0; font-size: 14px; }
        @media (max-width: 768px) {
            .certificate-grid { grid-template-columns: 1fr; }
            .certificate-header { flex-direction: column; text-align: center; }
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
                <li class="nav-item"><a href="certificates.php" class="nav-link active"><span class="nav-icon">🏆</span><span class="nav-text">Certificates</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">⚙️</span><span class="nav-text">Settings</span></a></li>
            </ul>
            <div class="sidebar-footer"><a href="../logout.php" class="logout-btn"><span class="nav-icon">🚪</span><span class="nav-text">Logout</span></a></div>
        </div>
        
        <div class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h1>🏆 My Certificates</h1>
                    <p>Your earned certificates for successfully completed exams</p>
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
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_certificates; ?></div>
                        <div class="stat-label">Certificates Earned</div>
                    </div>
                </div>
                
                <?php if(count($certificates) > 0): ?>
                    <div class="certificate-grid">
                        <?php foreach($certificates as $cert): ?>
                        <div class="certificate-card">
                            <div class="certificate-header">
                                <div>
                                    <i class="fas fa-certificate certificate-icon"></i>
                                    <h3 style="display: inline-block; margin-left: 10px;"><?php echo htmlspecialchars($cert['exam_name']); ?></h3>
                                </div>
                                <span class="badge-success">✓ Issued</span>
                            </div>
                            <div class="certificate-info">
                                <p><strong>📚 Course:</strong> <?php echo $cert['course_code']; ?> - <?php echo htmlspecialchars($cert['course_name']); ?></p>
                                <p><strong>📊 Score:</strong> <?php echo $cert['score']; ?>/<?php echo $cert['total_marks']; ?> (<?php echo round($cert['percentage'], 1); ?>%)</p>
                                <p><strong>📅 Issue Date:</strong> <?php echo date('F j, Y', strtotime($cert['issue_date'])); ?></p>
                                <p><strong>📄 Certificate No:</strong> <?php echo $cert['certificate_no']; ?></p>
                                <p><strong>🔑 Verification Code:</strong> <span class="verification-code"><?php echo $cert['verification_code']; ?></span></p>
                            </div>
                            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 15px;">
                                <button onclick="verifyCertificate('<?php echo $cert['verification_code']; ?>')" class="btn-verify"><i class="fas fa-search"></i> Verify</button>
                                <a href="download_certificate.php?id=<?php echo $cert['id']; ?>" class="btn-download"><i class="fas fa-download"></i> Download PDF</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="background: white; border-radius: 10px; padding: 60px 40px; text-align: center;">
                        <i class="fas fa-certificate" style="font-size: 80px; color: #ddd; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px;">No Certificates Yet</h3>
                        <p style="color: #666; margin-bottom: 20px;">You haven't earned any certificates yet. Pass an exam with the required passing percentage to receive a certificate!</p>
                        <a href="take_exam.php" class="btn-primary" style="display: inline-block; text-decoration: none;">📝 Take an Exam</a>
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
        
        function verifyCertificate(code) {
            window.open(`verify_certificate.php?code=${code}`, '_blank', 'width=600,height=500');
        }
    </script>
</body>
</html>