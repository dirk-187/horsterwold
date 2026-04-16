<?php
require_once __DIR__ . '/../backend/core/Database.php';

$db = Database::getConnection();
$p = Database::getActivePeriod();
$periodId = $p['id'];

echo "Verification for Period $periodId (2026):\n";

$count = $db->query("SELECT COUNT(*) FROM readings WHERE billing_period_id = $periodId")->fetchColumn();
echo "Total Readings: $count\n";

$anomalies = $db->query("SELECT COUNT(*) FROM readings WHERE billing_period_id = $periodId AND is_afwijking = 1")->fetchColumn();
echo "Anomalies: $anomalies\n";

$scenarios = $db->query("SELECT scenario, COUNT(*) FROM readings WHERE billing_period_id = $periodId GROUP BY scenario")->fetchAll();
echo "Scenarios:\n";
foreach($scenarios as $s) echo "  {$s['scenario']}: {$s['COUNT(*)']}\n";

echo "\nSample Readings (first 5):\n";
$samples = $db->query("SELECT r.lot_id, l.lot_number, l.resident_email, r.scenario, r.is_afwijking, r.afwijking_reden 
                       FROM readings r 
                       JOIN lots l ON l.id = r.lot_id 
                       WHERE r.billing_period_id = $periodId 
                       LIMIT 5")->fetchAll();
print_r($samples);
