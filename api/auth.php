<?php
require_once __DIR__ . '/config.php';
setCorsHeaders();

$conn = getConnection();

// Ensure users table has all required columns
try { $conn->query("ALTER TABLE users ADD COLUMN picture VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
try { $conn->query("ALTER TABLE users ADD COLUMN auth_provider VARCHAR(50) DEFAULT 'local'"); } catch(Exception $e) {}
try { $conn->query("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) DEFAULT NULL"); } catch(Exception $e) {}

// Seed firspointCHL user if missing
$check = $conn->query("SELECT id FROM users WHERE email = 'firspointCHL' LIMIT 1");
if ($check->num_rows == 0) {
    $pass1 = password_hash('FPAI26', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)");
    $e1 = 'firspointCHL'; $n1 = 'First Point CHL';
    $stmt->bind_param('sss', $e1, $n1, $pass1);
    $stmt->execute();
}

// Seed paul.valencia if missing
$checkPaul = $conn->query("SELECT id FROM users WHERE email = 'paul.valencia@247ga.co' LIMIT 1");
if ($checkPaul->num_rows == 0) {
    $pass2 = password_hash('247ga2024', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)");
    $e2 = 'paul.valencia@247ga.co'; $n2 = 'Paul Valencia';
    $stmt->bind_param('sss', $e2, $n2, $pass2);
    $stmt->execute();
}

// Seed paulvalencia@ga.co if missing
$chk3 = $conn->query("SELECT id FROM users WHERE email = 'paulvalencia@ga.co' LIMIT 1");
if ($chk3->num_rows == 0) {
    $p3 = password_hash('FPAI26', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)");
    $e3 = 'paulvalencia@ga.co'; $n3 = 'Paul Valencia';
    $stmt->bind_param('sss', $e3, $n3, $p3);
    $stmt->execute();
}

// Seed/Update jbdelrosario@ga.co with correct password (FPAI26).
// IMPORTANT: only run password_hash() when actually needed -- it's CPU-expensive
// and would otherwise slow every request enough to cause client-side timeouts
// ("Network error. Try again.").
$row4 = $conn->query("SELECT id, password_hash FROM users WHERE email = 'jbdelrosario@ga.co' LIMIT 1")->fetch_assoc();
if (!$row4) {
    $p4 = password_hash('FPAI26', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (email, name, password_hash, is_active) VALUES (?, ?, ?, 1)");
    $e4 = 'jbdelrosario@ga.co'; $n4 = 'JB Del Rosario';
    $stmt->bind_param('sss', $e4, $n4, $p4);
    $stmt->execute();
} else if (empty($row4['password_hash']) || !password_verify('FPAI26', $row4['password_hash'])) {
    // Existing hash is missing or doesn't match FPAI26 (e.g. earlier FPA126 typo) -> repair once.
    $p4 = password_hash('FPAI26', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password_hash = ?, is_active = 1 WHERE email = 'jbdelrosario@ga.co'");
    $stmt->bind_param('s', $p4);
    $stmt->execute();
}

// Seed/Update khentagustin@ga.co with correct password (FPAI26).
// Only run password_hash when actually needed to avoid per-request slowdown.
$chk5 = $conn->query("SELECT id, password_hash FROM users WHERE email = 'khentagustin@ga.co' LIMIT 1");
if ($chk5 && $chk5->num_rows == 0) {
    $p5 = password_hash('FPAI26', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (email, name, password_hash, is_active) VALUES (?, ?, ?, 1)");
    $e5 = 'khentagustin@ga.co'; $n5 = 'Khent Agustin';
    $stmt->bind_param('sss', $e5, $n5, $p5);
    $stmt->execute();
} else if ($chk5 && $chk5->num_rows > 0) {
    $row5 = $chk5->fetch_assoc();
    if (empty($row5['password_hash']) || !password_verify('FPAI26', $row5['password_hash'])) {
        $p5 = password_hash('FPAI26', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ?, is_active = 1 WHERE email = 'khentagustin@ga.co'");
        $stmt->bind_param('s', $p5);
        $stmt->execute();
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        if ($method !== 'POST') {
            sendResponse([
                'error' => 'Method not allowed. Login requires a POST request. If you are seeing this, ensure you are accessing the site via HTTPS (https://) to avoid redirect method degradation.'
            ], 405);
        }

        $data = getJsonInput();
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            sendResponse(['error' => 'Email and password are required'], 400);
        }

        $stmt = $conn->prepare("SELECT id, email, name, password_hash, is_active FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user) sendResponse(['error' => 'Account not found. Contact admin to get access.'], 401);
        if (!$user['is_active']) sendResponse(['error' => 'Account is deactivated'], 403);
        if (!password_verify($password, $user['password_hash'])) sendResponse(['error' => 'Incorrect password'], 401);

        // Generate a DB-backed token (30-day expiry)
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        $uid = $user['id']; $uemail = $user['email']; $uname = $user['name'];
        $tStmt = $conn->prepare("INSERT INTO auth_tokens (token, user_id, user_email, user_name, expires_at) VALUES (?, ?, ?, ?, ?)");
        $tStmt->bind_param('sisss', $token, $uid, $uemail, $uname, $expires);
        $tStmt->execute();

        // Set cookie (30 days, SameSite=Lax works cross-request on Railway)
        setcookie('crm_token', $token, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => true,
        ]);

        $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . $user['id']);

        sendResponse([
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => ['id' => $user['id'], 'email' => $user['email'], 'name' => $user['name']]
        ]);
        break;

    case 'logout':
        $token = $_COOKIE['crm_token'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
        if (!empty($token)) {
            $safe = $conn->real_escape_string($token);
            $conn->query("DELETE FROM auth_tokens WHERE token = '$safe'");
        }
        // Clear cookie
        setcookie('crm_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'samesite' => 'Lax']);
        sendResponse(['message' => 'Logged out']);
        break;

    case 'me':
        $user = getAuthUser($conn);
        if (!$user) {
            sendResponse(['authenticated' => false], 401);
        }
        sendResponse([
            'authenticated' => true,
            'user' => [
                'id'    => $user['user_id'],
                'email' => $user['user_email'],
                'name'  => $user['user_name'],
            ]
        ]);
        break;

    case 'users':
        requireAuth();

        if ($method === 'GET') {
            $result = $conn->query("SELECT id, email, name, is_active, created_at, last_login FROM users ORDER BY name ASC");
            $users = [];
            while ($row = $result->fetch_assoc()) $users[] = $row;
            sendResponse($users);
        }

        if ($method === 'POST') {
            $data = getJsonInput();
            $email    = strtolower(trim($data['email'] ?? ''));
            $name     = trim($data['name'] ?? '');
            $password = $data['password'] ?? '';

            if (empty($email) || empty($name) || empty($password)) {
                sendResponse(['error' => 'Email, name, and password are required'], 400);
            }

            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->bind_param('s', $email);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows > 0) {
                sendResponse(['error' => 'Email already registered'], 400);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $email, $name, $hash);
            if ($stmt->execute()) {
                sendResponse(['id' => $conn->insert_id, 'message' => 'User added']);
            } else {
                sendResponse(['error' => 'Failed to add user'], 500);
            }
        }

        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) sendResponse(['error' => 'User ID required'], 400);

            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            sendResponse(['message' => 'User deleted']);
        }
        break;

    default:
        sendResponse(['error' => 'Invalid action'], 400);
}

$conn->close();
?>
