<?php
/**
 * Request Queue Manager
 * 
 * Handles queuing, assignment, and processing of API requests
 * in the distributed LMArena Bridge system.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/TokenCounter.php';

class RequestQueue {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Queue a new API request
     * 
     * @param array $requestData Request information
     * @return string|false Request ID or false on failure
     */
    public function queueRequest($requestData) {
        $requestId = $this->generateRequestId();
        
        // Calculate input tokens
        $tokenCounter = new TokenCounter();
        $inputTokens = $tokenCounter->countTokens($requestData['messages'] ?? []);
        
        // Determine priority based on user subscription
        $priority = $this->calculatePriority($requestData['user_tier'] ?? 'free');
        
        // Encrypt sensitive content
        $encryptedContent = $this->encryptContent(json_encode($requestData['messages'] ?? []));
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_requests (
                    request_id, user_id, model_requested, request_content, 
                    request_hash, priority, stream_mode, input_tokens
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $requestHash = hash('sha256', json_encode($requestData['messages'] ?? []));
            
            $stmt->execute([
                $requestId,
                $requestData['user_id'],
                $requestData['model'],
                $encryptedContent,
                $requestHash,
                $priority,
                $requestData['stream'] ?? true,
                $inputTokens
            ]);
            
            $this->logSystemEvent('info', "Request queued", [
                'request_id' => $requestId,
                'user_id' => $requestData['user_id'],
                'model' => $requestData['model'],
                'input_tokens' => $inputTokens
            ]);
            
            return $requestId;
        } catch (PDOException $e) {
            error_log("Failed to queue request: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get next request for processing by browser client
     * 
     * @param string $browserSessionId Browser session identifier
     * @return array|null Request data or null if no requests available
     */
    public function getNextRequest($browserSessionId) {
        try {
            $this->db->beginTransaction();
            
            // Find highest priority queued request
            $stmt = $this->db->prepare("
                SELECT request_id, user_id, model_requested, request_content, 
                       stream_mode, input_tokens, priority, created_at
                FROM api_requests 
                WHERE status = 'queued' 
                ORDER BY priority DESC, created_at ASC 
                LIMIT 1 
                FOR UPDATE
            ");
            $stmt->execute();
            $request = $stmt->fetch();
            
            if (!$request) {
                $this->db->rollBack();
                return null;
            }
            
            // Assign request to browser session
            $stmt = $this->db->prepare("
                UPDATE api_requests 
                SET status = 'processing', 
                    assigned_browser_session = ?, 
                    started_at = CURRENT_TIMESTAMP 
                WHERE request_id = ?
            ");
            $stmt->execute([$browserSessionId, $request['request_id']]);
            
            // Update browser session load
            $stmt = $this->db->prepare("
                UPDATE browser_sessions 
                SET current_load = current_load + 1,
                    last_heartbeat = CURRENT_TIMESTAMP
                WHERE session_id = ?
            ");
            $stmt->execute([$browserSessionId]);
            
            $this->db->commit();
            
            // Decrypt content for processing
            $request['request_content'] = $this->decryptContent($request['request_content']);
            
            $this->logSystemEvent('info', "Request assigned to browser", [
                'request_id' => $request['request_id'],
                'browser_session' => $browserSessionId
            ]);
            
            return $request;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Failed to get next request: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Store response chunk for streaming
     * 
     * @param string $requestId Request identifier
     * @param string $content Response content
     * @param int $sequence Chunk sequence number
     * @param string $type Chunk type (data, error, done)
     * @return bool Success status
     */
    public function storeResponseChunk($requestId, $content, $sequence, $type = 'data') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO request_responses (request_id, chunk_sequence, response_content, chunk_type)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$requestId, $sequence, $content, $type]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Failed to store response chunk: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Complete a request
     * 
     * @param string $requestId Request identifier
     * @param int $outputTokens Number of output tokens
     * @param string $status Final status (completed, failed, timeout)
     * @param string $errorMessage Error message if failed
     * @return bool Success status
     */
    public function completeRequest($requestId, $outputTokens = 0, $status = 'completed', $errorMessage = null) {
        try {
            $this->db->beginTransaction();
            
            // Update request status
            $stmt = $this->db->prepare("
                UPDATE api_requests 
                SET status = ?, 
                    output_tokens = ?, 
                    completed_at = CURRENT_TIMESTAMP,
                    error_message = ?
                WHERE request_id = ?
            ");
            $stmt->execute([$status, $outputTokens, $errorMessage, $requestId]);
            
            // Get request details for analytics
            $stmt = $this->db->prepare("
                SELECT user_id, model_requested, input_tokens, assigned_browser_session,
                       TIMESTAMPDIFF(MICROSECOND, started_at, CURRENT_TIMESTAMP) / 1000 as response_time_ms
                FROM api_requests 
                WHERE request_id = ?
            ");
            $stmt->execute([$requestId]);
            $requestData = $stmt->fetch();
            
            if ($requestData) {
                // Update browser session load
                if ($requestData['assigned_browser_session']) {
                    $stmt = $this->db->prepare("
                        UPDATE browser_sessions 
                        SET current_load = GREATEST(0, current_load - 1),
                            last_heartbeat = CURRENT_TIMESTAMP
                        WHERE session_id = ?
                    ");
                    $stmt->execute([$requestData['assigned_browser_session']]);
                }
                
                // Record usage analytics
                $totalTokens = $requestData['input_tokens'] + $outputTokens;
                $estimatedCost = $this->calculateCost($requestData['model_requested'], $totalTokens);
                
                $stmt = $this->db->prepare("
                    INSERT INTO usage_analytics (
                        user_id, request_id, model_used, input_tokens, output_tokens,
                        estimated_cost, response_time_ms, date_used, hour_used
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), HOUR(NOW()))
                ");
                $stmt->execute([
                    $requestData['user_id'],
                    $requestId,
                    $requestData['model_requested'],
                    $requestData['input_tokens'],
                    $outputTokens,
                    $estimatedCost,
                    $requestData['response_time_ms']
                ]);
                
                // Update user total tokens
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET total_tokens_used = total_tokens_used + ?
                    WHERE id = ?
                ");
                $stmt->execute([$totalTokens, $requestData['user_id']]);
            }
            
            $this->db->commit();
            
            $this->logSystemEvent('info', "Request completed", [
                'request_id' => $requestId,
                'status' => $status,
                'output_tokens' => $outputTokens
            ]);
            
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Failed to complete request: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get response chunks for streaming
     * 
     * @param string $requestId Request identifier
     * @param int $fromSequence Start from sequence number
     * @return array Response chunks
     */
    public function getResponseChunks($requestId, $fromSequence = 0) {
        try {
            $stmt = $this->db->prepare("
                SELECT chunk_sequence, response_content, chunk_type, created_at
                FROM request_responses 
                WHERE request_id = ? AND chunk_sequence > ?
                ORDER BY chunk_sequence ASC
            ");
            $stmt->execute([$requestId, $fromSequence]);
            
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get response chunks: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get request status
     * 
     * @param string $requestId Request identifier
     * @return array|null Request status information
     */
    public function getRequestStatus($requestId) {
        try {
            $stmt = $this->db->prepare("
                SELECT status, created_at, started_at, completed_at, 
                       assigned_browser_session, error_message
                FROM api_requests 
                WHERE request_id = ?
            ");
            $stmt->execute([$requestId]);
            
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Failed to get request status: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Clean up timed out requests
     * 
     * @param int $timeoutSeconds Timeout in seconds
     * @return int Number of cleaned up requests
     */
    public function cleanupTimedOutRequests($timeoutSeconds = 300) {
        try {
            $stmt = $this->db->prepare("
                UPDATE api_requests 
                SET status = 'timeout',
                    completed_at = CURRENT_TIMESTAMP,
                    error_message = 'Request timed out'
                WHERE status = 'processing' 
                AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$timeoutSeconds]);
            
            $cleanedUp = $stmt->rowCount();
            
            if ($cleanedUp > 0) {
                $this->logSystemEvent('warning', "Cleaned up timed out requests", [
                    'count' => $cleanedUp,
                    'timeout_seconds' => $timeoutSeconds
                ]);
            }
            
            return $cleanedUp;
        } catch (PDOException $e) {
            error_log("Failed to cleanup timed out requests: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Generate unique request ID
     * 
     * @return string Request ID
     */
    private function generateRequestId() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Calculate request priority based on user tier
     * 
     * @param string $userTier User subscription tier
     * @return int Priority value (higher = more priority)
     */
    private function calculatePriority($userTier) {
        $priorities = [
            'enterprise' => 100,
            'premium' => 50,
            'free' => 10
        ];
        
        return $priorities[$userTier] ?? 10;
    }
    
    /**
     * Encrypt sensitive content
     * 
     * @param string $content Content to encrypt
     * @return string Encrypted content
     */
    private function encryptContent($content) {
        // Simple base64 encoding for now - implement proper encryption in production
        return base64_encode($content);
    }
    
    /**
     * Decrypt sensitive content
     * 
     * @param string $encryptedContent Encrypted content
     * @return string Decrypted content
     */
    private function decryptContent($encryptedContent) {
        // Simple base64 decoding for now - implement proper decryption in production
        return base64_decode($encryptedContent);
    }
    
    /**
     * Calculate estimated cost for request
     * 
     * @param string $model Model name
     * @param int $totalTokens Total tokens used
     * @return float Estimated cost
     */
    private function calculateCost($model, $totalTokens) {
        // Simplified cost calculation - customize based on your pricing model
        $costPerToken = 0.00001; // $0.01 per 1000 tokens
        return $totalTokens * $costPerToken;
    }
    
    /**
     * Log system event
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function logSystemEvent($level, $message, $context = []) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO system_logs (level, message, context, user_id, request_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $level,
                $message,
                json_encode($context),
                $context['user_id'] ?? null,
                $context['request_id'] ?? null
            ]);
        } catch (PDOException $e) {
            error_log("Failed to log system event: " . $e->getMessage());
        }
    }
}
