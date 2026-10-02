<?php
/** Role/unit/CSRF matrix; all rejected requests must leave school records identical. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    throw new RuntimeException('Use a flagged disposable audit database.');
}
require_once __DIR__ . '/../koneksi.php';
$base = rtrim((string)getenv('SPP_TEST_BASE_URL'), '/');
$url = parse_url($base);
if (($url['scheme'] ?? '') !== 'http' || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Use a loopback HTTP server.');
}
function access_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function access_http(string $path, string $session, ?array $data = null): array {
    global $base;
    $headers = ['Cookie: PHPSESSID=' . $session];
    if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $body = file_get_contents($base . '/' . $path, false, stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
        'content' => $data === null ? '' : http_build_query($data),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
    ]]));
    preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m);
    access_assert($body !== false && (int)($m[1] ?? 0) < 500, 'HTTP failure: ' . $path);
    return [(int)($m[1] ?? 0), (string)$body];
}
function access_fingerprint(mysqli $db): array {
    $result = [];
    foreach ($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all() as $table) {
        $name = (string)$table[0];
        $rows = $db->query('SELECT * FROM `' . str_replace('`', '``', $name) . '`')->fetch_all(MYSQLI_ASSOC);
        $encoded = array_map(static fn($row) => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $result[$name] = hash('sha256', implode("\n", $encoded));
    }
    ksort($result);
    return $result;
}
function access_session(?array $account, int $unit): string {
    $id = 'accessmatrix' . bin2hex(random_bytes(12));
    session_id($id); session_start();
    $_SESSION = $account ? ['admin_id' => (int)$account['id'], 'admin_role' => $account['role'],
        'active_unit_id' => $unit, 'admin_nama' => 'Uji akses'] : [];
    session_write_close();
    return $id;
}
$identity = access_http('tests/browser_clone_identity.php', '');
access_assert($identity[0] === 200 && (json_decode($identity[1], true)['database'] ?? '') === DB_NAME,
    'HTTP server does not use the requested clone.');
access_assert($koneksi->query('SELECT DATABASE()')->fetch_row()[0] === DB_NAME, 'CLI target differs.');
unit_set_context($koneksi, 1);
$foreignStudent = $koneksi->query('SELECT id,NO_INDUK FROM siswa LIMIT 1')->fetch_assoc();
$foreignPayment = $koneksi->query('SELECT id FROM bayar LIMIT 1')->fetch_assoc();
$foreignClass = $koneksi->query('SELECT id FROM master_kelas WHERE tingkat=1 AND is_placeholder=0 LIMIT 1')->fetch_assoc();
access_assert($foreignStudent && $foreignPayment && $foreignClass, 'Baseline SD fixtures missing.');
$initial = access_fingerprint($koneksi);
$fixtureNis = (string)random_int(9400000000, 9499999999);
$classId = (int)$foreignClass['id'];
$operator = (string)$koneksi->query("SELECT id FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_row()[0];
$koneksi->begin_transaction();
$stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id) VALUES(?,'UJI AKSES SALDO','1',?)");
$stmt->bind_param('si', $fixtureNis, $classId); $stmt->execute(); $stmt->close();
$stmt = $koneksi->prepare('INSERT INTO tabungan(NO_INDUK,SALDO) VALUES(?,12345)');
$stmt->bind_param('s', $fixtureNis); $stmt->execute(); $stmt->close();
$stmt = $koneksi->prepare('INSERT INTO transaksi_m(NO_INDUK,MASUK,TANGGAL,user_id) VALUES(?,12345,NOW(),?)');
$stmt->bind_param('ss', $fixtureNis, $operator); $stmt->execute(); $stmt->close();
$koneksi->commit();
$balance = ['NO_INDUK' => $fixtureNis, 'SALDO' => 12345];
$before = access_fingerprint($koneksi);
$pages = [
    'siswa/daftar.php' => ['admin', 'kasir'], 'master_kelas.php' => ['admin', 'kasir'],
    'master_spp.php' => ['admin', 'kasir'], 'master_daftar_ulang.php' => ['admin', 'kasir'],
    'master_biaya_lain.php' => ['admin', 'kasir'], 'role_management.php' => [],
    'pembayaran/form.php' => ['admin', 'kasir'], 'tabungan/masuk.php' => ['admin', 'kasir'],
    'tabungan/keluar.php' => ['admin', 'kasir'], 'pembayaran/titipan_spp.php' => ['admin', 'kasir', 'bendahara'],
    'otorisasi_transaksi.php' => ['admin', 'kasir', 'bendahara'], 'laporan/global.php' => ['admin', 'kasir', 'bendahara'],
];
$mutations = [
    'siswa/daftar.php' => ['aksi' => 'toggle_status', 'id' => $foreignStudent['id'], 'target_active' => '0'],
    'master_kelas.php' => ['aksi' => 'toggle', 'id' => $foreignClass['id'], 'target_active' => '0'],
    'master_spp.php' => ['aksi' => 'terbitkan', 'tahun_ajaran' => '2026/2027'],
    'master_daftar_ulang.php' => ['aksi' => 'terbitkan', 'tahun_ajaran' => '2026/2027'],
    'master_biaya_lain.php' => ['aksi' => 'tambah', 'nama' => 'DITOLAK', 'nominal' => '123'],
    'role_management.php' => ['aksi' => 'tambah', 'username' => 'DITOLAK'],
    'pembayaran/proses.php' => ['aksi' => 'input', 'no_induk' => $foreignStudent['NO_INDUK'], 'uang_pangkal' => 123],
    'tabungan/proses.php' => ['aksi' => 'masuk', 'no_induk' => $foreignStudent['NO_INDUK'], 'nominal' => 123],
    'pembayaran/titipan_spp.php' => ['no_induk' => $foreignStudent['NO_INDUK'], 'nominal' => 123],
    'otorisasi_transaksi.php' => ['action' => 'reject', 'request_id' => 1],
    'unit_switch.php' => ['unit_id' => 2], 'logout.php' => [],
];
$sessions = []; $requests = 0;
try {
    foreach ([1, 2, 3] as $unit) foreach (['super_admin', 'admin', 'kasir', 'bendahara'] as $role) {
        $stmt = $koneksi->prepare('SELECT id,role FROM admin WHERE role=? AND is_active=1'
            . ($role === 'super_admin' ? '' : ' AND unit_id=?') . ' ORDER BY id LIMIT 1');
        if ($role === 'super_admin') $stmt->bind_param('s', $role); else $stmt->bind_param('si', $role, $unit);
        $stmt->execute(); $account = $stmt->get_result()->fetch_assoc(); $stmt->close();
        access_assert((bool)$account, 'Active role fixture missing: ' . $role . '/' . $unit);
        $session = access_session($account, $unit); $sessions[] = $session;
        foreach ($pages as $page => $roles) {
            [$status, $body] = access_http($page, $session); $requests++;
            $allowed = $role === 'super_admin' || in_array($role, $roles, true);
            access_assert($status === ($allowed ? 200 : 302), 'Wrong page access: ' . $page . '/' . $role . '/' . $unit);
        }
        foreach ($mutations as $page => $data) {
            [$status] = access_http($page, $session, $data + ['csrf_token' => 'invalid']); $requests++;
            access_assert(in_array($status, [302, 303, 403], true), 'Unexpected rejection: ' . $page);
        }
        [$status, $body] = access_http('tabungan/get_saldo.php?nis=' . rawurlencode($balance['NO_INDUK']), $session); $requests++;
        access_assert($status === ($role === 'bendahara' ? 403 : 200), 'Wrong balance API role.');
        if ($status === 200) access_assert((float)(json_decode($body, true)['saldo'] ?? -1) === ($unit === 1 ? (float)$balance['SALDO'] : 0.0), 'Cross-unit balance leak.');
        if ($unit !== 1 && in_array($role, ['admin', 'kasir'], true)) {
            [$status] = access_http('pembayaran/edit.php?id=' . (int)$foreignPayment['id'], $session); $requests++;
            access_assert($status === 302, 'Cross-unit payment edit exposed.');
            [$status, $body] = access_http('siswa/daftar.php', $session);
            preg_match('/<form\b[^>]*id="form-master-siswa"[^>]*>(.*?)<\/form>/s', $body, $form);
            access_assert(preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $form[1] ?? '', $token) === 1, 'Student token missing.');
            access_http('siswa/daftar.php', $session, $mutations['siswa/daftar.php'] + ['csrf_token' => $token[1]]); $requests++;
        }
    }
    $anonymous = access_session(null, 1); $sessions[] = $anonymous;
    foreach (array_keys($pages) as $page) {
        [$status] = access_http($page, $anonymous); $requests++;
        access_assert($status === 302, 'Anonymous page exposure: ' . $page);
    }
    foreach ($mutations as $page => $data) {
        [$status] = access_http($page, $anonymous, $data + ['csrf_token' => 'invalid']); $requests++;
        access_assert(in_array($status, [302, 303, 403], true), 'Anonymous mutation not rejected.');
    }
    [$status] = access_http('tabungan/get_saldo.php', $anonymous); $requests++;
    access_assert($status === 401, 'Anonymous balance API exposure.');
    access_assert(access_fingerprint($koneksi) === $before, 'Rejected requests changed a school record.');
} finally {
    foreach ($sessions as $session) {
        session_id($session); session_start(); $_SESSION = []; session_destroy(); session_write_close();
    }
    unit_set_context($koneksi, 1);
    foreach (['transaksi_m', 'tabungan', 'siswa'] as $table) {
        $stmt = $koneksi->prepare('DELETE FROM ' . $table . ' WHERE NO_INDUK=?');
        $stmt->bind_param('s', $fixtureNis); $stmt->execute(); $stmt->close();
    }
}
access_assert(access_fingerprint($koneksi) === $initial, 'Fixture cleanup changed an original record.');
echo "OK: {$requests} role/unit/anonymous/CSRF requests; direct foreign IDs rejected; all table fingerprints unchanged.\n";
