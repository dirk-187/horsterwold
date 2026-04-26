<?php
/**
 * Migration 005: Fix Consumption Rollover
 * 
 * Herberekent alle consumption waarden in de readings tabel met de correcte rollover logica.
 * Dit is nodig omdat eerdere berekeningen geen rekening hielden met meteroverloop bij 99999.
 * 
 * Rollover logica: Als nieuwe_stand < oude_stand, dan verbruik = (99999 - oude_stand) + nieuwe_stand
 */

require_once __DIR__ . '/../../backend/core/Database.php';

try {
    $db = Database::getConnection();
    
    echo "=== Migration 005: Fix Consumption Rollover ===\n\n";
    
    // Stap 1: Haal alle readings op die consumption data hebben
    $stmt = $db->query("
        SELECT 
            r.id,
            r.gas_new_reading,
            r.water_new_reading,
            r.electricity_new_reading,
            lo.start_gas,
            lo.start_water,
            lo.start_elec
        FROM readings r
        JOIN lot_occupancy lo ON lo.id = r.occupancy_id
        WHERE r.status = 'approved'
        ORDER BY r.id
    ");
    
    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalReadings = count($readings);
    
    echo "Gevonden: {$totalReadings} goedgekeurde readings om te herberekenen\n\n";
    
    if ($totalReadings === 0) {
        echo "Geen readings gevonden om te migreren.\n";
        exit(0);
    }
    
    // Stap 2: Bereken consumption met rollover logica
    $updated = 0;
    $unchanged = 0;
    
    $updateStmt = $db->prepare("
        UPDATE readings 
        SET 
            gas_consumption = ?,
            water_consumption = ?,
            electricity_consumption = ?
        WHERE id = ?
    ");
    
    foreach ($readings as $reading) {
        $id = $reading['id'];
        
        // Bereken gas consumption met rollover
        $gasNew = (float)$reading['gas_new_reading'];
        $gasOld = (float)$reading['start_gas'];
        $gasConsumption = ($gasNew < $gasOld) 
            ? (99999 - $gasOld) + $gasNew 
            : max(0, $gasNew - $gasOld);
        
        // Bereken water consumption met rollover
        $waterNew = (float)$reading['water_new_reading'];
        $waterOld = (float)$reading['start_water'];
        $waterConsumption = ($waterNew < $waterOld) 
            ? (99999 - $waterOld) + $waterNew 
            : max(0, $waterNew - $waterOld);
        
        // Bereken electricity consumption met rollover
        $elecNew = (float)$reading['electricity_new_reading'];
        $elecOld = (float)$reading['start_elec'];
        $elecConsumption = ($elecNew < $elecOld) 
            ? (99999 - $elecOld) + $elecNew 
            : max(0, $elecNew - $elecOld);
        
        // Update de reading
        $updateStmt->execute([
            $gasConsumption,
            $waterConsumption,
            $elecConsumption,
            $id
        ]);
        
        $updated++;
        
        // Progress indicator
        if ($updated % 10 === 0) {
            echo "Verwerkt: {$updated}/{$totalReadings} readings...\n";
        }
    }
    
    echo "\n=== Migratie Voltooid ===\n";
    echo "Totaal verwerkt: {$updated} readings\n";
    echo "Alle consumption waarden zijn herberekend met rollover logica.\n\n";
    
} catch (Exception $e) {
    echo "FOUT tijdens migratie: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
