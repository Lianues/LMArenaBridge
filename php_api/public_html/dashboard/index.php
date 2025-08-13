<?php
/**
 * User Dashboard for LMArena Bridge API
 * 
 * Provides a comprehensive interface for users to monitor their
 * API usage, manage settings, and view analytics.
 */

require_once __DIR__ . '/../../includes/ApiAuth.php';
require_once __DIR__ . '/../../config/database.php';

// Start session for dashboard authentication
session_start();

// Handle login/logout
if (isset($_POST['login'])) {
    $apiKey = $_POST['api_key'] ?? '';
    $auth = new ApiAuth();
    $user = $auth->authenticate("Bearer $apiKey");
    
    if ($user) {
        $_SESSION['user'] = $user;
        $_SESSION['api_key'] = $apiKey;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginError = "Invalid API key";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Check if user is logged in
$user = $_SESSION['user'] ?? null;
$apiKey = $_SESSION['api_key'] ?? '';

if (!$user) {
    // Show login form
    showLoginForm($loginError ?? null);
    exit;
}

// Get user statistics
$db = Database::getInstance()->getConnection();
$stats = getUserDashboardStats($db, $user['id']);
$rateLimits = (new ApiAuth())->getRateLimitStatus();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LMArena Bridge - Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { color: #333; margin-bottom: 10px; }
        .header .user-info { display: flex; justify-content: space-between; align-items: center; }
        .logout-btn { background: #dc3545; color: white; padding: 8px 16px; border: none; border-radius: 4px; text-decoration: none; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { color: #333; margin-bottom: 15px; font-size: 18px; }
        .stat-item { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .stat-label { color: #666; }
        .stat-value { font-weight: bold; color: #333; }
        .progress-bar { width: 100%; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; margin-top: 5px; }
        .progress-fill { height: 100%; background: #28a745; transition: width 0.3s ease; }
        .progress-fill.warning { background: #ffc107; }
        .progress-fill.danger { background: #dc3545; }
        .api-key-section { margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee; }
        .api-key-display { font-family: monospace; background: #f8f9fa; padding: 10px; border-radius: 4px; word-break: break-all; }
        .btn { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #0056b3; }
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table th, .table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
        .table th { background: #f8f9fa; font-weight: 600; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .status-processing { background: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>LMArena Bridge Dashboard</h1>
            <div class="user-info">
                <div>
                    <strong>Subscription:</strong> <?= ucfirst($user['subscription_tier']) ?>
                    <span style="margin-left: 20px;"><strong>Member since:</strong> <?= date('M Y', strtotime($user['created_at'])) ?></span>
                </div>
                <a href="?logout=1" class="logout-btn">Logout</a>
            </div>
        </div>

        <div class="grid">
            <!-- Usage Statistics -->
            <div class="card">
                <h2>📊 Usage Statistics (Today)</h2>
                <div class="stat-item">
                    <span class="stat-label">Requests Made:</span>
                    <span class="stat-value"><?= number_format($stats['today']['requests']) ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Tokens Used:</span>
                    <span class="stat-value"><?= number_format($stats['today']['tokens']) ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Estimated Cost:</span>
                    <span class="stat-value">$<?= number_format($stats['today']['cost'], 4) ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Avg Response Time:</span>
                    <span class="stat-value"><?= number_format($stats['today']['avg_response_time']) ?>ms</span>
                </div>
            </div>

            <!-- Rate Limits -->
            <div class="card">
                <h2>⚡ Rate Limits</h2>
                <?php foreach (['minute', 'hour', 'day'] as $window): ?>
                    <div class="stat-item">
                        <span class="stat-label"><?= ucfirst($window) ?>:</span>
                        <span class="stat-value"><?= $rateLimits[$window]['used'] ?> / <?= $rateLimits[$window]['limit'] ?></span>
                    </div>
                    <div class="progress-bar">
                        <?php 
                        $percentage = ($rateLimits[$window]['used'] / $rateLimits[$window]['limit']) * 100;
                        $class = $percentage > 90 ? 'danger' : ($percentage > 70 ? 'warning' : '');
                        ?>
                        <div class="progress-fill <?= $class ?>" style="width: <?= min($percentage, 100) ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Monthly Overview -->
            <div class="card">
                <h2>📈 Monthly Overview</h2>
                <div class="stat-item">
                    <span class="stat-label">Total Requests:</span>
                    <span class="stat-value"><?= number_format($stats['month']['requests']) ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Total Tokens:</span>
                    <span class="stat-value"><?= number_format($stats['month']['tokens']) ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Total Cost:</span>
                    <span class="stat-value">$<?= number_format($stats['month']['cost'], 2) ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Active Days:</span>
                    <span class="stat-value"><?= $stats['month']['active_days'] ?></span>
                </div>
            </div>

            <!-- API Key Management -->
            <div class="card">
                <h2>🔑 API Key Management</h2>
                <div class="stat-item">
                    <span class="stat-label">Current Key:</span>
                    <span class="stat-value">Active</span>
                </div>
                <div class="api-key-section">
                    <strong>Your API Key:</strong>
                    <div class="api-key-display"><?= substr($apiKey, 0, 20) . '...' . substr($apiKey, -8) ?></div>
                    <button class="btn" onclick="copyApiKey()" style="margin-top: 10px;">Copy Full Key</button>
                </div>
            </div>
        </div>

        <!-- Recent Requests -->
        <div class="card" style="margin-top: 20px;">
            <h2>🕒 Recent Requests</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Model</th>
                        <th>Tokens</th>
                        <th>Response Time</th>
                        <th>Status</th>
                        <th>Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['recent_requests'] as $request): ?>
                    <tr>
                        <td><?= date('H:i:s', strtotime($request['created_at'])) ?></td>
                        <td><?= htmlspecialchars($request['model_used']) ?></td>
                        <td><?= number_format($request['total_tokens']) ?></td>
                        <td><?= number_format($request['response_time_ms']) ?>ms</td>
                        <td>
                            <span class="status-badge status-<?= $request['status'] ?>">
                                <?= ucfirst($request['status']) ?>
                            </span>
                        </td>
                        <td>$<?= number_format($request['estimated_cost'], 4) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function copyApiKey() {
            const apiKey = '<?= $apiKey ?>';
            navigator.clipboard.writeText(apiKey).then(() => {
                alert('API key copied to clipboard!');
            });
        }

        // Auto-refresh every 30 seconds
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>

<?php

function showLoginForm($error = null) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>LMArena Bridge - Login</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
            .login-form { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
            .login-form h1 { text-align: center; margin-bottom: 30px; color: #333; }
            .form-group { margin-bottom: 20px; }
            .form-group label { display: block; margin-bottom: 5px; color: #555; }
            .form-group input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
            .btn { width: 100%; background: #007bff; color: white; padding: 12px; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
            .btn:hover { background: #0056b3; }
            .error { color: #dc3545; margin-bottom: 15px; text-align: center; }
        </style>
    </head>
    <body>
        <form class="login-form" method="post">
            <h1>🚀 LMArena Bridge</h1>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <div class="form-group">
                <label for="api_key">API Key:</label>
                <input type="password" id="api_key" name="api_key" required placeholder="Enter your API key">
            </div>
            <button type="submit" name="login" class="btn">Login to Dashboard</button>
        </form>
    </body>
    </html>
    <?php
}

function getUserDashboardStats($db, $userId) {
    try {
        // Today's stats
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as requests,
                COALESCE(SUM(total_tokens), 0) as tokens,
                COALESCE(SUM(estimated_cost), 0) as cost,
                COALESCE(AVG(response_time_ms), 0) as avg_response_time
            FROM usage_analytics 
            WHERE user_id = ? AND date_used = CURDATE()
        ");
        $stmt->execute([$userId]);
        $today = $stmt->fetch();

        // Month's stats
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as requests,
                COALESCE(SUM(total_tokens), 0) as tokens,
                COALESCE(SUM(estimated_cost), 0) as cost,
                COUNT(DISTINCT date_used) as active_days
            FROM usage_analytics 
            WHERE user_id = ? AND date_used >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$userId]);
        $month = $stmt->fetch();

        // Recent requests
        $stmt = $db->prepare("
            SELECT 
                ua.model_used, ua.total_tokens, ua.response_time_ms, 
                ua.estimated_cost, ua.created_at, ar.status
            FROM usage_analytics ua
            LEFT JOIN api_requests ar ON ua.request_id = ar.request_id
            WHERE ua.user_id = ?
            ORDER BY ua.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $recentRequests = $stmt->fetchAll();

        return [
            'today' => $today,
            'month' => $month,
            'recent_requests' => $recentRequests
        ];
    } catch (Exception $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
        return [
            'today' => ['requests' => 0, 'tokens' => 0, 'cost' => 0, 'avg_response_time' => 0],
            'month' => ['requests' => 0, 'tokens' => 0, 'cost' => 0, 'active_days' => 0],
            'recent_requests' => []
        ];
    }
}
?>
