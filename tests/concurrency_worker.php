<?php
$root = dirname(__DIR__);
putenv('APP_CONFIG=' . __DIR__ . '/config.test.php');
require_once $root . '/includes/bootstrap.php';

$startAt   = (float) ($argv[1] ?? 0);
$productId = (int) ($argv[2] ?? 0);
$quantity  = (int) ($argv[3] ?? 1);
$resultFile = (string) ($argv[4] ?? '');
$key        = (string) ($argv[5] ?? '');

$cashierId = (int) scalar_lookup('SELECT id FROM users WHERE username = ? LIMIT 1', ['cashier']);
while (microtime(true) < $startAt) {
    usleep(1000);
}

$result = checkout(
    [['product_id' => $productId, 'quantity' => $quantity]],
    ['method' => 'cash', 'tendered' => 1000],
    $cashierId,
    0.0,
    $key
);

file_put_contents($resultFile, json_encode($result));

function scalar_lookup(string $sql, array $params)
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
