<?php
// ============================================
// tabungan/riwayat.php — Riwayat Transaksi Tabungan
// ============================================
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'kasir', 'bendahara']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Filter
$filter_nis = trim($_GET['nis'] ?? '');
$filter_bulan = $_GET['bulan'] ?? date('m');
$filter_tahun = $_GET['tahun'] ?? date('Y');

// Query gabungan masuk + keluar. Filter NIS selalu diparameterkan agar
// input URL tidak pernah menjadi bagian dari SQL.
$where_nis_masuk = $filter_nis !== '' ? ' AND tm.NO_INDUK = ?' : '';
$where_nis_keluar = $filter_nis !== '' ? ' AND tk.NO_INDUK = ?' : '';

$sql_masuk = "
    SELECT tm.id, tm.NO_INDUK, s.NAMA, s.KELAS, tm.TANGGAL,
           tm.MASUK as nominal, 0 as keluar, 'masuk' as jenis, tm.user_id
    FROM transaksi_m tm
    JOIN siswa s ON s.NO_INDUK = tm.NO_INDUK
    WHERE MONTH(tm.TANGGAL) = ? AND YEAR(tm.TANGGAL) = ?$where_nis_masuk
";
$sql_keluar = "
    SELECT tk.id, tk.NO_INDUK, s.NAMA, s.KELAS, tk.TANGGAL,
           0 as nominal, tk.KELUAR as keluar, 'keluar' as jenis, tk.user_id
    FROM transaksi_k tk
    JOIN siswa s ON s.NO_INDUK = tk.NO_INDUK
    WHERE MONTH(tk.TANGGAL) = ? AND YEAR(tk.TANGGAL) = ?$where_nis_keluar
";

$sql = "($sql_masuk) UNION ALL ($sql_keluar) ORDER BY TANGGAL DESC";
$stmt = $koneksi->prepare($sql);
if ($filter_nis !== '') {
    $stmt->bind_param(
        'iisiis',
        $filter_bulan,
        $filter_tahun,
        $filter_nis,
        $filter_bulan,
        $filter_tahun,
        $filter_nis
    );
} else {
    $stmt->bind_param('iiii', $filter_bulan, $filter_tahun, $filter_bulan, $filter_tahun);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Saldo per siswa
$saldo_list = $koneksi->query("SELECT t.NO_INDUK, s.NAMA, t.SALDO FROM tabungan t JOIN siswa s ON s.NO_INDUK = t.NO_INDUK ORDER BY s.NAMA ASC")->fetch_all(MYSQLI_ASSOC);

$total_masuk  = array_sum(array_column($rows, 'nominal'));
$total_keluar = array_sum(array_column($rows, 'keluar'));

$bln_names = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
               '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Tabungan | SistemSPP</title>
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
        <h2>Riwayat Tabungan</h2>
        <span class="breadcrumb">SistemSPP / Tabungan / Riwayat</span>
      </div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>

    <?php if ($flash): ?>
    <div id="flash-msg" class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="margin:16px 20px 0;">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <div class="page-content">

      <!-- Saldo Cards -->
      <div class="stats-grid" style="margin-bottom:8px;">
        <div class="stat-card stat-green">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($total_masuk, 0, ',', '.') ?></span>
            <span class="stat-label">Total Masuk (Bulan Ini)</span>
          </div>
        </div>
        <div class="stat-card stat-red" style="--c:#ef4444;">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 17H18M12 22V2M7 7l5-5 5 5"/></svg></div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($total_keluar, 0, ',', '.') ?></span>
            <span class="stat-label">Total Keluar (Bulan Ini)</span>
          </div>
        </div>
        <div class="stat-card stat-blue">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
          <div class="stat-info">
            <span class="stat-value"><?= count($rows) ?></span>
            <span class="stat-label">Total Transaksi</span>
          </div>
        </div>
      </div>

      <!-- Filter -->
      <div class="main-card" style="margin-bottom:16px;">
        <form method="GET" class="tabungan-filter-form">
          <div class="field-row tabungan-filter-month">
            <label class="field-label">Bulan</label>
            <select class="field-input field-select" name="bulan">
              <?php foreach ($bln_names as $num => $nama): ?>
              <option value="<?= $num ?>" <?= $filter_bulan == $num ? 'selected' : '' ?>><?= $nama ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-row tabungan-filter-year">
            <label class="field-label">Tahun</label>
            <select class="field-input field-select" name="tahun">
              <?php for ($y = date('Y')-1; $y <= date('Y')+1; $y++): ?>
              <option value="<?= $y ?>" <?= $filter_tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="field-row tabungan-filter-nis">
            <label class="field-label">No. Induk (opsional)</label>
            <div class="search-box">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" name="nis" placeholder="Kosongkan = semua" value="<?= htmlspecialchars($filter_nis) ?>" />
            </div>
          </div>
          <div class="tabungan-filter-actions">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="riwayat.php" class="btn btn-ghost">Reset</a>
          </div>
        </form>
      </div>

      <!-- Tabel Transaksi -->
      <div class="main-card">
        <div class="card-header tabungan-card-header">
          <h3 class="card-title">Daftar Transaksi — <?= $bln_names[str_pad($filter_bulan,2,'0',STR_PAD_LEFT)] ?> <?= $filter_tahun ?></h3>
          <?php if (hasRole(['admin','kasir'])): ?>
          <div class="tabungan-card-actions">
            <a href="masuk.php" class="btn btn-primary">+ Tabungan Masuk</a>
            <a href="keluar.php" class="btn btn-warning">- Tabungan Keluar</a>
          </div>
          <?php endif; ?>
        </div>

        <div class="table-container">
          <table class="payment-table responsive-table" id="tbl-riwayat">
            <thead>
              <tr>
                <th>No</th><th>No. Induk</th><th>Nama</th><th>Kelas</th>
                <th>Tanggal</th><th>Jenis</th><th>Masuk (Rp)</th><th>Keluar (Rp)</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
              <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">Belum ada transaksi tabungan pada periode ini.</td></tr>
              <?php else: ?>
              <?php foreach ($rows as $i => $r): ?>
              <tr class="<?= $i % 2 === 0 ? 'row-highlight' : '' ?>">
                <td data-label="No"><?= $i + 1 ?></td>
                <td data-label="No. Induk"><span class="badge-nis"><?= htmlspecialchars($r['NO_INDUK']) ?></span></td>
                <td data-label="Nama"><?= htmlspecialchars($r['NAMA']) ?></td>
                <td data-label="Kelas"><?= htmlspecialchars($r['KELAS']) ?></td>
                <td data-label="Tanggal"><?= date('d M Y H:i', strtotime($r['TANGGAL'])) ?></td>
                <td data-label="Jenis">
                  <?php if ($r['jenis'] === 'masuk'): ?>
                  <span style="background:rgba(34,197,94,0.15);color:#16a34a;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">↑ Masuk</span>
                  <?php else: ?>
                  <span style="background:rgba(239,68,68,0.15);color:#dc2626;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">↓ Keluar</span>
                  <?php endif; ?>
                </td>
                <td class="nominal"><?= $r['nominal'] > 0 ? 'Rp ' . number_format($r['nominal'],0,',','.') : '—' ?></td>
                <td class="nominal" style="color:#dc2626;"><?= $r['keluar'] > 0 ? 'Rp ' . number_format($r['keluar'],0,',','.') : '—' ?></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Rekap Saldo Per Siswa -->
      <div class="main-card" style="margin-top:16px;">
        <div class="card-header">
          <h3 class="card-title">Rekap Saldo Tabungan Per Siswa</h3>
        </div>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead><tr><th>No</th><th>No. Induk</th><th>Nama</th><th>Saldo Tabungan (Rp)</th></tr></thead>
            <tbody>
              <?php if (empty($saldo_list)): ?>
              <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-muted);">Belum ada data saldo tabungan.</td></tr>
              <?php else: ?>
              <?php foreach ($saldo_list as $i => $sl): ?>
              <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                <td data-label="No"><?= $i+1 ?></td>
                <td data-label="No. Induk"><span class="badge-nis"><?= htmlspecialchars($sl['NO_INDUK']) ?></span></td>
                <td data-label="Nama"><?= htmlspecialchars($sl['NAMA']) ?></td>
                <td data-label="Saldo" class="nominal" style="font-weight:700;color:var(--accent);">Rp <?= number_format($sl['SALDO'],0,',','.') ?></td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>
</div>
<div class="toast" id="toast"><span id="toast-icon"></span><span id="toast-msg"></span></div>
<script src="../assets/js/app.js?v=2.8"></script>
<script>document.addEventListener('DOMContentLoaded', function(){ autoHideFlash(); });</script>
</body>
</html>
