<?php
require 'backend/core/Database.php';
$db = Database::getConnection();
try {
    $db->exec("ALTER TABLE lots ADD COLUMN magic_link_token VARCHAR(64) NULL AFTER address");
    $db->exec("ALTER TABLE lots ADD COLUMN token_expires_at DATETIME NULL AFTER magic_link_token");
    echo "Columns added successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
