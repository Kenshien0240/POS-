<?php
/**
 * Copy this file to config/local.php and fill in your own values.
 * config/local.php is ignored by git and must never be committed.
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'retail_pos');
define('DB_USER', 'root');
define('DB_PASS', '');          // set a strong password in production
define('DB_CHARSET', 'utf8mb4');

// Keep false in production. Set true only on a local development machine.
define('APP_DEBUG', false);
