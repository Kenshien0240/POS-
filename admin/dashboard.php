<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();
$pdo  = db();

$_page_title    = 'Dashboard';
$_page_subtitle = 'Store performance and inventory at a glance.';
$_active_nav    = 'dashboard';
$_page_styles   = ['css/dashboard.css'];
$_page_scripts  = '<script src="' . e(asset('js/dashboard.js')) . '"></script>';

// ---------------------------------------------------------------------------
// KPI queries
// ---------------------------------------------------------------------------
$today = date('Y-m-d');

$todayStats = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS count
     FROM sales WHERE status = "completed" AND DATE(created_at) = ?'
);
$todayStats->execute([$today]);
$todayStats = $todayStats->fetch();

$yesterday = date('Y-m-d', strtotime('-1 day'));
$ystats = $pdo->prepare(
    'SELECT COALESCE(SUM(total_amount),0) AS total FROM sales WHERE status = "completed" AND DATE(created_at) = ?'
);
$ystats->execute([$yesterday]);
$yesterdayTotal = (float) $ystats->fetchColumn();

$todayTotal = (float) $todayStats['total'];
$delta = $yesterdayTotal > 0 ? (($todayTotal - $yesterdayTotal) / $yesterdayTotal) * 100 : null;

$lowStock = $pdo->query(
    'SELECT p.*, c.name AS category_name FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.status = 1 AND p.stock_quantity <= p.low_stock_threshold
     ORDER BY (CAST(p.stock_quantity AS SIGNED) - CAST(p.low_stock_threshold AS SIGNED)), p.name LIMIT 6'
)->fetchAll();

$lowStockCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM products WHERE status = 1 AND stock_quantity <= low_stock_threshold'
)->fetchColumn();

$productStats = $pdo->query(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 1 AND stock_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
            COALESCE(SUM(price * stock_quantity),0) AS stock_value
     FROM products'
)->fetch();
$inactiveProducts = (int) $productStats['total'] - (int) $productStats['active'];

// 7-day sales series
$from = date('Y-m-d', strtotime('-6 days'));
$series = $pdo->prepare(
    'SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS total
     FROM sales WHERE status = "completed" AND DATE(created_at) >= ?
     GROUP BY DATE(created_at)'
);
$series->execute([$from]);
$byDay = [];
foreach ($series->fetchAll() as $row) {
    $byDay[$row['d']] = (float) $row['total'];
}
$chartLabels = [];
$chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D', strtotime($day));
    $chartValues[] = round($byDay[$day] ?? 0, 2);
}

// Best sellers (last 30 days)
$bestSellers = $pdo->query(
    'SELECT si.product_name_snapshot, si.sku_snapshot,
            SUM(si.quantity) AS units, COALESCE(SUM(si.line_total),0) AS revenue
     FROM sale_items si
     JOIN sales s ON s.id = si.sale_id
     WHERE s.status = "completed" AND s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY si.product_id, si.product_name_snapshot, si.sku_snapshot
     ORDER BY units DESC LIMIT 5'
)->fetchAll();
$maxUnits = 0;
foreach ($bestSellers as $b) { $maxUnits = max($maxUnits, (int) $b['units']); }

// Recent transactions
$recent = $pdo->query(
    'SELECT s.*, u.name AS cashier_name,
            (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
     FROM sales s JOIN users u ON u.id = s.cashier_id
     ORDER BY s.created_at DESC LIMIT 8'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<section class="grid grid--kpi mb-3">
    <article class="kpi">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Sales today</p>
                <p class="kpi__value"><?= e(money($todayTotal)) ?></p>
                <p class="kpi__meta">
                    <?php if ($delta === null): ?>
                        <?= icon('clock', 'icon', 14) ?> No sales yesterday
                    <?php elseif ($delta >= 0): ?>
                        <span class="text-success"><?= icon('trending', 'icon', 14) ?> <?= number_format($delta, 1) ?>%</span> vs yesterday
                    <?php else: ?>
                        <span class="text-danger"><?= icon('trending', 'icon', 14) ?> <?= number_format($delta, 1) ?>%</span> vs yesterday
                    <?php endif; ?>
                </p>
            </div>
            <span class="kpi__icon"><?= icon('wallet', 'icon', 22) ?></span>
        </div>
    </article>

    <article class="kpi kpi--info">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Transactions today</p>
                <p class="kpi__value"><?= number_format((int) $todayStats['count']) ?></p>
                <p class="kpi__meta"><?= icon('receipt', 'icon', 14) ?> Completed sales</p>
            </div>
            <span class="kpi__icon"><?= icon('receipt', 'icon', 22) ?></span>
        </div>
    </article>

    <article class="kpi">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Total products</p>
                <p class="kpi__value"><?= number_format((int) $productStats['total']) ?></p>
                <p class="kpi__meta"><?= icon('check-circle', 'icon', 14) ?> <?= (int) $productStats['active'] ?> active &middot; <?= $inactiveProducts ?> inactive</p>
            </div>
            <span class="kpi__icon"><?= icon('package', 'icon', 22) ?></span>
        </div>
    </article>

    <article class="kpi kpi--warning">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Low-stock items</p>
                <p class="kpi__value"><?= number_format($lowStockCount) ?></p>
                <p class="kpi__meta"><?= icon('alert', 'icon', 14) ?> At or below threshold</p>
            </div>
            <span class="kpi__icon"><?= icon('alert', 'icon', 22) ?></span>
        </div>
    </article>

    <article class="kpi kpi--dark">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Inventory value</p>
                <p class="kpi__value"><?= e(money((float) $productStats['stock_value'])) ?></p>
                <p class="kpi__meta"><?= icon('alert', 'icon', 14) ?> <?= (int) $productStats['out_of_stock'] ?> out of stock &middot; <?= (int) $productStats['active'] ?> active</p>
            </div>
            <span class="kpi__icon"><?= icon('package', 'icon', 22) ?></span>
        </div>
    </article>
</section>

<section class="grid grid--dash mb-3">
    <article class="card">
        <header class="card__header">
            <div class="card__title-group">
                <h2>Sales - last 7 days</h2>
                <small>Completed transactions grouped by day</small>
            </div>
            <div class="card__actions">
                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/reports.php')) ?>"><?= icon('chart', 'icon', 16) ?> Full reports</a>
            </div>
        </header>
        <div class="chart-box">
            <canvas class="chart-canvas" data-chart="line"
                    data-values='<?= e(json_encode($chartValues)) ?>'
                    data-labels='<?= e(json_encode($chartLabels)) ?>' role="img"
                    aria-label="Line chart of sales over the last seven days"></canvas>
        </div>
    </article>

    <article class="card">
        <header class="card__header">
            <div class="card__title-group">
                <h2>Best sellers</h2>
                <small>By units sold, last 30 days</small>
            </div>
        </header>
        <?php if (!$bestSellers): ?>
            <div class="state">
                <div class="state__icon"><?= icon('trending', 'icon', 32) ?></div>
                <p class="state__title">No sales yet</p>
                <p class="state__text">Best sellers appear once transactions are recorded.</p>
            </div>
        <?php else: ?>
            <div class="rank-list">
                <?php foreach ($bestSellers as $i => $b): ?>
                    <div class="rank-item">
                        <span class="rank-item__no"><?= $i + 1 ?></span>
                        <div class="rank-item__body">
                            <div class="rank-item__name"><?= e($b['product_name_snapshot']) ?></div>
                            <div class="rank-item__meta"><?= e($b['sku_snapshot']) ?> &middot; <?= (int) $b['units'] ?> sold</div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $maxUnits ? round((int) $b['units'] / $maxUnits * 100) : 0 ?>%"></div></div>
                        </div>
                        <span class="rank-item__value"><?= e(money((float) $b['revenue'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="grid grid--dash">
    <article class="card">
        <header class="card__header">
            <div class="card__title-group">
                <h2>Recent transactions</h2>
                <small>Latest activity across the store</small>
            </div>
            <div class="card__actions">
                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/sales.php')) ?>">View all</a>
            </div>
        </header>
        <?php if (!$recent): ?>
            <div class="state">
                <div class="state__icon"><?= icon('receipt', 'icon', 32) ?></div>
                <p class="state__title">No transactions yet</p>
                <p class="state__text">Completed sales will be listed here.</p>
                <a class="btn btn--primary" href="<?= e(url('cashier/pos.php')) ?>"><?= icon('cart', 'icon', 18) ?> Open Point of Sale</a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Cashier</th>
                            <th class="num">Items</th>
                            <th class="num">Total</th>
                            <th>Status</th>
                            <th>Time</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $sale): ?>
                            <tr>
                                <td class="mono"><?= e($sale['receipt_number']) ?></td>
                                <td><?= e($sale['cashier_name']) ?></td>
                                <td class="num"><?= (int) $sale['item_count'] ?></td>
                                <td class="num"><?= e(money((float) $sale['total_amount'])) ?></td>
                                <td>
                                    <?php if ($sale['status'] === 'completed'): ?>
                                        <span class="badge badge--success"><span class="dot"></span>Completed</span>
                                    <?php else: ?>
                                        <span class="badge badge--danger"><span class="dot"></span>Voided</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted nowrap"><?= e(nice_date($sale['created_at'], 'M j, g:i A')) ?></td>
                                <td class="actions">
                                    <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/sale_view.php?id=' . (int) $sale['id'])) ?>"><?= icon('eye', 'icon', 15) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="card">
        <header class="card__header">
            <div class="card__title-group">
                <h2>Low-stock alerts</h2>
                <small>Reorder soon</small>
            </div>
            <div class="card__actions">
                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/products.php?stock=low')) ?>">Manage</a>
            </div>
        </header>
        <?php if (!$lowStock): ?>
            <div class="state">
                <div class="state__icon"><?= icon('check-circle', 'icon', 32) ?></div>
                <p class="state__title">All stocked up</p>
                <p class="state__text">No products are below their low-stock threshold.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <tbody>
                        <?php foreach ($lowStock as $p): ?>
                            <tr>
                                <td>
                                    <div class="cell-title"><?= e($p['name']) ?></div>
                                    <div class="cell-sub mono"><?= e($p['sku']) ?></div>
                                </td>
                                <td class="num">
                                    <span class="badge <?= (int) $p['stock_quantity'] === 0 ? 'badge--danger' : 'badge--warning' ?>">
                                        <?= (int) $p['stock_quantity'] ?> left
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
