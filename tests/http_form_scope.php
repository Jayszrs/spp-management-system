<?php
/** Prove the HTTP process and CLI test use the same disposable database. */
function spp_test_assert_http_clone(string $base, string $database): void {
    $url = parse_url($base);
    if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
        || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', $database)
        || ($url['scheme'] ?? '') !== 'http'
        || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('HTTP test requires a flagged disposable clone and loopback server.');
    }
    $body = file_get_contents(rtrim($base, '/') . '/tests/browser_clone_identity.php', false,
        stream_context_create(['http'=>['ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]));
    if (!str_contains($http_response_header[0] ?? '', ' 200 ')
        || $body === false || (json_decode($body, true)['database'] ?? '') !== $database) {
        throw new RuntimeException('HTTP server does not use the named disposable database.');
    }
}

/** Select an application form so navigation CSRF tokens cannot satisfy a test. */
function spp_test_form_scope(string $html, string $requiredField): string {
    preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $forms);
    foreach ($forms[0] ?? [] as $form) {
        if (preg_match('/\bname=["\x27]' . preg_quote($requiredField, '/') . '["\x27]/i', $form)) {
            return $form;
        }
    }
    throw new RuntimeException('Expected application form with field ' . $requiredField . ' is missing.');
}
