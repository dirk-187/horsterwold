<?php
/**
 * Backend Initialization
 * Loads configuration, database, and Composer autoloader
 */

// Composer Autoloader
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// Configuration
require_once __DIR__ . '/../config.php';

// Core Classes
require_once __DIR__ . '/Database.php';

// Set error reporting based on environment
if (defined('APP_ENV') && APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
