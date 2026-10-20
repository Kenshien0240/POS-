-- ---------------------------------------------------------------------------
-- Group 6 Retail POS - Inventory hardening (Part 9)
-- Adds a human-readable reference to stock movements and a database-level
-- guard that prevents a sale from deducting the same product twice.
--
-- Safe to run on an existing database; run once.
-- ---------------------------------------------------------------------------

USE `retail_pos`;

SET NAMES utf8mb4;

-- 1. Reference (receipt no., supplier DR, adjustment ticket, ...) ------------
SET @has_ref := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_movements'
      AND COLUMN_NAME = 'reference'
);
SET @sql := IF(@has_ref = 0,
    'ALTER TABLE inventory_movements ADD COLUMN `reference` varchar(100) DEFAULT NULL AFTER `sale_id`',
    'SELECT ''reference column already present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. One movement per (sale, product, type) ----------------------------------
--    Sale_id is NULL for manual movements, and MySQL allows many NULLs in a
--    UNIQUE index, so manual restocks/adjustments are unaffected. A retried
--    checkout can therefore never insert a second "sale" deduction row.
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_movements'
      AND INDEX_NAME = 'uq_inventory_sale_product_type'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE inventory_movements ADD UNIQUE KEY `uq_inventory_sale_product_type` (`sale_id`,`product_id`,`movement_type`)',
    'SELECT ''unique index already present'' AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
