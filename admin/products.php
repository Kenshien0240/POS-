<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();
$pdo  = db();

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();
    if (post('action') === 'toggle') {
        $id = (int) post('id');
        $stmt = $pdo->prepare('UPDATE products SET status = 1 - status WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', 'Product status updated.');
    }

    // Rebuild only the known list filters instead of echoing the raw query back.
    $keep = [];
    parse_str((string) get('return', ''), $parsed);
    foreach (['q', 'category', 'status', 'stock', 'page'] as $key) {
        if (isset($parsed[$key]) && is_scalar($parsed[$key])) {
            $keep[$key] = (string) $parsed[$key];
        }
    }
    $qs = http_build_query($keep);
    redirect('admin/products.php' . ($qs !== '' ? '?' . $qs : ''));
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$search     = get('q', '');
$categoryId = (int) get('category', 0);
$status     = get('status', 'active');
$stock      = get('stock', '');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($categoryId > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $categoryId;
}
if ($status === 'active') {
    $where[] = 'p.status = 1';
} elseif ($status === 'inactive') {
    $where[] = 'p.status = 0';
}
if ($stock === 'low') {
    $where[] = 'p.status = 1 AND p.stock_quantity <= p.low_stock_threshold';
} elseif ($stock === 'out') {
    $where[] = 'p.stock_quantity = 0';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSql");
$count->execute($params);
$pg = paginate((int) $count->fetchColumn(), 15);

$sql = "SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        $whereSql
        ORDER BY p.status DESC, p.name
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = 1 AND stock_quantity <= low_stock_threshold')->fetchColumn();
$outOfStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = 1 AND stock_quantity = 0')->fetchColumn();

$returnQuery = http_build_query(array_filter([
    'q' => $search, 'category' => $categoryId ?: '', 'status' => $status, 'stock' => $stock, 'page' => $pg['page'],
]));

$_page_title    = 'Products';
$_page_subtitle = 'Manage your catalogue, pricing and stock levels.';
$_active_nav    = 'products';
$_page_actions  = '<a class="btn btn--primary btn--sm" href="' . e(url('admin/product_form.php')) . '">' . icon('plus', 'icon', 16) . ' Add product</a>';

require __DIR__ . '/../includes/header.php';
?>

<?php if ($lowStockCount > 0): ?>
    <div class="alert alert--warning" role="status">
        <span class="alert__icon"><?= icon('alert-circle', 'icon', 18) ?></span>
        <div class="alert__text">
            <strong><?= number_format($lowStockCount) ?> active product<?= $lowStockCount === 1 ? '' : 's' ?></strong>
            at or below the low-stock threshold<?php if ($outOfStockCount > 0): ?> &middot; <?= number_format($outOfStockCount) ?> out of stock<?php endif; ?>.
            <a href="<?= e(url('admin/products.php?stock=low')) ?>">View low-stock items</a> &middot;
            <a href="<?= e(url('admin/inventory.php')) ?>">Manage inventory</a>
        </div>
    </div>
<?php endif; ?>

<div class="toolbar">
    <form method="get" action="<?= e(url('admin/products.php')) ?>">
        <div class="search-box">
            <span class="search-box__icon"><?= icon('search', 'icon', 18) ?></span>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search name or SKUâ€¦" aria-label="Search products">
        </div>
        <select class="select" name="category" data-autosubmit aria-label="Filter by category">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="status" data-autosubmit aria-label="Filter by status">
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
        </select>
        <select class="select" name="stock" data-autosubmit aria-label="Filter by stock">
            <option value="" <?= $stock === '' ? 'selected' : '' ?>>Any stock</option>
            <option value="low" <?= $stock === 'low' ? 'selected' : '' ?>>Low stock</option>
            <option value="out" <?= $stock === 'out' ? 'selected' : '' ?>>Out of stock</option>
        </select>
        <button class="btn btn--ghost" type="submit"><?= icon('filter', 'icon', 16) ?> Apply</button>
        <?php if ($search !== '' || $categoryId || $stock !== '' || $status !== 'active'): ?>
            <a class="btn btn--link" href="<?= e(url('admin/products.php')) ?>">Reset</a>
        <?php endif; ?>
    </form>
    <span class="toolbar__spacer"></span>
    <span class="text-muted text-sm"><?= number_format($pg['total']) ?> product<?= $pg['total'] === 1 ? '' : 's' ?></span>
</div>

<div class="card">
    <?php if (!$products): ?>
        <div class="state">
            <div class="state__icon"><?= icon('package', 'icon', 32) ?></div>
            <p class="state__title">No products found</p>
            <p class="state__text">Adjust your filters or add a new product to the catalogue.</p>
            <a class="btn btn--primary" href="<?= e(url('admin/product_form.php')) ?>"><?= icon('plus', 'icon', 18) ?> Add product</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="num">Price</th>
                        <th class="num">Stock</th>
                        <th>Status</th>
                        <th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p):
                        $low = (int) $p['stock_quantity'] <= (int) $p['low_stock_threshold'];
                        $out = (int) $p['stock_quantity'] === 0;
                    ?>
                        <tr>
                            <td>
                                <div class="cell-title"><?= e($p['name']) ?></div>
                                <div class="cell-sub mono"><?= e($p['sku']) ?></div>
                            </td>
                            <td class="text-muted"><?= e($p['category_name'] ?? 'â€”') ?></td>
                            <td class="num"><?= e(money((float) $p['price'])) ?></td>
                            <td class="num">
                                <?php if ($out): ?>
                                    <span class="badge badge--danger">0</span>
                                <?php elseif ($low): ?>
                                    <span class="badge badge--warning"><?= (int) $p['stock_quantity'] ?></span>
                                <?php else: ?>
                                    <?= (int) $p['stock_quantity'] ?>
                                <?php endif; ?>
                                <div class="cell-sub">threshold <?= (int) $p['low_stock_threshold'] ?></div>
                            </td>
                            <td>
                                <?php if ((int) $p['status'] === 1): ?>
                                    <span class="badge badge--success"><span class="dot"></span>Active</span>
                                <?php else: ?>
                                    <span class="badge badge--muted">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a class="btn btn--ghost btn--sm" href="<?= e(url('admin/product_form.php?id=' . (int) $p['id'])) ?>"><?= icon('edit', 'icon', 15) ?> Edit</a>
                                <form method="post" action="<?= e(url('admin/products.php?return=' . rawurlencode($returnQuery))) ?>" class="inline-form" data-confirm="<?= (int) $p['status'] === 1 ? 'Deactivate' : 'Activate' ?> â€œ<?= e($p['name']) ?>â€?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit"><?= icon('power', 'icon', 15) ?> <?= (int) $p['status'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                                </form>
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
