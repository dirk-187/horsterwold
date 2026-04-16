-- ============================================================
-- Horsterwold Meterstanden Applicatie
-- Database Schema — Fase 1
-- Aangemaakt: 2026-03-26
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- TABEL 1: users
-- Bewoners (role=resident) en beheerders (role=admin)
-- Authenticatie via magic link / uitnodigingscode
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(255) NOT NULL UNIQUE,
    name                VARCHAR(255) NULL,
    role                ENUM('resident', 'admin') NOT NULL DEFAULT 'resident',
    magic_link_token    VARCHAR(64) NULL,
    token_expires_at    DATETIME NULL,
    last_login_at       DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Gebruikers: bewoners en beheerders';

-- ============================================================
-- TABEL 2: lots (kavels)
-- Elk kavelnummer krijgt 1 rij; nummering loopt t/m 205.
-- lot_type bepaalt welke vaste lasten van toepassing zijn.
-- ============================================================
CREATE TABLE IF NOT EXISTS lots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lot_number      SMALLINT UNSIGNED NOT NULL UNIQUE COMMENT 'Kavelnummer 1–205',
    user_id         INT UNSIGNED NULL COMMENT 'Huidige bewoner',
    resident_name   VARCHAR(255) NULL COMMENT 'Naam gekoppeld aan kavel',
    lot_type        ENUM('bebouwd', 'onbebouwd') NOT NULL DEFAULT 'bebouwd',
    address         VARCHAR(255) NULL,
    has_gas         TINYINT(1) NOT NULL DEFAULT 1,
    has_water       TINYINT(1) NOT NULL DEFAULT 1,
    has_electricity TINYINT(1) NOT NULL DEFAULT 1,
    has_solar       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Zonnepanelen (teruglevering)',
    meter_type      VARCHAR(100) NULL COMMENT 'Bijv. Normaal, Zonnepaneel',
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_lot_number (lot_number),
    INDEX idx_lot_type (lot_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kavels 1–205 in het Horsterwold park';

-- ============================================================
-- TABEL 3: billing_periods (afrekenperiodes)
-- Doorgaans één rij per jaar (bijv. "Jaarafrekening 2025")
-- ============================================================
CREATE TABLE IF NOT EXISTS billing_periods (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year        YEAR NOT NULL,
    label       VARCHAR(100) NULL COMMENT 'Bijv. Jaarafrekening 2025',
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    status      ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Afrekenperiodes per jaar';

-- Seed: voeg periode 2025 direct toe
INSERT INTO billing_periods (year, label, start_date, end_date, status)
VALUES (2025, 'Jaarafrekening 2025', '2025-01-01', '2025-12-31', 'open');

-- ============================================================
-- TABEL 4: tariffs (tarieven per afrekenperiode)
-- Instelbaar via admin dashboard.
-- Prijzen uit Excel 2025:
--   Gas:   € 3,45 / m³
--   Water: € 1,70 / m³  (kolom "1,7" in Excel = prijs per m³)
--   Elektra: € 8,27 / kWh (vast verbruikskolom in header)
-- ============================================================
CREATE TABLE IF NOT EXISTS tariffs (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    billing_period_id           INT UNSIGNED NOT NULL,
    gas_price_per_m3            DECIMAL(8,4) NOT NULL DEFAULT 3.4500,
    water_price_per_m3          DECIMAL(8,4) NOT NULL DEFAULT 1.7000,
    electricity_price_per_kwh   DECIMAL(8,4) NOT NULL DEFAULT 8.2700,
    solar_return_price_per_kwh  DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'Vergoeding teruglevering',
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id),
    UNIQUE KEY unique_period (billing_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Energietarieven per afrekenperiode';

-- Seed: tarieven 2025
INSERT INTO tariffs (billing_period_id, gas_price_per_m3, water_price_per_m3, electricity_price_per_kwh)
VALUES (1, 3.4500, 1.7000, 8.2700);

-- ============================================================
-- TABEL 5: fixed_costs_templates (standaard vaste lasten)
-- Per afrekenperiode + kaveltype.
-- Bedragen uit Excel 2025 header:
--   Bebouwd:   Vast gas €44,64/mnd, Vast water €53,40/mnd,
--              Vast Elekt €99,24/mnd, VVE €145/mnd, Erfpacht €31/mnd
--   Onbebouwd: Geen vast gas/water, VVE €696/jr of €1740/jr (per type)
-- ============================================================
CREATE TABLE IF NOT EXISTS fixed_costs_templates (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    billing_period_id           INT UNSIGNED NOT NULL,
    lot_type                    ENUM('bebouwd', 'onbebouwd') NOT NULL,
    vast_gas_per_month          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vast_water_per_month        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vast_electricity_per_month  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vve_per_year                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    erfpacht_per_year           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id),
    UNIQUE KEY unique_period_type (billing_period_id, lot_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Standaard vaste lasten per kaveltype per afrekenperiode';

-- Seed: vaste lasten 2025
INSERT INTO fixed_costs_templates
    (billing_period_id, lot_type, vast_gas_per_month, vast_water_per_month, vast_electricity_per_month, vve_per_year, erfpacht_per_year)
VALUES
    (1, 'bebouwd',    44.64, 53.40, 99.24, 1740.00, 372.00),
    (1, 'onbebouwd',   0.00,  0.00,  0.00,  696.00, 372.00);

-- ============================================================
-- TABEL 6: readings (ingevoerde meterstanden) ⭐ KERN
-- Eén rij per kavel per periode (of per verhuizing-deelperiode).
-- Gas, water en elektra worden tegelijk ingevoerd.
-- Bij verhuizing: 2 rijen voor dezelfde kavel (elk ander period_months).
-- ============================================================
CREATE TABLE IF NOT EXISTS readings (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lot_id                      INT UNSIGNED NOT NULL,
    billing_period_id           INT UNSIGNED NOT NULL,
    submitted_by                INT UNSIGNED NULL COMMENT 'Bewoner die heeft ingediend',

    -- Scenario
    scenario                    ENUM('jaarafrekening', 'verhuizing') NOT NULL DEFAULT 'jaarafrekening',
    period_months               TINYINT UNSIGNED NOT NULL DEFAULT 12 COMMENT 'Aantal maanden in deze periode',
    reading_date                DATE NOT NULL COMMENT 'Datum van aflezing',

    -- GAS (m³)
    gas_prev_reading            DECIMAL(10,3) NULL COMMENT 'Beginstand gas m³',
    gas_new_reading             DECIMAL(10,3) NULL COMMENT 'Eindstand gas m³',
    gas_consumption             DECIMAL(10,3) NULL COMMENT 'Verbruik gas m³ (berekend)',

    -- WATER (m³)
    water_prev_reading          DECIMAL(10,3) NULL COMMENT 'Beginstand water m³',
    water_new_reading           DECIMAL(10,3) NULL COMMENT 'Eindstand water m³',
    water_consumption           DECIMAL(10,3) NULL COMMENT 'Verbruik water m³ (berekend)',

    -- ELEKTRA (kWh) — standaard meter (1 waarde)
    electricity_prev_reading    DECIMAL(10,3) NULL COMMENT 'Beginstand elektra kWh',
    electricity_new_reading     DECIMAL(10,3) NULL COMMENT 'Eindstand elektra kWh',
    electricity_consumption     DECIMAL(10,3) NULL COMMENT 'Verbruik kWh (berekend)',

    -- TERUGLEVERING zonnepanelen (kWh)
    solar_return                DECIMAL(10,3) NULL COMMENT 'Teruggeleverd kWh zonnepanelen',

    -- T1/T2/T3/T4 voor slimme meters met daltarief
    t1_verbruik_dal             DECIMAL(10,3) NULL COMMENT 'Elektra verbruik dal (kWh)',
    t2_verbruik_piek            DECIMAL(10,3) NULL COMMENT 'Elektra verbruik piek (kWh)',
    t3_terug_dal                DECIMAL(10,3) NULL COMMENT 'Teruglevering dal (kWh)',
    t4_terug_piek               DECIMAL(10,3) NULL COMMENT 'Teruglevering piek (kWh)',

    -- OCR & foto verificatie
    image_url                   VARCHAR(500) NULL COMMENT 'URL foto in cloud storage',
    ocr_raw_value               TEXT NULL COMMENT 'Ruwe OCR output tekst',
    ocr_confidence              DECIMAL(5,2) NULL COMMENT 'OCR betrouwbaarheid percentage',
    is_manual_correction        TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = handmatig gecorrigeerd',

    -- EXIF fraude-preventie
    exif_timestamp              DATETIME NULL COMMENT 'Tijdstip uit EXIF fotometadata',
    exif_gps_lat                DECIMAL(10,7) NULL COMMENT 'GPS breedtegraad het EXIF',
    exif_gps_lon                DECIMAL(10,7) NULL COMMENT 'GPS lengtegraad uit EXIF',

    -- Afwijking detectie (backend berekend bij opslaan)
    is_afwijking                TINYINT(1) NOT NULL DEFAULT 0,
    afwijking_reden             VARCHAR(255) NULL,

    -- Admin review workflow
    status                      ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by                 INT UNSIGNED NULL,
    reviewed_at                 DATETIME NULL,
    admin_notes                 TEXT NULL,

    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (lot_id) REFERENCES lots(id),
    FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id),
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_lot_period        (lot_id, billing_period_id),
    INDEX idx_status            (status),
    INDEX idx_afwijking          (is_afwijking),
    INDEX idx_scenario          (scenario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ingevoerde meterstanden per kavel per (deel)periode';

-- ============================================================
-- TABEL 7: billing_results (berekende financiële afrekening)
-- Wordt gegenereerd nadat admin meterstanden goedkeurt.
-- Kolommen mappen 1:1 op de kolommen in de Excel eindafrekening.
-- ============================================================
CREATE TABLE IF NOT EXISTS billing_results (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reading_id                  INT UNSIGNED NOT NULL UNIQUE,
    lot_id                      INT UNSIGNED NOT NULL,
    billing_period_id           INT UNSIGNED NOT NULL,

    -- Verbruikskosten (tarief × verbruik)
    gas_cost                    DECIMAL(10,2) NULL COMMENT 'Gas m³ kosten (€)',
    water_cost                  DECIMAL(10,2) NULL COMMENT 'Water m³ kosten (€)',
    electricity_cost            DECIMAL(10,2) NULL COMMENT 'Elektra kWh kosten (€)',
    solar_credit                DECIMAL(10,2) NULL COMMENT 'Terugleveringsvergoeding zonnepanelen (€)',

    -- Vaste lasten (vaste kosten × period_months)
    vast_gas_total              DECIMAL(10,2) NULL COMMENT 'Vast gas totaal (€)',
    vast_water_total            DECIMAL(10,2) NULL COMMENT 'Vast water totaal (€)',
    vast_electricity_total      DECIMAL(10,2) NULL COMMENT 'Vast elektra totaal (€)',
    vve_total                   DECIMAL(10,2) NULL COMMENT 'VVE bijdrage totaal (€)',
    erfpacht_total              DECIMAL(10,2) NULL COMMENT 'Erfpacht totaal (€)',

    -- Financieel eindresultaat (= kolommen Excel)
    subtotal                    DECIMAL(10,2) NULL COMMENT '"Totaal" uit Excel (€)',
    previously_invoiced         DECIMAL(10,2) NULL COMMENT '"Gefactureerd" uit Excel (€)',
    settlement_amount           DECIMAL(10,2) NULL COMMENT '"Afrek." — positief = terug aan bewoner (€)',
    monthly_amount              DECIMAL(10,2) NULL COMMENT 'Maandbedrag komend jaar (€)',

    calculated_at               DATETIME NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (reading_id) REFERENCES readings(id),
    FOREIGN KEY (lot_id) REFERENCES lots(id),
    FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id),
    INDEX idx_lot_period (lot_id, billing_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Berekende financiële afrekening per kavel per periode';

-- ============================================================
-- TABEL 8: import_history (historische basisdata)
-- Eenmalige import van de Excel 2024/2025 eindstanden als nulpunt.
-- Vormt de baseline voor afwijking-detectie.
-- ============================================================
CREATE TABLE IF NOT EXISTS import_history (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lot_id                      INT UNSIGNED NOT NULL,
    billing_period_id           INT UNSIGNED NOT NULL,
    source_file                 VARCHAR(255) NULL COMMENT 'Bronbestand (bijv. eindafrekening_2025.xlsx)',
    imported_by                 INT UNSIGNED NULL,

    -- Geïmporteerde begin- en eindstanden (directe mapping uit Excel)
    gas_prev_reading            DECIMAL(10,3) NULL COMMENT 'GAS beginstand (Eind vorig jaar)',
    gas_new_reading             DECIMAL(10,3) NULL COMMENT 'GAS eindstand (Eind dit jaar)',
    water_prev_reading          DECIMAL(10,3) NULL,
    water_new_reading           DECIMAL(10,3) NULL,
    electricity_prev_reading    DECIMAL(10,3) NULL,
    electricity_new_reading     DECIMAL(10,3) NULL,

    imported_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (lot_id) REFERENCES lots(id),
    FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id),
    FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_lot_period (lot_id, billing_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historische basisdata geïmporteerd uit Excel eindafrekeningen';

SET FOREIGN_KEY_CHECKS = 1;
