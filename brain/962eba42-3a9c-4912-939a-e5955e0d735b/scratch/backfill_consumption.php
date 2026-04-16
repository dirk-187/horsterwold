<?php
/**
 * Backfill consumption values for approved readings in the active period.
 */

require_once __DIR__ . '/../../../backend/core/Database.php';
require_once __DIR__ . '/../../../backend/services/InvoiceService.php';

use Horsterwold\Services\InvoiceService;

try {
    $db = Database::getConnection();
    $activePeriod = Database::getActivePeriod();
    if (!$activePeriod) {
        die("No active period found.\n");
    }
    $periodId = $activePeriod['id'];
    echo "Processing period: " . $activePeriod['label'] . " (ID: $periodId)\n";

    $invoiceService = new InvoiceService();

    // Find all approved readings for this period
    $stmt = $db->prepare("SELECT id, lot_id FROM readings WHERE billing_period_id = ? AND status = 'approved'");
    $stmt->execute([$periodId]);
    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($readings) . " approved readings.\n";

    foreach ($readings as $r) {
        try {
            $preview = $invoiceService->calculatePreview((int)$r['lot_id'], (int)$periodId);
            $cons = $preview['consumption'];

            $upd = $db->prepare("
                UPDATE readings SET 
                    gas_consumption = ?,
                    water_consumption = ?,
                    electricity_consumption = ?
                WHERE id = ?
            ");
            $upd->execute([ $cons['gas'], $cons['water'], $cons['elec'], $r['id'] ]);
            echo "Updated Reading ID " . $r['id'] . " (Lot " . $r['lot_id'] . "): G=" . $cons['gas'] . " W=" . $cons['water'] . " E=" . $cons['elec'] . "\n";
        } catch (Exception $e) {
            echo "Error updating Reading ID " . $r['id'] . ": " . $e->getMessage() . "\n";
        }
    }

    echo "Backfill complete.\n";

} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
