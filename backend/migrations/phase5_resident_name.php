<?php
/**
 * Migration Script: Fase 5 Resident Name
 * Adds resident_name column to lots table.
 */

require_once __DIR__ . '/../core/Database.php';

try {
    $db = Database::getConnection();

    // 1. Lots: resident_name
    try {
        $db->exec("ALTER TABLE lots ADD COLUMN resident_name VARCHAR(255) NULL AFTER user_id");
        echo "Added 'resident_name' to 'lots'.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Column 'resident_name' already exists in 'lots'.\n";
        } else {
            throw $e;
        }
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
