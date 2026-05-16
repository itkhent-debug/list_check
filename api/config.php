<?php
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
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR)) {
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => "PHP Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']]);
        exit;
    }
});

ob_start();

// Start session (best effort - Railway may not persist files)
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

// Create connection
function getConnection() {
    static $conn = null;
    if ($conn !== null) return $conn;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
        $conn->set_charset('utf8mb4');

        // Auto-initialize all tables
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

        // DB-backed auth tokens (fixes Railway ephemeral session issue)
        $conn->query("CREATE TABLE IF NOT EXISTS auth_tokens (
            token VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            user_email VARCHAR(255) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL
        )");

        return $conn;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }
}

// Get current user from DB token (reads cookie or X-Auth-Token header)
function getAuthUser($conn) {
    $token = $_COOKIE['crm_token'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if (empty($token)) return null;

    $safe = $conn->real_escape_string($token);
    $result = $conn->query("SELECT user_id, user_email, user_name FROM auth_tokens 
                            WHERE token = '$safe' AND expires_at > NOW() LIMIT 1");
    if (!$result || $result->num_rows === 0) return null;
    return $result->fetch_assoc();
}

// Require authenticated user - sends 401 if not logged in
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
?>
