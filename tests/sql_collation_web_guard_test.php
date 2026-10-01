<?php
/** An unauthenticated web request must never run collation DDL. */
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', (string)getenv('SPP_DB_NAME'))
    || !preg_match('#^http://127\.0\.0\.1:\d+/?$#', (string)getenv('SPP_HTTP_BASE'))) {
    fwrite(STDERR, "Tes guard HTTP hanya untuk server lokal dan database clone dengan flag mutasi.\n");
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';

$collations = static function (mysqli $db): array {
    return $db->query("SELECT TABLE_NAME,COLUMN_NAME,COLLATION_NAME
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
          AND COLLATION_NAME IS NOT NULL ORDER BY TABLE_NAME,COLUMN_NAME")
        ->fetch_all(MYSQLI_ASSOC);
};
$before = $collations($koneksi);
if (!$before) throw new RuntimeException('Clone uji tidak memiliki kolom teks untuk diperiksa.');
$url = rtrim((string)getenv('SPP_HTTP_BASE'), '/') . '/sql/fix_mixed_collations.php';
$context = stream_context_create(['http'=>[
    'method'=>'GET', 'ignore_errors'=>true, 'follow_location'=>0, 'timeout'=>15,
]]);
$body = file_get_contents($url, false, $context);
$headers = $http_response_header ?? [];
$after = $collations($koneksi);
if ($body === false || !str_contains($headers[0] ?? '', '404') || $before !== $after) {
    throw new RuntimeException('HTTP masih dapat menjalankan skrip collation atau mengubah skema clone.');
}
echo "PASS: HTTP tanpa sesi menerima 404 dan collation clone tetap sama.\n";
