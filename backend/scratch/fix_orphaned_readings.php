<?php
/**
 * Maintenance Script: Fix orphaned readings (approved but no occupancy_id)
 * Run this via: php backend/scratch/fix_orphaned_readings.php
 */

require_once __DIR__ . '/../core/Database.php';

$db = Database::getConnection();

echo "Starting data repair for orphaned readings...\n";

// 1. Find problematic readings
$stmt = $db->query("
    SELECT r.id, r.lot_id 
    FROM readings r 
    WHERE r.occupancy_id IS NULL AND r.status = 'approved'
");
$orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orphans)) {
    echo "No orphaned readings found. Everything looks good!\n";
    exit;
}

echo "Found " . count($orphans) . " orphaned readings. Attempting to match with active residents...\n";

$fixedCount = 0;
$failedCount = 0;

foreach ($orphans as $orphan) {
    // Find active occupancy for this lot
    $stmtOcc = $db->prepare("SELECT id FROM lot_occupancy WHERE lot_id = ? AND is_active = 1 LIMIT 1");
    $stmtOcc->execute([$orphan['lot_id']]);
    $occId = $stmtOcc->fetchColumn();

    if ($occId) {
        // Update reading
        $upd = $db->prepare("UPDATE readings SET occupancy_id = ? WHERE id = ?");
        $upd->execute([$occId, $orphan['id']]);
        $fixedCount++;
        echo " - Fixed reading #{$orphan['id']} for kavel #{$orphan['lot_id']} -> Occupancy #{$occId}\n";
    } else {
        // No active resident? Try most recent one
        $stmtOcc = $db->prepare("SELECT id FROM lot_occupancy WHERE lot_id = ? ORDER BY start_date DESC LIMIT 1");
        $stmtOcc->execute([$orphan['lot_id']]);
        $occId = $stmtOcc->fetchColumn();
        
        if ($occId) {
            $upd = $db->prepare("UPDATE readings SET occupancy_id = ? WHERE id = ?");
            $upd->execute([$occId, $orphan['id']]);
            $fixedCount++;
            echo " - Fixed reading #{$orphan['id']} for kavel #{$orphan['lot_id']} -> Occupancy #{$occId} (Fallback to most recent)\n";
        } else {
            echo " - No resident found for kavel #{$orphan['lot_id']}! Reading #{$orphan['id']} remains orphaned.\n";
            $failedCount++;
        }
    }
}

echo "\nRepair completed.\n";
echo "Fixed: $fixedCount\n";
echo "Failed: $failedCount\n";
