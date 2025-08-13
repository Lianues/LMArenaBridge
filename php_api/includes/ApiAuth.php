<?php
/**
 * API Authentication and Authorization Handler
 * 
 * Handles API key validation, user authentication, and authorization
 * for the LMArena Bridge distributed API system.
 */

require_once __DIR__ . '/../config/database.php';

class ApiAuth {
    private $db;
    private $currentUser = null;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Authenticate API request using Bearer token
     * 
     * @param string $authHeader Authorization header value
     * @return array|false User data or false if authentication fails
     */
    public function authenticate($authHeader) {
        if (!$authHeader || !preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            return false;
        }
        
        $apiKey = trim($matches[1]);
        $apiKeyHash = hash('sha256', $apiKey);
        
        try {
            $stmt = $this->db->prepare("
                SELECT id, email, subscription_tier, requests_per_minute, 
                       requests_per_hour, requests_per_day, total_tokens_used,
                       is_active, created_at
                FROM users 
                WHERE api_key_hash = ? AND is_active = TRUE
            ");
            $stmt->execute([$apiKeyHash]);
            
            $user = $stmt->fetch();
            if ($user) {
                $this->currentUser = $user;
                return $user;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Authentication error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate a new API key for a user
     * 
     * @param string $email User email
     * @param string $subscriptionTier Subscription tier (free, premium, enterprise)
     * @return string|false Generated API key or false on failure
     */
    public function generateApiKey($email, $subscriptionTier = 'free') {
        $apiKey = 'lma_' . bin2hex(random_bytes(32));
        $apiKeyHash = hash('sha256', $apiKey);
        
        // Set rate limits based on subscription tier
        $rateLimits = $this->getRateLimitsForTier($subscriptionTier);
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO users (api_key_hash, email, subscription_tier, 
                                 requests_per_minute, requests_per_hour, requests_per_day)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                api_key_hash = VALUES(api_key_hash),
                subscription_tier = VALUES(subscription_tier),
                requests_per_minute = VALUES(requests_per_minute),
                requests_per_hour = VALUES(requests_per_hour),
                requests_per_day = VALUES(requests_per_day),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $apiKeyHash, 
                $email, 
                $subscriptionTier,
                $rateLimits['minute'],
                $rateLimits['hour'],
                $rateLimits['day']
            ]);
            
            return $apiKey;
        } catch (PDOException $e) {
            error_log("API key generation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get rate limits for subscription tier
     * 
     * @param string $tier Subscription tier
     * @return array Rate limits
     */
    private function getRateLimitsForTier($tier) {
        $limits = [
            'free' => ['minute' => 10, 'hour' => 100, 'day' => 1000],
            'premium' => ['minute' => 60, 'hour' => 1000, 'day' => 10000],
            'enterprise' => ['minute' => 300, 'hour' => 5000, 'day' => 50000]
        ];
        
        return $limits[$tier] ?? $limits['free'];
    }
    
    /**
     * Check if user has permission for specific action
     * 
     * @param string $action Action to check
     * @param array $context Additional context
     * @return bool Permission granted
     */
    public function hasPermission($action, $context = []) {
        if (!$this->currentUser) {
            return false;
        }
        
        switch ($action) {
            case 'chat_completion':
                return $this->checkRateLimit();
            case 'admin_access':
                return $this->currentUser['subscription_tier'] === 'enterprise';
            case 'priority_queue':
                return in_array($this->currentUser['subscription_tier'], ['premium', 'enterprise']);
            default:
                return true;
        }
    }
    
    /**
     * Check rate limits for current user
     * 
     * @return bool Rate limit check passed
     */
    public function checkRateLimit() {
        if (!$this->currentUser) {
            return false;
        }
        
        $userId = $this->currentUser['id'];
        $windows = ['minute', 'hour', 'day'];
        
        try {
            $this->db->beginTransaction();
            
            foreach ($windows as $window) {
                $windowStart = $this->getWindowStart($window);
                $limit = $this->currentUser["requests_per_{$window}"];
                
                // Get or create rate limit record
                $stmt = $this->db->prepare("
                    INSERT INTO rate_limits (user_id, time_window, window_start, request_count)
                    VALUES (?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE 
                    request_count = request_count + 1
                ");
                $stmt->execute([$userId, $window, $windowStart]);
                
                // Check if limit exceeded
                $stmt = $this->db->prepare("
                    SELECT request_count FROM rate_limits 
                    WHERE user_id = ? AND time_window = ? AND window_start = ?
                ");
                $stmt->execute([$userId, $window, $windowStart]);
                $count = $stmt->fetchColumn();
                
                if ($count > $limit) {
                    $this->db->rollBack();
                    return false;
                }
            }
            
            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Rate limit check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get window start time for rate limiting
     * 
     * @param string $window Time window (minute, hour, day)
     * @return string Window start timestamp
     */
    private function getWindowStart($window) {
        $now = new DateTime();
        
        switch ($window) {
            case 'minute':
                return $now->format('Y-m-d H:i:00');
            case 'hour':
                return $now->format('Y-m-d H:00:00');
            case 'day':
                return $now->format('Y-m-d 00:00:00');
            default:
                return $now->format('Y-m-d H:i:s');
        }
    }
    
    /**
     * Get current authenticated user
     * 
     * @return array|null User data
     */
    public function getCurrentUser() {
        return $this->currentUser;
    }
    
    /**
     * Get rate limit status for current user
     * 
     * @return array Rate limit information
     */
    public function getRateLimitStatus() {
        if (!$this->currentUser) {
            return null;
        }
        
        $userId = $this->currentUser['id'];
        $status = [];
        
        try {
            foreach (['minute', 'hour', 'day'] as $window) {
                $windowStart = $this->getWindowStart($window);
                $limit = $this->currentUser["requests_per_{$window}"];
                
                $stmt = $this->db->prepare("
                    SELECT COALESCE(request_count, 0) as count 
                    FROM rate_limits 
                    WHERE user_id = ? AND time_window = ? AND window_start = ?
                ");
                $stmt->execute([$userId, $window, $windowStart]);
                $count = $stmt->fetchColumn() ?: 0;
                
                $status[$window] = [
                    'limit' => $limit,
                    'used' => $count,
                    'remaining' => max(0, $limit - $count),
                    'reset_time' => $this->getWindowReset($window)
                ];
            }
            
            return $status;
        } catch (PDOException $e) {
            error_log("Rate limit status error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get window reset time
     * 
     * @param string $window Time window
     * @return string Reset timestamp
     */
    private function getWindowReset($window) {
        $now = new DateTime();
        
        switch ($window) {
            case 'minute':
                $now->add(new DateInterval('PT1M'));
                return $now->format('Y-m-d H:i:00');
            case 'hour':
                $now->add(new DateInterval('PT1H'));
                return $now->format('Y-m-d H:00:00');
            case 'day':
                $now->add(new DateInterval('P1D'));
                return $now->format('Y-m-d 00:00:00');
            default:
                return $now->format('Y-m-d H:i:s');
        }
    }
}
