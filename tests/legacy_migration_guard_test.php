<?php
/** Historical migration entry points must refuse main writes before DB connect. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

function legacy_guard_denies(string $script, array $arguments, string $expected): void {
    $environment = array_merge(getenv(), [
        'SPP_DB_NAME' => 'db_spp',
        'SPP_DB_HOST' => 'invalid.invalid',
        'SPP_ALLOW_MAIN_MIGRATION' => '',
        'SPP_TEST_ALLOW_MUTATION' => '',
    ]);
    $process = proc_open(array_merge([PHP_BINARY, $script], $arguments),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), $environment,
        ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Proses guard migrasi tidak dapat dibuka.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status === 0 || !str_contains($output, $expected)
        || str_contains($output, 'getaddrinfo')) {
        throw new RuntimeException("Migrasi tidak ditolak sebelum koneksi: {$script}; status={$status}; {$output}");
    }
}

$root = dirname(__DIR__);
$credentials = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'spp-account-denial-' . bin2hex(random_bytes(6)) . '.txt';
legacy_guard_denies($root . '/sql/migrate_units.php', ['--apply'], 'DDL hanya boleh pada clone');
legacy_guard_denies($root . '/sql/migrate_spp_billing.php', ['--execute'], 'DDL hanya boleh pada clone');
legacy_guard_denies($root . '/sql/bootstrap_unit_accounts.php', [$credentials, '--apply'], 'DDL hanya boleh pada clone');
if (file_exists($credentials)) throw new RuntimeException('Penolakan bootstrap masih membuat berkas kredensial.');
echo "PASS: tiga migrasi historis menolak target utama sebelum koneksi.\n";
