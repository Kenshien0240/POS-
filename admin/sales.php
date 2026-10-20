<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_login();
$pdo  = db();

// ---------------------------------------------------------------------------
// Actions (administrators may void a sale)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();
    require_admin();
    if (post('action') === 'void') {
        $result = void_sale((int) post('id'), (int) $user['id']);
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
    }
    redirect('admin/sales.php');
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$from    = get('from', '');
$to      = get('to', '');
$status  = get('status', '');
$cashier = (int) get('cashier', 0);
$search  = get('q', '');

$where = [];
$params = [];

// Cashiers may only ever see their own transactions.
if ($user['role'] !== 'admin') {
    $where[] = 's.cashier_id = ?';
    $params[] = (int) $user['id'];
} elseif ($cashier > 0) {
    $where[] = 's.cashier_id = ?';
    $params[] = $cashier;
}

if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'DATE(s.created_at) >= ?';
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'DATE(s.created_at) <= ?';
    $params[] = $to;
}
if (in_array($status, ['completed', 'voided'], true)) {
    $where[] = 's.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = 's.receipt_number LIKE ?';
    $params[] = '%' . $search . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare("SELECT COUNT(*) FROM sales s $whereSql");
$count->execute($params);
$pg = paginate((int) $count->fetchColumn(), 20);

// Totals for the filtered range
$sumStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(s.total_amount),0) AS total,
            SUM(CASE WHEN s.status = 'voided' THEN 1 ELSE 0 END) AS voided
     FROM sales s $whereSql"
);
$sumStmt->execute($params);
$summary = $sumStmt->fetch();

$sql = "SELECT s.*, u.name AS cashier_name,
               (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si WHERE si.sale_id = s.id) AS item_count,
               p.payment_method
        FROM sales s
        JOIN users u ON u.id = s.cashier_id
        LEFT JOIN payments p ON p.sale_id = s.id
        $whereSql
        ORDER BY s.created_at DESC
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$cashiers = $user['role'] === 'admin'
    ? $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll()
    : [];

$_page_title    = $user['role'] === 'admin' ? 'Sales' : 'My Sales';
$_page_subtitle = $user['role'] === 'admin' ? 'Every transaction processed in the store.' : 'Transactions you have processed.';
$_active_nav    = 'sales';

require __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--3 mb-3">
    <article class="kpi">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Filtered sales</p>
                <p class="kpi__value"><?= e(money((float) $summary['total'])) ?></p>
                <p class="kpi__meta"><?= icon('receipt', 'icon', 14) ?> <?= number_format($pg['total']) ?> transaction(s)</p>
            </div>
            <span class="kpi__icon"><?= icon('wallet', 'icon', 22) ?></span>
        </div>
    </article>
    <article class="kpi kpi--info">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Average sale</p>
                <p class="kpi__value"><?= e(money($pg['total'] > 0 ? (float) $summary['total'] / $pg['total'] : 0)) ?></p>
                <p class="kpi__meta"><?= icon('trending', 'icon', 14) ?> Per transaction</p>
            </div>
            <span class="kpi__icon"><?= icon('trending', 'icon', 22) ?></span>
        </div>
    </article>
    <article class="kpi kpi--warning">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Voided</p>
                <p class="kpi__value"><?= number_format((int) $summary['voided']) ?></p>
                <p class="kpi__meta"><?= icon('alert', 'icon', 14) ?> Reversed transactions</p>
            </div>
            <span class="kpi__icon"><?= icon('alert', 'icon', 22) ?></span>
        </div>
    </article>
</div>

<div class="toolbar">
    <form method="get" action="<?= e(url('admin/sales.php')) ?>">
        <div class="search-box">
            <span class="search-box__icon"><?= icon('search', 'icon', 18) ?></span>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Receipt numberâ€¦" aria-label="Search receipts">
        </div>
        <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From date" style="width:auto">
        <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To date" style="width:auto">
        <select class="select" name="status" data-autosubmit aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="voided" <?= $status === 'voided' ? 'selected' : '' ?>>Voided</option>
        </select>
        <?php if ($user['role'] === 'admin'): ?>
            <select class="select" name="cashier" data-autosubmit aria-label="Filter by cashier">
                <option value="">All cashiers</option>
                <?php foreach ($cashiers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $cashier === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn btn--ghost" type="submit"><?= icon('filter', 'icon', 16) ?> Filter</button>
        <?php if ($from || $to || $status || $cashier || $search): ?>
            <a class="btn btn--link" href="<?= e(url('admin/sales.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (!$sales): ?>
        <div class="state">
            <div class="state__icon"><?= icon('receipt', 'icon', 32) ?></div>
            <p class="state__title">No transactions found</p>
            <p class="state__text">Adjust your filters or process a sale in the POS.</p>
            <a class="btn btn--primary" href="<?= e(url('cashier/pos.php')) ?>"><?= icon('cart', 'icon', 18) ?> Open Point of Sale</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Date</th>
                        <?php if ($user['role'] === 'admin'): ?><th>Cashier</th><?php endif; ?>
                        <th class="num">Items</th>
                        <th>Payment</th>
                        <th class="num">Total</th>
                        <th>Status</th>
                        <th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $s):
                        $methodLabels = ['cash' => 'Cash', 'card' => 'Card', 'e_wallet' => 'E-Wallet'];
                    ?>
                        <tr>
                            <td class="mono"><?= e($s['receipt_number']) ?></td>
                            <td class="text-muted nowrap"><?= e(nice_date($s['created_at'], 'M j, Y g:i A')) ?></td>
                            <?php if ($user['role'] === 'admin'): ?><td><?= e($s['cashier_name']) ?></td><?php endif; ?>
                            <td class="num"><?= (int) $s['item_count'] ?></td>
                            <td><span class="badge badge--info"><?= e($methodLabels[$s['payment_method']] ?? 'â€”') ?></span></td>
                            <td class="num"><?= e(money((float) $s['total_amount'])) ?></td>
                            <td>
                                <?php if ($s['status'] === 'completed'): ?>
                                    <span class="badge badge--success"><span class="dot"></span>Completed</span>
                                <?php else: ?>
                                    <span class="badge badge--danger"><span class="dot"></span>Voided</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/sale_view.php?id=' . (int) $s['id'])) ?>"><?= icon('eye', 'icon', 15) ?> View</a>
                                <?php if ($user['role'] === 'admin' && $s['status'] === 'completed'): ?>
                                    <form method="post" action="<?= e(url('admin/sales.php')) ?>" class="inline-form" data-confirm="Void receipt <?= e($s['receipt_number']) ?>? Stock will be restored. This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="void">
                                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                        <button class="btn btn--danger-soft btn--sm" type="submit"><?= icon('x', 'icon', 15) ?> Void</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_links($pg) ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
