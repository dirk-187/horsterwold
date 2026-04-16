<?php
/**
 * Meter Upload API Endpoint
 * Verwerkt de geüploade foto en de OCR-aanvraag
 */

header('Content-Type: application/json');

// Initialize dependencies
require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/OcrService.php';

use Horsterwold\Services\OcrService;

try {
    // SECURITY: Check of de gebruiker is ingelogd
    if (!AuthService::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Niet ingelogd.']);
        exit;
    }

    // We expect a base64 image string or a multipart upload
    $input = json_decode(file_get_contents('php://input'), true);
    $imageData = $input['image'] ?? null;
    $meterType = $input['type'] ?? 'unknown';

    if (!$imageData) {
        throw new Exception('Geen afbeelding ontvangen.');
    }

    // Clean base64 data (if data:image/jpeg;base64, prefix is present)
    if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
        $imageData = substr($imageData, strpos($imageData, ',') + 1);
        $imageData = base64_decode($imageData);

        if ($imageData === false) {
            throw new Exception('Fout bij het decoderen van de afbeelding.');
        }
    } else {
        throw new Exception('Ongeldig afbeeldingsformaat.');
    }

    // Initialize OCR Service
    $ocrService = new OcrService();
    $ocrResult = $ocrService->detectMeterReading($imageData, $meterType);

    if ($ocrResult === null || !isset($ocrResult['reading'])) {
        throw new Exception('Geen meterstand gevonden. Probeer de foto opnieuw te maken.');
    }

    // Deliver success response
    echo json_encode([
        'success' => true,
        'reading' => $ocrResult['reading'],
        'meter_number' => $ocrResult['meter_number'] ?? null,
        'type' => $meterType
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
