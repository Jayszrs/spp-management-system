<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir(__DIR__ . '/../laporan');
require_once '../koneksi.php';
require_once '../includes/reports.php';

function principal_view_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

try {
    session_id('principal-view-' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = ['admin_id'=>-1, 'admin_role'=>'super_admin', 'admin_nama'=>'UI Test'];
    $_SERVER['PHP_SELF'] = '/laporan/template.php';
    $_SERVER['REQUEST_URI'] = '/laporan/template.php?template=tunggakan-siswa';
    foreach ([1, 2, 3] as $unitId) {
        $_SESSION['active_unit_id'] = $unitId;
        unit_set_context($koneksi, $unitId);
        $reportUnitId = $unitId;
        $filters = report_filters($koneksi, ['template'=>'tunggakan-siswa', 'siswa_status'=>'active']);
        $report = report_principal_debt_data($koneksi, $filters);
        $classes = report_classes($koneksi);
        $classLevels = array_map('strval', range(...unit_level_bounds()));
        $exportQuery = ['template'=>'tunggakan-siswa', 'unit'=>'active', 'kelas'=>'', 'siswa_status'=>'active'];
        $total = array_sum(array_column($report['rows'], 'total_tunggakan'));

        $render = static function (array $query) use (&$report, &$filters, &$reportUnitId, &$exportQuery, &$classes, &$classLevels, &$koneksi): string {
            $_GET = $query;
            ob_start();
            include '../includes/principal_letter_page.php';
            return (string)ob_get_clean();
        };
        $summary = $render([]);
        principal_view_assert(str_contains($summary, 'Rekap Tunggakan per Kelas/Rombel')
            && str_contains($summary, 'Rata-rata Tunggakan')
            && str_contains($summary, report_money($total))
            && str_contains($summary, 'Rombel Menunggak'), 'Rekap ' . unit_label($unitId) . ' tidak lengkap.');
        if (!$report['rows']) continue;
        $students = report_student_debt_groups($koneksi, $filters, '', [], $report['as_of_date']);
        principal_view_assert(!str_contains($summary, $students[0]['nama']), 'Nama siswa muncul pada rekap ' . unit_label($unitId) . '.');

        $firstRow = $report['rows'][0];
        $detailKey = (int)$firstRow['master_kelas_id'] > 0
            ? 'rombel:' . (int)$firstRow['master_kelas_id']
            : 'kelas:' . (int)$firstRow['tingkat'] . ':' . $firstRow['kelas'];
        principal_view_assert(str_contains($summary, 'detail=' . rawurlencode($detailKey)),
            'Tautan detail rombel ' . unit_label($unitId) . ' tidak memakai identitas stabil.');
        $detail = $render(['view'=>'detail', 'detail'=>$detailKey]);
        principal_view_assert(str_contains($detail, 'Detail Tunggakan')
            && str_contains($detail, $report['rows'][0]['kelas'])
            && str_contains($detail, 'Riwayat Tagihan'), 'Detail rombel ' . unit_label($unitId) . ' tidak lengkap.');
        preg_match('/href="([^"]*template=riwayat-tagihan[^"]*)"/', $detail, $historyMatch);
        parse_str((string)parse_url(html_entity_decode($historyMatch[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY), $historyQuery);
        principal_view_assert(isset($historyQuery['q']) && !isset($historyQuery['kelas']),
            'Riwayat tagihan menyaring kelas sekarang dan menyembunyikan utang tahun lalu.');
        $filters['q'] = 'nis-lama-tidak-ada';
        $legacyDetail = $render(['view'=>'detail', 'detail'=>$detailKey, 'q'=>'nis-lama-tidak-ada']);
        principal_view_assert(str_contains($legacyDetail, 'data-principal-row'),
            'Parameter pencarian lama menghilangkan detail rombel ' . unit_label($unitId) . '.');

        if (count($report['rows']) > 1) {
            $laterRow = $report['rows'][1];
            $laterKey = (int)$laterRow['master_kelas_id'] > 0
                ? 'rombel:' . (int)$laterRow['master_kelas_id']
                : 'kelas:' . (int)$laterRow['tingkat'] . ':' . $laterRow['kelas'];
            $originalRows = $report['rows'];
            $report['rows'] = array_slice($originalRows, 1);
            $movedDetail = $render(['view'=>'detail', 'detail'=>$laterKey]);
            principal_view_assert(str_contains($movedDetail, 'Detail Tunggakan — ' . htmlspecialchars($laterRow['kelas'], ENT_QUOTES, 'UTF-8')),
                'Tautan detail berpindah rombel setelah urutan rekap berubah.');
            $report['rows'] = $originalRows;
        }

        $preview = $render(['view'=>'preview']);
        principal_view_assert(str_contains($preview, 'principal-preview-frame')
            && str_contains($preview, 'Download PDF')
            && str_contains($preview, report_money($total))
            && !str_contains($preview, $students[0]['nama']), 'Pratinjau surat ' . unit_label($unitId) . ' tidak sesuai.');
    }

    session_destroy();
    echo "OK: rekap, detail, dan pratinjau SD/SMP/SMA memakai data dan cakupan unit yang sesuai.\n";
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
