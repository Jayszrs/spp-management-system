<?php
require_once __DIR__ . "/http_form_scope.php";
/** Replaying one publish form cannot create or undo an additional other-fee debt. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $target)) {
    throw new RuntimeException('Tes mutasi hanya untuk database audit berflag.');
}
$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8810'), '/');
spp_test_assert_http_clone($base, $target);
$parts = parse_url($base);
if (($parts['scheme'] ?? '') !== 'http' || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Server harus memakai HTTP loopback.');
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

function fee_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function fee_http(string $url, ?array $post, array &$cookies): array {
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
    fee_assert($body !== false, 'Permintaan HTTP gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status'=>$status, 'body'=>$body];
}
function fee_bill_state(mysqli $db, int $id): array {
    $stmt = $db->prepare('SELECT nominal_tagihan,status FROM tagihan_biaya_lain WHERE id=?');
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    fee_assert((bool)$row, 'Tagihan fixture hilang.');
    return $row;
}

$probeCookies = [];
$identity = fee_http($base . '/tests/browser_clone_identity.php', null, $probeCookies);
fee_assert($identity['status'] === 200
    && (json_decode($identity['body'], true)['database'] ?? '') === $target,
    'Server HTTP tidak menuju clone yang sama.');
$admin = $koneksi->query("SELECT id,username,password FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
fee_assert((bool)$admin, 'Admin SD fixture tidak tersedia.');
$adminId = (int)$admin['id'];
$oldHash = (string)$admin['password'];
$password = hash_hmac('sha256', bin2hex(random_bytes(16)), $seed);
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
$stmt->bind_param('si', $hash, $adminId); $stmt->execute(); $stmt->close();

$billId = 0;
$original = null;
try {
    $fixture = $koneksi->query("SELECT t.id,t.master_biaya_lain_id,t.no_induk,t.nominal_tagihan,
            m.nominal,COALESCE(SUM(d.nominal_snapshot),0) paid
        FROM tagihan_biaya_lain t
        JOIN master_biaya_lain m ON m.id=t.master_biaya_lain_id AND m.is_active=1
        JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.is_active=1
        LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id
        WHERE t.status='open'
        GROUP BY t.id,t.master_biaya_lain_id,t.no_induk,t.nominal_tagihan,m.nominal
        HAVING paid=nominal_tagihan AND paid>0
        ORDER BY t.id LIMIT 1")->fetch_assoc();
    fee_assert((bool)$fixture, 'Tagihan Biaya Lain lunas untuk fixture tidak tersedia.');
    $billId = (int)$fixture['id'];
    $original = fee_bill_state($koneksi, $billId);

    $browser = [];
    $login = fee_http($base . '/login.php', ['username'=>$admin['username'], 'password'=>$password], $browser);
    fee_assert($login['status'] === 302 && isset($browser['PHPSESSID']), 'Login admin latihan gagal.');
    $page = fee_http($base . '/master_biaya_lain.php', null, $browser);
    fee_assert($page['status'] === 200, 'Form penerbitan tidak terbuka.');
    fee_assert((bool)preg_match('/<form\b[^>]*id="form-terbit-biaya"[^>]*>.*?<\/form>/si', $page['body'], $form),
        'Form penerbitan tidak ditemukan.');
    fee_assert((bool)preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form[0], $csrf),
        'Token CSRF penerbitan tidak ditemukan.');
    fee_assert((bool)preg_match('/name="publish_request_key" value="([a-f0-9]{32})"/', $form[0], $request),
        'Kunci penerbitan sekali pakai tidak ditemukan.');
    $payload = [
        'aksi'=>'terbitkan_tagihan', 'csrf_token'=>$csrf[1],
        'publish_request_key'=>$request[1],
        'master_id'=>(string)$fixture['master_biaya_lain_id'],
        'target'=>'siswa', 'no_induk'=>[$fixture['no_induk']],
    ];
    $first = fee_http($base . '/master_biaya_lain.php', $payload, $browser);
    fee_assert($first['status'] === 302, 'Penerbitan pertama gagal HTTP.');
    $afterFirst = fee_bill_state($koneksi, $billId);
    fee_assert(abs((float)$afterFirst['nominal_tagihan'] - ((float)$fixture['paid'] + (float)$fixture['nominal'])) < .001,
        'Penerbitan ulang sengaja tidak menambah satu biaya baru.');

    $replay = fee_http($base . '/master_biaya_lain.php', $payload, $browser);
    fee_assert($replay['status'] === 302, 'Kiriman ulang gagal HTTP.');
    $afterReplay = fee_bill_state($koneksi, $billId);
    fee_assert(abs((float)$afterReplay['nominal_tagihan'] - (float)$afterFirst['nominal_tagihan']) < .001,
        'Kiriman ulang mengubah tagihan Biaya Lain.');

    $freshPage = fee_http($base . '/master_biaya_lain.php', null, $browser);
    fee_assert($freshPage['status'] === 200
        && (bool)preg_match('/<form\b[^>]*id="form-terbit-biaya"[^>]*>.*?<\/form>/si', $freshPage['body'], $freshForm)
        && (bool)preg_match('/name="publish_request_key" value="([a-f0-9]{32})"/', $freshForm[0], $freshKey),
        'Formulir penerbitan baru tidak tersedia.');
    $freshPayload = $payload;
    $freshPayload['publish_request_key'] = $freshKey[1];
    fee_http($base . '/master_biaya_lain.php', $freshPayload, $browser);
    $afterFresh = fee_bill_state($koneksi, $billId);
    fee_assert(abs((float)$afterFresh['nominal_tagihan'] - (float)$afterFirst['nominal_tagihan']) < .001,
        'Permintaan baru membatalkan tagihan yang masih terbuka.');
    echo "PASS: POST ulang dan formulir baru saat tagihan ulang masih terbuka tidak mengubah kewajiban Biaya Lain.\n";
} finally {
    if ($billId > 0 && $original) {
        $restore = $koneksi->prepare('UPDATE tagihan_biaya_lain SET nominal_tagihan=?,status=? WHERE id=?');
        $amount = (float)$original['nominal_tagihan'];
        $status = (string)$original['status'];
        $restore->bind_param('dsi', $amount, $status, $billId); $restore->execute(); $restore->close();
    }
    $restore = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    $restore->bind_param('si', $oldHash, $adminId); $restore->execute(); $restore->close();
}
