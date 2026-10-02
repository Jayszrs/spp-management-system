<?php
/** Verify the idempotency guard on an explicitly selected disposable database. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/financial_request.php';

$database = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/i', $database)) {
    throw new RuntimeException('Tes keuangan_request hanya boleh dijalankan pada clone db_spp_audit_* dengan flag tes.');
}
$db = new mysqli(getenv('SPP_DB_HOST') ?: 'localhost', getenv('SPP_DB_USER') ?: 'root',
    getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '', $database,
    (int)(getenv('SPP_DB_PORT') ?: 3306));
$expectInvalid = in_array('--expect-incompatible', $argv, true);
if ($expectInvalid) {
    try {
        financial_request_assert_ready($db);
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), 'Skema pengaman transaksi tidak sesuai')) {
            echo "PASS: skema tidak kompatibel ditolak sebelum mutasi.\n";
            exit(0);
        }
        throw $error;
    }
    throw new RuntimeException('Skema tidak kompatibel lolos pemeriksaan.');
}

financial_request_assert_ready($db);
$key = bin2hex(random_bytes(16));
$db->begin_transaction();
try {
    $stmt = $db->prepare("INSERT INTO keuangan_request(request_key,unit_id,aksi,operator_id)
        VALUES(?,1,'tabungan_masuk',1)");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
    financial_request_complete($db, $key, 3000000000);
    $stmt = $db->prepare('SELECT referensi_id FROM keuangan_request WHERE request_key=?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $reference = (string)($stmt->get_result()->fetch_assoc()['referensi_id'] ?? '');
    $stmt->close();
    if ($reference !== '3000000000') throw new RuntimeException('Referensi BIGINT terpotong.');
    echo "PASS: tabel InnoDB dengan primary key unik dan referensi BIGINT menerima ID di atas INT.\n";
} finally {
    $db->rollback();
}
