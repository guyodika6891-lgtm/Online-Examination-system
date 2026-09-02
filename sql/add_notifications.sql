USE exam_system;

-- Email queue table for sending emails
CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(100) NOT NULL,
    recipient_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL
);

-- Certificates table
CREATE TABLE IF NOT EXISTS certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_schedule_id INT NOT NULL,
    certificate_no VARCHAR(50) UNIQUE NOT NULL,
    file_path VARCHAR(255),
    issue_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('issued', 'pending', 'failed') DEFAULT 'pending',
    verification_code VARCHAR(50) UNIQUE,
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (exam_schedule_id) REFERENCES exam_schedules(id)
);

-- Notification settings table
CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email_notifications BOOLEAN DEFAULT TRUE,
    exam_reminders BOOLEAN DEFAULT TRUE,
    result_notifications BOOLEAN DEFAULT TRUE,
    certificate_available BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_user_settings (user_id)
);

-- Email templates table
CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(50) UNIQUE NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    last_modified TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default email templates
INSERT INTO email_templates (template_name, subject, body) VALUES
('exam_registration', 'Exam Registration Confirmation', '
Dear {student_name},

You have successfully registered for the exam "{exam_name}".

📅 Date: {exam_date}
⏰ Time: {start_time} - {end_time}
⏱️ Duration: {duration} minutes
✅ Passing Percentage: {passing_percentage}%

Please log in to the system before the exam starts.

Best regards,
Exam System Administrator
'),

('exam_result', 'Your Exam Result - {exam_name}', '
Dear {student_name},

Your result for the exam "{exam_name}" has been published.

📊 Your Score: {score}/{total_marks}
📈 Percentage: {percentage}%
🎯 Status: {status}

{passed_message}

You can view your detailed results by logging into the system.

Best regards,
Exam System Administrator
'),

('certificate_issued', 'Your Certificate is Ready - {exam_name}', '
Dear {student_name},

Congratulations! You have successfully passed the exam "{exam_name}" with a score of {percentage}%.

Your certificate is now available for download.

📄 Certificate Number: {certificate_no}
🔑 Verification Code: {verification_code}

You can download your certificate from your dashboard.

Best regards,
Exam System Administrator
'),

('exam_reminder', 'Reminder: Upcoming Exam - {exam_name}', '
Dear {student_name},

This is a reminder that you have an upcoming exam:

📚 Exam: {exam_name}
📅 Date: {exam_date}
⏰ Time: {start_time} - {end_time}
⏱️ Duration: {duration} minutes

Please make sure you are prepared and log in on time.

Good luck!
Exam System Administrator
');

-- Add email and certificate columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified BOOLEAN DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_token VARCHAR(100) NULL;