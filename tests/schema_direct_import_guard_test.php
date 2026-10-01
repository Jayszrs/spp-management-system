<?php
/** Even mysql --force must not be able to run the canonical schema directly. */
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_(?:audit|test)_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "FAILED: gunakan CLI, target clone, dan SPP_TEST_ALLOW_MUTATION=1.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$name = 'db_spp_audit_schema_guard_' . bin2hex(random_bytes(6));
$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$password = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$port = filter_var(getenv('SPP_DB_PORT') ?: '3306', FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($port === false) throw new RuntimeException('Port database latihan tidak valid.');
$db = new mysqli($host, $user, $password, '', $port);
$db->set_charset('utf8mb4');
$created = false;

try {
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db->select_db($name);
    $db->query('CREATE TABLE audit_fixture (id INT PRIMARY KEY) ENGINE=InnoDB');
    $db->query('INSERT INTO audit_fixture(id) VALUES(1)');

    $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
    if ($schema === false || !preg_match(
        "/\\A--[^\\r\\n]*\\r?\\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='[^']+';\\r?\\n?\\z/D",
        $schema
    )) throw new RuntimeException('schema.sql harus hanya berisi penolakan impor langsung.');

    require_once __DIR__ . '/../sql/schema_source.php';
    $source = spp_schema_source();
    if (substr_count($source, 'CREATE TABLE') < 30) {
        throw new RuntimeException('Payload instalasi tidak memuat skema lengkap.');
    }

    $rejected = false;
    try {
        $db->multi_query($schema);
        do {
            $result = $db->store_result();
            if ($result instanceof mysqli_result) $result->free();
        } while ($db->more_results() && $db->next_result());
    } catch (mysqli_sql_exception $error) {
        $rejected = $error->getSqlState() === '45000';
    }
    if (!$rejected) throw new RuntimeException('Impor langsung tidak ditolak oleh guard.');

    $payload = file_get_contents(__DIR__ . '/../sql/schema.payload');
    if ($payload === false) throw new RuntimeException('Payload schema tidak tersedia.');
    $mysqlBin = getenv('SPP_MYSQL_BIN') ?: 'mysql';
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['MYSQL_PWD'] = $password;
    foreach (['schema.sql' => $schema, 'schema.payload' => $payload] as $file => $input) {
        $process = proc_open(
            [$mysqlBin, '--force', '--host=' . $host, '--port=' . $port, '--user=' . $user, $name],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) throw new RuntimeException('Klien mysql untuk uji --force tidak tersedia.');
        fwrite($pipes[0], $input); fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        proc_close($process);
        if ($file === 'schema.sql' && !str_contains($stdout . $stderr, 'Gunakan bootstrap_production.php')) {
            throw new RuntimeException('mysql --force tidak membaca penolakan schema.sql.');
        }
    }

    $tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetch_all(MYSQLI_NUM);
    if ($tables !== [['audit_fixture']]
        || (int)$db->query('SELECT COUNT(*) FROM audit_fixture')->fetch_row()[0] !== 1) {
        throw new RuntimeException('Impor biasa atau --force terhadap ' . $file . ' masih mengubah tabel/fixture clone.');
    }
    echo "PASS: impor schema biasa dan --force tidak mengeksekusi DDL/DML.\n";
} finally {
    // Close any unfinished multi_query result before dropping the clone.
    $db->close();
    if ($created) {
        if (!preg_match('/^db_spp_audit_schema_guard_[a-f0-9]{12}$/D', $name)) {
            throw new RuntimeException('Nama clone tidak cocok; penghapusan dibatalkan.');
        }
        $cleanup = new mysqli($host, $user, $password, '', $port);
        $exists = (int)$cleanup->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$name}'")->fetch_row()[0];
        if ($exists !== 1) throw new RuntimeException('Clone tidak ditemukan; penghapusan dibatalkan.');
        $cleanup->query("DROP DATABASE `{$name}`");
        $cleanup->close();
    }
}
