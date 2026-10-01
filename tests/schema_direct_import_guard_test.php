<?php
/** A direct schema import must stop before touching an existing database. */
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
$db = new mysqli($host, $user, $password);
$db->set_charset('utf8mb4');
$created = false;

try {
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db->select_db($name);
    $db->query('CREATE TABLE audit_fixture (id INT PRIMARY KEY) ENGINE=InnoDB');
    $db->query('INSERT INTO audit_fixture(id) VALUES(1)');

    $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
    if ($schema === false) throw new RuntimeException('schema.sql tidak tersedia.');
    $schema = str_replace(
        ['CREATE DATABASE IF NOT EXISTS `db_spp`', 'USE `db_spp`'],
        ["CREATE DATABASE IF NOT EXISTS `{$name}`", "USE `{$name}`"],
        $schema,
        $replacements
    );
    if ($replacements !== 2) throw new RuntimeException('Kontrak target schema.sql berubah.');

    $rejected = false;
    try {
        $db->multi_query($schema);
        do {
            $result = $db->store_result();
            if ($result instanceof mysqli_result) $result->free();
        } while ($db->more_results() && $db->next_result());
    } catch (mysqli_sql_exception $error) {
        $rejected = $error->getCode() === 3141;
    }
    if (!$rejected) throw new RuntimeException('Impor langsung pada database berisi data tidak ditolak oleh guard.');

    $tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetch_all(MYSQLI_NUM);
    if ($tables !== [['audit_fixture']]
        || (int)$db->query('SELECT COUNT(*) FROM audit_fixture')->fetch_row()[0] !== 1) {
        throw new RuntimeException('Impor yang ditolak masih mengubah tabel/fixture clone.');
    }
    echo "PASS: impor schema pada database nonkosong ditolak sebelum DDL.\n";
} finally {
    // Close any unfinished multi_query result before dropping the clone.
    $db->close();
    if ($created) {
        if (!preg_match('/^db_spp_audit_schema_guard_[a-f0-9]{12}$/D', $name)) {
            throw new RuntimeException('Nama clone tidak cocok; penghapusan dibatalkan.');
        }
        $cleanup = new mysqli($host, $user, $password);
        $exists = (int)$cleanup->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$name}'")->fetch_row()[0];
        if ($exists !== 1) throw new RuntimeException('Clone tidak ditemukan; penghapusan dibatalkan.');
        $cleanup->query("DROP DATABASE `{$name}`");
        $cleanup->close();
    }
}
