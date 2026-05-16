<?php
require_once 'config.php';
setCorsHeaders();
requireAuth();

$conn = getConnection();

// Create tags table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    color VARCHAR(7) DEFAULT '#3b82f6',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Create batch_tags junction table for many-to-many relationship
$conn->query("CREATE TABLE IF NOT EXISTS batch_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    tag_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_batch_tag (batch_id, tag_id),
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
)");

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get all tags, or tags for a specific batch
        if (isset($_GET['batch_id'])) {
            // Get tags for a specific batch
            $batchId = (int)$_GET['batch_id'];
            $result = $conn->query("SELECT t.* FROM tags t 
                INNER JOIN batch_tags bt ON t.id = bt.tag_id 
                WHERE bt.batch_id = $batchId 
                ORDER BY t.name ASC");
            $tags = [];
            while ($row = $result->fetch_assoc()) {
                $tags[] = $row;
            }
            sendResponse($tags);
        } else {
            // Get all tags
            $result = $conn->query("SELECT * FROM tags ORDER BY name ASC");
            $tags = [];
            while ($row = $result->fetch_assoc()) {
                $tags[] = $row;
            }
            sendResponse($tags);
        }
        break;
        
    case 'POST':
        // Create new tag
        $data = getJsonInput();
        $name = $conn->real_escape_string(trim($data['name'] ?? ''));
        $color = $conn->real_escape_string($data['color'] ?? '#3b82f6');
        
        if (empty($name)) {
            sendResponse(['error' => 'Tag name is required'], 400);
        }
        
        // Check if tag already exists
        $check = $conn->query("SELECT id FROM tags WHERE name = '$name'");
        if ($check->num_rows > 0) {
            sendResponse(['error' => 'Tag already exists'], 400);
        }
        
        $stmt = $conn->prepare("INSERT INTO tags (name, color) VALUES (?, ?)");
        $stmt->bind_param('ss', $name, $color);
        
        if ($stmt->execute()) {
            sendResponse(['id' => $conn->insert_id, 'message' => 'Tag created']);
        } else {
            sendResponse(['error' => 'Failed to create tag'], 500);
        }
        break;
        
    case 'PUT':
        // Manage tags for a batch (add or remove)
        $data = getJsonInput();
        $batchId = (int)($data['batchId'] ?? 0);
        $action = $data['action'] ?? 'set'; // 'add', 'remove', or 'set' (replace all)
        
        if ($batchId <= 0) {
            sendResponse(['error' => 'Batch ID is required'], 400);
        }
        
        if ($action === 'add') {
            // Add a single tag to batch
            $tagId = (int)($data['tagId'] ?? 0);
            if ($tagId <= 0) {
                sendResponse(['error' => 'Tag ID is required'], 400);
            }
            
            // Insert ignore to avoid duplicates
            $stmt = $conn->prepare("INSERT IGNORE INTO batch_tags (batch_id, tag_id) VALUES (?, ?)");
            $stmt->bind_param('ii', $batchId, $tagId);
            $stmt->execute();
            sendResponse(['message' => 'Tag added to batch']);
            
        } else if ($action === 'remove') {
            // Remove a single tag from batch
            $tagId = (int)($data['tagId'] ?? 0);
            if ($tagId <= 0) {
                sendResponse(['error' => 'Tag ID is required'], 400);
            }
            
            $stmt = $conn->prepare("DELETE FROM batch_tags WHERE batch_id = ? AND tag_id = ?");
            $stmt->bind_param('ii', $batchId, $tagId);
            $stmt->execute();
            sendResponse(['message' => 'Tag removed from batch']);
            
        } else {
            // 'set' - Replace all tags for a batch
            $tagIds = $data['tagIds'] ?? [];
            
            // Remove all existing tags for this batch
            $conn->query("DELETE FROM batch_tags WHERE batch_id = $batchId");
            
            // Add new tags
            if (!empty($tagIds) && is_array($tagIds)) {
                $stmt = $conn->prepare("INSERT IGNORE INTO batch_tags (batch_id, tag_id) VALUES (?, ?)");
                foreach ($tagIds as $tagId) {
                    $tagId = (int)$tagId;
                    if ($tagId > 0) {
                        $stmt->bind_param('ii', $batchId, $tagId);
                        $stmt->execute();
                    }
                }
            }
            sendResponse(['message' => 'Tags updated for batch']);
        }
        break;
        
    case 'DELETE':
        // Delete tag
        $id = (int)($_GET['id'] ?? 0);
        
        if ($id <= 0) {
            sendResponse(['error' => 'Tag ID is required'], 400);
        }
        
        // batch_tags entries will be deleted automatically via CASCADE
        $stmt = $conn->prepare("DELETE FROM tags WHERE id = ?");
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            sendResponse(['message' => 'Tag deleted']);
        } else {
            sendResponse(['error' => 'Failed to delete tag'], 500);
        }
        break;
        
    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

$conn->close();
?>
