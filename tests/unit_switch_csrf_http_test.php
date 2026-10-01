<?php
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "SKIPPED: hanya untuk database audit disposable.\n");
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';

function unit_csrf_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function unit_csrf_post(string $url, string $sessionId, array $body): string {
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'follow_location' => 0, 'ignore_errors' => true,
        'timeout' => 20,
        'header' => 'Cookie: ' . session_name() . '=' . $sessionId . "\r\n"
            . 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($body),
    ]]);
    file_get_contents($url, false, $context);
    return $http_response_header[0] ?? '';
}

$base = rtrim((string)getenv('SPP_HTTP_BASE'), '/');
$host = parse_url($base, PHP_URL_HOST);
unit_csrf_assert(in_array($host, ['127.0.0.1', 'localhost'], true), 'Server HTTP harus lokal.');
unit_csrf_assert((string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0] === getenv('SPP_DB_NAME'), 'Koneksi CLI salah database.');
$account = $koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
unit_csrf_assert((bool)$account, 'Akun super admin latihan tidak tersedia.');

$sessionId = 'unitcsrf' . bin2hex(random_bytes(12));
session_id($sessionId);
session_start();
$_SESSION = ['admin_id' => (int)$account['id'], 'admin_role' => 'super_admin',
    'admin_nama' => 'Tes Unit', 'active_unit_id' => 1];
session_write_close();
$passed = false;
try {
    $url = $base . '/unit_switch.php';
    $status = unit_csrf_post($url, $sessionId, ['unit_id' => 2, 'next' => '/dashboard.php']);
    unit_csrf_assert(str_contains($status, '403'), 'Peralihan unit tanpa token tidak ditolak.');
    session_id($sessionId);
    session_start();
    unit_csrf_assert((int)($_SESSION['active_unit_id'] ?? 0) === 1, 'Unit berubah tanpa token.');
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_unit_switch'] = $token;
    session_write_close();

    $status = unit_csrf_post($url, $sessionId, [
        'unit_id' => 2, 'next' => '/dashboard.php', 'csrf_token' => $token,
    ]);
    unit_csrf_assert(str_contains($status, '303'), 'Peralihan unit dengan token sah gagal.');
    session_id($sessionId);
    session_start();
    unit_csrf_assert((int)($_SESSION['active_unit_id'] ?? 0) === 2, 'Unit tidak berubah dengan token sah.');
    session_write_close();
    $passed = true;
} finally {
    session_id($sessionId);
    session_start();
    $_SESSION = [];
    session_destroy();
    session_write_close();
}
if ($passed) echo "OK: peralihan unit tanpa token ditolak, token sah bekerja.\n";
