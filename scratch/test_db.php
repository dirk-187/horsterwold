<?php
require_once __DIR__ . '/../backend/core/Database.php';
try {
    $db = Database::getConnection();
    echo "Connected successfully to " . DB_NAME . "\n";
    $stmt = $db->query("SELECT 1");
    echo "Query executed successfully\n";
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
