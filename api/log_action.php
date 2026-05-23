<?php
require_once __DIR__ . '/config.php';
setCorsHeaders();

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Method not allowed'], 405);
}

$input = getJsonInput();

$action    = trim($input['action'] ?? '');
$pageUrl   = trim($input['page_url'] ?? '');
$detail    = trim($input['detail'] ?? '');
$severity  = in_array($input['severity'] ?? '', ['info','warning','critical']) ? $input['severity'] : 'info';
$userEmail = trim($input['user_email'] ?? '');
$userName  = trim($input['user_name'] ?? '');

if (empty($action)) {
    sendResponse(['error' => 'action is required'], 400);
}

$allowedActions = [
    'PAGE_VIEW',
    'BROWSER_BACK_FORWARD',
    'CLIPBOARD_COPY',
    'SCAM_IMAGE_DETECTION',
    'SUSPICIOUS_UPLOAD',
    'FEATURE_USED',
    'SESSION_START',
    'SESSION_END',
];

if (!in_array($action, $allowedActions)) {
    sendResponse(['error' => 'Unknown action'], 400);
}

$conn = getConnection();

// Try to resolve authenticated user from token
$token = $_COOKIE['crm_token'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
if (!empty($token)) {
    $safeToken = $conn->real_escape_string($token);
    $uRes = $conn->query("SELECT user_email, user_name FROM auth_tokens WHERE token='$safeToken' AND expires_at > NOW() LIMIT 1");
    if ($uRes && $uRes->num_rows > 0) {
        $uRow = $uRes->fetch_assoc();
        $userEmail = $uRow['user_email'];
        $userName  = $uRow['user_name'];
    }
}

$ip     = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$safeEmail  = $conn->real_escape_string(substr($userEmail, 0, 254));
$safeName   = $conn->real_escape_string(substr($userName, 0, 99));
$safeAction = $conn->real_escape_string($action);
$safePage   = $conn->real_escape_string(substr($pageUrl, 0, 499));
$safeDetail = $conn->real_escape_string(substr($detail, 0, 2000));
$safeSev    = $conn->real_escape_string($severity);
$safeIp     = $conn->real_escape_string(substr($ip, 0, 59));

$conn->query("INSERT INTO activity_logs (user_email, user_name, action, target_type, detail, severity, page_url, ip_address)
              VALUES ('$safeEmail','$safeName','$safeAction','client','$safeDetail','$safeSev','$safePage','$safeIp')");

sendResponse(['ok' => true, 'logged' => $action]);
?>
