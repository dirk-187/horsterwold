<?php
require_once 'backend/core/Database.php';

try {
    $db = Database::getConnection();
    echo "Database connection successful.\n";
    
    $stmt = $db->query("SELECT id, email, role, status, magic_link_token FROM users WHERE role = 'admin'");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Admins found: " . count($admins) . "\n";
    foreach ($admins as $admin) {
        echo "ID: {$admin['id']}, Email: {$admin['email']}, Status: {$admin['status']}, Token: " . ($admin['magic_link_token'] ? 'SET' : 'NULL') . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
