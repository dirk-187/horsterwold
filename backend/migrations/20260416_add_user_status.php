<?php
/**
 * Migration: Add status column to users table
 */

require_once __DIR__ . '/../core/Database.php';

try {
    $db = Database::getConnection();
    
    // Check if column already exists
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($stmt->rowCount() === 0) {
        $db->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role");
        echo "Migration successful: status column added to users table.\n";
    } else {
        echo "Migration skipped: status column already exists.\n";
    }

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
