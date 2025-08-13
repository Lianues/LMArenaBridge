<?php
/**
 * Browser Client Registration Endpoint
 * 
 * Handles registration of new browser clients in the
 * distributed LMArena Bridge API system.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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

require_once __DIR__ . '/../../../includes/BrowserSessionManager.php';

try {
    // Initialize session manager
    $sessionManager = new BrowserSessionManager();
    
    // Parse request body
    $input = file_get_contents('php://input');
    $requestData = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON in request body']);
        exit;
    }
    
    // Validate required fields
    if (!isset($requestData['client_identifier'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing client_identifier']);
        exit;
    }
    
    // Prepare session data
    $sessionData = [
        'client_identifier' => $requestData['client_identifier'],
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'capabilities' => $requestData['capabilities'] ?? [
            'supported_models' => ['text', 'image'],
            'max_concurrent_requests' => 5,
            'streaming_support' => true
        ],
        'max_concurrent_requests' => $requestData['max_concurrent_requests'] ?? 5,
        'geographic_location' => $requestData['geographic_location'] ?? detectGeographicLocation()
    ];
    
    // Register the session
    $sessionId = $sessionManager->registerSession($sessionData);
    
    if ($sessionId) {
        echo json_encode([
            'success' => true,
            'session_id' => $sessionId,
            'message' => 'Browser session registered successfully',
            'polling_endpoint' => '/api/browser/poll.php',
            'response_endpoint' => '/api/browser/response.php',
            'recommended_poll_interval' => 5 // seconds
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to register browser session']);
    }
    
} catch (Exception $e) {
    error_log("Browser registration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Detect geographic location from IP address
 * This is a simplified implementation - consider using a proper GeoIP service
 */
function detectGeographicLocation() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Skip private/local IPs
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        // In production, use a GeoIP service like MaxMind or ipapi.co
        // For now, return a default location
        return 'unknown';
    }
    
    return 'local';
}
?>
