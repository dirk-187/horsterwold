<?php
require_once 'backend/core/Database.php';
$db = Database::getConnection();
$s = $db->query('SELECT * FROM fixed_costs_templates');
while($r = $s->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}
