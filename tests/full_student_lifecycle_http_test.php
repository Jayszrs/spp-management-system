<?php

/** End-to-end HTTP school-year simulation; run only on a disposable db_spp_audit_* clone. */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';

if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1' || !str_starts_with(DB_NAME, 'db_spp_audit_')) {
    fwrite(STDERR, "SKIPPED: use SPP_TEST_ALLOW_MUTATION=1 and a db_spp_audit_* database.\n");
    exit(0);
}
$password = (string)getenv('SPP_TEST_ADMIN_PASSWORD');
if ($password === '' && ($file = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE')) !== '') $password = trim(file_get_contents($file));
if ($password === '') throw new RuntimeException('SPP_TEST_ADMIN_PASSWORD is required.');
$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8098'), '/');

function lifecycle_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function lifecycle_request(string $url, ?array $data, array &$cookies, string $academicYear = ''): array {
    $headers = [];
    if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($academicYear !== '') $headers[] = 'X-SPP-Test-Current-Year: ' . $academicYear;
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $key => $value) $pairs[] = $key . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $data === null ? '' : http_build_query($data),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    lifecycle_assert($body !== false, 'HTTP request failed: ' . $url);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

function lifecycle_token(string $html): string {
    lifecycle_assert(preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $match) === 1, 'CSRF token missing.');
    return $match[1];
}

function lifecycle_flash(string $html): string {
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $html, $match)) {
        return trim(html_entity_decode(strip_tags($match[1])));
    }
    return '';
}

function lifecycle_class(mysqli $db, int $level): int {
    $stmt = $db->prepare("SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1");
    $stmt->bind_param('i', $level); $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();
    lifecycle_assert($id > 0, 'Class ' . $level . 'A missing.');
    return $id;
}

function lifecycle_report(mysqli $db, string $template, string $nis, string $year, string $status): array {
    return report_build($db, $template, report_filters($db, [
        'q' => $nis, 'tahun_ajaran' => $year, 'siswa_status' => $status,
        'kategori' => 'spp', 'bulan_awal' => '07',
    ]))['rows'];
}

$cookies = [];
$nis = (string)random_int(9900000000, 9999999999);
$firstYear = '2030/2031';
try {
    $_SESSION['active_unit_id'] = 1;
    unit_set_context($koneksi, 1);
    $login = lifecycle_request($base . '/login.php', ['username' => 'admin', 'password' => $password], $cookies);
    lifecycle_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Admin HTTP login failed.');

    $masterPage = lifecycle_request($base . '/master_spp.php?tahun=' . rawurlencode($firstYear), null, $cookies, $firstYear);
    lifecycle_assert($masterPage['status'] === 200, 'First SPP master page failed.');
    $rates = array_fill(1, 6, 250000);
    $save = lifecycle_request($base . '/master_spp.php', [
        'aksi' => 'simpan_tarif', 'csrf_token' => lifecycle_token($masterPage['body']),
        'tahun_ajaran' => $firstYear, 'jumlah' => $rates,
    ], $cookies, $firstYear);
    lifecycle_assert($save['status'] === 302, 'First SPP rates request failed.');

    $studentPage = lifecycle_request($base . '/siswa/daftar.php', null, $cookies, $firstYear);
    lifecycle_assert($studentPage['status'] === 200, 'Student registration page failed.');
    $register = lifecycle_request($base . '/siswa/daftar.php', [
        'aksi' => 'tambah', 'csrf_token' => lifecycle_token($studentPage['body']),
        'no_induk' => $nis, 'nama' => 'UJI SIKLUS LENGKAP',
        'master_kelas_id' => lifecycle_class($koneksi, 1),
        'advanced_enabled' => '1', 'spp_perbulan' => 250000,
        'pangkal' => 0, 'psb' => 0, 'pomg' => 0, 'daftar_ulang' => 0,
        'potong_pangkal' => 0, 'potong_du' => 0,
    ], $cookies, $firstYear);
    lifecycle_assert($register['status'] === 302, 'Student registration request failed.');
    $registered = $koneksi->query("SELECT KELAS,is_active FROM siswa WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc();
    lifecycle_assert($registered && $registered['KELAS'] === '1' && (int)$registered['is_active'] === 1, 'Class 1 student was not registered.');
    lifecycle_assert(count(lifecycle_report($koneksi, 'status', $nis, $firstYear, 'active')) === 1, 'First year placement/report missing.');

    $publish = lifecycle_request($base . '/master_spp.php', [
        'aksi' => 'terbitkan', 'csrf_token' => lifecycle_token($masterPage['body']),
        'tahun_ajaran' => $firstYear, 'selected_students' => [$nis],
    ], $cookies, $firstYear);
    lifecycle_assert($publish['status'] === 302, 'First SPP publication request failed.');
    $billCount = (int)$koneksi->query("SELECT COUNT(*) n FROM tagihan_spp WHERE no_induk='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc()['n'];
    lifecycle_assert($billCount === 12, 'First SPP publication did not create 12 months.');
    $paymentForm = lifecycle_request($base . '/pembayaran/form.php', null, $cookies, $firstYear);
    lifecycle_assert($paymentForm['status'] === 200
        && preg_match('/name="request_key" value="([a-f0-9]{32})"/', $paymentForm['body'], $paymentKey) === 1,
        'Payment form request key is missing.');
    $payment = lifecycle_request($base . '/pembayaran/proses.php', [
        'aksi' => 'input', 'payment_plan' => 'monthly', 'no_induk' => $nis,
        'csrf_token' => lifecycle_token($paymentForm['body']), 'request_key' => $paymentKey[1],
        'bulan_bayar' => '07', 'tahun_bayar' => '2030',
        'sistem_pembayaran' => 'Tunai', 'uang_spp' => 250000, 'uang_komite' => 0,
        'spp_action' => 'bayar',
    ], $cookies, $firstYear);
    lifecycle_assert($payment['status'] === 302, 'First SPP payment request failed.');
    $paid = (int)$koneksi->query("SELECT COUNT(*) n FROM bayar WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "' AND U_SPP=250000")->fetch_assoc()['n'];
    if ($paid !== 1) {
        $paymentPage = lifecycle_request($base . '/pembayaran/form.php', null, $cookies, $firstYear);
        throw new RuntimeException('First SPP payment was not recorded: ' . lifecycle_flash($paymentPage['body']));
    }
    $yearRows = lifecycle_report($koneksi, 'spp-tahunan', $nis, $firstYear, 'active');
    lifecycle_assert(count($yearRows) === 1 && $yearRows[0]['kelas'] === '1A' && (float)$yearRows[0]['total_bayar'] === 250000.0, 'First year report is incorrect after payment.');

    for ($level = 1; $level <= 5; $level++) {
        $sourceStart = 2029 + $level;
        $sourceYear = $sourceStart . '/' . ($sourceStart + 1);
        $targetYear = ($sourceStart + 1) . '/' . ($sourceStart + 2);
        $classPage = lifecycle_request($base . '/master_kelas.php', null, $cookies, $sourceYear);
        lifecycle_assert($classPage['status'] === 200, 'Promotion page failed for class ' . $level . '.');
        $promote = lifecycle_request($base . '/master_kelas.php', [
            'aksi' => 'naikkan_siswa', 'csrf_token' => lifecycle_token($classPage['body']),
            'no_induk' => $nis, 'target_tahun_ajaran' => $targetYear,
            'target_master_kelas_id' => lifecycle_class($koneksi, $level + 1),
        ], $cookies, $sourceYear);
        lifecycle_assert($promote['status'] === 302, 'Promotion request failed for class ' . $level . '.');
        $current = $koneksi->query("SELECT KELAS FROM siswa WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc();
        lifecycle_assert($current && (int)$current['KELAS'] === $level + 1, 'Promotion did not reach class ' . ($level + 1) . '.');
        $oldRows = lifecycle_report($koneksi, 'spp-tahunan', $nis, $sourceYear, 'active');
        lifecycle_assert(count($oldRows) === 1 && $oldRows[0]['kelas'] === $level . 'A', 'Source year report lost historical class ' . $level . 'A.');

        $masterPage = lifecycle_request($base . '/master_spp.php?tahun=' . rawurlencode($targetYear), null, $cookies, $targetYear);
        lifecycle_assert($masterPage['status'] === 200, 'SPP master page failed for ' . $targetYear . '.');
        $rate = 250000 + ($level * 10000);
        $save = lifecycle_request($base . '/master_spp.php', [
            'aksi' => 'simpan_tarif', 'csrf_token' => lifecycle_token($masterPage['body']),
            'tahun_ajaran' => $targetYear, 'jumlah' => array_fill(1, 6, $rate),
        ], $cookies, $targetYear);
        lifecycle_assert($save['status'] === 302, 'SPP rates failed for ' . $targetYear . '.');
        $publish = lifecycle_request($base . '/master_spp.php', [
            'aksi' => 'terbitkan', 'csrf_token' => lifecycle_token($masterPage['body']),
            'tahun_ajaran' => $targetYear, 'selected_students' => [$nis],
            'confirm_previous_debt' => '1',
        ], $cookies, $targetYear);
        lifecycle_assert($publish['status'] === 302, 'SPP publication failed for ' . $targetYear . '.');
        $targetRows = lifecycle_report($koneksi, 'spp-tahunan', $nis, $targetYear, 'active');
        lifecycle_assert(count($targetRows) === 1 && $targetRows[0]['kelas'] === ($level + 1) . 'A'
            && (float)$targetRows[0]['total_tagihan'] === (float)(12 * $rate),
            'Target year report is incorrect for ' . $targetYear . ': ' . json_encode($targetRows));
        $perItem = report_build($koneksi, 'per-item', report_filters($koneksi, [
            'q' => $nis, 'kategori' => 'spp', 'bulan_awal' => '06', 'tahun_awal' => $sourceStart + 1,
            'bulan_akhir' => '07', 'tahun_akhir' => $sourceStart + 1,
        ]))['rows'];
        lifecycle_assert(count($perItem) === 2 && $perItem[0]['tahun_ajaran'] === $sourceYear
            && $perItem[1]['tahun_ajaran'] === $targetYear, 'Cross-year Per Item rows failed.');
    }

    $lastYear = '2035/2036';
    $classPage = lifecycle_request($base . '/master_kelas.php', null, $cookies, $lastYear);
    $graduate = lifecycle_request($base . '/master_kelas.php', [
        'aksi' => 'luluskan_siswa', 'csrf_token' => lifecycle_token($classPage['body']),
        'no_induk' => $nis, 'target_tahun_ajaran' => '2036/2037',
    ], $cookies, $lastYear);
    lifecycle_assert($graduate['status'] === 302, 'Graduation request failed.');
    $graduateState = $koneksi->query("SELECT KELAS,is_active FROM siswa WHERE NO_INDUK='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc();
    lifecycle_assert($graduateState && (int)$graduateState['is_active'] === 0 && $graduateState['KELAS'] === '6', 'Student was not graduated.');
    lifecycle_assert(lifecycle_report($koneksi, 'status', $nis, $firstYear, 'active') === [], 'Graduate remains in active historical report.');
    lifecycle_assert(count(lifecycle_report($koneksi, 'status', $nis, $firstYear, 'archived')) === 1, 'Graduate missing from archived historical report.');
    $placementCount = (int)$koneksi->query("SELECT COUNT(*) n FROM siswa_tahun_ajaran WHERE no_induk='" . $koneksi->real_escape_string($nis) . "'")->fetch_assoc()['n'];
    lifecycle_assert($placementCount === 6, 'Full student cycle must have six year placements.');
    echo "OK: HTTP student registration, SPP publication/payment, five yearly promotions, historical reports, and graduation.\n";
    echo 'NIS ' . $nis . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
