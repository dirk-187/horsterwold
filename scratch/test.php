<?php
require_once __DIR__ . '/../backend/core/Database.php';
require_once __DIR__ . '/../backend/services/AuthService.php';

try {
    $authService = new AuthService();
    $link = $authService->generateMagicLink('test@allemeters.nl', true);
    echo "Generated Link: " . $link . "\n";

    // Extract token
    $parts = explode('token=', $link);
    $token = $parts[1] ?? '';

    $user = $authService->verifyMagicLink($token);
    echo "Verified user: \n";
    print_r($user);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
