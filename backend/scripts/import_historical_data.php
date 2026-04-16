<?php
/**
 * Script to import historical meter readings from the TSV file.
 * Mapping confirmed:
 * Lot: Col 19
 * Gas 2024: Start Col 2, End Col 3
 * Gas 2025: Start Col 3, End Col 5
 * ... and so on for Water and Elec.
 */

require_once __DIR__ . '/../core/Database.php';

$tsvPath = __DIR__ . '/../../documenten/Ontvangen documenten/excel';
$db = Database::getConnection();

// Get Period IDs
$stmt = $db->query("SELECT year, id FROM billing_periods");
$periods = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [year => id]
$id2024 = $periods[2024] ?? null;
$id2025 = $periods[2025] ?? null;

var_dump($periods);
if (!$id2024 || !$id2025) {
    die("Error: Required billing periods (2024, 2025) not found in database.\n");
}

if (!file_exists($tsvPath)) {
    die("Error: TSV file not found at $tsvPath\n");
}

$handle = fopen($tsvPath, "r");
$rowCount = 0;
$importedCount = 0;

$db->beginTransaction();

try {
    while (($line = fgets($handle)) !== false) {
        $rowCount++;
        // Skip header lines (first 2 lines)
        if ($rowCount <= 2) continue;

        $cols = explode("\t", $line);
        if (count($cols) < 20) continue;

        $lotNr = trim($cols[19]);
        if (empty($lotNr) || !is_numeric($lotNr)) continue;

        // Find lot_id
        $stmt = $db->prepare("SELECT id FROM lots WHERE lot_number = ?");
        $stmt->execute([$lotNr]);
        $lotId = $stmt->fetchColumn();

        if (!$lotId) {
            echo "Skipping Lot #$lotNr: Not found in database.\n";
            continue;
        }

        // Data 2024
        $importData24 = [
            'lot_id' => $lotId,
            'billing_period_id' => $id2024,
            'gas_prev' => trim($cols[2]),
            'gas_new' => trim($cols[3]),
            'water_prev' => trim($cols[8]),
            'water_new' => trim($cols[9]),
            'elec_prev' => trim($cols[14]),
            'elec_new' => trim($cols[15])
        ];

        // Data 2025
        $importData25 = [
            'lot_id' => $lotId,
            'billing_period_id' => $id2025,
            'gas_prev' => trim($cols[3]),
            'gas_new' => trim($cols[5]),
            'water_prev' => trim($cols[9]),
            'water_new' => trim($cols[11]),
            'elec_prev' => trim($cols[15]),
            'elec_new' => trim($cols[17])
        ];

        saveImportHistory($db, $importData24);
        saveImportHistory($db, $importData25);
        echo "Imported Lot #$lotNr (2024 ID: $id2024, 2025 ID: $id2025)\n";
        $importedCount++;
    }

    $db->commit();
    echo "Import completed! Processed $importedCount lots.\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "Error during import: " . $e->getMessage() . "\n";
}

fclose($handle);

function saveImportHistory($db, $data) {
    // Check if already exists to avoid duplicates
    $stmt = $db->prepare("SELECT id FROM import_history WHERE lot_id = ? AND billing_period_id = ?");
    $stmt->execute([$data['lot_id'], $data['billing_period_id']]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $sql = "UPDATE import_history SET 
                gas_prev_reading = ?, gas_new_reading = ?, 
                water_prev_reading = ?, water_new_reading = ?, 
                electricity_prev_reading = ?, electricity_new_reading = ?,
                source_file = 'manual_import_tsv'
                WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $data['gas_prev'], $data['gas_new'],
            $data['water_prev'], $data['water_new'],
            $data['elec_prev'], $data['elec_new'],
            $existingId
        ]);
    } else {
        $sql = "INSERT INTO import_history 
                (lot_id, billing_period_id, gas_prev_reading, gas_new_reading, water_prev_reading, water_new_reading, electricity_prev_reading, electricity_new_reading, source_file)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual_import_tsv')";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $data['lot_id'], $data['billing_period_id'],
            $data['gas_prev'], $data['gas_new'],
            $data['water_prev'], $data['water_new'],
            $data['elec_prev'], $data['elec_new']
        ]);
    }
}
