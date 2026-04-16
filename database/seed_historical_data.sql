-- ============================================================
-- Seed: Historische data voor de eerste 5 kavels (uit Excel 2025)
-- Voor demo doeleinden
-- ============================================================

-- Periode 2024 (als import_history)
INSERT INTO import_history (lot_id, billing_period_id, gas_prev_reading, gas_new_reading, water_prev_reading, water_new_reading, electricity_prev_reading, electricity_new_reading)
VALUES
(1, 1, 5981, 6101, 915, 919, 34045, 34766), -- Kavel 1
(2, 1, 4086, 4186, 519, 522, 35410, 35797), -- Kavel 2
(3, 1, 13293, 13869, 4748, 4952, 52885, 56858), -- Kavel 3
(4, 1, 4117, 4313, 491, 516, 5300, 6420), -- Kavel 4
(5, 1, 7433, 7879, 1840, 1956, 52852, 59765); -- Kavel 5

-- Periode 2025 (als readings - reeds ingediend voor demo)
INSERT INTO readings (lot_id, billing_period_id, scenario, status, gas_prev_reading, gas_new_reading, gas_consumption, water_prev_reading, water_new_reading, water_consumption, electricity_prev_reading, electricity_new_reading, electricity_consumption, reading_date)
VALUES
(1, 1, 'jaarafrekening', 'approved', 6101, 6120, 19, 919, 919, 0, 34766, 34849, 83, '2025-12-31'),
(2, 1, 'jaarafrekening', 'pending',  4186, 4309, 123, 522, 522, 0, 35797, 36050, 253, '2025-12-31');
