<?php
/**
 * OpenAI-Compatible Chat Completions Endpoint
 * 
 * Handles chat completion requests in OpenAI format for the
 * LMArena Bridge distributed API system.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => ['message' => 'Method not allowed', 'type' => 'invalid_request_error']]);
    exit;
}

require_once __DIR__ . '/../../../includes/ApiAuth.php';
require_once __DIR__ . '/../../../includes/RequestQueue.php';
require_once __DIR__ . '/../../../includes/BrowserSessionManager.php';
require_once __DIR__ . '/../../../includes/TokenCounter.php';

try {
    // Initialize components
    $auth = new ApiAuth();
    $queue = new RequestQueue();
    $sessionManager = new BrowserSessionManager();
    $tokenCounter = new TokenCounter();
    
    // Authenticate request
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $user = $auth->authenticate($authHeader);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'error' => [
                'message' => 'Invalid API key',
                'type' => 'authentication_error'
            ]
        ]);
        exit;
    }
    
    // Check rate limits
    if (!$auth->checkRateLimit()) {
        $rateLimitStatus = $auth->getRateLimitStatus();
        http_response_code(429);
        echo json_encode([
            'error' => [
                'message' => 'Rate limit exceeded',
                'type' => 'rate_limit_error',
                'details' => $rateLimitStatus
            ]
        ]);
        exit;
    }
    
    // Parse request body
    $input = file_get_contents('php://input');
    $requestData = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'error' => [
                'message' => 'Invalid JSON in request body',
                'type' => 'invalid_request_error'
            ]
        ]);
        exit;
    }
    
    // Validate required fields
    if (!isset($requestData['model']) || !isset($requestData['messages'])) {
        http_response_code(400);
        echo json_encode([
            'error' => [
                'message' => 'Missing required fields: model and messages',
                'type' => 'invalid_request_error'
            ]
        ]);
        exit;
    }
    
    // Validate token limits
    $inputTokens = $tokenCounter->countTokens($requestData['messages']);
    $tokenValidation = $tokenCounter->validateTokenLimit($requestData['model'], $inputTokens);
    
    if (!$tokenValidation['valid']) {
        http_response_code(400);
        echo json_encode([
            'error' => [
                'message' => "Token limit exceeded. Model limit: {$tokenValidation['limit']}, requested: {$inputTokens}",
                'type' => 'invalid_request_error',
                'details' => $tokenValidation
            ]
        ]);
        exit;
    }
    
    // Check for available browser sessions
    $availableSession = $sessionManager->getBestSession([
        'model_type' => $requestData['model']
    ]);
    
    if (!$availableSession) {
        http_response_code(503);
        echo json_encode([
            'error' => [
                'message' => 'No browser clients available. Please try again later.',
                'type' => 'service_unavailable_error'
            ]
        ]);
        exit;
    }
    
    // Prepare request for queue
    $queueData = [
        'user_id' => $user['id'],
        'user_tier' => $user['subscription_tier'],
        'model' => $requestData['model'],
        'messages' => $requestData['messages'],
        'stream' => $requestData['stream'] ?? true,
        'temperature' => $requestData['temperature'] ?? 1.0,
        'max_tokens' => $requestData['max_tokens'] ?? null,
        'top_p' => $requestData['top_p'] ?? 1.0,
        'frequency_penalty' => $requestData['frequency_penalty'] ?? 0,
        'presence_penalty' => $requestData['presence_penalty'] ?? 0,
        'stop' => $requestData['stop'] ?? null
    ];
    
    // Queue the request
    $requestId = $queue->queueRequest($queueData);
    
    if (!$requestId) {
        http_response_code(500);
        echo json_encode([
            'error' => [
                'message' => 'Failed to queue request',
                'type' => 'internal_server_error'
            ]
        ]);
        exit;
    }
    
    // Handle streaming vs non-streaming response
    $isStreaming = $requestData['stream'] ?? true;
    
    if ($isStreaming) {
        // Set headers for streaming response
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        
        // Start streaming response
        streamResponse($requestId, $requestData['model'], $queue);
    } else {
        // Wait for complete response
        $response = waitForCompleteResponse($requestId, $requestData['model'], $queue);
        echo json_encode($response);
    }
    
} catch (Exception $e) {
    error_log("Chat completions error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => [
            'message' => 'Internal server error',
            'type' => 'internal_server_error'
        ]
    ]);
}

/**
 * Stream response chunks to client
 */
function streamResponse($requestId, $model, $queue) {
    $lastSequence = 0;
    $startTime = time();
    $timeout = 300; // 5 minutes timeout
    
    // Send initial response
    $initialChunk = [
        'id' => $requestId,
        'object' => 'chat.completion.chunk',
        'created' => time(),
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'delta' => ['role' => 'assistant'],
                'finish_reason' => null
            ]
        ]
    ];
    
    echo "data: " . json_encode($initialChunk) . "\n\n";
    flush();
    
    while (true) {
        // Check for timeout
        if (time() - $startTime > $timeout) {
            $queue->completeRequest($requestId, 0, 'timeout', 'Response timeout');
            echo "data: [DONE]\n\n";
            break;
        }
        
        // Get new response chunks
        $chunks = $queue->getResponseChunks($requestId, $lastSequence);
        
        foreach ($chunks as $chunk) {
            $lastSequence = $chunk['chunk_sequence'];
            
            if ($chunk['chunk_type'] === 'done') {
                echo "data: [DONE]\n\n";
                flush();
                return;
            }
            
            if ($chunk['chunk_type'] === 'error') {
                $errorChunk = [
                    'id' => $requestId,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'error' => json_decode($chunk['response_content'], true)
                ];
                echo "data: " . json_encode($errorChunk) . "\n\n";
                flush();
                return;
            }
            
            // Parse and format response chunk
            $responseData = json_decode($chunk['response_content'], true);
            if ($responseData) {
                $formattedChunk = [
                    'id' => $requestId,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'delta' => ['content' => $responseData['content'] ?? ''],
                            'finish_reason' => $responseData['finish_reason'] ?? null
                        ]
                    ]
                ];
                
                echo "data: " . json_encode($formattedChunk) . "\n\n";
                flush();
            }
        }
        
        // Check request status
        $status = $queue->getRequestStatus($requestId);
        if ($status && in_array($status['status'], ['completed', 'failed', 'timeout'])) {
            if ($status['status'] !== 'completed') {
                $errorChunk = [
                    'id' => $requestId,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'error' => [
                        'message' => $status['error_message'] ?? 'Request failed',
                        'type' => 'processing_error'
                    ]
                ];
                echo "data: " . json_encode($errorChunk) . "\n\n";
            }
            echo "data: [DONE]\n\n";
            break;
        }
        
        // Short sleep to prevent excessive polling
        usleep(100000); // 100ms
    }
    
    flush();
}

/**
 * Wait for complete response (non-streaming)
 */
function waitForCompleteResponse($requestId, $model, $queue) {
    $startTime = time();
    $timeout = 300; // 5 minutes timeout
    $content = '';
    $lastSequence = 0;
    
    while (true) {
        // Check for timeout
        if (time() - $startTime > $timeout) {
            $queue->completeRequest($requestId, 0, 'timeout', 'Response timeout');
            return [
                'error' => [
                    'message' => 'Request timeout',
                    'type' => 'timeout_error'
                ]
            ];
        }
        
        // Get new response chunks
        $chunks = $queue->getResponseChunks($requestId, $lastSequence);
        
        foreach ($chunks as $chunk) {
            $lastSequence = $chunk['chunk_sequence'];
            
            if ($chunk['chunk_type'] === 'error') {
                return [
                    'error' => json_decode($chunk['response_content'], true)
                ];
            }
            
            if ($chunk['chunk_type'] === 'done') {
                // Calculate token usage
                $tokenCounter = new TokenCounter();
                $outputTokens = $tokenCounter->countTextTokens($content);
                
                return [
                    'id' => $requestId,
                    'object' => 'chat.completion',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => $content
                            ],
                            'finish_reason' => 'stop'
                        ]
                    ],
                    'usage' => [
                        'prompt_tokens' => 0, // Will be calculated by queue
                        'completion_tokens' => $outputTokens,
                        'total_tokens' => $outputTokens
                    ]
                ];
            }
            
            // Accumulate content
            $responseData = json_decode($chunk['response_content'], true);
            if ($responseData && isset($responseData['content'])) {
                $content .= $responseData['content'];
            }
        }
        
        // Check request status
        $status = $queue->getRequestStatus($requestId);
        if ($status && in_array($status['status'], ['completed', 'failed', 'timeout'])) {
            if ($status['status'] !== 'completed') {
                return [
                    'error' => [
                        'message' => $status['error_message'] ?? 'Request failed',
                        'type' => 'processing_error'
                    ]
                ];
            }
            break;
        }
        
        // Short sleep to prevent excessive polling
        usleep(100000); // 100ms
    }
    
    // Fallback response if we exit the loop without proper completion
    return [
        'error' => [
            'message' => 'Unexpected response completion',
            'type' => 'processing_error'
        ]
    ];
}
?>
