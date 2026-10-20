<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_login();
$id   = (int) get('id', 0);
$sale = get_sale($id);

if (!$sale || !can_view_sale($sale)) {
    http_response_code(404);
    if (is_api_request()) {
        json_response(['ok' => false, 'message' => 'Receipt not found.'], 404);
    }
    exit('Receipt not found.');
}

$items = get_sale_items($id);
$embed = (int) get('embed', 0) === 1;
$autoprint = (int) get('autoprint', 0) === 1;

if ($embed) {
    // Fragment only - rendered inside the POS receipt modal.
    require __DIR__ . '/../includes/receipt_body.php';
    exit;
}

$_page_title = 'Receipt ' . $sale['receipt_number'];
$_active_nav = 'sales';
$_body_class = 'receipt-page';
$_page_scripts = $autoprint ? '<script>window.addEventListener("load",function(){window.print();});</script>' : '';

$_page_styles = ['css/receipt.css'];

require __DIR__ . '/../includes/header.php';
?>

<div class="no-print toolbar">
    <a class="btn btn--ghost btn--sm" href="<?= e(url(is_admin() ? 'admin/sales.php' : 'cashier/pos.php')) ?>"><?= icon('arrow-left', 'icon', 16) ?> Back</a>
    <span class="toolbar__spacer"></span>
    <button class="btn btn--primary btn--sm" type="button" onclick="window.print()"><?= icon('printer', 'icon', 16) ?> Print receipt</button>
</div>

<div class="card" style="max-width:520px;margin:0 auto">
    <div class="card__body">
        <?php require __DIR__ . '/../includes/receipt_body.php'; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
