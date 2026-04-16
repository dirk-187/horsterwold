<?php
/**
 * Test Data Manager for Horsterwold
 * Usage: 
 *   php manage_test_data.php seed
 *   php manage_test_data.php clear
 *   php manage_test_data.php init
 */

require_once __DIR__ . '/../backend/core/Database.php';

$db = Database::getConnection();
$action = $argv[1] ?? 'help';

$activePeriod = Database::getActivePeriod();
if (!$activePeriod) {
    die("No active period found. Please create one in the database first.\n");
}
$periodId = $activePeriod['id'];

switch ($action) {
    case 'init':
        echo "Updating demo lot 999 to 0...\n";
        $db->exec("UPDATE lots SET lot_number = '0' WHERE lot_number = '999'");
        
        echo "Setting up sample tariffs/templates for Period $periodId...\n";
        // Tariffs
        $db->exec("INSERT IGNORE INTO tariffs (billing_period_id, gas_price_per_m3, water_price_per_m3, electricity_price_per_kwh, solar_return_price_per_kwh) 
                   VALUES ($periodId, 1.45, 1.10, 0.35, 0.15)");
        
        // Fixed costs templates
        $db->exec("UPDATE fixed_costs_templates SET 
                   vast_gas_per_month = 5.00, 
                   vast_water_per_month = 2.50, 
                   vast_electricity_per_month = 10.00,
                   vve_per_year = 1200.00,
                   erfpacht_per_year = 2400.00
                   WHERE billing_period_id = $periodId AND lot_type = 'bebouwd'");
        
        $db->exec("UPDATE fixed_costs_templates SET 
                   vve_per_year = 600.00,
                   erfpacht_per_year = 1200.00
                   WHERE billing_period_id = $periodId AND lot_type = 'onbebouwd'");
                   
        echo "Done.\n";
        break;

    case 'seed':
        echo "Seeding test readings for Period $periodId ({$activePeriod['year']})...\n";
        
        // 1. Get all lot baselines (last year's end)
        $stmt = $db->prepare("
            SELECT l.id as lot_id, 
                   COALESCE(prev_ih.gas_new_reading, 0) as gas, 
                   COALESCE(prev_ih.water_new_reading, 0) as water, 
                   COALESCE(prev_ih.electricity_new_reading, 0) as elec
            FROM lots l
            LEFT JOIN import_history prev_ih ON prev_ih.lot_id = l.id AND prev_ih.billing_period_id = (
                SELECT ih2.billing_period_id 
                FROM import_history ih2
                JOIN billing_periods bp2 ON bp2.id = ih2.billing_period_id
                WHERE ih2.lot_id = l.id AND bp2.year < ?
                ORDER BY bp2.year DESC LIMIT 1
            )
        ");
        $stmt->execute([$activePeriod['year']]);
        $baselines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $db->beginTransaction();
        try {
            // Clear existing for this period
            $db->exec("DELETE FROM readings WHERE billing_period_id = $periodId");

            $stmtInsert = $db->prepare("INSERT INTO readings 
                (lot_id, billing_period_id, scenario, status, reading_date, 
                 gas_prev_reading, gas_new_reading, gas_consumption, 
                 water_prev_reading, water_new_reading, water_consumption, 
                 electricity_prev_reading, electricity_new_reading, electricity_consumption,
                 is_afwijking, afwijking_reden, period_months) 
                VALUES (?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($baselines as $b) {
                $lotId = $b['lot_id'];
                $scenario = 'jaarafrekening';
                $months = 12;
                $gasCons = 10;
                $waterCons = 2;
                $elecCons = 100;
                $isAfwijking = 0;
                $reden = null;

                // Lot 10: Anomaly
                if ($lotId == 10) {
                    $gasCons = 50;
                    $isAfwijking = 1;
                    $reden = "Gasverbruik wijkt 400.0% af";
                }
                
                // Lot 0 (formerly 999): Relocation scenario
                // Find lot ID for lot number 0
                $lot0 = $db->query("SELECT id FROM lots WHERE lot_number = '0'")->fetch();
                if ($lot0 && $lotId == $lot0['id']) {
                    $scenario = 'verhuizing';
                    $months = 4; // 4 months for testing
                }

                $stmtInsert->execute([
                    $lotId,
                    $periodId,
                    $scenario,
                    $b['gas'], (float)$b['gas'] + $gasCons, $gasCons,
                    $b['water'], (float)$b['water'] + $waterCons, $waterCons,
                    $b['elec'], (float)$b['elec'] + $elecCons, $elecCons,
                    $isAfwijking, $reden,
                    $months
                ]);
            }
            $db->commit();
            echo "Successfully seeded " . count($baselines) . " readings.\n";
            echo "Scenario: Lot #0 is set to RELOCATION (4 months).\n";
        } catch (Exception $e) {
            $db->rollBack();
            die("Error: " . $e->getMessage() . "\n");
        }
        break;

    case 'clear':
        echo "Clearing readings and billing results for Period $periodId...\n";
        $db->exec("DELETE FROM readings WHERE billing_period_id = $periodId");
        $db->exec("DELETE FROM billing_results WHERE billing_period_id = $periodId");
        echo "Done.\n";
        break;

    case 'seed-test-2026':
        echo "Seeding special test scenario for 20 lots in Period $periodId ({$activePeriod['year']})...\n";
        
        // 1. Get first 20 lots
        $lots = $db->query("SELECT * FROM lots ORDER BY id LIMIT 20")->fetchAll();
        
        $db->beginTransaction();
        try {
            foreach ($lots as $i => $lot) {
                $lotId = $lot['id'];
                $lotNum = $lot['lot_number'];
                
                // 2. Set test resident data for magic mail
                $testEmail = "test-kavel-{$lotNum}@horsterwold.nl";
                $testName = "Bewoner Kavel {$lotNum}";
                $db->prepare("UPDATE lots SET resident_email = ?, resident_name = ? WHERE id = ?")
                   ->execute([$testEmail, $testName, $lotId]);

                // 3. Get baseline (previous year)
                $stmtPrev = $db->prepare("
                    SELECT gas_new_reading as gas, water_new_reading as water, electricity_new_reading as elec
                    FROM import_history 
                    WHERE lot_id = ? AND billing_period_id = (
                        SELECT id FROM billing_periods WHERE year < ? ORDER BY year DESC LIMIT 1
                    )
                ");
                $stmtPrev->execute([$lotId, $activePeriod['year']]);
                $base = $stmtPrev->fetch() ?: ['gas' => 1000, 'water' => 100, 'elec' => 5000];

                // 4. Define Scenarios
                $scenario = ($i < 12) ? 'jaarafrekening' : 'verhuizing';
                $months = ($scenario == 'jaarafrekening') ? 12 : 5;
                
                // 5. Consumption Logic
                $gasCons = 120; // Default
                $waterCons = 15;
                $elecCons = 250;
                $isAfwijking = 0;
                $reden = null;

                if ($i == 0) { // High Warning
                    $gasCons = 500;
                    $isAfwijking = 1;
                    $reden = "Gasverbruik wijkt 400.0% af";
                } elseif ($i == 1) { // High Warning
                    $waterCons = 80;
                    $isAfwijking = 1;
                    $reden = "Waterverbruik wijkt 500.0% af";
                } elseif ($i == 2) { // Negative Reading
                    $gasCons = -10;
                    $isAfwijking = 1;
                    $reden = "Gasmeterstand lager dan vorige meting";
                } elseif ($i == 3) { // Solar Return (High Production)
                    $elecCons = -1500;
                    $isAfwijking = 0; // Solar return might not be an anomaly if has_solar is true, but let's see
                } elseif ($i == 4) { // Zero
                    $gasCons = 0; $waterCons = 0; $elecCons = 0;
                } elseif ($i == 5) { // High Elec
                    $elecCons = 2000;
                    $isAfwijking = 1;
                    $reden = "Elektraverbruik wijkt sterk af";
                }

                $stmtInsert = $db->prepare("INSERT INTO readings 
                    (lot_id, billing_period_id, scenario, status, reading_date, 
                     gas_prev_reading, gas_new_reading, gas_consumption, 
                     water_prev_reading, water_new_reading, water_consumption, 
                     electricity_prev_reading, electricity_new_reading, electricity_consumption,
                     is_afwijking, afwijking_reden, period_months) 
                    VALUES (?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmtInsert->execute([
                    $lotId, $periodId, $scenario, 
                    $base['gas'], (float)$base['gas'] + $gasCons, $gasCons,
                    $base['water'], (float)$base['water'] + $waterCons, $waterCons,
                    $base['elec'], (float)$base['elec'] + $elecCons, $elecCons,
                    $isAfwijking, $reden, $months
                ]);
            }
            $db->commit();
            echo "Successfully seeded 20 test readings for magic mail and flow testing.\n";
            echo "- 12 Annual, 8 Relocation.\n";
            echo "- Includes anomalies: High gas, High water, Negative gas, Solar return, Zero consumption.\n";
            echo "- Added test emails: test-kavel-X@horsterwold.nl\n";
        } catch (Exception $e) {
            $db->rollBack();
            die("Error seeding: " . $e->getMessage() . "\n");
        }
        break;

    default:
        echo "Usage: php manage_test_data.php [init|seed|clear]\n";
        break;
}
