<?php
// Simple certificate generator - No Composer required!

function generateCertificate($pdo, $student_id, $exam_schedule_id, $result) {
    // Get student details
    $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    // Get exam and course details
    $stmt = $pdo->prepare("
        SELECT es.*, c.course_name, c.course_code 
        FROM exam_schedules es
        JOIN courses c ON es.course_id = c.id
        WHERE es.id = ?
    ");
    $stmt->execute([$exam_schedule_id]);
    $exam = $stmt->fetch();
    
    if(!$student || !$exam) return false;
    
    // Check if certificate already exists
    $stmt = $pdo->prepare("SELECT id FROM certificates WHERE student_id = ? AND exam_schedule_id = ?");
    $stmt->execute([$student_id, $exam_schedule_id]);
    if($stmt->fetch()) return false;
    
    // Create certificates directory
    $cert_dir = '../certificates/';
    if(!is_dir($cert_dir)) {
        mkdir($cert_dir, 0777, true);
    }
    
    // Generate unique certificate number
    $certificate_no = 'CERT-' . date('Y') . '-' . str_pad($student_id, 6, '0', STR_PAD_LEFT) . '-' . rand(1000, 9999);
    $verification_code = strtoupper(substr(md5($certificate_no . time()), 0, 10));
    
    // Save to database
    $stmt = $pdo->prepare("
        INSERT INTO certificates (student_id, exam_schedule_id, certificate_no, verification_code, status, issue_date)
        VALUES (?, ?, ?, ?, 'issued', NOW())
    ");
    $stmt->execute([$student_id, $exam_schedule_id, $certificate_no, $verification_code]);
    
    return $certificate_no;
}

function downloadCertificate($file_path) {
    $full_path = '../certificates/' . $file_path;
    if(file_exists($full_path)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="certificate.pdf"');
        readfile($full_path);
        exit;
    }
}

function verifyCertificate($pdo, $verification_code) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name, es.exam_name 
        FROM certificates c
        JOIN users u ON c.student_id = u.id
        JOIN exam_schedules es ON c.exam_schedule_id = es.id
        WHERE c.verification_code = ?
    ");
    $stmt->execute([$verification_code]);
    return $stmt->fetch();
}
?>