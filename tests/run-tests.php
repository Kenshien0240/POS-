<?php
/**
 * PART 14 integration test suite.
 *
 * Usage:
 *   php tests/setup-test-db.php
 *   php tests/run-tests.php
 *
 * The suite starts its own PHP development server against the isolated
 * retail_pos_test database and stops it when finished.
 */

$root = dirname(__DIR__);
putenv('APP_CONFIG=' . __DIR__ . '/config.test.php');
putenv('POS_TEST_ROOT=' . $root);

$php = PHP_BINARY;
exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/setup-test-db.php') . ' 2>&1', $setupOut, $setupCode);
if ($setupCode !== 0) {
    fwrite(STDERR, "Database setup failed:\n" . implode("\n", $setupOut) . "\n");
    exit(1);
}

require_once $root . '/includes/bootstrap.php';

$port = (int) (getenv('POS_TEST_PORT') ?: 8765);
$base = 'http://127.0.0.1:' . $port;
$server = null;
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos-test-server-' . getmypid() . '.log';

register_shutdown_function(static function () use (&$server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});

function test_server_start(string $root, int $port, string $log): array
{
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the PHP test server.');
    }
    if (isset($pipes[0])) {
        fclose($pipes[0]);
    }

    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(200000);
        $ch = curl_init('http://127.0.0.1:' . $port . '/login.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status > 0) {
            $ready = true;
            break;
        }
    }

    if (!$ready) {
        proc_terminate($process);
        proc_close($process);
        $tail = is_file($log) ? file_get_contents($log) : '';
        throw new RuntimeException('PHP test server did not start. Log: ' . $tail);
    }

    return [$process, $log];
}

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function locationPath(): ?string
    {
        $location = $this->header('location');
        return $location === null ? null : (parse_url($location, PHP_URL_PATH) ?: null);
    }

    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Response is not valid JSON: ' . substr($this->body, 0, 300));
        }
        return $data;
    }
}

final class HttpSession
{
    private string $jar;

    public function __construct(private readonly string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'pos-cookie-');
        file_put_contents($this->jar, '');
    }

    public function get(string $path, array $options = []): Response
    {
        return $this->request('GET', $path, $options);
    }

    public function postForm(string $path, array $fields, array $options = []): Response
    {
        return $this->request('POST', $path, $options + [
            'body'    => http_build_query($fields, '', '&'),
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
    }

    public function postJson(string $path, array $payload, array $options = []): Response
    {
        return $this->request('POST', $path, $options + [
            'body'    => json_encode($payload),
            'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
    }

    public function request(string $method, string $path, array $options = []): Response
    {
        $headers = [];
        $ch = curl_init($this->base . $path);
        $curlHeaders = $options['headers'] ?? [];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if ($trimmed !== '' && strpos($trimmed, ':') !== false) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return $length;
            },
        ]);

        if (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }
        if (isset($options['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $options['cookie']);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, $headers, (string) $body);
    }
}

$tests = [];
$passed = 0;
$failed = 0;

function t_test(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "PASS  {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  {$name}\n      " . $e->getMessage() . "\n";
    }
}

function t_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function t_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function t_status(Response $response, int $expected, string $context = ''): void
{
    t_same($expected, $response->status, trim($context . ' status'));
}

function t_contains(string $haystack, string $needle, string $message): void
{
    t_assert(str_contains($haystack, $needle), $message . ' (missing "' . $needle . '")');
}

function t_not_contains(string $haystack, string $needle, string $message): void
{
    t_assert(!str_contains($haystack, $needle), $message . ' (unexpected "' . $needle . '")');
}

function t_location(Response $response, string $expectedPath, string $context = ''): void
{
    t_same($expectedPath, $response->locationPath(), trim($context . ' redirect'));
}

function t_money_field(array $data, string $field, string $expected, string $context = ''): void
{
    t_assert(isset($data[$field]), $context . ' missing ' . $field);
    t_contains((string) $data[$field], $expected, $context . ' ' . $field);
}

function t_csrf(string $html): string
{
    if (preg_match('/name="_csrf"\s+value="([a-f0-9]{64})"/', $html, $matches)) {
        return $matches[1];
    }
    if (preg_match('/window\.POS_CONFIG\s*=\s*(\{.*?\});/s', $html, $matches)) {
        $config = json_decode($matches[1], true);
        if (is_array($config) && !empty($config['csrf'])) {
            return (string) $config['csrf'];
        }
    }
    throw new RuntimeException('CSRF token not found in response.');
}

function t_pos_config(string $html): array
{
    if (!preg_match('/window\.POS_CONFIG\s*=\s*(\{.*?\});/s', $html, $matches)) {
        throw new RuntimeException('POS config not found.');
    }
    $config = json_decode($matches[1], true);
    if (!is_array($config)) {
        throw new RuntimeException('POS config is not valid JSON.');
    }
    return $config;
}

function t_login(HttpSession $http, string $username, string $password): Response
{
    $form = $http->get('/login.php');
    t_status($form, 200, 'login form');
    return $http->postForm('/login.php', [
        '_csrf'    => t_csrf($form->body),
        'username' => $username,
        'password' => $password,
    ]);
}

function t_token(HttpSession $http, string $path): string
{
    $response = $http->get($path);
    t_status($response, 200, $path);
    return t_csrf($response->body);
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row ?: null;
}

function scalar(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}

function count_where(string $table, string $where = '1', array $params = []): int
{
    return (int) scalar("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
}

function insert_product(string $sku, string $name, string $price, int $stock, int $threshold = 5, int $status = 1): int
{
    q(
        'INSERT INTO products (category_id, sku, name, description, price, stock_quantity, low_stock_threshold, status)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?)',
        [$sku, $name, 'Integration test fixture.', $price, $stock, $threshold, $status]
    );
    return (int) db()->lastInsertId();
}

function checkout_payload(int $productId, int $quantity, array $payment, string $key, $discount = 0): array
{
    return [
        'cart'              => [['product_id' => $productId, 'quantity' => $quantity]],
        'payment'           => $payment,
        'discount_percent'  => $discount,
        'idempotency_key'   => $key,
    ];
}

[$server, $serverLog] = test_server_start($root, $port, $serverLog);
echo "Test server: {$base}\n";
echo "Test database: " . DB_NAME . "\n\n";

$admin = new HttpSession($base);
$cashier = new HttpSession($base);
$productId = 0;
$categoryId = 0;
$managedUserId = 0;
$cashSaleId = 0;
$cashReceipt = '';
$cardSaleId = 0;
$cardReceipt = '';
$idemSaleId = 0;
$idemReceipt = '';
$concSaleId = 0;
$cashierToken = '';

t_test('admin login succeeds and opens the dashboard', function () use ($admin, $base): void {
    $response = t_login($admin, 'admin', 'Admin@12345');
    t_status($response, 302, 'admin login');
    t_location($response, '/admin/dashboard.php', 'admin login');
    $dashboard = $admin->get('/admin/dashboard.php');
    t_status($dashboard, 200, 'admin dashboard');
    t_contains($dashboard->body, 'Sales today', 'dashboard content');
});

t_test('invalid credentials are rejected without creating a session', function () use ($base): void {
    $http = new HttpSession($base);
    $response = t_login($http, 'admin', 'wrong-password');
    t_status($response, 200, 'invalid login');
    t_contains($response->body, 'Incorrect username or password.', 'invalid login message');
    $dashboard = $http->get('/admin/dashboard.php');
    t_status($dashboard, 302, 'unauthenticated dashboard');
    t_location($dashboard, '/login.php', 'unauthenticated dashboard');
});

t_test('empty login fields are rejected', function () use ($base): void {
    $http = new HttpSession($base);
    $form = $http->get('/login.php');
    $response = $http->postForm('/login.php', [
        '_csrf'    => t_csrf($form->body),
        'username' => 'admin',
        'password' => '',
    ]);
    t_status($response, 200, 'empty login');
    t_contains($response->body, 'Please enter both your username and password.', 'empty login message');
});

t_test('login rejects a missing or expired CSRF token', function () use ($base): void {
    $http = new HttpSession($base);
    $http->get('/login.php');
    $response = $http->postForm('/login.php', [
        'username' => 'admin',
        'password' => 'Admin@12345',
    ]);
    t_status($response, 419, 'missing CSRF login');
});

t_test('cashier login redirects to the POS and cannot open admin pages', function () use ($cashier, $base): void {
    $response = t_login($cashier, 'cashier', 'Cashier@123');
    t_status($response, 302, 'cashier login');
    t_location($response, '/cashier/pos.php', 'cashier login');
    $pos = $cashier->get('/cashier/pos.php');
    t_status($pos, 200, 'cashier POS');
    $adminPage = $cashier->get('/admin/dashboard.php');
    t_status($adminPage, 403, 'cashier admin access');
    t_contains($adminPage->body, 'Access denied', 'cashier forbidden page');
});

t_test('deactivated accounts cannot log in', function (): void {
    global $base;
    q('UPDATE users SET status = 0 WHERE username = ?', ['cashier']);
    try {
        $http = new HttpSession($base);
        $response = t_login($http, 'cashier', 'Cashier@123');
        t_status($response, 200, 'deactivated login');
        t_contains($response->body, 'This account has been deactivated.', 'deactivated login message');
    } finally {
        q('UPDATE users SET status = 1 WHERE username = ?', ['cashier']);
    }
});

t_test('deactivating an account invalidates its existing session', function () use ($base): void {
    $http = new HttpSession($base);
    $login = t_login($http, 'cashier', 'Cashier@123');
    t_status($login, 302, 'temporary cashier login');
    q('UPDATE users SET status = 0 WHERE username = ?', ['cashier']);
    try {
        $response = $http->get('/cashier/pos.php');
        t_status($response, 302, 'deactivated session');
        t_location($response, '/login.php', 'deactivated session');
    } finally {
        q('UPDATE users SET status = 1 WHERE username = ?', ['cashier']);
    }
});

t_test('an unknown session id is rejected', function () use ($base): void {
    $http = new HttpSession($base);
    $response = $http->get('/admin/dashboard.php', [
        'cookie' => 'retailpos_session=unknown-session-id',
    ]);
    t_status($response, 302, 'unknown session');
    t_location($response, '/login.php', 'unknown session');
});

t_test('logout destroys the session', function () use ($base): void {
    $http = new HttpSession($base);
    $login = t_login($http, 'admin', 'Admin@12345');
    t_status($login, 302, 'logout test login');
    $dashboard = $http->get('/admin/dashboard.php');
    $token = t_csrf($dashboard->body);
    $logout = $http->postForm('/logout.php', ['_csrf' => $token]);
    t_status($logout, 302, 'logout');
    t_location($logout, '/login.php', 'logout');
    $after = $http->get('/admin/dashboard.php');
    t_status($after, 302, 'session after logout');
    t_location($after, '/login.php', 'session after logout');
});

t_test('valid product creation writes the product and opening-stock ledger', function () use ($admin): void {
    global $productId;
    $token = t_token($admin, '/admin/product_form.php');
    $response = $admin->postForm('/admin/product_form.php', [
        '_csrf'               => $token,
        'sku'                 => 'TEST-100',
        'name'                => 'Test Widget',
        'description'         => 'Created by the integration suite.',
        'category_id'         => '1',
        'price'               => '123.45',
        'stock_quantity'      => '10',
        'low_stock_threshold' => '3',
        'status'              => '1',
    ]);
    t_status($response, 302, 'product create');
    t_location($response, '/admin/products.php', 'product create');

    $row = one('SELECT * FROM products WHERE sku = ?', ['TEST-100']);
    t_assert($row !== null, 'product was not inserted');
    $productId = (int) $row['id'];
    t_same('Test Widget', $row['name'], 'product name');
    t_same('123.45', $row['price'], 'product price');
    t_same(10, (int) $row['stock_quantity'], 'opening stock');
    $movement = one(
        'SELECT * FROM inventory_movements WHERE product_id = ? AND movement_type = "initial"',
        [$productId]
    );
    t_assert($movement !== null, 'opening-stock movement missing');
    t_same(10, (int) $movement['quantity_change'], 'opening movement quantity');
});

t_test('duplicate SKU is rejected on create', function () use ($admin): void {
    global $productId;
    $token = t_token($admin, '/admin/product_form.php');
    $response = $admin->postForm('/admin/product_form.php', [
        '_csrf'               => $token,
        'sku'                 => 'TEST-100',
        'name'                => 'Duplicate Widget',
        'price'               => '10.00',
        'stock_quantity'      => '1',
        'low_stock_threshold' => '1',
        'status'              => '1',
    ]);
    t_status($response, 200, 'duplicate SKU');
    t_contains($response->body, 'That product code is already in use.', 'duplicate SKU message');
    t_same(1, count_where('products', 'sku = ?', ['TEST-100']), 'duplicate product count');
    t_same(1, count_where('products', 'id = ?', [$productId]), 'original product count');
});

t_test('invalid price and stock values are rejected', function () use ($admin): void {
    $token = t_token($admin, '/admin/product_form.php');

    $badPrice = $admin->postForm('/admin/product_form.php', [
        '_csrf'               => $token,
        'sku'                 => 'TEST-101',
        'name'                => 'Bad Price Widget',
        'price'               => 'not-a-price',
        'stock_quantity'      => '1',
        'low_stock_threshold' => '1',
        'status'              => '1',
    ]);
    t_status($badPrice, 200, 'invalid price');
    t_contains($badPrice->body, 'Enter a valid price', 'invalid price message');
    t_same(0, count_where('products', 'sku = ?', ['TEST-101']), 'invalid price product count');

    $badStock = $admin->postForm('/admin/product_form.php', [
        '_csrf'               => $token,
        'sku'                 => 'TEST-102',
        'name'                => 'Bad Stock Widget',
        'price'               => '10.00',
        'stock_quantity'      => '-1',
        'low_stock_threshold' => '1',
        'status'              => '1',
    ]);
    t_status($badStock, 200, 'invalid stock');
    t_contains($badStock->body, 'Opening stock must be a whole number (0 or more).', 'invalid stock message');
    t_same(0, count_where('products', 'sku = ?', ['TEST-102']), 'invalid stock product count');
});

t_test('editing a product updates fields and records a stock adjustment', function () use ($admin, &$productId): void {
    $token = t_token($admin, '/admin/product_form.php?id=' . $productId);
    $response = $admin->postForm('/admin/product_form.php?id=' . $productId, [
        '_csrf'               => $token,
        'sku'                 => 'TEST-100',
        'name'                => 'Test Widget Updated',
        'description'         => 'Updated by the integration suite.',
        'category_id'         => '2',
        'price'               => '130.00',
        'stock_adjustment'    => '5',
        'low_stock_threshold' => '3',
        'status'              => '1',
    ]);
    t_status($response, 302, 'product edit');
    t_location($response, '/admin/products.php', 'product edit');

    $row = one('SELECT * FROM products WHERE id = ?', [$productId]);
    t_same('Test Widget Updated', $row['name'], 'edited name');
    t_same('130.00', $row['price'], 'edited price');
    t_same(2, (int) $row['category_id'], 'edited category');
    t_same(15, (int) $row['stock_quantity'], 'edited stock');
    $movement = one(
        'SELECT * FROM inventory_movements WHERE product_id = ? AND movement_type = "restock" ORDER BY id DESC LIMIT 1',
        [$productId]
    );
    t_assert($movement !== null, 'edit adjustment movement missing');
    t_same(5, (int) $movement['quantity_change'], 'edit adjustment change');
    t_same(15, (int) $movement['stock_after'], 'edit adjustment after-stock');
});

t_test('an over-large negative adjustment is rejected without changing stock', function () use ($admin, &$productId): void {
    $token = t_token($admin, '/admin/product_form.php?id=' . $productId);
    $response = $admin->postForm('/admin/product_form.php?id=' . $productId, [
        '_csrf'               => $token,
        'sku'                 => 'TEST-100',
        'name'                => 'Test Widget Updated',
        'category_id'         => '2',
        'price'               => '130.00',
        'stock_adjustment'    => '-999',
        'low_stock_threshold' => '3',
        'status'              => '1',
    ]);
    t_status($response, 200, 'negative product adjustment');
    t_contains($response->body, 'Adjustment would make stock negative', 'negative adjustment message');
    t_same(15, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$productId]), 'stock after rejected adjustment');
});

t_test('editing to a duplicate SKU is rejected', function () use ($admin, &$productId): void {
    $token = t_token($admin, '/admin/product_form.php?id=' . $productId);
    $response = $admin->postForm('/admin/product_form.php?id=' . $productId, [
        '_csrf'               => $token,
        'sku'                 => 'BEV-001',
        'name'                => 'Test Widget Updated',
        'category_id'         => '2',
        'price'               => '130.00',
        'stock_adjustment'    => '',
        'low_stock_threshold' => '3',
        'status'              => '1',
    ]);
    t_status($response, 200, 'duplicate edit SKU');
    t_contains($response->body, 'That product code is already in use.', 'duplicate edit SKU message');
    t_same('TEST-100', scalar('SELECT sku FROM products WHERE id = ?', [$productId]), 'SKU after duplicate edit');
});

t_test('low-stock filtering includes a product at its threshold', function () use ($admin, &$productId): void {
    $token = t_token($admin, '/admin/product_form.php?id=' . $productId);
    $response = $admin->postForm('/admin/product_form.php?id=' . $productId, [
        '_csrf'               => $token,
        'sku'                 => 'TEST-100',
        'name'                => 'Test Widget Updated',
        'category_id'         => '2',
        'price'               => '130.00',
        'stock_adjustment'    => '',
        'low_stock_threshold' => '20',
        'status'              => '1',
    ]);
    t_status($response, 302, 'low-stock threshold edit');
    t_same(20, (int) scalar('SELECT low_stock_threshold FROM products WHERE id = ?', [$productId]), 'threshold');
    $page = $admin->get('/admin/products.php?stock=low');
    t_status($page, 200, 'low-stock page');
    t_contains($page->body, 'Test Widget Updated', 'low-stock product listing');
});

t_test('inventory restock and adjustment update stock and the ledger', function () use ($admin, &$productId): void {
    $token = t_token($admin, '/admin/inventory.php');
    $restock = $admin->postForm('/admin/inventory.php', [
        '_csrf'         => $token,
        'product_id'    => (string) $productId,
        'movement_type' => 'restock',
        'quantity'      => '7',
        'reference'     => 'PO-TEST',
        'note'          => 'Integration restock',
    ]);
    t_status($restock, 302, 'inventory restock');
    t_location($restock, '/admin/inventory.php', 'inventory restock');
    t_same(22, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$productId]), 'stock after restock');

    $movement = one('SELECT * FROM inventory_movements WHERE product_id = ? AND reference_id = ? ORDER BY id DESC LIMIT 1', [$productId, 'PO-TEST']);
    t_assert($movement !== null, 'restock movement missing');
    t_same(7, (int) $movement['quantity_change'], 'restock movement quantity');

    $token = t_token($admin, '/admin/inventory.php');
    $adjust = $admin->postForm('/admin/inventory.php', [
        '_csrf'         => $token,
        'product_id'    => (string) $productId,
        'movement_type' => 'adjustment',
        'quantity'      => '-2',
        'reference'     => 'COUNT-TEST',
        'note'          => 'Integration adjustment',
    ]);
    t_status($adjust, 302, 'inventory adjustment');
    t_same(20, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$productId]), 'stock after adjustment');
    $movement = one('SELECT * FROM inventory_movements WHERE product_id = ? AND reference_id = ? ORDER BY id DESC LIMIT 1', [$productId, 'COUNT-TEST']);
    t_assert($movement !== null, 'adjustment movement missing');
    t_same(-2, (int) $movement['quantity_change'], 'adjustment movement quantity');
});

t_test('inventory rejects negative restocks and below-zero adjustments', function () use ($admin, &$productId): void {
    $token = t_token($admin, '/admin/inventory.php');
    $negativeRestock = $admin->postForm('/admin/inventory.php', [
        '_csrf'         => $token,
        'product_id'    => (string) $productId,
        'movement_type' => 'restock',
        'quantity'      => '-5',
    ]);
    t_status($negativeRestock, 200, 'negative restock');
    t_contains($negativeRestock->body, 'Restock quantity must be positive.', 'negative restock message');

    $token = t_token($admin, '/admin/inventory.php');
    $negativeAdjust = $admin->postForm('/admin/inventory.php', [
        '_csrf'         => $token,
        'product_id'    => (string) $productId,
        'movement_type' => 'adjustment',
        'quantity'      => '-999',
    ]);
    t_status($negativeAdjust, 200, 'negative adjustment');
    t_contains($negativeAdjust->body, 'Adjustment would make stock negative', 'negative adjustment message');
    t_same(20, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$productId]), 'stock after rejected inventory changes');
});

t_test('product deactivation removes the item from POS search', function () use ($admin, $cashier, &$productId): void {
    $token = t_token($admin, '/admin/products.php');
    $toggle = $admin->postForm('/admin/products.php', [
        '_csrf'  => $token,
        'action' => 'toggle',
        'id'     => (string) $productId,
    ]);
    t_status($toggle, 302, 'product toggle');
    t_same(0, (int) scalar('SELECT status FROM products WHERE id = ?', [$productId]), 'inactive status');

    $search = $cashier->get('/cashier/product_search.php?q=TEST-100');
    t_status($search, 200, 'inactive product search');
    $data = $search->json();
    t_same(0, count($data['products']), 'inactive product search results');

    $token = t_token($admin, '/admin/products.php');
    $admin->postForm('/admin/products.php', [
        '_csrf'  => $token,
        'action' => 'toggle',
        'id'     => (string) $productId,
    ]);
    t_same(1, (int) scalar('SELECT status FROM products WHERE id = ?', [$productId]), 'reactivated status');
});

t_test('categories can be created, edited, deactivated and protected from duplicates', function () use ($admin, $cashier, &$categoryId): void {
    $token = t_token($admin, '/admin/categories.php');
    $create = $admin->postForm('/admin/categories.php', [
        '_csrf'       => $token,
        'action'      => 'save',
        'id'          => '0',
        'name'        => 'Integration Category',
        'description' => 'Created by the integration suite.',
        'status'      => '1',
    ]);
    t_status($create, 302, 'category create');
    t_location($create, '/admin/categories.php', 'category create');

    $row = one('SELECT * FROM categories WHERE name = ?', ['Integration Category']);
    t_assert($row !== null, 'category was not inserted');
    $categoryId = (int) $row['id'];
    t_same(1, (int) $row['status'], 'category active status');

    $token = t_token($admin, '/admin/categories.php');
    $duplicate = $admin->postForm('/admin/categories.php', [
        '_csrf'  => $token,
        'action' => 'save',
        'id'     => '0',
        'name'   => 'Integration Category',
        'status' => '1',
    ]);
    t_status($duplicate, 302, 'duplicate category');
    $categoryPage = $admin->get('/admin/categories.php');
    t_contains($categoryPage->body, 'A category with that name already exists.', 'duplicate category message');
    t_same(1, count_where('categories', 'name = ?', ['Integration Category']), 'duplicate category count');

    $token = t_token($admin, '/admin/categories.php');
    $edit = $admin->postForm('/admin/categories.php', [
        '_csrf'       => $token,
        'action'      => 'save',
        'id'          => (string) $categoryId,
        'name'        => 'Integration Category Renamed',
        'description' => 'Updated by the integration suite.',
        'status'      => '1',
    ]);
    t_status($edit, 302, 'category edit');
    t_same('Integration Category Renamed', scalar('SELECT name FROM categories WHERE id = ?', [$categoryId]), 'edited category name');

    $token = t_token($admin, '/admin/categories.php');
    $toggle = $admin->postForm('/admin/categories.php', [
        '_csrf'  => $token,
        'action' => 'toggle',
        'id'     => (string) $categoryId,
    ]);
    t_status($toggle, 302, 'category toggle');
    t_same(0, (int) scalar('SELECT status FROM categories WHERE id = ?', [$categoryId]), 'inactive category status');
    $pos = $cashier->get('/cashier/pos.php');
    t_not_contains($pos->body, 'Integration Category Renamed', 'inactive category hidden from POS');

    $token = t_token($admin, '/admin/categories.php');
    $admin->postForm('/admin/categories.php', [
        '_csrf'  => $token,
        'action' => 'toggle',
        'id'     => (string) $categoryId,
    ]);
    t_same(1, (int) scalar('SELECT status FROM categories WHERE id = ?', [$categoryId]), 'reactivated category status');
});

t_test('users can be created, edited and deactivated with password validation', function () use ($admin, $cashier, $base, &$managedUserId): void {
    $forbidden = $cashier->get('/admin/users.php');
    t_status($forbidden, 403, 'cashier users page');
    t_contains($forbidden->body, 'Access denied', 'cashier users forbidden page');

    $token = t_token($admin, '/admin/user_form.php');
    $create = $admin->postForm('/admin/user_form.php', [
        '_csrf'            => $token,
        'name'             => 'Integration Cashier',
        'username'         => 'testcashier',
        'role'             => 'cashier',
        'status'           => '1',
        'password'         => 'UserPass!123',
        'password_confirm' => 'UserPass!123',
    ]);
    t_status($create, 302, 'user create');
    t_location($create, '/admin/users.php', 'user create');

    $row = one('SELECT * FROM users WHERE username = ?', ['testcashier']);
    t_assert($row !== null, 'user was not inserted');
    $managedUserId = (int) $row['id'];
    t_same('cashier', $row['role'], 'created user role');
    t_same(1, (int) $row['status'], 'created user status');
    t_assert(password_verify('UserPass!123', $row['password_hash']), 'created user password hash');

    $token = t_token($admin, '/admin/user_form.php');
    $duplicate = $admin->postForm('/admin/user_form.php', [
        '_csrf'            => $token,
        'name'             => 'Duplicate Cashier',
        'username'         => 'testcashier',
        'role'             => 'cashier',
        'status'           => '1',
        'password'         => 'UserPass!123',
        'password_confirm' => 'UserPass!123',
    ]);
    t_status($duplicate, 200, 'duplicate username');
    t_contains($duplicate->body, 'That username is already taken.', 'duplicate username message');
    t_same(1, count_where('users', 'username = ?', ['testcashier']), 'duplicate username count');

    $token = t_token($admin, '/admin/user_form.php');
    $shortPassword = $admin->postForm('/admin/user_form.php', [
        '_csrf'            => $token,
        'name'             => 'Short Password',
        'username'         => 'shortpass',
        'role'             => 'cashier',
        'status'           => '1',
        'password'         => 'short',
        'password_confirm' => 'short',
    ]);
    t_status($shortPassword, 200, 'short password');
    t_contains($shortPassword->body, 'Password must be at least 8 characters.', 'short password message');
    t_same(0, count_where('users', 'username = ?', ['shortpass']), 'short password user count');

    $token = t_token($admin, '/admin/user_form.php?id=' . $managedUserId);
    $edit = $admin->postForm('/admin/user_form.php?id=' . $managedUserId, [
        '_csrf'            => $token,
        'name'             => 'Integration Cashier Updated',
        'username'         => 'testcashier',
        'role'             => 'cashier',
        'status'           => '1',
        'password'         => '',
        'password_confirm' => '',
    ]);
    t_status($edit, 302, 'user edit');
    t_same('Integration Cashier Updated', scalar('SELECT name FROM users WHERE id = ?', [$managedUserId]), 'edited user name');

    $managedLogin = t_login(new HttpSession($base), 'testcashier', 'UserPass!123');
    t_status($managedLogin, 302, 'managed user login');
    t_location($managedLogin, '/cashier/pos.php', 'managed user login');

    $token = t_token($admin, '/admin/users.php');
    $toggle = $admin->postForm('/admin/users.php', [
        '_csrf'  => $token,
        'action' => 'toggle',
        'id'     => (string) $managedUserId,
    ]);
    t_status($toggle, 302, 'user deactivate');
    t_same(0, (int) scalar('SELECT status FROM users WHERE id = ?', [$managedUserId]), 'deactivated user status');

    $deactivated = t_login(new HttpSession($base), 'testcashier', 'UserPass!123');
    t_status($deactivated, 200, 'deactivated user login');
    t_contains($deactivated->body, 'This account has been deactivated.', 'deactivated user message');
});

$fixtures = [
    'cash'  => insert_product('TEST-CASH', 'Test Cash Product', '10.00', 50, 5),
    'alert' => insert_product('TEST-LOWALERT', 'Test Low Alert Product', '5.00', 0, 5),
    'low'   => insert_product('TEST-LOW', 'Test Low Stock Product', '25.50', 5, 5),
    'idem'  => insert_product('TEST-IDEM', 'Test Idempotency Product', '20.00', 10, 5),
    'off'   => insert_product('TEST-OFF', 'Test Inactive Product', '5.00', 10, 5, 0),
    'roll'  => insert_product('TEST-ROLL', 'Test Rollback Product', '15.00', 10, 5),
    'conc'  => insert_product('TEST-CONC', 'Test Concurrency Product', '30.00', 5, 5),
    'card'  => insert_product('TEST-CARD', 'Test Card Product', '12.34', 20, 5),
];

t_test('dashboard shows low-stock and out-of-stock alerts', function () use ($admin): void {
    $page = $admin->get('/admin/dashboard.php');
    t_status($page, 200, 'dashboard low-stock alert');
    t_contains($page->body, 'Low-stock alerts', 'dashboard low-stock alert panel');
    t_contains($page->body, 'Test Low Alert Product', 'dashboard low-stock product');
    $lowCount = (int) scalar('SELECT COUNT(*) FROM products WHERE status = 1 AND stock_quantity <= low_stock_threshold');
    t_assert($lowCount > 0, 'low-stock database count');
});

t_test('POS loads with checkout configuration', function () use ($cashier): void {
    $page = $cashier->get('/cashier/pos.php');
    t_status($page, 200, 'POS page');
    $config = t_pos_config($page->body);
    t_assert(str_ends_with((string) $config['apiCheckout'], '/cashier/checkout.php'), 'checkout endpoint path');
    t_assert(str_ends_with((string) $config['apiProducts'], '/cashier/product_search.php'), 'product endpoint path');
    t_assert(!empty($config['csrf']), 'POS CSRF token');
    t_same(false, (bool) $config['taxEnabled'], 'seeded tax setting');
});

t_test('product search returns matching active products and filters categories', function () use ($cashier): void {
    $response = $cashier->get('/cashier/product_search.php?q=TEST-CASH&category=1');
    t_status($response, 200, 'product search');
    $data = $response->json();
    t_same(1, count($data['products']), 'product search count');
    t_same('TEST-CASH', $data['products'][0]['code'], 'product search SKU');
    t_same(50, $data['products'][0]['stock'], 'product search stock');

    $empty = $cashier->get('/cashier/product_search.php?q=NO-SUCH-PRODUCT');
    t_same([], $empty->json()['products'], 'unmatched product search');
});

t_test('POS API endpoints require login, the right method and CSRF', function () use ($base, $cashier): void {
    $anon = new HttpSession($base);
    $search = $anon->get('/cashier/product_search.php?q=TEST-CASH', ['headers' => ['Accept: application/json']]);
    t_status($search, 401, 'anonymous product search');
    t_same(false, $search->json()['ok'], 'anonymous search ok flag');

    $wrongMethod = $cashier->get('/cashier/checkout.php');
    t_status($wrongMethod, 405, 'GET checkout');

    $wrongMethod = $cashier->postForm('/cashier/product_search.php', []);
    t_status($wrongMethod, 405, 'POST product search');

    $missingCsrf = $cashier->postJson('/cashier/checkout.php', checkout_payload(1, 1, ['method' => 'cash', 'tendered' => 100], 'csrf-test'));
    t_status($missingCsrf, 419, 'checkout without CSRF');
});

t_test('a cash sale calculates totals, records payment and deducts stock', function () use ($cashier, $fixtures, &$cashSaleId, &$cashReceipt): void {
    global $cashierToken;
    $pos = $cashier->get('/cashier/pos.php');
    $cashierToken = t_pos_config($pos->body)['csrf'];

    $response = $cashier->postJson(
        '/cashier/checkout.php',
        checkout_payload($fixtures['cash'], 2, ['method' => 'cash', 'tendered' => '50.00'], 'cash-sale-1'),
        ['headers' => ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $cashierToken]]
    );
    t_status($response, 200, 'cash checkout');
    $data = $response->json();
    t_same(true, $data['ok'], 'cash checkout ok');
    t_same(false, $data['duplicate'], 'cash checkout duplicate flag');
    t_money_field($data, 'total', '20.00', 'cash checkout');
    t_money_field($data, 'change', '30.00', 'cash checkout');
    t_assert(str_starts_with($data['receipt_number'], 'RCP-'), 'receipt number format');
    $cashSaleId = (int) $data['sale_id'];
    $cashReceipt = $data['receipt_number'];

    $sale = one('SELECT * FROM sales WHERE id = ?', [$cashSaleId]);
    t_same('completed', $sale['status'], 'cash sale status');
    t_same('20.00', $sale['subtotal'], 'cash sale subtotal');
    t_same('20.00', $sale['total_amount'], 'cash sale total');
    $payment = one('SELECT * FROM payments WHERE sale_id = ?', [$cashSaleId]);
    t_same('cash', $payment['payment_method'], 'cash payment method');
    t_same('50.00', $payment['tendered_amount'], 'cash tendered');
    t_same('30.00', $payment['change_amount'], 'cash change');
    t_same('paid', $payment['payment_status'], 'cash payment status');
    t_same(48, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['cash']]), 'stock after cash sale');
    $movement = one('SELECT * FROM inventory_movements WHERE sale_id = ? AND product_id = ?', [$cashSaleId, $fixtures['cash']]);
    t_assert($movement !== null, 'cash sale movement missing');
    t_same(-2, (int) $movement['quantity_change'], 'cash sale movement quantity');
    t_same($cashReceipt, $movement['reference_id'], 'movement receipt reference');
});

t_test('insufficient cash is rejected without changing sales or stock', function () use ($cashier, $fixtures, &$cashierToken): void {
    $beforeSales = count_where('sales');
    $beforeStock = (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['cash']]);
    $response = $cashier->postJson(
        '/cashier/checkout.php',
        checkout_payload($fixtures['cash'], 2, ['method' => 'cash', 'tendered' => '10.00'], 'cash-fail-1'),
        ['headers' => ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $cashierToken]]
    );
    t_status($response, 422, 'insufficient cash');
    t_contains($response->json()['message'], 'less than the total due', 'insufficient cash message');
    t_same($beforeSales, count_where('sales'), 'sales after insufficient cash');
    t_same($beforeStock, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['cash']]), 'stock after insufficient cash');
});

t_test('invalid cart lines, unavailable products and insufficient stock are rejected', function () use ($cashier, $fixtures, &$cashierToken): void {
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $cashierToken];
    $beforeSales = count_where('sales');
    $beforeCashStock = (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['cash']]);
    $beforeLowStock = (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['low']]);

    $badQuantity = $cashier->postJson('/cashier/checkout.php', checkout_payload($fixtures['cash'], 0, ['method' => 'cash', 'tendered' => '100'], 'bad-qty'), ['headers' => $headers]);
    t_status($badQuantity, 422, 'zero quantity');
    t_contains($badQuantity->json()['message'], 'between 1 and 10,000', 'zero quantity message');

    $missingProduct = $cashier->postJson('/cashier/checkout.php', checkout_payload(999999, 1, ['method' => 'cash', 'tendered' => '100'], 'missing-product'), ['headers' => $headers]);
    t_status($missingProduct, 422, 'missing product');
    t_contains($missingProduct->json()['message'], 'no longer exists', 'missing product message');

    $unavailable = $cashier->postJson('/cashier/checkout.php', checkout_payload($fixtures['off'], 1, ['method' => 'cash', 'tendered' => '100'], 'inactive-product'), ['headers' => $headers]);
    t_status($unavailable, 422, 'inactive product');
    t_contains($unavailable->json()['message'], 'no longer available for sale', 'inactive product message');

    $tooMuch = $cashier->postJson('/cashier/checkout.php', checkout_payload($fixtures['low'], 6, ['method' => 'cash', 'tendered' => '500'], 'stock-overflow'), ['headers' => $headers]);
    t_status($tooMuch, 422, 'stock overflow');
    t_contains($tooMuch->json()['message'], 'Only 5 left', 'stock overflow message');

    $badMethod = $cashier->postJson('/cashier/checkout.php', checkout_payload($fixtures['cash'], 1, ['method' => 'cheque', 'tendered' => '100'], 'bad-method'), ['headers' => $headers]);
    t_status($badMethod, 422, 'bad payment method');
    t_contains($badMethod->json()['message'], 'valid payment method', 'bad payment method message');

    $badDiscount = $cashier->postJson('/cashier/checkout.php', checkout_payload($fixtures['cash'], 1, ['method' => 'cash', 'tendered' => '100'], 'bad-discount', 'abc'), ['headers' => $headers]);
    t_status($badDiscount, 422, 'bad discount');
    t_contains($badDiscount->json()['message'], 'valid discount', 'bad discount message');

    t_same($beforeSales, count_where('sales'), 'sales after rejected carts');
    t_same($beforeCashStock, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['cash']]), 'cash stock after rejected carts');
    t_same($beforeLowStock, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['low']]), 'low stock after rejected carts');
});

t_test('a card sale records the exact total with no cash change', function () use ($cashier, $fixtures, &$cashierToken, &$cardSaleId, &$cardReceipt): void {
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $cashierToken];
    $response = $cashier->postJson(
        '/cashier/checkout.php',
        checkout_payload($fixtures['card'], 2, ['method' => 'card', 'tendered' => '0', 'reference' => 'CARD-REF-1'], 'card-sale-1'),
        ['headers' => $headers]
    );
    t_status($response, 200, 'card checkout');
    $data = $response->json();
    t_money_field($data, 'total', '24.68', 'card checkout');
    t_money_field($data, 'change', '0.00', 'card checkout');
    $cardSaleId = (int) $data['sale_id'];
    $cardReceipt = $data['receipt_number'];
    $payment = one('SELECT * FROM payments WHERE sale_id = ?', [$cardSaleId]);
    t_same('card', $payment['payment_method'], 'card method');
    t_same('24.68', $payment['tendered_amount'], 'card tendered');
    t_same('0.00', $payment['change_amount'], 'card change');
    t_same('CARD-REF-1', $payment['reference_no'], 'card reference');
    t_same(18, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['card']]), 'stock after card sale');
});

t_test('idempotency prevents a duplicate sale', function () use ($cashier, $fixtures, &$cashierToken, &$idemSaleId, &$idemReceipt): void {
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $cashierToken];
    $first = $cashier->postJson(
        '/cashier/checkout.php',
        checkout_payload($fixtures['idem'], 1, ['method' => 'cash', 'tendered' => '50.00'], 'idem-test-1'),
        ['headers' => $headers]
    );
    t_status($first, 200, 'first idempotent checkout');
    $firstData = $first->json();
    $idemSaleId = (int) $firstData['sale_id'];
    $idemReceipt = $firstData['receipt_number'];
    t_same(false, $firstData['duplicate'], 'first idempotent duplicate flag');

    $second = $cashier->postJson(
        '/cashier/checkout.php',
        checkout_payload($fixtures['idem'], 1, ['method' => 'cash', 'tendered' => '50.00'], 'idem-test-1'),
        ['headers' => $headers]
    );
    t_status($second, 200, 'second idempotent checkout');
    $secondData = $second->json();
    t_same(true, $secondData['duplicate'], 'second idempotent duplicate flag');
    t_same($idemSaleId, (int) $secondData['sale_id'], 'idempotent sale id');
    t_same($idemReceipt, $secondData['receipt_number'], 'idempotent receipt');
    t_same(1, count_where('sales', 'idempotency_key = ?', ['idem-test-1']), 'idempotent sale count');
    t_same(9, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['idem']]), 'stock after idempotent retry');
});

t_test('a second limited-stock sale is rejected after the first succeeds', function () use ($cashier, $fixtures, &$cashierToken): void {
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $cashierToken];
    $first = $cashier->postJson('/cashier/checkout.php', checkout_payload($fixtures['conc'], 3, ['method' => 'cash', 'tendered' => '200'], 'conc-seq-1'), ['headers' => $headers]);
    t_status($first, 200, 'first limited-stock sale');
    $second = $cashier->postJson('/cashier/checkout.php', checkout_payload($fixtures['conc'], 3, ['method' => 'cash', 'tendered' => '200'], 'conc-seq-2'), ['headers' => $headers]);
    t_status($second, 422, 'second limited-stock sale');
    t_contains($second->json()['message'], 'Only 2 left', 'second limited-stock message');
    t_same(2, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['conc']]), 'stock after limited-stock sales');
});

t_test('receipt and sale detail show the completed transaction', function () use ($cashier, $admin, &$cashSaleId, &$cashReceipt): void {
    $receipt = $cashier->get('/cashier/receipt.php?id=' . $cashSaleId);
    t_status($receipt, 200, 'cashier receipt');
    t_contains($receipt->body, $cashReceipt, 'receipt number');
    t_contains($receipt->body, 'Test Cash Product', 'receipt product');
    t_contains($receipt->body, '20.00', 'receipt total');

    $embed = $cashier->get('/cashier/receipt.php?id=' . $cashSaleId . '&embed=1');
    t_status($embed, 200, 'embedded receipt');
    t_contains($embed->body, $cashReceipt, 'embedded receipt number');

    $adminView = $admin->get('/admin/sale_view.php?id=' . $cashSaleId);
    t_status($adminView, 200, 'admin sale view');
    t_contains($adminView->body, $cashReceipt, 'sale view receipt');
    t_contains($adminView->body, 'Test Cash Product', 'sale view item');

    $sales = $admin->get('/admin/sales.php?q=' . urlencode($cashReceipt));
    t_status($sales, 200, 'sales search');
    t_contains($sales->body, $cashReceipt, 'sales search receipt');
});

t_test('historical sale items keep their product snapshots after a rename', function () use ($cashier, $fixtures, &$cashSaleId, &$cashReceipt): void {
    q('UPDATE products SET name = ?, price = ? WHERE id = ?', ['Renamed After Sale', '999.00', $fixtures['cash']]);
    $receipt = $cashier->get('/cashier/receipt.php?id=' . $cashSaleId);
    t_status($receipt, 200, 'snapshot receipt');
    t_contains($receipt->body, 'Test Cash Product', 'receipt name snapshot');
    t_not_contains($receipt->body, 'Renamed After Sale', 'receipt renamed product leak');
    t_contains($receipt->body, '10.00', 'receipt price snapshot');
    t_contains($receipt->body, $cashReceipt, 'snapshot receipt number');
});

t_test('a second cashier cannot view another cashier\'s receipt', function () use ($base, $fixtures, &$cashSaleId): void {
    $hash = password_hash('Cashier2@123', PASSWORD_DEFAULT);
    q(
        'INSERT INTO users (name, username, password_hash, role, status) VALUES (?, ?, ?, "cashier", 1)',
        ['Second Cashier', 'cashier2', $hash]
    );
    $other = new HttpSession($base);
    $login = t_login($other, 'cashier2', 'Cashier2@123');
    t_status($login, 302, 'cashier2 login');
    $receipt = $other->get('/cashier/receipt.php?id=' . $cashSaleId);
    t_status($receipt, 404, 'other cashier receipt');
    t_contains($receipt->body, 'Receipt not found.', 'other cashier receipt message');
});

t_test('reports show daily totals, best sellers, transaction details and date filters', function () use ($admin, &$cashReceipt, &$cardReceipt, &$cardSaleId): void {
    $today = date('Y-m-d');
    $revenue = (float) scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE status = "completed" AND DATE(created_at) = ?', [$today]);
    t_assert($revenue > 0, 'today revenue should be positive');

    $report = $admin->get('/admin/reports.php?from=' . $today . '&to=' . $today);
    t_status($report, 200, 'today report');
    t_contains($report->body, 'Gross revenue', 'report heading');
    t_contains($report->body, number_format($revenue, 2), 'report revenue');
    t_contains($report->body, $cashReceipt, 'report cash transaction');
    t_contains($report->body, $cardReceipt, 'report card transaction');
    t_contains($report->body, 'Test Cash Product', 'report best seller');

    q('UPDATE sales SET created_at = ? WHERE id = ?', ['2020-01-02 12:00:00', $cardSaleId]);
    $todayReport = $admin->get('/admin/reports.php?from=' . $today . '&to=' . $today);
    t_not_contains($todayReport->body, $cardReceipt, 'today report excludes old sale');
    $oldReport = $admin->get('/admin/reports.php?from=2020-01-02&to=2020-01-02');
    t_status($oldReport, 200, 'old report');
    t_contains($oldReport->body, $cardReceipt, 'old report includes old sale');
    t_contains($oldReport->body, '24.68', 'old report revenue');
});

t_test('voiding a sale restores stock, refunds payment and excludes it from reports', function () use ($admin, $fixtures, &$idemSaleId, &$idemReceipt): void {
    $today = date('Y-m-d');
    $revenueBefore = (float) scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE status = "completed" AND DATE(created_at) = ?', [$today]);
    $token = t_token($admin, '/admin/sale_view.php?id=' . $idemSaleId);
    $void = $admin->postForm('/admin/sale_view.php?id=' . $idemSaleId, [
        '_csrf' => $token,
        'id'    => (string) $idemSaleId,
    ]);
    t_status($void, 302, 'void sale');
    t_location($void, '/admin/sale_view.php', 'void sale');

    t_same('voided', scalar('SELECT status FROM sales WHERE id = ?', [$idemSaleId]), 'voided status');
    t_same('refunded', scalar('SELECT payment_status FROM payments WHERE sale_id = ?', [$idemSaleId]), 'refunded payment');
    t_same(10, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['idem']]), 'stock after void');
    $movement = one('SELECT * FROM inventory_movements WHERE sale_id = ? AND movement_type = "adjustment"', [$idemSaleId]);
    t_assert($movement !== null, 'void movement missing');
    t_same(1, (int) $movement['quantity_change'], 'void movement quantity');

    $revenueAfter = (float) scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE status = "completed" AND DATE(created_at) = ?', [$today]);
    t_same(round($revenueBefore - 20.00, 2), round($revenueAfter, 2), 'revenue after void');
    $view = $admin->get('/admin/sale_view.php?id=' . $idemSaleId);
    t_contains($view->body, 'Voided', 'voided sale view');
    $report = $admin->get('/admin/reports.php?from=' . $today . '&to=' . $today);
    t_not_contains($report->body, $idemReceipt, 'report excludes voided sale');
    $sales = $admin->get('/admin/sales.php?status=voided');
    t_contains($sales->body, $idemReceipt, 'voided sales list');
});

t_test('dashboard reflects the reportable revenue', function () use ($admin): void {
    $today = date('Y-m-d');
    $revenue = (float) scalar('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE status = "completed" AND DATE(created_at) = ?', [$today]);
    $dashboard = $admin->get('/admin/dashboard.php');
    t_status($dashboard, 200, 'dashboard after sales');
    t_contains($dashboard->body, number_format($revenue, 2), 'dashboard revenue');
});

t_test('money calculations apply discount, tax and rounding correctly', function (): void {
    $lines = [[
        'product_id' => 1,
        'name'       => 'Calculation Item',
        'code'       => 'CALC-1',
        'price'      => '10.00',
        'quantity'   => 3,
        'stock'      => 100,
    ]];
    $plain = calculate_totals($lines, [
        'tax_enabled'      => false,
        'tax_rate'         => 0,
        'discount_enabled' => false,
        'discount_max'     => 100,
    ], 50.0);
    t_same(3000, $plain['subtotal'], 'plain subtotal');
    t_same(0, $plain['discount'], 'disabled discount');
    t_same(3000, $plain['total'], 'plain total');

    $discounted = calculate_totals($lines, [
        'tax_enabled'      => true,
        'tax_rate'         => 12,
        'discount_enabled' => true,
        'discount_max'     => 100,
    ], 10.0);
    t_same(300, $discounted['discount'], 'discount cents');
    t_same(324, $discounted['tax'], 'tax cents');
    t_same(3024, $discounted['total'], 'discounted total');

    $capped = calculate_totals($lines, [
        'tax_enabled'      => true,
        'tax_rate'         => 12,
        'discount_enabled' => true,
        'discount_max'     => 15,
    ], 50.0);
    t_same(15.0, (float) $capped['discount_percent'], 'discount cap percent');
    t_same(450, $capped['discount'], 'capped discount cents');
    t_same(306, $capped['tax'], 'capped tax cents');
    t_same(2856, $capped['total'], 'capped total');
});

t_test('a database failure during checkout rolls back stock and sale rows', function () use ($fixtures): void {
    $beforeSales = count_where('sales');
    $beforeStock = (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['roll']]);
    $beforeMovements = count_where('inventory_movements', 'product_id = ?', [$fixtures['roll']]);
    $rollbackLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos-rollback-' . getmypid() . '.log';
    $oldErrorLog = ini_set('error_log', $rollbackLog);
    try {
        $result = checkout(
            [['product_id' => $fixtures['roll'], 'quantity' => 1]],
            ['method' => 'cash', 'tendered' => 100],
            999999,
            0.0,
            'rollback-test-1'
        );
    } finally {
        ini_set('error_log', (string) $oldErrorLog);
        @unlink($rollbackLog);
    }
    t_same(false, $result['ok'], 'rollback checkout ok flag');
    t_same($beforeSales, count_where('sales'), 'sales after rollback');
    t_same($beforeStock, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$fixtures['roll']]), 'stock after rollback');
    t_same($beforeMovements, count_where('inventory_movements', 'product_id = ?', [$fixtures['roll']]), 'movements after rollback');
});

t_test('two concurrent checkouts cannot oversell limited stock', function () use ($root, $fixtures, &$concSaleId): void {
    $productId = $fixtures['conc'];
    q('UPDATE products SET stock_quantity = 5 WHERE id = ?', [$productId]);
    $beforeSales = (int) scalar('SELECT COUNT(*) FROM sales s JOIN sale_items si ON si.sale_id = s.id WHERE si.product_id = ? AND s.status = "completed"', [$productId]);

    $startAt = microtime(true) + 4.0;
    $jobs = [];
    for ($i = 1; $i <= 2; $i++) {
        $resultFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos-conc-' . getmypid() . '-' . $i . '.json';
        @unlink($resultFile);
        $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos-conc-' . getmypid() . '-' . $i . '.log';
        $command = [
            PHP_BINARY,
            $root . '/tests/concurrency_worker.php',
            (string) $startAt,
            (string) $productId,
            '3',
            $resultFile,
            'conc-race-' . $i,
        ];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ], $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start concurrency worker.');
        }
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $jobs[] = [$process, $resultFile, $logFile];
    }

    $results = [];
    foreach ($jobs as [$process, $resultFile, $logFile]) {
        $deadline = microtime(true) + 25;
        while (microtime(true) < $deadline && !is_file($resultFile)) {
            usleep(100000);
        }
        proc_close($process);
        if (!is_file($resultFile)) {
            $log = is_file($logFile) ? file_get_contents($logFile) : '';
            throw new RuntimeException('Concurrency worker produced no result. Log: ' . $log);
        }
        $data = json_decode((string) file_get_contents($resultFile), true);
        if (!is_array($data)) {
            throw new RuntimeException('Concurrency worker returned invalid JSON.');
        }
        $results[] = $data;
    }

    $successes = array_values(array_filter($results, static fn(array $r): bool => !empty($r['ok'])));
    $failures = array_values(array_filter($results, static fn(array $r): bool => empty($r['ok'])));
    t_same(1, count($successes), 'concurrent successful sales');
    t_same(1, count($failures), 'concurrent failed sales');
    t_same(2, (int) scalar('SELECT stock_quantity FROM products WHERE id = ?', [$productId]), 'stock after concurrent checkouts');
    t_same($beforeSales + 1, (int) scalar('SELECT COUNT(*) FROM sales s JOIN sale_items si ON si.sale_id = s.id WHERE si.product_id = ? AND s.status = "completed"', [$productId]), 'concurrent sale count');
    $concSaleId = (int) $successes[0]['sale_id'];
});

echo "\n" . str_repeat('-', 56) . "\n";
echo "Passed: {$passed}  Failed: {$failed}  Total: " . ($passed + $failed) . "\n";
echo "Test database: " . DB_NAME . "\n";
echo 'Server log: ' . $serverLog . "\n";

exit($failed === 0 ? 0 : 1);
