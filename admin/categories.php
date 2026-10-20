<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();
$pdo  = db();

// ---------------------------------------------------------------------------
// Handle actions (POST -> redirect -> GET)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();
    $action = post('action');

    if ($action === 'save') {
        $id          = (int) post('id', 0);
        $name        = post('name', '');
        $description = post('description', '');
        $isActive    = post('status') ? 1 : 0;
        $errors      = [];

        if ($name === '') {
            $errors[] = 'Category name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Category name must be 100 characters or fewer.';
        }
        if ($description !== '' && mb_strlen($description) > 255) {
            $errors[] = 'Description must be 255 characters or fewer.';
        }

        // Unique name check
        if (!$errors) {
            $chk = $pdo->prepare('SELECT id FROM categories WHERE name = ? AND id <> ? LIMIT 1');
            $chk->execute([$name, $id]);
            if ($chk->fetch()) {
                $errors[] = 'A category with that name already exists.';
            }
        }

        if ($errors) {
            foreach ($errors as $msg) {
                flash('danger', $msg);
            }
        } elseif ($id > 0) {
            $stmt = $pdo->prepare('UPDATE categories SET name = ?, description = ?, status = ? WHERE id = ?');
            $stmt->execute([$name, $description ?: null, $isActive, $id]);
            flash('success', 'Category updated.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO categories (name, description, status) VALUES (?, ?, ?)');
            $stmt->execute([$name, $description ?: null, $isActive]);
            flash('success', 'Category created.');
        }
    } elseif ($action === 'toggle') {
        $id = (int) post('id');
        $stmt = $pdo->prepare('UPDATE categories SET status = 1 - status WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', 'Category status updated.');
    }

    redirect('admin/categories.php');
}

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------
$search = get('q', '');
$params = [];
$where  = '';
if ($search !== '') {
    $where  = 'WHERE c.name LIKE ? OR c.description LIKE ?';
    $params = ['%' . $search . '%', '%' . $search . '%'];
}

$count = $pdo->prepare("SELECT COUNT(*) FROM categories c $where");
$count->execute($params);
$pg = paginate((int) $count->fetchColumn(), 15);

$sql = "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
        FROM categories c $where ORDER BY c.name LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();

$editItem = null;
if (($eid = (int) get('edit', 0)) > 0) {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$eid]);
    $editItem = $stmt->fetch() ?: null;
}

$_page_title    = 'Categories';
$_page_subtitle = 'Organise products into groups for easier browsing and reporting.';
$_active_nav    = 'categories';
$_page_actions  = '<button class="btn btn--primary btn--sm" data-modal-open="categoryModal">' . icon('plus', 'icon', 16) . ' New category</button>';

require __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" action="<?= e(url('admin/categories.php')) ?>">
        <div class="search-box">
            <span class="search-box__icon"><?= icon('search', 'icon', 18) ?></span>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search categoriesâ€¦" aria-label="Search categories">
        </div>
        <button class="btn btn--ghost" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn--link" href="<?= e(url('admin/categories.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (!$categories): ?>
        <div class="state">
            <div class="state__icon"><?= icon('tags', 'icon', 32) ?></div>
            <p class="state__title"><?= $search !== '' ? 'No matching categories' : 'No categories yet' ?></p>
            <p class="state__text"><?= $search !== '' ? 'Try a different search term.' : 'Create your first category to start organising products.' ?></p>
            <button class="btn btn--primary" data-modal-open="categoryModal"><?= icon('plus', 'icon', 18) ?> New category</button>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th class="num">Products</th>
                        <th>Status</th>
                        <th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td><span class="cell-title"><?= e($c['name']) ?></span></td>
                            <td class="text-muted"><?= e($c['description'] ?: 'â€”') ?></td>
                            <td class="num"><?= (int) $c['product_count'] ?></td>
                            <td>
                                <?php if ((int) $c['status'] === 1): ?>
                                    <span class="badge badge--success"><span class="dot"></span>Active</span>
                                <?php else: ?>
                                    <span class="badge badge--muted">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <a class="btn btn--ghost btn--sm" href="?edit=<?= (int) $c['id'] ?>"><?= icon('edit', 'icon', 15) ?> Edit</a>
                                <form method="post" action="<?= e(url('admin/categories.php')) ?>" class="inline-form" data-confirm="Change the status of â€œ<?= e($c['name']) ?>â€?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit"><?= icon('power', 'icon', 15) ?> <?= (int) $c['status'] === 1 ? 'Deactivate' : 'Activate' ?></button>
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

<div class="modal" id="categoryModal" <?= $editItem ? '' : 'hidden' ?>>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle">
        <form method="post" action="<?= e(url('admin/categories.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editItem['id'] ?? 0) ?>">
            <div class="modal__header">
                <h3 id="categoryModalTitle"><?= $editItem ? 'Edit category' : 'New category' ?></h3>
                <button type="button" class="btn btn--icon btn--ghost" data-modal-close aria-label="Close"><?= icon('x', 'icon', 18) ?></button>
            </div>
            <div class="modal__body form-grid">
                <label class="field">
                    <span class="field__label">Category name <span class="req">*</span></span>
                    <input class="input" type="text" name="name" value="<?= e($editItem['name'] ?? '') ?>" maxlength="100" required autofocus>
                </label>
                <label class="field">
                    <span class="field__label">Description</span>
                    <textarea class="textarea" name="description" maxlength="255" placeholder="Optional short description"><?= e($editItem['description'] ?? '') ?></textarea>
                </label>
                <label class="check">
                    <input type="checkbox" name="status" value="1" <?= (!$editItem || (int) $editItem['status'] === 1) ? 'checked' : '' ?>>
                    <span class="check__text"><strong>Active</strong><small>Inactive categories are hidden from the POS filters.</small></span>
                </label>
            </div>
            <div class="modal__footer">
                <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn--primary"><?= icon('check', 'icon', 16) ?> Save category</button>
            </div>
        </form>
    </div>
</div>

<script>
// Open the modal automatically when editing via ?edit=ID
if (new URLSearchParams(location.search).has('edit')) openModal('categoryModal');
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
