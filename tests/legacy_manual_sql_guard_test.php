<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$php = PHP_BINARY;
$database = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_test_legacy_sql_[a-z0-9_]+$/D', $database)) {
    fwrite(STDERR, "FAILED: set SPP_DB_NAME=db_spp_test_legacy_sql_* and SPP_TEST_ALLOW_MUTATION=1.\n");
    exit(1);
}

function manual_sql_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function manual_sql_process(array $command, array $env): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        dirname(__DIR__), $env);
    if (!is_resource($process)) throw new RuntimeException('Tidak dapat menjalankan subprocess PHP.');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

$runner = $root . '/sql/run_legacy_sql.php';
[$listCode, $list] = manual_sql_process([$php, $runner, '--list'], getenv());
manual_sql_assert($listCode === 0, 'Inventaris SQL gagal dibaca.');
$entries = [];
foreach (explode("\n", trim($list)) as $line) {
    [$name, $type] = explode("\t", $line, 2);
    $entries[$name] = $type;
}
$sqlFiles = array_map('basename', glob($root . '/sql/*.sql'));
$sqlFiles = array_values(array_diff($sqlFiles, ['schema.sql', 'verify_schema.sql']));
sort($sqlFiles);
$names = array_keys($entries);
sort($names);
manual_sql_assert($names === $sqlFiles, 'Inventaris runner tidak mencakup semua SQL legacy.');
foreach ($names as $name) {
    $stub = file_get_contents($root . '/sql/' . $name);
    manual_sql_assert($stub !== false && substr_count($stub, 'SIGNAL SQLSTATE') === 1
        && !preg_match('/^\s*(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE|TRUNCATE)\b/im', $stub),
        "$name tidak gagal tertutup saat diimpor langsung.");
    $definition = file_get_contents($root . '/sql/definitions/' . $name);
    manual_sql_assert($definition !== false
        && preg_match('/^-- Encoded internal SQL definition; run only through sql\/run_legacy_sql\.php\.\r?\nB64:[A-Za-z0-9+\/=]+\r?\n?$/D', $definition) === 1
        && substr_count($definition, ';') === 1
        && !preg_match('/^\s*(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE|TRUNCATE)\b/im', $definition),
        "$name masih dapat diimpor sebagai SQL mentah.");
}

$deniedEnv = array_merge(getenv(), [
    'SPP_DB_NAME' => 'db_spp',
    'SPP_DB_HOST' => 'invalid.invalid',
    'SPP_ALLOW_MAIN_MIGRATION' => '0',
]);
[$deniedCode, $denied] = manual_sql_process([$php, $runner,
    '--script=add_transaction_authorization.sql', '--apply',
    '--confirm-script=add_transaction_authorization.sql'], $deniedEnv);
manual_sql_assert($deniedCode !== 0 && str_contains($denied, 'DDL hanya boleh pada clone'),
    'Target utama harus ditolak sebelum koneksi.');
[$historicalCode, $historical] = manual_sql_process([$php, $runner,
    '--script=remove_payment_linked_savings.sql', '--apply',
    '--confirm-script=remove_payment_linked_savings.sql'], $deniedEnv);
manual_sql_assert($historicalCode !== 0 && str_contains($historical, 'hanya boleh pada clone disposable'),
    'Script historis harus menolak database utama sebelum koneksi.');
[$retiredCode, $retired] = manual_sql_process([$php, $runner,
    '--script=allow_spp_installments.sql', '--apply',
    '--confirm-script=allow_spp_installments.sql'], getenv());
manual_sql_assert($retiredCode !== 0 && str_contains($retired, 'sudah usang'),
    'Migrasi superseded harus ditolak.');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$pass = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$port = (int)(getenv('SPP_DB_PORT') ?: '3306');
$db = new mysqli($host, $user, $pass, '', $port);
$created = false;
try {
    $db->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db->select_db($database);
    $db->query('CREATE TABLE admin (id INT PRIMARY KEY)');
    $db->query('CREATE TABLE bayar (id INT PRIMARY KEY, created_at TIMESTAMP NULL)');
    $db->query('CREATE TABLE siswa (id INT PRIMARY KEY, KEGIATAN DECIMAL(15,2) NOT NULL DEFAULT 0)');
    $db->query("INSERT INTO bayar (id, created_at) VALUES (1, '2020-01-02 03:04:05')");
    $stub = file_get_contents($root . '/sql/add_transaction_authorization.sql');
    try {
        $db->query($stub);
        throw new RuntimeException('Direct import semestinya gagal.');
    } catch (mysqli_sql_exception $error) {
        manual_sql_assert($error->getCode() === 1644, 'Direct import gagal dengan alasan tak terduga.');
    }

    $applyEnv = array_merge(getenv(), ['SPP_DB_NAME' => $database]);
    $command = [$php, $runner, '--script=add_transaction_authorization.sql', '--apply',
        '--confirm-script=add_transaction_authorization.sql'];
    for ($run = 0; $run < 2; $run++) {
        [$code, $output] = manual_sql_process($command, $applyEnv);
        manual_sql_assert($code === 0, "Runner gagal pada clone, run $run: $output");
    }
    $foreignKeys = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_otorisasi'")->num_rows;
    manual_sql_assert($foreignKeys === 3, 'Migrasi clone tidak menambah tiga foreign key yang diharapkan.');

    $procedureCommand = [$php, $runner, '--script=add_payment_updated_at.sql', '--apply',
        '--confirm-script=add_payment_updated_at.sql'];
    for ($run = 0; $run < 2; $run++) {
        [$code, $output] = manual_sql_process($procedureCommand, $applyEnv);
        manual_sql_assert($code === 0, "Migrasi procedure gagal pada clone, run $run: $output");
    }
    $timestamps = $db->query('SELECT created_at, updated_at FROM bayar WHERE id=1')->fetch_assoc();
    manual_sql_assert($timestamps['created_at'] === $timestamps['updated_at'],
        'Procedure migrasi gagal mengisi timestamp dari pembayaran lama.');
    if (stripos($db->server_info, 'MariaDB') === false) {
        [$code, $output] = manual_sql_process([$php, $runner,
            '--script=add_student_optional_fees.sql', '--apply',
            '--confirm-script=add_student_optional_fees.sql'], $applyEnv);
        manual_sql_assert($code !== 0 && str_contains($output, 'tidak ada statement dijalankan'),
            'Dialek MariaDB harus ditolak sebelum statement pertama pada MySQL.');
        $unexpectedColumns = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
            AND COLUMN_NAME IN ('MAKAN','SORGA','INFAQ')")->num_rows;
        manual_sql_assert($unexpectedColumns === 0, 'Preflight dialek mengubah tabel siswa.');
    }
    $financialCommand = [$php, $root . '/sql/add_financial_request_guard.php', '--apply'];
    for ($run = 0; $run < 2; $run++) {
        [$code, $output] = manual_sql_process($financialCommand, $applyEnv);
        manual_sql_assert($code === 0, "Wrapper keuangan_request gagal pada clone, run $run: $output");
    }
    $financialTable = $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keuangan_request'")->fetch_assoc();
    manual_sql_assert((int)$financialTable['n'] === 1, 'Wrapper keuangan_request tidak membuat tabel.');
    echo "OK: 29 direct import ditolak, gate utama/retired ditolak, migrasi DDL/procedure/keuangan_request clone idempoten.\n";
} finally {
    if ($created) {
        $db->query("DROP DATABASE `$database`");
    }
    $db->close();
}
