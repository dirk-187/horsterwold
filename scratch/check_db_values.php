<?php
require_once __DIR__ . '/../backend/core/Database.php';
$db = Database::getConnection();

$lotId = 1; // Aanpassen indien nodig
echo "--- Lot Occupancy for Lot $lotId ---\n";
$stmt = $db->prepare("SELECT * FROM lot_occupancy WHERE lot_id = ? ORDER BY start_date DESC");
$stmt->execute([$lotId]);
$rows = $stmt->fetchAll();
print_r($rows);

echo "\n--- Readings for Lot $lotId ---\n";
$stmt = $db->prepare("SELECT id, occupancy_id, status, scenario, gas_new_reading FROM readings WHERE lot_id = ?");
$stmt->execute([$lotId]);
$rows = $stmt->fetchAll();
print_r($rows);
