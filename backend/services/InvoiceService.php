<?php
/**
 * InvoiceService — Calculates utility bills based on readings and tariffs.
 * Handles VAT (21%) and manual corrections.
 */

namespace Horsterwold\Services;

require_once __DIR__ . '/../core/Database.php';

use Database;
use PDO;
use Exception;
use DateTime;

class InvoiceService
{
    private PDO $db;
    private float $vatRate = 21.0; // Default 21%

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Calculate billing for a specific occupancy record
     */
    public function calculatePreview(int $occupancyId, float $correction = 0, string $reason = ''): array
    {
        // 1. Fetch Basic Data
        $occupancy = $this->getOccupancy($occupancyId);
        if (!$occupancy) throw new Exception("Geen bewoningsgegevens gevonden.");

        $lotId = $occupancy['lot_id'];
        $lot = $this->getLot($lotId);
        $activePeriod = Database::getActivePeriod();
        $periodId = $activePeriod['id'];
        
        $tariffs = $this->getTariffs($periodId);
        $fixed = $this->getFixedCosts($periodId, $lot['lot_type']);
        $reading = $this->getLatestApprovedReadingForOccupancy($occupancyId, $periodId);
        
        if (!$reading) {
            // Fallback for preview: use latest pending or just current lot readings if approved not found
            $reading = $this->getLatestReadingForOccupancy($occupancyId, $periodId);
        }
        
        if (!$reading) {
            throw new Exception("Geen meting gevonden voor deze bewoner in deze periode.");
        }

        $baseline = [
            'gas'   => $occupancy['start_gas'], 
            'water' => $occupancy['start_water'], 
            'elec'  => $occupancy['start_elec']
        ];

        // 2. Consumption Math with Rollover Logic
        $consGas   = $this->calculateConsumption($reading['gas_new_reading'] ?? 0, $baseline['gas'] ?? 0);
        $consWater = $this->calculateConsumption($reading['water_new_reading'] ?? 0, $baseline['water'] ?? 0);
        $consElec  = $this->calculateConsumption($reading['electricity_new_reading'] ?? 0, $baseline['elec'] ?? 0);
        $consSolar = $reading['solar_return'] ?? 0;

        // 3. Costs (Excl VAT)
        $costGas   = $consGas   * $tariffs['gas_price_per_m3'];
        $costWater = $consWater * $tariffs['water_price_per_m3'];
        $costElec  = $consElec  * $tariffs['electricity_price_per_kwh'];
        $creditSolar = $consSolar * $tariffs['solar_return_price_per_kwh'];

        // 4. Fixed Costs (dynamic months)
        // Calculate months based on occupancy start and end/reading date
        $startDate = new DateTime($occupancy['start_date']);
        $endDate = $occupancy['end_date'] ? new DateTime($occupancy['end_date']) : new DateTime($reading['reading_date'] ?? date('Y-12-31'));
        
        // Ensure we don't calculate outside the current year/period
        // For simplicity, we assume the active period is the current calendar year
        $periodYear = (int)$activePeriod['year'];
        
        $startLimit = new DateTime("$periodYear-01-01");
        $endLimit = new DateTime("$periodYear-12-31");
        
        $actualStart = max($startDate, $startLimit);
        $actualEnd = min($endDate, $endLimit);
        
        if ($actualStart > $actualEnd) {
            $months = 0;
        } else {
            $interval = $actualStart->diff($actualEnd);
            $months = ($interval->y * 12) + $interval->m + ($interval->d > 15 ? 1 : 0);
            $months = max(1, $months); // Minimaal 1 maand bij bewoning
        }
        
        $vastGas   = $fixed['vast_gas_per_month'] * $months;
        $vastWater = $fixed['vast_water_per_month'] * $months;
        $vastElec  = $fixed['vast_electricity_per_month'] * $months;
        
        // VVE and Erfpacht are per year, so we pro-rate them if months < 12
        $vastVve   = ($fixed['vve_per_year'] / 12) * $months;
        $vastErfpacht = ($fixed['erfpacht_per_year'] / 12) * $months;

        $totalInclVat = ($costGas + $costWater + $costElec) 
                       + ($vastGas + $vastWater + $vastElec + $vastVve + $vastErfpacht)
                       - $creditSolar
                       + $correction;

        // Tarieven zijn reeds inclusief BTW. We berekenen de BTW "terug" voor info.
        $vatRateDecimal = $this->vatRate / 100;
        $subtotalExVat = $totalInclVat / (1 + $vatRateDecimal);
        $vatAmount = $totalInclVat - $subtotalExVat;

        return [
            'lot' => $lot,
            'occupancy' => $occupancy,
            'period_id' => $periodId,
            'reading_id' => $reading['id'],
            'consumption' => [
                'gas' => $consGas,
                'water' => $consWater,
                'elec' => $consElec,
                'solar' => $consSolar
            ],
            'costs' => [
                'gas' => round($costGas, 2),
                'water' => round($costWater, 2),
                'elec' => round($costElec, 2),
                'solar_credit' => round($creditSolar, 2)
            ],
            'fixed' => [
                'gas' => round($vastGas, 2),
                'water' => round($vastWater, 2),
                'elec' => round($vastElec, 2),
                'vve' => round($vastVve, 2),
                'erfpacht' => round($vastErfpacht, 2)
            ],
            'summary' => [
                'period_months' => $months,
                'subtotal_ex_vat' => round($subtotalExVat, 2),
                'vat_rate' => $this->vatRate,
                'vat_amount' => round($vatAmount, 2),
                'correction' => round($correction, 2),
                'correction_reason' => $reason,
                'total' => round($totalInclVat, 2),
                'is_vat_inclusive' => true
            ]
        ];
    }

    private function getOccupancy(int $id) {
        $stmt = $this->db->prepare("SELECT * FROM lot_occupancy WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getLot(int $id) {
        $stmt = $this->db->prepare("SELECT * FROM lots WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getTariffs(int $periodId) {
        $stmt = $this->db->prepare("SELECT * FROM tariffs WHERE billing_period_id = ?");
        $stmt->execute([$periodId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getFixedCosts(int $periodId, string $type) {
        $stmt = $this->db->prepare("SELECT * FROM fixed_costs_templates WHERE billing_period_id = ? AND lot_type = ?");
        $stmt->execute([$periodId, $type]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getLatestApprovedReadingForOccupancy(int $occupancyId, int $periodId) {
        $stmt = $this->db->prepare("SELECT * FROM readings WHERE occupancy_id = ? AND billing_period_id = ? AND status = 'approved' ORDER BY reading_date DESC LIMIT 1");
        $stmt->execute([$occupancyId, $periodId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function getLatestReadingForOccupancy(int $occupancyId, int $periodId) {
        $stmt = $this->db->prepare("SELECT * FROM readings WHERE occupancy_id = ? AND billing_period_id = ? ORDER BY reading_date DESC LIMIT 1");
        $stmt->execute([$occupancyId, $periodId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Calculate consumption with rollover logic for 5-digit meters
     * If new reading < old reading, assume meter rolled over at 99999
     */
    private function calculateConsumption(float $newReading, float $oldReading): float
    {
        if ($newReading < $oldReading) {
            // Rollover detected: (99999 - old) + new
            return (99999 - $oldReading) + $newReading;
        }
        
        // Normal case: new - old
        return max(0, $newReading - $oldReading);
    }
}
