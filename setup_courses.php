<?php
require_once 'config/database.php';

echo "<h2>Setting up Course Assignments</h2>";

// Get teacher ID
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'teacher1'");
$stmt->execute();
$teacher = $stmt->fetch();

if(!$teacher) {
    die("Teacher not found! Please run fix_passwords.php first.");
}

$teacher_id = $teacher['id'];
$admin_id = $_SESSION['user_id'] ?? 1;

// Get or create departments
$depts = [
    'CS' => 'Computer Science',
    'IT' => 'Information Technology'
];

foreach($depts as $code => $name) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO departments (dept_name, dept_code) VALUES (?, ?)");
    $stmt->execute([$name, $code]);
}

// Get department IDs
$stmt = $pdo->query("SELECT id FROM departments WHERE dept_code = 'CS'");
$cs_dept = $stmt->fetch()['id'];

// Assign courses to teacher
$courses = [
    ['CS101', 'Introduction to Programming', 3, 1],
    ['CS201', 'Data Structures', 3, 2],
    ['CS301', 'Database Systems', 3, 3],
    ['CS401', 'Web Development', 3, 4]
];

foreach($courses as $course) {
    $code = $course[0];
    $name = $course[1];
    $credits = $course[2];
    $semester = $course[3];
    
    // Check if course exists
    $stmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = ?");
    $stmt->execute([$code]);
    
    if($stmt->fetch()) {
        // Update existing course
        $stmt = $pdo->prepare("UPDATE courses SET teacher_id = ?, status = 'active' WHERE course_code = ?");
        $stmt->execute([$teacher_id, $code]);
        echo "✅ Updated course: $code<br>";
    } else {
        // Insert new course
        $stmt = $pdo->prepare("
            INSERT INTO courses (course_code, course_name, department_id, credits, semester, teacher_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([$code, $name, $cs