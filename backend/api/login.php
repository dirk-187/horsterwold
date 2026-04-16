<?php
/**
 * Login API Endpoint
 * Verwerkt Magic Link aanvragen en validatie — voor zowel bewoners als beheerders.
 */

ob_start(); // Start output buffering
header('Content-Type: application/json');

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/AuthService.php';

try {
    $authService = new AuthService();
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'request':
            $email   = trim($input['email'] ?? '');
            $isAdmin = isset($input['is_admin']) && $input['is_admin'] === true;

            if (empty($email)) throw new Exception('E-mailadres is verplicht');

            $link = $authService->generateMagicLink($email, $isAdmin);

            if ($link) {
                // In productie: hier een e-mail sturen.
                $mailSent = false;
                if ($isAdmin) {
                    require_once __DIR__ . '/../services/MailService.php';
                    $mailService = new \Horsterwold\Services\MailService();
                    $mailSent = $mailService->sendAdminLoginEmail($email, $link);
                    if (!$mailSent) {
                        throw new Exception('E-mail kon niet worden verzonden. Controleer de netwerkinstellingen.');
                    }
                }

                $obContent = ob_get_clean(); // Discard any warnings from sending the mail
                // In development: geef de url terug voor testen.
                echo json_encode([
                    'success' => true,
                    'message' => 'Check je mailbox voor de inloglink!',
                    'debug_link' => (defined('APP_ENV') && APP_ENV === 'development') ? $link : null,
                    'debug_output' => $obContent
                ]);
            } else {
                throw new Exception('E-mailadres niet gevonden');
            }
            break;

        case 'verify':
            $token = $input['token'] ?? '';
            if (empty($token)) throw new Exception('Token is verplicht');

            $user = $authService->verifyMagicLink($token);
            if ($user) {
                $authService->startSession($user);
                ob_get_clean();
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                throw new Exception('Ongeldige of verlopen link. Vraag een nieuwe aan.');
            }
            break;

        case 'check':
            if (AuthService::isLoggedIn()) {
                $db = Database::getConnection();
                $user = null;

                if ($_SESSION['user_id'] > 0) {
                    // Admin or older user linkage
                    $stmt = $db->prepare('SELECT id, email, name, role FROM users WHERE id = ?');
                    $stmt->execute([$_SESSION['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } elseif (isset($_SESSION['lot_id'])) {
                    // New resident logic: get from lots
                    $stmt = $db->prepare('SELECT 0 as id, resident_email as email, resident_name as name, "resident" as role, id as lot_id, lot_number FROM lots WHERE id = ?');
                    $stmt->execute([$_SESSION['lot_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($user) {
                    ob_get_clean();
                    echo json_encode(['success' => true, 'user' => $user]);
                } else {
                    ob_get_clean();
                    echo json_encode(['success' => false]);
                }
            } else {
                ob_get_clean();
                echo json_encode(['success' => false]);
            }
            break;

        case 'logout':
            AuthService::logout();
            ob_get_clean();
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Ongeldige actie');
    }
} catch (\Throwable $e) {
    http_response_code(200);
    $obContent = ob_get_clean();
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'debug_output' => $obContent
    ]);
}
