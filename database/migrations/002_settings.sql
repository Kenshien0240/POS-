-- ---------------------------------------------------------------------------
-- Group 6 Retail POS - Settings table
-- Adds configurable store information, tax and discount behaviour.
-- Safe to run on an existing database (does not touch other tables).
-- ---------------------------------------------------------------------------

USE `retail_pos`;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   varchar(64)  NOT NULL,
  `setting_value` text         DEFAULT NULL,
  `updated_at`    timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
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
  ('receipt_footer',           'Thank you for shopping with us!')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
