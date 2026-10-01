<?php
/** A student identifier with an academic placement must not be renamed and orphan its history. */
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', (string)getenv('SPP_DB_NAME'))
    || !preg_match('#^http://127\.0\.0\.1:\d+/?$#', (string)getenv('SPP_HTTP_BASE'))) {
    fwrite(STDERR, "Tes NIS memerlukan database clone dan server HTTP lokal dengan flag mutasi.\n");
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';

function student_nis_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function student_nis_http(string $url, ?array $post, string $sessionId): array {
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => 'Cookie: PHPSESSID=' . $sessionId . "\r\n"
            . ($post === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n"),
        'content' => $post === null ? '' : http_build_query($post),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    student_nis_assert($body !== false, 'Permintaan HTTP gagal.');
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) $status = (int)$match[1];
    }
    return [$status, $body];
}

$base = rtrim((string)getenv('SPP_HTTP_BASE'), '/');
$expectUnsafe = in_array('--expect-unsafe', $argv, true);
$originalNis = (string)random_int(9700000000, 9799999999);
$changedNis = (string)random_int(9700000000, 9799999999);
student_nis_assert($originalNis !== $changedNis, 'NIS fixture bertabrakan.');
$sessionId = bin2hex(random_bytes(16));
$studentId = 0;
$failure = null;
try {
    $stmt = $koneksi->prepare('SELECT COUNT(*) FROM siswa WHERE NO_INDUK IN (?,?)');
    $stmt->bind_param('ss', $originalNis, $changedNis); $stmt->execute();
    $existingNisCount = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    student_nis_assert($existingNisCount === 0, 'NIS acak tes sudah dipakai; fixture tidak dibuat.');
    $admin = $koneksi->query("SELECT id FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1")->fetch_assoc();
    $year = $koneksi->query("SELECT id FROM tahun_ajaran WHERE label='2026/2027' LIMIT 1")->fetch_assoc();
    student_nis_assert($admin && $class && $year, 'Akun, kelas, atau tahun fixture tidak tersedia.');
    $classId = (int)$class['id'];
    $yearId = (int)$year['id'];
    $name = 'UJI NIS RIWAYAT';
    $level = '1';
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,?,?,?,1)');
    $stmt->bind_param('sssi', $originalNis, $name, $level, $classId);
    $stmt->execute(); $studentId = (int)$koneksi->insert_id; $stmt->close();
    $snapshot = '1A';
    $stmt = $koneksi->prepare('INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,\'aktif\')');
    $stmt->bind_param('issis', $yearId, $originalNis, $level, $classId, $snapshot);
    $stmt->execute(); $stmt->close();

    session_id($sessionId); session_start();
    $_SESSION = ['admin_id' => (int)$admin['id'], 'admin_role' => 'admin', 'active_unit_id' => 1];
    session_write_close();
    [$status, $page] = student_nis_http($base . '/siswa/daftar.php?edit=' . $studentId, null, $sessionId);
    student_nis_assert($status === 200, 'Form edit Data Siswa tidak terbuka.');
    student_nis_assert(str_contains($page, 'value="' . $originalNis . '"')
        && str_contains($page, 'UJI NIS RIWAYAT'),
        'Server HTTP tidak menunjukkan fixture clone; permintaan POST dibatalkan.');
    student_nis_assert(preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $match) === 1, 'Token CSRF tidak ditemukan.');
    if (!$expectUnsafe) {
        student_nis_assert(preg_match('/id="nis-baru"[^>]*\breadonly\b/', $page) === 1,
            'Form edit masih menawarkan perubahan langsung nomor induk.');
    }
    [$status] = student_nis_http($base . '/siswa/daftar.php', [
        'aksi' => 'update', 'id' => $studentId, 'csrf_token' => $match[1],
        'no_induk' => $changedNis, 'nama' => $name, 'master_kelas_id' => $classId,
    ], $sessionId);
    student_nis_assert($status === 302, 'Permintaan edit tidak selesai dengan redirect.');

    $stmt = $koneksi->prepare('SELECT NO_INDUK FROM siswa WHERE id=?');
    $stmt->bind_param('i', $studentId); $stmt->execute();
    $actualNis = (string)($stmt->get_result()->fetch_assoc()['NO_INDUK'] ?? ''); $stmt->close();
    $stmt = $koneksi->prepare('SELECT COUNT(*) AS n FROM siswa_tahun_ajaran WHERE no_induk=?');
    $stmt->bind_param('s', $originalNis); $stmt->execute();
    $oldPlacementCount = (int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
    $stmt = $koneksi->prepare('SELECT COUNT(*) AS n FROM siswa_tahun_ajaran WHERE no_induk=?');
    $stmt->bind_param('s', $changedNis); $stmt->execute();
    $newPlacementCount = (int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();

    if ($expectUnsafe) {
        student_nis_assert($actualNis === $changedNis && $oldPlacementCount === 1,
            'Reproduksi lama tidak memisahkan siswa dari penempatan NIS asal.');
    } else {
        student_nis_assert($actualNis === $originalNis && $oldPlacementCount === 1 && $newPlacementCount === 0,
            'Perubahan NIS yang mempunyai riwayat belum ditolak tanpa mengubah data.');
        [$status, $feedback] = student_nis_http($base . '/siswa/daftar.php', null, $sessionId);
        student_nis_assert($status === 200 && str_contains($feedback, 'Nomor induk tidak dapat diubah'),
            'Alasan penolakan NIS tidak tampak kepada operator.');
        [$status] = student_nis_http($base . '/siswa/daftar.php', [
            'aksi' => 'update', 'id' => $studentId, 'csrf_token' => $match[1],
            'no_induk' => $originalNis, 'nama' => 'UJI NIS RIWAYAT DIPERBARUI',
            'master_kelas_id' => $classId,
        ], $sessionId);
        student_nis_assert($status === 302, 'Edit nama dengan NIS tetap tidak selesai.');
        $stmt = $koneksi->prepare('SELECT NAMA FROM siswa WHERE id=? AND NO_INDUK=?');
        $stmt->bind_param('is', $studentId, $originalNis); $stmt->execute();
        $updatedName = (string)($stmt->get_result()->fetch_assoc()['NAMA'] ?? ''); $stmt->close();
        student_nis_assert($updatedName === 'UJI NIS RIWAYAT DIPERBARUI',
            'Pengaman NIS menghalangi edit nama siswa yang sah.');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($studentId > 0) {
        foreach ([$originalNis, $changedNis] as $nis) {
            foreach (['tagihan_komite', 'tagihan_daftar_ulang', 'siswa_tahun_ajaran', 'siswa_audit_log'] as $table) {
                $column = $table === 'siswa_audit_log' ? 'no_induk_snapshot' : 'no_induk';
                $stmt = $koneksi->prepare("DELETE FROM {$table} WHERE {$column}=?");
                $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
            }
        }
        $stmt = $koneksi->prepare('DELETE FROM siswa WHERE id=?');
        $stmt->bind_param('i', $studentId); $stmt->execute(); $stmt->close();
    }
    session_id($sessionId); session_start(); session_destroy(); session_write_close();
}

if ($failure) { fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL); exit(1); }
echo $expectUnsafe
    ? "REPRODUCED: edit NIS memisahkan siswa dari penempatan historis.\n"
    : "PASS: edit NIS dengan penempatan ditolak tanpa mengubah histori.\n";
