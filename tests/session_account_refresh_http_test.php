<?php
/** Active HTTP sessions must observe account deactivation, role and unit changes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$database = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $database)) {
    throw new RuntimeException('Tes akun hanya untuk database audit berflag.');
}
$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8813'), '/');
$url = parse_url($base);
if (($url['scheme'] ?? '') !== 'http' || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Server harus memakai HTTP loopback.');
}
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
function session_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function session_http(string $url, ?array $post, array &$cookies): array {
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
    session_assert($body !== false, 'Permintaan HTTP gagal.');
    $status = 0;
    $location = '';
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Location:\s*(.*)$/i', $header, $match)) $location = trim($match[1]);
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'location' => $location, 'body' => $body];
}
function session_student_active(mysqli $db, int $studentId): int {
    $stmt = $db->prepare('SELECT is_active FROM siswa WHERE id=?');
    $stmt->bind_param('i', $studentId); $stmt->execute();
    $status = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    return $status;
}

$probeCookies = [];
$identity = session_http($base . '/tests/browser_clone_identity.php', null, $probeCookies);
session_assert($identity['status'] === 200
    && (json_decode($identity['body'], true)['database'] ?? '') === $database,
    'Server HTTP tidak menuju clone yang sama.');
$admin = $koneksi->query("SELECT id,username,password,role,unit_id,is_active FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
session_assert((bool)$admin, 'Admin SD fixture tidak tersedia.');
$adminId = (int)$admin['id'];
$student = $koneksi->query('SELECT s.id,s.NO_INDUK FROM siswa s WHERE s.is_active=1 AND NOT EXISTS(SELECT 1 FROM siswa_data other WHERE other.unit_id=2 AND other.NO_INDUK=s.NO_INDUK) ORDER BY s.NO_INDUK LIMIT 1')->fetch_assoc();
session_assert((bool)$student, 'Siswa SD fixture tidak tersedia.');
$studentId = (int)$student['id'];
$sdNis = (string)$student['NO_INDUK'];
$smp = $koneksi->query("SELECT NO_INDUK FROM siswa_data WHERE unit_id=2 AND is_active=1 ORDER BY NO_INDUK LIMIT 1")->fetch_row();
session_assert((bool)$smp, 'Siswa SMP fixture tidak tersedia.');
$smpNis = (string)$smp[0];
$password = hash_hmac('sha256', bin2hex(random_bytes(16)), $seed);
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
$stmt->bind_param('si', $hash, $adminId); $stmt->execute(); $stmt->close();

try {
    $cookies = [];
    $login = session_http($base . '/login.php', ['username' => $admin['username'], 'password' => $password], $cookies);
    session_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin latihan gagal.');
    $studentPage = session_http($base . '/siswa/daftar.php?q=' . rawurlencode($sdNis), null, $cookies);
    session_assert($studentPage['status'] === 200
        && str_contains($studentPage['body'], '<span class="badge-nis">' . $sdNis . '</span>')
        && (bool)preg_match('/<form\b[^>]*id="form-master-siswa"[^>]*>.*?<\/form>/si', $studentPage['body'], $form)
        && (bool)preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form[0], $csrf),
        'Sesi awal tidak dapat membaca siswa SD atau token formulir.');
    $archive = ['aksi' => 'toggle_status', 'id' => $studentId,
        'target_active' => '0', 'csrf_token' => $csrf[1]];

    $stmt = $koneksi->prepare('UPDATE admin SET is_active=0 WHERE id=?');
    $stmt->bind_param('i', $adminId); $stmt->execute(); $stmt->close();
    $disabledPost = session_http($base . '/siswa/daftar.php', $archive, $cookies);
    session_assert($disabledPost['status'] === 302
        && str_ends_with($disabledPost['location'], 'login.php')
        && session_student_active($koneksi, $studentId) === 1,
        'Akun nonaktif masih dapat memutasi siswa melalui sesi lama.');
    $disabledGet = session_http($base . '/siswa/daftar.php', null, $cookies);
    session_assert($disabledGet['status'] === 302
        && str_ends_with($disabledGet['location'], 'login.php'),
        'Akun nonaktif masih dapat membaca halaman siswa.');

    $stmt = $koneksi->prepare('UPDATE admin SET is_active=1 WHERE id=?');
    $stmt->bind_param('i', $adminId); $stmt->execute(); $stmt->close();
    $cookies = [];
    $login = session_http($base . '/login.php', ['username' => $admin['username'], 'password' => $password], $cookies);
    session_assert($login['status'] === 302, 'Login ulang admin latihan gagal.');
    $studentPage = session_http($base . '/siswa/daftar.php', null, $cookies);
    session_assert($studentPage['status'] === 200, 'Sesi kedua tidak terbuka.');
    $stmt = $koneksi->prepare("UPDATE admin SET role='bendahara' WHERE id=?");
    $stmt->bind_param('i', $adminId); $stmt->execute(); $stmt->close();
    $downgradedPost = session_http($base . '/siswa/daftar.php', $archive, $cookies);
    session_assert($downgradedPost['status'] === 302
        && str_contains($downgradedPost['location'], 'laporan/index.php')
        && session_student_active($koneksi, $studentId) === 1,
        'Role yang diturunkan masih dapat memutasi siswa.');
    $report = session_http($base . '/laporan/index.php', null, $cookies);
    session_assert($report['status'] === 200, 'Sesi bendahara tidak dapat membaca laporan yang diizinkan.');

    $stmt = $koneksi->prepare("UPDATE admin SET role='admin',unit_id=2 WHERE id=?");
    $stmt->bind_param('i', $adminId); $stmt->execute(); $stmt->close();
    $oldUnitPost = session_http($base . '/siswa/daftar.php', $archive, $cookies);
    session_assert($oldUnitPost['status'] === 302 && session_student_active($koneksi, $studentId) === 1,
        'Sesi yang berpindah unit masih dapat memutasi siswa unit lama.');
    $oldUnitRead = session_http($base . '/siswa/daftar.php?q=' . rawurlencode($sdNis), null, $cookies);
    $newUnitRead = session_http($base . '/siswa/daftar.php?q=' . rawurlencode($smpNis), null, $cookies);
    session_assert($oldUnitRead['status'] === 200
        && !str_contains($oldUnitRead['body'], '<span class="badge-nis">' . $sdNis . '</span>')
        && $newUnitRead['status'] === 200
        && str_contains($newUnitRead['body'], '<span class="badge-nis">' . $smpNis . '</span>'),
        'Sesi yang berpindah unit tidak mengikuti cakupan siswa terbaru.');
    echo "PASS: sesi lama menolak akun nonaktif, memperbarui role, dan membatasi baca/tulis ke unit baru.\n";
} finally {
    $role = (string)$admin['role'];
    $unit = (int)$admin['unit_id'];
    $active = (int)$admin['is_active'];
    $oldHash = (string)$admin['password'];
    $stmt = $koneksi->prepare('UPDATE admin SET password=?,role=?,unit_id=?,is_active=? WHERE id=?');
    $stmt->bind_param('ssiii', $oldHash, $role, $unit, $active, $adminId);
    $stmt->execute(); $stmt->close();
}
