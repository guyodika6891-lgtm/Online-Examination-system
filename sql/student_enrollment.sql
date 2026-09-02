USE exam_system;

-- Create student_courses table (which courses each student is enrolled in)
CREATE TABLE IF NOT EXISTS student_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    enrollment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'dropped', 'completed') DEFAULT 'active',
    dropped_date TIMESTAMP NULL,
    grade VARCHAR(2) NULL,
    enrolled_by INT,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (enrolled_by) REFERENCES users(id),
    UNIQUE KEY unique_enrollment (student_id, course_id)
);

-- Create enrollment_history table for tracking
CREATE TABLE IF NOT EXISTS enrollment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    action VARCHAR(20) NOT NULL, -- 'enroll', 'drop', 'complete'
    performed_by INT NOT NULL,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason TEXT,
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (performed_by) REFERENCES users(id)
);

-- Insert sample enrollments for student1
INSERT IGNORE INTO student_courses (student_id, course_id, status, enrolled_by)
SELECT 
    (SELECT id FROM users WHERE username = 'student1' LIMIT 1) as student_id,
    c.id as course_id,
    'active' as status,
    (SELECT id FROM users WHERE username = 'admin' LIMIT 1) as enrolled_by
FROM courses c
WHERE c.course_code IN ('CS101', 'CS201', 'CS301')
AND NOT EXISTS (
    SELECT 1 FROM student_courses sc 
    WHERE sc.student_id = (SELECT id FROM users WHERE username = 'student1' LIMIT 1)
    AND sc.course_id = c.id
);

-- Add department column to users if not exists
ALTER TABLE users ADD COLUMN IF NOT EXISTS enrollment_date DATE NULL;