<?php
require_once 'config.php';
setCorsHeaders();

// Simple key protection
$key = $_GET['key'] ?? '';
if ($key !== 'MONITOR2026') {
    sendResponse(['error' => 'Unauthorized'], 401);
}

$conn = getConnection();

// DB counts
$batchCount = $conn->query("SELECT COUNT(*) as c FROM batches")->fetch_assoc()['c'];
$itemCount  = $conn->query("SELECT COUNT(*) as c FROM items")->fetch_assoc()['c'];
$userCount  = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$checkedCount = $conn->query("SELECT COUNT(*) as c FROM items WHERE checked = 1")->fetch_assoc()['c'];
$deleteCount  = $conn->query("SELECT COUNT(*) as c FROM activity_logs WHERE action = 'DELETE_BATCH'")->fetch_assoc()['c'];

// All batches with item count
$batchesResult = $conn->query("SELECT b.id, b.name, b.created_at, b.updated_at,
    (SELECT COUNT(*) FROM items WHERE batch_id = b.id) as item_count,
    (SELECT COUNT(*) FROM items WHERE batch_id = b.id AND checked = 1) as checked_count
    FROM batches b ORDER BY b.updated_at DESC");
$batches = [];
while ($row = $batchesResult->fetch_assoc()) $batches[] = $row;

// All users
$usersResult = $conn->query("SELECT id, email, name, is_active, created_at, last_login FROM users ORDER BY name ASC");
$users = [];
while ($row = $usersResult->fetch_assoc()) $users[] = $row;

// Per-user activity summary
$userActivity = [];
$actResult = $conn->query("SELECT user_email, user_name,
    COUNT(*) as total_actions,
    SUM(CASE WHEN action='CREATE_BATCH' THEN 1 ELSE 0 END) as created,
    SUM(CASE WHEN action='DELETE_BATCH' THEN 1 ELSE 0 END) as deleted,
    SUM(CASE WHEN action='CHECK_ITEM' THEN 1 ELSE 0 END) as checked,
    SUM(CASE WHEN action='UNCHECK_ITEM' THEN 1 ELSE 0 END) as unchecked,
    MAX(created_at) as last_action
    FROM activity_logs GROUP BY user_email, user_name ORDER BY last_action DESC");
while ($row = $actResult->fetch_assoc()) $userActivity[] = $row;

// Recent 100 activity logs
$logsResult = $conn->query("SELECT id, user_name, user_email, action, target_type, target_id, detail, created_at
    FROM activity_logs ORDER BY created_at DESC LIMIT 100");
$logs = [];
while ($row = $logsResult->fetch_assoc()) $logs[] = $row;

// Recent 100 API logs
$apiLogsResult = $conn->query("SELECT method, path, http_status, duration_ms, created_at FROM api_logs ORDER BY created_at DESC LIMIT 100");
$apiLogs = [];
if ($apiLogsResult) {
    while ($row = $apiLogsResult->fetch_assoc()) $apiLogs[] = $row;
}

sendResponse([
    'status'    => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'stats' => [
        'batches'  => (int)$batchCount,
        'items'    => (int)$itemCount,
        'users'    => (int)$userCount,
        'checked'  => (int)$checkedCount,
        'deleted_batches' => (int)$deleteCount,
    ],
    'batches'       => $batches,
    'users'         => $users,
    'user_activity' => $userActivity,
    'logs'          => $logs,
    'api_logs'      => $apiLogs,
]);
?>
