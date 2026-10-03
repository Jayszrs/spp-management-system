<?php
/** Exported student names beginning with formula syntax must remain text. */
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', (string)getenv('SPP_DB_NAME'))
    || !preg_match('#^http://127\.0\.0\.1:\d+/?$#', (string)getenv('SPP_HTTP_BASE'))) {
    fwrite(STDERR, "Tes Excel hanya untuk server lokal dan database clone dengan flag mutasi.\n");
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';

$expectUnsafe = in_array('--expect-unsafe', $argv, true);
$nis = (string)random_int(9800000000, 9899999999);
$name = '=1+1';
$sessionId = bin2hex(random_bytes(16));
$fixtureCreated = false;
$failure = null;
try {
    $account = $koneksi->query("SELECT id FROM admin WHERE role='admin' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
    if (!$account) throw new RuntimeException('Akun admin clone tidak tersedia.');
    $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active) VALUES(?,?,'1',1)");
    $stmt->bind_param('ss', $nis, $name); $stmt->execute(); $stmt->close();
    $fixtureCreated = true;

    session_id($sessionId); session_start();
    $_SESSION = ['admin_id'=>(int)$account['id'], 'admin_role'=>'admin',
        'active_unit_id'=>1, 'admin_nama'=>'Uji Ekspor'];
    session_write_close();
    $url = rtrim((string)getenv('SPP_HTTP_BASE'), '/') . '/siswa/export_excel.php?'
        . http_build_query(['q'=>$nis, 'status'=>'all', 'download'=>'1']);
    $context = stream_context_create(['http'=>[
        'method'=>'GET', 'ignore_errors'=>true, 'follow_location'=>0, 'timeout'=>20,
        'header'=>'Cookie: '.session_name().'='.$sessionId."\r\n",
    ]]);
    $body = file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    if ($body === false || !str_contains($headers[0] ?? '', '200')) {
        throw new RuntimeException('Unduhan Excel Data Siswa tidak tersedia.');
    }
    if ($expectUnsafe) {
        if (!str_contains($body, '<td>=1+1</td>')) {
            throw new RuntimeException('Reproduksi sebelum perbaikan tidak menemukan formula pada sel nama.');
        }
    } elseif (!str_contains($body, '<td>&#039;=1+1</td>') || str_contains($body, '<td>=1+1</td>')) {
        throw new RuntimeException('Sel nama di unduhan Excel masih dapat dibaca sebagai formula.');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($fixtureCreated) {
        $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
        $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
    }
    session_id($sessionId); session_start(); session_destroy(); session_write_close();
}

if ($failure) { fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL); exit(1); }
echo $expectUnsafe
    ? "REPRODUCED: nama berawalan '=' masuk ke sel Excel sebagai formula.\n"
    : "PASS: nama berawalan '=' diekspor sebagai teks.\n";
