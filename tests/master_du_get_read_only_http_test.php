<?php
// A GET of a future Master Daftar Ulang year must never create a draft row.
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "SKIPPED: hanya untuk database audit disposable.\n");
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';

function du_get_test_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$base = rtrim((string)getenv('SPP_HTTP_BASE'), '/');
$parts = parse_url($base);
du_get_test_assert(in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true), 'Server HTTP harus lokal.');
$database = (string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0];
du_get_test_assert($database === getenv('SPP_DB_NAME'), 'Koneksi CLI tidak menuju database audit yang diminta.');

$year = null;
for ($start = 2180; $start <= 2198; $start++) {
    $label = $start . '/' . ($start + 1);
    $stmt = $koneksi->prepare('SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1');
    $stmt->bind_param('s', $label);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$exists) { $year = $label; break; }
}
du_get_test_assert($year !== null, 'Tidak ada tahun kosong untuk regresi GET.');

$admin = $koneksi->query("SELECT id,unit_id,role FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_assoc();
du_get_test_assert((bool)$admin, 'Akun admin latihan unit SD tidak tersedia.');
$sessionId = 'duget' . bin2hex(random_bytes(12));
session_id($sessionId);
session_start();
$_SESSION = [
    'admin_id' => (int)$admin['id'],
    'admin_role' => 'admin',
    'admin_nama' => 'Tes Daftar Ulang',
    'admin_unit_id' => 1,
    'active_unit_id' => 1,
];
session_write_close();

$passed = false;
try {
    $headers = ['Cookie: ' . session_name() . '=' . $sessionId];
    $url = $base . '/master_daftar_ulang.php?tahun=' . rawurlencode($year);
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $headers),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30,
    ]]);
    $body = file_get_contents($url, false, $context);
    du_get_test_assert(str_contains($http_response_header[0] ?? '', '200'), 'GET tahun draf tidak tampil.');
    du_get_test_assert(str_contains((string)$body, $year) && str_contains((string)$body, 'Simpan & Terbitkan Tagihan'), 'Pratinjau tahun virtual tidak tampil.');

    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label=?');
    $stmt->bind_param('s', $year);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    du_get_test_assert($count === 0, 'GET membuat tahun ajaran draf.');

    $post = http_build_query(['aksi' => 'simpan_dan_terbitkan', 'tahun_ajaran' => $year, 'csrf_token' => 'tidak-valid']);
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", array_merge($headers, [
            'Content-Type: application/x-www-form-urlencoded',
        ])),
        'content' => $post, 'follow_location' => 0,
        'ignore_errors' => true, 'timeout' => 30,
    ]]);
    file_get_contents($url, false, $context);
    du_get_test_assert(str_contains($http_response_header[0] ?? '', '302'), 'POST tanpa token tidak ditolak.');
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label=?');
    $stmt->bind_param('s', $year);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    du_get_test_assert($count === 0, 'POST tanpa token membuat tahun ajaran.');

    session_id($sessionId);
    session_start();
    $validToken = (string)($_SESSION['csrf_master_du'] ?? '');
    session_write_close();
    du_get_test_assert($validToken !== '', 'Token formulir tidak tersedia.');
    $amounts = array_fill_keys(range(1, 6), '100000');
    $post = http_build_query([
        'aksi' => 'simpan_dan_terbitkan', 'tahun_ajaran' => $year,
        'csrf_token' => $validToken, 'jumlah' => $amounts,
    ]);
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", array_merge($headers, [
            'Content-Type: application/x-www-form-urlencoded',
        ])),
        'content' => $post, 'follow_location' => 0,
        'ignore_errors' => true, 'timeout' => 30,
    ]]);
    file_get_contents($url, false, $context);
    du_get_test_assert(str_contains($http_response_header[0] ?? '', '302'), 'POST yang gagal tidak kembali ke master.');
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tahun_ajaran WHERE label=?');
    $stmt->bind_param('s', $year);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    du_get_test_assert($count === 0, 'Penerbitan tanpa penempatan tidak mengembalikan pembuatan draf.');
    $passed = true;
} finally {
    session_id($sessionId);
    session_start();
    $_SESSION = [];
    session_destroy();
    session_write_close();
}
if ($passed) echo "OK: GET baca saja; POST tanpa token ditolak; penerbitan gagal rollback penuh.\n";
