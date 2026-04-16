<?php
require_once __DIR__ . '/../backend/services/AuthService.php';
$auth = new AuthService();

// Try to find an admin user and generate a token
$db = Database::getConnection();
$adminEmail = 'dirk@vloeienddigitaal.nl';
$stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$adminEmail]);
$id = $stmt->fetchColumn();

if (!$id) {
    echo "Admin user not found: $adminEmail\n";
    exit;
}

$token = bin2hex(random_bytes(24));
$db->prepare("UPDATE users SET magic_link_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")
   ->execute([$token, $id]);

echo "Generated token: $token\n";

// Now verify it
$user = $auth->verifyMagicLink($token);

if ($user) {
    echo "Verification successful for user: " . $user['email'] . "\n";
} else {
    echo "Verification failed!\n";
}
