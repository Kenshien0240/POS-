<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user    = require_login();
$pdo     = db();
$config  = sales_config();

$categories = $pdo->query(
    'SELECT c.id, c.name, COUNT(p.id) AS product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id AND p.status = 1
     WHERE c.status = 1
     GROUP BY c.id, c.name
     ORDER BY c.name'
)->fetchAll();

$_page_title    = 'Point of Sale';
$_page_subtitle = 'Search, add to cart and complete the sale.';
$_active_nav    = 'pos';
$_body_class    = 'pos-page';
$_page_styles   = ['css/pos.css', 'css/receipt.css'];
$_page_scripts  = '<script src="' . e(asset('js/pos.js')) . '"></script>';

$posConfig = [
    'currency'          => setting('currency_symbol', 'P'),
    'apiProducts'       => url('cashier/product_search.php'),
    'apiCheckout'       => url('cashier/checkout.php'),
    'receiptUrl'        => url('cashier/receipt.php'),
    'csrf'              => csrf_token(),
    'taxEnabled'        => $config['tax_enabled'],
    'taxRate'           => $config['tax_rate'],
    'taxLabel'          => $config['tax_label'],
    'discountEnabled'   => $config['discount_enabled'],
    'discountDefault'   => $config['discount_default'],
    'discountMax'       => $config['discount_max'],
];

require __DIR__ . '/../includes/header.php';
?>

<div class="pos">
    <!-- Products --------------------------------------------------------- -->
    <section class="pos-panel">
        <div class="pos-search">
            <div class="search-box">
                <span class="search-box__icon"><?= icon('search', 'icon', 18) ?></span>
                <input class="input" type="search" id="productSearch" placeholder="Scan barcode or search by name, SKU…" autocomplete="off" autofocus aria-label="Search products">
            </div>
            <button class="btn btn--ghost" type="button" id="searchClear" aria-label="Clear search"><?= icon('x', 'icon', 16) ?></button>
        </div>

        <div class="pos-filters" id="posFilters" role="group" aria-label="Filter by category">
            <button type="button" class="chip is-active" data-category="0">All products</button>
            <?php foreach ($categories as $c): ?>
                <button type="button" class="chip" data-category="<?= (int) $c['id'] ?>">
                    <?= e($c['name']) ?> <span class="text-muted"><?= (int) $c['product_count'] ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="product-grid" id="productGrid" aria-live="polite">
            <div class="loading-overlay" style="grid-column: 1 / -1"><span class="spinner"></span> Loading products…</div>
        </div>
    </section>

    <!-- Cart ------------------------------------------------------------- -->
    <aside class="pos-panel cart-panel" aria-label="Shopping cart">
        <div class="card__header">
            <div class="card__title-group">
                <h2>Current sale</h2>
                <small><span id="cartCount">0</span> item(s)</small>
            </div>
            <div class="card__actions">
                <button class="btn btn--ghost btn--sm" type="button" id="clearCart" disabled><?= icon('trash', 'icon', 15) ?> Clear</button>
            </div>
        </div>

        <div class="cart-list" id="cartList">
            <div class="state">
                <div class="state__icon"><?= icon('cart', 'icon', 30) ?></div>
                <p class="state__title">Cart is empty</p>
                <p class="state__text">Search or tap a product to add it to the sale.</p>
            </div>
        </div>

        <div class="totals" aria-live="polite">
            <div class="totals__row"><span>Subtotal</span><span id="totalSubtotal"><?= e(money(0)) ?></span></div>
            <?php if ($config['discount_enabled']): ?>
                <div class="totals__row totals__row--muted" id="discountRow">
                    <span>
                        Discount
                        <span class="input-group" style="display:inline-flex;margin-left:.4rem;max-width:120px">
                            <input class="input" style="padding:.2rem .4rem;font-size:.8rem" type="number" id="discountPercent" min="0" max="<?= (int) $config['discount_max'] ?>" step="1" value="<?= (int) $config['discount_default'] ?>" aria-label="Discount percent">
                            <span class="input-group__addon" style="padding:0 .5rem">%</span>
                        </span>
                    </span>
                    <span id="totalDiscount">-<?= e(money(0)) ?></span>
                </div>
            <?php else: ?>
                <input type="hidden" id="discountPercent" value="0">
            <?php endif; ?>
            <?php if ($config['tax_enabled']): ?>
                <div class="totals__row totals__row--muted"><span><?= e($config['tax_label']) ?> (<?= (float) $config['tax_rate'] ?>%)</span><span id="totalTax"><?= e(money(0)) ?></span></div>
            <?php endif; ?>
            <hr class="totals__rule">
            <div class="totals__grand"><span>Total</span><span id="totalGrand"><?= e(money(0)) ?></span></div>
        </div>

        <div class="pos-actions">
            <button class="btn btn--primary btn--lg btn--block" type="button" id="checkoutBtn" disabled>
                <?= icon('wallet', 'icon', 20) ?> <span id="checkoutLabel">Charge <?= e(money(0)) ?></span>
            </button>
        </div>
    </aside>
</div>

<!-- Payment modal -------------------------------------------------------- -->
<div class="modal" id="paymentModal" hidden>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="paymentTitle">
        <div class="modal__header">
            <h3 id="paymentTitle">Take payment</h3>
            <button type="button" class="btn btn--icon btn--ghost" data-modal-close aria-label="Close"><?= icon('x', 'icon', 18) ?></button>
        </div>
        <div class="modal__body stack gap">
            <div class="totals" style="padding:0">
                <div class="totals__row"><span>Amount due</span><span id="payTotal"><?= e(money(0)) ?></span></div>
            </div>

            <div class="field">
                <span class="field__label">Payment method</span>
                <div class="pay-methods" id="payMethods" role="radiogroup" aria-label="Payment method">
                    <button type="button" class="pay-method is-active" data-method="cash" role="radio" aria-checked="true"><?= icon('wallet', 'icon', 20) ?> Cash</button>
                    <button type="button" class="pay-method" data-method="card" role="radio" aria-checked="false"><?= icon('card', 'icon', 20) ?> Card</button>
                    <button type="button" class="pay-method" data-method="e_wallet" role="radio" aria-checked="false"><?= icon('receipt', 'icon', 20) ?> E-Wallet</button>
                </div>
            </div>

            <div class="field" id="tenderedField">
                <span class="field__label">Amount tendered</span>
                <span class="input-group input-group--lead">
                    <span class="input-group__addon"><?= e(setting('currency_symbol', 'P')) ?></span>
                    <input class="input" type="number" id="tendered" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                </span>
                <div class="flex gap-xs wrap mt-1" id="quickCash">
                    <button type="button" class="chip" data-cash="exact">Exact</button>
                </div>
            </div>

            <div class="field hidden" id="referenceField">
                <span class="field__label">Reference number</span>
                <input class="input" type="text" id="paymentReference" maxlength="100" placeholder="Card / e-wallet reference (optional)">
            </div>

            <div class="totals" style="padding:0">
                <div class="totals__row"><span>Change</span><span id="payChange"><?= e(money(0)) ?></span></div>
            </div>

            <div class="alert alert--danger hidden" id="payError"><span class="alert__icon"><?= icon('alert', 'icon', 18) ?></span><span class="alert__text"></span></div>
        </div>
        <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
            <button type="button" class="btn btn--primary btn--lg" id="confirmPayment"><?= icon('check', 'icon', 18) ?> Complete sale</button>
        </div>
    </div>
</div>

<!-- Receipt modal -------------------------------------------------------- -->
<div class="modal" id="receiptModal" hidden>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="receiptTitle">
        <div class="modal__header">
            <h3 id="receiptTitle">Receipt</h3>
            <button type="button" class="btn btn--icon btn--ghost" data-modal-close aria-label="Close"><?= icon('x', 'icon', 18) ?></button>
        </div>
        <div class="modal__body" id="receiptContent"></div>
        <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>Close</button>
            <button type="button" class="btn btn--ghost" id="printReceipt"><?= icon('printer', 'icon', 16) ?> Print</button>
            <button type="button" class="btn btn--primary" id="newSale"><?= icon('plus', 'icon', 16) ?> New sale</button>
        </div>
    </div>
</div>

<script>window.POS_CONFIG = <?= json_encode($posConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
