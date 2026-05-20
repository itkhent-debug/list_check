<?php
require_once __DIR__ . '/config.php';
setCorsHeaders();
$user = requireAuth();

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get all batches or single batch
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $result = $conn->query("SELECT * FROM batches WHERE id = $id");
            $batch = $result->fetch_assoc();
            
            if ($batch) {
                // Get tags for this batch
                $tagsResult = $conn->query("SELECT t.id, t.name, t.color FROM tags t 
                    INNER JOIN batch_tags bt ON t.id = bt.tag_id 
                    WHERE bt.batch_id = $id ORDER BY t.name");
                $tags = [];
                while ($tagRow = $tagsResult->fetch_assoc()) {
                    $tags[] = $tagRow;
                }
                $batch['tags'] = $tags;
                
                // Get items for this batch
                $itemsResult = $conn->query("SELECT * FROM items WHERE batch_id = $id ORDER BY item_date, item_time");
                $items = [];
                while ($row = $itemsResult->fetch_assoc()) {
                    $items[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'label' => $row['label'],
                        'date' => $row['item_date'],
                        'time' => substr($row['item_time'], 0, 5),
                        'checked' => (bool)$row['checked'],
                        'timeOk' => (bool)$row['time_ok'],
                        'crmOk' => (bool)$row['crm_ok']
                    ];
                }
                $batch['items'] = $items;
                sendResponse($batch);
            } else {
                sendResponse(['error' => 'Batch not found'], 404);
            }
        } else {
            // Get all batches with their tags
            $result = $conn->query("SELECT * FROM batches ORDER BY updated_at DESC");
            $batches = [];
            while ($row = $result->fetch_assoc()) {
                // Get tags for each batch
                $batchId = $row['id'];
                $tagsResult = $conn->query("SELECT t.id, t.name, t.color FROM tags t 
                    INNER JOIN batch_tags bt ON t.id = bt.tag_id 
                    WHERE bt.batch_id = $batchId ORDER BY t.name");
                $tags = [];
                while ($tagRow = $tagsResult->fetch_assoc()) {
                    $tags[] = $tagRow;
                }
                $row['tags'] = $tags;
                $batches[] = $row;
            }
            sendResponse($batches);
        }
        break;

    case 'POST':
        // Create new batch
        $data = getJsonInput();
        $name = $conn->real_escape_string($data['name'] ?? '');
        
        if (empty($name)) {
            sendResponse(['error' => 'Batch name is required'], 400);
        }
        
        $stmt = $conn->prepare("INSERT INTO batches (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        
        if ($stmt->execute()) {
            $batchId = $conn->insert_id;
            logActivity($conn, $user, 'CREATE_BATCH', 'batch', $batchId, $name);
            sendResponse(['id' => $batchId, 'message' => 'Batch created']);
        } else {
            sendResponse(['error' => 'Failed to create batch'], 500);
        }
        break;

    case 'PUT':
        // Update batch
        $data = getJsonInput();
        $id = (int)($data['id'] ?? 0);
        $name = $conn->real_escape_string($data['name'] ?? '');
        
        if ($id <= 0) {
            sendResponse(['error' => 'Batch ID is required'], 400);
        }
        
        // Update batch name
        $stmt = $conn->prepare("UPDATE batches SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $id);
        $stmt->execute();
        
        // Delete existing items and re-insert
        $conn->query("DELETE FROM items WHERE batch_id = $id");
        
        if (!empty($data['items'])) {
            $itemStmt = $conn->prepare("INSERT INTO items (batch_id, name, label, item_date, item_time, checked, time_ok, crm_ok) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($data['items'] as $item) {
                $itemName = $item['name'];
                $label = $item['label'];
                $date = $item['date'];
                $time = $item['time'] ?? '10:00';
                $checked = $item['checked'] ? 1 : 0;
                $timeOk = $item['timeOk'] ? 1 : 0;
                $crmOk = $item['crmOk'] ? 1 : 0;
                
                $itemStmt->bind_param("issssiii", $id, $itemName, $label, $date, $time, $checked, $timeOk, $crmOk);
                $itemStmt->execute();
            }
        }
        logActivity($conn, $user, 'UPDATE_BATCH', 'batch', $id, $name);
        sendResponse(['message' => 'Batch updated']);
        break;

    case 'DELETE':
        // Delete batch
        $id = (int)($_GET['id'] ?? 0);
        
        if ($id <= 0) {
            sendResponse(['error' => 'Batch ID is required'], 400);
        }
        
        $stmt = $conn->prepare("DELETE FROM batches WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity($conn, $user, 'DELETE_BATCH', 'batch', $id, 'Batch ID '.$id);
            sendResponse(['message' => 'Batch deleted']);
        } else {
            sendResponse(['error' => 'Failed to delete batch'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

$conn->close();
?>
