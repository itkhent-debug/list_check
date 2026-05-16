<?php
require_once 'config.php';
setCorsHeaders();
$user = requireAuth();

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get items by batch_id
        $batchId = (int)($_GET['batch_id'] ?? 0);
        
        if ($batchId <= 0) {
            sendResponse(['error' => 'Batch ID is required'], 400);
        }
        
        $result = $conn->query("SELECT * FROM items WHERE batch_id = $batchId ORDER BY item_date, item_time");
        $items = [];
        while ($row = $result->fetch_assoc()) {
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
        sendResponse($items);
        break;

    case 'POST':
        // Add new item
        $data = getJsonInput();
        $batchId = (int)($data['batch_id'] ?? 0);
        $name = $data['name'] ?? '';
        $label = $data['label'] ?? 'Text Reminder';
        $date = $data['date'] ?? date('Y-m-d');
        $time = $data['time'] ?? '10:00';
        
        if ($batchId <= 0 || empty($name)) {
            sendResponse(['error' => 'Batch ID and item name are required'], 400);
        }
        
        $stmt = $conn->prepare("INSERT INTO items (batch_id, name, label, item_date, item_time) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $batchId, $name, $label, $date, $time);
        
        if ($stmt->execute()) {
            sendResponse([
                'id' => $conn->insert_id,
                'message' => 'Item added'
            ]);
        } else {
            sendResponse(['error' => 'Failed to add item'], 500);
        }
        break;

    case 'PUT':
        // Update item (toggle checkbox, etc.)
        $data = getJsonInput();
        $id = (int)($data['id'] ?? 0);
        
        if ($id <= 0) {
            sendResponse(['error' => 'Item ID is required'], 400);
        }
        
        // Build update query dynamically
        $updates = [];
        $params = [];
        $types = '';
        
        if (isset($data['checked'])) {
            $updates[] = 'checked = ?';
            $params[] = $data['checked'] ? 1 : 0;
            $types .= 'i';
        }
        if (isset($data['timeOk'])) {
            $updates[] = 'time_ok = ?';
            $params[] = $data['timeOk'] ? 1 : 0;
            $types .= 'i';
        }
        if (isset($data['crmOk'])) {
            $updates[] = 'crm_ok = ?';
            $params[] = $data['crmOk'] ? 1 : 0;
            $types .= 'i';
        }
        if (isset($data['name'])) {
            $updates[] = 'name = ?';
            $params[] = $data['name'];
            $types .= 's';
        }
        if (isset($data['label'])) {
            $updates[] = 'label = ?';
            $params[] = $data['label'];
            $types .= 's';
        }
        if (isset($data['date'])) {
            $updates[] = 'item_date = ?';
            $params[] = $data['date'];
            $types .= 's';
        }
        if (isset($data['time'])) {
            $updates[] = 'item_time = ?';
            $params[] = $data['time'];
            $types .= 's';
        }
        
        if (empty($updates)) {
            sendResponse(['error' => 'No fields to update'], 400);
        }
        
        $params[] = $id;
        $types .= 'i';
        
        $sql = "UPDATE items SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            // Log check/uncheck actions
            if (isset($data['checked'])) {
                $action = $data['checked'] ? 'CHECK_ITEM' : 'UNCHECK_ITEM';
                logActivity($conn, $user, $action, 'item', $id, 'Item ID '.$id);
            }
            sendResponse(['message' => 'Item updated']);
        } else {
            sendResponse(['error' => 'Failed to update item'], 500);
        }
        break;

    case 'DELETE':
        // Delete item
        $id = (int)($_GET['id'] ?? 0);
        
        if ($id <= 0) {
            sendResponse(['error' => 'Item ID is required'], 400);
        }
        
        $stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity($conn, $user, 'DELETE_ITEM', 'item', $id, 'Item ID '.$id);
            sendResponse(['message' => 'Item deleted']);
        } else {
            sendResponse(['error' => 'Failed to delete item'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

$conn->close();
?>
