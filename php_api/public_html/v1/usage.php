<?php
/**
 * Usage Statistics Endpoint
 * 
 * Returns user usage statistics and analytics for the
 * LMArena Bridge distributed API system.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => ['message' => 'Method not allowed', 'type' => 'invalid_request_error']]);
    exit;
}

require_once __DIR__ . '/../../includes/ApiAuth.php';
require_once __DIR__ . '/../../config/database.php';

try {
    // Initialize components
    $auth = new ApiAuth();
    $db = Database::getInstance()->getConnection();
    
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
    
    $userId = $user['id'];
    $timeRange = $_GET['range'] ?? 'day'; // day, week, month, all
    $includeDetails = isset($_GET['details']) && $_GET['details'] === 'true';
    
    // Get usage statistics
    $usage = getUserUsageStatistics($db, $userId, $timeRange, $includeDetails);
    
    // Get rate limit status
    $rateLimitStatus = $auth->getRateLimitStatus();
    
    // Prepare response
    $response = [
        'user_id' => $userId,
        'subscription_tier' => $user['subscription_tier'],
        'time_range' => $timeRange,
        'usage' => $usage,
        'rate_limits' => $rateLimitStatus,
        'account_info' => [
            'total_tokens_used' => (int)$user['total_tokens_used'],
            'account_created' => $user['created_at'],
            'is_active' => (bool)$user['is_active']
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Usage endpoint error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => [
            'message' => 'Internal server error',
            'type' => 'internal_server_error'
        ]
    ]);
}

/**
 * Get user usage statistics
 */
function getUserUsageStatistics($db, $userId, $timeRange, $includeDetails) {
    $whereClause = "WHERE user_id = ?";
    $params = [$userId];
    
    // Add time range filter
    switch ($timeRange) {
        case 'day':
            $whereClause .= " AND date_used = CURDATE()";
            break;
        case 'week':
            $whereClause .= " AND date_used >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $whereClause .= " AND date_used >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
        case 'all':
            // No additional filter
            break;
        default:
            $whereClause .= " AND date_used = CURDATE()";
            $timeRange = 'day';
    }
    
    try {
        // Get summary statistics
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(total_tokens) as total_tokens,
                SUM(estimated_cost) as total_cost,
                AVG(response_time_ms) as avg_response_time,
                COUNT(DISTINCT model_used) as unique_models_used,
                COUNT(DISTINCT date_used) as active_days
            FROM usage_analytics 
            {$whereClause}
        ");
        $stmt->execute($params);
        $summary = $stmt->fetch();
        
        // Get model breakdown
        $stmt = $db->prepare("
            SELECT 
                model_used,
                COUNT(*) as request_count,
                SUM(input_tokens) as input_tokens,
                SUM(output_tokens) as output_tokens,
                SUM(total_tokens) as total_tokens,
                SUM(estimated_cost) as cost,
                AVG(response_time_ms) as avg_response_time
            FROM usage_analytics 
            {$whereClause}
            GROUP BY model_used
            ORDER BY total_tokens DESC
        ");
        $stmt->execute($params);
        $modelBreakdown = $stmt->fetchAll();
        
        // Get daily usage (for trends)
        $stmt = $db->prepare("
            SELECT 
                date_used,
                COUNT(*) as requests,
                SUM(total_tokens) as tokens,
                SUM(estimated_cost) as cost
            FROM usage_analytics 
            {$whereClause}
            GROUP BY date_used
            ORDER BY date_used DESC
            LIMIT 30
        ");
        $stmt->execute($params);
        $dailyUsage = $stmt->fetchAll();
        
        // Get hourly usage for current day (if day range)
        $hourlyUsage = [];
        if ($timeRange === 'day') {
            $stmt = $db->prepare("
                SELECT 
                    hour_used,
                    COUNT(*) as requests,
                    SUM(total_tokens) as tokens,
                    SUM(estimated_cost) as cost
                FROM usage_analytics 
                WHERE user_id = ? AND date_used = CURDATE()
                GROUP BY hour_used
                ORDER BY hour_used
            ");
            $stmt->execute([$userId]);
            $hourlyUsage = $stmt->fetchAll();
        }
        
        $usage = [
            'summary' => [
                'total_requests' => (int)($summary['total_requests'] ?? 0),
                'total_input_tokens' => (int)($summary['total_input_tokens'] ?? 0),
                'total_output_tokens' => (int)($summary['total_output_tokens'] ?? 0),
                'total_tokens' => (int)($summary['total_tokens'] ?? 0),
                'total_cost' => (float)($summary['total_cost'] ?? 0),
                'average_response_time_ms' => (float)($summary['avg_response_time'] ?? 0),
                'unique_models_used' => (int)($summary['unique_models_used'] ?? 0),
                'active_days' => (int)($summary['active_days'] ?? 0)
            ],
            'by_model' => array_map(function($model) {
                return [
                    'model' => $model['model_used'],
                    'requests' => (int)$model['request_count'],
                    'input_tokens' => (int)$model['input_tokens'],
                    'output_tokens' => (int)$model['output_tokens'],
                    'total_tokens' => (int)$model['total_tokens'],
                    'cost' => (float)$model['cost'],
                    'avg_response_time_ms' => (float)$model['avg_response_time']
                ];
            }, $modelBreakdown),
            'daily_usage' => array_map(function($day) {
                return [
                    'date' => $day['date_used'],
                    'requests' => (int)$day['requests'],
                    'tokens' => (int)$day['tokens'],
                    'cost' => (float)$day['cost']
                ];
            }, $dailyUsage)
        ];
        
        if ($timeRange === 'day' && !empty($hourlyUsage)) {
            $usage['hourly_usage'] = array_map(function($hour) {
                return [
                    'hour' => (int)$hour['hour_used'],
                    'requests' => (int)$hour['requests'],
                    'tokens' => (int)$hour['tokens'],
                    'cost' => (float)$hour['cost']
                ];
            }, $hourlyUsage);
        }
        
        // Add detailed request history if requested
        if ($includeDetails) {
            $stmt = $db->prepare("
                SELECT 
                    ua.request_id,
                    ua.model_used,
                    ua.input_tokens,
                    ua.output_tokens,
                    ua.total_tokens,
                    ua.estimated_cost,
                    ua.response_time_ms,
                    ua.created_at,
                    ar.status
                FROM usage_analytics ua
                LEFT JOIN api_requests ar ON ua.request_id = ar.request_id
                {$whereClause}
                ORDER BY ua.created_at DESC
                LIMIT 100
            ");
            $stmt->execute($params);
            $requestHistory = $stmt->fetchAll();
            
            $usage['request_history'] = array_map(function($request) {
                return [
                    'request_id' => $request['request_id'],
                    'model' => $request['model_used'],
                    'input_tokens' => (int)$request['input_tokens'],
                    'output_tokens' => (int)$request['output_tokens'],
                    'total_tokens' => (int)$request['total_tokens'],
                    'cost' => (float)$request['estimated_cost'],
                    'response_time_ms' => (int)$request['response_time_ms'],
                    'status' => $request['status'],
                    'timestamp' => $request['created_at']
                ];
            }, $requestHistory);
        }
        
        return $usage;
        
    } catch (PDOException $e) {
        error_log("Failed to get usage statistics: " . $e->getMessage());
        return [
            'summary' => [
                'total_requests' => 0,
                'total_tokens' => 0,
                'total_cost' => 0
            ],
            'by_model' => [],
            'daily_usage' => []
        ];
    }
}
?>
