<?php
require_once __DIR__ . '/config.php';
setCorsHeaders();
header('Content-Type: application/json');

$conn = getConnection();

// Ensure columns exist
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS picture VARCHAR(500) DEFAULT NULL");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_provider VARCHAR(50) DEFAULT 'local'");

// Get credential from POST body (sent via fetch from login.html)
$input = json_decode(file_get_contents('php://input'), true);
$credential = $input['credential'] ?? $_POST['credential'] ?? '';

if (empty($credential)) {
    echo json_encode(['success' => false, 'error' => 'No credential provided']);
    exit;
}

// Decode JWT token
function decodeJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    $payload = base64_decode(strtr($parts[1], '-_', '+/'));
    return json_decode($payload, true);
}

$payload = decodeJWT($credential);

if (!$payload || empty($payload['email'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$email   = strtolower(trim($payload['email']));
$name    = $payload['name'] ?? '';
$picture = $payload['picture'] ?? '';

// Validate email domain
$domain = substr(strrchr($email, '@'), 1);
if ($domain !== '247ga.co' && $domain !== 'ga.co') {
    echo json_encode(['success' => false, 'error' => 'invalid_domain']);
    exit;
}

// Check if user exists
$stmt = $conn->prepare("SELECT id, email, name, is_active, picture FROM users WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();

if ($user) {
    if (!$user['is_active']) {
        echo json_encode(['success' => false, 'error' => 'account_deactivated']);
        exit;
    }
    $updateStmt = $conn->prepare("UPDATE users SET name = ?, picture = ?, auth_provider = 'google', last_login = NOW() WHERE id = ?");
    $updateStmt->bind_param('ssi', $name, $picture, $user['id']);
    $updateStmt->execute();
    $userId = $user['id'];
} else {
    $stmt = $conn->prepare("INSERT INTO users (email, name, picture, auth_provider) VALUES (?, ?, ?, 'google')");
    $stmt->bind_param('sss', $email, $name, $picture);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'registration_failed']);
        exit;
    }
    $userId = $conn->insert_id;
}

// Generate token
$token   = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+30 days'));

$tStmt = $conn->prepare("INSERT INTO auth_tokens (token, user_id, user_email, user_name, expires_at) VALUES (?, ?, ?, ?, ?)");
$tStmt->bind_param('sisss', $token, $userId, $email, $name, $expires);
$tStmt->execute();

// Set cookie
setcookie('crm_token', $token, [
    'expires'  => time() + (30 * 24 * 60 * 60),
    'path'     => '/',
    'secure'   => true,
    'httponly' => false,
    'samesite' => 'Lax'
]);

// Set session
$_SESSION['user_id']      = $userId;
$_SESSION['user_email']   = $email;
$_SESSION['user_name']    = $name;
$_SESSION['user_picture'] = $picture;

$conn->close();

echo json_encode(['success' => true, 'token' => $token, 'name' => $name, 'email' => $email, 'picture' => $picture]);
