<?php
// ============================================
// pembayaran/lihat.php - View All Payments
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Filter
$search     = trim($_GET['search'] ?? '');
$filter_bln = $_GET['bulan']  ?? '';
$filter_thn = $_GET['tahun']  ?? '';

$where = "WHERE 1=1";
$params = [];
$types  = '';
if ($search) {
    $like = "%$search%";
    $where .= " AND (s.NAMA LIKE ? OR s.NO_INDUK LIKE ?)";
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}
if ($filter_bln) {
    $where .= " AND p.BULAN = ?";
    $params[] = $filter_bln; $types .= 's';
}
if ($filter_thn) {
    $where .= " AND p.TAHUN = ?";
    $params[] = $filter_thn; $types .= 's';
}

$sql = "SELECT p.*, s.NO_INDUK, s.NAMA, s.KELAS FROM bayar p
        JOIN siswa s ON s.NO_INDUK = p.NO_INDUK
        $where ORDER BY p.created_at DESC";

$stmt = $koneksi->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

// Months list
$bln_list = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Lihat Pembayaran | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Lihat semua data transaksi pembayaran siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=3.2" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

  <div class="bg-orbs">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
  </div>

  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Lihat Pembayaran Siswa</h2>
          <span class="breadcrumb">SistemSPP / Pembayaran / Lihat</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>" id="flash-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($flash['type'] === 'success'): ?><polyline points="20 6 9 17 4 12"/>
          <?php else: ?><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          <?php endif; ?>
        </svg>
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            Data Pembayaran Siswa
          </div>
          <a href="form.php" class="btn btn-primary" id="btn-tambah" style="padding:8px 18px;font-size:13px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Tambah Baru
          </a>
        </div>

        <!-- Filter Bar -->
        <form method="GET" action="lihat.php" class="filter-bar">
          <div class="search-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="search-lihat" name="search" placeholder="Cari nama / NIS..."
              value="<?= htmlspecialchars($search) ?>" />
          </div>
          <select class="field-input field-select filter-sel" name="bulan" id="filter-bulan">
            <option value="">Semua Bulan</option>
            <?php foreach ($bln_list as $b): ?>
            <option value="<?=$b?>" <?= $filter_bln === $b ? 'selected' : '' ?>><?=$b?></option>
            <?php endforeach; ?>
          </select>
          <select class="field-input field-select filter-sel" name="tahun" id="filter-tahun">
            <option value="">Semua Tahun</option>
            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
            <option value="<?=$y?>" <?= $filter_thn == $y ? 'selected' : '' ?>><?=$y?></option>
            <?php endfor; ?>
          </select>
          <button type="submit" class="btn btn-primary" id="btn-filter" style="padding:8px 16px;font-size:13px">Filter</button>
          <a href="lihat.php" class="btn btn-ghost" id="btn-reset-filter" style="padding:8px 16px;font-size:13px">Reset</a>
        </form>

        <!-- Table -->
        <div class="table-container">
          <table class="payment-table responsive-table" id="tbl-lihat">
            <thead>
              <tr>
                <th>No</th>
                <th>NIS</th>
                <th>Nama Siswa</th>
                <th>Kelas</th>
                <th>Bulan / Tahun</th>
                <th>SPP</th>
                <th>Total Bayar</th>
                <th>Tanggal</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($result->num_rows > 0):
                $no = 1;
                while ($row = $result->fetch_assoc()): ?>
              <tr>
                <td data-label="No"><?= $no++ ?></td>
                <td data-label="NIS"><span class="badge-nis"><?= htmlspecialchars($row['NO_INDUK']) ?></span></td>
                <td data-label="Nama Siswa"><?= htmlspecialchars($row['NAMA']) ?></td>
                <td data-label="Kelas"><?= htmlspecialchars($row['KELAS']) ?></td>
                <td data-label="Bulan / Tahun"><?= htmlspecialchars($row['BULAN']) ?> <?= $row['TAHUN'] ?></td>
                <td data-label="SPP" class="nominal">Rp <?= number_format($row['U_SPP'], 0, ',', '.') ?></td>
                <td data-label="Total Bayar" class="nominal">Rp <?= number_format($row['total_jumlah'], 0, ',', '.') ?></td>
                <td data-label="Tanggal"><?= date('d/m/Y', strtotime($row['TGL_BYR'])) ?></td>
                <td data-label="Aksi" class="aksi-col">
                  <a href="edit.php?id=<?= $row['id'] ?>" class="btn-tbl btn-tbl-edit" title="Edit">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit
                  </a>
                  <a href="proses.php?aksi=hapus&id=<?= $row['id'] ?>"
                     class="btn-tbl btn-tbl-del" title="Hapus"
                     onclick="return confirm('Yakin ingin menghapus data pembayaran ini?')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Hapus
                  </a>
                </td>
              </tr>
              <?php endwhile;
              else: ?>
              <tr><td colspan="9">
                <div class="empty-state">
                  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                  <p>Belum ada data pembayaran</p>
                  <a href="form.php" class="btn btn-primary" style="margin-top:12px">+ Input Pembayaran</a>
                </div>
              </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <script src="../assets/js/app.js?v=2.8"></script>
</body>
</html>

