<?php
require_once __DIR__ . '/config.php';
setCorsHeaders();
requireAuth();

$conn = getConnection();

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) DEFAULT 'Anonymous',
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    page_visited VARCHAR(255) DEFAULT 'CRM Checklist',
    visited_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get all visitors
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
        $result = $conn->query("SELECT * FROM visitors ORDER BY visited_at DESC LIMIT $limit");
        $visitors = [];
        while ($row = $result->fetch_assoc()) {
            $visitors[] = $row;
        }
        sendResponse($visitors);
        break;
        
    case 'POST':
        // Log a new visitor
        $data = getJsonInput();
        $name = isset($data['name']) ? $conn->real_escape_string($data['name']) : 'Anonymous';
        
        // Get real IP (check forwarded headers for ngrok/proxy)
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] 
            ?? $_SERVER['HTTP_X_REAL_IP'] 
            ?? $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['REMOTE_ADDR'] 
            ?? 'Unknown';
        
        // If multiple IPs in X-Forwarded-For, get the first one (client IP)
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $page = isset($data['page']) ? $conn->real_escape_string($data['page']) : 'CRM Checklist';
        
        // Check if same IP visited in last 1 minute to avoid duplicate logs
        $checkResult = $conn->query("SELECT id FROM visitors WHERE ip_address = '$ip' AND visited_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        
        if ($checkResult->num_rows == 0) {
            $stmt = $conn->prepare("INSERT INTO visitors (name, ip_address, user_agent, page_visited) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $name, $ip, $userAgent, $page);
            $stmt->execute();
            sendResponse(['message' => 'Visit logged', 'id' => $conn->insert_id]);
        } else {
            sendResponse(['message' => 'Recent visit already logged']);
        }
        break;
        
    case 'DELETE':
        // Clear visitor log (admin only)
        if (isset($_GET['all'])) {
            $conn->query("DELETE FROM visitors");
            sendResponse(['message' => 'All visitor logs cleared']);
        }
        break;
}

$conn->close();
?>
