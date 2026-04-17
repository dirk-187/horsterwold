<?php
require_once __DIR__ . '/../services/OcrService.php';
require_once __DIR__ . '/../config.php';

$service = new Horsterwold\Services\OcrService();

$files = [
    'gas' => 'c:/xampp/htdocs/horsterwold/documenten/meters/horsterwold meters/test/6101.jpg',
    'elec' => 'c:/xampp/htdocs/horsterwold/documenten/meters/horsterwold meters/test/8199.jpg'
];

foreach ($files as $type => $path) {
    echo "=== SYMBOL DUMP FOR $path ($type) ===\n";
    $content = file_get_contents($path);
    
    // We'll use reflection to call extractMeterData or just modify OcrService again to log everything.
    // Modifying OcrService is easier to get the integrated context.
    $service->detectMeterReading($content, $type);
}
