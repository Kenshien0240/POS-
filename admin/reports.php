<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();
$pdo  = db();

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$from = get('from', date('Y-m-d', strtotime('-29 days')));
$to   = get('to', date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-d', strtotime('-29 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to = date('Y-m-d'); }
if ($from > $to) { [$from, $to] = [$to, $from]; }

// Half-open range [from 00:00, day after `to` 00:00) so both date boundaries
// are inclusive and the comparison can use the created_at index.
$fromTs = $from . ' 00:00:00';
$toTs   = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
$rangeParams = [$fromTs, $toTs];
$rangeSql = 's.created_at >= ? AND s.created_at < ? AND s.status = "completed"';

// --- Summary ---------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS transactions,
            COALESCE(SUM(s.subtotal),0)        AS subtotal,
            COALESCE(SUM(s.discount_amount),0) AS discounts,
            COALESCE(SUM(s.tax_amount),0)      AS tax,
            COALESCE(SUM(s.total_amount),0)     AS revenue
     FROM sales s WHERE $rangeSql"
);
$stmt->execute($rangeParams);
$summary = $stmt->fetch();

$itemsSold = 0;
$itemStmt = $pdo->prepare("SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE $rangeSql");
$itemStmt->execute($rangeParams);
$itemsSold = (int) $itemStmt->fetchColumn();

$transactions = (int) $summary['transactions'];
$revenue      = (float) $summary['revenue'];
$avgSale      = $transactions > 0 ? $revenue / $transactions : 0;

// --- Daily breakdown -------------------------------------------------------
$dailyStmt = $pdo->prepare(
    "SELECT DATE(s.created_at) AS d, COUNT(*) AS txns,
            COALESCE(SUM(s.total_amount),0) AS revenue
     FROM sales s WHERE $rangeSql
     GROUP BY DATE(s.created_at) ORDER BY d"
);
$dailyStmt->execute($rangeParams);
$daily = $dailyStmt->fetchAll();

// --- Top products ----------------------------------------------------------
$topStmt = $pdo->prepare(
    "SELECT si.sku_snapshot, si.product_name_snapshot,
            SUM(si.quantity) AS units, COALESCE(SUM(si.line_total),0) AS revenue
     FROM sale_items si JOIN sales s ON s.id = si.sale_id
     WHERE $rangeSql
     GROUP BY si.product_id, si.sku_snapshot, si.product_name_snapshot
     ORDER BY revenue DESC LIMIT 10"
);
$topStmt->execute($rangeParams);
$topProducts = $topStmt->fetchAll();

// --- Sales by category -----------------------------------------------------
$catStmt = $pdo->prepare(
    "SELECT COALESCE(c.name, 'Uncategorised') AS category,
            SUM(si.quantity) AS units, COALESCE(SUM(si.line_total),0) AS revenue
     FROM sale_items si
     JOIN sales s ON s.id = si.sale_id
     LEFT JOIN products p ON p.id = si.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE $rangeSql
     GROUP BY c.id, c.name ORDER BY revenue DESC"
);
$catStmt->execute($rangeParams);
$byCategory = $catStmt->fetchAll();

// --- Sales by cashier ------------------------------------------------------
$cashStmt = $pdo->prepare(
    "SELECT u.name AS cashier, COUNT(*) AS txns, COALESCE(SUM(s.total_amount),0) AS revenue
     FROM sales s JOIN users u ON u.id = s.cashier_id
     WHERE $rangeSql
     GROUP BY s.cashier_id, u.name ORDER BY revenue DESC"
);
$cashStmt->execute($rangeParams);
$byCashier = $cashStmt->fetchAll();

// --- Sales by payment method ----------------------------------------------
$methodStmt = $pdo->prepare(
    "SELECT p.payment_method, COUNT(*) AS txns, COALESCE(SUM(p.amount_paid),0) AS revenue
     FROM payments p
     JOIN sales s ON s.id = p.sale_id
     WHERE $rangeSql
     GROUP BY p.payment_method ORDER BY revenue DESC"
);
$methodStmt->execute($rangeParams);
$byMethod = $methodStmt->fetchAll();

$methodLabels = ['cash' => 'Cash', 'card' => 'Card', 'e_wallet' => 'E-Wallet'];
$methodTotal  = 0.0;
foreach ($byMethod as $m) { $methodTotal += (float) $m['revenue']; }

// --- Individual transactions (with line-item breakdown) --------------------
$txnStmt = $pdo->prepare(
    "SELECT s.id, s.receipt_number, s.created_at, s.subtotal, s.discount_amount,
            s.tax_amount, s.total_amount, s.status, u.name AS cashier_name,
            p.payment_method, p.tendered_amount, p.change_amount, p.reference_no,
            (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si WHERE si.sale_id = s.id) AS units,
            (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS line_count
     FROM sales s
     JOIN users u ON u.id = s.cashier_id
     LEFT JOIN payments p ON p.sale_id = s.id
     WHERE $rangeSql
     ORDER BY s.created_at DESC
     LIMIT 200"
);
$txnStmt->execute($rangeParams);
$txnRows = $txnStmt->fetchAll();

$txnItems = [];
if ($txnRows) {
    $ids = array_map(static fn($t) => (int) $t['id'], $txnRows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $itemStmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id IN ($in) ORDER BY id");
    $itemStmt->execute($ids);
    foreach ($itemStmt->fetchAll() as $row) {
        $txnItems[(int) $row['sale_id']][] = $row;
    }
}

$shownTxns = count($txnRows);

// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales-report-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, ['Sales report', csv_safe(setting('store_name', 'Retail Store'))]);
    fputcsv($out, ['Period', $from . ' to ' . $to]);
    fputcsv($out, []);
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Gross revenue', number_format($revenue, 2)]);
    fputcsv($out, ['Transactions', $transactions]);
    fputcsv($out, ['Items sold', $itemsSold]);
    fputcsv($out, ['Average sale', number_format($avgSale, 2)]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Transactions', 'Revenue']);
    foreach ($daily as $row) {
        fputcsv($out, [$row['d'], $row['txns'], number_format((float) $row['revenue'], 2)]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Top products', 'Units', 'Revenue']);
    foreach ($topProducts as $row) {
        fputcsv($out, [csv_safe($row['product_name_snapshot'] . ' (' . $row['sku_snapshot'] . ')'), $row['units'], number_format((float) $row['revenue'], 2)]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Sales by cashier', 'Transactions', 'Revenue']);
    foreach ($byCashier as $row) {
        fputcsv($out, [csv_safe($row['cashier']), $row['txns'], number_format((float) $row['revenue'], 2)]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Sales by payment method', 'Transactions', 'Revenue']);
    foreach ($byMethod as $row) {
        fputcsv($out, [
            $methodLabels[$row['payment_method']] ?? $row['payment_method'],
            $row['txns'],
            number_format((float) $row['revenue'], 2),
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Transactions', 'Date', 'Cashier', 'Payment', 'Units', 'Total']);
    foreach ($txnRows as $t) {
        fputcsv($out, [
            csv_safe($t['receipt_number']), csv_safe($t['created_at']), csv_safe($t['cashier_name']),
            csv_safe($methodLabels[$t['payment_method'] ?? ''] ?? ($t['payment_method'] ?? '')),
            (int) $t['units'], number_format((float) $t['total_amount'], 2),
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Product', 'Date', 'Receipt', 'Code', 'Qty', 'Unit price', 'Line total']);
    foreach ($txnRows as $t) {
        foreach ($txnItems[(int) $t['id']] ?? [] as $it) {
            fputcsv($out, [
                csv_safe($it['product_name_snapshot']), csv_safe($t['created_at']), csv_safe($t['receipt_number']), csv_safe($it['sku_snapshot']),
                $it['quantity'], number_format((float) $it['unit_price'], 2),
                number_format((float) $it['line_total'], 2),
            ]);
        }
    }
    fclose($out);
    exit;
}

// Chart data (fill every day in range)
$dailyMap = [];
foreach ($daily as $row) { $dailyMap[$row['d']] = (float) $row['revenue']; }
$labels = [];
$values = [];
$cursor = strtotime($from);
$end = strtotime($to);
$guard = 0;
while ($cursor <= $end && $guard < 400) {
    $day = date('Y-m-d', $cursor);
    $labels[] = date('M j', $cursor);
    $values[] = round($dailyMap[$day] ?? 0, 2);
    $cursor = strtotime('+1 day', $cursor);
    $guard++;
}

$maxCatRevenue = 0;
foreach ($byCategory as $c) { $maxCatRevenue = max($maxCatRevenue, (float) $c['revenue']); }

$_page_title    = 'Reports';
$_page_subtitle = 'Analyse sales performance for a chosen period.';
$_active_nav    = 'reports';
$_page_actions  = '<a class="btn btn--ghost btn--sm" href="?' . e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) . '">' . icon('download', 'icon', 16) . ' Export CSV</a>';
$_page_styles   = ['css/dashboard.css'];
$_page_scripts  = '<script src="' . e(asset('js/dashboard.js')) . '"></script>';

require __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" action="<?= e(url('admin/reports.php')) ?>">
        <label class="text-sm text-muted" for="from">From</label>
        <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>" style="width:auto">
        <label class="text-sm text-muted" for="to">To</label>
        <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>" style="width:auto">
        <button class="btn btn--primary" type="submit"><?= icon('filter', 'icon', 16) ?> Apply</button>
    </form>
    <span class="toolbar__spacer"></span>
    <div class="flex gap-xs">
        <a class="btn btn--ghost btn--sm" href="?from=<?= e(date('Y-m-d')) ?>&to=<?= e(date('Y-m-d')) ?>">Today</a>
        <a class="btn btn--ghost btn--sm" href="?from=<?= e(date('Y-m-d', strtotime('-6 days'))) ?>&to=<?= e(date('Y-m-d')) ?>">7 days</a>
        <a class="btn btn--ghost btn--sm" href="?from=<?= e(date('Y-m-01')) ?>&to=<?= e(date('Y-m-d')) ?>">This month</a>
    </div>
</div>

<section class="grid grid--kpi mb-3">
    <article class="kpi">
        <div class="kpi__top">
            <div><p class="kpi__label">Gross revenue</p><p class="kpi__value"><?= e(money($revenue)) ?></p><p class="kpi__meta"><?= icon('wallet', 'icon', 14) ?> <?= e(nice_date($from, 'M j')) ?> â€“ <?= e(nice_date($to, 'M j, Y')) ?></p></div>
            <span class="kpi__icon"><?= icon('wallet', 'icon', 22) ?></span>
        </div>
    </article>
    <article class="kpi kpi--info">
        <div class="kpi__top">
            <div><p class="kpi__label">Transactions</p><p class="kpi__value"><?= number_format($transactions) ?></p><p class="kpi__meta"><?= icon('receipt', 'icon', 14) ?> <?= number_format($itemsSold) ?> items sold</p></div>
            <span class="kpi__icon"><?= icon('receipt', 'icon', 22) ?></span>
        </div>
    </article>
    <article class="kpi kpi--dark">
        <div class="kpi__top">
            <div><p class="kpi__label">Average sale</p><p class="kpi__value"><?= e(money($avgSale)) ?></p><p class="kpi__meta"><?= icon('trending', 'icon', 14) ?> Per transaction</p></div>
            <span class="kpi__icon"><?= icon('trending', 'icon', 22) ?></span>
        </div>
    </article>
    <article class="kpi kpi--warning">
        <div class="kpi__top">
            <div><p class="kpi__label">Discounts given</p><p class="kpi__value"><?= e(money((float) $summary['discounts'])) ?></p><p class="kpi__meta"><?= icon('tags', 'icon', 14) ?> Tax collected: <?= e(money((float) $summary['tax'])) ?></p></div>
            <span class="kpi__icon"><?= icon('tags', 'icon', 22) ?></span>
        </div>
    </article>
</section>

<div class="card mb-3">
    <header class="card__header">
        <div class="card__title-group"><h2>Revenue trend</h2><small>Daily gross revenue for the selected period</small></div>
    </header>
    <div class="chart-box">
        <canvas class="chart-canvas" data-chart="bar"
                data-values='<?= e(json_encode($values)) ?>'
                data-labels='<?= e(json_encode($labels)) ?>' role="img"
                aria-label="Bar chart of daily revenue"></canvas>
    </div>
</div>

<section class="grid grid--3">
    <article class="card">
        <header class="card__header"><h2>Top products</h2></header>
        <?php if (!$topProducts): ?>
            <div class="state"><div class="state__icon"><?= icon('package', 'icon', 30) ?></div><p class="state__title">No sales in this period</p><p class="state__text">Try a wider date range.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th>#</th><th>Product</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
                    <tbody>
                        <?php foreach ($topProducts as $i => $p): ?>
                            <tr>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td><div class="cell-title"><?= e($p['product_name_snapshot']) ?></div><div class="cell-sub mono"><?= e($p['sku_snapshot']) ?></div></td>
                                <td class="num"><?= (int) $p['units'] ?></td>
                                <td class="num"><?= e(money((float) $p['revenue'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="card">
        <header class="card__header"><h2>Sales by category</h2></header>
        <?php if (!$byCategory): ?>
            <div class="state"><div class="state__icon"><?= icon('tags', 'icon', 30) ?></div><p class="state__title">No category sales</p><p class="state__text">Nothing to report for this period.</p></div>
        <?php else: ?>
            <div class="rank-list">
                <?php foreach ($byCategory as $c): ?>
                    <div class="rank-item">
                        <div class="rank-item__body">
                            <div class="rank-item__name"><?= e($c['category']) ?></div>
                            <div class="rank-item__meta"><?= (int) $c['units'] ?> units sold</div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $maxCatRevenue > 0 ? round((float) $c['revenue'] / $maxCatRevenue * 100) : 0 ?>%"></div></div>
                        </div>
                        <span class="rank-item__value"><?= e(money((float) $c['revenue'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="card">
        <header class="card__header"><h2>Payment methods</h2></header>
        <?php if (!$byMethod): ?>
            <div class="state"><div class="state__icon"><?= icon('wallet', 'icon', 30) ?></div><p class="state__title">No payments recorded</p><p class="state__text">Payment summaries appear with completed sales.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th>Method</th><th class="num">Txns</th><th class="num">Revenue</th></tr></thead>
                    <tbody>
                        <?php foreach ($byMethod as $m):
                            $share = $methodTotal > 0 ? round((float) $m['revenue'] / $methodTotal * 100) : 0;
                            $tone  = $m['payment_method'] === 'cash' ? 'success' : ($m['payment_method'] === 'card' ? 'info' : 'accent');
                        ?>
                            <tr>
                                <td>
                                    <span class="badge badge--<?= $tone ?>"><?= e($methodLabels[$m['payment_method']] ?? $m['payment_method']) ?></span>
                                    <div class="cell-sub"><?= $share ?>% of revenue</div>
                                </td>
                                <td class="num"><?= (int) $m['txns'] ?></td>
                                <td class="num"><?= e(money((float) $m['revenue'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>

<article class="card mt-3">
    <header class="card__header"><h2>Sales by cashier</h2></header>
    <?php if (!$byCashier): ?>
        <div class="state"><div class="state__icon"><?= icon('users', 'icon', 30) ?></div><p class="state__title">No cashier activity</p><p class="state__text">Nothing to report for this period.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Cashier</th><th class="num">Transactions</th><th class="num">Revenue</th><th class="num">Average</th></tr></thead>
                <tbody>
                    <?php foreach ($byCashier as $c): ?>
                        <tr>
                            <td class="cell-title"><?= e($c['cashier']) ?></td>
                            <td class="num"><?= (int) $c['txns'] ?></td>
                            <td class="num"><?= e(money((float) $c['revenue'])) ?></td>
                            <td class="num"><?= e(money((int) $c['txns'] > 0 ? (float) $c['revenue'] / (int) $c['txns'] : 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</article>

<article class="card mt-3">
    <header class="card__header">
        <div class="card__title-group">
            <h2>Transaction detail</h2>
            <small>Completed sales in this period<?php if ($transactions > $shownTxns): ?> &middot; showing the latest <?= number_format($shownTxns) ?> of <?= number_format($transactions) ?><?php endif; ?></small>
        </div>
        <div class="card__actions">
            <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/sales.php')) ?>"><?= icon('receipt', 'icon', 15) ?> Sales history</a>
        </div>
    </header>
    <?php if (!$txnRows): ?>
        <div class="state">
            <div class="state__icon"><?= icon('receipt', 'icon', 30) ?></div>
            <p class="state__title">No transactions in this period</p>
            <p class="state__text">Pick a different date range, or complete a sale in the Point of Sale.</p>
        </div>
    <?php else: ?>
        <div class="txn-list">
            <?php foreach ($txnRows as $t):
                $rows   = $txnItems[(int) $t['id']] ?? [];
                $mLabel = $methodLabels[$t['payment_method'] ?? ''] ?? ($t['payment_method'] ?? 'â€”');
                $units  = (int) $t['units'];
            ?>
                <details class="txn">
                    <summary class="txn__summary">
                        <span class="txn__receipt mono"><?= e($t['receipt_number']) ?></span>
                        <span class="txn__meta"><?= e(nice_date($t['created_at'], 'M j, Y g:i A')) ?></span>
                        <span class="txn__meta"><?= e($t['cashier_name']) ?></span>
                        <span class="badge badge--muted"><?= e($mLabel) ?></span>
                        <span class="txn__meta"><?= $units ?> item<?= $units === 1 ? '' : 's' ?></span>
                        <span class="txn__total"><?= e(money((float) $t['total_amount'])) ?></span>
                    </summary>
                    <div class="txn__body">
                        <?php if ($rows): ?>
                            <table class="table table--compact">
                                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Subtotal</th></tr></thead>
                                <tbody>
                                    <?php foreach ($rows as $it): ?>
                                        <tr>
                                            <td>
                                                <div class="cell-title"><?= e($it['product_name_snapshot']) ?></div>
                                                <div class="cell-sub mono"><?= e($it['sku_snapshot']) ?></div>
                                            </td>
                                            <td class="num"><?= (int) $it['quantity'] ?></td>
                                            <td class="num"><?= e(money((float) $it['unit_price'])) ?></td>
                                            <td class="num"><?= e(money((float) $it['line_total'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <div class="txn__totals">
                            <span>Subtotal <strong><?= e(money((float) $t['subtotal'])) ?></strong></span>
                            <?php if ((float) $t['discount_amount'] > 0): ?>
                                <span>Discount <strong>-<?= e(money((float) $t['discount_amount'])) ?></strong></span>
                            <?php endif; ?>
                            <?php if ((float) $t['tax_amount'] > 0): ?>
                                <span><?= e(setting('tax_label', 'VAT')) ?> <strong><?= e(money((float) $t['tax_amount'])) ?></strong></span>
                            <?php endif; ?>
                            <span>Total <strong><?= e(money((float) $t['total_amount'])) ?></strong></span>
                            <?php if (($t['payment_method'] ?? '') === 'cash'): ?>
                                <span>Tendered <strong><?= e(money((float) ($t['tendered_amount'] ?? 0))) ?></strong></span>
                                <span>Change <strong><?= e(money((float) ($t['change_amount'] ?? 0))) ?></strong></span>
                            <?php elseif (!empty($t['reference_no'])): ?>
                                <span>Reference <strong><?= e($t['reference_no']) ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex gap-xs" style="margin-top:.65rem">
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/sale_view.php?id=' . (int) $t['id'])) ?>"><?= icon('eye', 'icon', 15) ?> Open transaction</a>
                            <a class="btn btn--ghost btn--sm" href="<?= e(url('cashier/receipt.php?id=' . (int) $t['id'])) ?>"><?= icon('printer', 'icon', 15) ?> Receipt</a>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>

<?php require __DIR__ . '/../includes/footer.php'; ?>
