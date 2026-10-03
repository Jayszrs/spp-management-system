<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$database = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_test_demo_reset_[a-z0-9_]+$/D', $database)) {
    fwrite(STDERR, "FAILED: jalankan tes seed hanya lewat CLI pada clone baru db_spp_test_demo_reset_* dengan SPP_TEST_ALLOW_MUTATION=1.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function demo_seed_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function demo_seed_run(mysqli $db, string $sql): void {
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do {
        if ($result = $db->store_result()) $result->free();
    } while ($db->more_results() && $db->next_result());
    if ($db->error !== '') throw new RuntimeException($db->error);
}

$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$pass = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$port = filter_var(getenv('SPP_DB_PORT') ?: '3306', FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($port === false) throw new RuntimeException('Port database latihan tidak valid.');
$db = new mysqli($host, $user, $pass, '', $port);
$db->set_charset('utf8mb4');
$createdDatabase = false;

try {
    // CREATE without IF NOT EXISTS refuses to overwrite a previous or unrelated clone.
    $db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdDatabase = true;
    require_once __DIR__ . '/../sql/schema_source.php';
    $schema = spp_schema_source();
    $schema = str_replace(
        ['CREATE DATABASE IF NOT EXISTS `db_spp`', 'USE `db_spp`'],
        ["CREATE DATABASE IF NOT EXISTS `{$database}`", "USE `{$database}`"],
        $schema,
        $replacementCount
    );
    demo_seed_assert($replacementCount === 2, 'Kontrak nama database schema.sql berubah.');
    demo_seed_run($db, $schema);
    $db->select_db($database);

    demo_seed_runner($database, 'reset_demo_students_and_finance.sql');

    demo_seed_runner($database, 'seed_students_psb.sql');
    demo_seed_runner($database, 'seed_students_psb.sql');

    $counts = $db->query("SELECT
        (SELECT COUNT(*) FROM siswa WHERE is_active=1) siswa,
        (SELECT COUNT(*) FROM siswa WHERE is_active=1 AND KELAS IN ('1','2','3','4','5','6')) reguler,
        (SELECT COUNT(*) FROM siswa WHERE is_active=1 AND KELAS='PSB') psb,
        (SELECT COUNT(*) FROM siswa WHERE is_active=1 AND PANGKAL-potong_pangkal>0) pangkal,
        (SELECT COUNT(*) FROM tagihan_spp) spp,
        (SELECT COUNT(*) FROM tagihan_komite) komite,
        (SELECT COUNT(*) FROM tagihan_daftar_ulang) daftar_ulang,
        (SELECT COUNT(*) FROM bayar) bayar,
        (SELECT COUNT(*) FROM transaksi_m)+(SELECT COUNT(*) FROM transaksi_k) mutasi,
        (SELECT COUNT(*) FROM tagihan_spp WHERE no_induk LIKE 'PSB%') spp_psb,
        (SELECT COUNT(*) FROM tagihan_komite WHERE no_induk LIKE 'PSB%') komite_psb")->fetch_assoc();

    demo_seed_assert((int)$counts['siswa'] === 150, 'Jumlah siswa baseline harus 150.');
    demo_seed_assert((int)$counts['reguler'] === 144 && (int)$counts['psb'] === 6, 'Komposisi siswa reguler/PSB salah.');
    demo_seed_assert((int)$counts['pangkal'] === 30, 'Tagihan Pangkal baseline harus hanya 30 siswa.');
    demo_seed_assert((int)$counts['spp'] === 1728 && (int)$counts['komite'] === 1728 && (int)$counts['daftar_ulang'] === 144, 'Jumlah tagihan baseline salah atau terduplikasi.');
    demo_seed_assert((int)$counts['bayar'] === 0 && (int)$counts['mutasi'] === 0, 'Baseline tidak boleh memiliki transaksi.');
    demo_seed_assert((int)$counts['spp_psb'] === 0 && (int)$counts['komite_psb'] === 0, 'Siswa PSB tidak boleh memiliki tagihan bulanan.');

    echo "OK: reset demo dan seeder baseline 150 siswa tervalidasi.\n";
} finally {
    if ($createdDatabase) $db->query("DROP DATABASE `{$database}`");
    $db->close();
}

function demo_seed_runner(string $database, string $script): void {
    $command = [PHP_BINARY, __DIR__ . '/../sql/run_legacy_sql.php',
        '--script=' . $script, '--apply', '--confirm-script=' . $script];
    $environment = array_merge(getenv(), ['SPP_DB_NAME' => $database]);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        dirname(__DIR__), $environment);
    if (!is_resource($process)) throw new RuntimeException('Runner SQL legacy tidak dapat dimulai.');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException("Runner $script gagal: $output");
}
