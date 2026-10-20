-- ===========================================================================
-- Group 6 - Retail Point-of-Sale System
-- Migration: checkout idempotency (prevent duplicate sale submissions).
--
-- Adds a nullable unique `idempotency_key` to `sales`. When the POS retries a
-- checkout (double-click, network retry, page reload), the server recognises
-- the same key and returns the original sale instead of creating a duplicate.
--
-- Run this only if your database was created before this migration, or if
-- `database/migrations/001_schema.sql` does not already include the column.
-- ===========================================================================

USE `retail_pos`;

SET NAMES utf8mb4;

-- MariaDB supports IF NOT EXISTS for ADD COLUMN. If your MySQL version does
-- not, remove this column line and run it once manually.
ALTER TABLE `sales`
  ADD COLUMN IF NOT EXISTS `idempotency_key` varchar(64) DEFAULT NULL AFTER `receipt_number`;

-- Create the unique index only if it does not exist.
SET @exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'sales'
    AND index_name = 'uq_sales_idempotency'
);
SET @sql := IF(@exists = 0,
  'ALTER TABLE `sales` ADD UNIQUE KEY `uq_sales_idempotency` (`idempotency_key`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
