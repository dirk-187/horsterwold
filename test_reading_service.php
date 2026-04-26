<?php
require_once __DIR__ . '/backend/core/Database.php';
require_once __DIR__ . '/backend/services/ReadingService.php';
require_once __DIR__ . '/backend/services/AfwijkingService.php';

// Mock session
session_start();
$_SESSION['user_id'] = 1;

try {
    $service = new Horsterwold\Services\ReadingService();
    $id = $service->saveReading([
        'lot_id' => 205,
        'type' => 'gas',
        'reading' => 123.456,
        'image_url' => 'test_gas.jpg',
        'exif_timestamp' => date('Y-m-d H:i:s')
    ]);
    echo "Saved Gas reading ID: $id\n";
    
    $id2 = $service->saveReading([
        'lot_id' => 205,
        'type' => 'water',
        'reading' => 789.012,
        'image_url' => 'test_water.jpg',
        'exif_timestamp' => date('Y-m-d H:i:s')
    ]);
    echo "Updated with Water reading ID: $id2\n";
    
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT * FROM readings WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "DB Row:\n";
    print_r($row);
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
