<?php
/**
 * Migration Script: Fase 4 SEPA Export
 * voegt iban_number toe aan users tabel.
 */

require_once __DIR__ . '/../core/Database.php';

try {
    $db = Database::getConnection();

    try {
        $db->exec("ALTER TABLE users ADD COLUMN iban_number VARCHAR(35) DEFAULT NULL AFTER email");
        echo "Added 'iban_number' to 'users'.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Column 'iban_number' already exists in 'users'.\n";
        } else {
            throw $e;
        }
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
