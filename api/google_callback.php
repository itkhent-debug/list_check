<?php
require_once 'config.php';
setCorsHeaders();

$conn = getConnection();

// Ensure columns exist
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS picture VARCHAR(500) DEFAULT NULL");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_provider VARCHAR(50) DEFAULT 'local'");

// Get the credential from POST (Google sends it as 'credential' or 'id_token')
$credential = $_POST['credential'] ?? $_POST['id_token'] ?? '';

if (empty($credential)) {
    header('Location: ../login.html?error=no_credential');
    exit;
}

// Decode JWT token (without verification for simplicity - in production, verify with Google's public keys)
function decodeJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    $payload = base64_decode(strtr($parts[1], '-_', '+/'));
    return json_decode($payload, true);
}

$payload = decodeJWT($credential);

if (!$payload || empty($payload['email'])) {
    header('Location: ../login.html?error=invalid_token');
    exit;
}

$email = strtolower(trim($payload['email']));
$name = $payload['name'] ?? '';
$picture = $payload['picture'] ?? '';

// Validate email domain
$domain = substr(strrchr($email, '@'), 1);
if ($domain !== '247ga.co') {
    header('Location: ../login.html?error=invalid_domain');
    exit;
}

// Check if user exists
$stmt = $conn->prepare("SELECT id, email, name, is_active, picture FROM users WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    // Existing user - check if active
    if (!$user['is_active']) {
        header('Location: ../login.html?error=account_deactivated');
        exit;
    }
    
    // Update name and picture if changed
    $updateStmt = $conn->prepare("UPDATE users SET name = ?, picture = ?, auth_provider = 'google', last_login = NOW() WHERE id = ?");
    $updateStmt->bind_param('ssi', $name, $picture, $user['id']);
    $updateStmt->execute();
    
    $userId = $user['id'];
} else {
    // New user - auto-register via Google
    $stmt = $conn->prepare("INSERT INTO users (email, name, picture, auth_provider) VALUES (?, ?, ?, 'google')");
    $stmt->bind_param('sss', $email, $name, $picture);
    
    if (!$stmt->execute()) {
        header('Location: ../login.html?error=registration_failed');
        exit;
    }
    
    $userId = $conn->insert_id;
}

// Set session
$_SESSION['user_id'] = $userId;
$_SESSION['user_email'] = $email;
$_SESSION['user_name'] = $name;
$_SESSION['user_picture'] = $picture;

$conn->close();

// Redirect to main app
header('Location: ../index.html');
exit;
?>
