<?php
/**
 * Migration: Update billing_results table with VAT and correction fields
 */
require_once __DIR__ . '/../core/Database.php';

try {
    $db = Database::getConnection();
    
    $sql = "ALTER TABLE billing_results 
            ADD COLUMN vat_rate DECIMAL(5,2) NULL DEFAULT 21.00 AFTER erfpacht_total,
            ADD COLUMN vat_amount DECIMAL(10,2) NULL AFTER vat_rate,
            ADD COLUMN correction_amount DECIMAL(10,2) NULL DEFAULT 0.00 AFTER vat_amount,
            ADD COLUMN correction_reason VARCHAR(255) NULL AFTER correction_amount";
            
    $db->exec($sql);
    echo "Database migration successful: Added VAT and correction fields to billing_results.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
