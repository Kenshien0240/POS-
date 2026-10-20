<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();
$pdo  = db();

$typeLabels = [
    'initial'    => ['Initial',    'muted'],
    'restock'    => ['Restock',    'success'],
    'sale'       => ['Sale',       'info'],
    'adjustment' => ['Adjustment', 'warning'],
];
$validTypes = array_keys($typeLabels);

// ---------------------------------------------------------------------------
// Record an authorized stock movement (restock or correction)
// ---------------------------------------------------------------------------
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();

    $productId = (int) post('product_id', 0);
    $type      = post('movement_type', 'restock');
    $quantity  = trim((string) post('quantity', ''));
    $reference = trim((string) post('reference', ''));
    $note      = trim((string) post('note', ''));

    if (!in_array($type, ['restock', 'adjustment'], true)) {
        $errors['movement_type'] = 'Choose a valid movement type.';
    }
    if ($quantity === '') {
        $errors['quantity'] = 'Enter a quantity.';
    } elseif (!preg_match('/^-?\d+$/', $quantity)) {
        $errors['quantity'] = 'Quantity must be a whole number.';
    } elseif ((int) $quantity === 0) {
        $errors['quantity'] = 'Quantity cannot be zero.';
    } elseif ((int) $quantity > 1000000 || (int) $quantity < -1000000) {
        $errors['quantity'] = 'Quantity is too large.';
    } elseif ($type === 'restock' && (int) $quantity < 0) {
        $errors['quantity'] = 'Restock quantity must be positive.';
    }
    if (mb_strlen($reference) > 100) {
        $errors['reference'] = 'Reference must be 100 characters or fewer.';
    }
    if (mb_strlen($note) > 255) {
        $errors['note'] = 'Notes must be 255 characters or fewer.';
    }

    $product = null;
    if ($productId <= 0) {
        $errors['product_id'] = 'Choose a product.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, sku FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            $errors['product_id'] = 'That product does not exist.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Lock the row so concurrent checkouts/restocks cannot interleave.
            $lock = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ? FOR UPDATE');
            $lock->execute([$productId]);
            $before = (int) $lock->fetchColumn();

            $change = $type === 'restock' ? abs((int) $quantity) : (int) $quantity;
            $after  = $before + $change;
            if ($after < 0) {
                $pdo->rollBack();
                $errors['quantity'] = 'Adjustment would make stock negative (current stock: ' . $before . ').';
            } else {
                $pdo->prepare('UPDATE products SET stock_quantity = ? WHERE id = ?')->execute([$after, $productId]);
                $pdo->prepare(
                    'INSERT INTO inventory_movements
                        (product_id, user_id, sale_id, movement_type, quantity_change, stock_before, stock_after, reference_type, reference_id, notes)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $productId,
                    (int) $user['id'],
                    $type,
                    $change,
                    $before,
                    $after,
                    $type,
                    $reference !== '' ? $reference : null,
                    $note !== '' ? $note : null,
                ]);
                $pdo->commit();

                $verb = $type === 'restock' ? 'Restocked' : 'Adjusted';
                flash('success', $verb . ' ' . $product['name'] . ' by ' . ($change > 0 ? '+' : '') . $change
                    . '. New stock: ' . $after . '.');
                redirect('admin/inventory.php');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_exception($e, 'Inventory movement failed');
            $errors['form'] = APP_DEBUG ? $e->getMessage() : 'Unable to record the stock movement.';
        }
    }
}

// ---------------------------------------------------------------------------
// Stock health summary
// ---------------------------------------------------------------------------
$stats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 1), 0) AS active,
        COALESCE(SUM(status = 1 AND stock_quantity = 0), 0) AS out_of_stock,
        COALESCE(SUM(status = 1 AND stock_quantity <= low_stock_threshold), 0) AS low_stock,
        COALESCE(SUM(CASE WHEN status = 1 THEN stock_quantity * price ELSE 0 END), 0) AS stock_value
     FROM products'
)->fetch();

$lowStock = $pdo->query(
    'SELECT id, sku, name, stock_quantity, low_stock_threshold
     FROM products
     WHERE status = 1 AND stock_quantity <= low_stock_threshold
     ORDER BY (CAST(stock_quantity AS SIGNED) - CAST(low_stock_threshold AS SIGNED)), name
     LIMIT 8'
)->fetchAll();

$productOptions = $pdo->query(
    'SELECT id, sku, name, stock_quantity, status FROM products ORDER BY status DESC, name'
)->fetchAll();

// ---------------------------------------------------------------------------
// Movement log + filters
// ---------------------------------------------------------------------------
$q    = get('q', '');
$type = get('type', '');

$where  = [];
$params = [];
if ($q !== '') {
    $where[]  = '(p.name LIKE ? OR p.sku LIKE ? OR m.reference_id LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($type, $validTypes, true)) {
    $where[]  = 'm.movement_type = ?';
    $params[] = $type;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare("SELECT COUNT(*) FROM inventory_movements m JOIN products p ON p.id = m.product_id $whereSql");
$count->execute($params);
$pg = paginate((int) $count->fetchColumn(), 20);

$sql = "SELECT m.*, p.name AS product_name, p.sku, u.name AS user_name
        FROM inventory_movements m
        JOIN products p ON p.id = m.product_id
        LEFT JOIN users u ON u.id = m.user_id
        $whereSql
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

$_page_title    = 'Inventory';
$_page_subtitle = 'Stock levels, authorized corrections and a full movement history.';
$_active_nav    = 'inventory';

require __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert--danger" role="alert">
        <span class="alert__icon"><?= icon('alert-circle', 'icon', 18) ?></span>
        <div class="alert__text">
            <?php foreach ($errors as $msg): ?><div><?= e($msg) ?></div><?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid--kpi">
    <div class="card kpi">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Active products</p>
                <p class="kpi__value"><?= number_format((int) $stats['active']) ?></p>
            </div>
            <span class="kpi__icon"><?= icon('package', 'icon', 20) ?></span>
        </div>
        <p class="kpi__meta"><?= number_format((int) $stats['total']) ?> in catalogue</p>
    </div>
    <div class="card kpi kpi--warning">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Low stock</p>
                <p class="kpi__value"><?= number_format((int) $stats['low_stock']) ?></p>
            </div>
            <span class="kpi__icon"><?= icon('alert', 'icon', 20) ?></span>
        </div>
        <p class="kpi__meta">At or below threshold</p>
    </div>
    <div class="card kpi">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Out of stock</p>
                <p class="kpi__value"><?= number_format((int) $stats['out_of_stock']) ?></p>
            </div>
            <span class="kpi__icon"><?= icon('x', 'icon', 20) ?></span>
        </div>
        <p class="kpi__meta">Cannot be sold</p>
    </div>
    <div class="card kpi kpi--info">
        <div class="kpi__top">
            <div>
                <p class="kpi__label">Stock value</p>
                <p class="kpi__value"><?= e(money((float) $stats['stock_value'])) ?></p>
            </div>
            <span class="kpi__icon"><?= icon('wallet', 'icon', 20) ?></span>
        </div>
        <p class="kpi__meta">Active products at cost price</p>
    </div>
</div>

<div class="grid grid--2" style="margin-top:1.25rem">
    <div class="card">
        <header class="card__header">
            <h2>Record stock movement</h2>
            <small>Authorized restocks and corrections</small>
        </header>
        <div class="card__body">
            <p class="field__hint" style="margin-bottom:1rem">
                Sales deduct stock automatically at checkout. Use this form to add received stock
                or correct a count; every entry is written to the movement log with your user ID.
            </p>
            <form method="post" action="<?= e(url('admin/inventory.php')) ?>" class="form-grid">
                <?= csrf_field() ?>
                <label class="field">
                    <span class="field__label">Product <span class="req">*</span></span>
                    <select class="select <?= isset($errors['product_id']) ? 'is-invalid' : '' ?>" name="product_id" required>
                        <option value="">Choose a productâ€¦</option>
                        <?php foreach ($productOptions as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (int) post('product_id', 0) === (int) $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['sku'] . ' â€” ' . $p['name']) ?>
                                (<?= (int) $p['stock_quantity'] ?> in stock<?= (int) $p['status'] === 1 ? '' : ', inactive' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-grid form-grid--2">
                    <label class="field">
                        <span class="field__label">Movement type <span class="req">*</span></span>
                        <select class="select" name="movement_type">
                            <option value="restock" <?= post('movement_type', 'restock') === 'restock' ? 'selected' : '' ?>>Restock (add stock)</option>
                            <option value="adjustment" <?= post('movement_type') === 'adjustment' ? 'selected' : '' ?>>Adjustment (correction)</option>
                        </select>
                    </label>
                    <label class="field">
                        <span class="field__label">Quantity <span class="req">*</span></span>
                        <input class="input <?= isset($errors['quantity']) ? 'is-invalid' : '' ?>" type="number" name="quantity"
                               value="<?= e(post('quantity', '')) ?>" step="1" placeholder="e.g. 24" required>
                        <span class="field__hint">Restock is positive; adjustments may be negative.</span>
                    </label>
                </div>
                <label class="field">
                    <span class="field__label">Reference</span>
                    <input class="input" type="text" name="reference" maxlength="100"
                           value="<?= e(post('reference', '')) ?>" placeholder="Supplier DR no., count sheet, ticketâ€¦">
                </label>
                <label class="field">
                    <span class="field__label">Notes</span>
                    <input class="input" type="text" name="note" maxlength="255"
                           value="<?= e(post('note', '')) ?>" placeholder="Reason or remarks">
                </label>
                <div class="toolbar" style="margin:0">
                    <span class="toolbar__spacer"></span>
                    <button class="btn btn--primary" type="submit"><?= icon('check', 'icon', 16) ?> Record movement</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <header class="card__header">
            <h2>Low-stock alerts</h2>
            <small><?= count($lowStock) ?> shown</small>
        </header>
        <div class="card__body card__body--flush">
            <?php if (!$lowStock): ?>
                <div class="state">
                    <div class="state__icon"><?= icon('check-circle', 'icon', 28) ?></div>
                    <p class="state__title">All stocked up</p>
                    <p class="state__text">No active products are at or below their threshold.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table--compact">
                        <thead>
                            <tr><th>Product</th><th class="num">Stock</th><th class="num">Threshold</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStock as $p): ?>
                                <tr>
                                    <td>
                                        <div class="cell-title"><?= e($p['name']) ?></div>
                                        <div class="cell-sub mono"><?= e($p['sku']) ?></div>
                                    </td>
                                    <td class="num">
                                        <?php if ((int) $p['stock_quantity'] === 0): ?>
                                            <span class="badge badge--danger">0</span>
                                        <?php else: ?>
                                            <span class="badge badge--warning"><?= (int) $p['stock_quantity'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num text-muted"><?= (int) $p['low_stock_threshold'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:1.25rem">
    <header class="card__header">
        <h2>Movement history</h2>
        <small><?= number_format($pg['total']) ?> movement<?= $pg['total'] === 1 ? '' : 's' ?></small>
    </header>
    <div class="card__body">
        <form method="get" action="<?= e(url('admin/inventory.php')) ?>" class="toolbar" style="margin-bottom:1rem">
            <div class="search-box">
                <span class="search-box__icon"><?= icon('search', 'icon', 18) ?></span>
                <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search product or referenceâ€¦" aria-label="Search movements">
            </div>
            <select class="select" name="type" data-autosubmit aria-label="Filter by movement type">
                <option value="">All movements</option>
                <?php foreach ($typeLabels as $key => [$label]): ?>
                    <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn--ghost" type="submit"><?= icon('filter', 'icon', 16) ?> Apply</button>
            <?php if ($q !== '' || $type !== ''): ?>
                <a class="btn btn--link" href="<?= e(url('admin/inventory.php')) ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>
    <?php if (!$movements): ?>
        <div class="state">
            <div class="state__icon"><?= icon('inbox', 'icon', 28) ?></div>
            <p class="state__title">No movements found</p>
            <p class="state__text">Stock changes will appear here as they happen.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th class="num">Change</th>
                        <th class="num">Stock</th>
                        <th>Reference / notes</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $m):
                        $meta = $typeLabels[$m['movement_type']] ?? [$m['movement_type'], 'muted'];
                        $delta = (int) $m['quantity_change'];
                    ?>
                        <tr>
                            <td class="text-muted text-sm"><?= e(nice_date($m['created_at'])) ?></td>
                            <td>
                                <div class="cell-title"><?= e($m['product_name']) ?></div>
                                <div class="cell-sub mono"><?= e($m['sku']) ?></div>
                            </td>
                            <td><span class="badge badge--<?= e($meta[1]) ?>"><?= e($meta[0]) ?></span></td>
                            <td class="num">
                                <strong style="color:<?= $delta < 0 ? 'var(--danger)' : 'var(--success)' ?>">
                                    <?= $delta > 0 ? '+' : '' ?><?= $delta ?>
                                </strong>
                            </td>
                            <td class="num text-muted"><?= (int) $m['stock_before'] ?> &rarr; <?= (int) $m['stock_after'] ?></td>
                            <td>
                                <?php if (!empty($m['reference_id'])): ?>
                                    <div class="cell-title mono"><?= e($m['reference_id']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($m['notes'])): ?>
                                    <div class="cell-sub"><?= e($m['notes']) ?></div>
                                <?php endif; ?>
                                <?php if (empty($m['reference_id']) && empty($m['notes'])): ?>
                                    <span class="text-muted">â€”</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted text-sm"><?= e($m['user_name'] ?? 'System') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_links($pg) ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
