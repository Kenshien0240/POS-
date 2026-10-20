<?php
/**
 * Authentication and authorization helpers.
 */

/**
 * Return the currently authenticated user (fresh from the database) or null.
 * Inactive accounts are logged out automatically.
 */
function current_user(): ?array
{
    static $user = null;
    static $resolved = false;

    if ($resolved) {
        return $user;
    }
    $resolved = true;

    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return $user = null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['status'] !== 1) {
        logout_user();
        return $user = null;
    }

    return $user = $row;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

function is_cashier(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'cashier';
}

/** Require an authenticated user, otherwise send them to the login page. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        if (is_api_request()) {
            json_response(['ok' => false, 'message' => 'Please sign in to continue.'], 401);
        }
        flash('warning', 'Please sign in to continue.');
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login.php');
    }
    return $user;
}

/** Require an administrator. Cashiers are rejected with 403. */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        if (is_api_request()) {
            json_response(['ok' => false, 'message' => 'You do not have permission to perform this action.'], 403);
        }
        http_response_code(403);
        require __DIR__ . '/forbidden.php';
        exit;
    }
    return $user;
}

/** Require a specific role (roles are a fixed allow-list). */
function require_role(string $role): array
{
    $user = require_login();
    if (!in_array($role, ['admin', 'cashier'], true) || $user['role'] !== $role) {
        if (is_api_request()) {
            json_response(['ok' => false, 'message' => 'You do not have permission to perform this action.'], 403);
        }
        http_response_code(403);
        require __DIR__ . '/forbidden.php';
        exit;
    }
    return $user;
}

/** Redirect signed-in users away from guest-only pages (e.g. login). */
function require_guest(): void
{
    if (is_logged_in()) {
        redirect(is_admin() ? 'admin/dashboard.php' : 'cashier/pos.php');
    }
}

/**
 * Attempt to authenticate a user.
 *
 * @return array{ok:bool, message:string, user:?array}
 */
function attempt_login(string $username, string $password): array
{
    $username = trim($username);
    $invalid  = ['ok' => false, 'message' => 'Incorrect username or password.', 'user' => null];

    // Reject absurdly long input before touching the database.
    if ($username === '' || strlen($username) > 50 || strlen($password) > 4096) {
        return $invalid;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Always run a hash check to reduce timing differences.
    $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    if (!password_verify($password, $hash) || !$user) {
        return $invalid;
    }

    if ((int) $user['status'] !== 1) {
        return ['ok' => false, 'message' => 'This account has been deactivated. Contact an administrator.', 'user' => null];
    }

    // Transparently upgrade old hashes when PHP's default algorithm changes.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if ($newHash !== false) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$newHash, (int) $user['id']]);
            $user['password_hash'] = $newHash;
        }
    }

    login_user($user);
    return ['ok' => true, 'message' => 'Welcome back, ' . $user['name'] . '.', 'user' => $user];
}

/** Establish the session for a user. */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));

    $stmt = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
}

/** Destroy the current session. */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// Convenience permission helpers (business rules)
// ---------------------------------------------------------------------------

/** Can the current user manage products / categories / users / settings? */
function can_manage_catalog(): bool
{
    return is_admin();
}

/** May the given user view a specific sale? Admins see all; cashiers see their own. */
function can_view_sale(array $sale): bool
{
    $user = current_user();
    if ($user === null) {
        return false;
    }
    return $user['role'] === 'admin' || (int) $sale['cashier_id'] === (int) $user['id'];
}
