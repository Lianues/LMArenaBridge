<?php
/**
 * Database Configuration for LMArena Bridge API
 * 
 * This file contains database connection settings and initialization
 * for the cPanel-compatible distributed API system.
 */

class Database {
    private static $instance = null;
    private $connection;
    
    // Database configuration - modify these for your cPanel setup
    private $host = 'localhost';
    private $database = 'lmarena_bridge';
    private $username = 'your_db_user';
    private $password = 'your_db_password';
    private $charset = 'utf8mb4';
    
    private function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        try {
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Initialize database tables if they don't exist
     */
    public function initializeTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            api_key_hash VARCHAR(255) UNIQUE NOT NULL,
            email VARCHAR(255) UNIQUE,
            subscription_tier ENUM('free', 'premium', 'enterprise') DEFAULT 'free',
            requests_per_minute INT DEFAULT 10,
            requests_per_hour INT DEFAULT 100,
            requests_per_day INT DEFAULT 1000,
            total_tokens_used BIGINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            INDEX idx_api_key (api_key_hash),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS api_requests (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) UNIQUE NOT NULL,
            user_id INT NOT NULL,
            model_requested VARCHAR(255) NOT NULL,
            request_content TEXT,
            request_hash VARCHAR(64),
            status ENUM('queued', 'processing', 'completed', 'failed', 'timeout') DEFAULT 'queued',
            assigned_browser_session VARCHAR(255),
            priority INT DEFAULT 0,
            stream_mode BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            input_tokens INT DEFAULT 0,
            output_tokens INT DEFAULT 0,
            estimated_cost DECIMAL(10,6) DEFAULT 0,
            error_message TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_request_id (request_id),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            INDEX idx_assigned_browser (assigned_browser_session)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS browser_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(255) UNIQUE NOT NULL,
            client_identifier VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            capabilities JSON,
            current_load INT DEFAULT 0,
            max_concurrent_requests INT DEFAULT 5,
            average_response_time DECIMAL(8,3) DEFAULT 0,
            success_rate DECIMAL(5,2) DEFAULT 100.00,
            last_heartbeat TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            geographic_location VARCHAR(100),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_id (session_id),
            INDEX idx_active (is_active),
            INDEX idx_heartbeat (last_heartbeat)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS request_responses (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(36) NOT NULL,
            chunk_sequence INT NOT NULL,
            response_content TEXT,
            chunk_type ENUM('data', 'error', 'done') DEFAULT 'data',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES api_requests(request_id) ON DELETE CASCADE,
            INDEX idx_request_id (request_id),
            INDEX idx_sequence (request_id, chunk_sequence)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS rate_limits (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            time_window ENUM('minute', 'hour', 'day') NOT NULL,
            window_start TIMESTAMP NOT NULL,
            request_count INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_window (user_id, time_window, window_start),
            INDEX idx_user_window (user_id, time_window),
            INDEX idx_window_start (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS usage_analytics (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_id VARCHAR(36),
            model_used VARCHAR(255),
            input_tokens INT DEFAULT 0,
            output_tokens INT DEFAULT 0,
            total_tokens INT GENERATED ALWAYS AS (input_tokens + output_tokens) STORED,
            estimated_cost DECIMAL(10,6) DEFAULT 0,
            response_time_ms INT,
            date_used DATE NOT NULL,
            hour_used TINYINT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_date (user_id, date_used),
            INDEX idx_model (model_used),
            INDEX idx_date (date_used)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS system_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            level ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL,
            message TEXT NOT NULL,
            context JSON,
            user_id INT,
            request_id VARCHAR(36),
            browser_session VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_level (level),
            INDEX idx_created_at (created_at),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        try {
            $this->connection->exec($sql);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to initialize tables: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean up old data to maintain performance
     */
    public function cleanupOldData() {
        $queries = [
            // Remove rate limit records older than 2 days
            "DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)",
            
            // Remove completed request responses older than 7 days
            "DELETE rr FROM request_responses rr 
             JOIN api_requests ar ON rr.request_id = ar.request_id 
             WHERE ar.status = 'completed' AND ar.completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            
            // Remove old system logs (keep only last 30 days)
            "DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            
            // Update inactive browser sessions
            "UPDATE browser_sessions SET is_active = FALSE 
             WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        ];
        
        foreach ($queries as $query) {
            try {
                $this->connection->exec($query);
            } catch (PDOException $e) {
                error_log("Cleanup query failed: " . $e->getMessage());
            }
        }
    }
}
