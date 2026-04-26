<?php
require_once 'backend/core/Database.php';
$db = Database::getConnection();

// Mock active period
$activePeriodId = 1; 

// Run the query from admin.php
$stmt = $db->prepare("
    SELECT 
        l.id, l.lot_number, l.lot_type,
        lo.resident_name,
        COALESCE(SUM(r.gas_consumption), 0) as total_gas,
        COALESCE(SUM(r.water_consumption), 0) as total_water,
        COALESCE(SUM(r.electricity_consumption), 0) as total_elec,
        COALESCE(SUM(r.period_months), 0) as total_months
    FROM lots l
    LEFT JOIN lot_occupancy lo ON lo.lot_id = l.id AND lo.is_active = 1
    LEFT JOIN readings r ON r.lot_id = l.id AND r.billing_period_id = ? AND r.status = 'approved'
    WHERE l.lot_type = 'bebouwd'
    GROUP BY l.id
    LIMIT 5
");
$stmt->execute([$activePeriodId]);
$usage = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtT = $db->prepare("SELECT * FROM tariffs WHERE billing_period_id = ?");
$stmtT->execute([$activePeriodId]);
$tariffs = $stmtT->fetch(PDO::FETCH_ASSOC);

$stmtF = $db->prepare("SELECT * FROM fixed_costs_templates WHERE billing_period_id = ? AND lot_type = 'bebouwd'");
$stmtF->execute([$activePeriodId]);
$fixed = $stmtF->fetch(PDO::FETCH_ASSOC);

foreach ($usage as $u) {
    $months = max(1, (int)$u['total_months']);
    $mGas = ($u['total_gas'] / $months) * $tariffs['gas_price_per_m3'];
    $mWater = ($u['total_water'] / $months) * $tariffs['water_price_per_m3'];
    $mElec = ($u['total_elec'] / $months) * $tariffs['electricity_price_per_kwh'];
    
    $mFixed = $fixed['vast_gas_per_month'] + $fixed['vast_water_per_month'] + $fixed['vast_electricity_per_month'];
    $mFixed += ($fixed['vve_per_year'] / 12) + ($fixed['erfpacht_per_year'] / 12);
    
    $newAdvance = round($mGas + $mWater + $mElec + $mFixed, 2);
    
    echo "Lot #{$u['lot_number']}: Total Months: $months, Total Gas: {$u['total_gas']}, Monthly Gas Cost: $mGas, Fixed: $mFixed, Total: $newAdvance\n";
}
