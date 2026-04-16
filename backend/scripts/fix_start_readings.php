<?php
/**
 * Fix Script: Initialize lot_occupancy start readings from import_history.
 * Run this once to fix records migrated with 0.000 values.
 */

require_once __DIR__ . '/../core/Database.php';

$db = Database::getConnection();
$activePeriod = Database::getActivePeriod();
$activePeriodId = $activePeriod['id'];

echo "Starting fix for lot_occupancy start readings...\n";

// 1. Get all occupancy records with zero start readings
// We use a broader check for 0
$stmt = $db->prepare("SELECT id, lot_id, start_gas FROM lot_occupancy WHERE ABS(start_gas) < 0.001 AND ABS(start_water) < 0.001 AND ABS(start_elec) < 0.001");
$stmt->execute();
$toFix = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($toFix) . " records to check (currently zero).\n";

$updated = 0;
foreach ($toFix as $row) {
    // 2. Find the baseline in import_history for this lot
    // Try current period, fallback to any period if not found
    $stmtBase = $db->prepare("
        SELECT gas_new_reading, water_new_reading, electricity_new_reading, billing_period_id
        FROM import_history 
        WHERE lot_id = ? 
        ORDER BY billing_period_id DESC LIMIT 1
    ");
    $stmtBase->execute([$row['lot_id']]);
    $base = $stmtBase->fetch(PDO::FETCH_ASSOC);

    if ($base) {
        // 3. Update the occupancy record
        $upd = $db->prepare("
            UPDATE lot_occupancy SET 
                start_gas = ?, 
                start_water = ?, 
                start_elec = ? 
            WHERE id = ?
        ");
        $upd->execute([
            $base['gas_new_reading'],
            $base['water_new_reading'],
            $base['electricity_new_reading'],
            $row['id']
        ]);
        $updated++;
        echo "Updated occupancy ID {$row['id']} for lot {$row['lot_id']} (using baseline from period {$base['billing_period_id']})\n";
    } else {
        echo "No baseline found for lot {$row['lot_id']}\n";
    }
}

echo "\nDone! Total updated: $updated records.\n";
