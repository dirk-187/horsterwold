-- Migration: Add resident_name and resident_email to lots table
-- Date: 2026-04-16

ALTER TABLE lots ADD COLUMN resident_name VARCHAR(255) NULL AFTER user_id;
ALTER TABLE lots ADD COLUMN resident_email VARCHAR(255) NULL AFTER resident_name;
