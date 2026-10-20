<?php
/**
 * Sales engine: totals, checkout, payment recording, inventory movements.
 * All money arithmetic is performed in integer cents to avoid floating point
 * rounding errors. Totals are always recalculated here on the server; prices
 * or totals sent by the browser are never trusted.
 */

// ---------------------------------------------------------------------------
// Configuration helpers
// ---------------------------------------------------------------------------

/** Return the tax/discount configuration from settings. */
function sales_config(): array
{
    return [
        'tax_enabled'      => setting_bool('tax_enabled', false),
        'tax_rate'         => (float) setting('tax_rate', '0'),
        'tax_label'        => setting('tax_label', 'VAT'),
        'discount_enabled' => setting_bool('discount_enabled', false),
        'discount_default' => (float) setting('default_discount_percent', '0'),
        'discount_max'     => (float) setting('max_discount_percent', '100'),
    ];
}

/**
 * Calculate order totals from a list of priced lines.
 *
 * @param array $lines  each: ['product_id','name','code','price','quantity','stock']
 * @param array $config output of sales_config()
 * @param float $discountPercent requested discount percentage (0 if none)
 * @return array{subtotal:int,discount:int,tax:int,total:int,items:array,discount_percent:float,tax_rate:float}
 */
function calculate_totals(array $lines, array $config, float $discountPercent = 0.0): array
{
    $items = [];
    $subtotal = 0;

    foreach ($lines as $line) {
        $unitCents = to_cents($line['price']);
        $qty       = (int) $line['quantity'];
        $lineTotal = $unitCents * $qty;
        $subtotal += $lineTotal;

        $items[] = [
            'product_id'   => (int) $line['product_id'],
            'sku' => (string) $line['code'],
            'product_name_snapshot' => (string) $line['name'],
            'unit_price'   => from_cents($unitCents),
            'quantity'     => $qty,
            'line_total'   => from_cents($lineTotal),
        ];
    }

    // Discount (percentage of subtotal) - only when enabled.
    $discountPercent = $config['discount_enabled'] ? max(0.0, min($config['discount_max'], $discountPercent)) : 0.0;
    $discountCents   = (int) round($subtotal * $discountPercent / 100, 0, PHP_ROUND_HALF_UP);

    // Tax is applied to the discounted subtotal.
    $taxRate    = $config['tax_enabled'] ? max(0.0, $config['tax_rate']) : 0.0;
    $taxable    = $subtotal - $discountCents;
    $taxCents   = (int) round($taxable * $taxRate / 100, 0, PHP_ROUND_HALF_UP);

    $totalCents = $taxable + $taxCents;

    return [
        'subtotal'         => $subtotal,
        'discount'         => $discountCents,
        'tax'              => $taxCents,
        'total'            => $totalCents,
        'items'            => $items,
        'discount_percent' => $discountPercent,
        'tax_rate'         => $taxRate,
    ];
}

// ---------------------------------------------------------------------------
// Receipt numbers
// ---------------------------------------------------------------------------

/** Generate the next receipt number for today (RCP-YYYYMMDD-0001). */
function next_receipt_number(PDO $pdo): string
{
    $prefix = 'RCP-' . date('Ymd') . '-';

    $stmt = $pdo->prepare(
        'SELECT receipt_number FROM sales WHERE receipt_number LIKE ? ORDER BY receipt_number DESC LIMIT 1'
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $seq = 1;
    if ($last) {
        $seq = (int) substr($last, -4) + 1;
    }

    // Guard against any unexpected collision.
    $candidate = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    $check = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE receipt_number = ?');
    $check->execute([$candidate]);
    while ((int) $check->fetchColumn() > 0) {
        $seq++;
        $candidate = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        $check->execute([$candidate]);
    }

    return $candidate;
}

// ---------------------------------------------------------------------------
// Duplicate-submission protection (idempotency)
// ---------------------------------------------------------------------------

/** True when a PDO exception is a duplicate-key (unique constraint) error. */
function is_duplicate_key_error(Throwable $e): bool
{
    return $e instanceof PDOException
        && (string) $e->getCode() === '23000'
        && strpos((string) $e->getMessage(), 'Duplicate entry') !== false;
}

/**
 * Find a previously committed sale by its idempotency key.
 * When a cashier id is given, only that user's sale is returned so one
 * cashier can never resolve another cashier's key.
 */
function find_sale_by_idempotency(string $key, ?int $cashierId = null): ?array
{
    $sql    = 'SELECT s.*, p.change_amount, p.tendered_amount
               FROM sales s LEFT JOIN payments p ON p.sale_id = s.id
               WHERE s.idempotency_key = ?';
    $params = [$key];
    if ($cashierId !== null) {
        $sql .= ' AND s.cashier_id = ?';
        $params[] = $cashierId;
    }
    $sql .= ' LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Normalise an idempotency key sent by the client; null when unusable. */
function normalize_idempotency_key($key): ?string
{
    if (!is_string($key)) {
        return null;
    }
    $key = trim($key);
    if ($key === '' || strlen($key) > 64) {
        return null;
    }
    return preg_match('/^[A-Za-z0-9._-]+$/', $key) ? $key : null;
}


// ---------------------------------------------------------------------------
// Checkout
// ---------------------------------------------------------------------------

/**
 * Process a checkout from a raw cart request.
 *
 * @param array $cart  list of ['product_id'=>int, 'quantity'=>int]
 * @param array $payment ['method'=>string,'tendered'=>float,'reference'=>?string]
 * @param int   $cashierId
 * @param float $discountPercent
 * @param string|null $idempotencyKey client key that makes retries safe
 * @return array{ok:bool,message:string,sale_id?:int,receipt_number?:string,total_cents?:int}
 */
function checkout(array $cart, array $payment, int $cashierId, float $discountPercent = 0.0, ?string $idempotencyKey = null): array
{
    $pdo = db();
    $config = sales_config();

    if (!is_finite($discountPercent)) {
        $discountPercent = 0.0;
    }

    // --- Duplicate submission guard ------------------------------------------
    // If this exact checkout was already committed (double-click, retry, page
    // reload), return the original sale rather than creating a second one.
    $idempotencyKey = normalize_idempotency_key($idempotencyKey);
    if ($idempotencyKey !== null) {
        $existing = find_sale_by_idempotency($idempotencyKey, $cashierId);
        if ($existing) {
            return [
                'ok'             => true,
                'duplicate'      => true,
                'message'        => 'This sale was already completed.',
                'sale_id'        => (int) $existing['id'],
                'receipt_number' => $existing['receipt_number'],
                'total_cents'    => to_cents($existing['total_amount']),
                'change_cents'   => to_cents($existing['change_amount'] ?? 0),
            ];
        }
    }

    // --- Normalise and validate the cart -------------------------------------
    if (count($cart) > 200) {
        return ['ok' => false, 'message' => 'The cart contains too many different items.'];
    }

    $wanted = [];
    foreach ($cart as $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'message' => 'The cart contains an invalid item.'];
        }

        // Reject arrays/objects/decimals before casting so malformed JSON can
        // never turn into an unintended product ID or quantity.
        $pid = filter_var($row['product_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_INT);

        if ($pid === false || $pid <= 0) {
            return ['ok' => false, 'message' => 'The cart contains an invalid product.'];
        }
        if ($qty === false || $qty <= 0 || $qty > 10000) {
            return ['ok' => false, 'message' => 'Quantities must be between 1 and 10,000.'];
        }

        $wanted[$pid] = ($wanted[$pid] ?? 0) + $qty;
        if ($wanted[$pid] > 10000) {
            return ['ok' => false, 'message' => 'Quantities must be between 1 and 10,000.'];
        }
    }

    if (!$wanted) {
        return ['ok' => false, 'message' => 'Your cart is empty.'];
    }

    // --- Validate payment method ---------------------------------------------
    $methods = ['cash', 'card', 'e_wallet'];
    $method  = $payment['method'] ?? '';
    if (!in_array($method, $methods, true)) {
        return ['ok' => false, 'message' => 'Please choose a valid payment method.'];
    }

    // Optional payment reference (card / e-wallet): normalise and bound it.
    $reference = $payment['reference'] ?? null;
    if ($reference !== null) {
        if (!is_string($reference) || strlen($reference) > 100) {
            return ['ok' => false, 'message' => 'The payment reference is too long.'];
        }
        $reference = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $reference));
        if ($reference === '') {
            $reference = null;
        }
    }

    try {
        $pdo->beginTransaction();

        // --- Lock and load products (server-side prices and stock) -----------
        $ids  = array_keys($wanted);
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($in) FOR UPDATE");
        $stmt->execute($ids);

        $products = [];
        foreach ($stmt->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        $lines = [];
        foreach ($wanted as $pid => $qty) {
            if (!isset($products[$pid])) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'One of the products no longer exists.'];
            }
            $product = $products[$pid];
            if ((int) $product['status'] !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => $product['name'] . ' is no longer available for sale.'];
            }
            if ((int) $product['stock_quantity'] < $qty) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'message' => sprintf('Not enough stock for %s. Only %d left.', $product['name'], (int) $product['stock_quantity']),
                ];
            }
            $lines[] = [
                'product_id' => $pid,
                'name'       => $product['name'],
                'code'       => $product['sku'],
                'price'      => $product['price'],
                'quantity'   => $qty,
                'stock'      => (int) $product['stock_quantity'],
            ];
        }

        // --- Recalculate totals on the server --------------------------------
        $totals = calculate_totals($lines, $config, $discountPercent);

        // --- Validate payment amount -----------------------------------------
        $totalCents = $totals['total'];
        if ($method === 'cash') {
            $tendered = $payment['tendered'] ?? 0;
            if (!is_numeric($tendered) || !is_finite((float) $tendered) || (float) $tendered < 0) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Please enter a valid amount tendered.'];
            }
            $tenderedCents = to_cents($tendered);
            if ($tenderedCents < $totalCents) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Amount tendered is less than the total due.'];
            }
        } else {
            $tenderedCents = $totalCents;
        }
        $changeCents = max(0, $tenderedCents - $totalCents);

        // --- Create the sale (with a unique receipt number) ------------------
        // The unique constraints on receipt_number and idempotency_key make
        // this safe even if two cashiers check out at the same instant.
        $saleStmt = $pdo->prepare(
            'INSERT INTO sales (receipt_number, idempotency_key, cashier_id, subtotal, discount_amount, tax_amount, total_amount, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "completed")'
        );

        $receipt = null;
        $saleId  = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $receipt = next_receipt_number($pdo);
            try {
                $saleStmt->execute([
                    $receipt,
                    $idempotencyKey,
                    $cashierId,
                    from_cents($totals['subtotal']),
                    from_cents($totals['discount']),
                    from_cents($totals['tax']),
                    from_cents($totals['total']),
                ]);
                $saleId = (int) $pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                if (!is_duplicate_key_error($e)) {
                    throw $e;
                }
                // Same idempotency key won the race: return the committed sale.
                if ($idempotencyKey !== null && strpos($e->getMessage(), 'uq_sales_idempotency') !== false) {
                    $pdo->rollBack();
                    $existing = find_sale_by_idempotency($idempotencyKey, $cashierId);
                    if ($existing) {
                        return [
                            'ok'             => true,
                            'duplicate'      => true,
                            'message'        => 'This sale was already completed.',
                            'sale_id'        => (int) $existing['id'],
                            'receipt_number' => $existing['receipt_number'],
                            'total_cents'    => to_cents($existing['total_amount']),
                            'change_cents'   => to_cents($existing['change_amount'] ?? 0),
                        ];
                    }
                    return ['ok' => false, 'message' => 'Duplicate checkout detected. Please refresh the page.'];
                }
                // Receipt number collision: try the next sequence number.
                if (strpos($e->getMessage(), 'uq_sales_receipt') !== false) {
                    continue;
                }
                throw $e;
            }
        }

        if ($saleId === null) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Could not generate a unique receipt number. Please try again.'];
        }

        // --- Create sale items and deduct stock ------------------------------
        $itemStmt = $pdo->prepare(
            'INSERT INTO sale_items (sale_id, product_id, sku_snapshot, product_name_snapshot, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stockStmt = $pdo->prepare(
            'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?'
        );
        $moveStmt = $pdo->prepare(
            'INSERT INTO inventory_movements
                (product_id, user_id, sale_id, movement_type, quantity_change, stock_before, stock_after, reference_type, reference_id, notes)
             VALUES (?, ?, ?, "sale", ?, ?, ?, "sale", ?, ?)'
        );

        foreach ($totals['items'] as $item) {
            $itemStmt->execute([
                $saleId,
                $item['product_id'],
                $item['sku'],
                $item['product_name_snapshot'],
                $item['unit_price'],
                $item['quantity'],
                $item['line_total'],
            ]);

            $before = (int) $products[$item['product_id']]['stock_quantity'];
            $stockStmt->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
            if ($stockStmt->rowCount() !== 1) {
                // Should not happen: stock was checked while locked.
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Stock changed while checking out. Please try again.'];
            }
            $after = $before - $item['quantity'];

            $moveStmt->execute([
                $item['product_id'],
                $cashierId,
                $saleId,
                -$item['quantity'],
                $before,
                $after,
                $receipt,
                'Sold on receipt ' . $receipt,
            ]);
        }

        // --- Record the payment ----------------------------------------------
        $payStmt = $pdo->prepare(
            'INSERT INTO payments (sale_id, payment_method, amount_paid, tendered_amount, change_amount, payment_status, reference_no)
             VALUES (?, ?, ?, ?, ?, "paid", ?)'
        );
        $payStmt->execute([
            $saleId,
            $method,
            from_cents($totalCents),
            from_cents($tenderedCents),
            from_cents($changeCents),
            $reference,
        ]);

        $pdo->commit();

        return [
            'ok'             => true,
            'duplicate'      => false,
            'message'        => 'Sale completed successfully.',
            'sale_id'        => $saleId,
            'receipt_number' => $receipt,
            'total_cents'    => $totalCents,
            'change_cents'   => $changeCents,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_exception($e, 'Checkout failed');
        if (APP_DEBUG) {
            return ['ok' => false, 'message' => 'Checkout failed: ' . $e->getMessage()];
        }
        return ['ok' => false, 'message' => 'Checkout failed. Please try again.'];
    }
}

// ---------------------------------------------------------------------------
// Reading sales
// ---------------------------------------------------------------------------

/** Fetch a single sale with cashier and payment details. */
function get_sale(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, u.name AS cashier_name, u.username AS cashier_username,
                p.payment_method, p.amount_paid, p.tendered_amount, p.change_amount, p.reference_no
         FROM sales s
         JOIN users u ON u.id = s.cashier_id
         LEFT JOIN payments p ON p.sale_id = s.id
         WHERE s.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Fetch the line items for a sale. */
function get_sale_items(int $saleId): array
{
    $stmt = db()->prepare('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id');
    $stmt->execute([$saleId]);
    return $stmt->fetchAll();
}

/**
 * Void a completed sale and restore the sold stock (administrators only).
 */
function void_sale(int $saleId, int $userId): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();

        if (!$sale) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Sale not found.'];
        }
        if ($sale['status'] !== 'completed') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Only completed sales can be voided.'];
        }

        $items = get_sale_items($saleId);

        $stockStmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?');
        $moveStmt  = $pdo->prepare(
            'INSERT INTO inventory_movements
                (product_id, user_id, sale_id, movement_type, quantity_change, stock_before, stock_after, reference_type, reference_id, notes)
             VALUES (?, ?, ?, "adjustment", ?, ?, ?, "sale", ?, ?)'
        );
        $readStmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ? FOR UPDATE');

        foreach ($items as $item) {
            $readStmt->execute([$item['product_id']]);
            $before = (int) $readStmt->fetchColumn();
            $stockStmt->execute([$item['quantity'], $item['product_id']]);
            $after = $before + (int) $item['quantity'];
            $moveStmt->execute([
                $item['product_id'],
                $userId,
                $saleId,
                (int) $item['quantity'],
                $before,
                $after,
                $sale['receipt_number'],
                'Stock restored from voided receipt ' . $sale['receipt_number'],
            ]);
        }

        $pdo->prepare('UPDATE sales SET status = "voided" WHERE id = ?')->execute([$saleId]);
        $pdo->prepare('UPDATE payments SET payment_status = "refunded" WHERE sale_id = ?')->execute([$saleId]);
        $pdo->commit();

        return ['ok' => true, 'message' => 'Sale ' . $sale['receipt_number'] . ' has been voided and stock restored.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_exception($e, 'Void sale failed');
        return ['ok' => false, 'message' => APP_DEBUG ? $e->getMessage() : 'Unable to void the sale.'];
    }
}
