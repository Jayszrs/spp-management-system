<?php
/** A malformed non-super account must never receive the all-unit DB scope. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $target)) {
    throw new RuntimeException('Tes hanya boleh dijalankan pada clone audit dengan flag mutasi.');
}
$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8795'), '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Server tes harus memakai loopback.');
}
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($password === '' && ($path = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE')) !== '') {
    $password = trim((string)file_get_contents($path));
}
if ($password === '') throw new RuntimeException('Password latihan belum disiapkan.');

session_start();
require_once __DIR__ . '/../koneksi.php';
if (DB_NAME !== $target || $koneksi->query('SELECT DATABASE()')->fetch_row()[0] !== $target) {
    throw new RuntimeException('Koneksi tes tidak mengarah ke clone yang diminta.');
}

function invalid_unit_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function invalid_unit_http(string $url, ?array $post, array &$cookies): array {
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
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    invalid_unit_assert($body !== false, 'Permintaan HTTP gagal.');
    $status = 0;
    $location = '';
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
        if (preg_match('/^Location:\s*(.+)$/i', $header, $match)) $location = trim($match[1]);
    }
    return ['status' => $status, 'location' => $location, 'body' => $body];
}

$cookies = [];
$identity = invalid_unit_http($base . '/tests/browser_clone_identity.php', null, $cookies);
invalid_unit_assert($identity['status'] === 200
    && (json_decode($identity['body'], true)['database'] ?? '') === $target,
    'Server HTTP tidak memakai database clone yang sama.');

$account = $koneksi->query("SELECT id,username,password,unit_id FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
invalid_unit_assert((bool)$account, 'Akun admin SD fixture tidak tersedia.');
$id = (int)$account['id'];
$savedHash = (string)$account['password'];
$savedUnit = (int)$account['unit_id'];
$hash = password_hash($password, PASSWORD_DEFAULT);
$failure = null;
try {
    $stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    $stmt->bind_param('si', $hash, $id); $stmt->execute(); $stmt->close();

    $browser = [];
    $login = invalid_unit_http($base . '/login.php', ['username'=>$account['username'], 'password'=>$password], $browser);
    invalid_unit_assert($login['status'] === 302 && isset($browser['PHPSESSID']), 'Login akun admin valid gagal.');
    $page = invalid_unit_http($base . '/dashboard.php', null, $browser);
    invalid_unit_assert($page['status'] === 200, 'Halaman admin SD valid tidak terbuka.');

    $koneksi->query('UPDATE admin SET unit_id=NULL WHERE id=' . $id);
    $page = invalid_unit_http($base . '/dashboard.php', null, $browser);
    invalid_unit_assert($page['status'] === 302 && str_contains($page['location'], 'login.php'),
        'Sesi akun admin tanpa unit masih dapat membuka dashboard.');
    $page = invalid_unit_http($base . '/siswa/daftar.php', null, $browser);
    invalid_unit_assert($page['status'] === 302 && str_contains($page['location'], 'login.php'),
        'Sesi akun admin tanpa unit masih dapat membuka Data Siswa.');

    $browser = [];
    $login = invalid_unit_http($base . '/login.php', ['username'=>$account['username'], 'password'=>$password], $browser);
    invalid_unit_assert($login['status'] === 200 && str_contains($login['body'], 'Username atau password salah'),
        'Akun admin tanpa unit masih diterima saat login baru.');

    $_SESSION['admin_id'] = $id;
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['active_unit_id'] = 1;
    unit_bootstrap_context($koneksi);
    invalid_unit_assert(!isset($_SESSION['admin_id']) && $GLOBALS['app_unit_id'] === 4,
        'Konteks akun tanpa unit tidak dikosongkan.');
    $visible = (int)$koneksi->query('SELECT COUNT(*) AS n FROM siswa')->fetch_assoc()['n'];
    invalid_unit_assert($visible === 0, 'VIEW siswa masih menampilkan data lintas unit.');
    echo "PASS: akun non-super tanpa unit ditolak saat login dan sesi lama kehilangan akses; VIEW siswa kosong.\n";
} catch (Throwable $error) {
    $failure = $error;
} finally {
    unit_set_context($koneksi, $savedUnit);
    $stmt = $koneksi->prepare('UPDATE admin SET password=?,unit_id=? WHERE id=?');
    $stmt->bind_param('sii', $savedHash, $savedUnit, $id); $stmt->execute(); $stmt->close();
    unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['active_unit_id']);
}
if ($failure) throw $failure;
