<?php
/**
 * Migration Script: 004 Add Advance Payment Column
 * Run this once to update the database schema.
 */

require_once __DIR__ . '/backend/core/Database.php';

try {
    $db = Database::getConnection();
    
    echo "Starting migration 004...\n";
    
    // Check if column already exists
    $check = $db->query("SHOW COLUMNS FROM lots LIKE 'monthly_advance'");
    if ($check->rowCount() > 0) {
        echo "Column 'monthly_advance' already exists in 'lots' table. Skipping.\n";
    } else {
        $sql = "ALTER TABLE lots ADD COLUMN monthly_advance DECIMAL(10,2) DEFAULT 0.00 AFTER is_resident_active";
        $db->exec($sql);
        echo "Successfully added 'monthly_advance' column to 'lots' table.\n";
    }
    
    echo "Migration completed successfully.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
