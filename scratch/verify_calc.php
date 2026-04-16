<?php
require 'backend/services/InvoiceService.php';
use Horsterwold\Services\InvoiceService;

$service = new InvoiceService();
$activePeriod = Database::getActivePeriod();

// We seeded lot_number 0 as a relocation with 4 months
$db = Database::getConnection();
$lot = $db->query("SELECT id FROM lots WHERE lot_number = '0'")->fetch();

if (!$lot) die("Lot 0 not found.\n");

// First approve the reading (InvoiceService needs approved readings)
$db->exec("UPDATE readings SET status = 'approved' WHERE lot_id = {$lot['id']} AND billing_period_id = {$activePeriod['id']}");

$preview = $service->calculatePreview($lot['id'], $activePeriod['id']);

echo "Lot 0 (Relocation, 4 months) Calculation:\n";
echo "Months: " . ($preview['lot']['period_months'] ?? '??') . "\n";
echo "Fixed Gas: " . $preview['fixed']['gas'] . " (Expected: monthly * 4)\n";
echo "Fixed VVE: " . $preview['fixed']['vve'] . " (Expected: annual / 3)\n";
echo "Total: " . $preview['summary']['total'] . "\n";

// To be safe, set back to pending if needed, but for test it's fine.
