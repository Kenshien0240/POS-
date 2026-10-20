<?php
/**
 * Group 6 - Retail Point-of-Sale System
 * Application configuration.
 *
 * Database credentials and environment-specific switches belong in
 * config/local.php (ignored by git), not in this file. Copy
 * config/local.example.php to config/local.php and edit it for your machine.
 *
 * Environment variables can also be used, e.g. DB_PASS=secret php -S ...
 * Set APP_CONFIG to an absolute path to load a different config file (used by
 * the isolated integration tests).
 */

$localConfig = getenv('APP_CONFIG') ?: __DIR__ . '/local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

// ---------------------------------------------------------------------------
// Application behaviour
// ---------------------------------------------------------------------------
// Detailed errors are for local development only. Production must keep this
// false so stack traces / SQL errors are never shown to users (they are still
// written to the PHP error log).
defined('APP_DEBUG') || define(
    'APP_DEBUG',
    filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN)
);

// Session name (cookie) used by the application.
defined('SESSION_NAME') || define('SESSION_NAME', 'retailpos_session');

// Timezone used for reports and receipts.
date_default_timezone_set('Asia/Manila');
