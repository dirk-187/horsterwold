<?php
/**
 * SettingsService — Manages global system settings from the database.
 */

namespace Horsterwold\Services;

require_once __DIR__ . '/../core/Database.php';

use Database;
use PDO;

class SettingsService
{
    private PDO $db;
    private static ?array $cache = null;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Get a setting value, with optional default (from config constants)
     */
    public function get(string $key, $default = null)
    {
        $settings = $this->getAll();
        return $settings[$key] ?? $default;
    }

    /**
     * Get all settings as an associative array
     */
    public function getAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stmt = $this->db->query("SELECT setting_key, setting_value FROM system_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        self::$cache = $settings;
        return $settings;
    }

    /**
     * Refresh cache
     */
    public function refresh(): void
    {
        self::$cache = null;
        $this->getAll();
    }
}
