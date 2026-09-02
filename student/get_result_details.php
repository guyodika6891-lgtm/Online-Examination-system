<?php
require_once '../config/database.php';
require_once '../config/multi_role.php';

if(!isset($_SESSION['user_id']) || !hasRole($pdo, $_SESSION['user_id'], 'student')) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$result_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$student_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT answers FROM results WHERE id = ? AND student_id = ?");
$stmt->execute([$result_id, $student_id]);
$result = $stmt->fetch();

if($result) {
    $answers = json_decode($result['answers'], true);
    $questions = [];
    
    foreach($answers as $q_id => $user_answer) {
        $stmt = $pdo->prepare("SELECT question_text, correct_answer FROM questions WHERE id = ?");
        $stmt->execute([$q_id]);
        $q = $stmt->fetch();
        
        if($q) {
            $questions[] = [
                'question' => $q['question_text'],
                'user_answer' => $user_answer,
                'correct_answer' => $q['correct_answer'],
                'is_correct' => ($user_answer == $q['correct_answer'])
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($questions);
} else {
    echo json_encode(['error' => 'Result not found']);
}
?>