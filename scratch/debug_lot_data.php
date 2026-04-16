<?php
require 'backend/core/Database.php';
$db = Database::getConnection();

echo "--- Active Period ---\n";
$activePeriod = Database::getActivePeriod();
print_r($activePeriod);

$periodId = $activePeriod['id'];

echo "\n--- Import History for Lot 1, Period $periodId ---\n";
$stmt = $db->prepare("SELECT * FROM import_history WHERE lot_id = 1 AND billing_period_id = ?");
$stmt->execute([$periodId]);
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\n--- Latest Reading for Lot 1 ---\n";
$stmt = $db->prepare("SELECT * FROM readings WHERE lot_id = 1 ORDER BY reading_date DESC LIMIT 1");
$stmt->execute();
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\n--- All Readings in Period $periodId ---\n";
$stmt = $db->prepare("SELECT lot_id, COUNT(*) as count FROM readings WHERE billing_period_id = ? GROUP BY lot_id");
$stmt->execute([$periodId]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- History for Lot 1 (get-history logic) ---\n";
$stmt = $db->prepare("
    SELECT ih.*, bp.year, bp.label
    FROM import_history ih
    JOIN billing_periods bp ON bp.id = ih.billing_period_id
    WHERE ih.lot_id = 1
    ORDER BY bp.year DESC
");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
