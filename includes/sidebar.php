<?php
// includes/sidebar.php
$current = basename($_SERVER['PHP_SELF']);
$dir     = basename(dirname($_SERVER['PHP_SELF']));

// Tentukan root path berdasarkan posisi subfolder
if (in_array($dir, ['pembayaran', 'siswa', 'tabungan', 'laporan'])) {
    $root = '../';
} else {
    $root = '';
}

$role = $_SESSION['admin_role'] ?? '';

// Definisi semua nav item: [href, label, svg-path, roles yang boleh akses]
$allNavItems = [
  ['dashboard.php', 'Dashboard',
   '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
   ['admin', 'bendahara']],

  ['pembayaran/form.php', 'Input Pembayaran',
   '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
   ['admin']],

  ['pembayaran/lihat.php', 'Lihat Pembayaran',
   '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
   ['admin']],

  ['siswa/daftar.php', 'Data Siswa',
   '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
   ['admin']],

  ['master_biaya_lain.php', 'Master Biaya Lain',
   '<path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
   ['admin']],

  ['role_management.php', 'Role Management',
   '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
   ['admin']],

  ['tabungan/masuk.php', 'Tabungan Masuk',
   '<path d="M12 2v20M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
   ['admin', 'kasir']],

  ['tabungan/keluar.php', 'Tabungan Keluar',
   '<path d="M6 17H18M12 22V2M7 7l5-5 5 5"/>',
   ['admin', 'kasir']],

  ['tabungan/riwayat.php', 'Riwayat Tabungan',
   '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
   ['admin', 'kasir', 'bendahara']],

  ['laporan/index.php', 'Laporan',
   '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
   ['admin', 'bendahara']],
];

// Filter nav items berdasarkan role
$navItems = array_filter($allNavItems, fn($item) => in_array($role, $item[3], true));
$navItems = array_values($navItems);

// Short label untuk bottom nav
$shortLabels = [
  'Input Pembayaran'  => 'Input',
  'Lihat Pembayaran'  => 'Lihat',
  'Data Siswa'        => 'Siswa',
  'Master Biaya Lain' => 'Biaya',
  'Role Management'   => 'Akun',
  'Tabungan Masuk'    => 'Masuk',
  'Tabungan Keluar'   => 'Keluar',
  'Riwayat Tabungan'  => 'Riwayat',
  'Laporan'           => 'Laporan',
];

// Role label
$roleLabels = ['admin' => 'Administrator', 'bendahara' => 'Bendahara TU', 'kasir' => 'Kasir'];
$roleLabel  = $roleLabels[$role] ?? 'Pengguna';
?>
<!-- Early theme init to prevent flash -->
<script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon brand-logo-wrap">
      <img src="<?= $root ?>assets/img/school-logo.png" alt="Logo SD MH" class="brand-logo-img" />
    </div>
    <span class="brand-name">SistemSPP</span>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($navItems as [$href, $label, $icon, $roles]):
      $isActive = (strpos($_SERVER['PHP_SELF'], str_replace('../', '', $href)) !== false) ? 'active' : '';
    ?>
    <a href="<?= $root . $href ?>" class="nav-item <?= $isActive ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
      <?= $label ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Theme Toggle -->
  <div style="padding: 0 12px 8px;">
    <button class="theme-toggle" id="btn-theme-toggle" onclick="toggleTheme()" title="Ganti tema">
      <span id="theme-icon">🌙</span>
      <span id="theme-label">Mode Gelap</span>
      <span class="toggle-track"><span class="toggle-thumb"></span></span>
    </button>
  </div>

  <div class="sidebar-footer">
    <div class="admin-info">
      <div class="admin-avatar"><?= strtoupper(substr($_SESSION['admin_nama'] ?? 'AD', 0, 2)) ?></div>
      <div>
        <span class="admin-name"><?= htmlspecialchars($_SESSION['admin_nama'] ?? 'Admin') ?></span>
        <span class="admin-role"><?= $roleLabel ?></span>
      </div>
    </div>
    <a href="<?= $root ?>logout.php" class="logout-btn" title="Logout">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
  </div>
</aside>
<div class="sidebar-backdrop" onclick="toggleSidebar()"></div>

<!-- Material 3 Bottom Navigation for Mobile -->
<nav class="bottom-nav">
  <?php foreach ($navItems as [$href, $label, $icon, $roles]):
    $isActive   = (strpos($_SERVER['PHP_SELF'], str_replace('../', '', $href)) !== false) ? 'active' : '';
    $shortLabel = $shortLabels[$label] ?? $label;
  ?>
  <a href="<?= $root . $href ?>" class="bottom-nav-item <?= $isActive ?>">
    <div class="bottom-nav-icon-wrap">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
    </div>
    <span class="bottom-nav-label"><?= $shortLabel ?></span>
  </a>
  <?php endforeach; ?>
</nav>
