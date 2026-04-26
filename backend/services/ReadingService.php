<?php
/**
 * ReadingService — Handles persistence of meter readings in the database
 */

namespace Horsterwold\Services;

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/AfwijkingService.php';

use Database;
use PDO;
use Exception;
use AfwijkingService;

class ReadingService
{
    private PDO $db;
    private AfwijkingService $afwijkingService;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->afwijkingService = new AfwijkingService();
    }

    /**
     * Save a single meter reading to the database
     */
    public function saveReading(array $data): int
    {
        // Extract data
        $lotId = $data['lot_id'];
        $type = $data['type']; // gas, water, elec
        $readingVal = $data['reading'];
        $imageUrl = $data['image_url'] ?? null;
        $exifTimestamp = $data['exif_timestamp'] ?? date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? null;

        // Perform Anomaly Detection
        // Note: For now, we only check one meter type at a time.
        $checkData = [
            'gas' => ($type === 'gas') ? $readingVal : null,
            'water' => ($type === 'water') ? $readingVal : null,
            'elec' => ($type === 'elec') ? $readingVal : null
        ];
        
        $anomalyCheck = $this->afwijkingService->check($lotId, $checkData['gas'], $checkData['water'], $checkData['elec']);

        // Get active billing period
        $activePeriod = Database::getActivePeriod();
        if (!$activePeriod) throw new Exception("Geen actieve afrekenperiode gevonden.");
        $billingPeriodId = $activePeriod['id'];

        // Get active occupancy for this lot
        $occStmt = $this->db->prepare('SELECT id FROM lot_occupancy WHERE lot_id = ? AND is_active = 1 LIMIT 1');
        $occStmt->execute([$lotId]);
        $occupancyId = $occStmt->fetchColumn();

        // FALLBACK: Als er geen actieve gevonden is, pak de meest recente 
        // (belangrijk bij verhuizing waarbij de admin de bewoner al op inactief heeft gezet)
        if (!$occupancyId) {
            $occStmt = $this->db->prepare('SELECT id FROM lot_occupancy WHERE lot_id = ? ORDER BY start_date DESC LIMIT 1');
            $occStmt->execute([$lotId]);
            $occupancyId = $occStmt->fetchColumn();
        }

        if (!$occupancyId) throw new Exception("Geen bewonershistorie gevonden voor kavel #" . $lotId);

        // Check for existing reading in this period to overwrite
        $checkStmt = $this->db->prepare(
            'SELECT id FROM readings WHERE lot_id = ? AND billing_period_id = ? AND occupancy_id = ?'
        );
        $checkStmt->execute([$lotId, $billingPeriodId, $occupancyId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE readings SET 
                    submitted_by = ?,
                    reading_date = ?,
                    gas_new_reading = COALESCE(?, gas_new_reading),
                    water_new_reading = COALESCE(?, water_new_reading),
                    electricity_new_reading = COALESCE(?, electricity_new_reading),
                    image_url = COALESCE(?, image_url),
                    image_url_gas = COALESCE(?, image_url_gas),
                    image_url_water = COALESCE(?, image_url_water),
                    image_url_elec = COALESCE(?, image_url_elec),
                    exif_timestamp = ?,
                    is_afwijking = ?,
                    afwijking_reden = ?,
                    is_manual_correction = COALESCE(?, is_manual_correction),
                    status = "pending",
                    reviewed_at = NULL,
                    reviewed_by = NULL,
                    occupancy_id = ?
                WHERE id = ?'
            );
            $stmt->execute([
                $userId,
                date('Y-m-d'),
                ($type === 'gas') ? $readingVal : null,
                ($type === 'water') ? $readingVal : null,
                ($type === 'elec') ? $readingVal : null,
                $imageUrl,
                ($type === 'gas') ? $imageUrl : null,
                ($type === 'water') ? $imageUrl : null,
                ($type === 'elec') ? $imageUrl : null,
                $exifTimestamp,
                $anomalyCheck['is_afwijking'] ? 1 : 0,
                $anomalyCheck['reden'],
                $data['is_manual_correction'] ?? 0,
                $occupancyId,
                $existing['id']
            ]);
            return (int)$existing['id'];
        } else {
            // Insert reading into MySQL
            $stmt = $this->db->prepare(
                'INSERT INTO readings (
                    lot_id, submitted_by, billing_period_id, 
                    reading_date, 
                    gas_new_reading, water_new_reading, electricity_new_reading,
                    image_url, image_url_gas, image_url_water, image_url_elec,
                    exif_timestamp, 
                    is_afwijking, afwijking_reden, 
                    is_manual_correction,
                    status, occupancy_id
                ) VALUES (
                    ?, ?, ?, 
                    ?, 
                    ?, ?, ?, 
                    ?, ?, ?, ?,
                    ?, 
                    ?, ?, 
                    ?,
                    "pending", ?
                )'
            );

            $stmt->execute([
                $lotId,
                $userId,
                $billingPeriodId,
                date('Y-m-d'),
                ($type === 'gas') ? $readingVal : null,
                ($type === 'water') ? $readingVal : null,
                ($type === 'elec') ? $readingVal : null,
                $imageUrl,
                ($type === 'gas') ? $imageUrl : null,
                ($type === 'water') ? $imageUrl : null,
                ($type === 'elec') ? $imageUrl : null,
                $exifTimestamp,
                $anomalyCheck['is_afwijking'] ? 1 : 0,
                $anomalyCheck['reden'],
                $data['is_manual_correction'] ?? 0,
                $occupancyId
            ]);

            return (int)$this->db->lastInsertId();
        }
    }

    /**
     * Save readings entered by an admin on behalf of a resident
     */
    public function saveProxyReading(array $data): int
    {
        $lotId = $data['lot_id'];
        $gas = $data['gas'] ?? null;
        $water = $data['water'] ?? null;
        $elec = $data['elec'] ?? null;
        $imageUrl = $data['image_url'] ?? null;
        $exifTimestamp = $data['exif_timestamp'] ?? date('Y-m-d H:i:s');
        $adminId = $_SESSION['user_id'] ?? null;

        // Perform Anomaly Detection
        $anomalyCheck = $this->afwijkingService->check($lotId, $gas, $water, $elec);

        $activePeriod = Database::getActivePeriod();
        if (!$activePeriod) throw new Exception("Geen actieve afrekenperiode gevonden.");
        $billingPeriodId = $activePeriod['id'];

        // Get active occupancy
        $occStmt = $this->db->prepare('SELECT id FROM lot_occupancy WHERE lot_id = ? AND is_active = 1 LIMIT 1');
        $occStmt->execute([$lotId]);
        $occupancyId = $occStmt->fetchColumn();

        $checkStmt = $this->db->prepare('SELECT id FROM readings WHERE lot_id = ? AND billing_period_id = ? AND occupancy_id = ?');
        $checkStmt->execute([$lotId, $billingPeriodId, $occupancyId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE readings SET 
                    submitted_by = ?,
                    reading_date = ?,
                    gas_new_reading = COALESCE(?, gas_new_reading),
                    water_new_reading = COALESCE(?, water_new_reading),
                    electricity_new_reading = COALESCE(?, electricity_new_reading),
                    image_url = COALESCE(?, image_url),
                    image_url_gas = COALESCE(?, image_url_gas),
                    image_url_water = COALESCE(?, image_url_water),
                    image_url_elec = COALESCE(?, image_url_elec),
                    exif_timestamp = ?,
                    is_afwijking = ?,
                    afwijking_reden = ?,
                    status = "approved",
                    reviewed_at = NOW(),
                    reviewed_by = ?,
                    occupancy_id = ?
                WHERE id = ?'
            );
            $stmt->execute([
                $adminId,
                date('Y-m-d'),
                $gas,
                $water,
                $elec,
                $imageUrl,
                ($gas !== null) ? $imageUrl : null,
                ($water !== null) ? $imageUrl : null,
                ($elec !== null) ? $imageUrl : null,
                $exifTimestamp,
                $anomalyCheck['is_afwijking'] ? 1 : 0,
                $anomalyCheck['reden'],
                $adminId,
                $occupancyId,
                $existing['id']
            ]);
            return (int)$existing['id'];
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO readings (
                    lot_id, submitted_by, billing_period_id, 
                    reading_date, 
                    gas_new_reading, water_new_reading, electricity_new_reading,
                    image_url, image_url_gas, image_url_water, image_url_elec,
                    exif_timestamp, 
                    is_afwijking, afwijking_reden, 
                    status, reviewed_at, reviewed_by,
                    occupancy_id
                ) VALUES (
                    ?, ?, ?, 
                    ?, 
                    ?, ?, ?, 
                    ?, ?, ?, ?,
                    ?, 
                    ?, ?, 
                    "approved", NOW(), ?, ?
                )'
            );

            $stmt->execute([
                $lotId,
                $adminId,
                $billingPeriodId,
                date('Y-m-d'),
                $gas,
                $water,
                $elec,
                $imageUrl,
                ($gas !== null) ? $imageUrl : null,
                ($water !== null) ? $imageUrl : null,
                ($elec !== null) ? $imageUrl : null,
                $exifTimestamp,
                $anomalyCheck['is_afwijking'] ? 1 : 0,
                $anomalyCheck['reden'],
                $adminId,
                $occupancyId
            ]);

            return (int)$this->db->lastInsertId();
        }
    }
}
