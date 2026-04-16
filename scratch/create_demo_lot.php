<?php
/**
 * Create Demo Lot #999 for testing and demonstration
 */
require_once __DIR__ . '/../backend/core/Database.php';

$db = Database::getConnection();

try {
    $db->beginTransaction();

    // 1. Check if lot exists
    $stmt = $db->prepare("SELECT id FROM lots WHERE lot_number = '999'");
    $stmt->execute();
    $lotId = $stmt->fetchColumn();

    if (!$lotId) {
        $stmt = $db->prepare("INSERT INTO lots (lot_number, lot_type, address, has_gas, has_water, has_electricity) VALUES ('999', 'bebouwd', 'Demo Straat 123', 1, 1, 1)");
        $stmt->execute();
        $lotId = $db->lastInsertId();
        echo "✅ Demo Lot #999 created.\n";
    } else {
        echo "ℹ️ Demo Lot #999 already exists.\n";
    }

    // 2. Add/Update Demo Resident
    $email = 'demo@horsterwold.nl';
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        $stmt = $db->prepare("INSERT INTO users (name, email, role, status) VALUES ('Demo Resident', ?, 'resident', 'active')");
        $stmt->execute([$email]);
        $userId = $db->lastInsertId();
    }
    
    $stmt = $db->prepare("UPDATE lots SET user_id = ? WHERE id = ?");
    $stmt->execute([$userId, $lotId]);

    // 3. Clear existing readings for this lot to have a fresh state
    $db->prepare("DELETE FROM readings WHERE lot_id = ?")->execute([$lotId]);

    // 4. Add Baseline (2025 Start)
    $db->prepare("INSERT INTO import_history (lot_id, billing_period_id, gas_new_reading, water_new_reading, electricity_new_reading) VALUES (?, 1, 1000, 500, 2000)")->execute([$lotId]);
    echo "✅ Baseline seeded (Gas: 1000, Water: 500, Elec: 2000).\n";

    $db->commit();
    echo "\n🚀 Demo environment for kavel #999 ready.\n";

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
