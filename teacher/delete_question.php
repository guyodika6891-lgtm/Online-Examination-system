<?php
require_once '../config/database.php';
require_once '../config/permissions.php';
checkRole(['teacher']);

$question_id = $_GET['id'] ?? 0;
$teacher_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("DELETE FROM questions WHERE id = ? AND created_by = ?");
$stmt->execute([$question_id, $teacher_id]);

logActivity($pdo, $teacher_id, 'question_deleted', "Deleted question ID: $question_id");
header("Location: question_bank.php?deleted=1");
exit();
?>