<?php
/** Transactional fixture; run only on a disposable audit database. */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';

if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/i', DB_NAME)) {
    fwrite(STDERR, "SKIPPED: use SPP_TEST_ALLOW_MUTATION=1 and db_spp_audit_* database.\n");
    exit(0);
}

function deposit_regression_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$_SESSION['admin_role'] = 'admin';
$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);
$operators = $koneksi->query("SELECT id FROM admin WHERE role='kasir' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
deposit_regression_assert(count($operators) === 2, 'Perlu dua kasir aktif di unit SD.');
$operatorA = (string)$operators[0]['id'];
$operatorB = (string)$operators[1]['id'];
$nis = (string)random_int(9800000000, 9899999999);
$date = '2099-12-29';
$filters = report_filters($koneksi, ['tanggal_awal'=>$date, 'tanggal_akhir'=>$date, 'operator'=>$operatorA]);
deposit_regression_assert($filters['operator'] === $operatorA, 'Kasir A tidak tersedia pada filter laporan.');
$failure = null;
$koneksi->begin_transaction();
try {
    $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES(?,'UJI SALDO TITIPAN','1',1)");
    $stmt->bind_param('s', $nis);$stmt->execute();$stmt->close();
    $fixture = [
        [$operatorB, 'masuk', 50.0, "$date 10:00:00"],
        [$operatorA, 'masuk', 100.0, "$date 11:00:00"],
        [$operatorA, 'koreksi_keluar', 20.0, "$date 12:00:00"],
        [$operatorB, 'pakai', 10.0, "$date 13:00:00"],
    ];
    $stmt = $koneksi->prepare('INSERT INTO titipan_spp_mutasi(no_induk,jenis,nominal,tanggal,user_id) VALUES(?,?,?,?,?)');
    foreach ($fixture as [$operator,$type,$amount,$at]) {
        $stmt->bind_param('ssdss', $nis, $type, $amount, $at, $operator);
        $stmt->execute();
    }
    $stmt->close();

    $filtered = report_spp_deposit_data($koneksi, $filters);
    deposit_regression_assert(count($filtered['rows']) === 2, 'Filter kasir A tidak memilih tepat dua mutasi.');
    deposit_regression_assert((float)$filtered['rows'][0]['saldo'] === 150.0,
        'Saldo pada mutasi A tidak menyertakan transaksi B yang lebih awal.');
    deposit_regression_assert((float)$filtered['rows'][1]['saldo'] === 130.0,
        'Saldo setelah koreksi keluar A tidak sesuai ledger lengkap.');
    $summary = $filtered['deposit_summary'];
    deposit_regression_assert((float)$summary['saldo_akhir'] === (float)$summary['saldo_awal'] + 120.0,
        'Saldo akhir terfilter berbeda dari saldo ledger sebenarnya.');
    deposit_regression_assert((float)$summary['penerimaan'] === 100.0
        && (float)$summary['koreksi_keluar'] === 20.0
        && (float)$summary['mutasi_operator_lain'] === 40.0,
        'Ringkasan tidak memisahkan koreksi atau mutasi operator lain.');
    $components = array_column($filtered['component_rows'], 'nominal', 'komponen');
    deposit_regression_assert((float)$components['Saldo awal periode']
        + (float)$components['Penerimaan Titipan']
        + (float)$components['Dipakai untuk SPP']
        + (float)$components['Pengembalian Titipan']
        + (float)$components['Koreksi Keluar']
        + (float)$components['Mutasi operator lain'] === (float)$summary['saldo_akhir'],
        'Komponen Titipan tidak merekonsiliasi saldo akhir.');

    $all = report_spp_deposit_data($koneksi, array_replace($filters, ['operator'=>'']));
    deposit_regression_assert(count($all['rows']) === 4
        && (float)$all['deposit_summary']['saldo_akhir'] === (float)$all['deposit_summary']['saldo_awal'] + 120.0,
        'Ledger tanpa filter kehilangan mutasi atau saldo akhir.');
    unit_set_context($koneksi, 2);
    $otherUnit = report_spp_deposit_data($koneksi, array_replace($filters, ['operator'=>'']));
    deposit_regression_assert(!in_array($nis, array_column($otherUnit['rows'], 'nis'), true),
        'Mutasi Titipan SD bocor ke unit SMP.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    unit_set_context($koneksi, 1);
    $koneksi->rollback();
}
if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: saldo Titipan lintas operator, koreksi, rekonsiliasi, dan isolasi unit.\n";
