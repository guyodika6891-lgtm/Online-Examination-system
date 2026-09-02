-- Create database
CREATE DATABASE IF NOT EXISTS exam_system;
USE exam_system;

-- Users table (all roles)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    role ENUM('student', 'teacher', 'exam_committee', 'admin') DEFAULT 'student',
    department VARCHAR(100),
    student_id VARCHAR(50) UNIQUE,
    teacher_id VARCHAR(50) UNIQUE,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    profile_pic VARCHAR(255),
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Departments table
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dept_name VARCHAR(100) UNIQUE NOT NULL,
    dept_code VARCHAR(10) UNIQUE NOT NULL,
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- Courses table
CREATE TABLE courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(20) UNIQUE NOT NULL,
    course_name VARCHAR(200) NOT NULL,
    department_id INT,
    credits INT DEFAULT 3,
    semester INT,
    teacher_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (teacher_id) REFERENCES users(id)
);

-- Questions table (teachers create, exam committee approves)
CREATE TABLE questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT,
    question_text TEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_answer CHAR(1) NOT NULL,
    marks INT DEFAULT 1,
    difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
    created_by INT,
    status ENUM('pending', 'approved', 'rejected', 'draft') DEFAULT 'pending',
    approved_by INT,
    approved_at DATETIME,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Exam schedules table (exam committee manages)
CREATE TABLE exam_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_name VARCHAR(200) NOT NULL,
    course_id INT,
    exam_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration_minutes INT DEFAULT 60,
    total_questions INT,
    total_marks INT,
    passing_percentage DECIMAL(5,2) DEFAULT 40,
    instructions TEXT,
    status ENUM('upcoming', 'ongoing', 'completed', 'cancelled') DEFAULT 'upcoming',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Student exam enrollment
CREATE TABLE exam_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_schedule_id INT,
    student_id INT,
    status ENUM('registered', 'started', 'completed', 'absent') DEFAULT 'registered',
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_schedule_id) REFERENCES exam_schedules(id),
    FOREIGN KEY (student_id) REFERENCES users(id),
    UNIQUE KEY unique_enrollment (exam_schedule_id, student_id)
);

-- Results table
CREATE TABLE results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_schedule_id INT,
    student_id INT,
    score INT DEFAULT 0,
    total_questions INT,
    total_marks INT,
    percentage DECIMAL(5,2),
    answers TEXT,
    started_at DATETIME,
    submitted_at DATETIME,
    status ENUM('pending', 'passed', 'failed', 'graded') DEFAULT 'pending',
    graded_by INT,
    remarks TEXT,
    FOREIGN KEY (exam_schedule_id) REFERENCES exam_schedules(id),
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (graded_by) REFERENCES users(id)
);

-- System logs
CREATE TABLE system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255),
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Insert default data
-- Admin user (password: Admin@123)
INSERT INTO users (username, password, full_name, email, role, status) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin@examsystem.com', 'admin', 'active');

-- Sample departments
INSERT INTO departments (dept_name, dept_code) VALUES 
('Computer Science', 'CS'),
('Mathematics', 'MATH'),
('Physics', 'PHY'),
('English', 'ENG');

-- Sample teacher (password: Teacher@123)
INSERT INTO users (username, password, full_name, email, role, department, teacher_id, status) VALUES 
('teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Smith', 'teacher@examsystem.com', 'teacher', 'Computer Science', 'TCH001', 'active');

-- Sample exam committee member (password: Committee@123)
INSERT INTO users (username, password, full_name, email, role, status) VALUES 
('exam_comm', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sarah Johnson', 'committee@examsystem.com', 'exam_committee', 'active');

-- Sample student (password: Student@123)
INSERT INTO users (username, password, full_name, email, role, department, student_id, status) VALUES 
('student1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mike Wilson', 'student@examsystem.com', 'student', 'Computer Science', 'STU001', 'active');