<?php
/**
 * Menyamakan collation database hasil migrasi XAMPP/Laragon.
 *
 * Jalankan dari root project untuk audit; DDL membutuhkan --apply dan gate migrasi:
 *   php sql/fix_mixed_collations.php [--apply]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/readiness_migration_guard.php';

$targetCharset = 'utf8mb4';
$targetCollation = 'utf8mb4_general_ci';
$database = $koneksi->query('SELECT DATABASE() AS db')->fetch_assoc()['db'] ?? '';
if ($database === '') {
    fwrite(STDERR, "Database aktif tidak ditemukan.\n");
    exit(1);
}

$quoteIdentifier = static function (string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
};

$before = $koneksi->query("
    SELECT COLLATION_NAME, COUNT(*) total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND COLLATION_NAME IS NOT NULL
    GROUP BY COLLATION_NAME
    ORDER BY COLLATION_NAME
")->fetch_all(MYSQLI_ASSOC);

echo "Database: {$database}\n";
echo "Sebelum:\n";
foreach ($before as $row) {
    echo "- {$row['COLLATION_NAME']}: {$row['total']} kolom\n";
}

if (!in_array('--apply', $argv, true)) {
    echo "AUDIT ONLY: collation database tidak diubah.\n";
    exit;
}
readiness_migration_assert_apply_allowed($argv, $database);

$tables = $koneksi->query("
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_NAME
")->fetch_all(MYSQLI_ASSOC);

$koneksi->query('SET FOREIGN_KEY_CHECKS = 0');
try {
    $koneksi->query(
        'ALTER DATABASE ' . $quoteIdentifier($database) .
        " CHARACTER SET {$targetCharset} COLLATE {$targetCollation}"
    );

    foreach ($tables as $row) {
        $table = (string)$row['TABLE_NAME'];
        $koneksi->query(
            'ALTER TABLE ' . $quoteIdentifier($table) .
            " CONVERT TO CHARACTER SET {$targetCharset} COLLATE {$targetCollation}"
        );
        echo "OK: {$table}\n";
    }
} finally {
    $koneksi->query('SET FOREIGN_KEY_CHECKS = 1');
}

$after = $koneksi->query("
    SELECT COLLATION_NAME, COUNT(*) total
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND COLLATION_NAME IS NOT NULL
    GROUP BY COLLATION_NAME
    ORDER BY COLLATION_NAME
")->fetch_all(MYSQLI_ASSOC);

echo "Sesudah:\n";
foreach ($after as $row) {
    echo "- {$row['COLLATION_NAME']}: {$row['total']} kolom\n";
}

$mixed = array_filter($after, static fn(array $row): bool => $row['COLLATION_NAME'] !== $targetCollation);
if ($mixed) {
    fwrite(STDERR, "Masih ada kolom dengan collation berbeda.\n");
    exit(1);
}

echo "Selesai: semua kolom teks memakai {$targetCollation}.\n";
