<?php
/** A test server aimed at the wrong database must fail before any POST. */
require_once __DIR__ . '/http_form_scope.php';
$database = (string)getenv('SPP_DB_NAME');
$base = (string)getenv('SPP_TEST_BASE_URL');
spp_test_assert_http_clone($base, $database);
$rejected = 0;
foreach ([[$base, 'db_spp_audit_wrongtarget'], [$base, 'db_spp'],
    ['http://example.invalid', $database]] as [$url, $name]) {
    try {
        spp_test_assert_http_clone($url, $name);
    } catch (RuntimeException $expected) {
        $rejected++;
    }
}
putenv('SPP_TEST_ALLOW_MUTATION=0');
try {
    spp_test_assert_http_clone($base, $database);
} catch (RuntimeException $expected) {
    $rejected++;
} finally {
    putenv('SPP_TEST_ALLOW_MUTATION=1');
}
if ($rejected !== 4) throw new RuntimeException('HTTP clone guard accepted an unsafe target.');
echo "OK: verified matching server; wrong clone/main/public host/missing flag rejected without POST.\n";
