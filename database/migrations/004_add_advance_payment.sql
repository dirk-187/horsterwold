-- Migration: Add monthly advance payment to lots
-- Date: 2026-04-25

-- Add monthly_advance to lots table
ALTER TABLE lots ADD COLUMN monthly_advance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER has_solar;

-- Add comment to clarify
ALTER TABLE lots MODIFY COLUMN monthly_advance DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Maandelijks voorschotbedrag';
