<?php
/**
 * Afwijking detectie service
 * Vergelijkt nieuwe meterstand met historisch gemiddelde.
 * Vlag is_afwijking = true als verbruik >20% afwijkt of stand lager is dan vorige.
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../config.php';

class AfwijkingService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Controleer een nieuwe meterstand op afwijkingen.
     * Retourneert ['is_afwijking' => bool, 'reden' => string|null]
     */
    public function check(int $lotId, float|null $gasNew, float|null $waterNew, float|null $electricityNew): array
    {
        $afwijking = false;
        $reasons = [];

        // Haal vorige goedgekeurde meting op als baseline
        $stmt = $this->db->prepare(
            'SELECT r.gas_new_reading, r.water_new_reading, r.electricity_new_reading,
                    r.gas_consumption, r.water_consumption, r.electricity_consumption
             FROM readings r
             WHERE r.lot_id = ? AND r.status = "approved"
             ORDER BY r.reading_date DESC
             LIMIT 1'
        );
        $stmt->execute([$lotId]);
        $prev = $stmt->fetch();

        if (!$prev) {
            // Geen historische data beschikbaar — geen afwijking-check mogelijk
            return ['is_afwijking' => false, 'reden' => null];
        }

        // GAS controles
        if ($gasNew !== null && $prev['gas_new_reading'] !== null) {
            if ($gasNew < $prev['gas_new_reading']) {
                $afwijking = true;
                $reasons[] = 'Gasmeterstand lager dan vorige meting';
            } elseif ($prev['gas_consumption'] > 0) {
                $gasConsumption = $gasNew - $prev['gas_new_reading'];
                $deviation = abs($gasConsumption - $prev['gas_consumption']) / $prev['gas_consumption'] * 100;
                if ($deviation > AFWIJKING_THRESHOLD_PERCENT) {
                    $afwijking = true;
                    $reasons[] = sprintf('Gasverbruik wijkt %.1f%% af van vorige periode', $deviation);
                }
            }
        }

        // WATER controles
        if ($waterNew !== null && $prev['water_new_reading'] !== null) {
            if ($waterNew < $prev['water_new_reading']) {
                $afwijking = true;
                $reasons[] = 'Watermeterstand lager dan vorige meting';
            } elseif ($prev['water_consumption'] > 0) {
                $waterConsumption = $waterNew - $prev['water_new_reading'];
                $deviation = abs($waterConsumption - $prev['water_consumption']) / $prev['water_consumption'] * 100;
                if ($deviation > AFWIJKING_THRESHOLD_PERCENT) {
                    $afwijking = true;
                    $reasons[] = sprintf('Waterverbruik wijkt %.1f%% af van vorige periode', $deviation);
                }
            }
        }

        // ELEKTRA controles
        if ($electricityNew !== null && $prev['electricity_new_reading'] !== null) {
            if ($electricityNew < $prev['electricity_new_reading']) {
                $afwijking = true;
                $reasons[] = 'Elektrameterstand lager dan vorige meting';
            } elseif ($prev['electricity_consumption'] > 0) {
                $elecConsumption = $electricityNew - $prev['electricity_new_reading'];
                $deviation = abs($elecConsumption - $prev['electricity_consumption']) / $prev['electricity_consumption'] * 100;
                if ($deviation > AFWIJKING_THRESHOLD_PERCENT) {
                    $afwijking = true;
                    $reasons[] = sprintf('Elektraverbruik wijkt %.1f%% af van vorige periode', $deviation);
                }
            }
        }

        return [
            'is_afwijking' => $afwijking,
            'reden'        => $afwijking ? implode('; ', $reasons) : null,
        ];
    }
}
