<?php
/**
 * Sidebar navigation. Included by header.php; expects $__nav, $__user,
 * $__store and $__active to be defined.
 */
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar__brand">
        <span class="brand__mark"><?= icon('store', 'icon', 22) ?></span>
        <span class="brand__text">
            <strong><?= e($__store) ?></strong>
            <small>Retail Point of Sale</small>
        </span>
    </div>

    <nav class="sidebar__nav" aria-label="Main navigation">
        <p class="sidebar__section-label"><?= $__user && $__user['role'] === 'admin' ? 'Management' : 'Workspace' ?></p>
        <?php foreach ($__nav as $item): ?>
            <a class="nav-item<?= $__active === $item['key'] ? ' is-active' : '' ?>"
               href="<?= e(url($item['href'])) ?>"
               <?= $__active === $item['key'] ? 'aria-current="page"' : '' ?>>
                <?= icon($item['icon'], 'nav-item__icon', 20) ?>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__footer">
        <div class="sidebar__user">
            <span class="avatar"><?= e(strtoupper(substr($__user['name'] ?? 'U', 0, 1))) ?></span>
            <span class="sidebar__user-info">
                <strong><?= e($__user['name'] ?? '') ?></strong>
                <small><?= e(ucfirst($__user['role'] ?? '')) ?></small>
            </span>
        </div>
        <form method="post" action="<?= e(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--ghost btn--block btn--sm">
                <?= icon('logout', 'icon', 16) ?> Sign out
            </button>
        </form>
    </div>
</aside>
