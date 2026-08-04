<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

$monthNames = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
];

function recap_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function recap_money($value): string {
    $amount = (float)$value;
    return $amount > 0 ? 'Rp ' . number_format($amount, 0, ',', '.') : '—';
}

$classes = [];
$classResult = $koneksi->query("SELECT DISTINCT KELAS FROM siswa WHERE KELAS <> '' ORDER BY CAST(KELAS AS UNSIGNED), KELAS");
while ($classRow = $classResult->fetch_assoc()) {
    $classes[] = (string)$classRow['KELAS'];
}

$filterClass = trim((string)($_GET['kelas'] ?? ($classes[0] ?? '')));
if ($classes && !in_array($filterClass, $classes, true)) {
    $filterClass = $classes[0];
}
$filterMonth = str_pad((string)(int)($_GET['bulan'] ?? date('m')), 2, '0', STR_PAD_LEFT);
if (!isset($monthNames[$filterMonth])) {
    $filterMonth = date('m');
}
$filterYear = (int)($_GET['tahun'] ?? date('Y'));
if ($filterYear < 2000 || $filterYear > 2100) {
    $filterYear = (int)date('Y');
}
$search = trim((string)($_GET['q'] ?? ''));

$monthAliases = array_values(array_unique([
    $filterMonth,
    (string)(int)$filterMonth,
    $monthNames[$filterMonth],
]));
$monthSql = implode(', ', array_map(
    fn($value) => "'" . $koneksi->real_escape_string($value) . "'",
    $monthAliases
));
$yearSql = $koneksi->real_escape_string((string)$filterYear);
$periodSql = "b.TAHUN = '$yearSql' AND b.BULAN IN ($monthSql)";

$sql = "
    SELECT
        s.NO_INDUK,
        s.NAMA,
        s.KELAS,
        s.SPP_PERBULAN,
        COALESCE(p.transaksi, 0) AS transaksi,
        COALESCE(p.pangkal, 0) AS pangkal,
        COALESCE(p.bangunan, 0) AS bangunan,
        COALESCE(p.seragam, 0) AS seragam,
        COALESCE(p.kegiatan, 0) AS kegiatan,
        COALESCE(p.spp, 0) AS spp,
        COALESCE(p.komite, 0) AS komite,
        COALESCE(du.daftar_ulang, 0) AS daftar_ulang,
        COALESCE(lain.biaya_lain, 0) AS biaya_lain,
        COALESCE(tab.tabungan, 0) AS tabungan,
        COALESCE(p.total_bayar, 0) + COALESCE(tab.tabungan, 0) AS total_bayar
    FROM siswa s
    LEFT JOIN (
        SELECT
            b.NO_INDUK,
            COUNT(*) AS transaksi,
            SUM(b.U_PANGKAL) AS pangkal,
            SUM(b.U_BANGUNAN) AS bangunan,
            SUM(b.U_SERAGAM) AS seragam,
            SUM(b.U_KEGIATAN) AS kegiatan,
            SUM(b.U_SPP) AS spp,
            SUM(b.U_KOMITE) AS komite,
            SUM(b.total_jumlah) AS total_bayar
        FROM bayar b
        WHERE $periodSql
        GROUP BY b.NO_INDUK
    ) p ON p.NO_INDUK = s.NO_INDUK
    LEFT JOIN (
        SELECT bd.no_induk, SUM(bd.jumlah) AS daftar_ulang
        FROM bayar_du bd
        JOIN bayar b ON b.id = bd.bayar_id
        WHERE $periodSql
        GROUP BY bd.no_induk
    ) du ON du.no_induk = s.NO_INDUK
    LEFT JOIN (
        SELECT b.NO_INDUK, SUM(d.nominal_snapshot) AS biaya_lain
        FROM bayar_biaya_lain d
        JOIN bayar b ON b.id = d.bayar_id
        WHERE $periodSql
        GROUP BY b.NO_INDUK
    ) lain ON lain.NO_INDUK = s.NO_INDUK
    LEFT JOIN (
        SELECT b.NO_INDUK, SUM(t.MASUK) AS tabungan
        FROM transaksi_m t
        JOIN bayar b ON b.id = t.bayar_id
        WHERE $periodSql
        GROUP BY b.NO_INDUK
    ) tab ON tab.NO_INDUK = s.NO_INDUK
    WHERE s.is_active = 1 AND s.KELAS = ?
";

$params = [$filterClass];
$types = 's';
if ($search !== '') {
    $sql .= ' AND (s.NAMA LIKE ? OR s.NO_INDUK LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
$sql .= ' ORDER BY s.NAMA, s.NO_INDUK';

$stmt = $koneksi->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary = [
    'students' => count($rows),
    'paid_spp' => 0.0,
    'total' => 0.0,
    'paid_off' => 0,
];
foreach ($rows as &$row) {
    $sppBill = (float)$row['SPP_PERBULAN'];
    $sppPaid = (float)$row['spp'];
    if ($sppBill <= 0) {
        $row['status_label'] = 'Tanpa Tagihan';
        $row['status_class'] = 'is-neutral';
    } elseif ($sppPaid >= $sppBill) {
        $row['status_label'] = 'Lunas';
        $row['status_class'] = 'is-paid';
        $summary['paid_off']++;
    } elseif ($sppPaid > 0) {
        $row['status_label'] = 'Sebagian';
        $row['status_class'] = 'is-partial';
    } else {
        $row['status_label'] = 'Belum Bayar';
        $row['status_class'] = 'is-unpaid';
    }
    $summary['paid_spp'] += $sppPaid;
    $summary['total'] += (float)$row['total_bayar'];
}
unset($row);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Rekap Pembayaran per Kelas | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Rekap pembayaran siswa berdasarkan kelas dan periode." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=4.4" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs recap-no-print">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
      <div class="topbar recap-no-print">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Rekap Pembayaran per Kelas</h2>
          <span class="breadcrumb">SistemSPP / Laporan / Rekap Kelas</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <div class="main-card class-recap-card">
        <div class="card-title-row">
          <div>
            <div class="recap-kicker">Kelas <?= recap_e($filterClass ?: '-') ?></div>
            <div class="card-title">Rekap <?= recap_e($monthNames[$filterMonth]) ?> <?= $filterYear ?></div>
            <p class="recap-subtitle">Data ditarik langsung dari siswa aktif dan transaksi pembayaran pada periode terpilih.</p>
          </div>
          <button type="button" class="btn btn-warning recap-no-print" onclick="window.print()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Cetak Rekap
          </button>
        </div>

        <form method="GET" action="rekap_kelas.php" class="filter-bar recap-filter recap-no-print">
          <div class="search-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="q" placeholder="Cari nama / NIS..." value="<?= recap_e($search) ?>" />
          </div>
          <select class="field-input field-select filter-sel" name="kelas" required>
            <?php foreach ($classes as $class): ?>
            <option value="<?= recap_e($class) ?>" <?= $filterClass === $class ? 'selected' : '' ?>>Kelas <?= recap_e($class) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="field-input field-select filter-sel month-code-select" name="bulan">
            <?php foreach ($monthNames as $code => $label): ?>
            <option value="<?= $code ?>" data-label="<?= recap_e($label) ?>" <?= $filterMonth === $code ? 'selected' : '' ?>><?= recap_e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="field-input field-select filter-sel" name="tahun">
            <?php for ($year = (int)date('Y') - 2; $year <= (int)date('Y') + 1; $year++): ?>
            <option value="<?= $year ?>" <?= $filterYear === $year ? 'selected' : '' ?>><?= $year ?></option>
            <?php endfor; ?>
          </select>
          <button type="submit" class="btn btn-primary">Tampilkan</button>
          <a href="rekap_kelas.php" class="btn btn-ghost">Reset</a>
        </form>

        <div class="recap-summary-grid">
          <div class="recap-summary-item"><span>Siswa ditampilkan</span><strong><?= number_format($summary['students']) ?></strong></div>
          <div class="recap-summary-item"><span>SPP diterima</span><strong>Rp <?= number_format($summary['paid_spp'], 0, ',', '.') ?></strong></div>
          <div class="recap-summary-item"><span>Total penerimaan</span><strong>Rp <?= number_format($summary['total'], 0, ',', '.') ?></strong></div>
          <div class="recap-summary-item"><span>SPP lunas</span><strong><?= number_format($summary['paid_off']) ?> siswa</strong></div>
        </div>

        <div class="table-container class-recap-scroll">
          <table class="payment-table class-recap-table">
            <thead>
              <tr>
                <th rowspan="2">No</th>
                <th rowspan="2">NIS</th>
                <th rowspan="2">Nama Siswa</th>
                <th colspan="10" class="text-center">Pembayaran <?= recap_e($monthNames[$filterMonth]) ?> <?= $filterYear ?></th>
                <th rowspan="2">Status SPP</th>
              </tr>
              <tr>
                <th>Pangkal</th>
                <th>Bangunan</th>
                <th>Seragam</th>
                <th>Kegiatan</th>
                <th>SPP</th>
                <th>Komite</th>
                <th>Daftar Ulang</th>
                <th>Biaya Lain</th>
                <th>Tabungan</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
              <tr><td colspan="14" class="text-center recap-empty">Belum ada siswa aktif untuk filter ini.</td></tr>
              <?php else: ?>
              <?php foreach ($rows as $index => $row): ?>
              <tr>
                <td class="text-center"><?= $index + 1 ?></td>
                <td><span class="badge-nis"><?= recap_e($row['NO_INDUK']) ?></span></td>
                <td class="recap-student-name"><strong><?= recap_e($row['NAMA']) ?></strong><small>Kelas <?= recap_e($row['KELAS']) ?> · <?= (int)$row['transaksi'] ?> transaksi</small></td>
                <td class="recap-money"><?= recap_money($row['pangkal']) ?></td>
                <td class="recap-money"><?= recap_money($row['bangunan']) ?></td>
                <td class="recap-money"><?= recap_money($row['seragam']) ?></td>
                <td class="recap-money"><?= recap_money($row['kegiatan']) ?></td>
                <td class="recap-money recap-spp"><?= recap_money($row['spp']) ?></td>
                <td class="recap-money"><?= recap_money($row['komite']) ?></td>
                <td class="recap-money"><?= recap_money($row['daftar_ulang']) ?></td>
                <td class="recap-money"><?= recap_money($row['biaya_lain']) ?></td>
                <td class="recap-money"><?= recap_money($row['tabungan']) ?></td>
                <td class="recap-money recap-total"><?= recap_money($row['total_bayar']) ?></td>
                <td><span class="recap-status <?= recap_e($row['status_class']) ?>"><?= recap_e($row['status_label']) ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <script src="../assets/js/app.js?v=3.8"></script>
</body>
</html>
