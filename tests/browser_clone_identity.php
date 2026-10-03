<?php
// This endpoint is available only to local browser tests on an audit clone.
$name = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $name)
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../koneksi.php';
$actual = (string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0];
if ($actual !== $name) {
    http_response_code(503);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['database' => $actual], JSON_THROW_ON_ERROR);
