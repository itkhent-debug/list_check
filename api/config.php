<?php
define('APP_START_TIME', microtime(true));
// Global error handler - MUST BE AT THE VERY TOP
ini_set('display_errors', 0);
error_reporting(E_ALL);

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => "PHP Error: $message in $file on line $line"]);
    exit;
});

set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => "PHP Exception: " . $e->getMessage()]);
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => "PHP Fatal: " . $error['message']]);
        exit;
    }
});

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Database Configuration
$dbUrl = getenv('MYSQL_URL') ?: getenv('DATABASE_URL');
if ($dbUrl) {
    $url = parse_url($dbUrl);
    if ($url) {
        define('DB_HOST', $url['host'] ?? 'localhost');
        define('DB_USER', $url['user'] ?? 'root');
        define('DB_PASS', $url['pass'] ?? '');
        define('DB_NAME', isset($url['path']) ? ltrim($url['path'], '/') : 'railway');
        define('DB_PORT', $url['port'] ?? 3306);
    }
}
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('MYSQLHOST') ?: 'yamanote.proxy.rlwy.net');
    define('DB_USER', getenv('MYSQLUSER') ?: 'root');
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'ahFYtSstCghtlLjIzkwRJJaTHXJifVdY');
    define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');
    define('DB_PORT', getenv('MYSQLPORT') ?: 58498);
}

function getConnection() {
    static $conn = null;
    if ($conn !== null) return $conn;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
        $conn->set_charset('utf8mb4');

        $conn->query("CREATE TABLE IF NOT EXISTS batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            workflow_name VARCHAR(255) DEFAULT '',
            assigned_to VARCHAR(255) DEFAULT '',
            organization VARCHAR(255) DEFAULT '',
            casino_name VARCHAR(255) DEFAULT '',
            campaign_dates VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            label VARCHAR(100) NOT NULL,
            item_date DATE NOT NULL,
            item_time TIME DEFAULT '10:00:00',
            checked TINYINT(1) DEFAULT 0,
            time_ok TINYINT(1) DEFAULT 0,
            crm_ok TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS tags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            color VARCHAR(20) DEFAULT '#3b82f6',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS batch_tags (
            batch_id INT NOT NULL,
            tag_id INT NOT NULL,
            PRIMARY KEY (batch_id, tag_id),
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
            FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) DEFAULT NULL,
            picture VARCHAR(500) DEFAULT NULL,
            auth_provider VARCHAR(50) DEFAULT 'local',
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME DEFAULT NULL
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS auth_tokens (
            token VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            user_email VARCHAR(255) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_email VARCHAR(255) NOT NULL DEFAULT '',
            user_name VARCHAR(100) NOT NULL DEFAULT '',
            action VARCHAR(80) NOT NULL,
            target_type VARCHAR(50) DEFAULT '',
            target_id INT DEFAULT NULL,
            detail TEXT,
            severity VARCHAR(20) DEFAULT 'info',
            page_url VARCHAR(500) DEFAULT '',
            ip_address VARCHAR(60) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Add new columns to activity_logs if they don't exist yet (safe migration)
        @$conn->query("ALTER TABLE activity_logs ADD COLUMN severity VARCHAR(20) DEFAULT 'info'");
        @$conn->query("ALTER TABLE activity_logs ADD COLUMN page_url VARCHAR(500) DEFAULT ''");
        @$conn->query("ALTER TABLE activity_logs ADD COLUMN ip_address VARCHAR(60) DEFAULT ''");

        $conn->query("CREATE TABLE IF NOT EXISTS api_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            method VARCHAR(10) NOT NULL,
            path VARCHAR(255) NOT NULL,
            http_status INT NOT NULL,
            duration_ms INT NOT NULL,
            user_email VARCHAR(255) DEFAULT '',
            ip_address VARCHAR(60) DEFAULT '',
            request_data TEXT DEFAULT NULL,
            is_suspicious TINYINT(1) DEFAULT 0,
            suspicious_reason VARCHAR(500) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Add new columns to api_logs if they don't exist yet (safe migration)
        @$conn->query("ALTER TABLE api_logs ADD COLUMN user_email VARCHAR(255) DEFAULT ''");
        @$conn->query("ALTER TABLE api_logs ADD COLUMN ip_address VARCHAR(60) DEFAULT ''");
        @$conn->query("ALTER TABLE api_logs ADD COLUMN request_data TEXT DEFAULT NULL");
        @$conn->query("ALTER TABLE api_logs ADD COLUMN is_suspicious TINYINT(1) DEFAULT 0");
        @$conn->query("ALTER TABLE api_logs ADD COLUMN suspicious_reason VARCHAR(500) DEFAULT ''");

        // System change tracking tables
        $conn->query("CREATE TABLE IF NOT EXISTS system_files_state (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_path VARCHAR(500) NOT NULL UNIQUE,
            file_hash VARCHAR(64) NOT NULL,
            file_size INT NOT NULL,
            last_modified INT NOT NULL,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS system_changes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            change_type VARCHAR(30) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            description TEXT,
            old_hash VARCHAR(64) DEFAULT NULL,
            new_hash VARCHAR(64) DEFAULT NULL,
            detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        return $conn;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }
}

// Get current user from DB token
function getAuthUser($conn) {
    $token = $_COOKIE['crm_token'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if (empty($token)) return null;
    $safe = $conn->real_escape_string($token);
    $result = $conn->query("SELECT user_id, user_email, user_name FROM auth_tokens
                            WHERE token = '$safe' AND expires_at > NOW() LIMIT 1");
    if (!$result || $result->num_rows === 0) return null;
    return $result->fetch_assoc();
}

// Require auth or send 401
function requireAuth() {
    $conn = getConnection();
    $user = getAuthUser($conn);
    if (!$user) {
        sendResponse(['error' => 'Not authenticated'], 401);
    }
    $_SESSION['user_id']    = $user['user_id'];
    $_SESSION['user_email'] = $user['user_email'];
    $_SESSION['user_name']  = $user['user_name'];
    return $user;
}

// Log user activity
function logActivity($conn, $user, $action, $targetType = '', $targetId = null, $detail = '', $severity = 'info', $pageUrl = '') {
    $email   = $conn->real_escape_string($user['user_email'] ?? '');
    $name    = $conn->real_escape_string($user['user_name'] ?? '');
    $act     = $conn->real_escape_string($action);
    $ttype   = $conn->real_escape_string($targetType);
    $det     = $conn->real_escape_string($detail);
    $sev     = $conn->real_escape_string($severity);
    $purl    = $conn->real_escape_string(substr($pageUrl, 0, 499));
    $ip      = $conn->real_escape_string($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $tid     = ($targetId !== null) ? (int)$targetId : 'NULL';
    $conn->query("INSERT INTO activity_logs (user_email, user_name, action, target_type, target_id, detail, severity, page_url, ip_address)
                  VALUES ('$email','$name','$act','$ttype',$tid,'$det','$sev','$purl','$ip')");
}

// Detect suspicious patterns in request data
function detectSuspicious($data) {
    if (empty($data)) return ['suspicious' => false, 'reason' => ''];
    $text = is_string($data) ? $data : json_encode($data);
    $patterns = [
        'SQL Injection' => "/(\bSELECT\b|\bDROP\b|\bDELETE\b|\bINSERT\b|\bUPDATE\b|\bUNION\b|\bEXEC\b|\bSCRIPT\b|--|;--|\bOR\b\s+['\"]?1['\"]?\s*=\s*['\"]?1)/i",
        'XSS Attempt'   => "/<script[\s\S]*?>[\s\S]*?<\/script>/i",
        'Path Traversal'=> "/\.\.\//",
        'PHP Injection' => "/<\?php/i",
    ];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $text)) {
            return ['suspicious' => true, 'reason' => $name . ' pattern detected'];
        }
    }
    return ['suspicious' => false, 'reason' => ''];
}

// CORS headers
function setCorsHeaders() {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
}

// JSON response helper
function sendResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// JSON input helper
function getJsonInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Log all API requests on shutdown
register_shutdown_function(function() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Log all API requests except monitor polling (to avoid infinite loop / DB bloat)
    if (strpos($uri, 'api/') !== false && strpos($uri, 'monitor.php') === false) {
        $duration = round((microtime(true) - APP_START_TIME) * 1000);
        $status   = http_response_code() ?: 200;
        $method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path     = parse_url($uri, PHP_URL_PATH);
        $ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

        // Capture request body for POST/PUT
        $rawBody = '';
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $rawBody = file_get_contents('php://input');
            if (strlen($rawBody) > 2000) {
                $rawBody = substr($rawBody, 0, 2000) . '...[truncated]';
            }
        }

        // Detect suspicious patterns
        $suspCheck = detectSuspicious($rawBody ?: $_SERVER['QUERY_STRING'] ?? '');

        // Try to resolve the authenticated user
        $userEmail = '';
        try {
            $token = $_COOKIE['crm_token'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
            if (!empty($token)) {
                $conn2 = getConnection();
                $safeToken = $conn2->real_escape_string($token);
                $uRes = $conn2->query("SELECT user_email FROM auth_tokens WHERE token='$safeToken' AND expires_at > NOW() LIMIT 1");
                if ($uRes && $uRes->num_rows > 0) {
                    $uRow = $uRes->fetch_assoc();
                    $userEmail = $uRow['user_email'];
                }
            }
        } catch (Exception $e) { /* ignore */ }

        try {
            $conn = getConnection();
            $stmt = $conn->prepare("INSERT INTO api_logs (method, path, http_status, duration_ms, user_email, ip_address, request_data, is_suspicious, suspicious_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $isSusp = $suspCheck['suspicious'] ? 1 : 0;
            $suspReason = $suspCheck['reason'];
            $stmt->bind_param('ssiisssis', $method, $path, $status, $duration, $userEmail, $ip, $rawBody, $isSusp, $suspReason);
            $stmt->execute();

            // If suspicious and it's a real user action, also log to activity_logs as an alert
            if ($suspCheck['suspicious'] && !empty($userEmail)) {
                $safeEmail  = $conn->real_escape_string($userEmail);
                $safeReason = $conn->real_escape_string($suspCheck['reason']);
                $safePath   = $conn->real_escape_string($path);
                $safeIp     = $conn->real_escape_string($ip);
                $conn->query("INSERT INTO activity_logs (user_email, user_name, action, target_type, detail, severity, ip_address)
                              VALUES ('$safeEmail','','SUSPICIOUS_REQUEST','api','$safeReason on $safePath','critical','$safeIp')");
            }
        } catch (Exception $e) {
            // Ignore logging errors silently
        }
    }
});
?>
