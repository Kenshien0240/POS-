<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();
$pdo  = db();

$id      = (int) get('id', 0);
$editing = $id > 0;
$product = [
    'sku'        => '',
    'name'                => '',
    'description'         => '',
    'category_id'         => '',
    'price'               => '',
    'stock_quantity'      => '0',
    'low_stock_threshold' => setting('low_stock_default', '5'),
    'status'           => 1,
];

if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash('danger', 'Product not found.');
        redirect('admin/products.php');
    }
    $product = $found;
}

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();

    $product['sku']        = strtoupper(post('sku', ''));
    $product['name']                = post('name', '');
    $product['description']         = post('description', '');
    $product['category_id']         = post('category_id', '');
    $product['price']               = post('price', '');
    $product['low_stock_threshold'] = post('low_stock_threshold', '5');
    $product['status']           = post('status') ? 1 : 0;
    if (!$editing) {
        $product['stock_quantity'] = post('stock_quantity', '0');
    }

    // Validation -----------------------------------------------------------
    if ($product['name'] === '') {
        $errors['name'] = 'Product name is required.';
    } elseif (mb_strlen($product['name']) > 150) {
        $errors['name'] = 'Product name must be 150 characters or fewer.';
    }

    if ($product['sku'] === '') {
        $errors['sku'] = 'Product code (SKU) is required.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9\-_.]{0,49}$/', $product['sku'])) {
        $errors['sku'] = 'Use letters, numbers, dashes, underscores or dots only (max 50).';
    } else {
        $chk = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1');
        $chk->execute([$product['sku'], $id]);
        if ($chk->fetch()) {
            $errors['sku'] = 'That product code is already in use.';
        }
    }

    if ($product['category_id'] !== '' && !is_whole_number($product['category_id'])) {
        $errors['category_id'] = 'Please choose a valid category.';
    }

    if ($product['price'] === '') {
        $errors['price'] = 'Selling price is required.';
    } elseif (!is_decimal($product['price']) || (float) $product['price'] > 99999999.99) {
        $errors['price'] = 'Enter a valid price with up to 2 decimal places.';
    }

    if (!is_whole_number($product['low_stock_threshold'])) {
        $errors['low_stock_threshold'] = 'Low-stock threshold must be a whole number.';
    }

    if (mb_strlen((string) $product['description']) > 500) {
        $errors['description'] = 'Description must be 500 characters or fewer.';
    }

    if (!$editing) {
        if (!is_whole_number($product['stock_quantity'])) {
            $errors['stock_quantity'] = 'Opening stock must be a whole number (0 or more).';
        } elseif ((int) $product['stock_quantity'] > 1000000) {
            $errors['stock_quantity'] = 'Opening stock is too large.';
        }
    } else {
        $adjustment = post('stock_adjustment', '');
        if ($adjustment !== '' && !preg_match('/^-?\d+$/', (string) $adjustment)) {
            $errors['stock_adjustment'] = 'Adjustment must be a whole number (may be negative).';
        } elseif ($adjustment !== '' && (int) $found['stock_quantity'] + (int) $adjustment < 0) {
            $errors['stock_adjustment'] = 'Adjustment would make stock negative.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $categoryId = $product['category_id'] === '' ? null : (int) $product['category_id'];
            $price      = from_cents(to_cents($product['price']));

            if ($editing) {
                // Lock the row so a concurrent sale cannot overwrite our change.
                $lock = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                $before = (int) $lock->fetchColumn();

                $adjustment    = post('stock_adjustment', '');
                $hasAdjustment = $adjustment !== '' && (int) $adjustment !== 0;

                if ($hasAdjustment && $before + (int) $adjustment < 0) {
                    $pdo->rollBack();
                    $errors['stock_adjustment'] = 'Adjustment would make stock negative (current stock: ' . $before . ').';
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE products SET sku = ?, name = ?, description = ?, category_id = ?,
                         price = ?, low_stock_threshold = ?, status = ? WHERE id = ?'
                    );
                    $stmt->execute([
                        $product['sku'], $product['name'], $product['description'] ?: null,
                        $categoryId, $price, (int) $product['low_stock_threshold'], $product['status'], $id,
                    ]);

                    if ($hasAdjustment) {
                        $after    = $before + (int) $adjustment;
                        $moveType = (int) $adjustment > 0 ? 'restock' : 'adjustment';
                        $pdo->prepare('UPDATE products SET stock_quantity = ? WHERE id = ?')->execute([$after, $id]);
                        $pdo->prepare(
                            'INSERT INTO inventory_movements
                                (product_id, user_id, movement_type, quantity_change, stock_before, stock_after, reference_type, reference_id, notes)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        )->execute([
                            $id,
                            (int) $user['id'],
                            $moveType,
                            (int) $adjustment,
                            $before,
                            $after,
                            $moveType,
                            'PRODUCT-EDIT',
                            'Manual stock adjustment while editing product',
                        ]);
                    }

                    flash('success', 'Product updated.');
                }
            } else {
                $stock = (int) $product['stock_quantity'];
                $stmt = $pdo->prepare(
                    'INSERT INTO products (sku, name, description, category_id, price, stock_quantity, low_stock_threshold, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $product['sku'], $product['name'], $product['description'] ?: null,
                    $categoryId, $price, $stock, (int) $product['low_stock_threshold'], $product['status'],
                ]);
                $newId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO inventory_movements
                        (product_id, user_id, movement_type, quantity_change, stock_before, stock_after, reference_type, reference_id, notes)
                     VALUES (?, ?, "initial", ?, 0, ?, "opening", "OPENING", ?)'
                )->execute([$newId, (int) $user['id'], $stock, $stock, 'Opening stock']);

                flash('success', 'Product created.');
            }

            if ($errors) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->commit();
                redirect('admin/products.php');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_exception($e, 'Product save failed');
            $errors['form'] = APP_DEBUG ? $e->getMessage() : 'Unable to save the product.';
        }
    }
}

$categories = $pdo->query('SELECT id, name, status FROM categories ORDER BY name')->fetchAll();

$_page_title    = $editing ? 'Edit product' : 'Add product';
$_page_subtitle = $editing ? 'Update details, pricing and stock.' : 'Create a new item for the catalogue.';
$_active_nav    = 'products';
$_page_actions  = '<a class="btn btn--ghost btn--sm" href="' . e(url('admin/products.php')) . '">' . icon('arrow-left', 'icon', 16) . ' Back to products</a>';

require __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert--danger"><span class="alert__icon"><?= icon('alert', 'icon', 18) ?></span><span class="alert__text"><?= e($errors['form']) ?></span></div>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/product_form.php' . ($editing ? '?id=' . $id : ''))) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="grid grid--dash">
        <div class="section-stack">
            <div class="card">
                <header class="card__header"><h2>Product details</h2></header>
                <div class="card__body form-grid form-grid--2">
                    <label class="field" style="grid-column: span 2">
                        <span class="field__label">Product name <span class="req">*</span></span>
                        <input class="input <?= isset($errors['name']) ? 'is-invalid' : '' ?>" type="text" name="name" value="<?= e($product['name']) ?>" maxlength="150" required>
                        <?php if (isset($errors['name'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['name']) ?></span><?php endif; ?>
                    </label>

                    <label class="field">
                        <span class="field__label">Product code / SKU <span class="req">*</span></span>
                        <input class="input mono <?= isset($errors['sku']) ? 'is-invalid' : '' ?>" type="text" name="sku" value="<?= e($product['sku']) ?>" maxlength="50" placeholder="e.g. BEV-001" required>
                        <?php if (isset($errors['sku'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['sku']) ?></span><?php else: ?><span class="field__hint">Also used for barcode-style search in the POS.</span><?php endif; ?>
                    </label>

                    <label class="field">
                        <span class="field__label">Category</span>
                        <select class="select <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>" name="category_id">
                            <option value="">Uncategorised</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= (int) $product['category_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?><?= (int) $c['status'] === 1 ? '' : ' (inactive)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['category_id'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['category_id']) ?></span><?php endif; ?>
                    </label>

                    <label class="field" style="grid-column: span 2">
                        <span class="field__label">Description</span>
                        <textarea class="textarea <?= isset($errors['description']) ? 'is-invalid' : '' ?>" name="description" maxlength="500" placeholder="Optional product notes"><?= e($product['description']) ?></textarea>
                        <?php if (isset($errors['description'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['description']) ?></span><?php endif; ?>
                    </label>
                </div>
            </div>

            <div class="card">
                <header class="card__header"><h2>Pricing &amp; stock</h2></header>
                <div class="card__body form-grid form-grid--2">
                    <label class="field">
                        <span class="field__label">Selling price <span class="req">*</span></span>
                        <span class="input-group input-group--lead">
                            <span class="input-group__addon"><?= e(setting('currency_symbol', 'P')) ?></span>
                            <input class="input <?= isset($errors['price']) ? 'is-invalid' : '' ?>" type="text" inputmode="decimal" name="price" value="<?= e($product['price']) ?>" required>
                        </span>
                        <?php if (isset($errors['price'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['price']) ?></span><?php endif; ?>
                    </label>

                    <label class="field">
                        <span class="field__label">Low-stock threshold <span class="req">*</span></span>
                        <input class="input <?= isset($errors['low_stock_threshold']) ? 'is-invalid' : '' ?>" type="number" name="low_stock_threshold" value="<?= e($product['low_stock_threshold']) ?>" min="0" step="1" required>
                        <?php if (isset($errors['low_stock_threshold'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['low_stock_threshold']) ?></span><?php else: ?><span class="field__hint">Alert when stock falls to this level.</span><?php endif; ?>
                    </label>

                    <?php if (!$editing): ?>
                        <label class="field">
                            <span class="field__label">Opening stock <span class="req">*</span></span>
                            <input class="input <?= isset($errors['stock_quantity']) ? 'is-invalid' : '' ?>" type="number" name="stock_quantity" value="<?= e(post('stock_quantity', $product['stock_quantity'])) ?>" min="0" step="1" required>
                            <?php if (isset($errors['stock_quantity'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['stock_quantity']) ?></span><?php endif; ?>
                        </label>
                    <?php else: ?>
                        <div class="field">
                            <span class="field__label">Current stock</span>
                            <input class="input" type="text" value="<?= (int) $product['stock_quantity'] ?>" readonly>
                            <span class="field__hint">Stock changes automatically when sales are made.</span>
                        </div>
                        <label class="field">
                            <span class="field__label">Stock adjustment</span>
                            <input class="input <?= isset($errors['stock_adjustment']) ? 'is-invalid' : '' ?>" type="number" name="stock_adjustment" value="<?= e(post('stock_adjustment', '')) ?>" step="1" placeholder="e.g. 20 to restock, -3 for damage">
                            <?php if (isset($errors['stock_adjustment'])): ?><span class="field__error"><?= icon('alert-circle', 'icon', 14) ?><?= e($errors['stock_adjustment']) ?></span><?php else: ?><span class="field__hint">Positive to add stock, negative to reduce.</span><?php endif; ?>
                        </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="section-stack">
            <div class="card">
                <header class="card__header"><h2>Visibility</h2></header>
                <div class="card__body">
                    <label class="check">
                        <input type="checkbox" name="status" value="1" <?= (int) $product['status'] === 1 ? 'checked' : '' ?>>
                        <span class="check__text">
                            <strong>Active</strong>
                            <small>Active products appear in the POS and can be sold.</small>
                        </span>
                    </label>
                </div>
            </div>

            <div class="card">
                <div class="card__body stack gap">
                    <button type="submit" class="btn btn--primary btn--lg btn--block"><?= icon('check', 'icon', 18) ?> <?= $editing ? 'Save changes' : 'Create product' ?></button>
                    <a class="btn btn--ghost btn--block" href="<?= e(url('admin/products.php')) ?>">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
