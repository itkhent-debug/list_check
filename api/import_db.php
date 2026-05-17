<?php
require_once 'config.php';

// Allow only GET for simplicity
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    die("Method not allowed");
}

$conn = getConnection();

// Path to the SQL file
$sqlFile = __DIR__ . '/../database/crm_checklist (1).sql';

if (!file_exists($sqlFile)) {
    die("SQL file not found at $sqlFile");
}

$sql = file_get_contents($sqlFile);

if (empty($sql)) {
    die("SQL file is empty");
}

// Drop existing tables to avoid "Table already exists" errors
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("DROP TABLE IF EXISTS batches, items, tags, batch_tags, users, visitors, workflow_gaps");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

// Execute the multi query
if ($conn->multi_query($sql)) {
    do {
        // We have to consume all results of multi_query, otherwise subsequent queries fail
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->next_result());
    
    if ($conn->errno) {
        echo "Error executing SQL: " . $conn->error;
    } else {
        echo "Database imported successfully! You can now safely delete this file.";
    }
} else {
    echo "Error executing multi_query: " . $conn->error;
}

$conn->close();
?>
