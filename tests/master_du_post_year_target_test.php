<?php

/** A Daftar Ulang form POST must use its year, not a stale year in the URL. */
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
$_SERVER['PHP_SELF'] = '/master_daftar_ulang.php';
$_SERVER['REQUEST_METHOD'] = 'POST';

$urlYear = '';
$postedYear = '';
$fallbackYear = '';
for ($start = 2180; $start <= 2190; $start++) {
    $first = $start . '/' . ($start + 1);
    $second = ($start + 1) . '/' . ($start + 2);
    $third = ($start + 2) . '/' . ($start + 3);
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label IN (?,?,?)');
    $stmt->bind_param('sss', $first, $second, $third); $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    if ($exists === 0) { $urlYear = $first; $postedYear = $second; $fallbackYear = $third; break; }
}
if ($urlYear === '') throw new RuntimeException('Tiga tahun ajaran kosong tidak ditemukan.');

$insert = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status) VALUES(?,?,?,'published')");
foreach ([$urlYear, $postedYear, $fallbackYear] as $label) {
    $start = (int)substr($label, 0, 4);
    $begin = $start . '-07-01';
    $end = ($start + 1) . '-06-30';
    $insert->bind_param('sss', $label, $begin, $end); $insert->execute();
}
$insert->close();

$token = bin2hex(random_bytes(32));
$arrayYear = in_array('--array-year', $argv, true);
$invalidYear = $arrayYear || in_array('--invalid-year', $argv, true);
$_SESSION['csrf_master_du'] = $token;
$_SERVER['HTTP_X_SPP_TEST_CURRENT_YEAR'] = $fallbackYear;
$_GET = ['tahun' => $urlYear];
$_POST = ['tahun_ajaran' => $arrayYear ? ['tahun-salah'] : ($invalidYear ? 'tahun-salah' : $postedYear),
    'aksi' => 'tutup', 'csrf_token' => $token];

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (!str_contains($message, 'session_start(): Ignoring session_start() because a session is already active')) {
        $warnings[] = $message;
    }
    return true;
});
register_shutdown_function(static function () use ($koneksi, $urlYear, $postedYear, $fallbackYear, $invalidYear, $arrayYear, &$warnings): void {
    while (ob_get_level() > 0) ob_end_clean();
    restore_error_handler();
    try {
        $rows = [];
        foreach ([$urlYear, $postedYear, $fallbackYear] as $label) {
            $stmt = $koneksi->prepare('SELECT id,status FROM tahun_ajaran WHERE label=?');
            $stmt->bind_param('s', $label); $stmt->execute();
            $rows[$label] = $stmt->get_result()->fetch_assoc(); $stmt->close();
        }
        $valid = ($rows[$urlYear]['status'] ?? '') === 'published'
            && ($rows[$postedYear]['status'] ?? '') === ($invalidYear ? 'published' : 'closed')
            && ($rows[$fallbackYear]['status'] ?? '') === 'published'
            && !$warnings;

        foreach ($rows as $row) {
            if (!$row) continue;
            $yearId = (int)$row['id'];
            $stmt = $koneksi->prepare('DELETE FROM daftar_ulang_audit_log WHERE tahun_ajaran_id=?');
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare('DELETE FROM tahun_ajaran WHERE id=?');
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
        }
        if (!$valid) {
            fwrite(STDERR, 'FAILED: POST Daftar Ulang salah menargetkan tahun atau mengeluarkan warning: ' . implode(' | ', $warnings) . PHP_EOL);
            exit(1);
        }
        echo $invalidYear
            ? ($arrayYear ? "PASS: POST Daftar Ulang menolak tahun formulir berbentuk array tanpa warning/data baru.\n" : "PASS: POST Daftar Ulang menolak tahun formulir tidak valid tanpa menulis data.\n")
            : "PASS: POST Daftar Ulang memakai tahun formulir dan membiarkan tahun URL.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
});

ob_start();
include __DIR__ . '/../master_daftar_ulang.php';
