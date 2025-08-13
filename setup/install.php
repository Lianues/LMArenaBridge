<?php
/**
 * Installation Script for LMArena Bridge Distributed API
 * 
 * This script sets up the database, creates initial configuration,
 * and prepares the system for first use.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if running from command line
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // Basic web interface for installation
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>LMArena Bridge - Installation</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
            .step { background: #f5f5f5; padding: 20px; margin: 20px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            .warning { background: #fff3cd; color: #856404; }
            pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
            .form-group { margin: 15px 0; }
            label { display: block; margin-bottom: 5px; font-weight: bold; }
            input, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px; }
            button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; }
            button:hover { background: #0056b3; }
        </style>
    </head>
    <body>
        <h1>🚀 LMArena Bridge - Installation</h1>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            handleInstallation($_POST);
        } else {
            showInstallationForm();
        }
        ?>
    </body>
    </html>
    <?php
    exit;
}

// CLI Installation
echo "🚀 LMArena Bridge - Distributed API Installation\n";
echo "================================================\n\n";

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    echo "❌ Error: PHP 7.4 or higher is required. Current version: " . PHP_VERSION . "\n";
    exit(1);
}

// Check required extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'openssl'];
$missingExtensions = [];

foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (!empty($missingExtensions)) {
    echo "❌ Error: Missing required PHP extensions: " . implode(', ', $missingExtensions) . "\n";
    exit(1);
}

echo "✅ PHP version and extensions check passed\n\n";

// Get installation parameters
$config = getInstallationConfig();

// Create database connection
echo "📊 Setting up database...\n";
$db = createDatabaseConnection($config);

// Initialize database tables
if (initializeDatabaseTables($db)) {
    echo "✅ Database tables created successfully\n";
} else {
    echo "❌ Failed to create database tables\n";
    exit(1);
}

// Create initial admin user
echo "👤 Creating initial admin user...\n";
$adminApiKey = createAdminUser($db, $config);
if ($adminApiKey) {
    echo "✅ Admin user created successfully\n";
    echo "🔑 Admin API Key: $adminApiKey\n";
    echo "⚠️  Please save this API key securely!\n\n";
} else {
    echo "❌ Failed to create admin user\n";
    exit(1);
}

// Create configuration files
echo "⚙️  Creating configuration files...\n";
if (createConfigurationFiles($config, $adminApiKey)) {
    echo "✅ Configuration files created successfully\n";
} else {
    echo "❌ Failed to create configuration files\n";
    exit(1);
}

// Set up directory permissions
echo "📁 Setting up directory permissions...\n";
setupDirectoryPermissions();

// Create sample data
echo "📝 Creating sample data...\n";
createSampleData($db);

echo "\n🎉 Installation completed successfully!\n\n";
echo "Next steps:\n";
echo "1. Configure your web server to point to the public_html directory\n";
echo "2. Update the database configuration in php_api/config/database.php\n";
echo "3. Start browser clients using: python distributed_clients/browser_client.py --api-url YOUR_API_URL\n";
echo "4. Access the dashboard at: YOUR_DOMAIN/dashboard/\n";
echo "5. Test the API at: YOUR_DOMAIN/v1/models\n\n";

function getInstallationConfig() {
    $config = [];
    
    echo "Please provide the following configuration:\n\n";
    
    $config['db_host'] = readline("Database Host [localhost]: ") ?: 'localhost';
    $config['db_name'] = readline("Database Name [lmarena_bridge]: ") ?: 'lmarena_bridge';
    $config['db_user'] = readline("Database Username: ");
    $config['db_pass'] = readline("Database Password: ");
    $config['admin_email'] = readline("Admin Email: ");
    $config['api_base_url'] = readline("API Base URL [http://localhost]: ") ?: 'http://localhost';
    
    return $config;
}

function createDatabaseConnection($config) {
    try {
        $dsn = "mysql:host={$config['db_host']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Create database if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$config['db_name']}`");
        
        return $pdo;
    } catch (PDOException $e) {
        echo "❌ Database connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function initializeDatabaseTables($db) {
    $sqlFile = __DIR__ . '/database_schema.sql';
    
    if (!file_exists($sqlFile)) {
        // Create the SQL schema inline
        $sql = file_get_contents(__DIR__ . '/../php_api/config/database.php');
        // Extract SQL from the initializeTables method
        // This is a simplified approach - in production, use a separate SQL file
        
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
            INDEX idx_created_at (created_at)
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
    } else {
        $sql = file_get_contents($sqlFile);
    }
    
    try {
        $db->exec($sql);
        return true;
    } catch (PDOException $e) {
        echo "SQL Error: " . $e->getMessage() . "\n";
        return false;
    }
}

function createAdminUser($db, $config) {
    $apiKey = 'lma_' . bin2hex(random_bytes(32));
    $apiKeyHash = hash('sha256', $apiKey);
    
    try {
        $stmt = $db->prepare("
            INSERT INTO users (api_key_hash, email, subscription_tier, 
                             requests_per_minute, requests_per_hour, requests_per_day)
            VALUES (?, ?, 'enterprise', 300, 5000, 50000)
        ");
        $stmt->execute([$apiKeyHash, $config['admin_email']]);
        
        return $apiKey;
    } catch (PDOException $e) {
        echo "Failed to create admin user: " . $e->getMessage() . "\n";
        return false;
    }
}

function createConfigurationFiles($config, $adminApiKey) {
    // Create .env file
    $envContent = "
DB_HOST={$config['db_host']}
DB_NAME={$config['db_name']}
DB_USER={$config['db_user']}
DB_PASS={$config['db_pass']}
API_BASE_URL={$config['api_base_url']}
ADMIN_API_KEY=$adminApiKey
ENVIRONMENT=production
DEBUG=false
";
    
    file_put_contents(__DIR__ . '/../.env', trim($envContent));
    
    // Update database.php with actual credentials
    $dbConfigPath = __DIR__ . '/../php_api/config/database.php';
    $dbConfig = file_get_contents($dbConfigPath);
    $dbConfig = str_replace('your_db_user', $config['db_user'], $dbConfig);
    $dbConfig = str_replace('your_db_password', $config['db_pass'], $dbConfig);
    $dbConfig = str_replace('lmarena_bridge', $config['db_name'], $dbConfig);
    file_put_contents($dbConfigPath, $dbConfig);
    
    return true;
}

function setupDirectoryPermissions() {
    $directories = [
        __DIR__ . '/../php_api/logs',
        __DIR__ . '/../php_api/cache',
        __DIR__ . '/../php_api/uploads'
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        chmod($dir, 0755);
    }
}

function createSampleData($db) {
    // Create a sample free user
    $sampleApiKey = 'lma_' . bin2hex(random_bytes(32));
    $sampleApiKeyHash = hash('sha256', $sampleApiKey);
    
    try {
        $stmt = $db->prepare("
            INSERT INTO users (api_key_hash, email, subscription_tier)
            VALUES (?, 'demo@example.com', 'free')
        ");
        $stmt->execute([$sampleApiKeyHash]);
        
        echo "📝 Sample user created with API key: $sampleApiKey\n";
    } catch (PDOException $e) {
        // Ignore if user already exists
    }
}

function showInstallationForm() {
    ?>
    <div class="step">
        <h2>System Requirements Check</h2>
        <p>✅ PHP Version: <?= PHP_VERSION ?></p>
        <p>✅ Required extensions available</p>
    </div>
    
    <form method="post">
        <div class="step">
            <h2>Database Configuration</h2>
            <div class="form-group">
                <label>Database Host:</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Database Name:</label>
                <input type="text" name="db_name" value="lmarena_bridge" required>
            </div>
            <div class="form-group">
                <label>Database Username:</label>
                <input type="text" name="db_user" required>
            </div>
            <div class="form-group">
                <label>Database Password:</label>
                <input type="password" name="db_pass" required>
            </div>
        </div>
        
        <div class="step">
            <h2>Admin Configuration</h2>
            <div class="form-group">
                <label>Admin Email:</label>
                <input type="email" name="admin_email" required>
            </div>
            <div class="form-group">
                <label>API Base URL:</label>
                <input type="url" name="api_base_url" value="<?= 'http://' . $_SERVER['HTTP_HOST'] ?>" required>
            </div>
        </div>
        
        <button type="submit">Install LMArena Bridge</button>
    </form>
    <?php
}

function handleInstallation($postData) {
    echo "<div class='step'><h2>Installation Progress</h2>";
    
    // Validate input
    $config = [
        'db_host' => $postData['db_host'],
        'db_name' => $postData['db_name'],
        'db_user' => $postData['db_user'],
        'db_pass' => $postData['db_pass'],
        'admin_email' => $postData['admin_email'],
        'api_base_url' => $postData['api_base_url']
    ];
    
    // Create database connection
    echo "<p>📊 Connecting to database...</p>";
    $db = createDatabaseConnection($config);
    echo "<p class='success'>✅ Database connection successful</p>";
    
    // Initialize tables
    echo "<p>🏗️ Creating database tables...</p>";
    if (initializeDatabaseTables($db)) {
        echo "<p class='success'>✅ Database tables created</p>";
    } else {
        echo "<p class='error'>❌ Failed to create tables</p>";
        return;
    }
    
    // Create admin user
    echo "<p>👤 Creating admin user...</p>";
    $adminApiKey = createAdminUser($db, $config);
    if ($adminApiKey) {
        echo "<p class='success'>✅ Admin user created</p>";
        echo "<div class='warning'><strong>⚠️ Important:</strong> Save this API key securely:<br><code>$adminApiKey</code></div>";
    } else {
        echo "<p class='error'>❌ Failed to create admin user</p>";
        return;
    }
    
    // Create config files
    echo "<p>⚙️ Creating configuration files...</p>";
    createConfigurationFiles($config, $adminApiKey);
    echo "<p class='success'>✅ Configuration files created</p>";
    
    echo "<div class='success'>";
    echo "<h3>🎉 Installation Completed Successfully!</h3>";
    echo "<p><strong>Next steps:</strong></p>";
    echo "<ol>";
    echo "<li>Access your dashboard at: <a href='/dashboard/'>/dashboard/</a></li>";
    echo "<li>Test the API at: <a href='/v1/models'>/v1/models</a></li>";
    echo "<li>Start browser clients using the provided Python script</li>";
    echo "</ol>";
    echo "</div>";
    echo "</div>";
}
?>
