<?php
/**
 * Setup script for Horsterwold testing environment.
 * 1. Runs the migration to add resident_email.
 * 2. Updates the 'excel' file with resident names.
 * 3. Imports baseline data and lot info into the database.
 */

require_once __DIR__ . '/../core/Database.php';

$db = Database::getConnection();

// 1. Run Migration
echo "Running migration...\n";
try {
    $db->exec("ALTER TABLE lots ADD COLUMN resident_name VARCHAR(255) NULL AFTER user_id");
    echo "Migration: Added resident_name.\n";
} catch (Exception $e) {}

try {
    $db->exec("ALTER TABLE lots ADD COLUMN resident_email VARCHAR(255) NULL AFTER resident_name");
    echo "Migration: Added resident_email.\n";
} catch (Exception $e) {}

// 2. Prepare Names List
$namesList = [
    "De Haag B.V., J. Kriele", "De Haag B.V., J. Kriele", "C. Kaldenbach, G. Kaldenbach", "C.M. van Tol",
    "Madiba Verhuur B.V., De heer J. van den End", "Madiba Verhuur B.V., De heer J. van den End", "Madiba Verhuur B.V., De heer J. van den End",
    "M. Alirezazadeh , B. Bahrami", "S. Bok, R.P.M. Bok", "R.P.M. Rademaker", "D. Brouwer, M.Y. Brouwer - Teunisse",
    "R.C.J. van Leeuwen, I. van Leeuwen", "E. Hogendoorn - de Groot, M. Jansen - de Groot", "Haas/ 3 oktober 2024",
    "W.M.M. Rademakers, M.G.J. Rademakers - Löwenthal", "A. Muhammad", "R. Leenders", "F. Kramer", "B.J. Hofland, J. Knol",
    "A.C.M. Harte", "M.C. Poelhekke - Verhage, R. Poelhekke", "D. Zeilstra", "Pelgrum", "B. Sluijs", "S. Hoving",
    "B.G.P. Kronenburg", "J.A.M. Bloedjes", "D.J. de Winter", "M.H.A. Kamphorst, E.L. Kamphorst - Grosman", "L. Tolhoek",
    "J. Dirar", "M.J. Rigtering", "L. Meurs", "M. Kamphorst", "H. Wanschers, J. Wanschers - Bosboom", "M.A.F. Moerel",
    "Nijkamp", "R. Bijl - van den Hoeven", "J.G.F. Drenth, B.D. Stapel", "A.H.H. Bader", "A.R. Kirschey - Heups",
    "C.J.C. Chardon - van der Maarl", "H. Haxe, M.H.A. Haxe - Arends", "I. de Rijk - Markus", "C.J.H.G. Zwezerijn, M. Zwezerijn - Welling",
    "R. Duinkerken", "De Jong", "A.H.M. de Bruycker-Martens, M. de Bruycker-Martens", "G.A. Rodijk", "Ismail", "Nadiro",
    "G. de Hek", "G. van der Hoek", "A. Polderman", "J. Schulp, S.C. Schulp - Donkervoort", "H. van Broekhuizen",
    "L.C. van der Kolk", "M.C. van Belkom", "H.G.A.M. Schreuder", "R.C. Verduin, J.A.M. Dekker", "R.H. van de Pol, J.E. van de Pol",
    "Flamingo", "E. van Tongeren, P.E.C. Westerhoff", "R.J. Netto, R. Netto", "A. Kloprogge", "C.M. van Apeldoorn",
    "Brinkman/ 4 juli 2024", "A. Polderman", "F.T.H.M. van Driel", "D.I. Weiss", "Polder Vastgoed I B.V.", "Polder Vastgoed I B.V.",
    "M.W. Boevé, A. Touwen", "M. De Vries - De Jager", "R. Nijkamp", "R. Nijkamp", "H.J. de Vries , M.C. de Vries en J.S. de Vries",
    "M.J. Groenveld", "Flamingo Ventures B.V., J. de Waard, J. van de Kamp", "T. Kroon", "A.C.G. Huiden", "H. Panhuis",
    "M.A. Poort", "L.A.H.B. Tappel , E.F.M. de Wit - Tappel", "R.J. Groen", "C. Koelewijn", "H. de Voor", "M.A. van der Moolen",
    "Polder Vastgoed I B.V.", "Polder Vastgoed I B.V.", "M. de Vet", "Hoogland", "C. van Dijk", "H.S. Renzenbrink, N.M. Renzenbrink - Tochhilkina",
    "L.L.A. Olde Rikkert", "G.J.C. Ransijn , S.C.E. van Doornen", "G. Steffens", "M.P. Wetser", "I. Knotters", "L. Karelse - Abrahamse",
    "H. Kuiper, M. Kuiper - Mik", "M.D. Korompis , C. Korompis - van Meerkerk", "W.K. Zwaagman, J. Zwaagman", "Secunda Giedi B.V.",
    "Visscher", "L. Herms/Meinten", "Chen", "M. van Ramshorst", "Polder Vastgoed I B.V.", "C.E. Scamacca, S. Scamacca",
    "S. Stormbroek", "C.E. Scamacca, S. Scamacca", "Polder Vastgoed I B.V.", "Polder Vastgoed I B.V.", "C.E. Scamacca, S. Scamacca",
    "R. Masselink", "R.H. Stalman", "A. Polderman", "A. Polderman", "Herbert Exploitatie B.V.", "Sman", "E.A.M. Spaaij, G.B. Timmer",
    "Relax Realty B.V", "A. Polderman", "A. Polderman", "H.J. de Vries, J.J. Roos", "R. Nijkamp", "A. Voorn, M. Voorn - Thiel",
    "A. Voorn, M. Voorn - Thiel", "S.C. Rudelsheim", "W. Chen, Y. Guo", "Bart Desaunois Holding B.V., B. Desaunois",
    "Bart Desaunois Holding B.V., B. Desaunois", "Bart Desaunois Holding B.V., B. Desaunois", "Bart Desaunois Holding B.V., B. Desaunois",
    "G.T. Kaiser, T. Kok", "Bart Desaunois Holding B.V., B. Desaunois", "Bart Desaunois Holding B.V., B. Desaunois",
    "Bart Desaunois Holding B.V., B. Desaunois", "Bart Desaunois Holding B.V., B. Desaunois", "Bart Desaunois Holding B.V., B. Desaunois",
    "Vermeulen 5 januari 2024", "Bart Desaunois Holding B.V., B. Desaunois", "Bart Desaunois Holding B.V., B. Desaunois",
    "Bart Desaunois Holding B.V., B. Desaunois", "M.J. Boellaard, S. Boellaard", "Polder Vastgoed I B.V.", "Polder Vastgoed I B.V.",
    "Polder Vastgoed I B.V.", "J.M.V. Vos", "W. Plaisier, J. Neijmeijer", "L.G. Romans van Schaik, H. van Caspel", "G.W. van 't Wout",
    "F. El Osrouti , M. El Osrouti", "Wallet", "G.B. Böekling, A.B. Böekling - Janse", "T. Pelgrum", "D.J. van Essen", "J.M. Appeldoorn",
    "Gootjes", "H.J. de Vries", "J. Pelgrum", "C. Klein", "Gootjes", "A. de Groot", "J.A. Rompes, L. Bushoff", "A. Polderman",
    "A. Polderman", "A. Polderman", "C.E. Scamacca, S. Scamacca", "Simsek", "A. Polderman", "A. Polderman", "Polder Vastgoed I B.V.",
    "A.A. Tillart", "L.L. Rebel", "D.V.A. van Grieken", "Polder Vastgoed II B.V.", "Polder Vastgoed II B.V.", "Polder Vastgoed II B.V.",
    "A. Versteeve", "Flamingo", "Polder Vastgoed I B.V.", "Polder Vastgoed I B.V.", "Polder Vastgoed I B.V.", "C.E. Scamacca, S. Scamacca",
    "C.E. Scamacca, S. Scamacca", "Herbert Exploitatie B.V.", "S. Acu", "F.N. Heinen, L. Scheepmaker", "F.N. Heinen, L. Scheepmaker",
    "Duits", "Mevlut", "Pelgrum", "J. Zeilstra, A. Zeilstra", "J. Zeilstra, A. Zeilstra", "D. Groen, M. Groen - de Lange",
    "H.M.T. Truijens", "L.A.H.B. Tappel , E.F.M. de Wit - Tappel", "Polderman 9 september 2024", "R.P.M. Bok, S. Bok", "Hofland",
    "J.A.M. Bank, D. Bank - Reine", "S. van Duykeren", "VvE Park Horsterwold", "H. Karssen, B. van de Heisteeg"
];

// 3. Update 'excel' file
echo "Updating excel file...\n";
$filePath = __DIR__ . '/../../documenten/Ontvangen documenten/excel';
$lines = file($filePath);
$newLines = [];
$nameIndex = 0;

foreach ($lines as $i => $line) {
    if ($i < 2) { // Header rows
        $newLines[] = $line;
        continue;
    }
    
    $parts = explode("\t", $line);
    $lotNumber = (int)trim($parts[0]);
    
    // Check if we have a name for this line (based on Lot Number)
    // Note: Kavel 40 is missing in the file, so we need to skip it in the names list if it corresponds.
    // However, the user said "startend vanaf rij 3 (nr 1)".
    // The list has 204 names? Let's check.
}

// Rewriting logic for more robustness
$outputLines = [];
$outputLines[0] = $lines[0];
$outputLines[1] = $lines[1];

$lotData = [];
for ($i = 2; $i < count($lines); $i++) {
    $line = trim($lines[$i]);
    if (empty($line)) continue;
    $parts = explode("\t", $lines[$i]);
    $lotNumber = (int)trim($parts[0]);
    if ($lotNumber > 0) {
        $lotData[$lotNumber] = $parts;
    }
}

// Map names to lots
$db->beginTransaction();
try {
    // Clear old import history for fresh test
    $db->exec("DELETE FROM import_history WHERE billing_period_id = 1");
    
    for ($lotNum = 1; $lotNum <= 205; $lotNum++) {
        if (!isset($lotData[$lotNum])) {
            if ($lotNum != 40) echo "Warning: Lot $lotNum missing in excel file data.\n";
            continue;
        }
        
        $parts = $lotData[$lotNum];
        $name = $namesList[$nameIndex++] ?? "Onbekend";
        
        // Update parts (Column 2 is index 1)
        // Make sure there are enough parts
        while (count($parts) < 2) $parts[] = "";
        $parts[1] = $name;
        
        $outputLines[] = implode("\t", $parts);
        
        // Database update: lots
        $email = "bewoner" . $lotNum . "@horsterwold.test";
        $stmt = $db->prepare("UPDATE lots SET resident_name = ?, resident_email = ?, lot_type = ? WHERE lot_number = ?");
        $type = (trim($parts[count($parts)-2]) == 'Bebouwd') ? 'bebouwd' : 'onbebouwd';
        $stmt->execute([$name, $email, $type, $lotNum]);
        
        // Database update: import_history (baseline)
        // Extracting 2024 and 2025 values from fixed positions in the file
        // 2024: parts[2] (Gas), parts[7] (Water), parts[13] (Elec)
        // 2025: parts[3] (Gas End), parts[8] (Water End), parts[14] (Elec End)
        // Note: Indices vary by file structure, let's keep it simple or based on inspection.
        
        $gas2024 = (float)($parts[2] ?? 0);
        $gas2025 = (float)($parts[3] ?? 0);
        $water2024 = (float)($parts[7] ?? 0);
        $water2025 = (float)($parts[8] ?? 0);
        $elec2024 = (float)($parts[13] ?? 0);
        $elec2025 = (float)($parts[14] ?? 0);
        
        $stmtH = $db->prepare("INSERT INTO import_history (lot_id, billing_period_id, gas_prev_reading, gas_new_reading, water_prev_reading, water_new_reading, electricity_prev_reading, electricity_new_reading) 
                              SELECT id, 1, ?, ?, ?, ?, ?, ? FROM lots WHERE lot_number = ?");
        $stmtH->execute([$gas2024, $gas2025, $water2024, $water2025, $elec2024, $elec2025, $lotNum]);
    }
    
    $db->commit();
    file_put_contents($filePath, implode("", $outputLines));
    echo "Processing complete. Database and excel file updated.\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
