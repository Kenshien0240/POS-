<?php
/**
 * 403 Forbidden page. Included by require_admin() for unauthorized access.
 */
$_page_title = 'Access denied';
require __DIR__ . '/header.php';
?>
<div class="state state--error">
    <div class="state__icon"><?= icon('lock', 'icon', 40) ?></div>
    <h2 class="state__title">Access denied</h2>
    <p class="state__text">Your account does not have permission to open this page. Administrators manage users, products, and reports.</p>
    <a class="btn btn--primary" href="<?= e(url(is_admin() ? 'admin/dashboard.php' : 'cashier/pos.php')) ?>">
        <?= icon('arrow-left', 'icon', 18) ?> Back to my dashboard
    </a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
