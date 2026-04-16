<?php
/**
 * Horsterwold Meterstanden — Database configuratie
 * Kopieer dit bestand naar config.php en vul je eigen waarden in.
 * Voeg config.php toe aan .gitignore!
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'horsterwold');
define('DB_USER', 'root');         // Pas aan voor productie
define('DB_PASS', '');             // Pas aan voor productie
define('DB_CHARSET', 'utf8mb4');

// Cloud Storage (AWS S3 of Google Cloud Storage)
define('STORAGE_PROVIDER', 'gcs'); // 'gcs' of 's3'
define('STORAGE_BUCKET', 'horsterwold-meters');
define('STORAGE_BASE_URL', 'https://storage.googleapis.com/horsterwold-meters/');

// OCR Service
define('OCR_PROVIDER', 'google');  // 'google' of 'aws'
define('OCR_API_KEY', '');         // Vul in na setup

// Applicatie
define('APP_NAME', 'Horsterwold Meterstanden');
define('APP_URL', 'http://localhost/horsterwold');
define('APP_ENV', 'development');  // 'development' of 'production'

// Magic Link instellingen
define('MAGIC_LINK_EXPIRY_MINUTES', 60);
define('MAGIC_LINK_TOKEN_LENGTH', 48);

// Afwijking drempel (>20% afwijking)
define('AFWIJKING_THRESHOLD_PERCENT', 20);
