<?php
/**
 * Shared helper functions used across the application.
 */

// ---------------------------------------------------------------------------
// Output escaping
// ---------------------------------------------------------------------------

/** Escape a value for safe output inside HTML. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Make a value safe for a spreadsheet export (prevents CSV formula injection). */
function csv_safe($value): string
{
    $value = (string) ($value ?? '');
    return $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

/** Write exception details to the server log without exposing them to users. */
function log_exception(Throwable $e, string $context = 'Error'): void
{
    error_log(sprintf(
        '[%s] %s: %s in %s:%d',
        date('c'),
        $context,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

// ---------------------------------------------------------------------------
// URLs and paths
// ---------------------------------------------------------------------------

/** Absolute URL path for a file inside the application root. */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return APP_BASE . ($path === '' ? '' : '/' . $path);
}

/** URL for a file inside /assets. */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/** True when a URL contains characters that could split headers or hide a separator. */
function has_unsafe_url_chars(string $path): bool
{
    return (bool) preg_match('/[\x00-\x1F\x7F]/', $path)
        || (bool) preg_match('#^/(?:%2f|%5c)#i', $path)
        || (bool) preg_match('/%0[0-9a-f]|%1[0-9a-f]|%7f/i', $path);
}

/** True for a same-site path that is safe to use as a redirect target. */
function is_safe_local_url(?string $path): bool
{
    if (!is_string($path) || $path === '' || $path[0] !== '/') {
        return false;
    }
    // Reject protocol-relative, backslash tricks and header injection.
    return !preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $path)
        && strpos($path, '\\') === false
        && !has_unsafe_url_chars($path);
}

/** Redirect the browser to an application path and stop execution. */
function redirect(string $path): void
{
    // Only same-site paths are allowed; never redirect to an external origin.
    if (has_unsafe_url_chars($path)
        || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)
        || strpos($path, '//') === 0
        || strpos($path, '\\') !== false
    ) {
        $path = 'index.php';
    }

    if ($path !== '' && $path[0] === '/') {
        $base = APP_BASE;
        if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
            $path = substr($path, strlen($base));
        }
    }

    header('Location: ' . url(ltrim($path, '/')));
    exit;
}

// ---------------------------------------------------------------------------
// Money helpers (decimal-safe: all arithmetic happens in integer cents)
// ---------------------------------------------------------------------------

/** Convert a decimal amount (e.g. "89.50") into integer cents. */
function to_cents($amount): int
{
    return (int) round(((float) $amount) * 100, 0, PHP_ROUND_HALF_UP);
}

/** Convert integer cents into a plain decimal string ("89.50"). */
function from_cents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

/** Format a decimal amount using the configured currency symbol. */
function money($amount): string
{
    return setting('currency_symbol', 'P') . number_format((float) $amount, 2);
}

/** Format integer cents using the configured currency symbol. */
function money_cents(int $cents): string
{
    return money(from_cents($cents));
}

/** Round a decimal to 2 places using half-up rounding. */
function round_money($amount): float
{
    return round((float) $amount, 2, PHP_ROUND_HALF_UP);
}

// ---------------------------------------------------------------------------
// Request helpers
// ---------------------------------------------------------------------------

/**
 * Return a trimmed scalar request value. Array input (e.g. `field[]=x`) is
 * ignored so it can never reach code that expects a string.
 */
function post(string $key, $default = null)
{
    if (!array_key_exists($key, $_POST) || is_array($_POST[$key])) {
        return $default;
    }
    return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
}

/** Return a trimmed scalar query-string value (arrays are ignored). */
function get(string $key, $default = null)
{
    if (!array_key_exists($key, $_GET) || is_array($_GET[$key])) {
        return $default;
    }
    return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key];
}

/** Abort the request unless it is a POST. */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('Method Not Allowed');
    }
}

/** Abort the request unless it is a GET. */
function require_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        exit('Method Not Allowed');
    }
}

/** Send a JSON response and stop. */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/** Read and decode a JSON request body (falls back to $_POST). */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return $_POST;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** True when the current request expects JSON (AJAX / fetch). */
function is_api_request(): bool
{
    if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        return true;
    }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        return true;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return strpos($script, '/api/') !== false
        || (bool) preg_match('#/(?:checkout|product_search)\.php$#', $script);
}

// ---------------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------------

/** Queue a flash message shown on the next page load. */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Pull and clear all queued flash messages. */
function take_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

// ---------------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------------

/** Return the posted value again so forms keep user input. */
function old(string $key, $default = '')
{
    return post($key, $default);
}

/** True when a string is a valid non-negative decimal number. */
function is_decimal($value): bool
{
    return is_string($value) || is_numeric($value)
        ? (bool) preg_match('/^\d+(\.\d{1,2})?$/', (string) $value)
        : false;
}

/** True when a string is a valid non-negative integer. */
function is_whole_number($value): bool
{
    return (bool) preg_match('/^\d+$/', (string) $value);
}

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

/**
 * Build pagination metadata.
 *
 * @return array{page:int, per_page:int, total:int, pages:int, offset:int}
 */
function paginate(int $total, int $perPage = 15, string $pageParam = 'page'): array
{
    $perPage = max(1, $perPage);
    $pages   = max(1, (int) ceil($total / $perPage));
    $page    = (int) get($pageParam, '1');
    $page    = max(1, min($pages, $page));

    return [
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
    ];
}

/** Build a query string preserving the current filters. */
function query_with(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
    return http_build_query($params);
}

/** Render a pagination control. */
function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="Pagination">';
    $prev = max(1, $p['page'] - 1);
    $next = min($p['pages'], $p['page'] + 1);

    $html .= '<a class="page-link' . ($p['page'] <= 1 ? ' is-disabled' : '') . '" href="?' . e(query_with(['page' => $prev])) . '">&laquo; Prev</a>';
    for ($i = 1; $i <= $p['pages']; $i++) {
        if ($i === 1 || $i === $p['pages'] || abs($i - $p['page']) <= 1) {
            $active = $i === $p['page'] ? ' is-active' : '';
            $html  .= '<a class="page-link' . $active . '" href="?' . e(query_with(['page' => $i])) . '">' . $i . '</a>';
        } elseif (abs($i - $p['page']) === 2) {
            $html .= '<span class="page-ellipsis">&hellip;</span>';
        }
    }
    $html .= '<a class="page-link' . ($p['page'] >= $p['pages'] ? ' is-disabled' : '') . '" href="?' . e(query_with(['page' => $next])) . '">Next &raquo;</a>';
    $html .= '</nav>';
    return $html;
}

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------

/** Human friendly date/time. */
function nice_date(?string $datetime, string $format = 'M j, Y g:i A'): string
{
    if (empty($datetime)) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date($format, $ts) : '—';
}
