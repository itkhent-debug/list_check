<?php
require_once __DIR__ . '/config.php';
setCorsHeaders();
$user = requireAuth();

$conn = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Retrieve last 20 backups for current user
        $email = $conn->real_escape_string($user['user_email'] ?? '');
        $result = $conn->query("SELECT id, action_type, target_id, backup_data, created_at FROM user_backups 
                                WHERE user_email = '$email' ORDER BY id DESC LIMIT 20");
        $backups = [];
        while ($row = $result->fetch_assoc()) {
            $data = json_decode($row['backup_data'], true);
            
            // Format nice human-readable description for client
            $desc = '';
            if ($row['action_type'] === 'delete_item') {
                $desc = "Deleted Item: \"" . ($data['name'] ?? 'Unknown') . "\"";
            } else if ($row['action_type'] === 'delete_batch') {
                $desc = "Deleted Batch: \"" . ($data['name'] ?? 'Unknown') . "\" (" . count($data['items'] ?? []) . " items)";
            } else if ($row['action_type'] === 'change_batch') {
                $desc = "Modified Batch: \"" . ($data['name'] ?? 'Unknown') . "\"";
            }
            
            $backups[] = [
                'id' => (int)$row['id'],
                'action_type' => $row['action_type'],
                'target_id' => (int)$row['target_id'],
                'description' => $desc,
                'created_at' => $row['created_at']
            ];
        }
        sendResponse($backups);
        break;

    case 'POST':
        $data = getJsonInput();
        $backupId = (int)($data['id'] ?? 0);
        $email = $conn->real_escape_string($user['user_email'] ?? '');
        
        if ($backupId <= 0) {
            sendResponse(['error' => 'Backup ID is required'], 400);
        }
        
        // Find backup record
        $stmt = $conn->prepare("SELECT * FROM user_backups WHERE id = ? AND user_email = ?");
        $stmt->bind_param("is", $backupId, $email);
        $stmt->execute();
        $backup = $stmt->get_result()->fetch_assoc();
        
        if (!$backup) {
            sendResponse(['error' => 'Backup record not found or access denied'], 404);
        }
        
        $backupPayload = json_decode($backup['backup_data'], true);
        $actionType = $backup['action_type'];
        $targetId = (int)$backup['target_id'];
        
        $conn->begin_transaction();
        try {
            $responsePayload = [];
            
            if ($actionType === 'delete_item') {
                $batchId = (int)($backupPayload['batch_id'] ?? 0);
                // Check if the batch still exists
                $bCheck = $conn->query("SELECT id FROM batches WHERE id = $batchId");
                if ($bCheck->num_rows === 0) {
                    throw new Exception("Cannot restore item: the batch it belonged to no longer exists.");
                }
                
                $stmt = $conn->prepare("INSERT INTO items (batch_id, name, label, item_date, item_time, checked, time_ok, crm_ok, sort_order) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $time = $backupPayload['item_time'] ?? '10:00';
                $checked = (int)($backupPayload['checked'] ?? 0);
                $timeOk = (int)($backupPayload['time_ok'] ?? 0);
                $crmOk = (int)($backupPayload['crm_ok'] ?? 0);
                $sortOrder = (int)($backupPayload['sort_order'] ?? 0);
                
                $stmt->bind_param("issssiiii", $batchId, $backupPayload['name'], $backupPayload['label'], $backupPayload['item_date'], $time, $checked, $timeOk, $crmOk, $sortOrder);
                $stmt->execute();
                
                $responsePayload = ['message' => 'Item restored', 'batch_id' => $batchId];
                logActivity($conn, $user, 'REVERT_DELETE_ITEM', 'item', $stmt->insert_id, 'Restored item: ' . $backupPayload['name']);
                
            } else if ($actionType === 'delete_batch') {
                // Recreate batch
                $stmt = $conn->prepare("INSERT INTO batches (name, workflow_name, assigned_to, organization, casino_name, campaign_dates) 
                                        VALUES (?, ?, ?, ?, ?, ?)");
                $wName = $backupPayload['workflow_name'] ?? '';
                $assTo = $backupPayload['assigned_to'] ?? '';
                $org = $backupPayload['organization'] ?? '';
                $casino = $backupPayload['casino_name'] ?? '';
                $dates = $backupPayload['campaign_dates'] ?? '';
                
                $stmt->bind_param("ssssss", $backupPayload['name'], $wName, $assTo, $org, $casino, $dates);
                $stmt->execute();
                $newBatchId = $conn->insert_id;
                
                // Restore tags mapping
                if (!empty($backupPayload['tags'])) {
                    $tagStmt = $conn->prepare("INSERT INTO batch_tags (batch_id, tag_id) VALUES (?, ?)");
                    foreach ($backupPayload['tags'] as $tagId) {
                        $tagStmt->bind_param("ii", $newBatchId, $tagId);
                        $tagStmt->execute();
                    }
                }
                
                // Restore batch items
                if (!empty($backupPayload['items'])) {
                    $itemStmt = $conn->prepare("INSERT INTO items (batch_id, name, label, item_date, item_time, checked, time_ok, crm_ok, sort_order) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($backupPayload['items'] as $item) {
                        $time = $item['item_time'] ?? '10:00';
                        $checked = (int)($item['checked'] ?? 0);
                        $timeOk = (int)($item['time_ok'] ?? 0);
                        $crmOk = (int)($item['crm_ok'] ?? 0);
                        $sortOrder = (int)($item['sort_order'] ?? 0);
                        
                        $itemStmt->bind_param("issssiiii", $newBatchId, $item['name'], $item['label'], $item['item_date'], $time, $checked, $timeOk, $crmOk, $sortOrder);
                        $itemStmt->execute();
                    }
                }
                
                $responsePayload = ['message' => 'Batch restored', 'batch_id' => $newBatchId];
                logActivity($conn, $user, 'REVERT_DELETE_BATCH', 'batch', $newBatchId, 'Restored batch: ' . $backupPayload['name']);
                
            } else if ($actionType === 'change_batch') {
                // Verify batch exists
                $bCheck = $conn->query("SELECT id FROM batches WHERE id = $targetId");
                if ($bCheck->num_rows === 0) {
                    throw new Exception("Cannot revert batch changes: the batch no longer exists.");
                }
                
                // Revert name if needed
                $stmt = $conn->prepare("UPDATE batches SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $backupPayload['name'], $targetId);
                $stmt->execute();
                
                // Remove existing items and insert backed up items
                $conn->query("DELETE FROM items WHERE batch_id = $targetId");
                
                if (!empty($backupPayload['items'])) {
                    $itemStmt = $conn->prepare("INSERT INTO items (batch_id, name, label, item_date, item_time, checked, time_ok, crm_ok, sort_order) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($backupPayload['items'] as $item) {
                        $time = $item['item_time'] ?? '10:00';
                        $checked = (int)($item['checked'] ?? 0);
                        $timeOk = (int)($item['time_ok'] ?? 0);
                        $crmOk = (int)($item['crm_ok'] ?? 0);
                        $sortOrder = (int)($item['sort_order'] ?? 0);
                        
                        $itemStmt->bind_param("issssiiii", $targetId, $item['name'], $item['label'], $item['item_date'], $time, $checked, $timeOk, $crmOk, $sortOrder);
                        $itemStmt->execute();
                    }
                }
                
                $responsePayload = ['message' => 'Batch state reverted', 'batch_id' => $targetId];
                logActivity($conn, $user, 'REVERT_BATCH_CHANGES', 'batch', $targetId, 'Reverted changes for batch: ' . $backupPayload['name']);
            }
            
            // Delete backup record
            $conn->query("DELETE FROM user_backups WHERE id = $backupId");
            
            $conn->commit();
            sendResponse($responsePayload);
            
        } catch (Exception $e) {
            $conn->rollback();
            sendResponse(['error' => $e->getMessage()], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
}

$conn->close();
?>
