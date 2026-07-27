<?php
// ============================================
// login.php — Split Layout (referensi myEdlinks)
// ============================================
session_start();
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once 'koneksi.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $koneksi->prepare("SELECT id, nama, password FROM admin WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin  = $result->fetch_assoc();
        $stmt->close();

        if ($admin && $admin['password'] === md5($password)) {
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_nama'] = $admin['nama'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Username atau password salah!';
        }
    } else {
        $error = 'Username dan password wajib diisi!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | SistemSPP</title>
  <meta name="description" content="Login admin sistem pembayaran SPP sekolah — kelola pembayaran siswa dengan mudah." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <!-- Prevent theme flash -->
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
  <link rel="stylesheet" href="assets/css/style.css?v=2.6" />
  <link rel="stylesheet" href="assets/css/login.css" />
</head>
<body class="login-split-body">

  <!-- ── FLOATING CARD ─────────────────── -->
  <div class="login-card-wrap">

  <!-- ── LEFT PANEL ──────────────────────── -->
  <div class="login-left" id="login-left">

    <!-- Top badge -->
    <div class="left-topbar">
      <div class="left-brand">
        <div class="brand-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
          </svg>
        </div>
        <span class="brand-name-left">SistemSPP</span>
      </div>
      <span class="left-version-badge">v2.0</span>
    </div>

    <!-- Decorative circles -->
    <div class="deco-circle deco-c1"></div>
    <div class="deco-circle deco-c2"></div>
    <div class="deco-circle deco-c3"></div>

    <!-- Hero content -->
    <div class="left-hero">
      <div class="left-tagline">
        <h1>
          Kelola Pembayaran<br/>
          <span class="tagline-accent">SPP Siswa</span><br/>
          Lebih Mudah &amp; Cepat!
        </h1>
        <p class="left-desc">
          Sistem terintegrasi untuk admin sekolah — input, pantau, dan kelola semua transaksi pembayaran siswa dalam satu platform modern.
        </p>
      </div>

      <!-- Illustration -->
      <div class="left-illustration-wrap">
        <img src="assets/img/login_illustration.png" alt="Ilustrasi sistem pembayaran sekolah" class="left-illustration" />
      </div>

      <!-- Hero Metrics (Simpler tapi Menarik) -->
      <div class="left-metrics-row">
        <div class="metric-pill">
          <div class="mp-icon mpi-green">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <div class="mp-info">
            <span class="mp-val">Rp 750Rb</span>
            <span class="mp-lbl">PSB Terbaru</span>
          </div>
        </div>
        <div class="metric-pill">
          <div class="mp-icon mpi-blue">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          </div>
          <div class="mp-info">
            <span class="mp-val">248 Siswa</span>
            <span class="mp-lbl">Total Aktif</span>
          </div>
        </div>
        <div class="metric-pill">
          <div class="mp-icon mpi-purple">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
          <div class="mp-info">
            <span class="mp-val">Rp 42.5Jt</span>
            <span class="mp-lbl">Bulan Ini</span>
          </div>
        </div>
      </div>

      <!-- Bottom dots decoration -->
      <div class="left-dots">
        <span class="dot dot-active"></span>
        <span class="dot"></span>
        <span class="dot"></span>
      </div>
    </div>

  </div><!-- /login-left -->

  <!-- ── RIGHT PANEL ─────────────────────── -->
  <div class="login-right" id="login-right">

    <!-- Theme toggle -->
    <button class="login-theme-btn" id="login-theme-btn" onclick="toggleTheme()" title="Ganti tema">
      <span class="theme-icon" id="login-theme-icon">☀️</span>
      <span id="login-theme-label">Mode Terang</span>
    </button>

    <div class="right-inner">

      <!-- Brand (mobile only) -->
      <div class="right-brand-mobile">
        <div class="brand-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
          </svg>
        </div>
        <span class="brand-name">SistemSPP</span>
      </div>

      <!-- Greeting -->
      <div class="right-greeting">
        <h2>Hai, selamat datang! 👋</h2>
        <p>Belum punya akun? <a href="#" class="link-accent" onclick="return false">Hubungi Admin</a></p>
      </div>

      <!-- Error Alert -->
      <?php if ($error): ?>
      <div class="alert alert-error right-alert" id="flash-msg">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <!-- Form -->
      <form method="POST" action="login.php" class="right-form" id="form-login">

        <div class="rfield-group">
          <label class="rfield-label" for="username">Username</label>
          <div class="rfield-wrap">
            <svg class="rfield-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input class="rfield-input" type="text" id="username" name="username"
              placeholder="Contoh: admin@sekolah.com"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              required autocomplete="username" />
          </div>
        </div>

        <div class="rfield-group">
          <div class="rfield-label-row">
            <label class="rfield-label" for="password">Password</label>
            <a href="#" class="link-muted" onclick="return false">Lupa kata sandi?</a>
          </div>
          <div class="rfield-wrap">
            <svg class="rfield-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input class="rfield-input" type="password" id="password" name="password"
              placeholder="Masukkan kata sandi"
              required autocomplete="current-password" />
            <button type="button" class="rfield-eye" onclick="togglePw()" id="btn-toggle-pw" title="Tampilkan password">
              <svg id="pw-eye-open" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg id="pw-eye-closed" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
        </div>

        <!-- Remember -->
        <label class="remember-row" for="remember">
          <input type="checkbox" id="remember" name="remember" class="remember-chk" />
          <span class="remember-custom"></span>
          <span class="remember-label">Ingat perangkat ini</span>
        </label>

        <button type="submit" class="btn-login-main" id="btn-login">
          <span>Masuk</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        </button>

        <!-- Divider -->
        <div class="or-divider">
          <span>Atau masuk menggunakan</span>
        </div>

        <!-- Social buttons (dekoratif) -->
        <div class="social-row">
          <button type="button" class="social-btn" id="btn-fb" title="Facebook" onclick="socialNotAvail()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
          </button>
          <button type="button" class="social-btn" id="btn-google" title="Google" onclick="socialNotAvail()">
            <svg width="18" height="18" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
          </button>
          <button type="button" class="social-btn" id="btn-apple" title="Apple" onclick="socialNotAvail()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></svg>
          </button>
        </div>

      </form>

      <!-- Terms -->
      <p class="terms-text">
        Dengan melanjutkan, kamu menerima
        <a href="#" class="link-accent" onclick="return false">Syarat Penggunaan</a> dan
        <a href="#" class="link-accent" onclick="return false">Kebijakan Privasi</a> kami.
      </p>

      <!-- Hint -->
      <p class="login-hint-bottom">Default: <code>admin</code> / <code>admin123</code></p>

    </div><!-- /right-inner -->
  </div><!-- /login-right -->

  </div><!-- /login-card-wrap -->

  <script src="assets/js/app.js?v=2.8"></script>
  <script>
    // Override togglePw for split layout
    function togglePw() {
      const pw   = document.getElementById('password');
      const open = document.getElementById('pw-eye-open');
      const cls  = document.getElementById('pw-eye-closed');
      if (pw.type === 'password') {
        pw.type = 'text';
        open.style.display = 'none';
        cls.style.display  = 'block';
      } else {
        pw.type = 'password';
        open.style.display = 'block';
        cls.style.display  = 'none';
      }
    }

    function socialNotAvail() {
      const toast = document.getElementById('toast');
      const icon  = document.getElementById('toast-icon');
      const msg   = document.getElementById('toast-msg');
      if (!toast) { alert('Fitur ini tidak tersedia untuk sistem internal.'); return; }
      icon.textContent = 'ℹ️';
      msg.textContent  = 'Fitur SSO tidak tersedia untuk sistem internal.';
      toast.className  = 'toast toast-info show';
      setTimeout(() => toast.classList.remove('show'), 3000);
    }
  </script>

  <!-- Toast (standalone) -->
  <div class="toast" id="toast">
    <span class="toast-icon" id="toast-icon">ℹ️</span>
    <span id="toast-msg"></span>
  </div>

</body>
</html>
