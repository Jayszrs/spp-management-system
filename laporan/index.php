<?php
// ============================================
// laporan/index.php — Rekap Laporan Keuangan
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$filter_bulan = $_GET['bulan'] ?? date('m');
$filter_tahun = $_GET['tahun'] ?? date('Y');

// ── Rekap Pembayaran SPP per bulan
$stmt = $koneksi->prepare("
    SELECT COUNT(*) as jml_tx,
           SUM(b.U_PANGKAL) as pangkal, SUM(b.U_BANGUNAN) as bangunan,
           SUM(b.U_SERAGAM) as seragam, SUM(b.U_KEGIATAN) as kegiatan,
           SUM(b.U_SPP) as spp, SUM(b.U_MAKAN) as makan,
           SUM(b.U_SORGA) as sorga, SUM(b.U_INFAQ) as infaq,
           SUM(b.U_KOMITE) as komite,
           SUM(b.total_jumlah) as total
    FROM bayar b
    WHERE MONTH(b.TGL_BYR) = ? AND YEAR(b.TGL_BYR) = ?
");
$stmt->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt->execute();
$bayar_recap = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmtBiayaLain = $koneksi->prepare("
    SELECT d.nama_biaya_snapshot AS nama, SUM(d.nominal_snapshot) AS total
    FROM bayar_biaya_lain d
    JOIN bayar b ON b.id = d.bayar_id
    WHERE MONTH(b.TGL_BYR) = ? AND YEAR(b.TGL_BYR) = ?
    GROUP BY d.nama_biaya_snapshot
    ORDER BY d.nama_biaya_snapshot ASC
");
$stmtBiayaLain->bind_param('ii', $filter_bulan, $filter_tahun);
$stmtBiayaLain->execute();
$rekap_biaya_lain = $stmtBiayaLain->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtBiayaLain->close();

// ── Rekap Tabungan
$stmt2 = $koneksi->prepare("
    SELECT COALESCE(SUM(MASUK),0) as total_masuk FROM transaksi_m
    WHERE MONTH(TANGGAL) = ? AND YEAR(TANGGAL) = ?
");
$stmt2->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt2->execute();
$tab_masuk = (float)$stmt2->get_result()->fetch_assoc()['total_masuk'];
$stmt2->close();

$stmt3 = $koneksi->prepare("
    SELECT COALESCE(SUM(KELUAR),0) as total_keluar FROM transaksi_k
    WHERE MONTH(TANGGAL) = ? AND YEAR(TANGGAL) = ?
");
$stmt3->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt3->execute();
$tab_keluar = (float)$stmt3->get_result()->fetch_assoc()['total_keluar'];
$stmt3->close();

// ── Detail Transaksi Pembayaran
$stmt4 = $koneksi->prepare("
    SELECT b.id, s.NO_INDUK, s.NAMA, s.KELAS, b.BULAN, b.TAHUN,
           b.U_PANGKAL, b.U_BANGUNAN, b.U_SERAGAM, b.U_KEGIATAN,
           b.U_SPP, b.U_MAKAN, b.U_SORGA, b.U_INFAQ, b.U_KOMITE,
           b.sistem_pembayaran, b.total_jumlah, b.TGL_BYR
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    WHERE MONTH(b.TGL_BYR) = ? AND YEAR(b.TGL_BYR) = ?
    ORDER BY b.TGL_BYR DESC
");
$stmt4->bind_param('ii', $filter_bulan, $filter_tahun);
$stmt4->execute();
$bayar_detail = $stmt4->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt4->close();

// ── Total Saldo Tabungan Semua Siswa
$total_saldo = (float)$koneksi->query("SELECT COALESCE(SUM(SALDO),0) as s FROM tabungan")->fetch_assoc()['s'];

$bln_names = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
               '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$bulan_label = $bln_names[str_pad($filter_bulan, 2, '0', STR_PAD_LEFT)];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Laporan Keuangan | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="../assets/css/style.css?v=4.7" />
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
      <div class="topbar-title">
        <h2>Laporan Keuangan</h2>
        <span class="breadcrumb">SistemSPP / Laporan</span>
      </div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>

    <div class="page-content">
      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" id="flash-msg" style="margin-bottom:16px;">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <!-- Filter -->
      <div class="main-card" style="margin-bottom:16px;">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
          <div class="field-row" style="flex:1;min-width:150px;">
            <label class="field-label">Bulan</label>
            <select class="field-input field-select" name="bulan">
              <?php foreach ($bln_names as $num => $nama): ?>
              <option value="<?= $num ?>" <?= $filter_bulan == $num ? 'selected' : '' ?>><?= $nama ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-row" style="flex:0;min-width:100px;">
            <label class="field-label">Tahun</label>
            <select class="field-input field-select" name="tahun">
              <?php for ($y = date('Y')-2; $y <= date('Y')+1; $y++): ?>
              <option value="<?= $y ?>" <?= $filter_tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">Tampilkan</button>
          <a href="export_excel.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>" class="btn btn-success" style="background:linear-gradient(135deg,#16a34a,#15803d);">
            📊 Export Excel
          </a>
          <a href="export_pdf.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>" class="btn btn-warning">
            📄 Export PDF
          </a>
        </form>
      </div>

      <!-- Summary Cards -->
      <div class="stats-grid" style="margin-bottom:8px;">
        <div class="stat-card stat-blue">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format((float)($bayar_recap['total'] ?? 0),0,',','.') ?></span>
            <span class="stat-label">Total Pembayaran SPP</span>
          </div>
        </div>
        <div class="stat-card stat-green">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($tab_masuk,0,',','.') ?></span>
            <span class="stat-label">Tabungan Masuk</span>
          </div>
        </div>
        <div class="stat-card" style="--c:#ef4444;">
          <div class="stat-icon" style="background:rgba(239,68,68,0.15);color:#ef4444;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 17H18M12 22V2M7 7l5-5 5 5"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($tab_keluar,0,',','.') ?></span>
            <span class="stat-label">Tabungan Keluar</span>
          </div>
        </div>
        <div class="stat-card stat-purple">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($total_saldo,0,',','.') ?></span>
            <span class="stat-label">Total Saldo Tabungan</span>
          </div>
        </div>
      </div>

      <!-- Rekap Komponen Pembayaran -->
      <?php if ($bayar_recap): ?>
      <div class="main-card" style="margin-bottom:16px;">
        <div class="card-header">
          <h3 class="card-title">Rekap Komponen Pembayaran — <?= $bulan_label ?> <?= $filter_tahun ?></h3>
        </div>
        <div class="table-container">
          <table class="payment-table">
            <thead><tr><th>Komponen</th><th>Total (Rp)</th></tr></thead>
            <tbody>
              <?php
              $komponen_map = [
                'Uang Pangkal'   => $bayar_recap['pangkal'],
                'Uang Bangunan'  => $bayar_recap['bangunan'],
                'Uang Seragam'   => $bayar_recap['seragam'],
                'Uang Kegiatan'  => $bayar_recap['kegiatan'],
                'Uang SPP'       => $bayar_recap['spp'],
                'Uang Komite'    => $bayar_recap['komite'],
                'Uang Makan'     => $bayar_recap['makan'],
                'Uang Sorga'     => $bayar_recap['sorga'],
                'Uang Infaq'     => $bayar_recap['infaq'],
              ];
              foreach ($komponen_map as $nama => $val):
                if ((float)$val <= 0) continue;
              ?>
              <tr><td><?= $nama ?></td><td class="nominal">Rp <?= number_format((float)$val,0,',','.') ?></td></tr>
              <?php endforeach; ?>
              <?php foreach ($rekap_biaya_lain as $biaya): if ((float)$biaya['total'] <= 0) continue; ?>
              <tr><td><?= htmlspecialchars($biaya['nama']) ?></td><td class="nominal">Rp <?= number_format((float)$biaya['total'],0,',','.') ?></td></tr>
              <?php endforeach; ?>
              <tr style="font-weight:700;border-top:2px solid var(--border);">
                <td>TOTAL</td>
                <td class="nominal" style="color:var(--accent);">Rp <?= number_format((float)($bayar_recap['total']??0),0,',','.') ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Detail Transaksi Pembayaran -->
      <div class="main-card">
        <div class="card-header laporan-detail-header">
          <div>
          <h3 class="card-title">Detail Transaksi Pembayaran — <?= $bulan_label ?> <?= $filter_tahun ?></h3>
          <span class="badge-count"><?= count($bayar_detail) ?> transaksi</span>
          </div>
          <?php if (!empty($bayar_detail)): ?>
          <button type="submit" form="print-selected-form" class="btn btn-warning btn-print-selected" id="btn-print-selected" disabled>
            Cetak Dipilih
          </button>
          <?php endif; ?>
        </div>
        <form method="GET" action="export_pdf.php" id="print-selected-form">
          <input type="hidden" name="bulan" value="<?= htmlspecialchars((string)$filter_bulan) ?>">
          <input type="hidden" name="tahun" value="<?= htmlspecialchars((string)$filter_tahun) ?>">
          <input type="hidden" name="mode" value="selected">
        <div class="table-container">
          <table class="payment-table" id="tbl-laporan">
            <thead>
              <tr>
                <th class="select-col"><input type="checkbox" class="select-print-check" id="check-all-print" aria-label="Pilih semua transaksi"></th>
                <th>No</th><th>No. Induk</th><th>Nama</th><th>Kelas</th><th>Bulan Bayar</th><th>Sistem</th><th>Total (Rp)</th><th>Tgl Bayar</th><th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($bayar_detail)): ?>
              <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">Belum ada data pembayaran pada periode ini.</td></tr>
              <?php else: ?>
              <?php foreach ($bayar_detail as $i => $b): ?>
              <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                <td class="select-col"><input type="checkbox" class="select-print-check row-print-check" name="ids[]" value="<?= (int)$b['id'] ?>" aria-label="Pilih transaksi <?= htmlspecialchars($b['NAMA']) ?>"></td>
                <td><?= $i+1 ?></td>
                <td><span class="badge-nis"><?= htmlspecialchars($b['NO_INDUK']) ?></span></td>
                <td><?= htmlspecialchars($b['NAMA']) ?></td>
                <td><?= htmlspecialchars($b['KELAS']) ?></td>
                <td><?= htmlspecialchars($b['BULAN']) ?> <?= htmlspecialchars($b['TAHUN']) ?></td>
                <td><?= htmlspecialchars($b['sistem_pembayaran'] ?? 'VA') ?></td>
                <td class="nominal">Rp <?= number_format((float)$b['total_jumlah'],0,',','.') ?></td>
                <td><?= date('d M Y', strtotime($b['TGL_BYR'])) ?></td>
                <td class="aksi-col"><a class="btn-tbl btn-tbl-print" href="export_pdf.php?bulan=<?= urlencode((string)$filter_bulan) ?>&tahun=<?= urlencode((string)$filter_tahun) ?>&mode=selected&ids[]=<?= (int)$b['id'] ?>">Cetak</a></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        </form>
      </div>
    </div>
  </main>
</div>

<div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
  <script src="../assets/js/app.js?v=3.1"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  autoHideFlash();

  const form = document.getElementById('print-selected-form');
  const checkAll = document.getElementById('check-all-print');
  const rowChecks = Array.from(document.querySelectorAll('.row-print-check'));
  const printButton = document.getElementById('btn-print-selected');

  function refreshPrintSelection() {
    const selectedCount = rowChecks.filter(check => check.checked).length;
    if (printButton) {
      printButton.disabled = selectedCount === 0;
      printButton.textContent = selectedCount > 0 ? 'Cetak Dipilih (' + selectedCount + ')' : 'Cetak Dipilih';
    }
    if (checkAll) {
      checkAll.checked = selectedCount > 0 && selectedCount === rowChecks.length;
      checkAll.indeterminate = selectedCount > 0 && selectedCount < rowChecks.length;
    }
  }

  if (checkAll) {
    checkAll.addEventListener('change', function(){
      rowChecks.forEach(check => { check.checked = checkAll.checked; });
      refreshPrintSelection();
    });
  }

  rowChecks.forEach(check => check.addEventListener('change', refreshPrintSelection));

  if (form) {
    form.addEventListener('submit', function(event){
      if (!rowChecks.some(check => check.checked)) {
        event.preventDefault();
        if (typeof showToast === 'function') {
          showToast('!', 'Pilih minimal satu transaksi untuk dicetak.', 'error');
        }
      }
    });
  }
});
</script>
</body>
</html>
