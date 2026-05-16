<?php
require_once 'config.php';
setCorsHeaders();
requireAuth();

$conn = getConnection();

// Create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS workflow_gaps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow VARCHAR(255) NOT NULL,
    gap VARCHAR(100) NOT NULL,
    from_item VARCHAR(255) NOT NULL,
    to_item VARCHAR(255) NOT NULL,
    selected_count INT DEFAULT 0,
    saved_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get all workflow gaps
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $result = $conn->query("SELECT * FROM workflow_gaps WHERE id = $id");
            $gap = $result->fetch_assoc();
            sendResponse($gap ?: ['error' => 'Not found'], $gap ? 200 : 404);
        } else {
            $result = $conn->query("SELECT * FROM workflow_gaps ORDER BY saved_at DESC");
            $gaps = [];
            while ($row = $result->fetch_assoc()) {
                $gaps[] = $row;
            }
            sendResponse($gaps);
        }
        break;
        
    case 'POST':
        // Create new workflow gap
        $data = getJsonInput();
        
        if (empty($data['workflow']) || empty($data['gap'])) {
            sendResponse(['error' => 'Workflow and gap are required'], 400);
        }
        
        $stmt = $conn->prepare("INSERT INTO workflow_gaps (workflow, gap, from_item, to_item, selected_count) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssi', 
            $data['workflow'],
            $data['gap'],
            $data['fromItem'],
            $data['toItem'],
            $data['selectedCount']
        );
        
        if ($stmt->execute()) {
            sendResponse(['id' => $conn->insert_id, 'message' => 'Created']);
        } else {
            sendResponse(['error' => 'Failed to create'], 500);
        }
        break;
        
    case 'DELETE':
        // Delete workflow gap
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $conn->query("DELETE FROM workflow_gaps WHERE id = $id");
            sendResponse(['message' => 'Deleted']);
        } else if (isset($_GET['all'])) {
            // Clear all
            $conn->query("DELETE FROM workflow_gaps");
            sendResponse(['message' => 'All gaps cleared']);
        } else {
            sendResponse(['error' => 'ID required'], 400);
        }
        break;
        
    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

$conn->close();
?>
