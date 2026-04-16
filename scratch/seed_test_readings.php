<?php
/**
 * Script to seed pending meter readings for all 205 lots.
 * Used for testing the bulk approval and settlement workflow.
 */

require_once __DIR__ . '/../backend/core/Database.php';

$db = Database::getConnection();

echo "Seeding test readings...\n";

try {
    // 1. Get all baselines
    $stmt = $db->query("SELECT lot_id, gas_new_reading as gas, water_new_reading as water, electricity_new_reading as elec FROM import_history WHERE billing_period_id = 1");
    $baselines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $db->beginTransaction();

    // 2. Clear existing pending readings to avoid duplicates during test
    $db->exec("DELETE FROM readings WHERE status = 'pending' AND billing_period_id = 1");

    $stmtInsert = $db->prepare("INSERT INTO readings 
        (lot_id, billing_period_id, scenario, status, reading_date, 
         gas_prev_reading, gas_new_reading, gas_consumption, 
         water_prev_reading, water_new_reading, water_consumption, 
         electricity_prev_reading, electricity_new_reading, electricity_consumption,
         is_afwijking, afwijking_reden) 
        VALUES (?, 1, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($baselines as $b) {
        $lotId = $b['lot_id'];
        
        $scenario = 'jaarafrekening';
        $gasCons = 10;
        $waterCons = 2;
        $elecCons = 100;
        $isAfwijking = 0;
        $reden = null;

        // Simulatie Anomalieën
        if ($lotId == 10) { // Extreme Gas afwijking
            $gasCons = 50; // 5x meer dan gemiddeld
            $isAfwijking = 1;
            $reden = "Gasverbruik wijkt 400.0% af van vorige periode";
        } elseif ($lotId == 20) { // Water meterstand lager
            $waterCons = -1; 
            $isAfwijking = 1;
            $reden = "Watermeterstand lager dan vorige meting";
        } elseif ($lotId == 30) { // Elektra afwijking
            $elecCons = 800;
            $isAfwijking = 1;
            $reden = "Elektraverbruik wijkt 700.0% af van vorige periode";
        }

        // Simulatie Verhuizing
        if ($lotId == 50) {
            $scenario = 'verhuizing';
        }

        $stmtInsert->execute([
            $lotId,
            $scenario,
            $b['gas'], (float)$b['gas'] + $gasCons, $gasCons,
            $b['water'], (float)$b['water'] + $waterCons, $waterCons,
            $b['elec'], (float)$b['elec'] + $elecCons, $elecCons,
            $isAfwijking, $reden
        ]);
    }

    $db->commit();
    echo "Seeding successful. 205 readings created (including 3 anomalies and 1 move).\n";

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
