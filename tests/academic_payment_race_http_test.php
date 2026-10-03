<?php
/** Two cashier sessions must not pay the same annual/monthly obligation twice. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    throw new RuntimeException('Use CLI and a flagged disposable audit database.');
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/spp_billing.php';
require_once __DIR__ . '/../includes/komite_billing.php';
require_once __DIR__ . '/http_form_scope.php';

function race_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function race_http(string $url, ?array $data, array &$cookies): array {
    $headers = [];
    if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(
        static fn($name, $value) => $name . '=' . $value, array_keys($cookies), $cookies));
    $body = file_get_contents($url, false, stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
        'content' => $data === null ? '' : http_build_query($data),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
    ]]));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) $status = (int)$m[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $m)) $cookies[$m[1]] = $m[2];
    }
    race_assert($body !== false, 'HTTP request failed.');
    return ['status' => $status, 'body' => $body];
}
function race_form(string $base, array &$cookies): array {
    $page = race_http($base . '/pembayaran/form.php', null, $cookies);
    race_assert($page['status'] === 200, 'Cashier form unavailable.');
    $form = spp_test_form_scope($page['body'], 'no_induk');
    race_assert(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form, $csrf) === 1
        && preg_match('/name="request_key" value="([a-f0-9]{32})"/', $form, $key) === 1, 'Form guards missing.');
    return ['csrf_token' => $csrf[1], 'request_key' => $key[1]];
}
function race_parallel(array $requests): void {
    $multi = curl_multi_init(); $handles = [];
    foreach ($requests as [$base, $data, $cookies]) {
        $handle = curl_init($base . '/pembayaran/proses.php');
        $cookie = implode('; ', array_map(static fn($name, $value) => $name . '=' . $value, array_keys($cookies), $cookies));
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . $cookie]]);
        curl_multi_add_handle($multi, $handle); $handles[] = $handle;
    }
    do { $state = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, .1); }
    while ($running && $state === CURLM_OK);
    $valid = true;
    foreach ($handles as $handle) {
        $valid = $valid && curl_getinfo($handle, CURLINFO_HTTP_CODE) === 302 && curl_errno($handle) === 0;
        curl_multi_remove_handle($multi, $handle); curl_close($handle);
    }
    curl_multi_close($multi);
    race_assert($valid, 'Concurrent requests did not finish normally.');
}

$bases = [rtrim((string)getenv('SPP_TEST_BASE_URL'), '/'), rtrim((string)getenv('SPP_TEST_SECOND_BASE_URL'), '/')];
foreach ($bases as $base) {
    $url = parse_url($base);
    race_assert(($url['scheme'] ?? '') === 'http' && in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true),
        'Use two loopback PHP servers.');
    $emptyCookies = [];
    $identity = race_http($base . '/tests/browser_clone_identity.php', null, $emptyCookies);
    race_assert($identity['status'] === 200 && (json_decode($identity['body'], true)['database'] ?? '') === DB_NAME,
        'HTTP target differs from the disposable database.');
}
race_assert($koneksi->query('SELECT DATABASE()')->fetch_row()[0] === DB_NAME, 'Wrong CLI target.');
$nis = (string)random_int(9700000000, 9799999999);
$komiteNis = (string)random_int(9600000000, 9699999999);
$hashes = []; $keys = []; $yearId = 0; $masterId = 0;
$failure = null;
try {
    $password = bin2hex(random_bytes(24)); $hash = password_hash($password, PASSWORD_DEFAULT);
    foreach (['kasir1', 'kasir2'] as $username) {
        $account = $koneksi->query("SELECT id,password FROM admin WHERE username='" . $username . "' AND role='kasir' AND unit_id=1 AND is_active=1")->fetch_assoc();
        race_assert((bool)$account, 'SD cashier fixture unavailable.');
        $id = (int)$account['id']; $hashes[$id] = $account['password'];
        $stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
        $stmt->bind_param('si', $hash, $id); $stmt->execute(); $stmt->close();
    }
    $sessions = [[], []];
    foreach (['kasir1', 'kasir2'] as $index => $username) {
        $login = race_http($bases[$index] . '/login.php', ['username' => $username, 'password' => $password], $sessions[$index]);
        race_assert($login['status'] === 302 && isset($sessions[$index]['PHPSESSID']), 'Cashier login failed.');
    }
    $classId = (int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' AND is_active=1")->fetch_row()[0];
    $koneksi->begin_transaction();
    $start = (int)date('Y') - 8;
    while ((int)$koneksi->query("SELECT COUNT(*) FROM tahun_ajaran WHERE label='{$start}/" . ($start + 1) . "'")->fetch_row()[0] > 0) $start++;
    race_assert($start <= (int)date('Y') + 10, 'No free fixture year within the payment horizon.');
    $label = $start . '/' . ($start + 1);
    $master = spp_master_ensure_year($koneksi, $label, true);
    $masterId = (int)$master['id']; $yearId = (int)$master['tahun_ajaran_id'];
    spp_master_save_rates($koneksi, $masterId, array_fill(1, 6, 250000));
    $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG) VALUES(?,'UJI RACE AKADEMIK','1',?,250000,15000)");
    $stmt->bind_param('si', $nis, $classId); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,'1',?,'1A',250000,15000,'aktif')");
    $stmt->bind_param('isi', $yearId, $nis, $classId); $stmt->execute(); $placementId = (int)$koneksi->insert_id; $stmt->close();
    komite_sync_placement($koneksi, $placementId);
    race_assert(spp_publish_students($koneksi, $masterId, [$nis])['created'] === 12, 'Fixture SPP publication failed.');
    // A separate student's Komite may be paid independently because SPP is not issued.
    $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG) VALUES(?,'UJI RACE KOMITE','1',?,250000,15000)");
    $stmt->bind_param('si', $komiteNis, $classId); $stmt->execute(); $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,'1',?,'1A',250000,15000,'aktif')");
    $stmt->bind_param('isi', $yearId, $komiteNis, $classId); $stmt->execute(); $komitePlacement = (int)$koneksi->insert_id; $stmt->close();
    komite_sync_placement($koneksi, $komitePlacement);
    $stmt = $koneksi->prepare("INSERT INTO tagihan_daftar_ulang(tahun_ajaran_id,penempatan_id,no_induk,kelas_snapshot,tahun_ajaran_snapshot,nominal_awal,nominal_tagihan) VALUES(?, ?, ?, '1', ?,1000000,1000000)");
    $stmt->bind_param('iiss', $yearId, $placementId, $nis, $label); $stmt->execute(); $duId = (int)$koneksi->insert_id; $stmt->close();
    $koneksi->commit();
    $baseData = ['aksi' => 'input', 'payment_plan' => 'monthly', 'no_induk' => $nis,
        'bulan_bayar' => '07', 'tahun_bayar' => (string)$start,
        'sistem_pembayaran' => 'Tunai', 'spp_action' => 'bayar'];
    foreach ([
        'SPP + Komite Juli' => ['uang_spp' => 250000, 'uang_komite' => 15000],
        'Komite Agustus tanpa SPP terbit' => ['no_induk' => $komiteNis, 'bulan_bayar' => '08', 'uang_komite' => 15000],
        'Daftar Ulang' => ['uang_du' => 600000, 'tagihan_daftar_ulang_id' => $duId],
    ] as $scenario => $amounts) {
        $scenarioNis = (string)($amounts['no_induk'] ?? $nis);
        $before = (int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='{$scenarioNis}'")->fetch_row()[0];
        $forms = [race_form($bases[0], $sessions[0]), race_form($bases[1], $sessions[1])];
        foreach ($forms as $form) $keys[] = $form['request_key'];
        race_assert($forms[0]['request_key'] !== $forms[1]['request_key'], 'Cashier requests must have different keys.');
        race_parallel([
            [$bases[0], $amounts + $baseData + $forms[0], $sessions[0]],
            [$bases[1], $amounts + $baseData + $forms[1], $sessions[1]],
        ]);
        $after = (int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='{$scenarioNis}'")->fetch_row()[0];
        if ($after !== $before + 1) {
            $feedback = [];
            foreach ($bases as $index => $base) {
                $page = race_http($base . '/pembayaran/form.php', null, $sessions[$index]);
                preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $page['body'], $flash);
                preg_match('/window\.sppFlashWarning\s*=\s*([^\n;]+);/', $page['body'], $warning);
                $feedback[] = trim(html_entity_decode(strip_tags($flash[1] ?? ''))) . ' '
                    . (json_decode($warning[1] ?? '{}', true)['message'] ?? '');
            }
            throw new RuntimeException($scenario . ': expected one payment; before=' . $before . ', after=' . $after
                . ', feedback=' . implode(' / ', $feedback));
        }
    }
    $totals = $koneksi->query("SELECT SUM(U_SPP) spp,SUM(U_KOMITE) komite,SUM(total_jumlah) cash FROM bayar WHERE NO_INDUK IN ('{$nis}','{$komiteNis}')")->fetch_assoc();
    $du = (float)$koneksi->query("SELECT SUM(jumlah) FROM bayar_du WHERE no_induk='{$nis}'")->fetch_row()[0];
    $allocation = (float)$koneksi->query("SELECT SUM(a.nominal_dari_bayar) FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id WHERE b.no_induk='{$nis}' AND b.status='active'")->fetch_row()[0];
    race_assert((float)$totals['spp'] === 250000.0 && (float)$totals['komite'] === 30000.0
        && $du === 600000.0 && $allocation === 250000.0 && (float)$totals['cash'] === 880000.0,
        'Header, component details, and allocations differ after concurrent requests.');
} catch (Throwable $error) {
    $failure = $error;
    try { $koneksi->rollback(); } catch (Throwable $ignored) {}
} finally {
    foreach ($keys as $key) {
        $stmt = $koneksi->prepare('DELETE FROM keuangan_request WHERE request_key=?');
        $stmt->bind_param('s', $key); $stmt->execute(); $stmt->close();
    }
    foreach ([$nis, $komiteNis] as $cleanupNis) foreach ([
        'DELETE a FROM spp_alokasi a JOIN spp_alokasi_batch b ON b.id=a.batch_id WHERE b.no_induk=?',
        'DELETE FROM spp_alokasi_batch WHERE no_induk=?',
        'DELETE FROM bayar_komite WHERE bayar_id IN (SELECT id FROM bayar WHERE NO_INDUK=?)',
        'DELETE FROM bayar_du WHERE no_induk=?', 'DELETE FROM bayar WHERE NO_INDUK=?',
        'DELETE FROM tagihan_spp WHERE no_induk=?', 'DELETE FROM tagihan_komite WHERE no_induk=?',
        'DELETE FROM tagihan_daftar_ulang WHERE no_induk=?', 'DELETE FROM spp_audit_log WHERE no_induk=?',
        'DELETE FROM siswa_tahun_ajaran WHERE no_induk=?', 'DELETE FROM siswa WHERE NO_INDUK=?',
    ] as $sql) {
        $stmt = $koneksi->prepare($sql); $stmt->bind_param('s', $cleanupNis); $stmt->execute(); $stmt->close();
    }
    if ($masterId > 0) {
        $koneksi->query('DELETE FROM spp_audit_log WHERE master_spp_tahun_id=' . $masterId);
        $koneksi->query('DELETE FROM master_spp_tarif WHERE master_spp_tahun_id=' . $masterId);
        $koneksi->query('DELETE FROM master_spp_tahun WHERE id=' . $masterId);
    }
    if ($yearId > 0) $koneksi->query('DELETE FROM tahun_ajaran WHERE id=' . $yearId);
    foreach ($hashes as $id => $originalHash) {
        $stmt = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
        $stmt->bind_param('si', $originalHash, $id); $stmt->execute(); $stmt->close();
    }
}
if ($failure) throw $failure;
echo "OK: two cashiers, different keys, one SPP/Komite/DU payment per obligation, intact totals and cleanup.\n";
