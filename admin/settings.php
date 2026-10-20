<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_admin();

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf_or_fail();

    $values = [
        'store_name'               => post('store_name', ''),
        'store_address'            => post('store_address', ''),
        'store_phone'              => post('store_phone', ''),
        'store_tax_id'             => post('store_tax_id', ''),
        'currency_code'            => post('currency_code', 'PHP'),
        'currency_symbol'          => post('currency_symbol', 'P'),
        'tax_enabled'              => post('tax_enabled') ? '1' : '0',
        'tax_rate'                 => post('tax_rate', '0'),
        'tax_label'                => post('tax_label', 'VAT'),
        'discount_enabled'         => post('discount_enabled') ? '1' : '0',
        'default_discount_percent' => post('default_discount_percent', '0'),
        'max_discount_percent'     => post('max_discount_percent', '100'),
        'low_stock_default'        => post('low_stock_default', '5'),
        'receipt_footer'           => post('receipt_footer', ''),
    ];

    if ($values['store_name'] === '') {
        $errors[] = 'Store name is required.';
    } elseif (mb_strlen($values['store_name']) > 100) {
        $errors[] = 'Store name must be 100 characters or fewer.';
    }
    if (mb_strlen($values['store_address']) > 200) {
        $errors[] = 'Address must be 200 characters or fewer.';
    }
    if (mb_strlen($values['store_phone']) > 40) {
        $errors[] = 'Phone must be 40 characters or fewer.';
    }
    if (mb_strlen($values['store_tax_id']) > 40) {
        $errors[] = 'Tax ID must be 40 characters or fewer.';
    }
    if (mb_strlen($values['currency_code']) > 8) {
        $errors[] = 'Currency code must be 8 characters or fewer.';
    }
    if (mb_strlen($values['currency_symbol']) > 4) {
        $errors[] = 'Currency symbol must be 4 characters or fewer.';
    }
    if (mb_strlen($values['tax_label']) > 20) {
        $errors[] = 'Tax label must be 20 characters or fewer.';
    }
    if (mb_strlen($values['receipt_footer']) > 160) {
        $errors[] = 'Receipt footer must be 160 characters or fewer.';
    }
    if (!is_numeric($values['tax_rate']) || (float) $values['tax_rate'] < 0 || (float) $values['tax_rate'] > 100) {
        $errors[] = 'Tax rate must be a number between 0 and 100.';
    }
    if (!is_numeric($values['default_discount_percent']) || (float) $values['default_discount_percent'] < 0 || (float) $values['default_discount_percent'] > 100) {
        $errors[] = 'Default discount must be a number between 0 and 100.';
    }
    if (!is_numeric($values['max_discount_percent']) || (float) $values['max_discount_percent'] < 0 || (float) $values['max_discount_percent'] > 100) {
        $errors[] = 'Maximum discount must be a number between 0 and 100.';
    }
    if ((float) $values['default_discount_percent'] > (float) $values['max_discount_percent']) {
        $errors[] = 'Default discount cannot exceed the maximum discount.';
    }
    if (!is_whole_number($values['low_stock_default'])) {
        $errors[] = 'Default low-stock threshold must be a whole number.';
    }

    if ($errors) {
        foreach ($errors as $msg) {
            flash('danger', $msg);
        }
    } else {
        // Normalise numeric values before storing them.
        $values['tax_rate']                 = number_format((float) $values['tax_rate'], 2, '.', '');
        $values['default_discount_percent'] = number_format((float) $values['default_discount_percent'], 2, '.', '');
        $values['max_discount_percent']     = number_format((float) $values['max_discount_percent'], 2, '.', '');
        $values['low_stock_default']        = (string) (int) $values['low_stock_default'];

        foreach ($values as $key => $value) {
            set_setting($key, $value);
        }
        flash('success', 'Settings saved.');
    }
    redirect('admin/settings.php');
}

$_page_title    = 'Settings';
$_page_subtitle = 'Store information, tax and discount configuration.';
$_active_nav    = 'settings';

require __DIR__ . '/../includes/header.php';
?>

<form method="post" action="<?= e(url('admin/settings.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="grid grid--2">
        <div class="section-stack">
            <div class="card">
                <header class="card__header"><h2>Store information</h2></header>
                <div class="card__body form-grid">
                    <label class="field">
                        <span class="field__label">Store name <span class="req">*</span></span>
                        <input class="input" type="text" name="store_name" value="<?= e(setting('store_name', '')) ?>" required>
                    </label>
                    <label class="field">
                        <span class="field__label">Address</span>
                        <input class="input" type="text" name="store_address" value="<?= e(setting('store_address', '')) ?>">
                    </label>
                    <label class="field">
                        <span class="field__label">Phone</span>
                        <input class="input" type="text" name="store_phone" value="<?= e(setting('store_phone', '')) ?>">
                    </label>
                    <label class="field">
                        <span class="field__label">Tax ID / TIN</span>
                        <input class="input" type="text" name="store_tax_id" value="<?= e(setting('store_tax_id', '')) ?>" maxlength="40" placeholder="Optional â€” printed on receipts">
                    </label>
                    <div class="form-grid form-grid--2">
                        <label class="field">
                            <span class="field__label">Currency code</span>
                            <input class="input" type="text" name="currency_code" value="<?= e(setting('currency_code', 'PHP')) ?>" maxlength="8">
                        </label>
                        <label class="field">
                            <span class="field__label">Currency symbol</span>
                            <input class="input" type="text" name="currency_symbol" value="<?= e(setting('currency_symbol', 'P')) ?>" maxlength="4">
                        </label>
                    </div>
                    <label class="field">
                        <span class="field__label">Receipt footer message</span>
                        <input class="input" type="text" name="receipt_footer" value="<?= e(setting('receipt_footer', '')) ?>" maxlength="160">
                    </label>
                </div>
            </div>

            <div class="card">
                <header class="card__header"><h2>Inventory defaults</h2></header>
                <div class="card__body form-grid">
                    <label class="field">
                        <span class="field__label">Default low-stock threshold</span>
                        <input class="input" type="number" name="low_stock_default" value="<?= e(setting('low_stock_default', '5')) ?>" min="0" step="1">
                        <span class="field__hint">Used as the suggested value when adding a new product.</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="section-stack">
            <div class="card">
                <header class="card__header"><h2>Tax</h2></header>
                <div class="card__body form-grid">
                    <label class="check">
                        <input type="checkbox" name="tax_enabled" value="1" <?= setting_bool('tax_enabled') ? 'checked' : '' ?>>
                        <span class="check__text"><strong>Enable tax</strong><small>Applied to the discounted subtotal at checkout.</small></span>
                    </label>
                    <div class="form-grid form-grid--2">
                        <label class="field">
                            <span class="field__label">Tax label</span>
                            <input class="input" type="text" name="tax_label" value="<?= e(setting('tax_label', 'VAT')) ?>" maxlength="20">
                        </label>
                        <label class="field">
                            <span class="field__label">Tax rate (%)</span>
                            <input class="input" type="number" name="tax_rate" value="<?= e(setting('tax_rate', '0')) ?>" min="0" max="100" step="0.01">
                        </label>
                    </div>
                    <p class="field__hint text-muted">By default tax is disabled (0%). Set a rate only if your store applies one.</p>
                </div>
            </div>

            <div class="card">
                <header class="card__header"><h2>Discount</h2></header>
                <div class="card__body form-grid">
                    <label class="check">
                        <input type="checkbox" name="discount_enabled" value="1" <?= setting_bool('discount_enabled') ? 'checked' : '' ?>>
                        <span class="check__text"><strong>Allow discounts</strong><small>Cashiers can apply a percentage discount at checkout.</small></span>
                    </label>
                    <div class="form-grid form-grid--2">
                        <label class="field">
                            <span class="field__label">Default discount (%)</span>
                            <input class="input" type="number" name="default_discount_percent" value="<?= e(setting('default_discount_percent', '0')) ?>" min="0" max="100" step="0.01">
                        </label>
                        <label class="field">
                            <span class="field__label">Maximum discount (%)</span>
                            <input class="input" type="number" name="max_discount_percent" value="<?= e(setting('max_discount_percent', '100')) ?>" min="0" max="100" step="0.01">
                        </label>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card__body">
                    <button type="submit" class="btn btn--primary btn--lg btn--block"><?= icon('check', 'icon', 18) ?> Save settings</button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
