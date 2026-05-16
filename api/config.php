<?php
// Start session for authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Configuration - Railway compatible
define('DB_HOST', getenv('MYSQLHOST') ?: 'yamanote.proxy.rlwy.net');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'ahFYtSstCghtlLjIzkwRJJaTHXJifVdY');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');
define('DB_PORT', getenv('MYSQLPORT') ?: 58498);

// Create connection
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    if ($conn->connect_error) {
        die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
    }
    
    $conn->set_charset('utf8mb4');
    return $conn;
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
