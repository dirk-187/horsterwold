-- 1. Create lot_occupancy table
CREATE TABLE IF NOT EXISTS lot_occupancy (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lot_id INT UNSIGNED NOT NULL,
    resident_name VARCHAR(255) DEFAULT NULL,
    resident_email VARCHAR(255) DEFAULT NULL,
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    start_gas DECIMAL(10,3) DEFAULT 0,
    start_water DECIMAL(10,3) DEFAULT 0,
    start_elec DECIMAL(10,3) DEFAULT 0,
    end_gas DECIMAL(10,3) DEFAULT NULL,
    end_water DECIMAL(10,3) DEFAULT NULL,
    end_elec DECIMAL(10,3) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lot_active (lot_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Add occupancy_id to readings for explicit linking
ALTER TABLE readings ADD COLUMN occupancy_id INT UNSIGNED DEFAULT NULL AFTER lot_id;
ALTER TABLE readings ADD INDEX idx_occupancy (occupancy_id);

-- 3. Data Migration: Move current resident data to the new table
INSERT INTO lot_occupancy (lot_id, resident_name, resident_email, start_date, is_active)
SELECT id, resident_name, resident_email, COALESCE(resident_since_date, '2026-01-01'), COALESCE(is_resident_active, 1)
FROM lots
WHERE resident_email IS NOT NULL AND resident_email != '';

-- 4. Update existing readings with the new occupancy_id (where possible)
-- (Simplification: link to the newly created occupancy records for active residents)
UPDATE readings r
JOIN lot_occupancy lo ON r.lot_id = lo.lot_id AND lo.is_active = 1
SET r.occupancy_id = lo.id;
