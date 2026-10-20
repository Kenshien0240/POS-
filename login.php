<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_guest();

$errors = [];
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();
    $username = post('username', '');
    $password = post('password', '');

    if ($username === '' || $password === '') {
        $errors['form'] = 'Please enter both your username and password.';
    } else {
        $result = attempt_login($username, $password);
        if ($result['ok']) {
            $intended = $_SESSION['_intended'] ?? null;
            unset($_SESSION['_intended']);
            flash('success', $result['message']);
            $home = $result['user']['role'] === 'admin' ? 'admin/dashboard.php' : 'cashier/pos.php';
            if (!is_safe_local_url($intended) || stripos((string) $intended, 'logout') !== false) {
                $intended = $home;
            }
            redirect($intended);
        }
        $errors['form'] = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1622">
    <title>Sign in &middot; <?= e(setting('store_name', 'Group 6 Retail')) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="login-page">
<main class="login">
    <section class="login__brand">
        <div class="login__brand-inner">
            <span class="login__logo"><?= icon('store', 'icon', 30) ?></span>
            <h1><?= e(setting('store_name', 'Group 6 Retail Store')) ?></h1>
            <p>Retail Point-of-Sale System</p>
            <ul class="login__features">
                <li><?= icon('cart', 'icon', 18) ?> Fast, cashier-friendly checkout</li>
                <li><?= icon('package', 'icon', 18) ?> Live inventory and low-stock alerts</li>
                <li><?= icon('chart', 'icon', 18) ?> Daily sales reports and analytics</li>
                <li><?= icon('shield', 'icon', 18) ?> Secure role-based access control</li>
            </ul>
            <footer>Group 6 Project &middot; PHP &amp; MySQL</footer>
        </div>
    </section>

    <section class="login__form-wrap">
        <form class="login__form" method="post" action="<?= e(url('login.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <header>
                <h2>Welcome back</h2>
                <p class="text-muted">Sign in to your account to continue.</p>
            </header>

            <?php render_flashes(); ?>

            <?php if (!empty($errors['form'])): ?>
                <div class="alert alert--danger" role="alert">
                    <span class="alert__icon"><?= icon('alert', 'icon', 18) ?></span>
                    <span class="alert__text"><?= e($errors['form']) ?></span>
                </div>
            <?php endif; ?>

            <label class="field">
                <span class="field__label">Username</span>
                <span class="input-group input-group--lead">
                    <span class="input-group__addon"><?= icon('user', 'icon', 18) ?></span>
                    <input class="input" type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
                </span>
            </label>

            <label class="field">
                <span class="field__label">Password</span>
                <span class="input-group input-group--lead input-group--trail">
                    <span class="input-group__addon"><?= icon('lock', 'icon', 18) ?></span>
                    <input class="input" id="loginPassword" type="password" name="password" autocomplete="current-password" required>
                    <button class="input-group__addon input-group__addon--action" type="button" data-password-toggle aria-controls="loginPassword" aria-label="Show password" aria-pressed="false">
                        <span class="password-toggle__icon password-toggle__icon--show"><?= icon('eye', 'icon', 18) ?></span>
                        <span class="password-toggle__icon password-toggle__icon--hide"><?= icon('eye-off', 'icon', 18) ?></span>
                    </button>
                </span>
            </label>

            <button class="btn btn--primary btn--lg btn--block" type="submit">
                <?= icon('power', 'icon', 18) ?> Sign in
            </button>

            <?php if (APP_DEBUG): ?>
                <div class="login__hint">
                    <strong><?= icon('info', 'icon', 15) ?> Development mode</strong>
                    <span>Demo account credentials are documented in <code>README.md</code>. Change them before deploying.</span>
                </div>
            <?php endif; ?>
        </form>
    </section>
</main>
<link rel="stylesheet" href="<?= e(asset('css/login.css')) ?>">
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
