<?php
/**
 * Create Admin Account for Dirk Luttikhold
 */
require_once __DIR__ . '/../backend/core/Database.php';
require_once __DIR__ . '/../backend/services/AuthService.php';

$db = Database::getConnection();
$auth = new AuthService();

$name = "Dirk Luttikhold";
$email = "dirk@vloeienddigitaal.nl";

try {
    // 1. Check/Insert User
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        $stmt = $db->prepare("INSERT INTO users (name, email, role, status) VALUES (?, ?, 'admin', 'active')");
        $stmt->execute([$name, $email]);
        $userId = $db->lastInsertId();
        echo "✅ User created: $name ($email)\n";
    } else {
        // Ensure user is admin and active
        $stmt = $db->prepare("UPDATE users SET role = 'admin', status = 'active' WHERE id = ?");
        $stmt->execute([$userId]);
        echo "✅ User updated to Admin: $name ($email)\n";
    }

    // 2. Generate Magic Link
    // generateMagicLink(email, isAdmin)
    $magicLink = $auth->generateMagicLink($email, true);

    if ($magicLink) {
        echo "\n🚀 JE TESTLINK (Geldig voor 1 uur):\n";
        echo $magicLink . "\n";
    } else {
        echo "❌ Fout bij het genereren van de link.\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
