<?php
// Enhanced Question Parser for various formats

function parseQuestionsFromWord($file_path) {
    $questions = [];
    
    // For DOCX files
    $zip = new ZipArchive;
    if($zip->open($file_path) === true) {
        $content = $zip->getFromName('word/document.xml');
        $zip->close();
        
        // Remove XML tags and decode HTML entities
        $content = strip_tags($content);
        $content = html_entity_decode($content);
        
        // Parse the content
        $questions = parseStructuredQuestions($content);
    }
    
    return $questions;
}

function parseStructuredQuestions($content) {
    $questions = [];
    
    // Split by question numbers (1., 1), Q1., etc.)
    $lines = explode("\n", $content);
    
    $current_question = null;
    $options = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
    $current_option = null;
    $correct_answer = null;
    
    foreach($lines as $line) {
        $line = trim($line);
        if(empty($line)) continue;
        
        // Match question number pattern: "1.", "1)", "Q1.", "Q1)"
        if(preg_match('/^(?:Q?(\d+)[\.\)]\s*)(.+)$/i', $line, $matches)) {
            // Save previous question
            if($current_question && !empty($options['A'])) {
                $questions[] = [
                    'question' => $current_question,
                    'a' => $options['A'],
                    'b' => $options['B'],
                    'c' => $options['C'],
                    'd' => $options['D'],
                    'correct' => $correct_answer ? strtoupper($correct_answer) : 'A'
                ];
            }
            // Start new question
            $current_question = trim($matches[2]);
            $options = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];
            $correct_answer = null;
        }
        // Match options: "A. text", "A) text", "A. text"
        elseif(preg_match('/^([A-D])[\.\)]\s+(.+)$/i', $line, $matches)) {
            $opt = strtoupper($matches[1]);
            $options[$opt] = trim($matches[2]);
        }
        // Match answer: "Answer: A", "Ans: B", "Correct: C"
        elseif(preg_match('/(?:Answer|Ans|Correct)[:\s]+([A-D])/i', $line, $matches)) {
            $correct_answer = strtoupper($matches[1]);
        }
        // Continue question text if line doesn't match other patterns
        elseif($current_question && !preg_match('/^[A-D][\.\)]/i', $line)) {
            $current_question .= ' ' . $line;
        }
    }
    
    // Add the last question
    if($current_question && !empty($options['A'])) {
        $questions[] = [
            'question' => $current_question,
            'a' => $options['A'],
            'b' => $options['B'],
            'c' => $options['C'],
            'd' => $options['D'],
            'correct' => $correct_answer ? strtoupper($correct_answer) : 'A'
        ];
    }
    
    return $questions;
}

// For your specific question format
function parseYourQuestionFormat($content) {
    $questions = [];
    
    // Your questions have format:
    // 1. Which Errors are occur when the code violates the rules of the programming language.
    // A.Syntax error
    // B.Runtime error
    // C.Logical error
    // D.Semantic error
    
    $lines = explode("\n", $content);
    $current_question = null;
    $options = [];
    $question_number = 0;
    
    foreach($lines as $line) {
        $line = trim($line);
        if(empty($line)) continue;
        
        // Match numbered question: "1. text" or "1) text"
        if(preg_match('/^(\d+)[\.\)]\s+(.+)$/', $line, $matches)) {
            // Save previous question
            if($current_question && count($options) == 4) {
                $questions[] = [
                    'question' => $current_question,
                    'a' => isset($options[0]) ? $options[0] : '',
                    'b' => isset($options[1]) ? $options[1] : '',
                    'c' => isset($options[2]) ? $options[2] : '',
                    'd' => isset($options[3]) ? $options[3] : '',
                    'correct' => 'A' // Default, should be detected from answer key
                ];
            }
            $current_question = trim($matches[2]);
            $options = [];
            $question_number = $matches[1];
        }
        // Match options: "A. text" or "A) text" or "A.text" (no space)
        elseif(preg_match('/^([A-D])[\.\)]?\s*(.+)$/i', $line, $matches)) {
            $options[] = trim($matches[2]);
        }
        // If line doesn't match, it might be continuation of question
        elseif($current_question && !preg_match('/^[A-D]/i', $line)) {
            $current_question .= ' ' . $line;
        }
    }
    
    // Add last question
    if($current_question && count($options) == 4) {
        $questions[] = [
            'question' => $current_question,
            'a' => $options[0],
            'b' => $options[1],
            'c' => $options[2],
            'd' => $options[3],
            'correct' => 'A'
        ];
    }
    
    return $questions;
}
?>