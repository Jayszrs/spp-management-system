<?php
// includes/sidebar.php
$current = basename($_SERVER['PHP_SELF']);
$dir     = basename(dirname($_SERVER['PHP_SELF']));
$root    = ($dir === 'pembayaran' || $dir === 'siswa') ? '../' : '';

$navItems = [
  ['dashboard.php', 'Dashboard', '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'],
  ['pembayaran/form.php', 'Input Pembayaran', '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>'],
  ['pembayaran/lihat.php', 'Lihat Pembayaran', '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'],
  ['siswa/daftar.php', 'Data Siswa', '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
];
?>
<!-- Early theme init to prevent flash -->
<script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
      </svg>
    </div>
    <span class="brand-name">SistemSPP</span>
  </div>

  <nav class="sidebar-nav">
    <?php
    foreach ($navItems as [$href, $label, $icon]):
      $isActive = (strpos($_SERVER['PHP_SELF'], str_replace('../','',$href)) !== false) ? 'active' : '';
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
        <span class="admin-role">Administrator</span>
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
  <?php
  foreach ($navItems as [$href, $label, $icon]):
    $isActive = (strpos($_SERVER['PHP_SELF'], str_replace('../','',$href)) !== false) ? 'active' : '';
    // Shorten label for bottom bar
    $shortLabel = $label;
    if ($label === 'Input Pembayaran') $shortLabel = 'Input';
    if ($label === 'Lihat Pembayaran') $shortLabel = 'Lihat';
    if ($label === 'Data Siswa') $shortLabel = 'Siswa';
  ?>
  <a href="<?= $root . $href ?>" class="bottom-nav-item <?= $isActive ?>">
    <div class="bottom-nav-icon-wrap">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
    </div>
    <span class="bottom-nav-label"><?= $shortLabel ?></span>
  </a>
  <?php endforeach; ?>
</nav>
