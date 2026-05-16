<?php
require_once 'config.php';
setCorsHeaders();
requireAuth();

// Store only the SHA-256 hash of the passcode (never the plain value)
define('UNCHECK_PASSCODE_HASH', 'f1ee529ef49111208f1c1646c53c8c311c9f093fd7891c1b46d77e98210b018d');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Issue a one-time nonce for challenge-response
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['passcode_nonce'] = $nonce;
    sendResponse(['nonce' => $nonce]);

} elseif ($method === 'POST') {
    $data = getJsonInput();
    $hash = $data['hash'] ?? '';
    $nonce = $_SESSION['passcode_nonce'] ?? '';

    // Invalidate nonce immediately (one-time use)
    unset($_SESSION['passcode_nonce']);

    if (empty($nonce)) {
        sendResponse(['valid' => false, 'message' => 'No challenge issued. Try again.'], 400);
    }

    // Expected: sha256( sha256(passcode) + nonce )
    $expected = hash('sha256', UNCHECK_PASSCODE_HASH . $nonce);

    if (hash_equals($expected, $hash)) {
        sendResponse(['valid' => true, 'message' => 'Passcode accepted']);
    } else {
        sendResponse(['valid' => false, 'message' => 'Incorrect passcode'], 403);
    }
} else {
    sendResponse(['error' => 'Method not allowed'], 405);
}
?>
