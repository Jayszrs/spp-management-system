<?php
/** Load an encoded legacy SQL definition after its caller has checked the target. */
function legacy_sql_definition(string $name): string {
    if (!preg_match('/^[a-z0-9_]+\.sql$/D', $name)) {
        throw new RuntimeException('Nama definisi SQL tidak valid.');
    }
    $contents = file_get_contents(__DIR__ . '/definitions/' . $name);
    if ($contents === false) {
        throw new RuntimeException("Definisi $name tidak tersedia.");
    }
    $contents = str_replace("\r\n", "\n", $contents);
    $prefix = '-- Encoded internal SQL definition; run only through sql/run_legacy_sql.php.' . "\nB64:";
    if (!str_starts_with($contents, $prefix)) {
        throw new RuntimeException("Definisi $name tidak memiliki format internal yang aman.");
    }
    $encoded = trim(substr($contents, strlen($prefix)));
    if (!preg_match('/^[A-Za-z0-9+\/=]+$/D', $encoded)) {
        throw new RuntimeException("Definisi $name mengandung data di luar payload base64.");
    }
    $sql = base64_decode($encoded, true);
    if ($sql === false || $sql === '' || base64_encode($sql) !== $encoded) {
        throw new RuntimeException("Definisi $name gagal didekode secara utuh.");
    }
    return $sql;
}
