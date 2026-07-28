<?php
// siswa/daftar.php - Student List Management
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'tambah') {
        $no_induk = trim($_POST['no_induk'] ?? '');
        $nama     = trim($_POST['nama']     ?? '');
        $kelas    = trim($_POST['kelas']    ?? '');
        if ($no_induk && $nama && $kelas) {
            $stmt = $koneksi->prepare("INSERT INTO siswa (NO_INDUK, NAMA, KELAS) VALUES (?,?,?)");
            $stmt->bind_param('sss', $no_induk, $nama, $kelas);
            if ($stmt->execute()) {
                $_SESSION['flash'] = ['type'=>'success','msg'=>"Siswa $nama berhasil ditambahkan!"];
            } else {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Gagal tambah siswa (No. Induk mungkin duplikat)!'];
            }
            $stmt->close();
        }
        header('Location: daftar.php'); exit;
    }
    if ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $koneksi->prepare("DELETE FROM siswa WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Data siswa berhasil dihapus!'];
        $stmt->close();
        header('Location: daftar.php'); exit;
    }
}

$siswa = $koneksi->query("SELECT s.*, COUNT(p.id) as jml_bayar FROM siswa s LEFT JOIN bayar p ON p.NO_INDUK = s.NO_INDUK GROUP BY s.id ORDER BY s.NAMA ASC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Data Siswa | SistemSPP</title>
  <meta name="description" content="Manajemen data siswa sistem pembayaran SPP." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=2.6" />
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
          <h2>Data Siswa</h2>
          <span class="breadcrumb">SistemSPP / Data Siswa</span>
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

      <!-- Tambah Siswa -->
      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Tambah Siswa Baru
          </div>
        </div>
        <form method="POST" action="daftar.php" id="form-tambah-siswa">
          <input type="hidden" name="aksi" value="tambah" />
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label" for="nis-baru">No. Induk</label>
              <input class="field-input" type="text" id="nis-baru" name="no_induk" placeholder="Nomor Induk Siswa" required />
            </div>
            <div class="field-row">
              <label class="field-label" for="nama-baru">Nama Lengkap</label>
              <input class="field-input" type="text" id="nama-baru" name="nama" placeholder="Nama lengkap siswa" required />
            </div>
            <div class="field-row">
              <label class="field-label" for="kelas-baru">Kelas</label>
              <select class="field-input field-select" id="kelas-baru" name="kelas" required>
                <option value="">-- Pilih Kelas --</option>
                <?php foreach(['X IPA','X IPS','XI IPA','XI IPS','XII IP','XII IS'] as $k): ?>
                <option><?=$k?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="action-bar" style="margin-top:16px">
            <button type="submit" class="btn btn-primary" id="btn-tambah-siswa">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
              Tambah Siswa
            </button>
          </div>
        </form>
      </div>

      <!-- Daftar Siswa -->
      <div class="main-card" style="margin-top:0">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Daftar Siswa (<?= $siswa->num_rows ?> siswa)
          </div>
        </div>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead>
              <tr><th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th><th>Jml. Bayar</th><th>Aksi</th></tr>
            </thead>
            <tbody>
              <?php $no = 1; while ($s = $siswa->fetch_assoc()): ?>
              <tr>
                <td data-label="No"><?= $no++ ?></td>
                <td data-label="No. Induk"><span class="badge-nis"><?= htmlspecialchars($s['NO_INDUK']) ?></span></td>
                <td data-label="Nama Siswa"><?= htmlspecialchars($s['NAMA']) ?></td>
                <td data-label="Kelas"><?= htmlspecialchars($s['KELAS']) ?></td>
                <td data-label="Jml. Bayar"><span class="badge-count"><?= $s['jml_bayar'] ?>x</span></td>
                <td data-label="Aksi">
                  <form method="POST" action="daftar.php" style="display:inline" onsubmit="return confirm('Hapus siswa <?= htmlspecialchars(addslashes($s['NAMA'])) ?>? Semua data pembayaran akan ikut terhapus!')">
                    <input type="hidden" name="aksi" value="hapus" />
                    <input type="hidden" name="id" value="<?= $s['id'] ?>" />
                    <button type="submit" class="btn-tbl btn-tbl-del" title="Hapus">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                      Hapus
                    </button>
                  </form>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <script src="../assets/js/app.js?v=2.8"></script>
</body>
</html>

