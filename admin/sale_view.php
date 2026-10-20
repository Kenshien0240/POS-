<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_login();
$id   = (int) get('id', 0);

// Voiding from the detail page (administrators only).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();
    require_admin();
    $result = void_sale((int) post('id'), (int) $user['id']);
    flash($result['ok'] ? 'success' : 'danger', $result['message']);
    redirect('admin/sale_view.php?id=' . (int) post('id'));
}

$sale = get_sale($id);
if (!$sale || !can_view_sale($sale)) {
    flash('danger', 'Transaction not found.');
    redirect('admin/sales.php');
}
$items = get_sale_items($id);

$methodLabels = ['cash' => 'Cash', 'card' => 'Card', 'e_wallet' => 'E-Wallet'];

$_page_title    = 'Receipt ' . $sale['receipt_number'];
$_page_subtitle = nice_date($sale['created_at']);
$_active_nav    = 'sales';
$_page_actions  = '<a class="btn btn--ghost btn--sm" href="' . e(url('admin/sales.php')) . '">' . icon('arrow-left', 'icon', 16) . ' Back</a>'
    . '<button class="btn btn--primary btn--sm" type="button" onclick="window.print()">' . icon('printer', 'icon', 16) . ' Print</button>';

require __DIR__ . '/../includes/header.php';
?>

<div class="grid grid--dash">
    <div class="section-stack">
        <div class="card">
            <header class="card__header">
                <div class="card__title-group">
                    <h2>Transaction details</h2>
                    <small><?= e($sale['receipt_number']) ?></small>
                </div>
                <div class="card__actions">
                    <?php if ($sale['status'] === 'completed'): ?>
                        <span class="badge badge--success"><span class="dot"></span>Completed</span>
                    <?php else: ?>
                        <span class="badge badge--danger"><span class="dot"></span>Voided</span>
                    <?php endif; ?>
                </div>
            </header>
            <div class="card__body">
                <div class="grid grid--3">
                    <div><p class="text-muted text-xs">Cashier</p><p class="strong"><?= e($sale['cashier_name']) ?></p></div>
                    <div><p class="text-muted text-xs">Date &amp; time</p><p class="strong"><?= e(nice_date($sale['created_at'])) ?></p></div>
                    <div><p class="text-muted text-xs">Payment method</p><p class="strong"><?= e($methodLabels[$sale['payment_method']] ?? 'â€”') ?></p></div>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Item</th><th class="num">Unit price</th><th class="num">Qty</th><th class="num">Line total</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <div class="cell-title"><?= e($item['product_name_snapshot']) ?></div>
                                    <div class="cell-sub mono"><?= e($item['sku_snapshot']) ?></div>
                                </td>
                                <td class="num"><?= e(money((float) $item['unit_price'])) ?></td>
                                <td class="num"><?= (int) $item['quantity'] ?></td>
                                <td class="num"><?= e(money((float) $item['line_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($user['role'] === 'admin' && $sale['status'] === 'completed'): ?>
            <div class="card">
                <div class="card__body flex justify-between items-center wrap gap">
                    <div>
                        <p class="strong">Void this transaction?</p>
                        <p class="text-muted text-sm">Voiding restores the sold quantities to inventory and marks the sale as voided.</p>
                    </div>
                    <form method="post" action="<?= e(url('admin/sale_view.php?id=' . (int) $sale['id'])) ?>" data-confirm="Void receipt <?= e($sale['receipt_number']) ?>? Stock will be restored.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $sale['id'] ?>">
                        <button class="btn btn--danger" type="submit"><?= icon('x', 'icon', 16) ?> Void sale</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="section-stack">
        <div class="card">
            <header class="card__header"><h2>Summary</h2></header>
            <div class="card__body">
                <div class="totals" style="padding:0">
                    <div class="totals__row"><span>Subtotal</span><span><?= e(money((float) $sale['subtotal'])) ?></span></div>
                    <?php if ((float) $sale['discount_amount'] > 0): ?>
                        <div class="totals__row"><span>Discount</span><span>-<?= e(money((float) $sale['discount_amount'])) ?></span></div>
                    <?php endif; ?>
                    <?php if ((float) $sale['tax_amount'] > 0): ?>
                        <div class="totals__row"><span><?= e(setting('tax_label', 'VAT')) ?></span><span><?= e(money((float) $sale['tax_amount'])) ?></span></div>
                    <?php endif; ?>
                    <hr class="totals__rule">
                    <div class="totals__grand"><span>Total</span><span><?= e(money((float) $sale['total_amount'])) ?></span></div>
                    <div class="totals__row"><span>Tendered</span><span><?= e(money((float) ($sale['tendered_amount'] ?? $sale['total_amount']))) ?></span></div>
                    <div class="totals__row"><span>Change</span><span><?= e(money((float) ($sale['change_amount'] ?? 0))) ?></span></div>
                    <?php if (!empty($sale['reference_no'])): ?>
                        <div class="totals__row"><span>Reference</span><span><?= e($sale['reference_no']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card no-print">
            <div class="card__body stack gap">
                <a class="btn btn--ghost btn--block" href="<?= e(url('cashier/receipt.php?id=' . (int) $sale['id'])) ?>"><?= icon('receipt', 'icon', 16) ?> Open printable receipt</a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
