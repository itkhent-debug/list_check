<?php
// Start session for authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global error handler to ensure JSON response
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => "PHP Error: $message in $file on line $line"]);
    exit;
});

set_exception_handler(function($e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => "PHP Exception: " . $e->getMessage()]);
    exit;
});

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
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $conn->set_charset('utf8mb4');
        return $conn;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }
}

// Safe query helper
function executeQuery($conn, $sql) {
    $result = $conn->query($sql);
    if ($result === false) {
        header('Content-Type: application/json');
        http_response_code(500);
        die(json_encode(['error' => 'Database query failed: ' . $conn->error, 'sql' => $sql]));
    }
    return $result;
}

// CORS headers for API
function setCorsHeaders() {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
}

// Check if user is authenticated
function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        // Auto-login for direct access
        $_SESSION['user_id'] = 1;
        $_SESSION['user_email'] = 'paul.valencia@247ga.co';
        $_SESSION['user_name'] = 'Paul Valencia';
        return;
    }
}

// Helper to send JSON response
function sendResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// Helper to get JSON input
function getJsonInput() {
    return json_decode(file_get_contents('php://input'), true);
}
?>
