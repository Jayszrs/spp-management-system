<?php
/** Exercise the supported installer after raw schema.sql imports were disabled. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "FAILED: gunakan CLI, flag tes, dan target audit disposable.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$name = 'db_spp_audit_bootstrap_' . bin2hex(random_bytes(6));
$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$password = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$port = filter_var(getenv('SPP_DB_PORT') ?: '3306', FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($port === false) throw new RuntimeException('Port database latihan tidak valid.');
$db = new mysqli($host, $user, $password, '', $port);
$db->set_charset('utf8mb4');
$createdNames = [];

function bootstrap_test_run(array $environment): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/../sql/bootstrap_production.php', '--execute'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), $environment,
        ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Installer tidak dapat dijalankan.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $output . $error];
}

try {
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdNames[] = $name;
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['SPP_DB_NAME'] = $name;
    $environment['SPP_BOOTSTRAP_TARGET'] = $name;
    $environment['SPP_BOOTSTRAP_ADMIN_USER'] = 'audit_admin';
    $environment['SPP_BOOTSTRAP_ADMIN_PASSWORD'] = bin2hex(random_bytes(16));
    [$status, $output] = bootstrap_test_run($environment);
    if ($status !== 0 || !str_contains($output, 'Bootstrap selesai')) {
        throw new RuntimeException('Instalasi database kosong gagal: ' . trim($output));
    }

    $db->select_db($name);
    $tables = $db->query('SHOW TABLES')->num_rows;
    $admin = (int)$db->query('SELECT COUNT(*) FROM admin')->fetch_row()[0];
    $students = (int)$db->query('SELECT COUNT(*) FROM siswa')->fetch_row()[0];
    $payments = (int)$db->query('SELECT COUNT(*) FROM bayar')->fetch_row()[0];
    if ($tables < 30 || $admin !== 1 || $students !== 0 || $payments !== 0) {
        throw new RuntimeException('Hasil bootstrap tidak sesuai instalasi kosong.');
    }
    [$repeatStatus, $repeatOutput] = bootstrap_test_run($environment);
    if ($repeatStatus === 0 || !str_contains($repeatOutput, 'tidak kosong')
        || (int)$db->query('SELECT COUNT(*) FROM admin')->fetch_row()[0] !== 1) {
        throw new RuntimeException('Installer ulang tidak ditolak secara utuh.');
    }

    $routineName = 'db_spp_audit_bootstrap_routine_' . bin2hex(random_bytes(6));
    $db->query("CREATE DATABASE `{$routineName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdNames[] = $routineName;
    $db->select_db($routineName);
    $db->query('CREATE PROCEDURE audit_probe() SELECT 1');
    $environment['SPP_DB_NAME'] = $routineName;
    $environment['SPP_BOOTSTRAP_TARGET'] = $routineName;
    [$routineStatus, $routineOutput] = bootstrap_test_run($environment);
    if ($routineStatus === 0 || !str_contains($routineOutput, 'tidak kosong')
        || $db->query('SHOW TABLES')->num_rows !== 0) {
        throw new RuntimeException('Installer menerima database yang sudah berisi routine.');
    }
    echo "PASS: installer resmi membangun database kosong dan menolak tabel/routine yang sudah ada.\n";
} finally {
    $db->close();
    foreach ($createdNames as $createdName) {
        if (!preg_match('/^db_spp_audit_bootstrap_(?:routine_)?[a-f0-9]{12}$/D', $createdName)) {
            throw new RuntimeException('Nama clone tidak sesuai; penghapusan dibatalkan.');
        }
        $cleanup = new mysqli($host, $user, $password, '', $port);
        $exists = (int)$cleanup->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$createdName}'")->fetch_row()[0];
        if ($exists !== 1) throw new RuntimeException('Clone tidak ditemukan; penghapusan dibatalkan.');
        $cleanup->query("DROP DATABASE `{$createdName}`");
        $cleanup->close();
    }
}
