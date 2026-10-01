<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$database = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_test_demo_payment_[a-z0-9_]+$/D', $database)) {
    fwrite(STDERR, "FAILED: jalankan tes seed hanya lewat CLI pada clone baru db_spp_test_demo_payment_* dengan SPP_TEST_ALLOW_MUTATION=1.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function demo_payment_seed_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function demo_payment_seed_run(mysqli $db, string $sql): void {
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do {
        if ($result = $db->store_result()) $result->free();
    } while ($db->more_results() && $db->next_result());
    if ($db->error !== '') throw new RuntimeException($db->error);
}

$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$pass = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$port = filter_var(getenv('SPP_DB_PORT') ?: '3306', FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($port === false) throw new RuntimeException('Port database latihan tidak valid.');
$db = new mysqli($host, $user, $pass, '', $port);
$db->set_charset('utf8mb4');
$createdDatabase = false;

try {
    // CREATE without IF NOT EXISTS refuses to overwrite a previous or unrelated clone.
    $db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdDatabase = true;
    require_once __DIR__ . '/../sql/schema_source.php';
    $schema = spp_schema_source();
    $schema = str_replace(
        ['CREATE DATABASE IF NOT EXISTS `db_spp`', 'USE `db_spp`'],
        ["CREATE DATABASE IF NOT EXISTS `{$database}`", "USE `{$database}`"],
        $schema,
        $replacementCount
    );
    demo_payment_seed_assert($replacementCount === 2, 'Kontrak nama database schema.sql berubah.');
    demo_payment_seed_run($db, $schema);
    $db->select_db($database);

    demo_payment_seed_runner($database, 'reset_demo_students_and_finance.sql');
    demo_payment_seed_runner($database, 'seed_students_psb.sql');
    demo_payment_seed_runner($database, 'seed_demo_payments.sql');

    $counts = $db->query("SELECT
        (SELECT COUNT(*) FROM bayar) pembayaran,
        (SELECT COUNT(*) FROM bayar WHERE KETERANGAN LIKE 'SEED-DEMO-SPP-%') spp,
        (SELECT COUNT(*) FROM bayar WHERE KETERANGAN LIKE 'SEED-DEMO-PSB-%') psb,
        (SELECT COUNT(*) FROM bayar WHERE KETERANGAN LIKE 'SEED-DEMO-TITIPAN-%') titipan,
        (SELECT COUNT(*) FROM bayar WHERE KETERANGAN LIKE 'SEED-DEMO-LAIN-%') biaya_lain,
        (SELECT COUNT(*) FROM spp_alokasi) alokasi_spp,
        (SELECT COUNT(*) FROM bayar_komite) bayar_komite,
        (SELECT COUNT(*) FROM bayar_du) bayar_du,
        (SELECT COUNT(*) FROM bayar_biaya_lain) bayar_biaya_lain,
        (SELECT COUNT(*) FROM titipan_spp_mutasi) mutasi_titipan,
        (SELECT COUNT(*) FROM transaksi_m) + (SELECT COUNT(*) FROM transaksi_k) mutasi_tabungan")->fetch_assoc();

    demo_payment_seed_assert((int)$counts['pembayaran'] === 1000, 'Seeder harus membuat tepat 1.000 pembayaran.');
    demo_payment_seed_assert((int)$counts['spp'] === 970 && (int)$counts['psb'] === 6 && (int)$counts['titipan'] === 12 && (int)$counts['biaya_lain'] === 12, 'Komposisi 1.000 pembayaran demo salah.');
    demo_payment_seed_assert((int)$counts['alokasi_spp'] === 970 && (int)$counts['bayar_komite'] === 970, 'Relasi SPP dan Komite demo tidak lengkap.');
    demo_payment_seed_assert((int)$counts['bayar_du'] === 100 && (int)$counts['bayar_biaya_lain'] === 12 && (int)$counts['mutasi_titipan'] === 12, 'Rincian Daftar Ulang, Biaya Lain, atau Titipan salah.');
    demo_payment_seed_assert((int)$counts['mutasi_tabungan'] === 0, 'Seeder pembayaran tidak boleh membuat mutasi tabungan.');

    // Pemanggilan ulang harus ditolak oleh guard, tanpa menambah transaksi.
    demo_payment_seed_runner($database, 'seed_demo_payments.sql');
    demo_payment_seed_assert((int)$db->query('SELECT COUNT(*) total FROM bayar')->fetch_assoc()['total'] === 1000, 'Seeder pembayaran tidak boleh menambah transaksi saat dijalankan ulang.');

    $invalid = $db->query("SELECT
        (SELECT COUNT(*) FROM (
            SELECT ts.id FROM tagihan_spp ts
            LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id
            LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active'
            GROUP BY ts.id,ts.nominal_tagihan
            HAVING COALESCE(SUM(CASE WHEN ab.id IS NOT NULL THEN a.nominal_dari_bayar+a.nominal_dari_titipan ELSE 0 END),0) > ts.nominal_tagihan + .001
        ) x) spp_lebih_bayar,
        (SELECT COUNT(*) FROM (
            SELECT tk.id FROM tagihan_komite tk LEFT JOIN bayar_komite bk ON bk.tagihan_komite_id=tk.id
            GROUP BY tk.id,tk.nominal_tagihan
            HAVING COALESCE(SUM(bk.nominal),0) > tk.nominal_tagihan + .001
        ) x) komite_lebih_bayar,
        (SELECT COUNT(*) FROM bayar b
            WHERE ABS(b.total_jumlah - (b.U_PANGKAL+b.U_PSB+b.U_SPP+b.U_TITIPAN_SPP+b.U_KOMITE+b.U_LAIN+COALESCE((SELECT SUM(d.jumlah) FROM bayar_du d WHERE d.bayar_id=b.id),0))) > .001
        ) total_tidak_sesuai")->fetch_assoc();
    demo_payment_seed_assert((int)$invalid['spp_lebih_bayar'] === 0 && (int)$invalid['komite_lebih_bayar'] === 0 && (int)$invalid['total_tidak_sesuai'] === 0, 'Nominal atau alokasi pembayaran demo tidak konsisten.');

    echo "OK: seeder 1.000 pembayaran demo tervalidasi.\n";
} finally {
    if ($createdDatabase) $db->query("DROP DATABASE `{$database}`");
    $db->close();
}

function demo_payment_seed_runner(string $database, string $script): void {
    $command = [PHP_BINARY, __DIR__ . '/../sql/run_legacy_sql.php',
        '--script=' . $script, '--apply', '--confirm-script=' . $script];
    $environment = array_merge(getenv(), ['SPP_DB_NAME' => $database]);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        dirname(__DIR__), $environment);
    if (!is_resource($process)) throw new RuntimeException('Runner SQL legacy tidak dapat dimulai.');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException("Runner $script gagal: $output");
}
