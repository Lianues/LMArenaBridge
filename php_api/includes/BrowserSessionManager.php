<?php
/**
 * Browser Session Manager
 * 
 * Manages distributed browser clients, load balancing, and session health
 * monitoring for the LMArena Bridge API system.
 */

require_once __DIR__ . '/../config/database.php';

class BrowserSessionManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Register a new browser session
     * 
     * @param array $sessionData Session registration data
     * @return string|false Session ID or false on failure
     */
    public function registerSession($sessionData) {
        $sessionId = $this->generateSessionId();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO browser_sessions (
                    session_id, client_identifier, ip_address, user_agent,
                    capabilities, max_concurrent_requests, geographic_location
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                capabilities = VALUES(capabilities),
                max_concurrent_requests = VALUES(max_concurrent_requests),
                geographic_location = VALUES(geographic_location),
                is_active = TRUE,
                last_heartbeat = CURRENT_TIMESTAMP
            ");
            
            $capabilities = json_encode($sessionData['capabilities'] ?? []);
            
            $stmt->execute([
                $sessionId,
                $sessionData['client_identifier'],
                $sessionData['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $sessionData['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $capabilities,
                $sessionData['max_concurrent_requests'] ?? 5,
                $sessionData['geographic_location'] ?? 'unknown'
            ]);
            
            $this->logSystemEvent('info', "Browser session registered", [
                'session_id' => $sessionId,
                'client_identifier' => $sessionData['client_identifier']
            ]);
            
            return $sessionId;
        } catch (PDOException $e) {
            error_log("Failed to register browser session: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update session heartbeat and performance metrics
     * 
     * @param string $sessionId Session identifier
     * @param array $metrics Performance metrics
     * @return bool Success status
     */
    public function updateHeartbeat($sessionId, $metrics = []) {
        try {
            $stmt = $this->db->prepare("
                UPDATE browser_sessions 
                SET last_heartbeat = CURRENT_TIMESTAMP,
                    average_response_time = COALESCE(?, average_response_time),
                    success_rate = COALESCE(?, success_rate),
                    current_load = COALESCE(?, current_load)
                WHERE session_id = ?
            ");
            
            $stmt->execute([
                $metrics['average_response_time'] ?? null,
                $metrics['success_rate'] ?? null,
                $metrics['current_load'] ?? null,
                $sessionId
            ]);
            
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Failed to update heartbeat: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get best available browser session for request
     * 
     * @param array $requirements Request requirements
     * @return array|null Best session or null if none available
     */
    public function getBestSession($requirements = []) {
        try {
            // Build query based on requirements
            $whereConditions = ["is_active = TRUE", "last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"];
            $params = [];
            
            // Check for model-specific requirements
            if (isset($requirements['model_type'])) {
                $whereConditions[] = "JSON_EXTRACT(capabilities, '$.supported_models') LIKE ?";
                $params[] = '%' . $requirements['model_type'] . '%';
            }
            
            // Check for geographic preferences
            if (isset($requirements['preferred_location'])) {
                $whereConditions[] = "geographic_location = ?";
                $params[] = $requirements['preferred_location'];
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            $stmt = $this->db->prepare("
                SELECT session_id, client_identifier, current_load, max_concurrent_requests,
                       average_response_time, success_rate, geographic_location,
                       (max_concurrent_requests - current_load) as available_slots,
                       (success_rate * 0.4 + (100 - average_response_time/10) * 0.3 + 
                        (max_concurrent_requests - current_load) * 0.3) as score
                FROM browser_sessions 
                WHERE {$whereClause}
                AND current_load < max_concurrent_requests
                ORDER BY score DESC, current_load ASC
                LIMIT 1
            ");
            
            $stmt->execute($params);
            $session = $stmt->fetch();
            
            if ($session) {
                $this->logSystemEvent('debug', "Selected browser session", [
                    'session_id' => $session['session_id'],
                    'score' => $session['score'],
                    'current_load' => $session['current_load']
                ]);
            }
            
            return $session;
        } catch (PDOException $e) {
            error_log("Failed to get best session: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all active browser sessions
     * 
     * @return array Active sessions
     */
    public function getActiveSessions() {
        try {
            $stmt = $this->db->prepare("
                SELECT session_id, client_identifier, ip_address, current_load,
                       max_concurrent_requests, average_response_time, success_rate,
                       geographic_location, last_heartbeat, created_at
                FROM browser_sessions 
                WHERE is_active = TRUE 
                AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER BY last_heartbeat DESC
            ");
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get active sessions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Deactivate inactive sessions
     * 
     * @param int $timeoutMinutes Timeout in minutes
     * @return int Number of deactivated sessions
     */
    public function deactivateInactiveSessions($timeoutMinutes = 5) {
        try {
            $stmt = $this->db->prepare("
                UPDATE browser_sessions 
                SET is_active = FALSE 
                WHERE is_active = TRUE 
                AND last_heartbeat < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$timeoutMinutes]);
            
            $deactivated = $stmt->rowCount();
            
            if ($deactivated > 0) {
                $this->logSystemEvent('warning', "Deactivated inactive browser sessions", [
                    'count' => $deactivated,
                    'timeout_minutes' => $timeoutMinutes
                ]);
            }
            
            return $deactivated;
        } catch (PDOException $e) {
            error_log("Failed to deactivate inactive sessions: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get session statistics
     * 
     * @return array Session statistics
     */
    public function getSessionStatistics() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_sessions,
                    SUM(CASE WHEN is_active = TRUE AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 1 ELSE 0 END) as active_sessions,
                    SUM(current_load) as total_load,
                    SUM(max_concurrent_requests) as total_capacity,
                    AVG(average_response_time) as avg_response_time,
                    AVG(success_rate) as avg_success_rate,
                    COUNT(DISTINCT geographic_location) as unique_locations
                FROM browser_sessions
            ");
            $stmt->execute();
            
            $stats = $stmt->fetch();
            
            // Calculate utilization percentage
            $stats['utilization_percentage'] = $stats['total_capacity'] > 0 
                ? ($stats['total_load'] / $stats['total_capacity']) * 100 
                : 0;
            
            return $stats;
        } catch (PDOException $e) {
            error_log("Failed to get session statistics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update session performance metrics
     * 
     * @param string $sessionId Session identifier
     * @param array $metrics Performance metrics
     * @return bool Success status
     */
    public function updatePerformanceMetrics($sessionId, $metrics) {
        try {
            $stmt = $this->db->prepare("
                UPDATE browser_sessions 
                SET average_response_time = (
                        COALESCE(average_response_time, 0) * 0.8 + ? * 0.2
                    ),
                    success_rate = (
                        COALESCE(success_rate, 100) * 0.9 + ? * 0.1
                    ),
                    last_heartbeat = CURRENT_TIMESTAMP
                WHERE session_id = ?
            ");
            
            $stmt->execute([
                $metrics['response_time'] ?? 0,
                $metrics['success'] ? 100 : 0,
                $sessionId
            ]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Failed to update performance metrics: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get session load balancing information
     * 
     * @return array Load balancing data
     */
    public function getLoadBalancingInfo() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    session_id,
                    client_identifier,
                    current_load,
                    max_concurrent_requests,
                    (max_concurrent_requests - current_load) as available_capacity,
                    average_response_time,
                    success_rate,
                    geographic_location,
                    CASE 
                        WHEN last_heartbeat > DATE_SUB(NOW(), INTERVAL 1 MINUTE) THEN 'excellent'
                        WHEN last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 'good'
                        WHEN last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'poor'
                        ELSE 'offline'
                    END as health_status
                FROM browser_sessions 
                WHERE is_active = TRUE
                ORDER BY 
                    (max_concurrent_requests - current_load) DESC,
                    success_rate DESC,
                    average_response_time ASC
            ");
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Failed to get load balancing info: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Remove session
     * 
     * @param string $sessionId Session identifier
     * @return bool Success status
     */
    public function removeSession($sessionId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE browser_sessions 
                SET is_active = FALSE 
                WHERE session_id = ?
            ");
            $stmt->execute([$sessionId]);
            
            $this->logSystemEvent('info', "Browser session removed", [
                'session_id' => $sessionId
            ]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Failed to remove session: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate unique session ID
     * 
     * @return string Session ID
     */
    private function generateSessionId() {
        return 'bs_' . bin2hex(random_bytes(16));
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
                INSERT INTO system_logs (level, message, context, browser_session)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $level,
                $message,
                json_encode($context),
                $context['session_id'] ?? null
            ]);
        } catch (PDOException $e) {
            error_log("Failed to log system event: " . $e->getMessage());
        }
    }
}
