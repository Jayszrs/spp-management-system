<?php
/** Verify Apache denies SQL source and no database archive remains in webroot. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = rtrim((string)(getenv('SPP_TEST_APACHE_BASE_URL') ?: 'http://spp-management-system.test'), '/');
$parts = parse_url($base);
$host = strtolower((string)($parts['host'] ?? ''));
if (($parts['scheme'] ?? '') !== 'http'
    || !($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test'))) {
    throw new RuntimeException('Tes akses statis hanya boleh memakai Apache lokal.');
}

$backupDir = __DIR__ . '/../sql/backups';
if (is_dir($backupDir)) {
    foreach (new DirectoryIterator($backupDir) as $entry) {
        if ($entry->isFile() && !str_starts_with($entry->getFilename(), '.')) {
            throw new RuntimeException('Masih ada file backup di dalam direktori web.');
        }
    }
}

function static_access_status(string $url): int {
    $context = stream_context_create(['http' => [
        'method' => 'HEAD', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    @file_get_contents($url, false, $context);
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) return (int)$match[1];
    }
    throw new RuntimeException('Server Apache lokal tidak dapat dihubungi.');
}

$schemaStatus = static_access_status($base . '/sql/verify_schema.sql');
$backupStatus = static_access_status($base . '/sql/backups/db_spp_before_psb_spp_full_20260831_224523.sql');
$loginStatus = static_access_status($base . '/login.php');
if ($schemaStatus !== 403 || !in_array($backupStatus, [403, 404], true) || $loginStatus !== 200) {
    throw new RuntimeException('Akses statis tidak aman atau aplikasi tidak tersedia: schema=' . $schemaStatus
        . ', backup=' . $backupStatus . ', login=' . $loginStatus);
}
echo "OK: Apache menolak /sql, backup tidak berada di webroot, dan login tetap tersedia.\n";
