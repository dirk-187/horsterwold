<?php
/**
 * run_ocr_benchmark.php
 * Script om de OCR herkenning structureel te testen tegen een dataset.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Horsterwold\Services\OcrService;

// Zet foutrapportage aan voor CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "================================================\n";
echo "   OCR Meterherkenning Benchmark Systeem\n";
echo "================================================\n\n";

$ocrService = new OcrService();
$datasetDir = __DIR__ . '/ocr_dataset';
$categories = ['gas', 'water', 'elec'];

$totalTests = 0;
$totalPassed = 0;

$results = [];

foreach ($categories as $category) {
    $dirPath = $datasetDir . '/' . $category;
    if (!is_dir($dirPath)) {
        echo "Warning: Map niet gevonden: $dirPath\n";
        continue;
    }

    echo "Scanning category: " . strtoupper($category) . "...\n";
    $files = scandir($dirPath);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (strpos($file, 'todo_') === 0) {
            echo "Skipping TODO file: $file\n";
            continue;
        }

        $filePath = $dirPath . '/' . $file;
        $expected = pathinfo($file, PATHINFO_FILENAME);
        // Remove trailing letters/numbers used for duplicates (e.g. 12345_2.jpg -> 12345)
        $expected = preg_replace('/_.*$/', '', $expected);

        try {
            $imageContent = file_get_contents($filePath);
            $startTime = microtime(true);
            $ocrResult = $ocrService->detectMeterReading($imageContent, $category);
            $duration = round(microtime(true) - $startTime, 2);

            $actual = $ocrResult['reading'] ?? 'NULL';
            $isValid = ($ocrResult['validation']['valid'] ?? false) ? 'YES' : 'NO';
            $passed = ($actual === $expected);

            $results[] = [
                'type' => strtoupper($category),
                'file' => $file,
                'expected' => $expected,
                'actual' => $actual,
                'valid' => $isValid,
                'passed' => $passed ? 'PASSED' : 'FAILED',
                'time' => $duration . 's'
            ];

            $totalTests++;
            if ($passed) $totalPassed++;

            echo "  - $file: " . ($passed ? "[PASSED]" : "[FAILED!]") . " (Exp: $expected, Act: $actual)\n";

        } catch (Exception $e) {
            echo "  - $file: ERROR - " . $e->getMessage() . "\n";
            $results[] = [
                'type' => strtoupper($category),
                'file' => $file,
                'expected' => $expected,
                'actual' => 'ERROR',
                'valid' => 'N/A',
                'passed' => 'ERROR',
                'time' => '0s'
            ];
        }
    }
    echo "\n";
}

// Print Tabel
echo "\nRESUMÉ:\n";
echo str_repeat("-", 85) . "\n";
printf("%-8s | %-20s | %-10s | %-10s | %-8s | %-8s\n", "Type", "Bestand", "Verwacht", "Actueel", "Match", "Tijd");
echo str_repeat("-", 85) . "\n";

foreach ($results as $r) {
    printf("%-8s | %-20s | %-10s | %-10s | %-8s | %-8s\n", 
        $r['type'], 
        substr($r['file'], 0, 20), 
        $r['expected'], 
        $r['actual'], 
        $r['passed'], 
        $r['time']
    );
}

echo str_repeat("-", 85) . "\n";
$accuracy = ($totalTests > 0) ? round(($totalPassed / $totalTests) * 100, 1) : 0;
echo "TOTAAL: $totalPassed van de $totalTests geslaagd ($accuracy%)\n";
echo "================================================\n";
