<?php
/**
 * Integration-test configuration.
 *
 * This file is only loaded when APP_CONFIG points to it, so the normal
 * development/production database in config/local.php is never touched.
 * Connection values can be overridden with TEST_DB_* environment variables.
 */

$testHost = getenv('TEST_DB_HOST');
$testPort = getenv('TEST_DB_PORT');
$testName = getenv('TEST_DB_NAME');
$testUser = getenv('TEST_DB_USER');
$testPass = getenv('TEST_DB_PASS');

define('DB_HOST', $testHost !== false && $testHost !== '' ? $testHost : '127.0.0.1');
define('DB_PORT', $testPort !== false && $testPort !== '' ? $testPort : '3306');
define('DB_NAME', $testName !== false && $testName !== '' ? $testName : 'retail_pos_test');
define('DB_USER', $testUser !== false && $testUser !== '' ? $testUser : 'root');
define('DB_PASS', $testPass !== false ? $testPass : '');
define('DB_CHARSET', 'utf8mb4');

define('APP_DEBUG', true);
