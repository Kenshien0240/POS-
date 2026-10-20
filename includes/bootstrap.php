<?php
/**
 * Application bootstrap: configuration, session, database and helpers.
 * Every public page starts by requiring this file.
 */

require_once __DIR__ . '/../config/app.php';

$__https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Reject attacker-supplied session IDs and keep the cookie HttpOnly.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $__https ? '1' : '0');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $__https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// Error reporting (details only on a development machine)
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------------
// Baseline security headers
// ---------------------------------------------------------------------------
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if ($__https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---------------------------------------------------------------------------
// Compute the application base URL path so the app works from any subfolder
// (e.g. http://localhost/RetailPOS/) as well as the web root.
// ---------------------------------------------------------------------------
if (!defined('APP_BASE')) {
    $docRoot  = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    $appRoot  = str_replace('\\', '/', realpath(dirname(__DIR__)));
    $base     = '';

    if ($docRoot !== '' && $appRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $base = substr($appRoot, strlen($docRoot));
    } else {
        // Derive the base URL from the current script's path relative to the
        // application root. This works for both /admin/ and /cashier/ pages.
        $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : '';
        $scriptFile = $scriptFile ? str_replace('\\', '/', $scriptFile) : '';
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');

        if ($scriptFile !== '' && strpos($scriptFile, $appRoot) === 0) {
            $relative = substr($scriptFile, strlen($appRoot));
            if ($relative !== '' && substr($scriptName, -strlen($relative)) === $relative) {
                $base = substr($scriptName, 0, -strlen($relative));
            }
        }
    }

    define('APP_BASE', rtrim($base, '/'));
}

// ---------------------------------------------------------------------------
// Core includes
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/flash.php';

// ---------------------------------------------------------------------------
// Last-resort exception handler: log the details, show a safe message.
// ---------------------------------------------------------------------------
set_exception_handler(static function (Throwable $e): void {
    log_exception($e, 'Unhandled exception');

    if (!headers_sent()) {
        http_response_code(500);
        if (is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }

    if (APP_DEBUG) {
        echo '<pre style="white-space:pre-wrap;padding:1rem">' . e((string) $e) . '</pre>';
    } elseif (is_api_request()) {
        echo json_encode(['ok' => false, 'message' => 'An unexpected error occurred. Please try again.']);
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
            . '<p style="font-family:system-ui;padding:2rem">An unexpected error occurred. '
            . 'Please try again or contact an administrator.</p>';
    }
    exit;
});
