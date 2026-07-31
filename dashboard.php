<?php
// ============================================
// dashboard.php
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'koneksi.php';
require_once 'includes/auth.php';
requireRole(['admin', 'bendahara']);

// Stats
$total_siswa    = $koneksi->query("SELECT COUNT(*) as c FROM siswa WHERE is_active = 1")->fetch_assoc()['c'];
$total_bayar    = $koneksi->query("SELECT COUNT(*) as c FROM bayar")->fetch_assoc()['c'];
$total_nominal  = $koneksi->query("SELECT COALESCE(SUM(total_jumlah),0) as s FROM bayar")->fetch_assoc()['s'];
$bayar_bulan_ini = $koneksi->query(
    "SELECT COUNT(*) as c FROM bayar WHERE MONTH(TGL_BYR)=MONTH(NOW()) AND YEAR(TGL_BYR)=YEAR(NOW())"
)->fetch_assoc()['c'];

// Transaksi terbaru
$recent = $koneksi->query("
    SELECT p.id, p.payment_link_version, s.NO_INDUK, s.NAMA, s.KELAS, p.BULAN, p.TAHUN, p.total_jumlah, p.TGL_BYR
    FROM bayar p
    JOIN siswa s ON s.NO_INDUK = p.NO_INDUK
    ORDER BY p.created_at DESC
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png" />
  <meta name="description" content="Dashboard admin sistem pembayaran SPP sekolah." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css?v=3.8" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

  <div class="bg-orbs">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
  </div>

  <!-- Sidebar -->
  <div class="layout">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
      <!-- Topbar -->
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" title="Toggle Sidebar">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Dashboard</h2>
          <span class="breadcrumb">SistemSPP / Dashboard</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card stat-blue">
          <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div class="stat-info">
            <span class="stat-value"><?= number_format($total_siswa) ?></span>
            <span class="stat-label">Total Siswa</span>
          </div>
        </div>
        <div class="stat-card stat-green">
          <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
          </div>
          <div class="stat-info">
            <span class="stat-value"><?= number_format($total_bayar) ?></span>
            <span class="stat-label">Total Transaksi</span>
          </div>
        </div>
        <div class="stat-card stat-purple">
          <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
          <div class="stat-info">
            <span class="stat-value">Rp <?= number_format($total_nominal, 0, ',', '.') ?></span>
            <span class="stat-label">Total Nominal</span>
          </div>
        </div>
        <div class="stat-card stat-orange">
          <div class="stat-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          </div>
          <div class="stat-info">
            <span class="stat-value"><?= number_format($bayar_bulan_ini) ?></span>
            <span class="stat-label">Bayar Bulan Ini</span>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="quick-actions">
        <a href="pembayaran/form.php" class="quick-btn">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          Input Pembayaran Baru
        </a>
        <a href="pembayaran/lihat.php" class="quick-btn quick-btn-ghost">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          Lihat Semua Pembayaran
        </a>
        <a href="siswa/daftar.php" class="quick-btn quick-btn-ghost">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          Manajemen Siswa
        </a>
      </div>

      <!-- Recent Transactions -->
      <div class="main-card" style="margin-top:0">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Transaksi Terbaru
          </div>
          <a href="pembayaran/lihat.php" class="btn btn-ghost" style="padding:6px 14px;font-size:13px">Lihat Semua</a>
        </div>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead>
              <tr>
                <th>NIS</th>
                <th>Nama Siswa</th>
                <th>Kelas</th>
                <th>Bulan / Tahun</th>
                <th>Total Bayar</th>
                <th>Tanggal</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($recent->num_rows > 0): ?>
                <?php while ($row = $recent->fetch_assoc()): ?>
                 <tr>
                   <td data-label="NIS"><span class="badge-nis"><?= htmlspecialchars($row['NO_INDUK']) ?></span></td>
                   <td data-label="Nama Siswa"><?= htmlspecialchars($row['NAMA']) ?></td>
                   <td data-label="Kelas"><?= htmlspecialchars($row['KELAS']) ?></td>
                   <td data-label="Bulan / Tahun"><?= htmlspecialchars($row['BULAN']) ?> <?= $row['TAHUN'] ?></td>
                   <td data-label="Total Bayar" class="nominal">Rp <?= number_format($row['total_jumlah'], 0, ',', '.') ?></td>
                   <td data-label="Tanggal"><?= date('d/m/Y', strtotime($row['TGL_BYR'])) ?></td>
                   <td data-label="Aksi">
                     <?php if ((int)($row['payment_link_version'] ?? 0) === 1): ?>
                     <a href="pembayaran/edit.php?id=<?= $row['id'] ?>" class="btn-tbl btn-tbl-edit" title="Edit">
                       <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                     </a>
                     <a href="pembayaran/proses.php?aksi=hapus&id=<?= $row['id'] ?>" class="btn-tbl btn-tbl-del"
                        title="Hapus" onclick="return confirm('Yakin hapus data ini?')">
                       <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                     </a>
                     <?php else: ?>
                     <span class="master-status is-inactive" title="Transaksi lama tanpa relasi eksplisit">Legacy</span>
                     <?php endif; ?>
                   </td>
                 </tr>
                <?php endwhile; ?>
              <?php else: ?>
              <tr><td colspan="7" class="text-center" style="padding:40px;color:var(--text-muted)">Belum ada transaksi</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </main>
  </div><!-- /layout -->

  <script src="assets/js/app.js?v=2.8"></script>
</body>
</html>
