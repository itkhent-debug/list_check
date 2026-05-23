<?php
require_once __DIR__ . '/config.php';
setCorsHeaders();

// Simple key protection
$key = $_GET['key'] ?? '';
if ($key !== 'MONITOR2026') {
    sendResponse(['error' => 'Unauthorized'], 401);
}

$conn = getConnection();

// ── Scan for system file changes ───────────────────────────────────────────────
function scanSystemChanges($conn) {
    $watchDir = realpath(__DIR__ . '/..');
    if (!$watchDir) return;

    $watchExtensions = ['html','php','js','css','json','toml','sql'];
    $filePaths = [];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($watchDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $watchExtensions)) continue;
        // Skip node_modules and .git
        $path = str_replace('\\', '/', $file->getRealPath());
        if (strpos($path, '/node_modules/') !== false) continue;
        if (strpos($path, '/.git/') !== false) continue;
        $filePaths[] = $path;
    }

    foreach ($filePaths as $path) {
        $hash  = md5_file($path);
        $size  = filesize($path);
        $mtime = filemtime($path);

        // Relative path for display
        $rel = str_replace(str_replace('\\','/',$watchDir).'/', '', $path);

        // Check existing state
        $safeP = $conn->real_escape_string($path);
        $existing = $conn->query("SELECT file_hash, file_size, last_modified FROM system_files_state WHERE file_path='$safeP' LIMIT 1");

        if ($existing && $existing->num_rows > 0) {
            $row = $existing->fetch_assoc();
            if ($row['file_hash'] !== $hash) {
                // File was modified
                $safeRel  = $conn->real_escape_string($rel);
                $safeOld  = $conn->real_escape_string($row['file_hash']);
                $safeNew  = $conn->real_escape_string($hash);
                $safeDesc = $conn->real_escape_string("File modified: $rel (size: {$size}B)");
                $conn->query("INSERT INTO system_changes (change_type, file_path, description, old_hash, new_hash)
                              VALUES ('FILE_MODIFIED','$safeRel','$safeDesc','$safeOld','$safeNew')");
                $conn->query("UPDATE system_files_state SET file_hash='$safeNew', file_size=$size, last_modified=$mtime WHERE file_path='$safeP'");
            }
        } else {
            // New file discovered
            $safeRel  = $conn->real_escape_string($rel);
            $safeHash = $conn->real_escape_string($hash);
            $safeDesc = $conn->real_escape_string("New file detected: $rel (size: {$size}B)");
            $conn->query("INSERT INTO system_files_state (file_path, file_hash, file_size, last_modified) VALUES ('$safeP','$safeHash',$size,$mtime)");
            // Only log creation events for files beyond the first scan (check if table had any rows before)
            $countRes = $conn->query("SELECT COUNT(*) as c FROM system_changes");
            $cnt = $countRes ? (int)$countRes->fetch_assoc()['c'] : 0;
            if ($cnt > 0) {
                $safeNewH = $safeHash;
                $conn->query("INSERT INTO system_changes (change_type, file_path, description, new_hash)
                              VALUES ('FILE_CREATED','$safeRel','$safeDesc','$safeNewH')");
            }
        }
    }

    // Detect deleted files (in DB but no longer on disk)
    $allStored = $conn->query("SELECT file_path FROM system_files_state");
    if ($allStored) {
        while ($row = $allStored->fetch_assoc()) {
            if (!file_exists($row['file_path'])) {
                $safeDel  = $conn->real_escape_string($row['file_path']);
                $safeRelD = str_replace(str_replace('\\','/',$watchDir).'/', '', $row['file_path']);
                $safeRelD = $conn->real_escape_string($safeRelD);
                $conn->query("INSERT INTO system_changes (change_type, file_path, description)
                              VALUES ('FILE_DELETED','$safeRelD','File deleted from server: $safeRelD')");
                $conn->query("DELETE FROM system_files_state WHERE file_path='$safeDel'");
            }
        }
    }
}

// Run system change scan (runs on every monitor poll)
scanSystemChanges($conn);

// ── DB counts ──────────────────────────────────────────────────────────────────
$batchCount   = $conn->query("SELECT COUNT(*) as c FROM batches")->fetch_assoc()['c'];
$itemCount    = $conn->query("SELECT COUNT(*) as c FROM items")->fetch_assoc()['c'];
$userCount    = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$checkedCount = $conn->query("SELECT COUNT(*) as c FROM items WHERE checked = 1")->fetch_assoc()['c'];
$deleteCount  = $conn->query("SELECT COUNT(*) as c FROM activity_logs WHERE action = 'DELETE_BATCH'")->fetch_assoc()['c'];

// ── All batches with item count ───────────────────────────────────────────────
$batchesResult = $conn->query("SELECT b.id, b.name, b.created_at, b.updated_at,
    (SELECT COUNT(*) FROM items WHERE batch_id = b.id) as item_count,
    (SELECT COUNT(*) FROM items WHERE batch_id = b.id AND checked = 1) as checked_count
    FROM batches b ORDER BY b.updated_at DESC");
$batches = [];
while ($row = $batchesResult->fetch_assoc()) $batches[] = $row;

// ── All users ─────────────────────────────────────────────────────────────────
$usersResult = $conn->query("SELECT id, email, name, is_active, created_at, last_login FROM users ORDER BY name ASC");
$users = [];
while ($row = $usersResult->fetch_assoc()) $users[] = $row;

// ── Per-user activity summary ─────────────────────────────────────────────────
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

// ── Recent 200 activity logs (all severity) ───────────────────────────────────
$logsResult = $conn->query("SELECT id, user_name, user_email, action, target_type, target_id, detail, severity, page_url, ip_address, created_at
    FROM activity_logs ORDER BY created_at DESC LIMIT 200");
$logs = [];
while ($row = $logsResult->fetch_assoc()) $logs[] = $row;

// ── Security alerts (critical/warning + suspicious API calls) ─────────────────
$alertsResult = $conn->query("SELECT id, user_name, user_email, action, detail, severity, page_url, ip_address, created_at
    FROM activity_logs WHERE severity IN ('critical','warning') ORDER BY created_at DESC LIMIT 200");
$alerts = [];
while ($row = $alertsResult->fetch_assoc()) $alerts[] = $row;

// ── Recent 200 API logs ────────────────────────────────────────────────────────
$apiLogsResult = $conn->query("SELECT id, method, path, http_status, duration_ms, user_email, ip_address, is_suspicious, suspicious_reason, created_at FROM api_logs ORDER BY created_at DESC LIMIT 200");
$apiLogs = [];
if ($apiLogsResult) {
    while ($row = $apiLogsResult->fetch_assoc()) $apiLogs[] = $row;
}

// ── System changes (last 200) ─────────────────────────────────────────────────
$changesResult = $conn->query("SELECT id, change_type, file_path, description, old_hash, new_hash, detected_at FROM system_changes ORDER BY detected_at DESC LIMIT 200");
$systemChanges = [];
if ($changesResult) {
    while ($row = $changesResult->fetch_assoc()) $systemChanges[] = $row;
}

// ── Suspicious API count ───────────────────────────────────────────────────────
$suspRes = $conn->query("SELECT COUNT(*) as c FROM api_logs WHERE is_suspicious=1");
$suspCount = $suspRes ? (int)$suspRes->fetch_assoc()['c'] : 0;

sendResponse([
    'status'          => 'ok',
    'timestamp'       => date('Y-m-d H:i:s'),
    'stats' => [
        'batches'         => (int)$batchCount,
        'items'           => (int)$itemCount,
        'users'           => (int)$userCount,
        'checked'         => (int)$checkedCount,
        'deleted_batches' => (int)$deleteCount,
        'suspicious_requests' => $suspCount,
    ],
    'batches'         => $batches,
    'users'           => $users,
    'user_activity'   => $userActivity,
    'logs'            => $logs,
    'alerts'          => $alerts,
    'api_logs'        => $apiLogs,
    'system_changes'  => $systemChanges,
]);
?>
