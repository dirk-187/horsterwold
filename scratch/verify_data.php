<?php
require_once __DIR__ . '/../backend/core/Database.php';
$db = Database::getConnection();
echo "--- LOTS ---\n";
$stmt = $db->query("SELECT lot_number, resident_name, resident_email FROM lots LIMIT 10");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
echo "\n--- IMPORT HISTORY ---\n";
$stmt = $db->query("SELECT lot_id, billing_period_id, gas_new_reading FROM import_history LIMIT 5");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
