<?php
// Prevent any existing output
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Mock the environment for login.php
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    
    // We can't easily mock php://input for file_get_contents inside the included file
    // unless we use a wrapper or temporary file, but we just want to see if it even
    // GETS to the try/catch or if it fails during initialization.
    
    require_once __DIR__ . '/../backend/api/login.php';
    
} catch (Throwable $e) {
    echo "\nFATAL ERROR CAPTURED: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
}

$output = ob_get_clean();
echo "--- OUTPUT BEGIN ---\n";
echo $output;
echo "\n--- OUTPUT END ---\n";
