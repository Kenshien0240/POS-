<?php
/**
 * Receipt body. Expects $sale (sale + cashier + payment) and $items (sale_items).
 *
 * All product information is read from the `sale_items` snapshot columns
 * (product_name_snapshot, sku_snapshot, unit_price, line_total) so a receipt reprinted
 * later still shows the exact values captured at the time of sale, even if the
 * product has since been renamed, re-priced, or deactivated.
 */
$methodLabels = ['cash' => 'Cash', 'card' => 'Card', 'e_wallet' => 'E-Wallet'];
$method       = $methodLabels[$sale['payment_method'] ?? 'cash'] ?? 'Cash';
$isCash       = ($sale['payment_method'] ?? 'cash') === 'cash';
$isVoided     = ($sale['status'] ?? 'completed') === 'voided';

$itemCount = 0;
$unitCount = 0;
foreach ($items as $it) {
    $itemCount++;
    $unitCount += (int) $it['quantity'];
}
?>
<div class="receipt<?= $isVoided ? ' receipt--voided' : '' ?>" id="receipt">
    <div class="receipt__store">
        <h3><?= e(setting('store_name', 'Group 6 Retail Store')) ?></h3>
        <?php if (setting('store_address')): ?><p><?= e(setting('store_address')) ?></p><?php endif; ?>
        <?php if (setting('store_phone')): ?><p>Tel: <?= e(setting('store_phone')) ?></p><?php endif; ?>
        <?php if (setting('store_tax_id')): ?><p>TIN: <?= e(setting('store_tax_id')) ?></p><?php endif; ?>
    </div>

    <p class="receipt__title">SALES RECEIPT</p>
    <?php if ($isVoided): ?><p class="receipt__void">*** VOIDED ***</p><?php endif; ?>

    <div class="receipt__meta">
        <div><span>Receipt No.</span><span><?= e($sale['receipt_number']) ?></span></div>
        <div><span>Date</span><span><?= e(nice_date($sale['created_at'], 'M j, Y g:i A')) ?></span></div>
        <div><span>Cashier</span><span><?= e($sale['cashier_name']) ?> (#<?= (int) $sale['cashier_id'] ?>)</span></div>
    </div>

    <table class="receipt__items">
        <thead>
            <tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <div class="receipt__item-name"><?= e($item['product_name_snapshot']) ?></div>
                        <div class="receipt__item-sub"><?= e($item['sku_snapshot']) ?> &times; <?= e(money((float) $item['unit_price'])) ?></div>
                    </td>
                    <td class="num"><?= (int) $item['quantity'] ?></td>
                    <td class="num"><?= e(money((float) $item['line_total'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="receipt__totals">
        <div><span><?= $itemCount ?> item<?= $itemCount === 1 ? '' : 's' ?> (<?= $unitCount ?> unit<?= $unitCount === 1 ? '' : 's' ?>)</span><span></span></div>
        <div><span>Subtotal</span><span><?= e(money((float) $sale['subtotal'])) ?></span></div>
        <?php if ((float) $sale['discount_amount'] > 0): ?>
            <div><span>Discount</span><span>-<?= e(money((float) $sale['discount_amount'])) ?></span></div>
        <?php endif; ?>
        <?php if ((float) $sale['tax_amount'] > 0): ?>
            <div><span><?= e(setting('tax_label', 'VAT')) ?></span><span><?= e(money((float) $sale['tax_amount'])) ?></span></div>
        <?php endif; ?>
        <div class="receipt__grand"><span>TOTAL</span><span><?= e(money((float) $sale['total_amount'])) ?></span></div>

        <?php if ($isCash): ?>
            <div><span>Amount received</span><span><?= e(money((float) ($sale['tendered_amount'] ?? $sale['total_amount']))) ?></span></div>
            <div><span>Change</span><span><?= e(money((float) ($sale['change_amount'] ?? 0))) ?></span></div>
        <?php else: ?>
            <div><span>Paid (<?= e($method) ?>)</span><span><?= e(money((float) ($sale['tendered_amount'] ?? $sale['total_amount']))) ?></span></div>
            <?php if (!empty($sale['reference_no'])): ?>
                <div><span>Reference</span><span><?= e($sale['reference_no']) ?></span></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="receipt__footer">
        <p><?= e(setting('receipt_footer', 'Thank you for shopping with us!')) ?></p>
        <p class="receipt__fineprint">This serves as your official receipt. Please keep it for your records.</p>
        <p class="receipt__fineprint"><?= e(setting('store_name', 'Group 6 Retail Store')) ?> &middot; Point of Sale</p>
    </div>
</div>
