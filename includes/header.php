<?php
/**
 * Shared layout header: <head>, sidebar navigation and top bar.
 * Pages set these variables before including this file:
 *   $_page_title    (string)  main heading
 *   $_page_subtitle (string)  optional supporting text
 *   $_active_nav    (string)  navigation key to highlight
 *   $_page_actions  (string)  optional HTML rendered in the top bar
 *   $_page_styles   (array)   optional extra stylesheet paths inside /assets
 *   $_body_class    (string)  optional extra body class
 */

if (!function_exists('current_user')) {
    require_once __DIR__ . '/bootstrap.php';
}

$__user     = current_user();
$__title    = $_page_title ?? 'Dashboard';
$__subtitle = $_page_subtitle ?? '';
$__active   = $_active_nav ?? '';
$__actions  = $_page_actions ?? '';
$__body     = $_body_class ?? '';
$__store    = setting('store_name', 'Group 6 Retail');

$__nav = [];
if ($__user && $__user['role'] === 'admin') {
    $__nav = [
        ['key' => 'dashboard', 'label' => 'Dashboard',  'href' => 'admin/dashboard.php', 'icon' => 'dashboard'],
        ['key' => 'pos',       'label' => 'Point of Sale', 'href' => 'cashier/pos.php', 'icon' => 'cart'],
        ['key' => 'sales',     'label' => 'Sales',      'href' => 'admin/sales.php', 'icon' => 'receipt'],
        ['key' => 'products',  'label' => 'Products',   'href' => 'admin/products.php', 'icon' => 'package'],
        ['key' => 'inventory', 'label' => 'Inventory',  'href' => 'admin/inventory.php', 'icon' => 'list'],
        ['key' => 'categories','label' => 'Categories', 'href' => 'admin/categories.php', 'icon' => 'tags'],
        ['key' => 'reports',   'label' => 'Reports',    'href' => 'admin/reports.php', 'icon' => 'chart'],
        ['key' => 'users',     'label' => 'Users',      'href' => 'admin/users.php', 'icon' => 'users'],
        ['key' => 'settings',  'label' => 'Settings',   'href' => 'admin/settings.php', 'icon' => 'settings'],
    ];
} elseif ($__user) {
    $__nav = [
        ['key' => 'pos',   'label' => 'Point of Sale', 'href' => 'cashier/pos.php', 'icon' => 'cart'],
        ['key' => 'sales', 'label' => 'My Sales',      'href' => 'admin/sales.php', 'icon' => 'receipt'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f1b2d">
    <title><?= e($__title) ?> &middot; <?= e($__store) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <?php foreach (($_page_styles ?? []) as $__stylesheet): ?>
        <link rel="stylesheet" href="<?= e(asset($__stylesheet)) ?>">
    <?php endforeach; ?>
    <?php if (!empty($_page_head)) { echo $_page_head; } ?>
</head>
<body class="<?= e($__body) ?>">
<a class="skip-link" href="#main-content">Skip to content</a>

<div class="app-shell" id="appShell">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>

    <div class="app-main">
        <header class="topbar">
            <button type="button" class="topbar__toggle" id="sidebarToggle" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">
                <?= icon('menu', 'icon', 22) ?>
            </button>
            <div class="topbar__heading">
                <h1 class="topbar__title"><?= e($__title) ?></h1>
                <?php if ($__subtitle !== ''): ?>
                    <p class="topbar__subtitle"><?= e($__subtitle) ?></p>
                <?php endif; ?>
            </div>
            <div class="topbar__actions">
                <?= $__actions ?>
                <span class="topbar__date"><?= icon('calendar', 'icon', 16) ?> <span><?= e(date('D, M j, Y')) ?></span></span>
                <span class="role-badge role-badge--<?= e($__user['role'] ?? 'cashier') ?>">
                    <?= icon($__user && $__user['role'] === 'admin' ? 'shield' : 'user', 'icon', 14) ?>
                    <?= e(ucfirst($__user['role'] ?? '')) ?>
                </span>
            </div>
        </header>

        <main class="content" id="main-content">
            <?php render_flashes(); ?>
