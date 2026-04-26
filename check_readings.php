<?php
require_once 'backend/core/Database.php';
$db = Database::getConnection();
$s = $db->query('SELECT id, lot_id, period_months, scenario FROM readings LIMIT 10');
while($r = $s->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}
