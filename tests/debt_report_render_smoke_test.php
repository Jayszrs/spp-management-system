<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__ . '/../laporan');
require_once '../koneksi.php';

try {
    session_id('codex-debt-' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = [
        'admin_id' => -1,
        'admin_role' => 'admin',
        'admin_nama' => 'UI Smoke Test',
    ];
    session_write_close();

    $_SERVER['PHP_SELF'] = '/laporan/template.php';
    $_GET = ['template' => 'tunggakan-siswa'];

    ob_start();
    include 'template.php';
    $html = ob_get_clean();

    foreach ([
        'report-filter-tunggakan-siswa',
        'report-field-kelas',
        'report-field-siswa-status',
        'report-per-page-field',
        'report-student-field',
    ] as $requiredClass) {
        if (!str_contains($html, $requiredClass)) {
            throw new RuntimeException('Susunan filter tunggakan tidak lengkap: ' . $requiredClass);
        }
    }

    if (str_contains($html, "addSelect('Status Siswa'")) {
        throw new RuntimeException('Filter tunggakan masih bergantung pada injeksi JavaScript.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    echo "OK: filter Rekap Tunggakan dirender stabil dari server.\n";
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
