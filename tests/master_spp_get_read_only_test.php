<?php

/** A GET of an unconfigured SPP year must not create year or master rows. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
session_start();
require_once __DIR__ . '/../koneksi.php';

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', DB_NAME)) {
    throw new RuntimeException('Tes ini hanya untuk clone audit dengan flag mutasi tes.');
}

$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'admin';
$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);
$_SERVER['PHP_SELF'] = '/master_spp.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

$fixtureYear = '';
for ($start = 2180; $start <= 2190; $start++) {
    $candidate = $start . '/' . ($start + 1);
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label=?');
    $stmt->bind_param('s', $candidate); $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    if ($exists === 0) { $fixtureYear = $candidate; break; }
}
if ($fixtureYear === '') throw new RuntimeException('Tidak ada tahun ajaran kosong untuk fixture.');
$existingYear = in_array('--existing-year', $argv, true);
if ($existingYear) {
    $start = (int)substr($fixtureYear, 0, 4);
    $begin = $start . '-07-01';
    $end = ($start + 1) . '-06-30';
    $stmt = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status) VALUES(?,?,?,'draft')");
    $stmt->bind_param('sss', $fixtureYear, $begin, $end); $stmt->execute(); $stmt->close();
}

$_GET = ['tahun' => $fixtureYear];
$_POST = [];
$failure = null;
try {
    ob_start();
    include __DIR__ . '/../master_spp.php';
    $html = (string)ob_get_clean();
    if (!str_contains($html, $fixtureYear) || !str_contains($html, 'Tarif Dasar per Tingkat')) {
        throw new RuntimeException('Halaman Master SPP tahun kosong tidak tampil.');
    }
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label=?');
    $stmt->bind_param('s', $fixtureYear); $stmt->execute();
    $yearCount = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    if ($yearCount !== ($existingYear ? 1 : 0)) {
        throw new RuntimeException('GET mengubah jumlah tahun ajaran.');
    }
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM master_spp_tahun m
        JOIN tahun_ajaran y ON y.id=m.tahun_ajaran_id WHERE y.label=?');
    $stmt->bind_param('s', $fixtureYear); $stmt->execute();
    $masterCount = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    if ($masterCount !== 0) throw new RuntimeException('GET membuat draf Master SPP.');
} catch (Throwable $error) {
    $failure = $error;
    if (ob_get_level() > 0) ob_end_clean();
} finally {
    // Clean up the rows created by the old behavior on a disposable clone.
    $stmt = $koneksi->prepare('SELECT id FROM tahun_ajaran WHERE label=?');
    $stmt->bind_param('s', $fixtureYear); $stmt->execute();
    $year = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($year) {
        $yearId = (int)$year['id'];
        $stmt = $koneksi->prepare('DELETE FROM master_spp_tahun WHERE tahun_ajaran_id=?');
        $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare('DELETE FROM tahun_ajaran WHERE id=?');
        $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
    }
}

if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
echo 'PASS: GET Master SPP tanpa master tidak menulis tahun atau draf ('
    . ($existingYear ? 'tahun ada' : 'tahun kosong') . ").\n";
