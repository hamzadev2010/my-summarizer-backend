<?php
// CORS Headers - MUST be at the very top, before any output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Origin, Accept, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(["message" => "CORS preflight OK"]);
    exit();
}

// --- Read JSON input ---
$input = json_decode(file_get_contents('php://input'), true);

// --- Validate input ---
if (!isset($input["text"]) || empty(trim($input["text"]))) {
    echo json_encode(["error" => "No or empty text provided."]);
    exit;
}

// --- Extract options ---
$options = $input["options"] ?? [];
$maxLength = $options["maxLength"] ?? 4000;
$detailedSummary = $options["detailedSummary"] ?? false;

// --- Clean up text ---
$text = trim($input["text"]);
$text = preg_replace('/\s+/', ' ', $text);

// --- Local summarization function ---
function generateLocalSummary($text, $maxLength, $detailedSummary) {
    // Split into sentences
    $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $sentences = array_map('trim', $sentences);
    $sentences = array_filter($sentences); // Remove empty sentences
    
    if (empty($sentences)) {
        return "This document appears to be empty or contains no readable text.";
    }
    
    // Calculate sentence importance
    $sentenceScores = [];
    foreach ($sentences as $index => $sentence) {
        $score = 0;
        
        // Longer sentences get higher scores (up to a point)
        $length = strlen($sentence);
        if ($length > 20 && $length < 200) {
            $score += 3;
        } elseif ($length >= 200) {
            $score += 1;
        }
        
        // Sentences with key words get higher scores
        $keywords = [
            'important', 'key', 'main', 'primary', 'essential', 'critical', 
            'significant', 'major', 'summary', 'conclusion', 'result', 'finding',
            'study', 'research', 'analysis', 'data', 'evidence', 'prove',
            'demonstrate', 'show', 'indicate', 'suggest', 'conclude', 'therefore',
            'however', 'nevertheless', 'furthermore', 'moreover', 'additionally'
        ];
        foreach ($keywords as $keyword) {
            if (stripos($sentence, $keyword) !== false) {
                $score += 4;
            }
        }
        
        // First and last sentences get bonus points
        if ($index === 0) $score += 3; // First sentence
        if ($index === count($sentences) - 1) $score += 3; // Last sentence
        
        // Sentences with numbers or dates get higher scores
        if (preg_match('/\d/', $sentence)) {
            $score += 2;
        }
        
        // Sentences with quotes or citations get higher scores
        if (preg_match('/["\']/', $sentence)) {
            $score += 2;
        }
        
        $sentenceScores[$index] = $score;
    }
    
    // Sort sentences by score (highest first)
    arsort($sentenceScores);
    
    // Select top sentences for summary
    $summarySentences = [];
    $currentLength = 0;
    $maxSentences = $detailedSummary ? 8 : 5;
    
    foreach ($sentenceScores as $index => $score) {
        $sentence = $sentences[$index];
        $sentenceLength = strlen($sentence);
        
        if ($currentLength + $sentenceLength <= $maxLength) {
            $summarySentences[] = $sentence;
            $currentLength += $sentenceLength;
        }
        
        // Stop if we have enough sentences
        if (count($summarySentences) >= $maxSentences) {
            break;
        }
    }
    
    // Sort sentences back to original order
    sort($summarySentences);
    
    // Create the summary
    $summary = implode('. ', $summarySentences);
    $summary = trim($summary);
    
    // Add period if missing
    if (!empty($summary) && !in_array(substr($summary, -1), ['.', '!', '?'])) {
        $summary .= '.';
    }
    
    // If summary is too short, add a generic message
    if (strlen($summary) < 50) {
        $summary = "This document contains information that can be summarized. The content includes various topics and provides detailed analysis. " . $summary;
    }
    
    return $summary;
}

// --- Process the text ---
$summary = generateLocalSummary($text, $maxLength, $detailedSummary);

// --- Return the summary ---
echo json_encode([
    "summary_text" => $summary,
    "original_length" => strlen($text),
    "summary_length" => strlen($summary),
    "compression_ratio" => round((strlen($summary) / strlen($text)) * 100, 2) . '%',
    "method" => "local_summarization",
    "status" => "success"
]);
?> 