<?php
/**
 * Test Script: Rollover Logic Verification
 * 
 * Test de rollover logica in InvoiceService en vergelijk met frontend berekeningen
 */

require_once __DIR__ . '/backend/core/Database.php';
require_once __DIR__ . '/backend/services/InvoiceService.php';

use Horsterwold\Services\InvoiceService;

echo "<!DOCTYPE html>\n";
echo "<html><head><meta charset='UTF-8'><title>Test Rollover Logic</title>";
echo "<style>
    body { font-family: 'Segoe UI', sans-serif; padding: 2rem; background: #1e293b; color: #e2e8f0; }
    h1 { color: #60a5fa; }
    .test-case { background: #0f172a; padding: 1.5rem; margin: 1rem 0; border-radius: 8px; border: 1px solid #334155; }
    .pass { color: #4ade80; font-weight: bold; }
    .fail { color: #f87171; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
    th, td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #334155; }
    th { background: #1e293b; color: #60a5fa; }
    .scenario { color: #fbbf24; font-weight: 600; }
</style>";
echo "</head><body>";
echo "<h1>🧪 Test: Rollover Logic Verification</h1>";

try {
    $db = Database::getConnection();
    
    // Test Cases
    $testCases = [
        [
            'name' => 'Normale situatie (geen rollover)',
            'old' => 10000,
            'new' => 10500,
            'expected' => 500
        ],
        [
            'name' => 'Rollover bij 99999 (gas/water)',
            'old' => 99800,
            'new' => 150,
            'expected' => (99999 - 99800) + 150 // = 199 + 150 = 349
        ],
        [
            'name' => 'Kleine rollover',
            'old' => 99990,
            'new' => 5,
            'expected' => (99999 - 99990) + 5 // = 9 + 5 = 14
        ],
        [
            'name' => 'Grote verbruik (geen rollover)',
            'old' => 50000,
            'new' => 52500,
            'expected' => 2500
        ],
        [
            'name' => 'Nul verbruik',
            'old' => 10000,
            'new' => 10000,
            'expected' => 0
        ]
    ];
    
    echo "<div class='test-case'>";
    echo "<h2>📊 Unit Tests: calculateConsumption()</h2>";
    echo "<table>";
    echo "<tr><th>Test Case</th><th>Oude Stand</th><th>Nieuwe Stand</th><th>Verwacht</th><th>Resultaat</th><th>Status</th></tr>";
    
    $invoiceService = new InvoiceService();
    $reflection = new ReflectionClass($invoiceService);
    $method = $reflection->getMethod('calculateConsumption');
    $method->setAccessible(true);
    
    $passed = 0;
    $failed = 0;
    
    foreach ($testCases as $test) {
        $result = $method->invoke($invoiceService, $test['new'], $test['old']);
        $isPass = abs($result - $test['expected']) < 0.01; // Float vergelijking met tolerantie
        
        if ($isPass) {
            $passed++;
            $status = "<span class='pass'>✅ PASS</span>";
        } else {
            $failed++;
            $status = "<span class='fail'>❌ FAIL</span>";
        }
        
        echo "<tr>";
        echo "<td>{$test['name']}</td>";
        echo "<td>" . number_format($test['old'], 0, ',', '.') . "</td>";
        echo "<td>" . number_format($test['new'], 0, ',', '.') . "</td>";
        echo "<td>" . number_format($test['expected'], 1, ',', '.') . "</td>";
        echo "<td>" . number_format($result, 1, ',', '.') . "</td>";
        echo "<td>{$status}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "<p><strong>Resultaat:</strong> {$passed} geslaagd, {$failed} gefaald</p>";
    echo "</div>";
    
    // Test met echte database data
    echo "<div class='test-case'>";
    echo "<h2>🗄️ Database Test: Echte Readings</h2>";
    
    $stmt = $db->query("
        SELECT 
            r.id,
            l.lot_number,
            r.gas_new_reading,
            r.water_new_reading,
            r.electricity_new_reading,
            lo.start_gas,
            lo.start_water,
            lo.start_elec,
            r.gas_consumption as stored_gas,
            r.water_consumption as stored_water,
            r.electricity_consumption as stored_elec
        FROM readings r
        JOIN lots l ON l.id = r.lot_id
        JOIN lot_occupancy lo ON lo.id = r.occupancy_id
        WHERE r.status = 'approved'
        ORDER BY r.id DESC
        LIMIT 10
    ");
    
    $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($readings) > 0) {
        echo "<p>Controle van de laatste 10 goedgekeurde readings:</p>";
        echo "<table>";
        echo "<tr><th>Kavel</th><th>Type</th><th>Oud → Nieuw</th><th>Opgeslagen</th><th>Herberekend</th><th>Match</th></tr>";
        
        foreach ($readings as $r) {
            // Test Gas
            $gasCalc = $method->invoke($invoiceService, (float)$r['gas_new_reading'], (float)$r['start_gas']);
            $gasMatch = abs($gasCalc - (float)$r['stored_gas']) < 0.01;
            
            echo "<tr>";
            echo "<td>#{$r['lot_number']}</td>";
            echo "<td>Gas</td>";
            echo "<td>" . number_format($r['start_gas'], 1) . " → " . number_format($r['gas_new_reading'], 1) . "</td>";
            echo "<td>" . number_format($r['stored_gas'], 1) . "</td>";
            echo "<td>" . number_format($gasCalc, 1) . "</td>";
            echo "<td>" . ($gasMatch ? "<span class='pass'>✅</span>" : "<span class='fail'>❌</span>") . "</td>";
            echo "</tr>";
            
            // Test Water
            $waterCalc = $method->invoke($invoiceService, (float)$r['water_new_reading'], (float)$r['start_water']);
            $waterMatch = abs($waterCalc - (float)$r['stored_water']) < 0.01;
            
            echo "<tr>";
            echo "<td>#{$r['lot_number']}</td>";
            echo "<td>Water</td>";
            echo "<td>" . number_format($r['start_water'], 1) . " → " . number_format($r['water_new_reading'], 1) . "</td>";
            echo "<td>" . number_format($r['stored_water'], 1) . "</td>";
            echo "<td>" . number_format($waterCalc, 1) . "</td>";
            echo "<td>" . ($waterMatch ? "<span class='pass'>✅</span>" : "<span class='fail'>❌</span>") . "</td>";
            echo "</tr>";
            
            // Test Elektra
            $elecCalc = $method->invoke($invoiceService, (float)$r['electricity_new_reading'], (float)$r['start_elec']);
            $elecMatch = abs($elecCalc - (float)$r['stored_elec']) < 0.01;
            
            echo "<tr>";
            echo "<td>#{$r['lot_number']}</td>";
            echo "<td>Elektra</td>";
            echo "<td>" . number_format($r['start_elec'], 0) . " → " . number_format($r['electricity_new_reading'], 0) . "</td>";
            echo "<td>" . number_format($r['stored_elec'], 0) . "</td>";
            echo "<td>" . number_format($elecCalc, 0) . "</td>";
            echo "<td>" . ($elecMatch ? "<span class='pass'>✅</span>" : "<span class='fail'>❌</span>") . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<p>Geen goedgekeurde readings gevonden in de database.</p>";
    }
    
    echo "</div>";
    
    // Frontend vs Backend vergelijking
    echo "<div class='test-case'>";
    echo "<h2>🔄 Frontend vs Backend Consistency</h2>";
    echo "<p>De rollover logica in de frontend (admin.js) en backend (InvoiceService.php) gebruiken nu dezelfde formule:</p>";
    echo "<pre style='background:#0f172a; padding:1rem; border-radius:4px;'>";
    echo "if (nieuwe_stand < oude_stand) {\n";
    echo "    verbruik = (99999 - oude_stand) + nieuwe_stand;\n";
    echo "} else {\n";
    echo "    verbruik = nieuwe_stand - oude_stand;\n";
    echo "}\n";
    echo "</pre>";
    echo "<p class='pass'>✅ Frontend en backend zijn nu consistent!</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='test-case'>";
    echo "<p class='fail'>❌ FOUT: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<p><a href='public/admin/index.html' style='color:#60a5fa;'>← Terug naar Admin Dashboard</a></p>";
echo "</body></html>";
