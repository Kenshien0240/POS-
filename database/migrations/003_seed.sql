-- ===========================================================================
-- LEGACY FILE - for existing/older databases only.
-- For a fresh install use database/schema.sql + database/seed.sql instead.
-- ===========================================================================
-- Group 6 - Retail Point-of-Sale System
-- Demo seed data. Run after 001_schema.sql and 002_settings.sql.
--
-- Demo accounts:
--   Administrator : admin   / Admin@12345
--   Cashier       : cashier / Cashier@123
-- ===========================================================================

USE `retail_pos`;

SET NAMES utf8mb4;

-- Categories ---------------------------------------------------------------
INSERT INTO `categories` (`id`, `name`, `description`, `is_active`) VALUES
  (1, 'Beverages',     'Bottled drinks, juices, and water',   1),
  (2, 'Snacks',        'Chips, biscuits, and quick bites',    1),
  (3, 'Household',     'Everyday home essentials',            1),
  (4, 'Personal Care', 'Toiletries and hygiene products',     1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Users (bcrypt password hashes) -------------------------------------------
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `is_active`) VALUES
  (1, 'cashier', '$2y$10$Us5xl.KAO9Ub4tHnjEYpjuST9TbcSUul3ruzb7c0IZ0DQdtNy.r9K', 'Demo Cashier',        'cashier', 1),
  (2, 'admin',   '$2y$10$LXIqBoyn/0rzc3fGk.Y0GuHT.RJnUR1j1HyejdOOHdmj4ainn/lqS', 'Store Administrator', 'admin',   1)
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

-- Products -----------------------------------------------------------------
INSERT INTO `products`
  (`id`, `category_id`, `product_code`, `name`, `description`, `price`, `stock_quantity`, `low_stock_threshold`, `is_active`) VALUES
  (1, 1, 'BEV-001', 'Bottled Water 500ml',         'Purified drinking water',        12.00, 48, 10, 1),
  (2, 1, 'BEV-002', 'Orange Juice 1L',             '100% orange juice',              89.50, 18,  5, 1),
  (3, 2, 'SNK-001', 'Potato Chips Original',       'Salted classic potato chips',    45.00, 30,  8, 1),
  (4, 2, 'SNK-002', 'Chocolate Biscuit Pack',      'Chocolate cream biscuits',       28.75,  6, 10, 1),
  (5, 3, 'HOU-001', 'Dishwashing Liquid 500ml',    'Lemon scent dishwashing liquid', 99.00, 14,  5, 1),
  (6, 3, 'HOU-002', 'Paper Towels 2-pack',         'Absorbent kitchen paper towels', 75.50,  9,  5, 1),
  (7, 4, 'PER-001', 'Bath Soap 90g',               'Moisturising bath soap',         39.00, 24,  6, 1),
  (8, 4, 'PER-002', 'Toothpaste 120g',             'Fluoride cavity protection',     65.00,  4,  6, 1)
ON DUPLICATE KEY UPDATE `product_code` = VALUES(`product_code`);

-- Opening stock movements ---------------------------------------------------
INSERT INTO `inventory_movements`
  (`product_id`, `movement_type`, `quantity_change`, `stock_before`, `stock_after`, `note`, `created_by`) VALUES
  (1, 'initial', 48, 0, 48, 'Opening stock from demo seed', 2),
  (2, 'initial', 18, 0, 18, 'Opening stock from demo seed', 2),
  (3, 'initial', 30, 0, 30, 'Opening stock from demo seed', 2),
  (4, 'initial',  6, 0,  6, 'Opening stock from demo seed', 2),
  (5, 'initial', 14, 0, 14, 'Opening stock from demo seed', 2),
  (6, 'initial',  9, 0,  9, 'Opening stock from demo seed', 2),
  (7, 'initial', 24, 0, 24, 'Opening stock from demo seed', 2),
  (8, 'initial',  4, 0,  4, 'Opening stock from demo seed', 2);
