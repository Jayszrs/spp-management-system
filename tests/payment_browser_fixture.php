<?php
/** Fixture and database verifier for the real Chrome cashier flow. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "FAILED: payment browser fixture requires a disposable audit database and test flag.\n");
    exit(1);
}
$action = $argv[1] ?? '';
if (!in_array($action, ['setup', 'state', 'verify'], true)) {
    fwrite(STDERR, "Usage: php tests/payment_browser_fixture.php setup|state|verify\n");
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/spp_billing.php';
require_once __DIR__ . '/../includes/komite_billing.php';
unit_set_context($koneksi, 1);

function payment_browser_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function payment_browser_scalar(mysqli $db, string $sql): mixed {
    return $db->query($sql)->fetch_row()[0];
}

$arrearsNis = '9988111001';
$currentNis = '9988111002';
$emptyNis = '9988111003';
$previous = '2025/2026';
$current = '2026/2027';

try {
    payment_browser_assert(payment_browser_scalar($koneksi, 'SELECT DATABASE()') === DB_NAME,
        'Connection does not match the named audit clone.');
    if ($action === 'state') {
        $payments = $koneksi->query("SELECT id,U_SPP,U_KOMITE,U_TITIPAN_SPP,total_jumlah,BULAN FROM bayar WHERE NO_INDUK='{$arrearsNis}' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['payments' => $payments, 'deposit_balance' => spp_deposit_balance($koneksi, $arrearsNis)], JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($action === 'setup') {
        $passwordFile = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE');
        payment_browser_assert($passwordFile !== '' && is_file($passwordFile), 'Test admin password file is required.');
        $password = trim((string)file_get_contents($passwordFile));
        payment_browser_assert($password !== '', 'Test admin password is empty.');

        $koneksi->begin_transaction();
        $existing = (int)payment_browser_scalar($koneksi,
            "SELECT COUNT(*) FROM siswa WHERE NO_INDUK IN ('{$arrearsNis}','{$currentNis}','{$emptyNis}')");
        payment_browser_assert($existing === 0, 'Fixture NIS already exists; use a fresh clone.');
        $admin = $koneksi->query("SELECT id FROM admin WHERE username='admin' AND role='admin' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_assoc();
        payment_browser_assert((bool)$admin, 'Active SD admin does not exist.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $adminId = (int)$admin['id'];
        $update = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
        $update->bind_param('si', $hash, $adminId);
        $update->execute();
        $update->close();

        $yearIds = [];
        foreach ([$previous, $current] as $label) {
            $stmt = $koneksi->prepare("SELECT id FROM tahun_ajaran WHERE label=? AND status='published' LIMIT 1");
            $stmt->bind_param('s', $label);
            $stmt->execute();
            $yearIds[$label] = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
            $stmt->close();
            payment_browser_assert($yearIds[$label] > 0, 'Published fixture year is missing: ' . $label);
        }
        $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' AND unit_id=1 AND is_active=1 AND is_placeholder=0 LIMIT 1")->fetch_assoc();
        payment_browser_assert((bool)$class, 'SD class 1A is missing.');
        $classId = (int)$class['id'];
        $master = $koneksi->query('SELECT id FROM master_spp_tahun WHERE tahun_ajaran_id=' . $yearIds[$current] . " AND status='published' LIMIT 1")->fetch_assoc();
        payment_browser_assert((bool)$master, 'Published current SPP master is missing.');

        $students = [
            $arrearsNis => ['BROWSER BAYAR TUNGGAKAN', 250000.0, 100000.0],
            $currentNis => ['BROWSER BAYAR TAHUN INI', 0.0, 0.0],
            $emptyNis => ['BROWSER TANPA TAGIHAN', 0.0, 0.0],
        ];
        $insertStudent = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,?,?,1)');
        $insertPlacement = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,?)');
        $insertDu = $koneksi->prepare('INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?,?,?,?,?,?,?)');
        foreach ($students as $studentNis => [$name, $sppRate, $komiteRate]) {
            $nis = (string)$studentNis;
            $level = '1';
            $snapshot = '1A';
            $insertStudent->bind_param('sssidd', $nis, $name, $level, $classId, $sppRate, $komiteRate);
            $insertStudent->execute();
            $placements = $nis === $arrearsNis ? [$previous, $current] : [$current];
            foreach ($placements as $label) {
                $yearId = $yearIds[$label];
                $status = $label === $current ? 'aktif' : 'pindah';
                $insertPlacement->bind_param('issisdds', $yearId, $nis, $level, $classId, $snapshot, $sppRate, $komiteRate, $status);
                $insertPlacement->execute();
                $placementId = (int)$koneksi->insert_id;
                if ($nis === $emptyNis) continue;
                $du = 1000000.0;
                $insertDu->bind_param('iisssdd', $yearId, $placementId, $nis, $level, $label, $du, $du);
                $insertDu->execute();
                if ($nis === $arrearsNis && $label === $current) {
                    payment_browser_assert(komite_sync_placement($koneksi, $placementId) === 12,
                        'Current Komite fixture did not publish 12 months.');
                }
            }
        }
        $insertStudent->close();
        $insertPlacement->close();
        $insertDu->close();
        $published = spp_publish_students($koneksi, (int)$master['id'], [$arrearsNis]);
        payment_browser_assert($published['created'] === 12, 'SPP fixture did not publish 12 months.');
        $koneksi->commit();
        echo "OK: browser payment fixture created on audit clone.\n";
    } else {
        $payments = $koneksi->query("SELECT id,U_SPP,U_KOMITE,U_TITIPAN_SPP,total_jumlah,BULAN FROM bayar WHERE NO_INDUK='{$arrearsNis}' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        payment_browser_assert(count($payments) === 3, 'Expected exactly three browser payments.');
        payment_browser_assert((float)$payments[0]['U_SPP'] === 250000.0
            && (float)$payments[0]['U_KOMITE'] === 100000.0
            && (float)$payments[0]['total_jumlah'] === 550000.0
            && $payments[0]['BULAN'] === '07', 'Edited July payment has wrong amounts.');
        payment_browser_assert((float)$payments[1]['U_TITIPAN_SPP'] === 100000.0
            && (float)$payments[1]['total_jumlah'] === 100000.0, 'Deposit payment was not recorded separately.');
        payment_browser_assert((float)$payments[2]['U_SPP'] === 150000.0
            && (float)$payments[2]['U_KOMITE'] === 100000.0
            && (float)$payments[2]['total_jumlah'] === 250000.0
            && $payments[2]['BULAN'] === '08', 'August payment did not combine cash and deposit correctly.');
        $firstId = (int)$payments[0]['id'];
        $du = $koneksi->query("SELECT d.jumlah,t.tahun_ajaran_snapshot FROM bayar_du d JOIN tagihan_daftar_ulang t ON t.id=d.tagihan_daftar_ulang_id WHERE d.bayar_id={$firstId}")->fetch_assoc();
        payment_browser_assert($du && (float)$du['jumlah'] === 200000.0
            && $du['tahun_ajaran_snapshot'] === $previous, 'Edited Daftar Ulang did not stay on the historical bill.');
        payment_browser_assert(abs(spp_deposit_balance($koneksi, $arrearsNis)) < .001,
            'Deposit balance did not return to zero after August allocation.');
        $allocation = $koneksi->query("SELECT COALESCE(SUM(a.nominal_dari_bayar),0) cash,COALESCE(SUM(a.nominal_dari_titipan),0) deposit FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id WHERE b.bayar_id={$payments[2]['id']} AND b.status='active'")->fetch_assoc();
        payment_browser_assert((float)$allocation['cash'] === 150000.0 && (float)$allocation['deposit'] === 100000.0,
            'August SPP allocation did not split cash and deposit.');
        payment_browser_assert((int)payment_browser_scalar($koneksi,
            "SELECT COUNT(*) FROM bayar WHERE NO_INDUK IN ('{$currentNis}','{$emptyNis}')") === 0,
            'No-arrear or no-bill students gained unexpected payments.');
        echo "OK: Chrome payment/edit/DU/deposit allocations match database and historical bill.\n";
    }
} catch (Throwable $error) {
    if ($action === 'setup') {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
    }
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
