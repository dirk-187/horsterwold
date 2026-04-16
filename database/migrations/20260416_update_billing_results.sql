-- Migration: Update billing_results table for status tracking
-- Date: 2026-04-16

-- Add payment_status if it doesn't exist (safety check)
SET @dbname = DATABASE();
SET @tablename = "billing_results";
SET @columnname = "payment_status";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname
     AND TABLE_NAME = @tablename
     AND COLUMN_NAME = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE billing_results ADD COLUMN payment_status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending' AFTER subtotal"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add sent_at column
SET @columnname = "sent_at";
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = @dbname
     AND TABLE_NAME = @tablename
     AND COLUMN_NAME = @columnname) > 0,
  "SELECT 1",
  "ALTER TABLE billing_results ADD COLUMN sent_at DATETIME NULL AFTER payment_status"
));
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
