<?php
/** HTTP export checks against the disposable demo database and a dedicated PHP test server. */
if (PHP_SAPI !== 'cli' || !preg_match('/^db_spp_test_[a-z0-9_]+$/i', (string)getenv('SPP_DB_NAME'))) {
    throw new RuntimeException('Gunakan salinan db_spp_test_* melalui CLI.');
}
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/report_letters.php';

function export_fixture_assert(bool $okay, string $message): void {
    if (!$okay) throw new RuntimeException($message);
}
function export_fixture_get(string $url, string $sessionId): array {
    $context = stream_context_create(['http'=>[
        'method'=>'GET','ignore_errors'=>true,'timeout'=>40,
        'header'=>'Cookie: '.session_name().'='.$sessionId."\r\n",
    ]]);
    $body = file_get_contents($url, false, $context);
    return [$http_response_header ?? [], $body === false ? '' : $body];
}
function export_fixture_header(array $headers, string $name): string {
    foreach ($headers as $header) if (stripos($header, $name.':') === 0) return trim(substr($header, strlen($name)+1));
    return '';
}

$base = rtrim((string)(getenv('SPP_HTTP_BASE') ?: 'http://127.0.0.1:8096'), '/');
$super = $koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
export_fixture_assert((bool)$super, 'Akun Super Admin uji tidak ditemukan.');
$date = $argv[1] ?? date('Y-m-d');
export_fixture_assert((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 'Tanggal harus YYYY-MM-DD.');
$checked = 0;
foreach ([1,2,3,0] as $unitId) {
    $sessionId = 'demoxport'.bin2hex(random_bytes(7));
    session_id($sessionId);
    session_start();
    $_SESSION = [
        'admin_id'=>(int)$super['id'],'admin_role'=>'super_admin','admin_unit_id'=>0,
        'active_unit_id'=>$unitId === 0 ? 1 : $unitId,'admin_nama'=>'Uji Ekspor',
    ];
    session_write_close();
    unit_set_context($koneksi, $unitId);
    $scope = $unitId === 0 ? 'all' : 'active';
    foreach (array_keys(report_registry()) as $template) {
        $source = [
            'template'=>$template,'unit'=>$scope,'tanggal_awal'=>$date,'tanggal_akhir'=>$date,
            'tahun_ajaran'=>'2026/2027','bulan_awal'=>'09','bulan_akhir'=>'09',
            'tahun_awal'=>2026,'tahun_akhir'=>2026,
            'kategori'=>$template === 'penerimaan' ? 'semua' : 'spp',
        ];
        if ($template === 'riwayat-tagihan') $source['siswa_status'] = 'all';
        $expected = report_build($koneksi, $template, report_filters($koneksi, $source));
        $path = $base.'/laporan/export_global.php?'.http_build_query($source);
        [$headers,$preview] = export_fixture_get($path.'&format=preview', $sessionId);
        export_fixture_assert(str_contains($headers[0] ?? '', '200') && str_contains($preview, 'data-preview-frame'),
            "Pratinjau PDF {$template} ".unit_label($unitId).' gagal.');
        $expectedCount = $template === 'riwayat-tagihan'
            ? count(report_billing_history_group_students($expected['rows']))
            : (int)($expected['total'] ?? count($expected['rows']));
        preg_match('/([0-9.,]+) data/', $preview, $previewCountMatch);
        export_fixture_assert(str_contains($preview, number_format($expectedCount).' data'),
            "Jumlah baris pratinjau {$template} ".unit_label($unitId)
            ." berbeda: sumber={$expectedCount}, pratinjau=".($previewCountMatch[1] ?? '?').'.');
        [$headers,$excel] = export_fixture_get($path.'&format=excel', $sessionId);
        export_fixture_assert(str_contains($headers[0] ?? '', '200') && str_contains($excel, 'Download EXCEL'),
            "Pratinjau Excel {$template} ".unit_label($unitId).' gagal.');
        if ($template === 'tunggakan-siswa') {
            [$pageHeaders,$page] = export_fixture_get($base.'/laporan/template.php?'.http_build_query($source), $sessionId);
            export_fixture_assert(str_contains($pageHeaders[0] ?? '', '200')
                && str_contains($page, 'Pratinjau Surat Pilihan')
                && str_contains($page, 'Seluruh Rombel Kelas')
                && str_contains($page, 'Total Tunggakan'),
                'Daftar surat kepala sekolah '.unit_label($unitId).' tidak lengkap.');
            preg_match('/<iframe[^>]+srcdoc="([^"]*)"/s', $preview, $match);
            $document = html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $letter = report_principal_letter_html($expected['rows'], $expected['as_of_date'] ?? $date);
            export_fixture_assert($document === $letter,
                'Isi pratinjau surat '.unit_label($unitId).' berbeda dari sumber PDF.');
            export_fixture_assert(str_contains($preview, 'Cetak') && str_contains($preview, 'download=1'),
                'Tindakan pada pratinjau surat '.unit_label($unitId).' hilang.');
            [$headers,$pdf] = export_fixture_get($path.'&format=pdf&download=1', $sessionId);
            export_fixture_assert(str_contains($headers[0] ?? '', '200') && str_starts_with($pdf, '%PDF-'),
                'PDF surat '.unit_label($unitId).' gagal.');
            export_fixture_assert(str_contains(strtolower(export_fixture_header($headers,'Content-Disposition')), 'attachment'),
                'Unduhan PDF surat '.unit_label($unitId).' bukan lampiran.');
            if ($expected['rows'] && (int)($expected['rows'][0]['master_kelas_id'] ?? 0) > 0) {
                $classId = (int)$expected['rows'][0]['master_kelas_id'];
                $classSource = array_replace($source, ['kelas'=>'rombel:'.$classId]);
                $classExpected = report_build($koneksi, $template, report_filters($koneksi, $classSource));
                [$classHeaders,$classPreview] = export_fixture_get(
                    $base.'/laporan/export_global.php?'.http_build_query($classSource).'&format=preview&mode=class',
                    $sessionId
                );
                export_fixture_assert(str_contains($classHeaders[0] ?? '', '200')
                    && str_contains($classPreview, number_format(count($classExpected['rows'])).' data'),
                    'Pratinjau surat satu rombel '.unit_label($unitId).' salah.');
                [$oldHeaders] = export_fixture_get($path.'&format=preview&mode=single&nis=tidak-berlaku', $sessionId);
                export_fixture_assert(str_contains($oldHeaders[0] ?? '', '400'),
                    'Mode surat kepala sekolah per siswa masih diterima.');
            }
        }
        $checked++;
    }
    session_id($sessionId);
    session_start();
    $_SESSION = [];
    session_destroy();
    session_write_close();
}
echo "OK: {$checked} jenis/unit membuka pratinjau PDF dan Excel; empat surat identik dengan sumber PDF dan dapat diunduh.\n";
