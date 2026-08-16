<?php
// ============================================
// laporan/global.php - Rekap harian kasir
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara', 'kasir']);

function global_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function global_money($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function global_month_code($value): string {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12',
    ];
    if (isset($map[$value])) return $map[$value];
    $number = (int)$value;
    return $number >= 1 && $number <= 12 ? str_pad((string)$number, 2, '0', STR_PAD_LEFT) : '01';
}

function global_date_param(string $key): string {
    $value = trim((string)($_GET[$key] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function global_transaction_date_label(string $startDate, string $endDate): string {
    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($startDate === $endDate) return date('d M Y', $startTs);
    if (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        return date('d', $startTs) . ' - ' . date('d M Y', $endTs);
    }
    return date('d M Y', $startTs) . ' - ' . date('d M Y', $endTs);
}

$legacyTanggal = global_date_param('tanggal');
$tanggalAwal = global_date_param('tanggal_awal') ?: $legacyTanggal ?: date('Y-m-d');
$tanggalAkhir = global_date_param('tanggal_akhir') ?: $legacyTanggal ?: $tanggalAwal;
if (strtotime($tanggalAwal) > strtotime($tanggalAkhir)) {
    [$tanggalAwal, $tanggalAkhir] = [$tanggalAkhir, $tanggalAwal];
}
$selectedOperator = trim((string)($_GET['operator'] ?? ''));
$start = $tanggalAwal . ' 00:00:00';
$end = date('Y-m-d H:i:s', strtotime($tanggalAkhir . ' +1 day'));
$periodMonth = date('m', strtotime($tanggalAwal));
$periodYear = date('Y', strtotime($tanggalAwal));
$periodMonthLegacy = (string)(int)$periodMonth;
$monthNames = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
];
$periodMonthName = $monthNames[$periodMonth];

$operators = $koneksi->query("
    SELECT id, nama, role
    FROM admin
    WHERE role IN ('admin', 'bendahara', 'kasir')
    ORDER BY FIELD(role, 'kasir', 'bendahara', 'admin'), nama
")->fetch_all(MYSQLI_ASSOC);

$whereOperator = '';
$operatorParams = [];
$operatorTypes = '';
if ($selectedOperator !== '' && ctype_digit($selectedOperator)) {
    $whereOperator = ' AND b.user_id = ?';
    $operatorTypes = 's';
    $operatorParams = [$selectedOperator];
}

$stmt = $koneksi->prepare("
    SELECT b.*, s.NAMA, s.NO_induk_diknas, s.KELAS AS KELAS_SISWA,
           COALESCE(op.nama, NULLIF(b.user_id, '')) AS operator_name
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    LEFT JOIN admin op ON op.id = CAST(b.user_id AS UNSIGNED)
    WHERE b.TGL_BYR >= ? AND b.TGL_BYR < ? $whereOperator
    ORDER BY b.TGL_BYR ASC, b.id ASC
");
$types = 'ss' . $operatorTypes;
$params = array_merge([$start, $end], $operatorParams);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$paymentIds = array_map(fn($row) => (int)$row['id'], $payments);
$duByPayment = [];
$otherByPayment = [];
if ($paymentIds) {
    $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
    $idTypes = str_repeat('i', count($paymentIds));

    $stmtDu = $koneksi->prepare("
        SELECT bayar_id, SUM(jumlah) AS jumlah
        FROM bayar_du
        WHERE bayar_id IN ($placeholders)
        GROUP BY bayar_id
    ");
    $stmtDu->bind_param($idTypes, ...$paymentIds);
    $stmtDu->execute();
    $resDu = $stmtDu->get_result();
    while ($row = $resDu->fetch_assoc()) {
        $duByPayment[(int)$row['bayar_id']] = (float)$row['jumlah'];
    }
    $stmtDu->close();

    $stmtOther = $koneksi->prepare("
        SELECT bayar_id, nama_biaya_snapshot, nominal_snapshot
        FROM bayar_biaya_lain
        WHERE bayar_id IN ($placeholders)
        ORDER BY bayar_id, urutan, id
    ");
    $stmtOther->bind_param($idTypes, ...$paymentIds);
    $stmtOther->execute();
    $resOther = $stmtOther->get_result();
    while ($row = $resOther->fetch_assoc()) {
        $otherByPayment[(int)$row['bayar_id']][] = $row;
    }
    $stmtOther->close();
}

$componentLabels = [
    'U_SPP' => 'Uang SPP',
    'U_KOMITE' => 'Komite',
    'U_PANGKAL' => 'Uang Pangkal',
    'U_BANGUNAN' => 'Uang Bangunan',
    'U_SERAGAM' => 'Uang Seragam',
    'U_KEGIATAN' => 'Uang Kegiatan',
    'U_MAKAN' => 'Uang Makan',
    'U_SORGA' => 'Uang Sorga',
    'U_INFAQ' => 'Uang Infaq',
];
$componentGroups = [];
$componentTotals = [];
$totalPayment = 0.0;
foreach ($payments as $payment) {
    $totalPayment += (float)$payment['total_jumlah'];
    foreach ($componentLabels as $column => $label) {
        $amount = (float)($payment[$column] ?? 0);
        if ($amount <= 0) continue;
        $componentGroups[$label][] = ['payment' => $payment, 'amount' => $amount];
        $componentTotals[$label] = ($componentTotals[$label] ?? 0) + $amount;
    }
    $duAmount = (float)($duByPayment[(int)$payment['id']] ?? 0);
    if ($duAmount > 0) {
        $componentGroups['Daftar Ulang'][] = ['payment' => $payment, 'amount' => $duAmount];
        $componentTotals['Daftar Ulang'] = ($componentTotals['Daftar Ulang'] ?? 0) + $duAmount;
    }
    foreach ($otherByPayment[(int)$payment['id']] ?? [] as $detail) {
        $label = (string)$detail['nama_biaya_snapshot'];
        $amount = (float)$detail['nominal_snapshot'];
        if ($amount <= 0) continue;
        $componentGroups[$label][] = ['payment' => $payment, 'amount' => $amount];
        $componentTotals[$label] = ($componentTotals[$label] ?? 0) + $amount;
    }
}

$tabWhereOperatorM = '';
$tabWhereOperatorK = '';
$tabParamsM = [$start, $end];
$tabParamsK = [$start, $end];
$tabTypesM = 'ss';
$tabTypesK = 'ss';
if ($selectedOperator !== '' && ctype_digit($selectedOperator)) {
    $tabWhereOperatorM = ' AND tm.user_id = ?';
    $tabWhereOperatorK = ' AND tk.user_id = ?';
    $tabParamsM[] = $selectedOperator;
    $tabParamsK[] = $selectedOperator;
    $tabTypesM .= 's';
    $tabTypesK .= 's';
}

$stmtTabM = $koneksi->prepare("
    SELECT tm.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, tm.TANGGAL, tm.MASUK AS nominal,
           COALESCE(op.nama, NULLIF(tm.user_id, '')) AS operator_name
    FROM transaksi_m tm
    JOIN siswa s ON s.NO_INDUK = tm.NO_INDUK
    LEFT JOIN admin op ON op.id = CAST(tm.user_id AS UNSIGNED)
    WHERE tm.TANGGAL >= ? AND tm.TANGGAL < ? $tabWhereOperatorM
    ORDER BY tm.TANGGAL ASC
");
$stmtTabM->bind_param($tabTypesM, ...$tabParamsM);
$stmtTabM->execute();
$tabMasukRows = $stmtTabM->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtTabM->close();

$stmtTabK = $koneksi->prepare("
    SELECT tk.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, tk.TANGGAL, tk.KELUAR AS nominal,
           COALESCE(op.nama, NULLIF(tk.user_id, '')) AS operator_name
    FROM transaksi_k tk
    JOIN siswa s ON s.NO_INDUK = tk.NO_INDUK
    LEFT JOIN admin op ON op.id = CAST(tk.user_id AS UNSIGNED)
    WHERE tk.TANGGAL >= ? AND tk.TANGGAL < ? $tabWhereOperatorK
    ORDER BY tk.TANGGAL ASC
");
$stmtTabK->bind_param($tabTypesK, ...$tabParamsK);
$stmtTabK->execute();
$tabKeluarRows = $stmtTabK->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtTabK->close();

$totalTabMasuk = array_sum(array_map(fn($row) => (float)$row['nominal'], $tabMasukRows));
$totalTabKeluar = array_sum(array_map(fn($row) => (float)$row['nominal'], $tabKeluarRows));

$stmtUnpaidSpp = $koneksi->prepare("
    SELECT *
    FROM (
        SELECT s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, s.SPP_PERBULAN AS tagihan,
               COALESCE(SUM(b.U_SPP), 0) AS sudah_bayar,
               GREATEST(s.SPP_PERBULAN - COALESCE(SUM(b.U_SPP), 0), 0) AS sisa
        FROM siswa s
        LEFT JOIN bayar b
            ON b.NO_INDUK = s.NO_INDUK
            AND b.TAHUN = ?
            AND (b.BULAN = ? OR b.BULAN = ? OR b.BULAN = ?)
        WHERE s.is_active = 1 AND s.SPP_PERBULAN > 0
        GROUP BY s.NO_INDUK, s.NO_induk_diknas, s.NAMA, s.KELAS, s.SPP_PERBULAN
    ) unpaid
    WHERE sisa > 0
    ORDER BY CAST(KELAS AS UNSIGNED), NAMA
");
$stmtUnpaidSpp->bind_param('ssss', $periodYear, $periodMonth, $periodMonthName, $periodMonthLegacy);
$stmtUnpaidSpp->execute();
$unpaidSppRows = $stmtUnpaidSpp->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtUnpaidSpp->close();

$tanggalLabel = global_transaction_date_label($tanggalAwal, $tanggalAkhir);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Laporan Global | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=6.1" />
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout">
  <?php include '../includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title"><h2>Laporan Global</h2><span class="breadcrumb">SistemSPP / Laporan / Global</span></div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>

    <div class="page-content">
      <div class="main-card report-global-hero">
        <div>
          <span class="recap-kicker">Rekap Harian Kasir</span>
          <h3>Ringkasan transaksi <?= global_e($tanggalLabel) ?></h3>
          <p>Pilih tanggal transaksi dari kalender untuk menarik data pembayaran: SPP, komite, daftar ulang, biaya lain, serta tabungan.</p>
        </div>
        <form method="GET" class="report-global-filter">
          <label class="field-row report-date-range-field"><span class="field-label">Tanggal transaksi</span>
            <span class="report-date-range-control">
              <input type="date" name="tanggal_awal" value="<?= global_e($tanggalAwal) ?>" aria-label="Tanggal transaksi mulai">
              <span>s/d</span>
              <input type="date" name="tanggal_akhir" value="<?= global_e($tanggalAkhir) ?>" aria-label="Tanggal transaksi sampai">
            </span>
          </label>
          <label class="field-row"><span class="field-label">Kasir / operator</span>
            <select class="field-input field-select" name="operator">
              <option value="">Semua operator</option>
              <?php foreach ($operators as $operator): ?>
              <option value="<?= (int)$operator['id'] ?>" <?= $selectedOperator === (string)$operator['id'] ? 'selected' : '' ?>>
                <?= global_e($operator['nama']) ?> - <?= global_e($operator['role']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn btn-primary" type="submit">Tampilkan</button>
        </form>
      </div>

      <div class="stats-grid report-stats-grid">
        <div class="stat-card stat-blue"><div class="stat-icon">$</div><div class="stat-info"><span class="stat-value"><?= global_money($totalPayment) ?></span><span class="stat-label">Total Pembayaran</span></div></div>
        <div class="stat-card stat-green"><div class="stat-icon">+</div><div class="stat-info"><span class="stat-value"><?= global_money($totalTabMasuk) ?></span><span class="stat-label">Tabungan Masuk</span></div></div>
        <div class="stat-card" style="--c:#ef4444;"><div class="stat-icon" style="background:rgba(239,68,68,0.15);color:#ef4444;">-</div><div class="stat-info"><span class="stat-value"><?= global_money($totalTabKeluar) ?></span><span class="stat-label">Tabungan Keluar</span></div></div>
        <div class="stat-card stat-purple"><div class="stat-icon">#</div><div class="stat-info"><span class="stat-value"><?= number_format(count($payments)) ?></span><span class="stat-label">Transaksi Pembayaran</span></div></div>
      </div>

      <div class="main-card report-component-summary">
        <div class="card-header"><h3 class="card-title">Total Per Komponen</h3></div>
        <div class="report-component-grid">
          <?php if (!$componentTotals): ?>
          <div class="report-empty-state">Belum ada pembayaran pada tanggal transaksi ini.</div>
          <?php else: foreach ($componentTotals as $label => $total): ?>
          <div class="report-component-card"><span><?= global_e($label) ?></span><strong><?= global_money($total) ?></strong><small><?= count($componentGroups[$label] ?? []) ?> siswa/transaksi</small></div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <?php foreach ($componentGroups as $label => $items): ?>
      <div class="main-card report-global-section">
        <div class="card-header laporan-detail-header">
          <div class="laporan-detail-heading"><h3 class="card-title">Yang Sudah Bayar <?= global_e($label) ?></h3><span class="badge-count"><?= count($items) ?> data</span></div>
        </div>
        <div class="table-container">
          <table class="payment-table">
            <thead><tr><th>No</th><th>Waktu</th><th>No. Induk</th><th>Nama</th><th class="kelas-col">Kelas</th><th>Nominal</th><th>Kasir</th><th>Struk</th></tr></thead>
            <tbody>
              <?php foreach ($items as $i => $item): $payment = $item['payment']; ?>
              <tr class="<?= $i % 2 === 0 ? 'row-highlight' : '' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= date('H:i', strtotime($payment['TGL_BYR'])) ?></td>
                <td><span class="badge-nis"><?= global_e($payment['NO_INDUK']) ?></span><?php if (!empty($payment['NO_induk_diknas'])): ?><small class="report-secondary-id">Diknas <?= global_e($payment['NO_induk_diknas']) ?></small><?php endif; ?></td>
                <td><?= global_e($payment['NAMA']) ?></td>
                <td class="kelas-col"><span class="kelas-badge">Kelas <?= global_e($payment['KELAS_SISWA']) ?></span></td>
                <td class="nominal"><?= global_money($item['amount']) ?></td>
                <td><?= global_e($payment['operator_name'] ?: '-') ?></td>
                <td class="aksi-col"><a class="btn-tbl btn-tbl-print" href="cetak_struk.php?id=<?= (int)$payment['id'] ?>" target="_blank" rel="noopener">Cetak</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="main-card report-global-section">
        <div class="card-header"><h3 class="card-title">Tabungan Hari Ini</h3></div>
        <div class="table-container">
          <table class="payment-table">
            <thead><tr><th>No</th><th>Waktu</th><th>Jenis</th><th>No. Induk</th><th>Nama</th><th class="kelas-col">Kelas</th><th>Nominal</th><th>Kasir</th></tr></thead>
            <tbody>
              <?php $tabRows = array_merge(array_map(fn($row) => $row + ['jenis' => 'Masuk'], $tabMasukRows), array_map(fn($row) => $row + ['jenis' => 'Keluar'], $tabKeluarRows)); ?>
              <?php if (!$tabRows): ?>
              <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted);">Belum ada transaksi tabungan pada tanggal transaksi ini.</td></tr>
              <?php else: foreach ($tabRows as $i => $row): ?>
              <tr class="<?= $i % 2 === 0 ? 'row-highlight' : '' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= date('H:i', strtotime($row['TANGGAL'])) ?></td>
                <td><span class="report-status-pill <?= $row['jenis'] === 'Masuk' ? 'is-paid' : 'is-unpaid' ?>"><?= global_e($row['jenis']) ?></span></td>
                <td><span class="badge-nis"><?= global_e($row['NO_INDUK']) ?></span></td>
                <td><?= global_e($row['NAMA']) ?></td>
                <td class="kelas-col"><span class="kelas-badge">Kelas <?= global_e($row['KELAS']) ?></span></td>
                <td class="nominal"><?= global_money($row['nominal']) ?></td>
                <td><?= global_e($row['operator_name'] ?: '-') ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="main-card report-global-section">
        <div class="card-header laporan-detail-header">
          <div class="laporan-detail-heading"><h3 class="card-title">SPP Belum Lunas - <?= global_e($periodMonthName . ' ' . $periodYear) ?></h3><span class="badge-count"><?= count($unpaidSppRows) ?> siswa</span></div>
        </div>
        <div class="table-container">
          <table class="payment-table">
            <thead><tr><th>No</th><th>No. Induk</th><th>Nama</th><th class="kelas-col">Kelas</th><th>Tagihan</th><th>Sudah Bayar</th><th>Sisa</th></tr></thead>
            <tbody>
              <?php if (!$unpaidSppRows): ?>
              <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">Semua SPP periode ini sudah lunas.</td></tr>
              <?php else: foreach ($unpaidSppRows as $i => $row): ?>
              <tr class="<?= $i % 2 === 0 ? 'row-highlight' : '' ?>">
                <td><?= $i + 1 ?></td>
                <td><span class="badge-nis"><?= global_e($row['NO_INDUK']) ?></span><?php if (!empty($row['NO_induk_diknas'])): ?><small class="report-secondary-id">Diknas <?= global_e($row['NO_induk_diknas']) ?></small><?php endif; ?></td>
                <td><?= global_e($row['NAMA']) ?></td>
                <td class="kelas-col"><span class="kelas-badge">Kelas <?= global_e($row['KELAS']) ?></span></td>
                <td class="nominal"><?= global_money($row['tagihan']) ?></td>
                <td class="nominal"><?= global_money($row['sudah_bayar']) ?></td>
                <td class="nominal report-remaining"><?= global_money($row['sisa']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </main>
</div>
<div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
<script src="../assets/js/app.js?v=3.1"></script>
<script>document.addEventListener('DOMContentLoaded', function(){ autoHideFlash(); });</script>
</body>
</html>
