<?php
/**
 * Add optional savings journal notes to both base tables and multiunit views.
 * Read-only by default. Back up and test on a clone before a live migration.
 * Live use requires the owner's separate approval, a backup, and all three
 * explicit switches described in the error below.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/readiness_migration_guard.php';

$apply = in_array('--apply', $argv, true);
$schema = DB_NAME;
$tables = ['transaksi_m', 'transaksi_k'];
$state = [];
foreach ($tables as $table) {
    foreach ([$table, $table . '_data'] as $name) {
        $stmt = $koneksi->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $kind = $stmt->get_result()->fetch_assoc()['TABLE_TYPE'] ?? null;
        $stmt->close();
        $state[$name] = $kind;
    }
}
$multiunit = $state['transaksi_m'] === 'VIEW' && $state['transaksi_k'] === 'VIEW'
    && $state['transaksi_m_data'] === 'BASE TABLE' && $state['transaksi_k_data'] === 'BASE TABLE';
$legacy = $state['transaksi_m'] === 'BASE TABLE' && $state['transaksi_k'] === 'BASE TABLE'
    && $state['transaksi_m_data'] === null && $state['transaksi_k_data'] === null;
if (!$multiunit && !$legacy) throw new RuntimeException('Struktur jurnal tabungan tidak dikenali; migrasi dibatalkan.');

$columnExists = static function (mysqli $db, string $table): bool {
    $stmt = $db->prepare("SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='keterangan'");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $count === 1;
};

foreach ($tables as $table) {
    $base = $multiunit ? $table . '_data' : $table;
    $baseReady = $columnExists($koneksi, $base);
    $viewReady = !$multiunit || $columnExists($koneksi, $table);
    echo "{$table}: base=" . ($baseReady ? 'ready' : 'missing')
        . ', view=' . ($viewReady ? 'ready' : 'missing') . PHP_EOL;
}
if (!$apply) {
    echo "AUDIT ONLY: database {$schema} tidak diubah.\n";
    exit;
}

readiness_migration_assert_apply_allowed($argv, $schema);

foreach ($tables as $table) {
    $base = $multiunit ? $table . '_data' : $table;
    if (!$columnExists($koneksi, $base)) {
        $koneksi->query("ALTER TABLE `{$base}` ADD COLUMN `keterangan` VARCHAR(255) NULL DEFAULT NULL AFTER `user_id`");
    }
    if ($multiunit && !$columnExists($koneksi, $table)) {
        $koneksi->query("CREATE OR REPLACE VIEW `{$table}` AS SELECT * FROM `{$base}` WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
    }
    if (!$columnExists($koneksi, $base) || ($multiunit && !$columnExists($koneksi, $table))) {
        throw new RuntimeException("Kolom keterangan {$table} belum tersedia setelah migrasi.");
    }
}
echo "OK: jurnal tabungan menerima keterangan; mutasi lama tetap tanpa keterangan.\n";
