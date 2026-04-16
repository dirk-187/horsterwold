<?php
/**
 * Admin API Endpoint
 * Beheer van kavels, magic links, meterstanden en afwijkingen
 */

header('Content-Type: application/json');
// CORS: geen wildcard — client + server lopen op hetzelfde origin
// Alleen preflight OPTIONS toestaan
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/FileService.php';
require_once __DIR__ . '/../services/ReadingService.php';
require_once __DIR__ . '/../services/InvoiceService.php';
require_once __DIR__ . '/../services/PdfService.php';
require_once __DIR__ . '/../services/MailService.php';
require_once __DIR__ . '/../services/SepaService.php';
require_once __DIR__ . '/../services/OcrService.php';

use Horsterwold\Services\FileService;
use Horsterwold\Services\ReadingService;
use Horsterwold\Services\InvoiceService;
use Horsterwold\Services\PdfService;
use Horsterwold\Services\MailService;
use Horsterwold\Services\SepaService;
use Horsterwold\Services\OcrService;

$db = Database::getConnection();
$authService = new AuthService();

$action = $_GET['action'] ?? '';

// ================================================================
// AUTHENTICATIE: Alleen admin heeft toegang
// ================================================================
if ($action === 'logout') {
    AuthService::logout();
    echo json_encode(['success' => true]);
    exit;
}

if (!AuthService::isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Geen toegang. Log in als beheerder.']);
    exit;
}

// FIX: Sluit de sessie direct na authenticatie om session deadlocks te voorkomen
// o.a. wanneer SMTP email verzenden veel tijd kost. 
session_write_close();

// Helper: magic link status afleiden
function getMagicLinkStatus(?string $token, ?string $expiresAt): string {
    if (!$token) return 'not_sent';
    $now = new DateTime();
    $expires = $expiresAt ? new DateTime($expiresAt) : null;
    if ($expires && $expires < $now) return 'expired';
    return 'valid';
}

try {
    // Actuele periode ophalen
    $activePeriod = Database::getActivePeriod();
    $activePeriodId = $activePeriod['id'] ?? null;

    switch ($action) {

        // ================================================================
        // GET-LOTS: Alle kavels met volledige statusinformatie
        // ================================================================
        case 'get-lots':
            if (!$activePeriodId) {
                throw new Exception("Geen actieve afrekenperiode gevonden. Maak eerst een periode aan in de database.");
            }
            $stmt = $db->prepare("
                SELECT
                    l.id,
                    l.lot_number,
                    l.lot_type,
                    l.address,
                    l.has_gas,
                    l.has_water,
                    l.has_electricity,
                    l.allow_direct_debit,
                    l.notes,
                    -- Gebruiker / magic link (uit lot-tabel)
                    l.magic_link_token,
                    l.token_expires_at,
                    -- Bewoner gegevens uit lot_occupancy (actieve bewoner)
                    lo.id              AS occupancy_id,
                    lo.resident_name   AS user_name,
                    lo.resident_email  AS user_email,
                    COALESCE(lo.is_active, 0) AS is_resident_active,
                    lo.start_date      AS resident_since_date,
                    -- Laatste meting (meest recente)
                    r.id           AS reading_id,
                    r.status       AS reading_status,
                    r.is_afwijking,
                    r.afwijking_reden,
                    r.gas_new_reading               AS curr_gas_reading,
                    r.water_new_reading             AS curr_water_reading,
                    r.electricity_new_reading       AS curr_elec_reading,
                    r.reading_date,
                    r.created_at   AS reading_submitted_at,
                    -- Historie data voor trend (vorig jaar)
                    prev_ih.gas_new_reading         AS baseline_gas,
                    prev_ih.gas_prev_reading        AS prev_year_start_gas,
                    prev_ih.water_new_reading       AS baseline_water,
                    prev_ih.water_prev_reading      AS prev_year_start_water,
                    prev_ih.electricity_new_reading AS baseline_elec,
                    prev_ih.electricity_prev_reading AS prev_year_start_elec,
                    -- Factuur en betaling status
                    (SELECT payment_status FROM billing_results br WHERE br.lot_id = l.id ORDER BY br.calculated_at DESC LIMIT 1) as payment_status,
                    (SELECT sent_at FROM billing_results br WHERE br.lot_id = l.id ORDER BY br.calculated_at DESC LIMIT 1) as sent_at
                FROM lots l
                -- Zoek actieve bewoner
                LEFT JOIN lot_occupancy lo ON lo.lot_id = l.id AND lo.is_active = 1
                -- Dynamic Baseline (Park-breed/Historie)
                LEFT JOIN import_history prev_ih ON prev_ih.lot_id = l.id AND prev_ih.billing_period_id = (
                    SELECT ih2.billing_period_id 
                    FROM import_history ih2
                    JOIN billing_periods bp2 ON bp2.id = ih2.billing_period_id
                    WHERE ih2.lot_id = l.id AND bp2.year < (SELECT year FROM billing_periods WHERE id = :period_id_base)
                    ORDER BY bp2.year DESC LIMIT 1
                )
                LEFT JOIN readings r ON r.id = (
                    SELECT id FROM readings r2
                    WHERE r2.lot_id = l.id AND r2.billing_period_id = :period_id_curr
                    ORDER BY r2.reading_date DESC, r2.created_at DESC
                    LIMIT 1
                )
                ORDER BY l.lot_number ASC
            ");
            $stmt->execute([
                'period_id_base' => $activePeriodId,
                'period_id_curr' => $activePeriodId
            ]);
            $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Uitnodigingsmail status toevoegen + meterstand-wachtstatus
            foreach ($lots as &$lot) {
                $lot['magic_link_status'] = getMagicLinkStatus(
                    $lot['magic_link_token'],
                    $lot['token_expires_at']
                );
                // Wacht op meterstand: uitnodiging verstuurd maar nog geen reading
                $lot['awaiting_reading'] = (
                    $lot['magic_link_status'] === 'valid' &&
                    $lot['reading_id'] === null
                );
                // Veiligheid: token niet doorsturen naar frontend
                unset($lot['magic_link_token']);
            }
            unset($lot);

            // Parkbrede gemiddelden (goedgekeurd voor actuele periode)
            // Fallback naar vorig jaar als er nog geen goedgekeurde metingen zijn
            $avgStmt = $db->prepare("
                SELECT
                    AVG(NULLIF(r.gas_consumption, 0))         AS avg_gas,
                    AVG(NULLIF(r.water_consumption, 0))       AS avg_water,
                    AVG(NULLIF(r.electricity_consumption, 0)) AS avg_elec
                FROM readings r
                WHERE r.status = 'approved' AND r.billing_period_id = :curr_pid
            ");
            $avgStmt->execute(['curr_pid' => $activePeriodId]);
            $averages = $avgStmt->fetch(PDO::FETCH_ASSOC);

            // Als er nog geen metingen zijn in dit jaar, pak dan het gemiddelde verbruik van de import_history
            if (!$averages['avg_gas'] && !$averages['avg_water'] && !$averages['avg_elec']) {
                $histAvgStmt = $db->query("
                    SELECT 
                        AVG(ABS(gas_new_reading - gas_prev_reading)) as avg_gas,
                        AVG(ABS(water_new_reading - water_prev_reading)) as avg_water,
                        AVG(ABS(electricity_new_reading - electricity_prev_reading)) as avg_elec
                    FROM import_history
                ");
                $averages = $histAvgStmt->fetch(PDO::FETCH_ASSOC);
            }

            // Stats
            $stats = [
                'total'            => count($lots),
                'magic_sent'       => 0,
                'magic_expired'    => 0,
                'awaiting_reading' => 0,
                'pending'          => 0,
                'afwijkingen'      => 0,
                'action'           => 0,
                'approved'         => 0,
                'avg_gas'          => round($averages['avg_gas']   ?? 0, 1),
                'avg_water'        => round($averages['avg_water'] ?? 0, 1),
                'avg_elec'         => round($averages['avg_elec']  ?? 0, 1),
            ];

            foreach ($lots as $lot) {
                if ($lot['magic_link_status'] === 'valid')   $stats['magic_sent']++;
                if ($lot['magic_link_status'] === 'expired') $stats['magic_expired']++;
                if ($lot['awaiting_reading'])                $stats['awaiting_reading']++;
                if ($lot['reading_status'] === 'pending')    $stats['pending']++;
                if ($lot['is_afwijking'])                    $stats['afwijkingen']++;
                if ($lot['reading_status'] === 'pending')    $stats['action']++;
                if ($lot['reading_status'] === 'approved')   $stats['approved']++;
            }

            echo json_encode(['success' => true, 'lots' => $lots, 'stats' => $stats]);
            break;

        // ================================================================
        // SEND-INVITATION: Uitnodigingsmail (v/h Magic Link) sturen
        // ================================================================
        case 'send-magic-link':
            $lotId = (int)($_GET['lot_id'] ?? 0);
            if (!$lotId) throw new Exception('Geen kavel opgegeven');

            $token = $authService->generateTokenForLot($lotId);

            if ($token) {
                // Gebruikergegevens ophalen voor mail
                $stmt = $db->prepare("SELECT u.email, u.name, l.lot_number FROM lots l JOIN users u ON u.id = l.user_id WHERE l.id = ?");
                $stmt->execute([$lotId]);
                $info = $stmt->fetch(PDO::FETCH_ASSOC);

                $link = APP_URL . '/?t=' . $token;
                $mailSent = false;

                if ($info && $info['email']) {
                    $mailService = new MailService();
                    $mailSent = $mailService->sendInvitationEmail($info['email'], $info['name'] ?? 'Bewoner', $info['lot_number'], $link);
                }

                echo json_encode(['success' => true, 'link' => $link, 'token' => $token, 'mail_sent' => $mailSent]);
            } else {
                throw new Exception('Kavel niet gevonden of geen gebruiker gekoppeld');
            }
            break;

        // ================================================================
        // SEND-MAGIC-LINK-ALL: Magic link genereren voor alle bebouwde kavels
        // ================================================================
        case 'send-magic-link-all':
            $testMode = isset($_GET['test_mode']) && $_GET['test_mode'] == '1';

            $sql = "
                SELECT lo.lot_id as id, l.lot_number, lo.resident_email as email, lo.resident_name as name 
                FROM lot_occupancy lo
                JOIN lots l ON l.id = lo.lot_id
                WHERE lo.is_active = 1 AND lo.resident_email IS NOT NULL AND lo.resident_email != '' 
            ";

            // In testmodus: alleen kavels met het admin e-mailadres verwerken
            if ($testMode) {
                $sql .= " AND lo.resident_email = " . $db->quote(MAIL_USER);
            }

            $sql .= " ORDER BY l.lot_number ASC";
            $stmt = $db->query($sql);
            $lotsToInvite = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = ['sent' => 0, 'failed' => 0, 'mails_sent' => 0, 'links' => [], 'test_mode' => $testMode];
            $mailService = new MailService();

            foreach ($lotsToInvite as $l) {
                $token = $authService->generateTokenForLot($l['id']);
                if ($token) {
                    $results['sent']++;
                    $link = APP_URL . '/?t=' . $token;
                    
                    $mailOk = false;
                    if ($l['email']) {
                        $mailOk = $mailService->sendInvitationEmail($l['email'], $l['name'] ?? 'Bewoner', $l['lot_number'], $link);
                        if ($mailOk) $results['mails_sent']++;
                    }

                    $results['links'][] = [
                        'lot_number' => $l['lot_number'],
                        'link'       => $link,
                        'mail_sent'  => $mailOk
                    ];
                } else {
                    $results['failed']++;
                }
            }

            echo json_encode(['success' => true, 'results' => $results]);
            break;


        // ================================================================
        // SEND-MAGIC-LINK-SELECTED: Magic link genereren voor geselecteerde kavels
        // ================================================================
        case 'send-magic-link-selected':
            $input = json_decode(file_get_contents('php://input'), true);
            $lotIds = $input['lot_ids'] ?? [];
            $scenario = $input['scenario'] ?? 'jaarafrekening';

            if (empty($lotIds) || !is_array($lotIds)) {
                throw new Exception('Geen kavels geselecteerd');
            }

            $results = ['sent' => 0, 'failed' => 0, 'mails_sent' => 0, 'links' => []];
            $mailService = new MailService();

            foreach ($lotIds as $lotId) {
                $lotId = (int)$lotId;
                $token = $authService->generateTokenForLot($lotId);

                // Gegevens ophalen van actieve bewoner
                $stmtUserInfo = $db->prepare("
                    SELECT l.lot_number, lo.resident_name as name, lo.resident_email as email 
                    FROM lots l
                    LEFT JOIN lot_occupancy lo ON lo.lot_id = l.id AND lo.is_active = 1
                    WHERE l.id = ?
                ");
                $stmtUserInfo->execute([$lotId]);
                $l = $stmtUserInfo->fetch(PDO::FETCH_ASSOC);

                if ($token && $l) {
                    $results['sent']++;
                    $link = APP_URL . '/?t=' . $token;
                    
                    $skipMail = false;
                    // Bij verhuizing: zet de huidige bewoner periode op inactief
                    if ($scenario === 'verhuizing') {
                        // Markeer kavel als inactief in de oude lot-tabel (voor compatibiliteit)
                        $db->prepare("UPDATE lots SET is_resident_active = 0 WHERE id = ?")->execute([$lotId]);
                        
                        // Markeer de actieve occupancy record als inactief
                        $upd = $db->prepare("UPDATE lot_occupancy SET is_active = 0, end_date = CURDATE() WHERE lot_id = ? AND is_active = 1");
                        $upd->execute([$lotId]);

                        // Check of er al metingen zijn voor dit jaar voor dit kavel
                        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM readings WHERE lot_id = ? AND billing_period_id = ?");
                        $stmtCheck->execute([$lotId, $activePeriodId]);
                        if ((int)$stmtCheck->fetchColumn() > 0) {
                            $skipMail = true;
                        }
                    }

                    $mailOk = false;
                    if ($l['email'] && !$skipMail) {
                        $mailOk = $mailService->sendInvitationEmail($l['email'], $l['name'] ?? 'Bewoner', $l['lot_number'], $link);
                        if ($mailOk) $results['mails_sent']++;
                        else {
                            error_log("Failed to send email to " . $l['email'] . " for lot " . $l['lot_number']);
                        }
                    } elseif ($skipMail) {
                        // Mail bewust overgeslagen omdat er al data is
                        $mailOk = true; 
                    }

                    $results['links'][] = [
                        'lot_id'       => $lotId,
                        'lot_number'   => $l['lot_number'],
                        'link'         => $link,
                        'mail_sent'    => $mailOk,
                        'mail_skipped' => $skipMail
                    ];
                } else {
                    $results['failed']++;
                }
            }

            echo json_encode(['success' => true, 'results' => $results, 'scenario' => $scenario]);
            break;
        // ================================================================
        // UPDATE-RESIDENT: Bewoner gegevens (naam/email) wijzigen
        // ================================================================
        case 'update-resident':
        case 'assign-resident':
            $input = json_decode(file_get_contents('php://input'), true);
            $lotId = (int)($input['lot_id'] ?? 0);
            $name  = $input['name'] ?? '';
            $email = trim($input['email'] ?? '');

            if (!$lotId || !$email) throw new Exception('Lot ID en Email zijn verplicht.');

            // Update actieve bewoner in lot_occupancy
            $stmt = $db->prepare("UPDATE lot_occupancy SET resident_name = ?, resident_email = ? WHERE lot_id = ? AND is_active = 1");
            $stmt->execute([$name, $email, $lotId]);

            echo json_encode(['success' => true, 'message' => 'Bewoner gegevens bijgewerkt.']);
            break;

        case 'save-new-resident':
            $input = json_decode(file_get_contents('php://input'), true);
            $lotId = (int)($input['lot_id'] ?? 0);
            $name = $input['name'] ?? '';
            $email = $input['email'] ?? '';
            $startDate = $input['start_date'] ?? null;

            if (!$lotId || !$name || !$email || !$startDate) {
                throw new Exception('Vul alle verplichte velden in.');
            }

            try {
                $db->beginTransaction();

                // 1. Zet alle bestaande bewoners op dit kavel op inactief
                $stmt = $db->prepare("UPDATE lot_occupancy SET is_active = 0, end_date = ? WHERE lot_id = ? AND is_active = 1");
                $stmt->execute([$startDate, $lotId]);

                // 2. Haal de allerlaatste standen op van dit kavel
                // Eerst checken we de readings (verhuisstanden van vorige bewoner)
                $stmtRead = $db->prepare("
                    SELECT gas_new_reading, water_new_reading, electricity_new_reading 
                    FROM readings 
                    WHERE lot_id = ? AND status = 'approved'
                    ORDER BY reading_date DESC, created_at DESC LIMIT 1
                ");
                $stmtRead->execute([$lotId]);
                $lastReadings = $stmtRead->fetch(PDO::FETCH_ASSOC);

                // Als er geen approved readings zijn, pakken we de baseline uit import_history (begin van het jaar)
                if (!$lastReadings) {
                    $stmtBase = $db->prepare("
                        SELECT gas_new_reading, water_new_reading, electricity_new_reading 
                        FROM import_history 
                        WHERE lot_id = ? AND billing_period_id = ?
                    ");
                    $stmtBase->execute([$lotId, $activePeriodId]);
                    $base = $stmtBase->fetch(PDO::FETCH_ASSOC);
                    
                    if ($base) {
                        $lastReadings = [
                            'gas_new_reading' => $base['gas_new_reading'],
                            'water_new_reading' => $base['water_new_reading'],
                            'electricity_new_reading' => $base['electricity_new_reading']
                        ];
                    }
                }

                // 3. Maak nieuwe occupancy record
                $stmtIns = $db->prepare("
                    INSERT INTO lot_occupancy (lot_id, resident_name, resident_email, start_date, is_active, start_gas, start_water, start_elec)
                    VALUES (?, ?, ?, ?, 1, ?, ?, ?)
                ");
                $stmtIns->execute([
                    $lotId, 
                    $name, 
                    $email, 
                    $startDate,
                    $lastReadings['gas_new_reading'] ?? 0,
                    $lastReadings['water_new_reading'] ?? 0,
                    $lastReadings['electricity_new_reading'] ?? 0
                ]);

                // 4. Reset magic link op de lot tabel
                $stmtLot = $db->prepare("UPDATE lots SET magic_link_token = NULL, token_expires_at = NULL, user_id = NULL WHERE id = ?");
                $stmtLot->execute([$lotId]);

                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;


        // ================================================================
        // GET-AFWIJKINGEN: Alle kavels met afwijkingen + filteropties
        // ================================================================
        case 'get-afwijkingen':
            $typeFilter = $_GET['type'] ?? 'all'; // gas | water | electricity | all

            $whereExtra = '';
            if ($typeFilter !== 'all') {
                $col = null;
                switch($typeFilter) {
                    case 'gas':         $col = 'r.gas_consumption'; break;
                    case 'water':       $col = 'r.water_consumption'; break;
                    case 'electricity': $col = 'r.electricity_consumption'; break;
                }
                if ($col) $whereExtra = " AND $col IS NOT NULL";
            }

            $stmt = $db->query("
                SELECT
                    l.id, l.lot_number, l.lot_type,
                    r.reading_date,
                    r.gas_consumption,
                    r.water_consumption,
                    r.electricity_consumption,
                    r.afwijking_reden,
                    r.status AS reading_status,
                    u.email AS user_email
                FROM readings r
                JOIN lots l ON l.id = r.lot_id
                LEFT JOIN users u ON u.id = l.user_id
                WHERE r.is_afwijking = 1
                $whereExtra
                ORDER BY l.lot_number ASC
            ");
            $afwijkingen = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Park gemiddelden voor vergelijking
            $avgStmt = $db->query("
                SELECT
                    AVG(gas_consumption) as avg_gas,
                    AVG(water_consumption) as avg_water,
                    AVG(electricity_consumption) as avg_elec
                FROM readings WHERE status = 'approved'
            ");
            $avg = $avgStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'     => true,
                'afwijkingen' => $afwijkingen,
                'averages'    => [
                    'gas'   => round($avg['avg_gas']   ?? 0, 1),
                    'water' => round($avg['avg_water'] ?? 0, 1),
                    'elec'  => round($avg['avg_elec']  ?? 0, 1),
                ]
            ]);
            break;

        // ================================================================
        // GET-HISTORY: Historie van één kavel inclusief foto's
        // ================================================================
        case 'get-history':
            $lotId = (int)($_GET['lot_id'] ?? 0);

            $stmt = $db->prepare("
                SELECT 
                    l.id, l.lot_number, l.lot_type, l.address,
                    COALESCE(lo.resident_name, l.resident_name) as resident_name,
                    COALESCE(lo.resident_email, l.resident_email) as resident_email,
                    COALESCE(lo.is_active, 0) as is_resident_active,
                    lo.start_date as resident_since_date
                FROM lots l
                LEFT JOIN lot_occupancy lo ON lo.lot_id = l.id AND lo.is_active = 1
                WHERE l.id = ?
            ");
            $stmt->execute([$lotId]);
            $lot = $stmt->fetch(PDO::FETCH_ASSOC);

            // Import historie (baseline) - Koppelen aan jaartallen
            $stmt = $db->prepare("
                SELECT ih.*, bp.label as period_name 
                FROM import_history ih 
                JOIN billing_periods bp ON bp.id = ih.billing_period_id
                WHERE ih.lot_id = ? 
                ORDER BY bp.year DESC
            ");
            $stmt->execute([$lotId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // PWA Metingen inkl. image_url
            $stmt = $db->prepare("
                SELECT r.*, u.email as submitted_by_email, bp.label as period_name
                FROM readings r
                LEFT JOIN users u ON u.id = r.submitted_by
                LEFT JOIN billing_periods bp ON bp.id = r.billing_period_id
                WHERE r.lot_id = ? AND r.billing_period_id = ?
                ORDER BY r.reading_date DESC, r.created_at DESC
            ");
            $stmt->execute([$lotId, $activePeriodId]);
            $readings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // VOLLEDIGE BEWONERSHISTORIE
            $stmt = $db->prepare("
                SELECT * FROM lot_occupancy 
                WHERE lot_id = ? 
                ORDER BY start_date DESC, id DESC
            ");
            $stmt->execute([$lotId]);
            $occupancy = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true, 
                'lot' => $lot, 
                'history' => $history, 
                'readings' => $readings,
                'occupancy' => $occupancy
            ]);
            break;

        // ================================================================
        // REVIEW ACTIES: Goedkeuren, Afwijzen, Heropenen
        // ================================================================
        case 'approve-reading':
            $id = (int)($_GET['id'] ?? 0);
            
            $db->beginTransaction();
            try {
                // Haal de volledige reading op incl occupancy_id
                $stmt = $db->prepare("SELECT * FROM readings WHERE id = ?");
                $stmt->execute([$id]);
                $reading = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$reading['occupancy_id']) {
                    // Fallback: zoek actieve occupancy als deze nog niet gekoppeld was
                    $stmtOcc = $db->prepare("SELECT id FROM lot_occupancy WHERE lot_id = ? AND is_active = 1 LIMIT 1");
                    $stmtOcc->execute([$reading['lot_id']]);
                    $reading['occupancy_id'] = $stmtOcc->fetchColumn();
                }

                // Bereken verbruik voor deze specifieke bewonersperiode
                $invoiceService = new InvoiceService();
                $preview = $invoiceService->calculatePreview((int)$reading['occupancy_id']);
                $cons = $preview['consumption'];

                $stmt = $db->prepare("
                    UPDATE readings SET 
                        status = 'approved', 
                        reviewed_at = NOW(), 
                        reviewed_by = ?,
                        gas_consumption = ?,
                        water_consumption = ?,
                        electricity_consumption = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    AuthService::isLoggedIn() ? $_SESSION['user_id'] : null, 
                    $cons['gas'],
                    $cons['water'],
                    $cons['elec'],
                    $id
                ]);

                if ($reading && $reading['scenario'] === 'verhuizing') {
                    // 1. Oude bewoner op inactief en eindstanden vastleggen
                    $stmt = $db->prepare("
                        UPDATE lot_occupancy SET 
                            is_active = 0, 
                            end_date = ?, 
                            end_gas = ?, 
                            end_water = ?, 
                            end_elec = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $reading['reading_date'],
                        $reading['gas_new_reading'],
                        $reading['water_new_reading'],
                        $reading['electricity_new_reading'],
                        $reading['occupancy_id']
                    ]);
                    
                    // 2. Ook op de lot tabel (compatibiliteit)
                    $stmtLot = $db->prepare("UPDATE lots SET is_resident_active = 0, user_id = NULL WHERE id = ?");
                    $stmtLot->execute([$reading['lot_id']]);
                    
                    // OPMERKING: We inactiveren de 'submitted_by' gebruiker NIET meer.
                    // Dit veroorzaakte bugs waarbij admins zichzelf uitlogden als ze 
                    // de verhuizing zelf hadden ingevoerd (proxy reading).
                }
                
                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'approve-all-lot-readings':
            $lotId = (int)($_GET['lot_id'] ?? 0);
            
            // We moeten hier ook het verbruik berekenen voor alle pending readings van dit kavel
            $stmt = $db->prepare("SELECT id FROM readings WHERE lot_id = ? AND status = 'pending' AND billing_period_id = ?");
            $stmt->execute([$lotId, $activePeriodId]);
            $pendingReadings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $db->beginTransaction();
            try {
                $invoiceService = new InvoiceService();
                foreach ($pendingReadings as $pr) {
                    $preview = $invoiceService->calculatePreview($lotId, $activePeriodId);
                    $cons = $preview['consumption'];

                    $upd = $db->prepare("
                        UPDATE readings SET 
                            status = 'approved', 
                            reviewed_at = NOW(), 
                            gas_consumption = ?,
                            water_consumption = ?,
                            electricity_consumption = ?
                        WHERE id = ?
                    ");
                    $upd->execute([$cons['gas'], $cons['water'], $cons['elec'], $pr['id']]);
                }
                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'reject-reading':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("UPDATE readings SET status = 'rejected', reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        case 'reset-reading':
            $id = (int)($_GET['id'] ?? 0);
            // Terug naar pending (of verwijderen als we echt 'resetten' bedoelen)
            // Voor nu: status op 'pending' zetten zodat het opnieuw beoordeeld kan worden
            $stmt = $db->prepare("UPDATE readings SET status = 'pending', reviewed_at = NULL WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        case 'save-proxy-reading':
            $input = json_decode(file_get_contents('php://input'), true);
            $lotId = (int)($input['lot_id'] ?? 0);
            $gas = $input['gas'];
            $water = $input['water'];
            $elec = $input['elec'];
            $base64Image = $input['image'] ?? null;

            if (!$lotId) throw new Exception('Geen kavel opgegeven');

            $imageUrl = null;
            $exifDate = date('Y-m-d H:i:s');

            if ($base64Image) {
                $fileService = new FileService();
                $filename = "lot{$lotId}_proxy_" . date('His');
                $storageResult = $fileService->storeBase64Image($base64Image, $filename);
                $imageUrl = $storageResult['url'];
                $exifDate = $storageResult['exif_date'];
            }

            $readingService = new ReadingService();
            $readingId = $readingService->saveProxyReading([
                'lot_id' => $lotId,
                'gas' => $gas,
                'water' => $water,
                'elec' => $elec,
                'image_url' => $imageUrl,
                'exif_timestamp' => $exifDate
            ]);

            echo json_encode(['success' => true, 'id' => $readingId]);
            break;

        case 'get-tariffs':
            // Voor 2025 (period 1)
            $stmt = $db->prepare("SELECT * FROM tariffs WHERE billing_period_id = ?");
            $stmt->execute([$activePeriodId]);
            $tariffs = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("SELECT * FROM fixed_costs_templates WHERE billing_period_id = ?");
            $stmt->execute([$activePeriodId]);
            $fixed = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'tariffs' => $tariffs, 'fixed' => $fixed]);
            break;

        case 'update-tariffs':
            $input = json_decode(file_get_contents('php://input'), true);
            $t = $input['tariffs'];
            $fb = $input['fixed_bebouwd'];
            $fo = $input['fixed_onbebouwd'];

            $db->beginTransaction();
            try {
                // Update Tarieven
                $stmt = $db->prepare("
                    UPDATE tariffs SET 
                        gas_price_per_m3 = ?, 
                        water_price_per_m3 = ?, 
                        electricity_price_per_kwh = ?
                    WHERE billing_period_id = ?
                ");
                $stmt->execute([$t['gas'], $t['water'], $t['elec'], $activePeriodId]);

                // Update Vaste Lasten Bebouwd
                $stmt = $db->prepare("
                    UPDATE fixed_costs_templates SET 
                        vast_gas_per_month = ?, 
                        vast_water_per_month = ?, 
                        vast_electricity_per_month = ?,
                        vve_per_year = ?,
                        erfpacht_per_year = ?
                    WHERE billing_period_id = ? AND lot_type = 'bebouwd'
                ");
                $stmt->execute([$fb['gas'], $fb['water'], $fb['elec'], $fb['vve'], $fb['erfpacht'], $activePeriodId]);

                // Update Vaste Lasten Onbebouwd
                $stmt = $db->prepare("
                    UPDATE fixed_costs_templates SET 
                        vve_per_year = ?,
                        erfpacht_per_year = ?
                    WHERE billing_period_id = ? AND lot_type = 'onbebouwd'
                ");
                $stmt->execute([$fo['vve'], $fo['erfpacht'], $activePeriodId]);

                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'get-billing-preview':
            $lotId = (int)($_GET['lot_id'] ?? 0);
            $occupancyId = (int)($_GET['occupancy_id'] ?? 0);
            $correction = (float)($_GET['correction'] ?? 0);
            $reason = $_GET['reason'] ?? '';

            if (!$occupancyId && $lotId) {
                // Zoek actieve occupancy voor dit kavel
                $stmtOcc = $db->prepare("SELECT id FROM lot_occupancy WHERE lot_id = ? AND is_active = 1 LIMIT 1");
                $stmtOcc->execute([$lotId]);
                $occupancyId = $stmtOcc->fetchColumn();
            }

            if (!$occupancyId) throw new Exception("Geen bewoningsgegevens gevonden.");
            
            $invoiceService = new InvoiceService();
            $preview = $invoiceService->calculatePreview($occupancyId, $correction, $reason);
            echo json_encode(['success' => true, 'preview' => $preview]);
            break;

        case 'test-ocr':
            $input = json_decode(file_get_contents('php://input'), true);
            $base64Image = $input['image'] ?? null;
            
            if (!$base64Image) throw new Exception('Geen afbeelding ontvangen');

            require_once __DIR__ . '/../services/OcrService.php';
            $ocr = new \Horsterwold\Services\OcrService();
            
            // Simuleer een verwerking (of voer echt uit)
            $result = $ocr->processMeterImage($base64Image);
            
            echo json_encode(['success' => true, 'result' => $result]);
            break;

        case 'save-billing-result':
            $input = json_decode(file_get_contents('php://input'), true);
            $p = $input['preview'];

            $stmt = $db->prepare("
                INSERT INTO billing_results (
                    reading_id, lot_id, occupancy_id, billing_period_id,
                    gas_cost, water_cost, electricity_cost, solar_credit,
                    vast_gas_total, vast_water_total, vast_electricity_total,
                    vve_total, erfpacht_total,
                    vat_rate, vat_amount, correction_amount, correction_reason,
                    subtotal, settlement_amount, calculated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, NOW()
                )
                ON DUPLICATE KEY UPDATE 
                    gas_cost=VALUES(gas_cost), water_cost=VALUES(water_cost),
                    electricity_cost=VALUES(electricity_cost), solar_credit=VALUES(solar_credit),
                    vat_amount=VALUES(vat_amount), correction_amount=VALUES(correction_amount),
                    subtotal=VALUES(subtotal), calculated_at=NOW(),
                    occupancy_id=VALUES(occupancy_id)
            ");

            $stmt->execute([
                $p['reading_id'], $p['lot']['id'], $p['occupancy']['id'], $p['period_id'],
                $p['costs']['gas'], $p['costs']['water'], $p['costs']['elec'], $p['costs']['solar_credit'],
                $p['fixed']['gas'], $p['fixed']['water'], $p['fixed']['elec'],
                $p['fixed']['vve'], $p['fixed']['erfpacht'],
                $p['summary']['vat_rate'], $p['summary']['vat_amount'], 
                $p['summary']['correction'], $p['summary']['correction_reason'],
                $p['summary']['total'], $p['summary']['total']
            ]);

            echo json_encode(['success' => true]);
            break;

        case 'get-system-settings':
            $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            echo json_encode(['success' => true, 'settings' => $settings]);
            break;

        case 'get-invoicing-stats':
            $stmt = $db->prepare("
                SELECT 
                    SUM(CASE WHEN r.status = 'approved' AND br.id IS NULL THEN 1 ELSE 0 END) as ready_count,
                    SUM(CASE WHEN br.id IS NOT NULL THEN 1 ELSE 0 END) as invoiced_count,
                    SUM(CASE WHEN br.sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent_count,
                    (SELECT COUNT(*) FROM lots WHERE lot_type = 'bebouwd') as total_built
                FROM lot_occupancy lo
                JOIN lots l ON l.id = lo.lot_id
                LEFT JOIN readings r ON r.occupancy_id = lo.id AND r.billing_period_id = :period_id
                LEFT JOIN billing_results br ON br.occupancy_id = lo.id AND br.billing_period_id = :period_id
                WHERE l.lot_type = 'bebouwd' AND lo.is_active = 1
            ");
            $stmt->execute(['period_id' => $activePeriodId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get the list of occupancy records that are ready (approved reading but no billing result)
            // Focus on active occupancies for bulk billing, but historical ones can be done individually via history modal.
            $stmtList = $db->prepare("
                SELECT lo.id as occupancy_id, l.id as lot_id, l.lot_number, lo.resident_name as name
                FROM lot_occupancy lo
                JOIN lots l ON l.id = lo.lot_id
                JOIN readings r ON r.occupancy_id = lo.id AND r.billing_period_id = :period_id
                LEFT JOIN billing_results br ON br.occupancy_id = lo.id AND br.billing_period_id = :period_id
                WHERE l.lot_type = 'bebouwd' AND r.status = 'approved' AND br.id IS NULL
                ORDER BY l.lot_number ASC
            ");
            $stmtList->execute(['period_id' => $activePeriodId]);
            $readyLots = $stmtList->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'stats' => $stats, 'ready_lots' => $readyLots]);
            break;

        case 'update-system-settings':
            $input = json_decode(file_get_contents('php://input'), true);
            $settings = $input['settings'] ?? [];

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                foreach ($settings as $key => $value) {
                    $stmt->execute([$key, $value]);
                }
                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'send-invoice':
            $lotId = (int)($_GET['lot_id'] ?? 0);
            if (!$lotId) throw new Exception('Geen kavel opgegeven');

            // 1. Get Billing Data from DB - Join met lot_occupancy voor de juiste ontvanger
            $stmt = $db->prepare("
                SELECT br.*, l.lot_number, lo.resident_email as user_email, lo.resident_name
                FROM billing_results br
                JOIN lots l ON br.lot_id = l.id
                JOIN lot_occupancy lo ON br.occupancy_id = lo.id
                WHERE br.lot_id = ?
                ORDER BY br.calculated_at DESC LIMIT 1
            ");
            $stmt->execute([$lotId]);
            $billing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$billing) throw new Exception('Geen opgeslagen berekening gevonden');
            if (!$billing['user_email']) throw new Exception('Geen e-mailadres bekend voor de bewoner');

            // 2. We need full data for the PDF (Tariffs etc)
            $invoiceService = new InvoiceService();
            // Gebruik de occupancy_id van de opgeslagen berekening
            $fullData = $invoiceService->calculatePreview((int)$billing['occupancy_id'], (float)$billing['correction_amount'], $billing['correction_reason']);
            
            // Tarieven expliciet toevoegen voor de PDF template
            $stmtT = $db->prepare("SELECT * FROM tariffs WHERE billing_period_id = ?");
            $stmtT->execute([$billing['billing_period_id']]);
            $fullData['tariffs'] = $stmtT->fetch(PDO::FETCH_ASSOC);

            // 3. Generate PDF
            $pdfService = new PdfService();
            $pdfFilename = $pdfService->generateInvoicePdf($fullData);
            $pdfPath = __DIR__ . "/../../public/uploads/invoices/" . $pdfFilename;

            // 4. Send Email
            $mailService = new MailService();
            $success = $mailService->sendInvoiceEmail($billing['user_email'], $pdfPath, $billing['lot_number']);

            if ($success) {
                // Update sent_at manually
                $upd = $db->prepare("UPDATE billing_results SET sent_at = NOW() WHERE lot_id = ? AND billing_period_id = ?");
                $upd->execute([$lotId, $activePeriodId]);
            }

            echo json_encode(['success' => $success, 'filename' => $pdfFilename]);
            break;

        case 'update-lot-settings':
            $input = json_decode(file_get_contents('php://input'), true);
            $lotId = (int)($input['lot_id'] ?? 0);
            $allowDirectDebit = isset($input['allow_direct_debit']) ? (int)$input['allow_direct_debit'] : 1;

            if (!$lotId) throw new Exception('Geen kavel opgegeven');

            $stmt = $db->prepare("UPDATE lots SET allow_direct_debit = ? WHERE id = ?");
            $stmt->execute([$allowDirectDebit, $lotId]);

            echo json_encode(['success' => true]);
            break;

        case 'update-payment-status':
            $input = json_decode(file_get_contents('php://input'), true);
            $lotId = (int)($input['lot_id'] ?? 0);
            $status = $input['status'] ?? 'pending';

            if (!$lotId) throw new Exception('Geen kavel opgegeven');

            $paidAt = ($status === 'paid') ? date('Y-m-d H:i:s') : null;

            $stmt = $db->prepare("
                UPDATE billing_results 
                SET payment_status = ?, paid_at = ?
                WHERE lot_id = ? 
                ORDER BY calculated_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$status, $paidAt, $lotId]);

            echo json_encode(['success' => true]);
            break;

        case 'finalize-year':
            if (!$activePeriodId) throw new Exception('Geen actieve periode gevonden om af te sluiten.');

            // 1. Check op onafgesloten metingen
            $stmt = $db->prepare("SELECT COUNT(*) FROM readings WHERE billing_period_id = ? AND status = 'pending'");
            $stmt->execute([$activePeriodId]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Er zijn nog onafgesloten metingen (Wacht op controle). Handel deze eerst af voordat je het jaar afsluit.');
            }

            $db->beginTransaction();
            try {
                $nextYear = (int)$activePeriod['year'] + 1;
                $nextLabel = "Jaarafrekening " . $nextYear;
                $startDate = $nextYear . "-01-01";
                $endDate = $nextYear . "-12-31";

                // 2. Maak nieuwe periode
                $stmt = $db->prepare("INSERT INTO billing_periods (year, label, start_date, end_date, status) VALUES (?, ?, ?, ?, 'open') ON DUPLICATE KEY UPDATE status='open'");
                $stmt->execute([$nextYear, $nextLabel, $startDate, $endDate]);
                $nextPeriodId = $db->lastInsertId();
                if (!$nextPeriodId) {
                    $stmt = $db->prepare("SELECT id FROM billing_periods WHERE year = ?");
                    $stmt->execute([$nextYear]);
                    $nextPeriodId = $stmt->fetchColumn();
                }

                // 3. Kopieer Tarieven
                $stmt = $db->prepare("INSERT IGNORE INTO tariffs (billing_period_id, gas_price_per_m3, water_price_per_m3, electricity_price_per_kwh) 
                                      SELECT ?, gas_price_per_m3, water_price_per_m3, electricity_price_per_kwh FROM tariffs WHERE billing_period_id = ?");
                $stmt->execute([$nextPeriodId, $activePeriodId]);

                // 4. Kopieer Vaste Lasten Templates
                $stmt = $db->prepare("INSERT IGNORE INTO fixed_costs_templates (billing_period_id, lot_type, vast_gas_per_month, vast_water_per_month, vast_electricity_per_month, vve_per_year, erfpacht_per_year)
                                      SELECT ?, lot_type, vast_gas_per_month, vast_water_per_month, vast_electricity_per_month, vve_per_year, erfpacht_per_year FROM fixed_costs_templates WHERE billing_period_id = ?");
                $stmt->execute([$nextPeriodId, $activePeriodId]);

                // 5. Voor elk kavel: zet laatste goedgekeurde stand als baseline voor nieuw jaar (in de nieuwe periode-id)
                $stmt = $db->prepare("
                    INSERT INTO import_history (lot_id, billing_period_id, gas_new_reading, water_new_reading, electricity_new_reading)
                    SELECT r.lot_id, ?, r.gas_new_reading, r.water_new_reading, r.electricity_new_reading
                    FROM readings r
                    WHERE r.id IN (
                        SELECT MAX(id) FROM readings 
                        WHERE billing_period_id = ? AND status = 'approved'
                        GROUP BY lot_id
                    )
                    ON DUPLICATE KEY UPDATE 
                        gas_new_reading = VALUES(gas_new_reading),
                        water_new_reading = VALUES(water_new_reading),
                        electricity_new_reading = VALUES(electricity_new_reading)
                ");
                $stmt->execute([$nextPeriodId, $activePeriodId]);

                // 6. Sluit oude periode
                $stmt = $db->prepare("UPDATE billing_periods SET status = 'closed' WHERE id = ?");
                $stmt->execute([$activePeriodId]);

                $db->commit();
                echo json_encode(['success' => true, 'new_period_id' => $nextPeriodId, 'next_year' => $nextYear]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'export-sepa':
            $lotIds = isset($_GET['lot_ids']) ? explode(',', $_GET['lot_ids']) : [];
            $lotIds = array_filter(array_map('intval', $lotIds));

            if (empty($lotIds)) throw new Exception('Geen kavels geselecteerd');

            $in = str_repeat('?,', count($lotIds) - 1) . '?';
            $stmt = $db->prepare("
                SELECT 
                    br.lot_id, 
                    br.total_amount_incl_vat AS amount,
                    u.name,
                    u.iban_number as iban,
                    u.incasso_mandate_date as mandate_date,
                    l.lot_number
                FROM billing_results br
                JOIN lots l ON l.id = br.lot_id
                JOIN users u ON l.user_id = u.id
                WHERE br.lot_id IN ($in) 
                  AND br.payment_status = 'pending'
                  AND l.allow_direct_debit = 1
                  AND u.iban_number IS NOT NULL
                  AND u.incasso_mandate_date IS NOT NULL
            ");
            $stmt->execute($lotIds);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($results)) {
                throw new Exception('Geen geldige (incasso-geaccordeerde) en openstaande facturen gevonden voor de geselecteerde kavels.');
            }

            $transactions = [];
            foreach ($results as $r) {
                // Ensure amount is strictly positive for direct debit
                if ($r['amount'] <= 0) continue; 
                
                $transactions[] = [
                    'lot_id' => $r['lot_id'],
                    'name' => $r['name'] ?: 'Kavel ' . $r['lot_number'],
                    'iban' => $r['iban'],
                    'mandate_date' => $r['mandate_date'],
                    'amount' => $r['amount'],
                    'description' => 'Afrekening Jaar ' . date('Y') . ' Kavel ' . $r['lot_number']
                ];
            }

            if (empty($transactions)) {
                throw new Exception('Geen positieve bedragen om te incasseren.');
            }

            $sepaService = new SepaService();
            $xml = $sepaService->generateSepaXml($transactions);

            // Output as file download
            header('Content-Type: application/xml');
            header('Content-Disposition: attachment; filename="sepa_incasso_' . date('Ymd_His') . '.xml"');
            echo $xml;
            exit;

        case 'get-admins':
            $stmt = $db->query("SELECT id, name, email, role, status FROM users WHERE role = 'admin' ORDER BY name ASC");
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'admins' => $admins]);
            break;

        case 'add-admin':
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $email = $input['email'] ?? '';

            if (!$email) throw new Exception('Email is verplicht.');

            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existing = $stmt->fetchColumn();

            if ($existing) {
                // Update bestaande gebruiker naar admin
                $stmt = $db->prepare("UPDATE users SET name = ?, role = 'admin', status = 'active' WHERE id = ?");
                $stmt->execute([$name, $existing]);
                $id = $existing;
            } else {
                $stmt = $db->prepare("INSERT INTO users (name, email, role, status) VALUES (?, ?, 'admin', 'active')");
                $stmt->execute([$name, $email]);
                $id = $db->lastInsertId();
            }

            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'update-admin':
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);
            $name = $input['name'] ?? '';
            $email = $input['email'] ?? '';

            if (!$id || !$email) throw new Exception('ID en Email zijn verplicht.');

            $stmt = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ? AND role = 'admin'");
            $stmt->execute([$name, $email, $id]);

            echo json_encode(['success' => true]);
            break;

        case 'delete-admin':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Geen beheerder opgegeven');

            // Veiligheid: niet zelf verwijderen
            if ($id === (int)($_SESSION['user_id'] ?? 0)) {
                throw new Exception('Je kunt jezelf niet verwijderen vanuit deze interface.');
            }

            // Veiligheid: check hoeveel admins er nog zijn
            $countStmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
            $count = (int)$countStmt->fetchColumn();
            if ($count <= 1) {
                throw new Exception('Dit is de laatste actieve beheerder. Verwijdering niet toegestaan.');
            }

            $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
            $stmt->execute([$id]);

            echo json_encode(['success' => true]);
            break;

        case 'test-ocr':
            $input = json_decode(file_get_contents('php://input'), true);
            $imageData = $input['image'] ?? null;

            if (!$imageData) {
                throw new Exception('Geen afbeelding ontvangen.');
            }

            // Clean base64 data
            if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
                $imageData = base64_decode($imageData);
            } else {
                // Try raw base64
                $decoded = base64_decode($imageData, true);
                if ($decoded) {
                    $imageData = $decoded;
                } else {
                    throw new Exception('Ongeldig afbeeldingsformaat.');
                }
            }

            $ocrService = new OcrService();
            $result = $ocrService->detectMeterReading($imageData);
            
            echo json_encode(['success' => true, 'result' => $result]);
            break;

        case 'close-period':
            if (!$activePeriodId) throw new Exception("Geen actieve periode gevonden.");
            
            $db->prepare("UPDATE billing_periods SET status = 'closed' WHERE id = ?")
               ->execute([$activePeriodId]);
               
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Ongeldige actie: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
