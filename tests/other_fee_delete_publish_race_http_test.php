<?php
require_once __DIR__ . "/http_form_scope.php";
/** A delete racing with publication must retain the master and its new bill. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$database = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $database)) {
    throw new RuntimeException('Tes mutasi hanya untuk database audit berflag.');
}
$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8812'), '/');
spp_test_assert_http_clone($base, $database);
$url = parse_url($base);
if (($url['scheme'] ?? '') !== 'http' || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Server harus memakai HTTP loopback.');
}
$port = (int)($url['port'] ?? 80);
$seed = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($seed === '' && ($path = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE')) !== '') {
    $seed = trim((string)file_get_contents($path));
}
if ($seed === '') throw new RuntimeException('Sandi latihan belum tersedia.');

session_start();
require_once __DIR__ . '/../koneksi.php';
if (DB_NAME !== $database || $koneksi->query('SELECT DATABASE()')->fetch_row()[0] !== $database) {
    throw new RuntimeException('Koneksi CLI tidak menuju clone audit.');
}
unit_set_context($koneksi, 1);

function race_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function race_http(string $url, ?array $post, array &$cookies): array {
    $headers = [];
    if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
    ]]);
    $body = file_get_contents($url, false, $context);
    race_assert($body !== false, 'Permintaan HTTP gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

$probeCookies = [];
$identity = race_http($base . '/tests/browser_clone_identity.php', null, $probeCookies);
race_assert($identity['status'] === 200
    && (json_decode($identity['body'], true)['database'] ?? '') === $database,
    'Server HTTP tidak menuju clone yang sama.');
$admin = $koneksi->query("SELECT id,username,password FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
race_assert((bool)$admin, 'Admin SD fixture tidak tersedia.');
$adminId = (int)$admin['id'];
$oldHash = (string)$admin['password'];
$password = hash_hmac('sha256', bin2hex(random_bytes(16)), $seed);
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
$stmt->bind_param('si', $hash, $adminId); $stmt->execute(); $stmt->close();

$masterId = 0;
$billId = 0;
$pending = null;
$transactionOpen = false;
try {
    $cookies = [];
    $login = race_http($base . '/login.php', ['username' => $admin['username'], 'password' => $password], $cookies);
    race_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin latihan gagal.');
    $page = race_http($base . '/master_biaya_lain.php', null, $cookies);
    race_assert($page['status'] === 200
        && (bool)preg_match('/<form\b[^>]*id="form-master-biaya"[^>]*>.*?<\/form>/si', $page['body'], $form)
        && (bool)preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form[0], $csrf),
        'Form master biaya tidak terbuka.');

    $name = 'UJI RACE HAPUS ' . bin2hex(random_bytes(6));
    $amount = 100000.0;
    $stmt = $koneksi->prepare('INSERT INTO master_biaya_lain (nama,nominal,is_active) VALUES (?,?,1)');
    $stmt->bind_param('sd', $name, $amount); $stmt->execute(); $masterId = $stmt->insert_id; $stmt->close();
    race_assert($masterId > 0, 'Fixture master gagal dibuat.');
    $student = $koneksi->query('SELECT NO_INDUK FROM siswa WHERE is_active=1 ORDER BY NO_INDUK LIMIT 1')->fetch_row();
    race_assert((bool)$student, 'Siswa latihan tidak tersedia.');
    $nis = (string)$student[0];

    // Connection A follows the publisher's lock order and keeps its new bill uncommitted.
    $koneksi->begin_transaction();
    $transactionOpen = true;
    $stmt = $koneksi->prepare('SELECT id FROM master_biaya_lain WHERE id=? AND is_active=1 FOR UPDATE');
    $stmt->bind_param('i', $masterId); $stmt->execute();
    race_assert((bool)$stmt->get_result()->fetch_row(), 'Master fixture tidak dapat dikunci.');
    $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO tagihan_biaya_lain (master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,status) VALUES (?,?,?,?,'open')");
    $stmt->bind_param('issd', $masterId, $nis, $name, $amount);
    $stmt->execute(); $billId = $stmt->insert_id; $stmt->close();
    race_assert($billId > 0, 'Fixture tagihan belum tersimpan dalam transaksi.');

    // Connection B is the actual HTTP delete endpoint. Keep it in flight until it waits for A.
    $body = http_build_query(['aksi' => 'hapus', 'id' => $masterId, 'csrf_token' => $csrf[1]]);
    $pending = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 5);
    race_assert(is_resource($pending), 'Tidak dapat membuka koneksi HTTP paralel: ' . $error);
    stream_set_timeout($pending, 15);
    $request = "POST /master_biaya_lain.php HTTP/1.1\r\n"
        . "Host: 127.0.0.1:$port\r\n"
        . 'Cookie: PHPSESSID=' . $cookies['PHPSESSID'] . "\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n" . $body;
    race_assert(fwrite($pending, $request) === strlen($request), 'Kiriman HTTP paralel tidak lengkap.');

    $waiting = $koneksi->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=? AND l.OBJECT_NAME=?');
    $table = 'master_biaya_lain_data';
    $waiting->bind_param('ss', $database, $table);
    $blocked = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $waiting->execute();
        if ((int)$waiting->get_result()->fetch_row()[0] > 0) { $blocked = true; break; }
        usleep(50000);
    }
    $waiting->close();
    race_assert($blocked, 'Endpoint hapus tidak mencapai penungguan kunci master.');

    $koneksi->commit();
    $transactionOpen = false;
    $response = stream_get_contents($pending);
    race_assert(is_string($response) && preg_match('/^HTTP\/1\.[01] 302 /', $response) === 1,
        'Endpoint hapus tidak selesai dengan redirect HTTP.');
    fclose($pending); $pending = null;

    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM master_biaya_lain WHERE id=?');
    $stmt->bind_param('i', $masterId); $stmt->execute();
    $masterCount = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM tagihan_biaya_lain WHERE id=? AND master_biaya_lain_id=?');
    $stmt->bind_param('ii', $billId, $masterId); $stmt->execute();
    $billCount = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    race_assert($masterCount === 1 && $billCount === 1,
        'Balapan hapus/penerbitan meninggalkan tagihan tanpa master.');
    $rejected = race_http($base . '/master_biaya_lain.php', null, $cookies);
    race_assert($rejected['status'] === 200
        && str_contains($rejected['body'], 'Master sudah dipakai pada transaksi'),
        'Penghapusan yang ditolak tidak memberi alasan kepada pengguna.');

    // Once the newly published bill is removed, the same endpoint must still delete an unused master.
    $stmt = $koneksi->prepare('DELETE FROM tagihan_biaya_lain WHERE id=?');
    $stmt->bind_param('i', $billId); $stmt->execute(); $stmt->close();
    $billId = 0;
    $ordinaryDelete = race_http($base . '/master_biaya_lain.php',
        ['aksi' => 'hapus', 'id' => $masterId, 'csrf_token' => $csrf[1]], $cookies);
    race_assert($ordinaryDelete['status'] === 302, 'Penghapusan master tanpa tagihan gagal HTTP.');
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM master_biaya_lain WHERE id=?');
    $stmt->bind_param('i', $masterId); $stmt->execute();
    $remaining = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    race_assert($remaining === 0, 'Master tanpa tagihan tidak terhapus.');
    $masterId = 0;
    echo "PASS: hapus menunggu penerbitan lalu mempertahankan master beserta tagihannya.\n";
} finally {
    if ($transactionOpen) $koneksi->rollback();
    if (is_resource($pending)) fclose($pending);
    if ($billId > 0) {
        $stmt = $koneksi->prepare('DELETE FROM tagihan_biaya_lain WHERE id=?');
        $stmt->bind_param('i', $billId); $stmt->execute(); $stmt->close();
    }
    if ($masterId > 0) {
        $stmt = $koneksi->prepare('DELETE FROM master_biaya_lain WHERE id=?');
        $stmt->bind_param('i', $masterId); $stmt->execute(); $stmt->close();
    }
    $stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    $stmt->bind_param('si', $oldHash, $adminId); $stmt->execute(); $stmt->close();
}
