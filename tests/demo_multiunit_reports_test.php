<?php
/** Read-only checks for the disposable multiunit demo fixture. */
if (PHP_SAPI !== 'cli' || !preg_match('/^db_spp_test_[a-z0-9_]+$/i', (string)getenv('SPP_DB_NAME'))) {
    throw new RuntimeException('Gunakan salinan db_spp_test_* melalui CLI.');
}
$date = $argv[1] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Tanggal harus YYYY-MM-DD.');
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/report_letters.php';

function fixture_assert(bool $okay, string $message): void {
    if (!$okay) throw new RuntimeException($message);
}
function fixture_count(mysqli $db, string $table): int {
    return (int)$db->query("SELECT COUNT(*) n FROM `{$table}`")->fetch_assoc()['n'];
}

$unitCounts = [];
foreach ([1, 2, 3] as $unitId) {
    $_SESSION['active_unit_id'] = $unitId;
    $_SESSION['admin_role'] = 'super_admin';
    unit_set_context($koneksi, $unitId);
    $label = unit_label($unitId);
    $students = fixture_count($koneksi, 'siswa');
    $payments = fixture_count($koneksi, 'bayar');
    $savings = fixture_count($koneksi, 'tabungan');
    fixture_assert($unitId === 1 ? $students >= 150 : $students === 36, "Jumlah siswa {$label} salah.");
    // An imported disposable snapshot can contain later legitimate payments
    // in addition to the 17 transactions created by the demo fixture.
    fixture_assert($unitId === 1 ? $payments >= 1002 : $payments >= 17, "Jumlah pembayaran {$label} salah.");
    fixture_assert($savings === 6 && fixture_count($koneksi, 'transaksi_m') === 6 && fixture_count($koneksi, 'transaksi_k') === 3,
        "Contoh tabungan {$label} tidak lengkap.");
    $balance = $koneksi->query('SELECT MIN(SALDO) minimum, SUM(SALDO) total FROM tabungan')->fetch_assoc();
    fixture_assert((float)$balance['minimum'] >= 0 && (float)$balance['total'] === 525000.0, "Saldo {$label} salah.");
    if ($unitId !== 1) {
        fixture_assert(fixture_count($koneksi, 'tagihan_spp') === 396 && fixture_count($koneksi, 'tagihan_komite') === 396,
            "Tagihan bulanan {$label} tidak lengkap.");
        fixture_assert(fixture_count($koneksi, 'tagihan_daftar_ulang') === 33, "Tagihan Daftar Ulang {$label} salah.");
        fixture_assert(fixture_count($koneksi, 'spp_alokasi') === 6 && fixture_count($koneksi, 'bayar_komite') === 6,
            "Relasi pembayaran SPP/Komite {$label} salah.");
        fixture_assert(fixture_count($koneksi, 'bayar_du') === 3 && fixture_count($koneksi, 'bayar_biaya_lain') === 2,
            "Relasi pembayaran Daftar Ulang/Biaya Lain {$label} salah.");
        fixture_assert(fixture_count($koneksi, 'titipan_spp_mutasi') >= 3, "Mutasi Titipan SPP {$label} tidak lengkap.");
        fixture_assert((int)$koneksi->query("SELECT COUNT(*) n FROM siswa WHERE KELAS='PSB'")->fetch_assoc()['n'] === 3,
            "Siswa PSB {$label} salah.");
        $year = $koneksi->query("SELECT status FROM tahun_ajaran WHERE label='2026/2027'")->fetch_assoc();
        fixture_assert(($year['status'] ?? '') === 'published', "Tahun ajaran {$label} belum terbit.");
        $daily = report_build($koneksi, 'penerimaan', report_filters($koneksi, [
            'template'=>'penerimaan','tanggal_awal'=>$date,'tanggal_akhir'=>$date,'kategori'=>'semua',
        ]));
        fixture_assert(count($daily['rows']) > 0, "Rekap penerimaan harian {$label} kosong.");
        foreach (report_categories($koneksi) as $category=>$categoryLabel) {
            $item = report_build($koneksi, 'per-item', report_filters($koneksi, [
                'template'=>'per-item','kategori'=>$category,'tahun_ajaran'=>'2026/2027',
                'bulan_awal'=>'09','bulan_akhir'=>'09','tahun_awal'=>2026,'tahun_akhir'=>2026,
                'tanggal_awal'=>$date,'tanggal_akhir'=>$date,
            ]));
            fixture_assert(isset($item['rows']), "Kategori {$categoryLabel} {$label} gagal dimuat.");
            if ($category === 'titipan_spp') {
                $ledger = report_build($koneksi, 'titipan-spp', report_filters($koneksi, [
                    'template'=>'titipan-spp','tanggal_awal'=>$date,'tanggal_akhir'=>$date,
                ]));
                fixture_assert(count($ledger['rows']) > 0, "Riwayat Titipan SPP {$label} kosong.");
            } else {
                fixture_assert(count($item['rows']) > 0, "Kategori {$categoryLabel} {$label} tidak terisi.");
            }
        }
    }
    $unitCounts[$unitId] = ['siswa'=>$students, 'bayar'=>$payments, 'tabungan'=>$savings];
    foreach (array_keys(report_registry()) as $template) {
        $source = [
            'template'=>$template,'tanggal_awal'=>$date,'tanggal_akhir'=>$date,
            'tahun_ajaran'=>'2026/2027','bulan_awal'=>'09','bulan_akhir'=>'09',
            'tahun_awal'=>2026,'tahun_akhir'=>2026,'kategori'=>'spp',
        ];
        if ($template === 'penerimaan') $source['kategori'] = 'semua';
        $report = report_build($koneksi, $template, report_filters($koneksi, $source));
        fixture_assert(isset($report['title'], $report['rows']), "Laporan {$template} {$label} gagal.");
        if ($template === 'tunggakan-siswa') {
            $html = report_principal_letter_html($report['rows'], $report['as_of_date'] ?? $date);
            fixture_assert(str_contains($html, report_e(unit_school_name($unitId))), "Surat {$label} memakai identitas salah.");
        }
    }
}
unit_set_context($koneksi, 0);
foreach (['siswa','bayar','tabungan'] as $table) {
    $all = fixture_count($koneksi, $table);
    $sum = array_sum(array_column($unitCounts, $table));
    fixture_assert($all === $sum, "Jumlah Semua Unit {$table} tidak sama dengan gabungan unit.");
}
foreach (array_keys(report_registry()) as $template) {
    $source = [
        'template'=>$template,'tanggal_awal'=>$date,'tanggal_akhir'=>$date,
        'tahun_ajaran'=>'2026/2027','bulan_awal'=>'09','bulan_akhir'=>'09',
        'tahun_awal'=>2026,'tahun_akhir'=>2026,'kategori'=>$template === 'penerimaan' ? 'semua' : 'spp',
    ];
    $report = report_build($koneksi, $template, report_filters($koneksi, $source));
    fixture_assert(isset($report['title'], $report['rows']), "Laporan {$template} Semua Unit gagal.");
}
echo 'OK: 11 laporan pada SD, SMP, SMA, Semua Unit; kategori per item, tabungan, surat, dan agregat unit valid.' . PHP_EOL;
