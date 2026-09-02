-- Use the database
USE exam_system;

-- Modify courses table to ensure teacher assignment is mandatory
ALTER TABLE courses 
MODIFY COLUMN teacher_id INT NOT NULL,
ADD COLUMN assigned_by INT,
ADD COLUMN assigned_at TIMESTAMP NULL,
ADD FOREIGN KEY (assigned_by) REFERENCES users(id);

-- Create course_assignments table for history tracking
CREATE TABLE IF NOT EXISTS course_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    assigned_by INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'removed') DEFAULT 'active',
    removed_at TIMESTAMP NULL,
    removed_by INT NULL,
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (teacher_id) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id),
    FOREIGN KEY (removed_by) REFERENCES users(id)
);

-- Insert sample departments if not exists
INSERT IGNORE INTO departments (dept_name, dept_code) VALUES 
('Computer Science', 'CS'),
('Information Technology', 'IT'),
('Software Engineering', 'SE'),
('Data Science', 'DS');

-- Insert sample courses with teacher assignments
-- First, make sure teacher exists
INSERT IGNORE INTO users (username, password, full_name, email, role, status) 
VALUES ('teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Smith', 'teacher@examsystem.com', 'teacher', 'active');

-- Get teacher ID (assuming teacher1 has ID 2)
SET @teacher_id = (SELECT id FROM users WHERE username = 'teacher1' LIMIT 1);
SET @admin_id = (SELECT id FROM users WHERE username = 'admin' LIMIT 1);

-- Insert courses assigned to teacher
INSERT INTO courses (course_code, course_name, department_id, credits, semester, teacher_id, status) VALUES
('CS101', 'Introduction to Programming', 1, 3, 1, @teacher_id, 'active'),
('CS201', 'Data Structures', 1, 3, 2, @teacher_id, 'active'),
('CS301', 'Database Management Systems', 1, 3, 3, @teacher_id, 'active'),
('CS401', 'Web Development', 1, 3, 4, @teacher_id, 'active');

-- Record the assignments in history
INSERT INTO course_assignments (course_id, teacher_id, assigned_by)
SELECT id, teacher_id, @admin_id FROM courses WHERE teacher_id = @teacher_id;