<?php
/** Decode the canonical fresh-install schema without exposing executable SQL to mysql import. */
function spp_schema_source(): string {
    $payload = file_get_contents(__DIR__ . '/schema.payload');
    if ($payload === false || !preg_match('/\AB64:([A-Za-z0-9+\/]+={0,2})\r?\n?\z/D', $payload, $match)) {
        throw new RuntimeException('Payload schema instalasi tidak valid.');
    }
    $schema = base64_decode($match[1], true);
    if ($schema === false || substr_count($schema, 'CREATE DATABASE IF NOT EXISTS `db_spp`') !== 1
        || substr_count($schema, 'USE `db_spp`') !== 1
        || substr_count($schema, 'CREATE TABLE') < 30) {
        throw new RuntimeException('Isi schema instalasi tidak sesuai kontrak.');
    }
    return $schema;
}
