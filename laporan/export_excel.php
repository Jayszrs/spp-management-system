<?php
// ============================================
// laporan/export_excel.php — Export ke Excel
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

$filter_bulan = (int)($_GET['bulan'] ?? date('m'));
$filter_tahun = (int)($_GET['tahun'] ?? date('Y'));
$download = isset($_GET['download']) && $_GET['download'] === '1';

$bln_names = ['1'=>'Januari','2'=>'Februari','3'=>'Maret','4'=>'April','5'=>'Mei','6'=>'Juni',
               '7'=>'Juli','8'=>'Agustus','9'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$bulan_label = $bln_names[$filter_bulan] ?? 'Unknown';

// Ambil data pembayaran
$stmt = $koneksi->prepare("
    SELECT s.NO_INDUK, s.NAMA, s.KELAS, b.BULAN, b.TAHUN,
           b.U_PANGKAL, b.U_BANGUNAN, b.U_SERAGAM, b.U_KEGIATAN,
           b.U_SPP, b.U_MAKAN, b.U_SORGA, b.U_INFAQ, b.U_KOMITE,
           b.sistem_pembayaran, b.total_jumlah, b.TGL_BYR
    FROM bayar b JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    WHERE MONTH(b.TGL_BYR) = ? AND YEAR(b.TGL_BYR) = ?
    ORDER BY b.TGL_BYR DESC
");
$stmt->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmtKomponen = $koneksi->prepare("
    SELECT SUM(U_PANGKAL) AS pangkal, SUM(U_BANGUNAN) AS bangunan,
           SUM(U_SERAGAM) AS seragam, SUM(U_KEGIATAN) AS kegiatan,
           SUM(U_SPP) AS spp, SUM(U_MAKAN) AS makan,
           SUM(U_SORGA) AS sorga, SUM(U_INFAQ) AS infaq,
           SUM(U_KOMITE) AS komite
    FROM bayar WHERE MONTH(TGL_BYR) = ? AND YEAR(TGL_BYR) = ?
");
$stmtKomponen->bind_param('ii', $filter_bulan, $filter_tahun);
$stmtKomponen->execute();
$komponenTetap = $stmtKomponen->get_result()->fetch_assoc();
$stmtKomponen->close();

$komponen_rows = [];
$komponenMap = [
    'Uang Pangkal' => 'pangkal', 'Uang Bangunan' => 'bangunan',
    'Uang Seragam' => 'seragam', 'Uang Kegiatan' => 'kegiatan',
    'Uang SPP' => 'spp', 'Uang Komite' => 'komite', 'Uang Makan' => 'makan',
    'Uang Sorga' => 'sorga', 'Uang Infaq' => 'infaq'
];
foreach ($komponenMap as $nama => $key) {
    if ((float)($komponenTetap[$key] ?? 0) > 0) {
        $komponen_rows[] = ['nama' => $nama, 'total' => $komponenTetap[$key]];
    }
}

$stmtBiayaLain = $koneksi->prepare("
    SELECT d.nama_biaya_snapshot AS nama, SUM(d.nominal_snapshot) AS total
    FROM bayar_biaya_lain d JOIN bayar b ON b.id = d.bayar_id
    WHERE MONTH(b.TGL_BYR) = ? AND YEAR(b.TGL_BYR) = ?
    GROUP BY d.nama_biaya_snapshot ORDER BY d.nama_biaya_snapshot ASC
");
$stmtBiayaLain->bind_param('ii', $filter_bulan, $filter_tahun);
$stmtBiayaLain->execute();
$komponen_rows = array_merge($komponen_rows, $stmtBiayaLain->get_result()->fetch_all(MYSQLI_ASSOC));
$stmtBiayaLain->close();

// Ambil data tabungan periode ini
$stmt2 = $koneksi->prepare("
    SELECT tm.NO_INDUK, s.NAMA, s.KELAS, tm.TANGGAL, tm.MASUK as nominal, 'masuk' as jenis
    FROM transaksi_m tm JOIN siswa s ON s.NO_INDUK = tm.NO_INDUK
    WHERE MONTH(tm.TANGGAL) = ? AND YEAR(tm.TANGGAL) = ?
    UNION ALL
    SELECT tk.NO_INDUK, s.NAMA, s.KELAS, tk.TANGGAL, tk.KELUAR as nominal, 'keluar' as jenis
    FROM transaksi_k tk JOIN siswa s ON s.NO_INDUK = tk.NO_INDUK
    WHERE MONTH(tk.TANGGAL) = ? AND YEAR(tk.TANGGAL) = ?
    ORDER BY TANGGAL DESC
");
$stmt2->bind_param('iiii', $filter_bulan, $filter_tahun, $filter_bulan, $filter_tahun);
$stmt2->execute();
$tab_rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

$preview_total_pembayaran = 0.0;
foreach ($rows as $row) {
    $preview_total_pembayaran += (float)$row['total_jumlah'];
}
$preview_total_tab_masuk = 0.0;
$preview_total_tab_keluar = 0.0;
foreach ($tab_rows as $tab) {
    if ($tab['jenis'] === 'masuk') {
        $preview_total_tab_masuk += (float)$tab['nominal'];
    } else {
        $preview_total_tab_keluar += (float)$tab['nominal'];
    }
}

// Set header untuk download Excel
$filename = 'Laporan_SPP_' . $bulan_label . '_' . $filter_tahun . '.xls';
if ($download) {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
}
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
  <meta charset="UTF-8">
  <title><?= $download ? htmlspecialchars($filename) : 'Preview Excel - ' . htmlspecialchars($bulan_label . ' ' . $filter_tahun) ?></title>
  <!--[if gte mso 9]>
  <xml><x:ExcelWorkbook><x:ExcelWorksheets>
    <x:ExcelWorksheet><x:Name>Pembayaran SPP</x:Name><x:WorksheetOptions><x:Print><x:FitToPage/></x:Print></x:WorksheetOptions></x:ExcelWorksheet>
  </x:ExcelWorksheets></x:ExcelWorkbook></xml>
  <![endif]-->
  <style>
    html { overflow-x: hidden; }
    body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: #f3f7f1; color: #17231c; overflow-x: hidden; }
    .no-print {
      position: sticky;
      top: 0;
      z-index: 10;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      padding: 10px 14px;
      background: #fff;
      border-bottom: 1px solid #cddccc;
    }
    .no-print a {
      border: 1px solid #1f6f3f;
      border-radius: 4px;
      padding: 8px 14px;
      color: #1f6f3f;
      background: #fff;
      font-size: 12px;
      font-weight: 700;
      text-decoration: none;
    }
    .no-print a.primary { background: #1f6f3f; color: #fff; }
    .preview-sheet {
      width: min(1040px, calc(100% - 32px));
      margin: 18px auto 28px;
      padding: 18px 22px 22px;
      background: #fff;
      border: 1px solid #cddccc;
      box-shadow: 0 14px 34px rgba(31, 111, 63, .10);
      overflow-x: auto;
    }
    .report-head {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: flex-start;
      margin-bottom: 14px;
      padding-bottom: 12px;
      border-bottom: 3px solid #f28c28;
    }
    .report-title {
      margin: 0 0 6px;
      color: #1f6f3f;
      font-size: 22px;
      line-height: 1.2;
    }
    .report-meta {
      margin: 0;
      color: #506053;
      font-size: 13px;
    }
    .period-pill {
      display: inline-block;
      padding: 7px 12px;
      border-radius: 4px;
      background: #fff4e6;
      color: #b45309;
      border: 1px solid #ffd6a3;
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
    }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 14px;
    }
    .summary-card {
      padding: 10px 12px;
      border: 1px solid #cfe4d2;
      border-left: 5px solid #1f6f3f;
      background: #f8fcf8;
    }
    .summary-card.orange { border-left-color: #f28c28; background: #fff9f1; }
    .summary-label {
      display: block;
      margin-bottom: 4px;
      color: #5a695d;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .summary-value {
      display: block;
      color: #17231c;
      font-size: 16px;
      font-weight: 800;
    }
    table {
      width: 100%;
      min-width: 760px;
      margin: 0 0 14px;
      border-collapse: collapse;
    }
    th { background: #1f6f3f; color: white; font-weight: bold; padding: 8px 10px; border: 1px solid #8eb99a; }
    td { padding: 7px 10px; border: 1px solid #cfd8cf; }
    .total-row { background: #fff4e6; color: #17231c; font-weight: bold; }
    .header-row { background: #f28c28; color: white; font-size: 12pt; font-weight: bold; }
    .section-header { background: #e6f3e8; font-weight: bold; font-size: 11pt; }
    .empty-row { color: #68746a; text-align: center; font-style: italic; background: #fbfdfb; }
    .table-card {
      margin: 0 0 14px;
    }
    .table-scroll {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .table-scroll table {
      margin-bottom: 0;
    }
    @media print {
      body { background: #fff; }
      .no-print { display: none !important; }
      .preview-sheet { width: auto; margin: 0; padding: 0; border: 0; box-shadow: none; }
    }
    @media (max-width: 760px) {
      .no-print {
        position: static;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        padding: 8px;
        background: #f7fbf7;
      }
      .no-print a {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 42px;
        padding: 8px 10px;
        border-radius: 7px;
        text-align: center;
      }
      .preview-sheet {
        width: 100%;
        margin: 0;
        padding: 14px 10px 18px;
        border-left: 0;
        border-right: 0;
        box-shadow: none;
        overflow-x: hidden;
      }
      .summary-grid {
        grid-template-columns: 1fr;
        gap: 8px;
      }
      .summary-card {
        padding: 11px 12px;
      }
      .report-head {
        display: block;
        margin-bottom: 12px;
        padding-bottom: 12px;
      }
      .report-title {
        font-size: 20px;
      }
      .period-pill { margin-top: 10px; }
      .table-card {
        border: 1px solid #cfe4d2;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
      }
      .table-scroll {
        overflow-x: auto;
      }
      table {
        min-width: 720px;
        table-layout: auto;
        font-size: 11px;
      }
      .table-card.compact table {
        min-width: 100%;
      }
      th,
      td {
        padding: 8px 8px;
        white-space: normal;
      }
      .header-row {
        font-size: 12px;
      }
      .empty-row {
        padding: 14px 10px;
      }
    }
  </style>
</head>
<body>

<?php if (!$download): ?>
<div class="no-print">
  <a class="primary" href="export_excel.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>&download=1">Download Excel</a>
  <a href="index.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>">Kembali</a>
</div>
<main class="preview-sheet">
<?php endif; ?>

<div class="report-head">
  <div>
    <h2 class="report-title">Laporan Keuangan Sistem SPP</h2>
    <p class="report-meta">Dicetak: <?= date('d M Y H:i') ?></p>
  </div>
  <span class="period-pill">Periode <?= $bulan_label . ' ' . $filter_tahun ?></span>
</div>

<?php if (!$download): ?>
<div class="summary-grid">
  <div class="summary-card">
    <span class="summary-label">Total Pembayaran</span>
    <span class="summary-value">Rp <?= number_format($preview_total_pembayaran, 0, ',', '.') ?></span>
  </div>
  <div class="summary-card orange">
    <span class="summary-label">Tabungan Masuk</span>
    <span class="summary-value">Rp <?= number_format($preview_total_tab_masuk, 0, ',', '.') ?></span>
  </div>
  <div class="summary-card orange">
    <span class="summary-label">Tabungan Keluar</span>
    <span class="summary-value">Rp <?= number_format($preview_total_tab_keluar, 0, ',', '.') ?></span>
  </div>
</div>
<?php endif; ?>

<div class="table-card compact">
<div class="table-scroll">
<table>
  <tr class="header-row"><td colspan="2">RINCIAN KOMPONEN PEMBAYARAN</td></tr>
  <tr><th>Komponen</th><th>Total (Rp)</th></tr>
  <?php if (empty($komponen_rows)): ?>
  <tr><td colspan="2" class="empty-row">Belum ada komponen pembayaran pada periode ini.</td></tr>
  <?php endif; ?>
  <?php foreach ($komponen_rows as $komponen): ?>
  <tr><td><?= htmlspecialchars($komponen['nama']) ?></td><td><?= number_format((float)$komponen['total'],0,',','.') ?></td></tr>
  <?php endforeach; ?>
</table>
</div>
</div>

<!-- Sheet 1: Pembayaran SPP -->
<div class="table-card">
<div class="table-scroll">
<table>
  <tr class="header-row"><td colspan="7">REKAP PEMBAYARAN SPP — <?= strtoupper($bulan_label . ' ' . $filter_tahun) ?></td></tr>
  <tr>
    <th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th>
    <th>Bulan Bayar / Sistem</th><th>Total Bayar (Rp)</th><th>Tanggal Bayar</th>
  </tr>
  <?php if (empty($rows)): ?>
  <tr><td colspan="7" class="empty-row">Belum ada transaksi pembayaran pada periode ini.</td></tr>
  <?php endif; ?>
  <?php
  $grand_total = 0;
  foreach ($rows as $i => $r):
    $grand_total += (float)$r['total_jumlah'];
  ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td><?= htmlspecialchars($r['NO_INDUK']) ?></td>
    <td><?= htmlspecialchars($r['NAMA']) ?></td>
    <td><?= htmlspecialchars($r['KELAS']) ?></td>
    <td><?= htmlspecialchars($r['BULAN']) ?> <?= htmlspecialchars($r['TAHUN']) ?><br>Sistem: <?= htmlspecialchars($r['sistem_pembayaran'] ?? 'VA') ?></td>
    <td><?= number_format((float)$r['total_jumlah'],0,',','.') ?></td>
    <td><?= date('d M Y', strtotime($r['TGL_BYR'])) ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="5">TOTAL</td>
    <td><?= number_format($grand_total,0,',','.') ?></td>
    <td></td>
  </tr>
</table>
</div>
</div>

<!-- Sheet 2: Tabungan -->
<div class="table-card">
<div class="table-scroll">
<table>
  <tr class="header-row"><td colspan="7">REKAP TABUNGAN — <?= strtoupper($bulan_label . ' ' . $filter_tahun) ?></td></tr>
  <tr>
    <th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th>
    <th>Tanggal</th><th>Jenis</th><th>Nominal (Rp)</th>
  </tr>
  <?php if (empty($tab_rows)): ?>
  <tr><td colspan="7" class="empty-row">Belum ada transaksi tabungan pada periode ini.</td></tr>
  <?php endif; ?>
  <?php
  $total_masuk_tab = 0;
  $total_keluar_tab = 0;
  foreach ($tab_rows as $i => $t):
    if ($t['jenis'] === 'masuk') $total_masuk_tab += (float)$t['nominal'];
    else $total_keluar_tab += (float)$t['nominal'];
  ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td><?= htmlspecialchars($t['NO_INDUK']) ?></td>
    <td><?= htmlspecialchars($t['NAMA']) ?></td>
    <td><?= htmlspecialchars($t['KELAS']) ?></td>
    <td><?= date('d M Y H:i', strtotime($t['TANGGAL'])) ?></td>
    <td><?= $t['jenis'] === 'masuk' ? '↑ Masuk' : '↓ Keluar' ?></td>
    <td><?= number_format((float)$t['nominal'],0,',','.') ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row"><td colspan="6">Total Masuk</td><td><?= number_format($total_masuk_tab,0,',','.') ?></td></tr>
  <tr class="total-row"><td colspan="6">Total Keluar</td><td><?= number_format($total_keluar_tab,0,',','.') ?></td></tr>
</table>
</div>
</div>

<?php if (!$download): ?>
</main>
<?php endif; ?>

</body>
</html>
