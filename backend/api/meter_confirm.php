<?php
/**
 * Meter Confirm API Endpoint
 * Verwerkt de definitieve opslag van de foto en de meterstand in de database.
 * SECURITY: lot_id wordt server-side uit de sessie verkregen, nooit vanuit de client.
 */

header('Content-Type: application/json');

// Initialize dependencies
require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/FileService.php';
require_once __DIR__ . '/../services/ReadingService.php';

use Horsterwold\Services\FileService;
use Horsterwold\Services\ReadingService;

try {
    // SECURITY: Check of de gebruiker is ingelogd
    if (!AuthService::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Niet ingelogd.']);
        exit;
    }

    $input     = json_decode(file_get_contents('php://input'), true);
    $imageData = $input['image'] ?? null;
    $type      = $input['type'] ?? 'unknown';
    $reading   = $input['reading'] ?? null;

    if (!$imageData || $reading === null) {
        throw new Exception('Onvoldoende gegevens ontvangen.');
    }

    // SECURITY: lot_id NOOIT van de client accepteren.
    // Wordt server-side uit de sessie gehaald (gezet door AuthService bij login)
    $lotId = $_SESSION['lot_id'] ?? null;

    if (!$lotId) {
        throw new Exception('Sessie verlopen of geen kavel gekoppeld. Log opnieuw in.');
    }

    // 1. Valideer afbeeldingsdata
    $rawImage = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $imageData));
    $info = @getimagesizefromstring($rawImage);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'])) {
        throw new Exception('Ongeldig afbeeldingsformaat. Alleen JPEG, PNG of WebP is toegestaan.');
    }

    // 2. Sla afbeelding op
    $fileService = new FileService();
    $filename = "lot" . $lotId . "_" . $type . "_" . date('His');
    $storageResult = $fileService->storeBase64Image($imageData, $filename);

    // 3. Sla meterstand op
    $readingService = new ReadingService();
    $readingId = $readingService->saveReading([
        'lot_id'          => $lotId,
        'type'            => $type,
        'reading'         => $reading,
        'image_url'       => $storageResult['url'],
        'exif_timestamp'  => $storageResult['exif_date'],
        'is_manual_correction' => (int)($input['is_manual_correction'] ?? 0)
    ]);

    echo json_encode([
        'success'   => true,
        'reading_id'=> $readingId,
        'image_url' => $storageResult['url'],
        'exif_date' => $storageResult['exif_date']
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
