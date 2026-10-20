<?php
/**
 * CSRF protection helpers.
 *
 * Loaded after functions.php, which provides e(), json_response() and
 * is_api_request().
 */

/** Get (creating if needed) the CSRF token for the current session. */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Hidden input field carrying the CSRF token. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Validate a submitted CSRF token against the session. */
function csrf_valid(?string $token): bool
{
    return !empty($_SESSION['_csrf'])
        && is_string($token)
        && hash_equals($_SESSION['_csrf'], $token);
}

/** Require a valid CSRF token on POST requests, aborting otherwise. */
function verify_csrf_or_fail(?string $token = null): void
{
    if ($token === null) {
        $candidate = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $token = is_string($candidate) ? $candidate : null;
    }
    if (!csrf_valid($token)) {
        http_response_code(419);
        if (is_api_request()) {
            json_response(['ok' => false, 'message' => 'Your session expired. Please refresh the page.'], 419);
        }
        exit('Invalid or expired CSRF token. Please go back and try again.');
    }
}
