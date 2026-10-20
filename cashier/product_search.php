<?php
/**
 * GET /cashier/product_search.php
 * Search active products for the POS screen.
 * Query: q (name/code), category, limit
 */
require_once __DIR__ . '/../includes/bootstrap.php';

require_get();
require_login();

$q        = get('q', '');
$q        = is_string($q) ? mb_substr($q, 0, 100) : '';
$category = (int) get('category', 0);
$limit    = min(60, max(1, (int) get('limit', 60)));

$where  = ['p.status = 1'];
$params = [];

if ($q !== '') {
    $where[]  = '(p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($category > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $category;
}

$sql = 'SELECT p.id, p.sku, p.name, p.price, p.stock_quantity, p.low_stock_threshold,
               p.category_id, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY p.name
        LIMIT ' . $limit;

$stmt = db()->prepare($sql);
$stmt->execute($params);

$products = [];
foreach ($stmt->fetchAll() as $row) {
    $products[] = [
        'id'        => (int) $row['id'],
        'code'      => $row['sku'],
        'name'      => $row['name'],
        'price'     => (float) $row['price'],
        'price_fmt' => money((float) $row['price']),
        'stock'     => (int) $row['stock_quantity'],
        'low'       => (int) $row['stock_quantity'] <= (int) $row['low_stock_threshold'],
        'category'  => $row['category_name'],
    ];
}

json_response(['ok' => true, 'products' => $products]);
