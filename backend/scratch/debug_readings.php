<?php
require_once __DIR__ . '/../core/Database.php';
$db = Database::getConnection();

$activePeriod = Database::getActivePeriod();
$activePeriodId = $activePeriod['id'];

echo "Active Period ID: $activePeriodId\n";

$stmtList = $db->prepare("
    SELECT lo.id as occupancy_id, l.id as lot_id, l.lot_number, l.lot_type, lo.resident_name as name, lo.is_active
    FROM lot_occupancy lo
    JOIN lots l ON l.id = lo.lot_id
    JOIN readings r ON r.occupancy_id = lo.id
    LEFT JOIN billing_results br ON br.occupancy_id = lo.id AND br.billing_period_id = r.billing_period_id
    WHERE r.billing_period_id = ? AND r.status = 'approved' AND br.id IS NULL
    ORDER BY l.lot_number ASC
");
$stmtList->execute([$activePeriodId]);
$readyLots = $stmtList->fetchAll(PDO::FETCH_ASSOC);

echo "Ready Lots Count: " . count($readyLots) . "\n";
foreach ($readyLots as $rl) {
    echo " - Kavel #{$rl['lot_number']} | Type: {$rl['lot_type']} | Occupancy {$rl['occupancy_id']} | Resident Active: {$rl['is_active']}\n";
}
