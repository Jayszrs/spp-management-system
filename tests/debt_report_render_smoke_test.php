<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__ . '/../laporan');
require_once '../koneksi.php';
require_once '../includes/reports.php';

try {
    session_id('codex-debt-' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = ['admin_id'=>-1, 'admin_role'=>'admin', 'admin_nama'=>'UI Smoke Test'];
    unit_set_context($koneksi, 1);

    $reportUnitId = 1;
    $filters = report_filters($koneksi, ['siswa_status'=>'active']);
    $report = report_principal_debt_data($koneksi, $filters);
    $classes = report_classes($koneksi);
    $classLevels = array_map('strval', range(...unit_level_bounds()));
    $exportQuery = ['template'=>'tunggakan-siswa', 'unit'=>'active', 'kelas'=>'', 'siswa_status'=>'active'];
    $_SERVER['PHP_SELF'] = '/laporan/template.php';
    ob_start();
    include '../includes/principal_letter_page.php';
    $html = ob_get_clean();

    foreach (['principal-class', 'principal-status', 'Pratinjau Surat Pilihan', 'Rekap Tunggakan per Kelas/Rombel', 'Rata-rata Tunggakan', 'Jumlah dihitung sampai'] as $required) {
        if (!str_contains($html, $required)) throw new RuntimeException('Pilihan rekap tunggakan tidak lengkap: ' . $required);
    }
    if (str_contains($html, 'Cari Siswa') || str_contains($html, 'letter-select-cell')) {
        throw new RuntimeException('Halaman kepala sekolah masih menampilkan pemilihan per siswa.');
    }
    session_destroy();
    echo "OK: pilihan cakupan dan rekap kepala sekolah dirender tanpa rincian siswa.\n";
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
