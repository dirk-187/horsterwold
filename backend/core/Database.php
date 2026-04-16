<?php
/**
 * Database verbinding via PDO (Singleton)
 */

require_once __DIR__ . '/../config.php';

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
        return self::$instance;
    }

    public static function getActivePeriod(): ?array
    {
        $db = self::getConnection();
        $stmt = $db->query("SELECT * FROM billing_periods WHERE status = 'open' ORDER BY year DESC LIMIT 1");
        return $stmt->fetch() ?: null;
    }
}
