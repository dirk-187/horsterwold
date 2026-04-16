-- Migration: Create system_settings table
-- Date: 2026-04-16

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES 
('park_name', 'Buitenplaats Horsterwold'),
('iban', 'NL00BANK0000000000'),
('creditor_id', 'NL99ZZZ000000000000'),
('contact_email', 'beheer@horsterwold.nl'),
('max_upload_time', '15'),
('max_consumption_dev', '25');
