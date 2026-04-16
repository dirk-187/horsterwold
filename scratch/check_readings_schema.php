<?php
require 'backend/core/Database.php';
$db = Database::getConnection();
$stmt = $db->query("SHOW CREATE TABLE readings");
echo $stmt->fetchColumn(1);
