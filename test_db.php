<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Database Connection...\n";

$host = getenv('MYSQLHOST') ?: 'yamanote.proxy.rlwy.net';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: 'ahFYtSstCghtlLjIzkwRJJaTHXJifVdY';
$name = getenv('MYSQLDATABASE') ?: 'railway';
$port = getenv('MYSQLPORT') ?: 58498;

echo "Host: $host\n";
echo "User: $user\n";
echo "DB: $name\n";
echo "Port: $port\n";

try {
    $conn = new mysqli($host, $user, $pass, $name, $port);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    echo "Successfully connected!\n";
    
    $res = $conn->query("SHOW TABLES");
    echo "Tables in database:\n";
    while ($row = $res->fetch_array()) {
        echo "- " . $row[0] . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
