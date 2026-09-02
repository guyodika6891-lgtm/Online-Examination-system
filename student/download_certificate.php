<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    header("Location: ../index.php");
    exit();
}

$cert_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$student_id = $_SESSION['user_id'];

// Get certificate details
$stmt = $pdo->prepare("
    SELECT c.*, es.exam_name, es.passing_percentage, 
           co.course_name, co.course_code,
           r.score, r.total_marks, r.percentage
    FROM certificates c
    JOIN exam_schedules es ON c.exam_schedule_id = es.id
    JOIN courses co ON es.course_id = co.id
    JOIN results r ON r.exam_schedule_id = es.id AND r.student_id = c.student_id
    WHERE c.id = ? AND c.student_id = ?
");
$stmt->execute([$cert_id, $student_id]);
$cert = $stmt->fetch();

if(!$cert) {
    header("Location: certificates.php?error=not_found");
    exit();
}

// Get student details
$stmt = $pdo->prepare("SELECT full_name, student_id FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

// Display certificate in browser for printing
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Certificate - <?php echo htmlspecialchars($cert['certificate_no']); ?></title>
    <style>
        @media print {
            .print-btn { display: none; }
            @page { margin: 0; }
            body { margin: 0; padding: 0; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Times New Roman', Times, serif;
            background: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .certificate {
            width: 900px;
            background: white;
            border: 20px solid #c9a83b;
            padding: 40px;
            position: relative;
            margin: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .border-decoration {
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            bottom: 20px;
            border: 2px solid #c9a83b;
            pointer-events: none;
        }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { font-size: 42px; color: #c9a83b; letter-spacing: 5px; }
        .header h2 { font-size: 22px; color: #333; margin: 10px 0; }
        .student-name { font-size: 42px; font-weight: bold; color: #2c3e50; margin: 30px 0; text-decoration: underline; text-decoration-color: #c9a83b; text-align: center; }
        .course-name { font-size: 22px; color: #34495e; text-align: center; margin: 20px 0; }
        .score { font-size: 32px; color: #27ae60; font-weight: bold; text-align: center; margin: 20px 0; }
        .footer { margin-top: 40px; display: flex; justify-content: space-between; }
        .signature { text-align: center; }
        .signature-line { width: 200px; border-top: 1px solid #333; margin-top: 40px; padding-top: 5px; }
        .certificate-no { font-size: 10px; color: #999; text-align: center; margin-top: 30px; }
        .verification-code { font-family: monospace; font-size: 12px; }
        .seal { position: absolute; bottom: 50px; right: 80px; width: 100px; height: 100px; border-radius: 50%; border: 3px solid #c9a83b; text-align: center; line-height: 94px; font-size: 12px; color: #c9a83b; }
        .print-btn { position: fixed; bottom: 20px; right: 20px; background: #28a745; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; z-index: 1000; }
        .print-btn:hover { background: #218838; }
        .back-btn { position: fixed; bottom: 20px; left: 20px; background: #6c757d; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; text-decoration: none; z-index: 1000; }
        @media (max-width: 768px) {
            .certificate { width: 95%; padding: 20px; margin: 10px; }
            .student-name { font-size: 28px; }
            .header h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <a href="certificates.php" class="back-btn">← Back</a>
    <button class="print-btn" onclick="window.print();">🖨️ Print / Save as PDF</button>
    
    <div class="certificate">
        <div class="border-decoration"></div>
        <div class="header">
            <h1>CERTIFICATE OF ACHIEVEMENT</h1>
            <h2>Online Examination System</h2>
            <p>This certificate is proudly presented to</p>
        </div>
        <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
        <div class="course-name">
            for successfully completing<br>
            <strong><?php echo htmlspecialchars($cert['course_name']); ?> (<?php echo $cert['course_code']; ?>)</strong><br>
            examination
        </div>
        <div class="score">
            Score: <?php echo $cert['score']; ?> / <?php echo $cert['total_marks']; ?> (<?php echo round($cert['percentage'], 1); ?>%)
        </div>
        <p style="text-align: center;">with a passing grade of <?php echo $cert['passing_percentage']; ?>%</p>
        
        <div class="footer">
            <div class="signature">
                <div class="signature-line"></div>
                <p>Examination Officer</p>
            </div>
            <div class="signature">
                <div class="signature-line"></div>
                <p>Date: <?php echo date('F j, Y'); ?></p>
            </div>
        </div>
        <div class="certificate-no">
            Certificate No: <?php echo $cert['certificate_no']; ?><br>
            Verification Code: <span class="verification-code"><?php echo $cert['verification_code']; ?></span>
        </div>
        <div class="seal">VERIFIED</div>
    </div>
    
    <script>
        // Auto-open print dialog
        setTimeout(function() {
            window.print();
        }, 500);
    </script>
</body>
</html>
<?php
exit;
?>