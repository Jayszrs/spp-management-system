<?php
// ============================================
// laporan/export_pdf.php — Print-ready PDF Report
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

$filter_bulan = (int)($_GET['bulan'] ?? date('m'));
$filter_tahun = (int)($_GET['tahun'] ?? date('Y'));

$bln_names = ['1'=>'Januari','2'=>'Februari','3'=>'Maret','4'=>'April','5'=>'Mei','6'=>'Juni',
               '7'=>'Juli','8'=>'Agustus','9'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$bulan_label = $bln_names[$filter_bulan] ?? '';

// Ambil data pembayaran
$stmt = $koneksi->prepare("
    SELECT s.NO_INDUK, s.NAMA, s.KELAS, b.BULAN, b.TAHUN,
           b.U_PANGKAL, b.U_BANGUNAN, b.U_SERAGAM, b.U_KEGIATAN,
           b.U_SPP, b.U_MAKAN, b.U_SORGA, b.U_INFAQ, b.U_LAIN,
           b.total_jumlah, b.TGL_BYR
    FROM bayar b JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    WHERE MONTH(b.TGL_BYR) = ? AND YEAR(b.TGL_BYR) = ?
    ORDER BY b.TGL_BYR DESC
");
$stmt->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Rekap per komponen
$stmt_rek = $koneksi->prepare("
    SELECT SUM(U_PANGKAL) as pangkal, SUM(U_BANGUNAN) as bangunan,
           SUM(U_SERAGAM) as seragam, SUM(U_KEGIATAN) as kegiatan,
           SUM(U_SPP) as spp, SUM(U_MAKAN) as makan,
           SUM(U_SORGA) as sorga, SUM(U_INFAQ) as infaq,
           SUM(U_LAIN) as lain, SUM(total_jumlah) as total
    FROM bayar WHERE MONTH(TGL_BYR) = ? AND YEAR(TGL_BYR) = ?
");
$stmt_rek->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt_rek->execute();
$rek = $stmt_rek->get_result()->fetch_assoc();
$stmt_rek->close();

// Tabungan
$stmt_tm = $koneksi->prepare("SELECT COALESCE(SUM(MASUK),0) as t FROM transaksi_m WHERE MONTH(TANGGAL)=? AND YEAR(TANGGAL)=?");
$stmt_tm->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt_tm->execute();
$tab_masuk = (float)$stmt_tm->get_result()->fetch_assoc()['t'];
$stmt_tm->close();

$stmt_tk = $koneksi->prepare("SELECT COALESCE(SUM(KELUAR),0) as t FROM transaksi_k WHERE MONTH(TANGGAL)=? AND YEAR(TANGGAL)=?");
$stmt_tk->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt_tk->execute();
$tab_keluar = (float)$stmt_tk->get_result()->fetch_assoc()['t'];
$stmt_tk->close();

function rp($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Laporan SPP — <?= $bulan_label ?> <?= $filter_tahun ?></title>
  <style>
    @page { size: A4; margin: 20mm 15mm; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Arial', sans-serif; font-size: 11pt; color: #1e1e2e; background: #fff; }

    .print-header { text-align: center; border-bottom: 3px solid #6750a4; padding-bottom: 12px; margin-bottom: 20px; }
    .print-header h1 { font-size: 16pt; color: #4c3d8f; }
    .print-header p { font-size: 10pt; color: #555; margin-top: 4px; }

    .section-title { font-size: 12pt; font-weight: bold; color: #4c3d8f; margin: 20px 0 8px 0;
      border-left: 4px solid #6750a4; padding-left: 8px; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    th { background: #4c3d8f; color: white; padding: 7px 10px; text-align: left; font-size: 10pt; border: 1px solid #3d3070; }
    td { padding: 6px 10px; font-size: 10pt; border: 1px solid #d4d4e8; }
    tr:nth-child(even) { background: #f5f3ff; }
    .total-row { background: #ede9fe !important; font-weight: bold; }
    .nominal { text-align: right; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 9pt; font-weight: 600; }
    .badge-masuk { background: #dcfce7; color: #16a34a; }
    .badge-keluar { background: #fee2e2; color: #dc2626; }

    .summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px; }
    .summary-box { border: 1px solid #c4b5fd; border-radius: 8px; padding: 12px 16px; background: #faf5ff; }
    .summary-box .label { font-size: 9pt; color: #7c3aed; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .summary-box .value { font-size: 14pt; font-weight: 800; color: #4c3d8f; margin-top: 4px; }

    .footer { text-align: right; margin-top: 30px; font-size: 9pt; color: #888; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    .sign-area { display: flex; justify-content: flex-end; margin-top: 40px; }
    .sign-box { text-align: center; min-width: 160px; }
    .sign-box .sign-line { border-top: 1px solid #333; margin-top: 60px; padding-top: 4px; font-size: 10pt; }

    @media print {
      .no-print { display: none !important; }
      body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    }
  </style>
</head>
<body>

<!-- Print Button (hilang saat print) -->
<div class="no-print" style="text-align:right;padding:12px 16px;background:#f5f3ff;border-bottom:1px solid #e5e7eb;">
  <button onclick="window.print()" style="background:#6750a4;color:white;border:none;padding:10px 24px;border-radius:8px;font-size:13px;cursor:pointer;font-weight:600;">
    🖨️ Cetak / Save as PDF
  </button>
  <a href="index.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>" style="margin-left:10px;background:#e8e0f5;color:#4c3d8f;text-decoration:none;border:none;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;display:inline-block;">
    ← Kembali
  </a>
</div>

<!-- Header -->
<div class="print-header">
  <h1>LAPORAN KEUANGAN SISTEM SPP</h1>
  <p>Periode: <?= $bulan_label . ' ' . $filter_tahun ?> | Dicetak: <?= date('d M Y, H:i') ?> WIB</p>
</div>

<!-- Summary -->
<div class="summary-grid">
  <div class="summary-box">
    <div class="label">Total Pembayaran SPP</div>
    <div class="value"><?= rp($rek['total'] ?? 0) ?></div>
  </div>
  <div class="summary-box">
    <div class="label">Jumlah Transaksi</div>
    <div class="value"><?= count($rows) ?> transaksi</div>
  </div>
  <div class="summary-box">
    <div class="label">Tabungan Masuk</div>
    <div class="value" style="color:#16a34a;"><?= rp($tab_masuk) ?></div>
  </div>
  <div class="summary-box">
    <div class="label">Tabungan Keluar</div>
    <div class="value" style="color:#dc2626;"><?= rp($tab_keluar) ?></div>
  </div>
</div>

<!-- Rekap Komponen -->
<p class="section-title">Rekap Komponen Pembayaran</p>
<table>
  <thead><tr><th>Komponen</th><th class="nominal">Total (Rp)</th></tr></thead>
  <tbody>
    <?php
    $komp_map = ['Uang Pangkal'=>'pangkal','Uang Bangunan'=>'bangunan','Uang Seragam'=>'seragam',
                 'Uang Kegiatan'=>'kegiatan','Uang SPP'=>'spp','Uang Makan'=>'makan',
                 'Uang Sorga'=>'sorga','Uang Infaq'=>'infaq','Uang Lain'=>'lain'];
    foreach ($komp_map as $label => $key):
      if ((float)($rek[$key] ?? 0) <= 0) continue;
    ?>
    <tr><td><?= $label ?></td><td class="nominal"><?= rp($rek[$key]) ?></td></tr>
    <?php endforeach; ?>
    <tr class="total-row"><td><strong>TOTAL</strong></td><td class="nominal"><strong><?= rp($rek['total'] ?? 0) ?></strong></td></tr>
  </tbody>
</table>

<!-- Detail Transaksi -->
<p class="section-title">Detail Transaksi Pembayaran SPP</p>
<table>
  <thead>
    <tr>
      <th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th>
      <th>Bulan Bayar</th><th class="nominal">Total (Rp)</th><th>Tgl Bayar</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($rows)): ?>
    <tr><td colspan="7" style="text-align:center;padding:20px;color:#888;">Tidak ada data.</td></tr>
    <?php else: ?>
    <?php
    $grand = 0;
    foreach ($rows as $i => $r):
      $grand += (float)$r['total_jumlah'];
    ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= htmlspecialchars($r['NO_INDUK']) ?></td>
      <td><?= htmlspecialchars($r['NAMA']) ?></td>
      <td><?= htmlspecialchars($r['KELAS']) ?></td>
      <td><?= htmlspecialchars($r['BULAN']) ?> <?= htmlspecialchars($r['TAHUN']) ?></td>
      <td class="nominal"><?= rp($r['total_jumlah']) ?></td>
      <td><?= date('d M Y', strtotime($r['TGL_BYR'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
      <td colspan="5"><strong>GRAND TOTAL</strong></td>
      <td class="nominal"><strong><?= rp($grand) ?></strong></td>
      <td></td>
    </tr>
    <?php endif; ?>
  </tbody>
</table>

<!-- Tanda Tangan -->
<div class="sign-area">
  <div class="sign-box">
    <p><?= date('d M Y') ?></p>
    <div class="sign-line">
      <?= htmlspecialchars($_SESSION['admin_nama'] ?? 'Bendahara') ?>
    </div>
  </div>
</div>

<div class="footer">
  Dicetak oleh sistem SistemSPP &mdash; <?= date('d/m/Y H:i:s') ?>
</div>

</body>
</html>
