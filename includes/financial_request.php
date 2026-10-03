<?php

/** Satu kunci formulir hanya boleh menghasilkan satu mutasi keuangan. */
class FinancialRequestReplayException extends RuntimeException {}

function financial_request_assert_ready(mysqli $db): void {
    // Jangan cache lintas koneksi: tes maupun pekerja CLI dapat mengganti database
    // dalam satu proses, dan keberadaan tabel saja tidak menjamin kunci unik/rollback.
    $table = $db->query("SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='keuangan_request'")->fetch_assoc();
    if (!$table) {
        throw new RuntimeException('Pengaman transaksi belum tersedia. Hubungi administrator sistem.');
    }
    if ($table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
        throw new RuntimeException('Skema pengaman transaksi tidak sesuai. Hubungi administrator sistem.');
    }

    $columns = [];
    $result = $db->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='keuangan_request'");
    while ($row = $result->fetch_assoc()) $columns[$row['COLUMN_NAME']] = $row;
    $expected = [
        'request_key' => ['char(32)', 'NO'],
        'unit_id' => ['tinyint unsigned', 'NO'],
        'aksi' => ["enum('pembayaran','tabungan_masuk','tabungan_keluar')", 'NO'],
        'operator_id' => ['int', 'NO'],
        'referensi_id' => ['bigint', 'YES'],
        'dibuat_pada' => ['timestamp', 'NO'],
    ];
    $valid = count($columns) === count($expected);
    foreach ($expected as $name => [$type, $nullable]) {
        $column = $columns[$name] ?? null;
        $valid = $valid && $column !== null && $column['COLUMN_TYPE'] === $type
            && $column['IS_NULLABLE'] === $nullable;
    }
    $valid = $valid
        && $columns['request_key']['CHARACTER_SET_NAME'] === 'ascii'
        && $columns['request_key']['COLLATION_NAME'] === 'ascii_bin'
        && in_array(strtoupper((string)$columns['dibuat_pada']['COLUMN_DEFAULT']),
            ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()'], true);

    $indexes = [];
    $unexpectedUniqueIndex = false;
    $result = $db->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='keuangan_request' ORDER BY INDEX_NAME,SEQ_IN_INDEX");
    while ($row = $result->fetch_assoc()) {
        if ((int)$row['NON_UNIQUE'] === 0 && $row['INDEX_NAME'] !== 'PRIMARY') {
            $unexpectedUniqueIndex = true;
        }
        $indexes[$row['INDEX_NAME']][] = [
            (int)$row['NON_UNIQUE'], $row['COLUMN_NAME'], $row['SUB_PART'], $row['INDEX_TYPE'],
        ];
    }
    $valid = $valid
        && !$unexpectedUniqueIndex
        && ($indexes['PRIMARY'] ?? null) === [[0, 'request_key', null, 'BTREE']]
        && ($indexes['idx_keuangan_request_unit_waktu'] ?? null) === [[1, 'unit_id', null, 'BTREE'], [1, 'dibuat_pada', null, 'BTREE']]
        && ($indexes['idx_keuangan_request_operator'] ?? null) === [[1, 'operator_id', null, 'BTREE'], [1, 'dibuat_pada', null, 'BTREE']];
    if (!$valid) {
        throw new RuntimeException('Skema pengaman transaksi tidak sesuai. Hubungi administrator sistem.');
    }
}

/** Panggil setelah begin_transaction; INSERT unik ikut di-rollback bila transaksi gagal. */
function financial_request_reserve(mysqli $db, string $key, string $action, int $actorId): void {
    if (!preg_match('/^[a-f0-9]{32}$/D', $key)) {
        throw new RuntimeException('Formulir transaksi kedaluwarsa. Muat ulang halaman dan coba lagi.');
    }
    if (!in_array($action, ['pembayaran', 'tabungan_masuk', 'tabungan_keluar'], true) || $actorId <= 0) {
        throw new RuntimeException('Identitas transaksi tidak valid.');
    }
    financial_request_assert_ready($db);
    $unitId = unit_active_id();
    if ($unitId < 1 || $unitId > 3) throw new RuntimeException('Pilih unit operasional terlebih dahulu.');
    try {
        $stmt = $db->prepare('INSERT INTO keuangan_request(request_key,unit_id,aksi,operator_id) VALUES(?,?,?,?)');
        $stmt->bind_param('sisi', $key, $unitId, $action, $actorId);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $error) {
        if ($error->getCode() === 1062) {
            throw new FinancialRequestReplayException('Formulir ini sudah pernah diproses. Periksa riwayat transaksi sebelum mengisi lagi.', 0, $error);
        }
        throw $error;
    }
}

function financial_request_complete(mysqli $db, string $key, int $recordId): void {
    if ($recordId <= 0) throw new RuntimeException('Referensi transaksi tidak valid.');
    $stmt = $db->prepare('UPDATE keuangan_request SET referensi_id=? WHERE request_key=? AND referensi_id IS NULL');
    $stmt->bind_param('is', $recordId, $key);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();
    if ($updated !== 1) throw new RuntimeException('Referensi transaksi gagal dicatat.');
}
