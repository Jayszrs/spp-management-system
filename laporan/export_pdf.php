<?php
// ============================================
// laporan/export_pdf.php - Print-ready receipt report
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

$filter_bulan = (int)($_GET['bulan'] ?? date('m'));
$filter_tahun = (int)($_GET['tahun'] ?? date('Y'));

$bln_names = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$bulan_label = $bln_names[$filter_bulan] ?? '';
$periode = trim($bulan_label . ' ' . $filter_tahun);
$printed_at = date('d/m/Y H:i:s');
$receipt_no = 'LAP-' . str_pad((string)$filter_bulan, 2, '0', STR_PAD_LEFT) . '-' . $filter_tahun . '-' . date('His');

$stmt = $koneksi->prepare("
    SELECT s.NO_INDUK, s.NAMA, s.KELAS, b.BULAN, b.TAHUN,
           b.U_PANGKAL, b.U_BANGUNAN, b.U_SERAGAM, b.U_KEGIATAN,
           b.U_SPP, b.U_MAKAN, b.U_SORGA, b.U_INFAQ, b.U_LAIN,
           b.total_jumlah, b.TGL_BYR
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    WHERE MONTH(b.TGL_BYR) = ? AND YEAR(b.TGL_BYR) = ?
    ORDER BY b.TGL_BYR DESC
");
$stmt->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt_rek = $koneksi->prepare("
    SELECT SUM(U_PANGKAL) as pangkal, SUM(U_BANGUNAN) as bangunan,
           SUM(U_SERAGAM) as seragam, SUM(U_KEGIATAN) as kegiatan,
           SUM(U_SPP) as spp, SUM(U_MAKAN) as makan,
           SUM(U_SORGA) as sorga, SUM(U_INFAQ) as infaq,
           SUM(U_LAIN) as lain, SUM(total_jumlah) as total
    FROM bayar
    WHERE MONTH(TGL_BYR) = ? AND YEAR(TGL_BYR) = ?
");
$stmt_rek->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt_rek->execute();
$rek = $stmt_rek->get_result()->fetch_assoc();
$stmt_rek->close();

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

function rp($n) {
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

function month_code($value) {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

$component_map = [
    'Uang Pangkal' => 'pangkal',
    'Uang Bangunan' => 'bangunan',
    'Uang Seragam' => 'seragam',
    'Uang Kegiatan' => 'kegiatan',
    'Uang SPP' => 'spp',
    'Uang Makan' => 'makan',
    'Uang Sorga' => 'sorga',
    'Uang Infaq' => 'infaq',
    'Uang Lain' => 'lain'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Kwitansi Laporan SPP - <?= htmlspecialchars($periode) ?></title>
  <style>
    @page { size: A4; margin: 14mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: #eef2f1;
      color: #16241d;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 11px;
      line-height: 1.45;
    }
    .no-print {
      position: sticky;
      top: 0;
      z-index: 10;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      padding: 10px 14px;
      background: #f8faf9;
      border-bottom: 1px solid #dbe7df;
    }
    .no-print button,
    .no-print a {
      border: 0;
      border-radius: 7px;
      padding: 9px 16px;
      font-weight: 700;
      font-size: 12px;
      text-decoration: none;
      cursor: pointer;
    }
    .no-print button { background: #168a46; color: #fff; }
    .no-print a { background: #e7f4ec; color: #116b34; }

    .sheet {
      width: 190mm;
      min-height: 269mm;
      margin: 14px auto;
      background: #fff;
      border: 1px solid #d8e3dd;
      box-shadow: 0 18px 50px rgba(19, 86, 48, 0.13);
      padding: 12mm;
    }
    .receipt {
      border: 1.5px solid #168a46;
      border-radius: 10px;
      overflow: hidden;
    }
    .receipt-head {
      display: table;
      width: 100%;
      padding: 16px 18px;
      border-bottom: 1px solid #cfe2d6;
      background: #f7fcf9;
    }
    .brand, .doc-meta { display: table-cell; vertical-align: top; }
    .brand { width: 62%; }
    .logo {
      float: left;
      width: 48px;
      height: 48px;
      border: 1px solid #dbe7df;
      border-radius: 8px;
      padding: 4px;
      margin-right: 12px;
      object-fit: contain;
    }
    .school-name {
      margin: 0 0 2px;
      font-size: 18px;
      line-height: 1.1;
      color: #116b34;
      letter-spacing: .2px;
    }
    .school-sub, .school-address {
      margin: 0;
      color: #607368;
      font-size: 10px;
    }
    .doc-meta {
      width: 38%;
      text-align: right;
      font-size: 10px;
      color: #40564a;
    }
    .doc-title {
      display: inline-block;
      margin-bottom: 8px;
      padding: 5px 10px;
      border-radius: 999px;
      background: #168a46;
      color: #fff;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .5px;
    }
    .meta-line { margin: 2px 0; }
    .receipt-body { padding: 18px; }
    .title-row {
      display: table;
      width: 100%;
      margin-bottom: 14px;
    }
    .title-left, .title-right { display: table-cell; vertical-align: middle; }
    .title-left h2 {
      margin: 0;
      color: #17261e;
      font-size: 18px;
      letter-spacing: .4px;
      text-transform: uppercase;
    }
    .title-left p { margin: 2px 0 0; color: #6b7e72; }
    .stamp {
      float: right;
      width: 82px;
      height: 82px;
      border: 2px solid #168a46;
      border-radius: 50%;
      color: #168a46;
      font-weight: 800;
      text-align: center;
      padding-top: 24px;
      transform: rotate(-8deg);
      opacity: .78;
      line-height: 1.15;
    }
    .summary {
      display: table;
      width: 100%;
      border: 1px solid #d7e7de;
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 14px;
    }
    .summary-box {
      display: table-cell;
      width: 25%;
      padding: 12px;
      border-right: 1px solid #d7e7de;
      background: #fbfefc;
    }
    .summary-box:last-child { border-right: 0; }
    .summary-label {
      display: block;
      color: #6b7e72;
      font-size: 9px;
      font-weight: 800;
      letter-spacing: .5px;
      text-transform: uppercase;
    }
    .summary-value {
      display: block;
      margin-top: 3px;
      color: #116b34;
      font-size: 16px;
      font-weight: 800;
    }
    .summary-value.red { color: #d32f2f; }
    .summary-value.dark { color: #17261e; }
    .section-title {
      margin: 16px 0 7px;
      padding-bottom: 5px;
      border-bottom: 1px solid #d7e7de;
      color: #116b34;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: .4px;
      text-transform: uppercase;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 10px;
      page-break-inside: auto;
    }
    th {
      padding: 7px 8px;
      background: #e7f4ec;
      border: 1px solid #cfe2d6;
      color: #116b34;
      font-size: 10px;
      text-align: left;
      text-transform: uppercase;
    }
    td {
      padding: 7px 8px;
      border: 1px solid #dde9e2;
      color: #24352c;
      vertical-align: top;
    }
    tbody tr:nth-child(even) td { background: #fbfefc; }
    .nominal { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .total-row td {
      background: #f0faf4 !important;
      font-weight: 800;
      color: #116b34;
    }
    .empty {
      padding: 18px;
      color: #6b7e72;
      text-align: center;
      font-style: italic;
    }
    .footer-row {
      display: table;
      width: 100%;
      margin-top: 28px;
    }
    .note, .signature { display: table-cell; vertical-align: top; }
    .note {
      width: 58%;
      color: #607368;
      font-size: 10px;
    }
    .note-box {
      border: 1px dashed #b7d0c0;
      border-radius: 8px;
      padding: 10px;
      min-height: 76px;
      background: #fbfefc;
    }
    .signature {
      width: 42%;
      text-align: center;
      color: #17261e;
    }
    .sign-space { height: 58px; }
    .sign-line {
      width: 170px;
      margin: 0 auto;
      border-top: 1px solid #17261e;
      padding-top: 5px;
      font-weight: 700;
    }
    .tiny-footer {
      margin-top: 18px;
      padding-top: 8px;
      border-top: 1px solid #d7e7de;
      color: #8aa095;
      font-size: 9px;
      text-align: center;
    }
    @media print {
      body { background: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
      .no-print { display: none !important; }
      .sheet {
        width: auto;
        min-height: auto;
        margin: 0;
        padding: 0;
        border: 0;
        box-shadow: none;
      }
      .receipt { border-radius: 8px; }
      a { color: inherit; text-decoration: none; }
    }
  </style>
</head>
<body>
  <div class="no-print">
    <button onclick="window.print()">Cetak / Save as PDF</button>
    <a href="index.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>">Kembali</a>
  </div>

  <main class="sheet">
    <section class="receipt">
      <header class="receipt-head">
        <div class="brand">
          <img class="logo" src="../assets/img/school-logo.png" alt="Logo Sekolah">
          <h1 class="school-name">SistemSPP</h1>
          <p class="school-sub">Laporan Administrasi Pembayaran Siswa</p>
          <p class="school-address">Dokumen rekap periode pembayaran sekolah</p>
        </div>
        <div class="doc-meta">
          <div class="doc-title">KWITANSI LAPORAN</div>
          <div class="meta-line"><strong>No:</strong> <?= htmlspecialchars($receipt_no) ?></div>
          <div class="meta-line"><strong>Periode:</strong> <?= htmlspecialchars($periode) ?></div>
          <div class="meta-line"><strong>Dicetak:</strong> <?= htmlspecialchars($printed_at) ?></div>
        </div>
      </header>

      <div class="receipt-body">
        <div class="title-row">
          <div class="title-left">
            <h2>Bukti Rekap Pembayaran</h2>
            <p>Ringkasan penerimaan SPP dan tabungan siswa untuk periode <?= htmlspecialchars($periode) ?>.</p>
          </div>
          <div class="title-right">
            <div class="stamp">SAH<br>SISTEM SPP</div>
          </div>
        </div>

        <div class="summary">
          <div class="summary-box">
            <span class="summary-label">Total Pembayaran</span>
            <span class="summary-value"><?= rp($rek['total'] ?? 0) ?></span>
          </div>
          <div class="summary-box">
            <span class="summary-label">Jumlah Transaksi</span>
            <span class="summary-value dark"><?= count($rows) ?></span>
          </div>
          <div class="summary-box">
            <span class="summary-label">Tabungan Masuk</span>
            <span class="summary-value"><?= rp($tab_masuk) ?></span>
          </div>
          <div class="summary-box">
            <span class="summary-label">Tabungan Keluar</span>
            <span class="summary-value red"><?= rp($tab_keluar) ?></span>
          </div>
        </div>

        <h3 class="section-title">Rincian Komponen Pembayaran</h3>
        <table>
          <thead>
            <tr>
              <th style="width: 8%;">No</th>
              <th>Komponen</th>
              <th class="nominal" style="width: 30%;">Nominal</th>
            </tr>
          </thead>
          <tbody>
            <?php $no = 1; foreach ($component_map as $label => $key): ?>
              <?php if ((float)($rek[$key] ?? 0) <= 0) continue; ?>
              <tr>
                <td class="center"><?= $no++ ?></td>
                <td><?= htmlspecialchars($label) ?></td>
                <td class="nominal"><?= rp($rek[$key]) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($no === 1): ?>
              <tr><td colspan="3" class="empty">Tidak ada komponen pembayaran pada periode ini.</td></tr>
            <?php endif; ?>
            <tr class="total-row">
              <td colspan="2">Total Penerimaan</td>
              <td class="nominal"><?= rp($rek['total'] ?? 0) ?></td>
            </tr>
          </tbody>
        </table>

        <h3 class="section-title">Detail Transaksi</h3>
        <table>
          <thead>
            <tr>
              <th style="width: 6%;">No</th>
              <th style="width: 13%;">No. Induk</th>
              <th>Nama Siswa</th>
              <th style="width: 10%;">Kelas</th>
              <th style="width: 14%;">Bulan</th>
              <th class="nominal" style="width: 18%;">Total</th>
              <th style="width: 14%;">Tanggal</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="empty">Tidak ada transaksi pembayaran pada periode ini.</td></tr>
            <?php else: ?>
              <?php $grand = 0; foreach ($rows as $i => $r): $grand += (float)$r['total_jumlah']; ?>
                <tr>
                  <td class="center"><?= $i + 1 ?></td>
                  <td><?= htmlspecialchars($r['NO_INDUK']) ?></td>
                  <td><?= htmlspecialchars($r['NAMA']) ?></td>
                  <td><?= htmlspecialchars($r['KELAS']) ?></td>
                  <td><?= htmlspecialchars(month_code($r['BULAN'])) ?> / <?= htmlspecialchars($r['TAHUN']) ?></td>
                  <td class="nominal"><?= rp($r['total_jumlah']) ?></td>
                  <td><?= date('d/m/Y', strtotime($r['TGL_BYR'])) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr class="total-row">
                <td colspan="5">Grand Total</td>
                <td class="nominal"><?= rp($grand) ?></td>
                <td></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="footer-row">
          <div class="note">
            <div class="note-box">
              <strong>Catatan:</strong><br>
              Dokumen ini dicetak dari SistemSPP dan digunakan sebagai bukti rekap administrasi pembayaran pada periode terkait.
            </div>
          </div>
          <div class="signature">
            <p><?= date('d/m/Y') ?></p>
            <p>Petugas Administrasi,</p>
            <div class="sign-space"></div>
            <div class="sign-line"><?= htmlspecialchars($_SESSION['admin_nama'] ?? 'Administrator') ?></div>
          </div>
        </div>

        <div class="tiny-footer">
          SistemSPP - <?= htmlspecialchars($receipt_no) ?> - Dicetak <?= htmlspecialchars($printed_at) ?>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
