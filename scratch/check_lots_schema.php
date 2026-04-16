<?php
require 'backend/core/Database.php';
$db = Database::getConnection();
echo "--- LOTS TABLE ---\n";
print_r($db->query("DESC lots")->fetchAll(PDO::FETCH_ASSOC));
