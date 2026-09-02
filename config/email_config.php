<?php
// Email configuration (Update with your SMTP settings)
define('SMTP_HOST', 'smtp.gmail.com');  // Your SMTP server
define('SMTP_PORT', 587);                // SMTP port (587 for TLS, 465 for SSL)
define('SMTP_USER', 'your_email@gmail.com');  // Your email
define('SMTP_PASS', 'your_app_password');     // Your email password or app password
define('SMTP_FROM_EMAIL', 'noreply@examsystem.com');
define('SMTP_FROM_NAME', 'Online Examination System');

// Function to send email using PHPMailer
function sendEmail($to_email, $to_name, $subject, $message) {
    require_once '../vendor/autoload.php'; // PHPMailer autoload
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to_email, $to_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags($message);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to add email to queue
function queueEmail($pdo, $recipient_email, $recipient_name, $subject, $message) {
    $stmt = $pdo->prepare("
        INSERT INTO email_queue (recipient_email, recipient_name, subject, message) 
        VALUES (?, ?, ?, ?)
    ");
    return $stmt->execute([$recipient_email, $recipient_name, $subject, $message]);
}

// Function to process email queue
function processEmailQueue($pdo) {
    $stmt = $pdo->prepare("
        SELECT * FROM email_queue 
        WHERE status = 'pending' AND attempts < 3 
        ORDER BY created_at ASC 
        LIMIT 10
    ");
    $stmt->execute();
    $emails = $stmt->fetchAll();
    
    foreach($emails as $email) {
        $success = sendEmail($email['recipient_email'], $email['recipient_name'], 
                            $email['subject'], $email['message']);
        
        $status = $success ? 'sent' : 'failed';
        $attempts = $email['attempts'] + 1;
        
        $update = $pdo->prepare("
            UPDATE email_queue 
            SET status = ?, attempts = ?, sent_at = ? 
            WHERE id = ?
        ");
        $update->execute([$status, $attempts, $success ? date('Y-m-d H:i:s') : null, $email['id']]);
    }
}

// Function to send email using template
function sendEmailTemplate($pdo, $template_name, $student_id, $data) {
    // Get template
    $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE template_name = ?");
    $stmt->execute([$template_name]);
    $template = $stmt->fetch();
    
    if(!$template) return false;
    
    // Get student info
    $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    // Replace placeholders
    $subject = $template['subject'];
    $body = $template['body'];
    
    foreach($data as $key => $value) {
        $subject = str_replace('{' . $key . '}', $value, $subject);
        $body = str_replace('{' . $key . '}', $value, $body);
    }
    
    // Queue email
    return queueEmail($pdo, $student['email'], $student['full_name'], $subject, $body);
}
?>