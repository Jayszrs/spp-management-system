<?php
/** Guard regression: every rejected request must stop before opening a database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function seed_guard_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function seed_guard_cli(string $script, array $arguments, array $overrides, string $expected): void {
    $environment = array_merge(getenv(), [
        'SPP_DB_HOST' => 'invalid.invalid',
        'SPP_DB_NAME' => '',
        'SPP_TEST_ALLOW_MUTATION' => '',
    ], $overrides);
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, $script], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        dirname(__DIR__), $environment);
    seed_guard_assert(is_resource($process), "Tidak dapat menjalankan {$script}.");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    seed_guard_assert($status !== 0 && str_contains($output, $expected),
        "Guard CLI {$script} tidak menolak sebelum koneksi (status {$status}, PHP " . PHP_BINARY . "): {$output}");
}

$root = dirname(__DIR__);
$reset = $root . '/tests/demo_reset_seed_test.php';
$payment = $root . '/tests/demo_payment_seed_test.php';
$multiunit = $root . '/sql/seed_demo_multiunit.php';
foreach ([
    [$reset, [], ['SPP_DB_NAME' => 'db_spp_test_demo_reset_guard'], 'SPP_TEST_ALLOW_MUTATION=1'],
    [$reset, [], ['SPP_DB_NAME' => 'db_spp', 'SPP_TEST_ALLOW_MUTATION' => '1'], 'db_spp_test_demo_reset_*'],
    [$payment, [], ['SPP_DB_NAME' => 'db_spp_test_demo_payment_guard'], 'SPP_TEST_ALLOW_MUTATION=1'],
    [$payment, [], ['SPP_DB_NAME' => 'db_spp', 'SPP_TEST_ALLOW_MUTATION' => '1'], 'db_spp_test_demo_payment_*'],
    [$multiunit, ['--apply', '--as-of=2026-09-30'], ['SPP_DB_NAME' => 'db_spp_test_guard'], 'SPP_TEST_ALLOW_MUTATION=1'],
    [$multiunit, ['--apply', '--as-of=2026-09-30'], ['SPP_DB_NAME' => 'db_spp', 'SPP_TEST_ALLOW_MUTATION' => '1'], 'db_spp_test_*'],
    [$multiunit, ['--apply-live', '--as-of=2026-09-30'], ['SPP_DB_NAME' => 'db_spp', 'SPP_TEST_ALLOW_MUTATION' => '1'], '--apply-live dinonaktifkan'],
    [$multiunit, [], ['SPP_DB_NAME' => 'db_spp'], 'db_spp_test_*'],
] as [$script, $arguments, $environment, $expected]) {
    seed_guard_cli($script, $arguments, $environment, $expected);
}

// Built-in PHP server uses the same source files through HTTP; no database is reachable.
$listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorText);
seed_guard_assert(is_resource($listener), "Tidak dapat memilih port HTTP: {$errorText}");
$port = (int)substr((string)stream_socket_get_name($listener, false), strrpos((string)stream_socket_get_name($listener, false), ':') + 1);
fclose($listener);
$pipes = [];
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
    $root, array_merge(getenv(), ['SPP_DB_HOST' => 'invalid.invalid', 'SPP_DB_NAME' => 'db_spp', 'SPP_TEST_ALLOW_MUTATION' => '1']));
seed_guard_assert(is_resource($server), 'Tidak dapat memulai server HTTP uji.');
try {
    fclose($pipes[0]);
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]);
    foreach (['tests/demo_reset_seed_test.php', 'tests/demo_payment_seed_test.php', 'sql/seed_demo_multiunit.php'] as $path) {
        $status = '';
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $http_response_header = [];
            $body = @file_get_contents("http://127.0.0.1:{$port}/{$path}", false, $context);
            $status = $http_response_header[0] ?? '';
            if ($body !== false) break;
            usleep(100000);
        }
        seed_guard_assert(str_contains($status, '404'), "{$path} tidak mengembalikan HTTP 404: {$status}");
    }
} finally {
    proc_terminate($server);
    foreach ([$pipes[1], $pipes[2]] as $pipe) fclose($pipe);
    proc_close($server);
}

echo "OK: 8 guard CLI dan 3 guard HTTP seed menolak sebelum koneksi database.\n";
