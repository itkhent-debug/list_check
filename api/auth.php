<?php
require_once 'config.php';
setCorsHeaders();

$conn = getConnection();

// Ensure users table has all required columns
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    picture VARCHAR(500) DEFAULT NULL,
    auth_provider VARCHAR(50) DEFAULT 'local',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME DEFAULT NULL
)");

// Note: Standard MySQL doesn't support 'ADD COLUMN IF NOT EXISTS'.
// Since we have the CREATE TABLE IF NOT EXISTS above with all columns, 
// we only need to worry if the table was created before we added columns.
try { $conn->query("ALTER TABLE users ADD COLUMN picture VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
try { $conn->query("ALTER TABLE users ADD COLUMN auth_provider VARCHAR(50) DEFAULT 'local'"); } catch(Exception $e) {}
try { $conn->query("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) DEFAULT NULL"); } catch(Exception $e) {}

// Seed default user if table is empty
$check = $conn->query("SELECT COUNT(*) as cnt FROM users");
$row = $check->fetch_assoc();
if ($row['cnt'] == 0) {
    // Primary user from request
    $pass1 = password_hash('FPAI26', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)");
    $e1 = 'firspointCHL';
    $n1 = 'First Point CHL';
    $stmt->bind_param('sss', $e1, $n1, $pass1);
    $stmt->execute();

    // Paul Valencia fallback
    $pass2 = password_hash('247ga2024', PASSWORD_DEFAULT);
    $e2 = 'paul.valencia@247ga.co';
    $n2 = 'Paul Valencia';
    $stmt->bind_param('sss', $e2, $n2, $pass2);
    $stmt->execute();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        if ($method !== 'POST') {
            sendResponse(['error' => 'Method not allowed'], 405);
        }
        
        $data = getJsonInput();
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            sendResponse(['error' => 'Email and password are required'], 400);
        }
        
        // Find user
        $stmt = $conn->prepare("SELECT id, email, name, password_hash, is_active FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!$user) {
            sendResponse(['error' => 'Account not found. Contact admin to get access.'], 401);
        }
        
        if (!$user['is_active']) {
            sendResponse(['error' => 'Account is deactivated'], 403);
        }
        
        if (!password_verify($password, $user['password_hash'])) {
            sendResponse(['error' => 'Incorrect password'], 401);
        }
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        
        // Update last login
        $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . $user['id']);
        
        sendResponse([
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name']
            ]
        ]);
        break;

    case 'logout':
        session_destroy();
        sendResponse(['message' => 'Logged out']);
        break;

    case 'google':
        if ($method !== 'POST') {
            sendResponse(['error' => 'Method not allowed'], 405);
        }
        
        $data = getJsonInput();
        $email = strtolower(trim($data['email'] ?? ''));
        $name = trim($data['name'] ?? '');
        $picture = $data['picture'] ?? '';
        
        if (empty($email)) {
            sendResponse(['error' => 'Email is required'], 400);
        }
        
        // Validate email domain
        $domain = substr(strrchr($email, '@'), 1);
        if ($domain !== '247ga.co') {
            sendResponse(['error' => 'Only @247ga.co email addresses are allowed'], 403);
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
                sendResponse(['error' => 'Account is deactivated'], 403);
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
                sendResponse(['error' => 'Failed to create account'], 500);
            }
            
            $userId = $conn->insert_id;
        }
        
        // Set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_picture'] = $picture;
        
        sendResponse([
            'message' => 'Login successful',
            'user' => [
                'id' => $userId,
                'email' => $email,
                'name' => $name,
                'picture' => $picture
            ]
        ]);
        break;

    case 'me':
        // Check current session
        if (empty($_SESSION['user_id'])) {
            sendResponse(['authenticated' => false], 401);
        }
        sendResponse([
            'authenticated' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'email' => $_SESSION['user_email'],
                'name' => $_SESSION['user_name'],
                'picture' => $_SESSION['user_picture'] ?? null
            ]
        ]);
        break;

    case 'users':
        // List/manage users (admin)
        requireAuth();
        
        if ($method === 'GET') {
            $result = $conn->query("SELECT id, email, name, is_active, created_at, last_login FROM users ORDER BY name ASC");
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            sendResponse($users);
        }
        
        if ($method === 'POST') {
            // Add new user
            $data = getJsonInput();
            $email = strtolower(trim($data['email'] ?? ''));
            $name = trim($data['name'] ?? '');
            $password = $data['password'] ?? '';
            
            if (empty($email) || empty($name) || empty($password)) {
                sendResponse(['error' => 'Email, name, and password are required'], 400);
            }
            
            $domain = substr(strrchr($email, '@'), 1);
            if ($domain !== '247ga.co') {
                sendResponse(['error' => 'Only @247ga.co email addresses are allowed'], 400);
            }
            
            // Check if email already exists
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
            if ($id <= 0) {
                sendResponse(['error' => 'User ID required'], 400);
            }
            
            // Prevent self-deletion
            if ($id == $_SESSION['user_id']) {
                sendResponse(['error' => 'Cannot delete your own account'], 400);
            }
            
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            sendResponse(['message' => 'User deleted']);
        }
        break;

    default:
        sendResponse(['error' => 'Invalid action. Use ?action=login|logout|me|users'], 400);
}

$conn->close();
?>
