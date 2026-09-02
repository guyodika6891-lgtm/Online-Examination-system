USE exam_system;

-- Create user_roles table for multiple roles per user
CREATE TABLE IF NOT EXISTS user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('student', 'teacher', 'exam_committee', 'admin') NOT NULL,
    assigned_by INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active',
    deactivated_at TIMESTAMP NULL,
    deactivated_by INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id),
    FOREIGN KEY (deactivated_by) REFERENCES users(id),
    UNIQUE KEY unique_user_role (user_id, role)
);

-- Migrate existing roles to user_roles table
INSERT IGNORE INTO user_roles (user_id, role, assigned_by, assigned_at)
SELECT id, role, 1, created_at FROM users WHERE role IS NOT NULL;

-- Add a column to track primary role (for display purposes)
ALTER TABLE users ADD COLUMN primary_role VARCHAR(50) NULL;

-- Set primary roles for existing users
UPDATE users u 
SET u.primary_role = (
    SELECT role FROM user_roles ur 
    WHERE ur.user_id = u.id 
    ORDER BY FIELD(role, 'admin', 'exam_committee', 'teacher', 'student') 
    LIMIT 1
);

-- Create role_permissions table
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    permission VARCHAR(100) NOT NULL,
    UNIQUE KEY unique_role_permission (role, permission)
);

-- Insert default permissions
INSERT INTO role_permissions (role, permission) VALUES
-- Student permissions
('student', 'view_dashboard'),
('student', 'take_exam'),
('student', 'view_own_results'),
('student', 'update_profile'),

-- Teacher permissions
('teacher', 'view_dashboard'),
('teacher', 'create_questions'),
('teacher', 'edit_questions'),
('teacher', 'delete_questions'),
('teacher', 'view_question_bank'),
('teacher', 'view_student_results'),

-- Exam Committee permissions
('exam_committee', 'view_dashboard'),
('exam_committee', 'approve_questions'),
('exam_committee', 'schedule_exams'),
('exam_committee', 'manage_exams'),
('exam_committee', 'generate_reports'),
('exam_committee', 'view_all_results'),

-- Admin permissions
('admin', 'view_dashboard'),
('admin', 'manage_users'),
('admin', 'manage_roles'),
('admin', 'manage_courses'),
('admin', 'manage_enrollments'),
('admin', 'system_settings'),
('admin', 'view_logs'),
('admin', 'backup_database');

-- Create role_switching_log table
CREATE TABLE IF NOT EXISTS role_switching_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    previous_role VARCHAR(50),
    new_role VARCHAR(50),
    switched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (user_id) REFERENCES users(id)
);