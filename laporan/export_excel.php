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

$bln_names = ['1'=>'Januari','2'=>'Februari','3'=>'Maret','4'=>'April','5'=>'Mei','6'=>'Juni',
               '7'=>'Juli','8'=>'Agustus','9'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$bulan_label = $bln_names[$filter_bulan] ?? 'Unknown';

// Ambil data pembayaran
$stmt = $koneksi->prepare("
    SELECT s.NO_INDUK, s.NAMA, s.KELAS, b.BULAN, b.TAHUN,
           b.U_PANGKAL, b.U_BANGUNAN, b.U_SERAGAM, b.U_KEGIATAN,
           b.U_SPP, b.U_MAKAN, b.U_SORGA, b.U_INFAQ, b.U_KOMITE,
           b.total_jumlah, b.TGL_BYR
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

// Set header untuk download Excel
$filename = 'Laporan_SPP_' . $bulan_label . '_' . $filter_tahun . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
  <meta charset="UTF-8">
  <!--[if gte mso 9]>
  <xml><x:ExcelWorkbook><x:ExcelWorksheets>
    <x:ExcelWorksheet><x:Name>Pembayaran SPP</x:Name><x:WorksheetOptions><x:Print><x:FitToPage/></x:Print></x:WorksheetOptions></x:ExcelWorksheet>
  </x:ExcelWorksheets></x:ExcelWorkbook></xml>
  <![endif]-->
  <style>
    table { border-collapse: collapse; }
    th { background: #4c3d8f; color: white; font-weight: bold; padding: 8px 12px; border: 1px solid #999; }
    td { padding: 6px 12px; border: 1px solid #ccc; }
    .total-row { background: #f0f0ff; font-weight: bold; }
    .header-row { background: #6750a4; color: white; font-size: 14pt; font-weight: bold; }
    .section-header { background: #e8e0f5; font-weight: bold; font-size: 11pt; }
  </style>
</head>
<body>

<h2 style="font-family:Arial;color:#4c3d8f;">Laporan Keuangan Sistem SPP</h2>
<p style="font-family:Arial;">Periode: <?= $bulan_label . ' ' . $filter_tahun ?> | Dicetak: <?= date('d M Y H:i') ?></p>
<br>

<table>
  <tr class="header-row"><td colspan="2">RINCIAN KOMPONEN PEMBAYARAN</td></tr>
  <tr><th>Komponen</th><th>Total (Rp)</th></tr>
  <?php foreach ($komponen_rows as $komponen): ?>
  <tr><td><?= htmlspecialchars($komponen['nama']) ?></td><td><?= number_format((float)$komponen['total'],0,',','.') ?></td></tr>
  <?php endforeach; ?>
</table>

<br><br>

<!-- Sheet 1: Pembayaran SPP -->
<table>
  <tr class="header-row"><td colspan="8">REKAP PEMBAYARAN SPP — <?= strtoupper($bulan_label . ' ' . $filter_tahun) ?></td></tr>
  <tr>
    <th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th>
    <th>Bulan Bayar</th><th>Total Bayar (Rp)</th><th>Tanggal Bayar</th>
  </tr>
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
    <td><?= htmlspecialchars($r['BULAN']) ?> <?= htmlspecialchars($r['TAHUN']) ?></td>
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

<br><br>

<!-- Sheet 2: Tabungan -->
<table>
  <tr class="header-row"><td colspan="6">REKAP TABUNGAN — <?= strtoupper($bulan_label . ' ' . $filter_tahun) ?></td></tr>
  <tr>
    <th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th>
    <th>Tanggal</th><th>Jenis</th><th>Nominal (Rp)</th>
  </tr>
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

</body>
</html>
