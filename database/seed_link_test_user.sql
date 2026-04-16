-- ============================================================
-- Seed: Koppel eerste 5 kavels aan de test gebruiker
-- ============================================================

-- Zorg dat test@example.nl bestaat
INSERT IGNORE INTO users (email, name, role) 
VALUES ('test@example.nl', 'Test Bewoner', 'resident');

-- Haal het ID op van de test gebruiker
SET @user_id = (SELECT id FROM users WHERE email = 'test@example.nl');

-- Koppel kavels
UPDATE lots SET user_id = @user_id WHERE lot_number IN (1, 2, 3, 4, 5);
