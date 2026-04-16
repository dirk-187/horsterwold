<?php
/**
 * User API Endpoint
 * Handles actions specific to the logged-in resident (PWA users)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/AuthService.php';

$authService = new AuthService();
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? '');

try {
    // Only logged in users can access this API
    if (!AuthService::isLoggedIn()) {
        throw new Exception('Niet ingelogd');
    }

    $userId = $_SESSION['user_id'];
    $db = Database::getConnection();

    switch ($action) {
        case 'save-incasso-mandate':
            $agreed = $input['agreed'] ?? false;
            
            if (!$agreed) {
                throw new Exception('U moet akkoord gaan met de machtiging');
            }

            // Eerst controleren of automatische incasso wel mag voor deze kavel
            $stmt = $db->prepare("
                SELECT l.allow_direct_debit 
                FROM lots l 
                WHERE l.user_id = ?
            ");
            $stmt->execute([$userId]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lot || $lot['allow_direct_debit'] != 1) {
                throw new Exception('Automatische incasso is niet beschikbaar voor uw kavel');
            }

            $iban = $input['iban'] ?? null;
            if (!$iban) throw new Exception('Geen IBAN opgegeven');

            // Datum opslaan
            $mandateDate = date('Y-m-d H:i:s');
            $stmt = $db->prepare("UPDATE users SET incasso_mandate_date = ?, iban_number = ? WHERE id = ?");
            $stmt->execute([$mandateDate, $iban, $userId]);

            echo json_encode(['success' => true, 'mandate_date' => $mandateDate]);
            break;

        default:
            throw new Exception('Ongeldige actie');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
