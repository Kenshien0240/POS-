<?php
/**
 * Database configuration and PDO connection (singleton).
 *
 * Real credentials live in config/local.php (ignored by git) or in
 * DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS environment variables.
 */

require_once __DIR__ . '/app.php';

defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', getenv('DB_PORT') ?: '3306');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'retail_pos');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Log the real cause, but never leak credentials to the browser.
        error_log('Database connection failed: ' . $e->getMessage());

        http_response_code(500);
        if (APP_DEBUG) {
            exit('Database connection failed: ' . $e->getMessage());
        }
        exit('Database connection failed. Please check your configuration.');
    }

    return $pdo;
}
