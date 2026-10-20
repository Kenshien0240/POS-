-- ---------------------------------------------------------------------------
-- Group 6 Retail POS - Part 11 schema alignment (migration for existing DBs)
--
-- Renames the application's original columns to the canonical names used by
-- database/schema.sql and adds the payment/inventory audit fields.
--
-- Run ONCE, after the original database/migrations/001..005 files, on an existing install:
--   mysql -u root < database/migrations/006_part11_schema_rename.sql
--
-- New installations should import database/schema.sql + database/seed.sql
-- instead and do NOT need this file.
-- ---------------------------------------------------------------------------

USE `retail_pos`;

SET NAMES utf8mb4;

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- users: full_name -> name, is_active -> status, add optional email ---------
ALTER TABLE `users`
  CHANGE COLUMN `full_name` `name` VARCHAR(100) NOT NULL,
  CHANGE COLUMN `is_active` `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = inactive',
  ADD COLUMN `email` VARCHAR(150) DEFAULT NULL AFTER `username`,
  ADD UNIQUE KEY `uq_users_email` (`email`);

-- categories: is_active -> status ------------------------------------------
ALTER TABLE `categories`
  CHANGE COLUMN `is_active` `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = inactive';

-- products: product_code -> sku, is_active -> status ------------------------
ALTER TABLE `products`
  CHANGE COLUMN `product_code` `sku` VARCHAR(50) NOT NULL,
  CHANGE COLUMN `is_active` `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = inactive';

-- sales: grand_total -> total_amount ----------------------------------------
ALTER TABLE `sales`
  CHANGE COLUMN `grand_total` `total_amount` DECIMAL(12,2) NOT NULL;

-- sale_items: snapshot column names, allow product deletion while keeping
-- the receipt intact (ON DELETE SET NULL) ----------------------------------
ALTER TABLE `sale_items`
  CHANGE COLUMN `product_code` `sku_snapshot` VARCHAR(50) NOT NULL,
  CHANGE COLUMN `product_name` `product_name_snapshot` VARCHAR(150) NOT NULL,
  MODIFY COLUMN `product_id` BIGINT UNSIGNED DEFAULT NULL;

ALTER TABLE `sale_items` DROP FOREIGN KEY `fk_sale_items_product`;
ALTER TABLE `sale_items`
  ADD CONSTRAINT `fk_sale_items_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- payments: method -> payment_method, amount -> amount_paid, add status -----
ALTER TABLE `payments`
  CHANGE COLUMN `method` `payment_method` ENUM('cash','card','e_wallet') NOT NULL,
  CHANGE COLUMN `amount` `amount_paid` DECIMAL(12,2) NOT NULL,
  ADD COLUMN `payment_status` ENUM('paid','refunded','failed') NOT NULL DEFAULT 'paid' AFTER `change_amount`;

UPDATE `payments` p
  JOIN `sales` s ON s.id = p.sale_id
  SET p.payment_status = 'refunded'
  WHERE s.status = 'voided';

-- inventory_movements: user_id / notes / reference_type + reference_id ------
ALTER TABLE `inventory_movements`
  CHANGE COLUMN `created_by` `user_id` BIGINT UNSIGNED DEFAULT NULL,
  CHANGE COLUMN `note` `notes` VARCHAR(255) DEFAULT NULL,
  CHANGE COLUMN `reference` `reference_id` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `reference_type` ENUM('opening','restock','sale','adjustment') DEFAULT NULL AFTER `stock_after`;

UPDATE `inventory_movements`
  SET `reference_type` = CASE
    WHEN `movement_type` = 'initial'  THEN 'opening'
    WHEN `movement_type` = 'sale'     THEN 'sale'
    WHEN `movement_type` = 'restock'  THEN 'restock'
    WHEN `sale_id` IS NOT NULL        THEN 'sale'
    ELSE 'adjustment'
  END
  WHERE `reference_type` IS NULL;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
