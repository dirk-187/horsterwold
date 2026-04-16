<?php
require 'backend/core/Database.php';

$db = Database::getConnection();

$stmt = $db->query("
    SELECT ih.*, bp.label as period_name 
    FROM import_history ih 
    JOIN billing_periods bp ON bp.id = ih.billing_period_id
    WHERE ih.lot_id = 1
    ORDER BY ih.id DESC
");
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "ORDER BY ih.id DESC:\n";
print_r($history);

$stmt = $db->query("
    SELECT ih.*, bp.label as period_name 
    FROM import_history ih 
    JOIN billing_periods bp ON bp.id = ih.billing_period_id
    WHERE ih.lot_id = 1
    ORDER BY bp.year DESC
");
echo "\nORDER BY bp.year DESC:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

