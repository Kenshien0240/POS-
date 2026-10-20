<?php
/**
 * POST /cashier/checkout.php
 * Server-side checkout. Totals and stock are always recalculated here.
 *
 * JSON body:
 * {
 *   "cart": [ {"product_id": 1, "quantity": 2} ],
 *   "payment": { "method": "cash", "tendered": 500, "reference": "" },
 *   "discount_percent": 0,
 *   "idempotency_key": "a-unique-token-per-sale"
 * }
 *
 * The idempotency key makes retries safe: sending the same key twice returns
 * the original sale instead of creating a duplicate.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

require_post();
$user = require_login();

// CSRF protection for JSON requests (token sent via X-CSRF-Token header).
verify_csrf_or_fail();

$input = json_input();

$cart            = isset($input['cart']) && is_array($input['cart']) ? $input['cart'] : [];
$payment         = isset($input['payment']) && is_array($input['payment']) ? $input['payment'] : [];
$idempotencyKey  = normalize_idempotency_key($input['idempotency_key'] ?? null);

$rawDiscount = $input['discount_percent'] ?? 0;
if (!is_numeric($rawDiscount) || !is_finite((float) $rawDiscount)) {
    json_response(['ok' => false, 'message' => 'Please enter a valid discount percentage.'], 422);
}
$discountPercent = (float) $rawDiscount;

$result = checkout($cart, $payment, (int) $user['id'], $discountPercent, $idempotencyKey);

if (!$result['ok']) {
    json_response(['ok' => false, 'message' => $result['message']], 422);
}

json_response([
    'ok'             => true,
    'duplicate'      => !empty($result['duplicate']),
    'message'        => $result['message'],
    'sale_id'        => $result['sale_id'],
    'receipt_number' => $result['receipt_number'],
    'total'          => money_cents($result['total_cents']),
    'change'         => money_cents($result['change_cents']),
]);
