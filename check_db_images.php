<?php
require_once __DIR__ . '/backend/core/Database.php';
$db = Database::getConnection();
$stmt = $db->query("SELECT id, lot_id, image_url, image_url_gas, image_url_water, image_url_elec FROM readings ORDER BY id DESC LIMIT 5");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($results, JSON_PRETTY_PRINT);
