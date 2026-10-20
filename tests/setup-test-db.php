<?php
/**
 * Recreate the isolated integration-test database from schema.sql + seed.sql.
 *
 * Usage:
 *   php tests/setup-test-db.php
 *
 * The database name must end in "_test"; this script refuses to drop any
 * other database. Override the connection with TEST_DB_* environment vars.
 */

require __DIR__ . '/config.test.php';

if (!preg_match('/^[A-Za-z0-9_]+$/', DB_NAME) || substr(DB_NAME, -5) !== '_test') {
    fwrite(STDERR, "Refusing to reset unsafe database name: " . DB_NAME . "\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/database/schema.sql');
$seed   = file_get_contents($root . '/database/seed.sql');
if ($schema === false || $seed === false) {
    fwrite(STDERR, "Unable to read schema.sql or seed.sql\n");
    exit(1);
}

$replace = function (string $sql): string {
    return str_replace('`retail_pos`', '`' . DB_NAME . '`', $sql);
};

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int) DB_PORT);
$mysqli->set_charset(DB_CHARSET);

$mysqli->query('DROP DATABASE IF EXISTS `' . DB_NAME . '`');

foreach (['schema' => $replace($schema), 'seed' => $replace($seed)] as $label => $sql) {
    if (!$mysqli->multi_query($sql)) {
        fwrite(STDERR, "Failed to import {$label}: " . $mysqli->error . "\n");
        exit(1);
    }
    while ($mysqli->more_results() && $mysqli->next_result()) {
        // Drain every result so the next multi_query starts cleanly.
    }
    if ($mysqli->errno) {
        fwrite(STDERR, "Failed while importing {$label}: " . $mysqli->error . "\n");
        exit(1);
    }
}

$count = (int) $mysqli->query('SELECT COUNT(*) FROM `' . DB_NAME . '`.products')->fetch_row()[0];
$mysqli->close();

echo 'Test database ' . DB_NAME . ' recreated with ' . $count . " products.\n";
