<?php
/** Check which period control a cashier actually sees for the selected item. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$database = (string)getenv('SPP_DB_NAME');
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', $database)) {
    throw new RuntimeException('Tes halaman hanya untuk clone audit dengan flag tes.');
}
$category = $argv[1] ?? '';
if (!in_array($category, ['daftar_ulang', 'komite'], true)) {
    throw new RuntimeException('Pilih kategori daftar_ulang atau komite.');
}

session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'admin';
$_SESSION['active_unit_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/laporan/template.php';
$_GET = ['template'=>'per-item', 'unit'=>'active', 'kategori'=>$category,
    'tahun_ajaran'=>'2026/2027', 'bulan_awal'=>'08', 'bulan_akhir'=>'08',
    'tahun_awal'=>'2026', 'tahun_akhir'=>'2026'];

chdir(__DIR__ . '/../laporan');
ob_start();
include __DIR__ . '/../laporan/template.php';
$html = ob_get_clean();

$fieldStyle = static function (string $period) use ($html): ?string {
    if (!preg_match('/<div\b[^>]*data-per-item-period="' . preg_quote($period, '/') . '"([^>]*)>/i', $html, $matches)) {
        return null;
    }
    return str_contains($matches[1], 'display:none') ? 'hidden' : 'visible';
};
$expected = $category === 'daftar_ulang'
    ? ['date'=>'hidden', 'month'=>'hidden', 'academic-year'=>'visible']
    : ['date'=>'hidden', 'month'=>'visible', 'academic-year'=>'hidden'];
foreach ($expected as $period => $visibility) {
    if ($fieldStyle($period) !== $visibility) {
        fwrite(STDERR, "FAILED: kategori {$category}, filter {$period} harus {$visibility}.\n");
        exit(1);
    }
}
echo "PASS: filter periode {$category} sesuai jenis tagihan.\n";
