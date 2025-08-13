<?php
/**
 * Browser Client Response Submission Endpoint
 * 
 * Handles response chunks from browser clients in the
 * distributed LMArena Bridge API system.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Browser-Session-ID');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../../includes/RequestQueue.php';
require_once __DIR__ . '/../../../includes/BrowserSessionManager.php';
require_once __DIR__ . '/../../../includes/TokenCounter.php';

try {
    // Initialize components
    $queue = new RequestQueue();
    $sessionManager = new BrowserSessionManager();
    $tokenCounter = new TokenCounter();
    
    // Get browser session ID from header
    $browserSessionId = $_SERVER['HTTP_X_BROWSER_SESSION_ID'] ?? '';
    
    if (empty($browserSessionId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing browser session ID']);
        exit;
    }
    
    // Parse request body
    $input = file_get_contents('php://input');
    $requestData = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON in request body']);
        exit;
    }
    
    // Validate required fields
    if (!isset($requestData['request_id']) || !isset($requestData['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: request_id, type']);
        exit;
    }
    
    $requestId = $requestData['request_id'];
    $responseType = $requestData['type']; // 'chunk', 'complete', 'error'
    
    switch ($responseType) {
        case 'chunk':
            handleResponseChunk($queue, $tokenCounter, $requestData);
            break;
            
        case 'complete':
            handleRequestComplete($queue, $sessionManager, $tokenCounter, $requestData, $browserSessionId);
            break;
            
        case 'error':
            handleRequestError($queue, $sessionManager, $requestData, $browserSessionId);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid response type']);
            exit;
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("Browser response error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Handle streaming response chunk
 */
function handleResponseChunk($queue, $tokenCounter, $requestData) {
    $requestId = $requestData['request_id'];
    $sequence = $requestData['sequence'] ?? 0;
    $content = $requestData['content'] ?? '';
    
    // Store the response chunk
    $chunkData = [
        'content' => $content,
        'tokens' => $tokenCounter->countResponseTokens($content)
    ];
    
    $success = $queue->storeResponseChunk(
        $requestId, 
        json_encode($chunkData), 
        $sequence, 
        'data'
    );
    
    if (!$success) {
        throw new Exception("Failed to store response chunk");
    }
}

/**
 * Handle request completion
 */
function handleRequestComplete($queue, $sessionManager, $tokenCounter, $requestData, $browserSessionId) {
    $requestId = $requestData['request_id'];
    $fullResponse = $requestData['full_response'] ?? '';
    $responseTimeMs = $requestData['response_time_ms'] ?? 0;
    
    // Count output tokens
    $outputTokens = $tokenCounter->countTextTokens($fullResponse);
    
    // Store final completion chunk
    $queue->storeResponseChunk($requestId, json_encode(['content' => '']), 999999, 'done');
    
    // Complete the request
    $success = $queue->completeRequest($requestId, $outputTokens, 'completed');
    
    if (!$success) {
        throw new Exception("Failed to complete request");
    }
    
    // Update browser session performance metrics
    $sessionManager->updatePerformanceMetrics($browserSessionId, [
        'response_time' => $responseTimeMs,
        'success' => true
    ]);
    
    error_log("Request {$requestId} completed successfully by browser session {$browserSessionId}");
}

/**
 * Handle request error
 */
function handleRequestError($queue, $sessionManager, $requestData, $browserSessionId) {
    $requestId = $requestData['request_id'];
    $errorMessage = $requestData['error_message'] ?? 'Unknown error';
    $errorType = $requestData['error_type'] ?? 'processing_error';
    
    // Store error response
    $errorData = [
        'message' => $errorMessage,
        'type' => $errorType,
        'code' => $requestData['error_code'] ?? null
    ];
    
    $queue->storeResponseChunk($requestId, json_encode($errorData), 0, 'error');
    
    // Complete the request with error status
    $success = $queue->completeRequest($requestId, 0, 'failed', $errorMessage);
    
    if (!$success) {
        throw new Exception("Failed to mark request as failed");
    }
    
    // Update browser session performance metrics
    $sessionManager->updatePerformanceMetrics($browserSessionId, [
        'response_time' => $requestData['response_time_ms'] ?? 0,
        'success' => false
    ]);
    
    error_log("Request {$requestId} failed in browser session {$browserSessionId}: {$errorMessage}");
}
?>
