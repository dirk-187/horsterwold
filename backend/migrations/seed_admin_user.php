<?php
/**
 * Migration: Seed admin test user
 * Aanmaken van een beheerder test account: test@allemeters.nl
 */

require_once __DIR__ . '/../core/Database.php';

try {
    $db = Database::getConnection();

    $email = 'test@allemeters.nl';
    $name  = 'Admin Testgebruiker';
    $role  = 'admin';

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        // Ensure it's an admin
        $db->prepare("UPDATE users SET role = 'admin', name = ? WHERE email = ?")->execute([$name, $email]);
        echo "Admin user '$email' already existed, role updated to 'admin'.\n";
    } else {
        $db->prepare("INSERT INTO users (email, name, role) VALUES (?, ?, ?)")->execute([$email, $name, $role]);
        echo "Admin user '$email' created successfully.\n";
    }

    echo "Done.\n";

} catch (Exception $e) {
    die("Seed failed: " . $e->getMessage() . "\n");
}
