<?php
/** Regression for the additive constraints applied to a disposable clone. */
if (PHP_SAPI !== 'cli'
    || !preg_match('/^db_spp_(?:test|audit)_[a-z0-9_]+$/i', (string)getenv('SPP_DB_NAME'))
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    throw new RuntimeException('Gunakan database disposable dengan SPP_TEST_ALLOW_MUTATION=1.');
}
require_once __DIR__ . '/../koneksi.php';

$koneksi->begin_transaction();
try {
    unit_set_context($koneksi, 2);
    $tariff = $koneksi->query('SELECT id FROM master_spp_tarif ORDER BY id LIMIT 1')->fetch_assoc();
    if (!$tariff) throw new RuntimeException('Tes membutuhkan satu tarif SPP SMP di salinan database.');
    $id = (int)$tariff['id'];
    $gradeRejected = false;
    try {
        $koneksi->query("UPDATE master_spp_tarif SET tingkat=6 WHERE id={$id}");
    } catch (mysqli_sql_exception $error) {
        $gradeRejected = $error->getSqlState() === '45000';
    }
    if (!$gradeRejected) throw new RuntimeException('Tarif SMP menerima tingkat SD.');

    unit_set_context($koneksi, 1);
    $savings = $koneksi->query('SELECT id FROM tabungan ORDER BY id LIMIT 1')->fetch_assoc();
    if (!$savings) {
        $student = $koneksi->query('SELECT NO_INDUK FROM siswa ORDER BY id LIMIT 1')->fetch_assoc();
        if (!$student) throw new RuntimeException('Tes membutuhkan satu siswa SD di salinan database.');
        $stmt = $koneksi->prepare('INSERT INTO tabungan(NO_INDUK,SALDO) VALUES(?,0)');
        $stmt->bind_param('s', $student['NO_INDUK']);
        $stmt->execute();
        $stmt->close();
        $savings = ['id' => $koneksi->insert_id];
    }
    $id = (int)$savings['id'];
    $negativeRejected = false;
    try {
        $koneksi->query("UPDATE tabungan SET SALDO=-1 WHERE id={$id}");
    } catch (mysqli_sql_exception $error) {
        $negativeRejected = $error->getCode() === 3819;
    }
    if (!$negativeRejected) throw new RuntimeException('Saldo tabungan negatif diterima.');
    echo "OK: tarif lintas unit dan saldo negatif ditolak database.\n";
} finally {
    $koneksi->rollback();
}
