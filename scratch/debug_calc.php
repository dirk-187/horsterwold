<?php
require 'backend/core/Database.php';
$db = Database::getConnection();

echo "--- Lot 0 ---\n";
print_r($db->query("SELECT * FROM lots WHERE lot_number = '0'")->fetch(PDO::FETCH_ASSOC));

echo "\n--- Templates (Period 3) ---\n";
print_r($db->query("SELECT * FROM fixed_costs_templates WHERE billing_period_id = 3")->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- Approved Reading (Lot 0, Period 3) ---\n";
print_r($db->query("SELECT * FROM readings WHERE lot_id = (SELECT id FROM lots WHERE lot_number = '0') AND billing_period_id = 3 AND status = 'approved'")->fetch(PDO::FETCH_ASSOC));
