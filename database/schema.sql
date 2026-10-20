-- ===========================================================================
-- Group 6 - Retail Point-of-Sale System
-- Canonical database schema (MySQL 8 / MariaDB 10.4+, InnoDB)
--
-- Import with phpMyAdmin (see README.md) or:
--   mysql -u root < database/schema.sql
--   mysql -u root < database/seed.sql
--
-- Design notes
--  * Every table uses InnoDB so transactions, row locks and foreign keys work.
--  * Money is stored as DECIMAL (never FLOAT) to avoid rounding errors.
--  * sale_items keeps immutable snapshots (product_name_snapshot, sku_snapshot,
--    unit_price) so historic receipts stay accurate after a product is renamed,
--    re-priced or deactivated.
--  * products are never hard-deleted by the application; they are deactivated
--    (status = 0). FKs still protect transactional history.
--  * status columns use TINYINT(1): 1 = active, 0 = inactive.
-- ===========================================================================

CREATE DATABASE IF NOT EXISTS `retail_pos`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `retail_pos`;

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE `users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100)    NOT NULL,
  `username`      VARCHAR(50)     NOT NULL,
  `email`         VARCHAR(150)    DEFAULT NULL,
  `password_hash` VARCHAR(255)    NOT NULL COMMENT 'password_hash() / bcrypt output',
  `role`          ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  `status`        TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = inactive',
  `last_login_at` DATETIME        DEFAULT NULL,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_status` (`role`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- categories
-- ---------------------------------------------------------------------------
CREATE TABLE `categories` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100)  NOT NULL,
  `description` VARCHAR(255)  DEFAULT NULL,
  `status`      TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = inactive',
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- products
-- ---------------------------------------------------------------------------
CREATE TABLE `products` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`         INT UNSIGNED    DEFAULT NULL,
  `sku`                 VARCHAR(50)     NOT NULL,
  `name`                VARCHAR(150)    NOT NULL,
  `description`         VARCHAR(500)    DEFAULT NULL,
  `price`               DECIMAL(10,2)   NOT NULL,
  `stock_quantity`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `low_stock_threshold` INT UNSIGNED    NOT NULL DEFAULT 5,
  `status`              TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = inactive',
  `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  KEY `idx_products_name` (`name`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_status_stock` (`status`, `stock_quantity`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_products_price` CHECK (`price` >= 0),
  CONSTRAINT `chk_products_stock` CHECK (`stock_quantity` >= 0),
  CONSTRAINT `chk_products_threshold` CHECK (`low_stock_threshold` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- sales
-- ---------------------------------------------------------------------------
CREATE TABLE `sales` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_number`   VARCHAR(32)     NOT NULL,
  `idempotency_key`  VARCHAR(64)     DEFAULT NULL COMMENT 'prevents duplicate submission',
  `cashier_id`       BIGINT UNSIGNED NOT NULL,
  `subtotal`         DECIMAL(12,2)   NOT NULL,
  `discount_amount`  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `tax_amount`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `total_amount`     DECIMAL(12,2)   NOT NULL,
  `status`           ENUM('completed','voided') NOT NULL DEFAULT 'completed',
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_receipt` (`receipt_number`),
  UNIQUE KEY `uq_sales_idempotency` (`idempotency_key`),
  KEY `idx_sales_created` (`created_at`),
  KEY `idx_sales_cashier_created` (`cashier_id`, `created_at`),
  KEY `idx_sales_status_created` (`status`, `created_at`),
  CONSTRAINT `fk_sales_cashier` FOREIGN KEY (`cashier_id`)
    REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_sales_subtotal` CHECK (`subtotal` >= 0),
  CONSTRAINT `chk_sales_discount` CHECK (`discount_amount` >= 0),
  CONSTRAINT `chk_sales_tax` CHECK (`tax_amount` >= 0),
  CONSTRAINT `chk_sales_total` CHECK (`total_amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- sale_items (immutable snapshot of the product at the time of sale)
-- ---------------------------------------------------------------------------
CREATE TABLE `sale_items` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`              BIGINT UNSIGNED NOT NULL,
  `product_id`           BIGINT UNSIGNED DEFAULT NULL COMMENT 'may be NULL if product later removed',
  `product_name_snapshot` VARCHAR(150)   NOT NULL,
  `sku_snapshot`          VARCHAR(50)    NOT NULL,
  `quantity`             INT UNSIGNED    NOT NULL,
  `unit_price`           DECIMAL(10,2)   NOT NULL,
  `line_total`           DECIMAL(12,2)   NOT NULL,
  `created_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale_items_sale` (`sale_id`),
  KEY `idx_sale_items_product` (`product_id`),
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`)
    REFERENCES `sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_items_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_sale_items_qty` CHECK (`quantity` > 0),
  CONSTRAINT `chk_sale_items_unit_price` CHECK (`unit_price` >= 0),
  CONSTRAINT `chk_sale_items_line_total` CHECK (`line_total` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payments (one payment record per sale)
-- ---------------------------------------------------------------------------
CREATE TABLE `payments` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`         BIGINT UNSIGNED NOT NULL,
  `payment_method`  ENUM('cash','card','e_wallet') NOT NULL,
  `amount_paid`     DECIMAL(12,2)   NOT NULL COMMENT 'amount applied to the sale',
  `tendered_amount` DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'cash handed over',
  `change_amount`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `payment_status`  ENUM('paid','refunded','failed') NOT NULL DEFAULT 'paid',
  `reference_no`    VARCHAR(100)    DEFAULT NULL COMMENT 'card / e-wallet reference',
  `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_sale` (`sale_id`),
  KEY `idx_payments_method` (`payment_method`),
  KEY `idx_payments_status` (`payment_status`),
  CONSTRAINT `fk_payments_sale` FOREIGN KEY (`sale_id`)
    REFERENCES `sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_payments_amount` CHECK (`amount_paid` >= 0),
  CONSTRAINT `chk_payments_tendered` CHECK (`tendered_amount` >= 0),
  CONSTRAINT `chk_payments_change` CHECK (`change_amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- inventory_movements (append-only stock ledger)
-- ---------------------------------------------------------------------------
CREATE TABLE `inventory_movements` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`     BIGINT UNSIGNED NOT NULL,
  `user_id`        BIGINT UNSIGNED DEFAULT NULL,
  `sale_id`        BIGINT UNSIGNED DEFAULT NULL,
  `movement_type`  ENUM('initial','restock','sale','adjustment') NOT NULL,
  `quantity_change` INT            NOT NULL COMMENT 'signed: negative for deductions',
  `stock_before`   INT UNSIGNED    NOT NULL,
  `stock_after`    INT UNSIGNED    NOT NULL,
  `reference_type` ENUM('opening','restock','sale','adjustment') DEFAULT NULL,
  `reference_id`   VARCHAR(100)    DEFAULT NULL COMMENT 'receipt no., supplier DR, ticket',
  `notes`          VARCHAR(255)    DEFAULT NULL,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_sale_product_type` (`sale_id`, `product_id`, `movement_type`),
  KEY `idx_inventory_product_created` (`product_id`, `created_at`),
  KEY `idx_inventory_sale` (`sale_id`),
  KEY `idx_inventory_type` (`movement_type`),
  KEY `idx_inventory_user` (`user_id`),
  CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_sale` FOREIGN KEY (`sale_id`)
    REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_inventory_change` CHECK (`quantity_change` <> 0),
  CONSTRAINT `chk_inventory_after` CHECK (`stock_after` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- settings (key/value store for store info, tax, discount, currency)
-- ---------------------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key`   VARCHAR(64) NOT NULL,
  `setting_value` TEXT        DEFAULT NULL,
  `updated_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
