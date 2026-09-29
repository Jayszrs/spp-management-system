<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__ . '/../laporan');
require_once '../koneksi.php';

try {
    $class = $koneksi->query("SELECT id FROM master_kelas WHERE is_active=1 AND is_placeholder=0 ORDER BY tingkat,kode_rombel LIMIT 1")->fetch_assoc();
    if (!$class) {
        echo "SKIPPED: belum ada rombel aktif non-placeholder untuk smoke test render.\n";
        exit(0);
    }

    session_id('codex-billing-' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = [
        'admin_id' => -1,
        'admin_role' => 'admin',
        'admin_nama' => 'UI Smoke Test',
    ];
    session_write_close();

    $_SERVER['PHP_SELF'] = '/laporan/template.php';
    $_GET = [
        'template' => 'riwayat-tagihan',
        'kelas' => 'rombel:' . (int)$class['id'],
        'siswa_status' => 'all',
    ];

    ob_start();
    include 'template.php';
    $html = ob_get_clean();

    if (!str_contains($html, 'report-billing-matrix-table')) {
        throw new RuntimeException('Matriks tagihan per siswa tidak dirender.');
    }
    if (!str_contains($html, 'Siswa/Halaman')) {
        throw new RuntimeException('Pagination belum memakai satuan siswa.');
    }
    if (!preg_match('~<table class="payment-table report-billing-matrix-table".*?</table>~s', $html, $matrixMatch)
        || !str_contains($matrixMatch[0], 'billing-matrix-amount')
        || str_contains($matrixMatch[0], 'Terbayar')
        || str_contains($matrixMatch[0], '>Sisa<')) {
        throw new RuntimeException('Matriks harus hanya menampilkan nominal tagihan per komponen.');
    }
    if (!str_contains($html, 'assets/css/style.css?v=10.28')) {
        throw new RuntimeException('Versi cache stylesheet laporan belum diperbarui.');
    }
    foreach (['report-date-range-field', 'report-field-komponen-tagihan', 'report-field-status', 'report-field-siswa-status'] as $filterClass) {
        if (!str_contains($html, $filterClass)) {
            throw new RuntimeException('Filter server-side tidak lengkap: ' . $filterClass);
        }
    }
    if (!str_contains($html, 'Tanggal Tagihan Dibuat') || str_contains($html, 'name="tahun_tagihan"')) {
        throw new RuntimeException('Filter Tahun Ajaran belum diganti dengan rentang tanggal.');
    }
    if (!str_contains($html, 'Tanggal Uang Pangkal dan Uang PSB mengikuti tanggal data siswa dibuat.')) {
        throw new RuntimeException('Penjelasan tanggal perkiraan belum tampil.');
    }
    if (!str_contains($html, 'report-student-field') || !str_contains($html, 'report-per-page-field')) {
        throw new RuntimeException('Susunan pencarian siswa dan pagination laporan tidak lengkap.');
    }
    $stylesheet = file_get_contents(__DIR__ . '/../assets/css/style.css');
    foreach ([
        '.report-global-filter-form.report-filter-riwayat-tagihan',
        '"kelas date component"',
        '"status student-status per-page"',
        '"student student student"',
        '.report-billing-matrix-table',
        '--billing-matrix-hover',
        'grid-template-columns: repeat(3, minmax(0, 1fr)) !important',
    ] as $cssContract) {
        if (!str_contains($stylesheet, $cssContract)) {
            throw new RuntimeException('Kontrak layout Riwayat Tagihan tidak lengkap: ' . $cssContract);
        }
    }

    session_write_close();
    $_GET['format'] = 'print';
    register_shutdown_function(static function(): void {
        $exportHtml=ob_get_clean();
        if (!is_string($exportHtml) || !str_contains($exportHtml,'billing-export-table') || !str_contains($exportHtml,'billing-export-note') || !str_contains($exportHtml,'billing-export-amount') || str_contains($exportHtml,'billing-export-cell-row')) {
            fwrite(STDERR,"FAILED: cetak tidak memakai matriks komponen yang sama.\n");
            exit(1);
        }
        echo "OK: matriks komponen, filter tanggal, dan cetak berhasil dirender.\n";
    });
    ob_start();
    include 'export_global.php';
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
