<?php
// Mock php://input
$token = 'invalid-token-123';
$input = json_encode(['action' => 'verify', 'token' => $token]);

// Set up the environment
$_SERVER['REQUEST_METHOD'] = 'POST';

// We can use a stream wrapper to mock php://input if needed, 
// but here we just want to see if the code runs without fatal errors.

require_once 'backend/core/Database.php';
require_once 'backend/services/AuthService.php';

try {
    $authService = new AuthService();
    $user = $authService->verifyMagicLink($token);
    if ($user) {
        echo "FAIL: Token should be invalid\n";
    } else {
        echo "PASS: Token recognized as invalid\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
