<?php
/** Replaying a status form must not undo the operator's original action. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $target)) {
    throw new RuntimeException('Tes mutasi hanya boleh berjalan pada clone audit berflag.');
}
$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8810'), '/');
$url = parse_url($base);
if (($url['scheme'] ?? '') !== 'http' || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Server tes harus memakai HTTP loopback.');
}
$seed = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($seed === '' && ($path = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE')) !== '') {
    $seed = trim((string)file_get_contents($path));
}
if ($seed === '') throw new RuntimeException('Sandi latihan belum tersedia.');

session_start();
require_once __DIR__ . '/../koneksi.php';
if (DB_NAME !== $target || $koneksi->query('SELECT DATABASE()')->fetch_row()[0] !== $target) {
    throw new RuntimeException('Koneksi CLI tidak menuju clone audit.');
}
unit_set_context($koneksi, 1);

function toggle_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function toggle_http(string $url, ?array $post, array &$cookies): array {
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
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    toggle_assert($body !== false, 'Permintaan HTTP gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status'=>$status, 'body'=>$body];
}
function toggle_token(string $html, string $action): string {
    preg_match_all('/<form\b[^>]*>.*?<\/form>/si', $html, $forms);
    foreach ($forms[0] as $form) {
        if (!str_contains($form, 'name="aksi" value="' . $action . '"')) continue;
        if (preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form, $match)) return $match[1];
    }
    throw new RuntimeException('Token form aksi ' . $action . ' tidak ditemukan.');
}
function toggle_status(mysqli $db, string $table, int $id): int {
    $stmt = $db->prepare("SELECT is_active FROM {$table} WHERE id=?");
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    toggle_assert((bool)$row, 'Fixture status tidak ditemukan.');
    return (int)$row['is_active'];
}
function toggle_student_audit_count(mysqli $db, int $id): int {
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM siswa_audit_log WHERE siswa_id=? AND aksi IN ('arsipkan','pulihkan')");
    $stmt->bind_param('i', $id); $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
    return $count;
}

$identityCookies = [];
$identity = toggle_http($base . '/tests/browser_clone_identity.php', null, $identityCookies);
toggle_assert($identity['status'] === 200
    && (json_decode($identity['body'], true)['database'] ?? '') === $target,
    'Server HTTP tidak terhubung ke clone yang sama.');
$admin = $koneksi->query("SELECT id,username,password FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
toggle_assert((bool)$admin, 'Admin SD untuk fixture tidak tersedia.');
$adminId = (int)$admin['id'];
$originalHash = (string)$admin['password'];
$password = hash_hmac('sha256', bin2hex(random_bytes(16)), $seed);
$newHash = password_hash($password, PASSWORD_DEFAULT);
$setHash = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
$setHash->bind_param('si', $newHash, $adminId); $setHash->execute(); $setHash->close();

try {
    $student = $koneksi->query("SELECT s.id FROM siswa s WHERE s.is_active=1
        AND NOT EXISTS (SELECT 1 FROM siswa_tahun_ajaran sta WHERE sta.no_induk=s.NO_INDUK AND sta.status='lulus')
        ORDER BY s.id LIMIT 1")->fetch_assoc();
    $class = $koneksi->query("SELECT mk.id FROM master_kelas mk WHERE mk.is_active=1 AND mk.is_placeholder=0
        AND NOT EXISTS (SELECT 1 FROM siswa s WHERE s.master_kelas_id=mk.id AND s.is_active=1)
        ORDER BY mk.id DESC LIMIT 1")->fetch_assoc();
    $fee = $koneksi->query('SELECT id FROM master_biaya_lain WHERE is_active=1 ORDER BY id LIMIT 1')->fetch_assoc();
    toggle_assert($student && $class && $fee, 'Fixture siswa, rombel kosong, atau biaya lain tidak lengkap.');

    $browser = [];
    $login = toggle_http($base . '/login.php', ['username'=>$admin['username'], 'password'=>$password], $browser);
    toggle_assert($login['status'] === 302 && isset($browser['PHPSESSID']), 'Login admin latihan gagal.');

    foreach ([
        ['siswa/daftar.php', 'siswa', (int)$student['id'], 'toggle_status'],
        ['master_kelas.php', 'master_kelas', (int)$class['id'], 'toggle'],
        ['master_biaya_lain.php', 'master_biaya_lain', (int)$fee['id'], 'toggle'],
    ] as [$path, $table, $id, $action]) {
        $page = toggle_http($base . '/' . $path, null, $browser);
        toggle_assert($page['status'] === 200, "Form {$path} tidak terbuka.");
        $token = toggle_token($page['body'], $action);
        toggle_assert(toggle_status($koneksi, $table, $id) === 1, "Fixture {$table} tidak aktif.");
        $auditBefore = $table === 'siswa' ? toggle_student_audit_count($koneksi, $id) : null;
        $payload = ['aksi'=>$action, 'id'=>$id, 'target_active'=>'0', 'csrf_token'=>$token];

        $invalid = $payload; $invalid['csrf_token'] = 'invalid';
        toggle_http($base . '/' . $path, $invalid, $browser);
        toggle_assert(toggle_status($koneksi, $table, $id) === 1, "CSRF salah mengubah {$table}.");
        toggle_http($base . '/' . $path . '?aksi=' . urlencode($action) . '&id=' . $id, null, $browser);
        toggle_assert(toggle_status($koneksi, $table, $id) === 1, "GET mengubah {$table}.");

        $first = toggle_http($base . '/' . $path, $payload, $browser);
        if ($first['status'] !== 302 || toggle_status($koneksi, $table, $id) !== 0) {
            $feedback = toggle_http($base . '/' . $path, null, $browser);
            preg_match('/class="alert[^\"]*"[^>]*>(.*?)<\/div>/s', $feedback['body'], $match);
            $message = isset($match[1]) ? trim(strip_tags($match[1])) : '';
            throw new RuntimeException("Permintaan pertama tidak menonaktifkan {$table}. {$message}");
        }
        $replay = toggle_http($base . '/' . $path, $payload, $browser);
        toggle_assert($replay['status'] === 302 && toggle_status($koneksi, $table, $id) === 0,
            "Kiriman ulang mengaktifkan kembali {$table}.");

        $stale = $payload; unset($stale['target_active']);
        toggle_http($base . '/' . $path, $stale, $browser);
        toggle_assert(toggle_status($koneksi, $table, $id) === 0,
            "Formulir lama tanpa status tujuan mengubah {$table}.");

        $currentPage = toggle_http($base . '/' . $path, null, $browser);
        $restorePayload = ['aksi'=>$action, 'id'=>$id, 'target_active'=>'1',
            'csrf_token'=>toggle_token($currentPage['body'], $action)];
        $restore = toggle_http($base . '/' . $path, $restorePayload, $browser);
        toggle_assert($restore['status'] === 302 && toggle_status($koneksi, $table, $id) === 1,
            "Permintaan pemulihan tidak mengaktifkan {$table}.");
        $restoreReplay = toggle_http($base . '/' . $path, $restorePayload, $browser);
        toggle_assert($restoreReplay['status'] === 302 && toggle_status($koneksi, $table, $id) === 1,
            "Kiriman ulang pemulihan menonaktifkan {$table}.");
        if ($table === 'siswa') {
            toggle_assert(toggle_student_audit_count($koneksi, $id) === $auditBefore + 2,
                'Kiriman ulang membuat audit siswa tambahan.');
        }
    }
    echo "PASS: status siswa, rombel, dan biaya lain menolak CSRF salah, GET, form lama, dan POST ulang di kedua arah.\n";
} finally {
    $restore = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    $restore->bind_param('si', $originalHash, $adminId); $restore->execute(); $restore->close();
}
