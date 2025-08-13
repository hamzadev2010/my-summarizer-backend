<?php
// Allow cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Hugging Face API endpoint and key
$api_url = "https://api-inference.huggingface.co/models/facebook/bart-large-cnn";
$api_key = "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"; // Your new API key

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input["text"]) || empty(trim($input["text"]))) {
    echo json_encode(["error" => "No or empty text provided."]);
    exit;
}

// Get options for enhanced summarization
$options = $input["options"] ?? [];
$maxLength = $options["maxLength"] ?? 4000;
$detailedSummary = $options["detailedSummary"] ?? false;
$chunkMode = $options["chunkMode"] ?? false;
$isLargeDocument = $options["isLargeDocument"] ?? false;

// Prepare text with better processing
$text = trim($input["text"]);
$text = preg_replace('/\s+/', ' ', $text); // Remove extra whitespace

// Enhanced text processing for large documents
function processLargeText($text, $maxLength, $isLargeDocument) {
    if (!$isLargeDocument || strlen($text) <= $maxLength) {
        return $text;
    }
    
    // For very large documents, use intelligent chunking
    if (strlen($text) > $maxLength * 2) {
        // Split into multiple chunks and process each
        $chunks = [];
        $chunkSize = $maxLength;
        $overlap = 200; // Overlap to maintain context
        
        for ($i = 0; $i < strlen($text); $i += ($chunkSize - $overlap)) {
            $chunk = substr($text, $i, $chunkSize);
            if (strlen($chunk) > 100) { // Only add meaningful chunks
                $chunks[] = $chunk;
            }
        }
        
        return $chunks;
    } else {
        // For moderately large texts, use smart truncation
        $third = intval($maxLength / 3);
        return substr($text, 0, $third) . 
               "\n\n[MIDDLE SECTION]\n\n" . 
               substr($text, intval(strlen($text) / 2) - $third/2, $third) . 
               "\n\n[FINAL SECTION]\n\n" . 
               substr($text, -$third);
    }
}

// Process the text based on size
$processedText = processLargeText($text, $maxLength, $isLargeDocument);

// If we have multiple chunks, process them separately
if (is_array($processedText)) {
    $summaries = [];
    
    foreach ($processedText as $index => $chunk) {
        $parameters = [
            "max_length" => $detailedSummary ? 200 : 150,
            "min_length" => $detailedSummary ? 100 : 50,
            "do_sample" => false,
            "num_beams" => 4,
            "early_stopping" => true,
            "length_penalty" => 1.0,
            "repetition_penalty" => 1.2
        ];
        
        $data = json_encode([
            "inputs" => $chunk,
            "parameters" => $parameters
        ]);
        
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $api_key",
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response !== false && $http_code === 200) {
            $result = json_decode($response, true);
            if (isset($result[0]['summary_text'])) {
                $summaries[] = $result[0]['summary_text'];
            }
        }
        
        // Add small delay between requests to avoid rate limiting
        usleep(500000); // 0.5 second delay
    }
    
    // Combine all summaries
    $combinedSummary = implode("\n\n", $summaries);
    
    // If combined summary is still too long, summarize it again
    if (strlen($combinedSummary) > 1000) {
        $finalParameters = [
            "max_length" => 400,
            "min_length" => 200,
            "do_sample" => false,
            "num_beams" => 5,
            "early_stopping" => true,
            "length_penalty" => 1.0,
            "repetition_penalty" => 1.2
        ];
        
        $finalData = json_encode([
            "inputs" => $combinedSummary,
            "parameters" => $finalParameters
        ]);
        
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $finalData,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $api_key",
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $finalResponse = curl_exec($ch);
        $finalHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($finalResponse !== false && $finalHttpCode === 200) {
            echo $finalResponse;
        } else {
            echo json_encode([["summary_text" => $combinedSummary]]);
        }
    } else {
        echo json_encode([["summary_text" => $combinedSummary]]);
    }
    
} else {
    // Single text processing (simplified parameters)
    $parameters = [
        "max_length" => 200,
        "min_length" => 50,
        "do_sample" => false
    ];

    $data = json_encode([
        "inputs" => $processedText,
        "parameters" => $parameters
    ]);

    // Initialize cURL
    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $api_key",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false, // Disable for local testing
        CURLOPT_SSL_VERIFYHOST => false, // Disable for local testing
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Handle cURL errors
    if ($response === false) {
        echo json_encode([
            "error" => "Failed to connect to Hugging Face API",
            "details" => $curl_error,
            "http_code" => $http_code
        ]);
        exit;
    }

    // Decode and return the API response
    if ($http_code === 200) {
        echo $response;
    } else {
        // Enhanced error handling with more details
        $errorDetails = json_decode($response, true);
        $errorMessage = "Hugging Face API error (HTTP $http_code)";
        
        if ($errorDetails) {
            if (isset($errorDetails['error'])) {
                $errorMessage .= ": " . $errorDetails['error'];
            }
            if (isset($errorDetails['estimated_time'])) {
                $errorMessage .= " - Model loading, please wait.";
            }
        }
        
        // For 400 errors, try with simpler parameters
        if ($http_code === 400) {
            // Try with minimal parameters and shorter text
            $simpleData = json_encode([
                "inputs" => substr($processedText, 0, 1000) // Much shorter text
            ]);
            
            $ch = curl_init($api_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $simpleData,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $api_key",
                    "Content-Type: application/json"
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_CONNECTTIMEOUT => 10
            ]);
            
            $simpleResponse = curl_exec($ch);
            $simpleHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($simpleResponse !== false && $simpleHttpCode === 200) {
                echo $simpleResponse;
                exit;
            }
        }
        
        echo json_encode([
            "error" => $errorMessage,
            "http_code" => $http_code,
            "response" => $errorDetails,
            "text_length" => strlen($processedText),
            "debug_info" => [
                "api_url" => $api_url,
                "text_preview" => substr($processedText, 0, 200),
                "parameters" => $parameters
            ]
        ]);
    }
}
?> 
