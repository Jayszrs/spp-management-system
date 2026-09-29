<?php

/** Smoke test pada db_spp demo lokal: semua INSERT dibatalkan sebelum selesai. */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/daftar_ulang.php';
require_once __DIR__ . '/../includes/spp_billing.php';
require_once __DIR__ . '/../includes/komite_billing.php';

if (getenv('SPP_TEST_ALLOW_LOCAL_ROLLBACK') !== '1' || $koneksi->query('SELECT DATABASE()')->fetch_row()[0] !== 'db_spp') {
    fwrite(STDERR, "SKIPPED: hanya untuk db_spp lokal dengan SPP_TEST_ALLOW_LOCAL_ROLLBACK=1.\n");
    exit(0);
}

function du_local_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function du_local_counts(mysqli $db): array {
    $counts = [];
    foreach (['bayar', 'bayar_du', 'spp_alokasi_batch', 'spp_alokasi', 'bayar_komite'] as $table) {
        $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM `' . $table . '`')->fetch_row()[0];
    }
    return $counts;
}

function du_local_insert_header(mysqli $db, string $nis, string $kelas, string $month, string $year,
    float $du, float $spp, float $komite): int {
    $date = date('Y-m-d H:i:s');
    $operator = 'UJI-ROLLBACK'; $method = 'Tunai';
    $total = $du + $spp + $komite;
    $stmt = $db->prepare('INSERT INTO bayar
        (NO_INDUK,KELAS,U_SPP,U_KOMITE,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,
         th_ajaran,kelas_du,total_jumlah,payment_link_version)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)');
    $academicYear = '2026/2027';
    $stmt->bind_param('ssddsssssssd', $nis, $kelas, $spp, $komite, $date, $month, $year,
        $operator, $method, $academicYear, $kelas, $total);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function du_local_run_case(mysqli $db, array $candidate, bool $withMonthly,
    string $month = '07', string $year = '2026'): void {
    $billId = (int)$candidate['id'];
    $nis = (string)$candidate['no_induk'];
    $kelas = (string)$candidate['kelas_snapshot'];
    $before = du_local_counts($db);
    $previous = du_find_bill_by_id($db, $billId);
    du_local_assert($previous !== null && $previous['sisa'] >= 2000, 'Tagihan Daftar Ulang untuk pengujian tidak tersedia.');

    $db->begin_transaction();
    try {
        $bill = du_require_selectable_bill($db, $billId, $nis, 0, true);
        du_assert_bill_snapshot($bill, $previous['nominal_tagihan'], $previous['terbayar']);
        $du = $withMonthly ? 2000.0 : 1000.0;
        du_assert_payment_amount($bill, $du);
        $spp = 0.0; $komite = 0.0;
        if ($withMonthly) {
            $status = spp_published_period_status($db, $nis, $month, $year);
            du_local_assert($status['status'] === 'payable', 'SPP terpilih tidak tersedia untuk uji gabungan.');
            $spp = (float)$status['selected']['remaining'];
            $komiteBill = komite_bill($db, $nis, $month, $year, true);
            $komite = (float)($komiteBill['remaining'] ?? 0);
            du_local_assert($komite > 0, 'Komite terpilih tidak tersedia untuk uji gabungan.');
        }
        $komiteBill = komite_validate_amount($db, $nis, $month, $year, $komite, $spp > 0);
        komite_validate_spp_pair($db, $nis, $month, $year, $komite, $spp > 0);

        $paymentId = du_local_insert_header($db, $nis, $kelas, $month, $year, $du, $spp, $komite);
        if ($spp > 0) {
            spp_allocate_payment($db, $nis, $paymentId, $month, $year, $spp, false,
                date('Y-m-d H:i:s'), 'Tunai', 'UJI-ROLLBACK');
        }
        komite_save_payment($db, $paymentId, $komiteBill, $komite);
        $academicYear = (string)$bill['tahun_ajaran'];
        $stmt = $db->prepare('INSERT INTO bayar_du
            (bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah)
            VALUES (?,?,?,?,?,?)');
        $stmt->bind_param('iisssd', $paymentId, $billId, $nis, $kelas, $academicYear, $du);
        $stmt->execute(); $stmt->close();

        $saved = du_find_bill_by_id($db, $billId);
        du_local_assert(abs($saved['terbayar'] - $previous['terbayar'] - $du) < .001,
            'Rincian Daftar Ulang tidak sesuai dengan pembayaran dalam transaksi.');
        $stmt = $db->prepare('SELECT total_jumlah FROM bayar WHERE id=?');
        $stmt->bind_param('i', $paymentId); $stmt->execute();
        $total = (float)$stmt->get_result()->fetch_row()[0]; $stmt->close();
        du_local_assert(abs($total - $du - $spp - $komite) < .001, 'Total header pembayaran tidak sesuai.');
    } finally {
        $db->rollback();
    }

    du_local_assert(du_local_counts($db) === $before, 'Jumlah transaksi berubah setelah rollback.');
    $after = du_find_bill_by_id($db, $billId);
    du_local_assert(abs($after['terbayar'] - $previous['terbayar']) < .001,
        'Saldo Daftar Ulang berubah setelah rollback.');
}

$result = $koneksi->query("SELECT t.id,t.no_induk,t.kelas_snapshot
    FROM tagihan_daftar_ulang t
    JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.is_active=1
    LEFT JOIN (SELECT tagihan_daftar_ulang_id,SUM(jumlah) paid FROM bayar_du GROUP BY tagihan_daftar_ulang_id) p
      ON p.tagihan_daftar_ulang_id=t.id
    WHERE t.status='open' AND t.tahun_ajaran_snapshot='2026/2027'
      AND t.kelas_snapshot IN ('5','6') AND t.nominal_tagihan-COALESCE(p.paid,0)>=2000
    ORDER BY t.id");
$candidates = $result->fetch_all(MYSQLI_ASSOC);
du_local_assert(count($candidates) > 0, 'Tidak ada Daftar Ulang kelas 5–6 yang terbuka pada data lokal.');
du_local_run_case($koneksi, $candidates[0], false);

$graduate = $koneksi->query("SELECT t.id,t.no_induk,t.kelas_snapshot
    FROM tagihan_daftar_ulang t
    JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.is_active=0
    JOIN siswa_tahun_ajaran sta ON sta.no_induk=t.no_induk AND sta.status='lulus'
    LEFT JOIN (SELECT tagihan_daftar_ulang_id,SUM(jumlah) paid FROM bayar_du GROUP BY tagihan_daftar_ulang_id) p
      ON p.tagihan_daftar_ulang_id=t.id
    WHERE t.status='open' AND t.tahun_ajaran_snapshot='2026/2027'
      AND t.kelas_snapshot='6' AND t.nominal_tagihan-COALESCE(p.paid,0)>=2000
    ORDER BY t.id LIMIT 1")->fetch_assoc();
du_local_assert($graduate !== null, 'Tidak ada lulusan kelas 6 dengan Daftar Ulang terbuka.');
du_local_run_case($koneksi, $graduate, false);

$combined = null;
$combinedMonth = '';
$combinedYear = '';
$periods = [['07','2026'],['08','2026'],['09','2026'],['10','2026'],['11','2026'],['12','2026'],
    ['01','2027'],['02','2027'],['03','2027'],['04','2027'],['05','2027'],['06','2027']];
foreach ($candidates as $candidate) {
    foreach ($periods as [$month, $year]) {
        $status = spp_published_period_status($koneksi, (string)$candidate['no_induk'], $month, $year);
        $bill = komite_bill($koneksi, (string)$candidate['no_induk'], $month, $year);
        if ($status['status'] === 'payable' && (float)($bill['remaining'] ?? 0) > 0) {
            $combined = $candidate;
            $combinedMonth = $month;
            $combinedYear = $year;
            break 2;
        }
    }
}
du_local_assert($combined !== null, 'Tidak ada siswa dengan Daftar Ulang, SPP, dan Komite yang terbuka.');
du_local_run_case($koneksi, $combined, true, $combinedMonth, $combinedYear);

echo "OK: Daftar Ulang siswa aktif/lulusan dan gabungan SPP/Komite tersimpan sementara, lalu seluruhnya rollback.\n";
