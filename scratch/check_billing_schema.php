<?php
require 'backend/core/Database.php';
$db = Database::getConnection();
echo "--- BILLING RESULTS SCHEMA ---\n";
print_r($db->query("DESC billing_results")->fetchAll(PDO::FETCH_ASSOC));
