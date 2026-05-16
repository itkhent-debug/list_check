<?php
require_once 'api/config.php';

echo "<h1>CRM Checklist - Database Setup</h1>";

try {
    $conn = getConnection();
    echo "<p style='color: green;'>✅ Connected to database successfully.</p>";
    
    // Create Batches table
    $sql = "CREATE TABLE IF NOT EXISTS batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        workflow_name VARCHAR(255) DEFAULT '',
        assigned_to VARCHAR(255) DEFAULT '',
        organization VARCHAR(255) DEFAULT '',
        casino_name VARCHAR(255) DEFAULT '',
        campaign_dates VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->query($sql);
    echo "<p>✅ Batches table checked/created.</p>";
    
    // Create Items table
    $sql = "CREATE TABLE IF NOT EXISTS items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        label VARCHAR(100) NOT NULL,
        item_date DATE NOT NULL,
        item_time TIME DEFAULT '10:00:00',
        checked TINYINT(1) DEFAULT 0,
        time_ok TINYINT(1) DEFAULT 0,
        crm_ok TINYINT(1) DEFAULT 0,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )";
    $conn->query($sql);
    echo "<p>✅ Items table checked/created.</p>";
    
    // Create Tags table
    $sql = "CREATE TABLE IF NOT EXISTS tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE,
        color VARCHAR(20) DEFAULT '#3b82f6',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);
    echo "<p>✅ Tags table checked/created.</p>";
    
    // Create Batch Tags table
    $sql = "CREATE TABLE IF NOT EXISTS batch_tags (
        batch_id INT NOT NULL,
        tag_id INT NOT NULL,
        PRIMARY KEY (batch_id, tag_id),
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    )";
    $conn->query($sql);
    echo "<p>✅ Batch Tags table checked/created.</p>";

    // Create Users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) DEFAULT NULL,
        picture VARCHAR(500) DEFAULT NULL,
        auth_provider VARCHAR(50) DEFAULT 'local',
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME DEFAULT NULL
    )";
    $conn->query($sql);
    echo "<p>✅ Users table checked/created.</p>";

    // Create Visitors table
    $sql = "CREATE TABLE IF NOT EXISTS visitors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) DEFAULT 'Anonymous',
        page VARCHAR(255) DEFAULT '',
        ip_address VARCHAR(45) DEFAULT '',
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);
    echo "<p>✅ Visitors table checked/created.</p>";

    // Seed default user
    $check = $conn->query("SELECT COUNT(*) as cnt FROM users");
    $row = $check->fetch_assoc();
    if ($row['cnt'] == 0) {
        $defaultPassword = password_hash('247ga2024', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)");
        $email = 'paul.valencia@247ga.co';
        $name = 'Paul Valencia';
        $stmt->bind_param('sss', $email, $name, $defaultPassword);
        $stmt->execute();
        echo "<p>✅ Default user created.</p>";
    }

    echo "<h2 style='color: blue;'>Setup Complete!</h2>";
    echo "<p><a href='index.html'>Go to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<p>Check your credentials in api/config.php</p>";
}
?>
