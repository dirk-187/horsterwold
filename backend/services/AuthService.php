<?php
/**
 * Magic Link authenticatie service
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../config.php';

class AuthService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Genereer een directe inlogtoken voor een specifieke kavel door deze in de kavel-tabel op te slaan.
     */
    public function generateTokenForLot(int $lotId): ?string
    {
        $token   = bin2hex(random_bytes(MAGIC_LINK_TOKEN_LENGTH / 2));
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $stmt = $this->db->prepare(
            'UPDATE lots SET magic_link_token = ?, token_expires_at = ? WHERE id = ?'
        );
        $stmt->execute([$token, $expires, $lotId]);

        return ($stmt->rowCount() > 0) ? $token : null;
    }

    /**
     * Genereer een magic link token voor een gebruiker op basis van e-mail.
     * Werkt voor zowel bewoners (via lots) als beheerders (via users).
     */
    public function generateMagicLink(string $email, bool $isAdmin = false): ?string
    {
        $token   = bin2hex(random_bytes(MAGIC_LINK_TOKEN_LENGTH / 2));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        if ($isAdmin) {
            $stmt = $this->db->prepare('UPDATE users SET magic_link_token = ?, token_expires_at = ? WHERE email = ? AND role = "admin"');
            $stmt->execute([$token, $expires, $email]);
            if ($stmt->rowCount() === 0) return null;
        } else {
            // Voor bewoners: we zoeken een kavel via de actieve bewoningsperiode
            // Als er geen actieve is (bijv. tijdens verhuizing), pakken we de meest recente
            $stmt = $this->db->prepare('
                UPDATE lots l
                JOIN (
                    SELECT lot_id FROM lot_occupancy 
                    WHERE resident_email = ? 
                    ORDER BY is_active DESC, start_date DESC LIMIT 1
                ) lo ON lo.lot_id = l.id
                SET l.magic_link_token = ?, l.token_expires_at = ?
            ');
            $stmt->execute([$email, $token, $expires]);
            if ($stmt->rowCount() === 0) return null;
        }

        $basePath = $isAdmin ? APP_URL . '/admin/?token=' : APP_URL . '/?t=';
        return $basePath . $token;
    }

    /**
     * Valideer een magic link token en start een sessie.
     * Token is eenmalig (One-Time Use).
     */
    public function verifyMagicLink(string $token): ?array
    {
        // 1. Check of het een Admin is (tabel users)
        $stmt = $this->db->prepare('
            SELECT id, email, name, role, NULL as lot_id, NULL as lot_number
            FROM users 
            WHERE magic_link_token = ? AND token_expires_at > NOW() AND role = "admin" AND status = "active"
        ');
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Token verbruiken in users
            $this->db->prepare('UPDATE users SET magic_link_token = NULL, token_expires_at = NULL, last_login_at = NOW() WHERE id = ?')
                     ->execute([$user['id']]);
            return $user;
        }

        // 2. Check of het een Bewoner is (tabel lots + lot_occupancy)
        $stmt = $this->db->prepare('
            SELECT 
                NULL AS id, 
                lo.resident_email AS email, 
                lo.resident_name AS name, 
                "resident" AS role, 
                l.id AS lot_id, 
                l.lot_number
            FROM lots l
            JOIN lot_occupancy lo ON lo.lot_id = l.id
            WHERE l.magic_link_token = ? AND l.token_expires_at > NOW()
            ORDER BY lo.is_active DESC, lo.start_date DESC
            LIMIT 1
        ');
        $stmt->execute([$token]);
        $resident = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($resident) {
            // Token verbruiken in lots
            $this->db->prepare('UPDATE lots SET magic_link_token = NULL, token_expires_at = NULL WHERE id = ?')
                     ->execute([$resident['lot_id']]);
            return $resident;
        }

        return null;
    }

    /**
     * Start een sessie voor een ingelogde gebruiker.
     */
    public function startSession(array $user): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $_SESSION['user_id']   = $user['id']; // This will be NULL for residents
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
        // Lot data (residents)
        if (!empty($user['lot_id'])) {
            $_SESSION['lot_id'] = $user['lot_id'];
        }
    }

    /**
     * Controleer of de huidige gebruiker is ingelogd.
     */
    public static function isLoggedIn(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['user_role']);
    }

    /**
     * Controleer of de ingelogde gebruiker admin is.
     */
    public static function isAdmin(): bool
    {
        return self::isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
    }

    /**
     * Uitloggen.
     */
    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
    }
}
