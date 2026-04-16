<?php
/**
 * Migration Script: Fase 3 Betalingen & Incasso
 * Adds new columns to lots, users, and billing_results tables.
 */

require_once __DIR__ . '/../core/Database.php';

try {
    $db = Database::getConnection();

    // 1. Lots: allow_direct_debit (Default 1 = allowed)
    try {
        $db->exec("ALTER TABLE lots ADD COLUMN allow_direct_debit TINYINT(1) DEFAULT 1 AFTER has_electricity");
        echo "Added 'allow_direct_debit' to 'lots'.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Column 'allow_direct_debit' already exists in 'lots'.\n";
        } else {
            throw $e;
        }
    }

    // 2. Users: incasso_mandate_date
    try {
        $db->exec("ALTER TABLE users ADD COLUMN incasso_mandate_date DATETIME DEFAULT NULL AFTER token_expires_at");
        echo "Added 'incasso_mandate_date' to 'users'.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Column 'incasso_mandate_date' already exists in 'users'.\n";
        } else {
            throw $e;
        }
    }

    // 3. Billing Results: payment_status and paid_at
    try {
        $db->exec("ALTER TABLE billing_results ADD COLUMN payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending' AFTER calculated_at");
        $db->exec("ALTER TABLE billing_results ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER payment_status");
        echo "Added 'payment_status' and 'paid_at' to 'billing_results'.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Columns 'payment_status'/'paid_at' already exist in 'billing_results'.\n";
        } else {
            throw $e;
        }
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
