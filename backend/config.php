<?php
/**
 * Horsterwold Meterstanden — Centrale Configuratie
 * Laadt instellingen uit .env bestand voor veiligheid.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Laad .env bestand
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Helper functie voor env variabelen
if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key);
        return $value === false || $value === null ? $default : $value;
    }
}

// Database
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'horsterwold'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// Cloud Storage
define('STORAGE_PROVIDER', env('STORAGE_PROVIDER', 'local'));
define('STORAGE_BUCKET', env('STORAGE_BUCKET', 'uploads'));

// OCR Service
define('OCR_PROVIDER', env('OCR_PROVIDER', 'mock'));
define('GOOGLE_KEY_FILE', __DIR__ . '/' . env('GOOGLE_KEY_FILE', 'config/google-key.json'));
define('OCR_API_KEY', env('OCR_API_KEY', ''));

// Applicatie
define('APP_NAME', 'Horsterwold Meterstanden');

// APP_URL: configureer via .env op productie (bijv. https://jouwdomein.nl)
// Op localhost wordt automatisch /horsterwold/public gebruikt
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$defaultUrl = $protocol . '://' . $host . '/horsterwold/public';
define('APP_URL', env('APP_URL', $defaultUrl));

define('STORAGE_BASE_URL', APP_URL . '/uploads/');
define('APP_ENV', env('APP_ENV', 'development'));

// Magic Link instellingen
define('MAGIC_LINK_EXPIRY_MINUTES', (int)env('MAGIC_LINK_EXPIRY_MINUTES', 60));
define('MAGIC_LINK_TOKEN_LENGTH', 48);

// Afwijking drempel
define('AFWIJKING_THRESHOLD_PERCENT', (int)env('AFWIJKING_THRESHOLD_PERCENT', 20));

// E-mail (SMTP) instellingen
define('MAIL_HOST', env('MAIL_HOST', 'localhost'));
define('MAIL_PORT', (int)env('MAIL_PORT', 587));
define('MAIL_USER', env('MAIL_USER', ''));
define('MAIL_PASS', env('MAIL_PASS', ''));
define('MAIL_FROM_ADDR', env('MAIL_FROM_ADDR', 'no-reply@horsterwold-meterstanden.nl'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Horsterwold Beheer'));
define('MAIL_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls'));

// SEPA Incasso (XML Export) Instellingen
define('SEPA_CREDITOR_NAME', env('SEPA_CREDITOR_NAME', 'Recreatiepark Horsterwold'));
define('SEPA_CREDITOR_ID', env('SEPA_CREDITOR_ID', 'NL99ZZZ123456780000'));
define('SEPA_CREDITOR_IBAN', env('SEPA_CREDITOR_IBAN', 'NL00BANK0000000000'));
