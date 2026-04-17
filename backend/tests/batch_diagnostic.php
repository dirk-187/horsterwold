<?php
/**
 * Batch Diagnostic Script for OCR Meter Recognition
 * This script tries all 3 meter types for a given set of images and finds the best match.
 */

require_once __DIR__ . '/../services/OcrService.php';
require_once __DIR__ . '/../config.php';

$testDir = 'c:/xampp/htdocs/horsterwold/documenten/meters/horsterwold meters/test';
$service = new Horsterwold\Services\OcrService();

// Ensure Google Key exists
if (!file_exists(__DIR__ . '/../config/google-key.json')) {
    die("Google Key not found at backend/config/google-key.json\n");
}

$files = glob("$testDir/*.{jpg,jpeg}", GLOB_BRACE);

echo "Batch Diagnostic Start...\n";
echo str_repeat("=", 80) . "\n";
echo sprintf("%-20s | %-10s | %-10s | %-10s | %-15s\n", "Bestand", "Verwacht", "Type", "Actueel", "Resultaat");
echo str_repeat("-", 80) . "\n";

$results = [];

foreach ($files as $file) {
    $filename = basename($file);
    // Extract expected value: take the number part from filename
    preg_match('/(\d+)/', $filename, $matches);
    $expected = $matches[1] ?? 'UNKNOWN';
    
    $imageContent = file_get_contents($file);
    
    $bestType = 'NONE';
    $bestResult = 'NULL';
    $matchFound = false;
    
    foreach (['gas', 'water', 'elec'] as $type) {
        try {
            $response = $service->detectMeterReading($imageContent, $type);
            $actual = $response['reading'];
            
            // Allow for leading zeros differences if any
            if ($actual !== null && ltrim($actual, '0') === ltrim($expected, '0')) {
                $bestType = strtoupper($type);
                $bestResult = $actual;
                $matchFound = true;
                break; // Found a match!
            }
            
            // Keep track of any non-null result if no match yet
            if ($actual !== null && $bestType === 'NONE') {
                $bestType = strtoupper($type);
                $bestResult = $actual;
            }
        } catch (Exception $e) {
            // Skip errors
        }
    }
    
    $status = $matchFound ? "[PASSED]" : "[FAILED]";
    echo sprintf("%-20s | %-10s | %-10s | %-10s | %-15s\n", 
        substr($filename, 0, 20), 
        $expected, 
        $bestType, 
        $bestResult, 
        $status
    );
}

echo str_repeat("=", 80) . "\n";
echo "Batch Diagnostic Finish.\n";
