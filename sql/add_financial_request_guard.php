<?php
/**
 * Inspect or install the financial request guard using the shared migration gate.
 * The SQL definition remains the single source for creating/upgrading the table.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/financial_request.php';
require_once __DIR__ . '/readiness_migration_guard.php';
require_once __DIR__ . '/legacy_sql_definition.php';

$apply = in_array('--apply', $argv, true);
$table = $koneksi->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='keuangan_request'")->fetch_assoc();
if (!$table) {
    $state = 'missing';
} else {
    try {
        financial_request_assert_ready($koneksi);
        $state = 'ready';
    } catch (RuntimeException $error) {
        $state = 'existing-but-not-ready';
    }
}
echo "keuangan_request: {$state} on " . DB_NAME . PHP_EOL;
if (!$apply) {
    echo "AUDIT ONLY: database " . DB_NAME . " tidak diubah.\n";
    exit;
}

readiness_migration_assert_apply_allowed($argv, DB_NAME);

$sql = legacy_sql_definition('add_financial_request_guard.sql');
$sql = preg_replace('/^DELIMITER\s+\/\/\s*$/mi', '', $sql);
$sql = preg_replace('/^DELIMITER\s+;\s*$/mi', '', $sql);
$statements = array_values(array_filter(array_map('trim', explode('//', $sql)),
    static fn(string $statement): bool => $statement !== ''));
if (count($statements) !== 4
    || !str_contains($statements[0], 'DROP PROCEDURE IF EXISTS install_keuangan_request_guard')
    || !str_contains($statements[1], 'CREATE PROCEDURE install_keuangan_request_guard()')
    || $statements[2] !== 'CALL install_keuangan_request_guard()'
    || $statements[3] !== 'DROP PROCEDURE install_keuangan_request_guard') {
    throw new RuntimeException('Format definisi migrasi keuangan_request berubah; periksa sebelum menjalankan DDL.');
}

$procedureCreated = false;
try {
    $koneksi->query($statements[0]);
    $koneksi->query($statements[1]);
    $procedureCreated = true;
    $koneksi->query($statements[2]);
} finally {
    if ($procedureCreated) {
        $koneksi->query($statements[3]);
    }
}
financial_request_assert_ready($koneksi);
echo "OK: keuangan_request siap; DDL hanya dijalankan pada database yang diizinkan.\n";
