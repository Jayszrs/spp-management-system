<?php
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_(?:audit|test)_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    error_log('FAILED: tes mutasi memerlukan CLI, clone db_spp_audit_* atau db_spp_test_*, dan SPP_TEST_ALLOW_MUTATION=1.');
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';

$failure = null;
$koneksi->begin_transaction();
try {
    $label = '2020/2021';
    $yearId = class_ensure_academic_year($koneksi, $label);
    foreach (range(1, 6) as $level) {
        $classText = (string)$level;
        $amount = 100000.0 * $level;
        $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES(?,?,?,?)');
        $stmt->bind_param('issd', $yearId, $label, $classText, $amount);
        $stmt->execute(); $stmt->close();
    }
    $nis = (string)random_int(9900000000, 9999999999);
    $currentClass = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=5 AND kode_rombel='A' AND is_active=1 LIMIT 1")->fetch_assoc();
    $oldClass = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=4 AND kode_rombel='A' AND is_active=1 LIMIT 1")->fetch_assoc();
    if (!$currentClass || !$oldClass) throw new RuntimeException('Rombel uji tidak tersedia.');
    $name = 'UJI KELAS HISTORIS'; $currentLevel = '5'; $currentClassId = (int)$currentClass['id'];
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,?,?,?,1)');
    $stmt->bind_param('sssi', $nis, $name, $currentLevel, $currentClassId);
    $stmt->execute(); $stmt->close();

    $blocked = false;
    try { du_publish_year_from_active_students($koneksi, $yearId, $label); }
    catch (RuntimeException $error) { $blocked = true; }
    if (!$blocked) throw new RuntimeException('Tahun lampau tanpa riwayat siswa diterbitkan dari kelas saat ini.');

    $oldLevel = '4'; $oldClassId = (int)$oldClass['id']; $snapshot = '4A';
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'pindah')");
    $stmt->bind_param('issis', $yearId, $nis, $oldLevel, $oldClassId, $snapshot);
    $stmt->execute(); $stmt->close();
    if (du_publish_year_from_active_students($koneksi, $yearId, $label) !== 1) throw new RuntimeException('Tagihan kelas historis tidak terbit.');
    $stmt = $koneksi->prepare('SELECT kelas_snapshot,nominal_tagihan FROM tagihan_daftar_ulang WHERE no_induk=? AND tahun_ajaran_id=?');
    $stmt->bind_param('si', $nis, $yearId);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$bill || $bill['kelas_snapshot'] !== '4' || (float)$bill['nominal_tagihan'] !== 400000.0) {
        throw new RuntimeException('Tagihan historis tidak memakai kelas penempatan tahun asal.');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $koneksi->rollback();
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo "OK: penerbitan historis memakai penempatan tahun yang benar.\n";
