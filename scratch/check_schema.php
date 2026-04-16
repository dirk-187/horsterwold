<?php
require 'backend/core/Database.php';
$db = Database::getConnection();
$cols = $db->query("DESC readings")->fetchAll(PDO::FETCH_ASSOC);
print_r($cols);
