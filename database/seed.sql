-- ===========================================================================
-- Group 6 - Retail Point-of-Sale System
-- Development seed data (sample categories, products, accounts, settings)
--
-- Import AFTER database/schema.sql:
--   mysql -u root < database/seed.sql
--
-- Everything here is fictional demo data. No real people, addresses or
-- production credentials are included.
--
-- DEMO ACCOUNTS (development only - change these before any real deployment)
--   Administrator : username `admin`   / password `Admin@12345`
--   Cashier       : username `cashier` / password `Cashier@123`
--
-- Passwords are stored as bcrypt hashes produced by PHP password_hash().
-- Re-running this file is safe: INSERT IGNORE leaves existing rows untouched.
-- ===========================================================================

USE `retail_pos`;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Settings / store profile
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('store_name',               'Group 6 Retail Store'),
  ('store_address',            '123 Rizal Avenue, Manila, Philippines'),
  ('store_phone',              '(02) 8123-4567'),
  ('store_tax_id',             ''),
  ('currency_code',            'PHP'),
  ('currency_symbol',          '₱'),
  ('tax_enabled',              '0'),
  ('tax_rate',                 '0'),
  ('tax_label',                'VAT'),
  ('discount_enabled',         '0'),
  ('default_discount_percent', '0'),
  ('max_discount_percent',     '100'),
  ('low_stock_default',        '5'),
  ('receipt_footer',           'Thank you for shopping with us!');

-- ---------------------------------------------------------------------------
-- Development accounts
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `users`
  (`name`, `username`, `email`, `password_hash`, `role`, `status`) VALUES
  ('Alex Admin', 'admin', NULL,
   '$2y$10$CM0Bu5Tx5o35hMdkYOlwueHoGYEY8Be4By8URjg0.zYI80IzvI.Nu', 'admin', 1),
  ('Casey Cashier', 'cashier', NULL,
   '$2y$10$jRscwOCu93X/y0uHUEba/OXu.TJj07TM4EKUWnK5kBJnRa0iTLFsm', 'cashier', 1);

-- ---------------------------------------------------------------------------
-- Categories
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `categories` (`id`, `name`, `description`, `status`) VALUES
  (1, 'Beverages',     'Bottled drinks, juices, and water', 1),
  (2, 'Snacks',        'Chips, biscuits, and quick bites',  1),
  (3, 'Household',     'Everyday home essentials',          1),
  (4, 'Personal Care', 'Toiletries and hygiene products',   1);

-- ---------------------------------------------------------------------------
-- Sample products (SKUs are fictional)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `products`
  (`category_id`, `sku`, `name`, `description`, `price`, `stock_quantity`, `low_stock_threshold`, `status`) VALUES
  (1, 'BEV-001', 'Bottled Water 500ml',      'Sample product for demonstrations.', 12.00, 40, 10, 1),
  (1, 'BEV-002', 'Orange Juice 1L',          'Sample product for demonstrations.', 89.50, 18,  5, 1),
  (2, 'SNK-001', 'Potato Chips Original',    'Sample product for demonstrations.', 45.00, 30,  8, 1),
  (2, 'SNK-002', 'Chocolate Biscuit Pack',   'Sample product for demonstrations.', 28.75,  6, 10, 1),
  (3, 'HOU-001', 'Dishwashing Liquid 500ml', 'Sample product for demonstrations.', 99.00, 14,  5, 1),
  (3, 'HOU-002', 'Paper Towels 2-pack',      'Sample product for demonstrations.', 75.50,  9,  5, 1),
  (4, 'PER-001', 'Bath Soap 90g',            'Sample product for demonstrations.', 39.00, 24,  6, 1),
  (4, 'PER-002', 'Toothpaste 120g',          'Sample product for demonstrations.', 65.00,  4,  6, 1);

-- ---------------------------------------------------------------------------
-- Opening stock ledger entries (keeps stock and movements reconcilable)
-- ---------------------------------------------------------------------------
INSERT INTO `inventory_movements`
  (`product_id`, `user_id`, `sale_id`, `movement_type`, `quantity_change`, `stock_before`, `stock_after`, `reference_type`, `reference_id`, `notes`)
SELECT p.id, NULL, NULL, 'initial', p.stock_quantity, 0, p.stock_quantity, 'opening', 'OPENING', 'Opening stock'
FROM `products` p
WHERE NOT EXISTS (
  SELECT 1 FROM `inventory_movements` m
  WHERE m.product_id = p.id AND m.movement_type = 'initial'
);
