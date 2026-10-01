<?php
/** HTTP regression for the logout method and CSRF boundary. Use a disposable clone only. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "SKIPPED: use CLI, SPP_TEST_ALLOW_MUTATION=1, and a db_spp_audit_* clone.\n");
    exit(1);
}
require_once __DIR__ . '/../koneksi.php';

function logout_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function logout_request(string $url, ?array $post, array &$cookies): array {
    $headers = [];
    if ($post !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ]]);
    $body = file_get_contents($url, false, $context);
    logout_assert($body !== false, 'HTTP request failed: ' . $url);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => $body];
}

$base = rtrim((string)(getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1:8791'), '/');
$url = parse_url($base);
logout_assert(($url['scheme'] ?? '') === 'http'
    && in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true),
    'SPP_TEST_BASE_URL must point to a local HTTP server.');
$account = $koneksi->query("SELECT id,password FROM admin WHERE username='admin' AND role='admin' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_assoc();
logout_assert((bool)$account, 'An active SD admin account is required on the clone.');
$originalHash = (string)$account['password'];
$id = (int)$account['id'];
$password = bin2hex(random_bytes(20));
$testHash = password_hash($password, PASSWORD_DEFAULT);
$setHash = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
$setHash->bind_param('si', $testHash, $id);
$setHash->execute();
$setHash->close();

$failure = null;
try {
    $cookies = [];
    $login = logout_request($base . '/login.php', ['username' => 'admin', 'password' => $password], $cookies);
    logout_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Clone admin login failed.');
    $dashboard = logout_request($base . '/dashboard.php', null, $cookies);
    logout_assert($dashboard['status'] === 200, 'Admin dashboard unavailable.');

    $get = logout_request($base . '/logout.php', null, $cookies);
    $afterGet = logout_request($base . '/dashboard.php', null, $cookies);
    logout_assert($get['status'] === 405 && $afterGet['status'] === 200,
        'GET logout must preserve the session (logout=' . $get['status']
        . ', dashboard=' . $afterGet['status'] . ').');

    $hasForm = preg_match('/<form[^>]*action="logout\.php"[^>]*method="post"[^>]*>/s', $dashboard['body']) === 1;
    $hasToken = preg_match('/<form[^>]*action="logout\.php"[^>]*method="post"[^>]*>\s*<input[^>]*name="csrf_token" value="([a-f0-9]{64})"/s', $dashboard['body'], $match) === 1;
    logout_assert($hasForm && $hasToken,
        'Logout form or its CSRF token is missing (form=' . (int)$hasForm . ', token=' . (int)$hasToken . ').');
    $token = $match[1];
    $nested = logout_request($base . '/tabungan/masuk.php', null, $cookies);
    logout_assert($nested['status'] === 200
        && str_contains($nested['body'], '<form action="../logout.php" method="post">')
        && str_contains($nested['body'], 'name="csrf_token" value="' . $token . '"'),
        'Nested pages must render a logout form targeting the root endpoint.');

    foreach ([[], ['csrf_token' => 'invalid']] as $post) {
        $response = logout_request($base . '/logout.php', $post, $cookies);
        logout_assert($response['status'] === 403, 'Logout without a valid CSRF token must be rejected.');
        logout_assert(logout_request($base . '/dashboard.php', null, $cookies)['status'] === 200,
            'Invalid logout token invalidated the authenticated session.');
    }

    $logout = logout_request($base . '/logout.php', ['csrf_token' => $token], $cookies);
    logout_assert($logout['status'] === 303, 'Valid logout did not redirect with POST/Redirect/GET.');
    logout_assert(logout_request($base . '/dashboard.php', null, $cookies)['status'] === 302,
        'Valid logout left the session authenticated.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $restore = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
    $restore->bind_param('si', $originalHash, $id);
    $restore->execute();
    $restore->close();
}
if ($failure) throw $failure;
echo "PASS: GET and tokenless logout preserve the session; valid POST logout ends it.\n";
