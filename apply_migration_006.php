<?php
/**
 * Migration 006: Add Photo per Meter Type
 * 
 * Voegt specifieke kolommen toe voor foto's van gas, water en elektra meters.
 */

require_once __DIR__ . '/backend/core/Database.php';

try {
    $db = Database::getConnection();
    
    echo "=== Migration 006: Add Photo per Meter Type ===\n\n";
    
    // Check if columns already exist
    $stmt = $db->query("SHOW COLUMNS FROM readings LIKE 'image_url_gas'");
    if ($stmt->fetch()) {
        echo "Kolommen bestaan al. Migratie overgeslagen.\n";
        exit(0);
    }
    
    $sql = "ALTER TABLE readings 
            ADD COLUMN image_url_gas VARCHAR(255) DEFAULT NULL AFTER image_url,
            ADD COLUMN image_url_water VARCHAR(255) DEFAULT NULL AFTER image_url_gas,
            ADD COLUMN image_url_elec VARCHAR(255) DEFAULT NULL AFTER image_url_water";
    
    $db->exec($sql);
    
    echo "Kolommen succesvol toegevoegd aan 'readings' tabel.\n";
    
    // Optioneel: Kopieer bestaande image_url naar de juiste kolom op basis van scenario/data?
    // Maar aangezien image_url vaak maar één van de drie bevat, is het lastig te weten welke.
    // We laten de oude image_url staan als fallback.
    
    echo "=== Migratie Voltooid ===\n";
    
} catch (Exception $e) {
    echo "FOUT tijdens migratie: " . $e->getMessage() . "\n";
    exit(1);
}
