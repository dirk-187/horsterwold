<?php
require_once __DIR__ . '/../services/OcrService.php';
require_once __DIR__ . '/../config.php';

$service = new Horsterwold\Services\OcrService();
$content = file_get_contents('c:/xampp/htdocs/horsterwold/documenten/meters/horsterwold meters/test/8199.jpg');

// Use reflection to get the annotation if we want, or just log from within detectMeterReading
// I'll just temporarily modify OcrService to log the raw text.
$response = $service->detectMeterReading($content, 'elec');
echo "Done\n";
