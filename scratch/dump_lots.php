<?php
require 'backend/core/Database.php';
$db = Database::getConnection();
$stmt = $db->query("SELECT id, lot_number, user_id, resident_name, resident_email, address FROM lots LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
