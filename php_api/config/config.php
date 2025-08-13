<?php
/**
 * Main Configuration File for LMArena Bridge API
 * 
 * Central configuration management for the distributed API system.
 * Modify these settings according to your deployment environment.
 */

// Prevent direct access
if (!defined('LMARENA_BRIDGE_CONFIG')) {
    define('LMARENA_BRIDGE_CONFIG', true);
}

return [
    // Database Configuration
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'name' => $_ENV['DB_NAME'] ?? 'lmarena_bridge',
        'username' => $_ENV['DB_USER'] ?? 'your_db_user',
        'password' => $_ENV['DB_PASS'] ?? 'your_db_password',
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    ],

    // API Configuration
    'api' => [
        'version' => '1.0.0',
        'base_url' => $_ENV['API_BASE_URL'] ?? 'https://your-domain.com',
        'rate_limiting' => [
            'enabled' => true,
            'cleanup_interval' => 3600, // 1 hour
        ],
        'request_timeout' => 300, // 5 minutes
        'max_request_size' => 10485760, // 10MB
    ],

    // Security Configuration
    'security' => [
        'api_key_length' => 64,
        'hash_algorithm' => 'sha256',
        'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? 'your-encryption-key-here',
        'cors' => [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization'],
        ]
    ],

    // Subscription Tiers and Rate Limits
    'subscription_tiers' => [
        'free' => [
            'requests_per_minute' => 10,
            'requests_per_hour' => 100,
            'requests_per_day' => 1000,
            'max_tokens_per_request' => 4096,
            'priority_level' => 10,
            'features' => ['basic_chat', 'standard_models']
        ],
        'premium' => [
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000,
            'requests_per_day' => 10000,
            'max_tokens_per_request' => 16384,
            'priority_level' => 50,
            'features' => ['basic_chat', 'standard_models', 'premium_models', 'priority_queue']
        ],
        'enterprise' => [
            'requests_per_minute' => 300,
            'requests_per_hour' => 5000,
            'requests_per_day' => 50000,
            'max_tokens_per_request' => 32768,
            'priority_level' => 100,
            'features' => ['basic_chat', 'standard_models', 'premium_models', 'priority_queue', 'admin_access', 'custom_models']
        ]
    ],

    // Browser Client Configuration
    'browser_clients' => [
        'session_timeout' => 300, // 5 minutes
        'heartbeat_interval' => 30, // 30 seconds
        'max_concurrent_per_client' => 10,
        'load_balancing' => [
            'algorithm' => 'weighted_round_robin', // round_robin, weighted_round_robin, least_connections
            'health_check_interval' => 60, // 1 minute
            'failure_threshold' => 3,
            'recovery_time' => 300 // 5 minutes
        ]
    ],

    // Model Configuration
    'models' => [
        'default_context_length' => 4096,
        'pricing' => [
            'default' => ['input' => 0.01, 'output' => 0.02], // per 1K tokens
            'gpt-4' => ['input' => 0.03, 'output' => 0.06],
            'gpt-3.5-turbo' => ['input' => 0.001, 'output' => 0.002],
            'claude-3-opus' => ['input' => 0.015, 'output' => 0.075],
            'claude-3-sonnet' => ['input' => 0.003, 'output' => 0.015],
            'claude-3-haiku' => ['input' => 0.00025, 'output' => 0.00125],
        ],
        'context_limits' => [
            'gpt-4' => 8192,
            'gpt-4-32k' => 32768,
            'gpt-3.5-turbo' => 4096,
            'gpt-3.5-turbo-16k' => 16384,
            'claude-3-opus' => 200000,
            'claude-3-sonnet' => 200000,
            'claude-3-haiku' => 200000,
        ]
    ],

    // Logging Configuration
    'logging' => [
        'level' => $_ENV['LOG_LEVEL'] ?? 'info', // debug, info, warning, error, critical
        'file' => $_ENV['LOG_FILE'] ?? '/tmp/lmarena_bridge.log',
        'max_file_size' => 10485760, // 10MB
        'retention_days' => 30,
        'include_request_bodies' => false, // For privacy
    ],

    // Cache Configuration
    'cache' => [
        'enabled' => true,
        'type' => 'file', // file, redis, memcached
        'ttl' => [
            'user_data' => 300, // 5 minutes
            'rate_limits' => 60, // 1 minute
            'model_list' => 3600, // 1 hour
            'system_stats' => 30, // 30 seconds
        ],
        'file_cache_dir' => $_ENV['CACHE_DIR'] ?? '/tmp/lmarena_cache',
    ],

    // Monitoring and Analytics
    'monitoring' => [
        'enabled' => true,
        'metrics_retention_days' => 90,
        'alert_thresholds' => [
            'error_rate' => 5, // percentage
            'response_time' => 5000, // milliseconds
            'queue_length' => 100,
            'browser_client_failures' => 3,
        ],
        'webhook_url' => $_ENV['WEBHOOK_URL'] ?? null,
    ],

    // Maintenance Configuration
    'maintenance' => [
        'cleanup_interval' => 3600, // 1 hour
        'data_retention' => [
            'completed_requests' => 7, // days
            'failed_requests' => 30, // days
            'rate_limit_records' => 2, // days
            'system_logs' => 30, // days
            'usage_analytics' => 365, // days
        ],
        'auto_optimize_tables' => true,
    ],

    // Feature Flags
    'features' => [
        'streaming_responses' => true,
        'multimodal_support' => true,
        'conversation_history' => true,
        'usage_analytics' => true,
        'rate_limiting' => true,
        'load_balancing' => true,
        'auto_scaling' => false, // Future feature
        'geographic_routing' => false, // Future feature
    ],

    // Development/Debug Configuration
    'debug' => [
        'enabled' => $_ENV['DEBUG'] ?? false,
        'show_sql_queries' => false,
        'log_all_requests' => false,
        'simulate_delays' => false,
        'mock_browser_clients' => false,
    ],

    // Integration Configuration
    'integrations' => [
        'original_lmarena_bridge' => [
            'enabled' => true,
            'models_json_path' => __DIR__ . '/../../models.json',
            'config_json_path' => __DIR__ . '/../../config.jsonc',
            'sync_models' => true,
        ],
        'external_apis' => [
            'openai_compatibility_check' => true,
            'model_availability_check' => true,
        ]
    ],

    // Deployment Configuration
    'deployment' => [
        'environment' => $_ENV['ENVIRONMENT'] ?? 'production', // development, staging, production
        'timezone' => $_ENV['TIMEZONE'] ?? 'UTC',
        'max_execution_time' => 300,
        'memory_limit' => '256M',
        'upload_max_filesize' => '10M',
    ]
];
?>
