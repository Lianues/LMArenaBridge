<?php
/**
 * Browser Client Polling Endpoint
 * 
 * Handles polling requests from distributed browser clients
 * for the LMArena Bridge API system.
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

try {
    // Initialize components
    $queue = new RequestQueue();
    $sessionManager = new BrowserSessionManager();
    
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
    
    // Update session heartbeat with performance metrics
    $metrics = [
        'current_load' => $requestData['current_load'] ?? 0,
        'average_response_time' => $requestData['average_response_time'] ?? null,
        'success_rate' => $requestData['success_rate'] ?? null
    ];
    
    $sessionManager->updateHeartbeat($browserSessionId, $metrics);
    
    // Clean up timed out requests
    $queue->cleanupTimedOutRequests();
    
    // Get next request for processing
    $nextRequest = $queue->getNextRequest($browserSessionId);
    
    if ($nextRequest) {
        // Prepare request for browser client
        $response = [
            'has_request' => true,
            'request' => [
                'request_id' => $nextRequest['request_id'],
                'model' => $nextRequest['model_requested'],
                'messages' => json_decode($nextRequest['request_content'], true),
                'stream' => (bool)$nextRequest['stream_mode'],
                'priority' => $nextRequest['priority'],
                'created_at' => $nextRequest['created_at']
            ]
        ];
        
        // Log request assignment
        error_log("Assigned request {$nextRequest['request_id']} to browser session {$browserSessionId}");
        
        echo json_encode($response);
    } else {
        // No requests available
        echo json_encode([
            'has_request' => false,
            'message' => 'No requests available',
            'poll_interval' => calculatePollInterval($sessionManager, $browserSessionId)
        ]);
    }
    
} catch (Exception $e) {
    error_log("Browser polling error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Calculate adaptive polling interval based on system load
 */
function calculatePollInterval($sessionManager, $browserSessionId) {
    try {
        $stats = $sessionManager->getSessionStatistics();
        $utilization = $stats['utilization_percentage'] ?? 0;
        
        // Adaptive polling: higher utilization = more frequent polling
        if ($utilization > 80) {
            return 1; // 1 second for high load
        } elseif ($utilization > 50) {
            return 2; // 2 seconds for medium load
        } elseif ($utilization > 20) {
            return 5; // 5 seconds for low load
        } else {
            return 10; // 10 seconds for very low load
        }
    } catch (Exception $e) {
        return 5; // Default 5 seconds on error
    }
}
?>
