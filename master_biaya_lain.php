<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
requireRole(['admin']);

function master_amount($value): float {
    if ($value === null || $value === '') return 0.0;
    return (float)str_replace(['.', ','], ['', '.'], (string)$value);
}

function master_redirect(): void {
    header('Location: master_biaya_lain.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah' || $aksi === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $nama = trim($_POST['nama'] ?? '');
        $nominal = master_amount($_POST['nominal'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($nama === '' || mb_strlen($nama) > 100) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nama biaya wajib diisi dan maksimal 100 karakter.'];
            master_redirect();
        }
        if ($nominal <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nominal biaya harus lebih dari Rp 0.'];
            master_redirect();
        }

        $stmtDuplikat = $koneksi->prepare('SELECT id FROM master_biaya_lain WHERE LOWER(nama) = LOWER(?) AND id <> ? LIMIT 1');
        $stmtDuplikat->bind_param('si', $nama, $id);
        $stmtDuplikat->execute();
        $duplikat = $stmtDuplikat->get_result()->fetch_assoc();
        $stmtDuplikat->close();

        if ($duplikat) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nama biaya tersebut sudah tersedia di master.'];
            master_redirect();
        }

        if ($aksi === 'tambah') {
            $stmt = $koneksi->prepare('INSERT INTO master_biaya_lain (nama, nominal, is_active) VALUES (?, ?, ?)');
            $stmt->bind_param('sdi', $nama, $nominal, $isActive);
            $berhasil = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $berhasil
                ? ['type' => 'success', 'msg' => "Biaya $nama berhasil ditambahkan."]
                : ['type' => 'error', 'msg' => 'Master biaya gagal ditambahkan.'];
        } else {
            if ($id <= 0) master_redirect();
            $stmt = $koneksi->prepare('UPDATE master_biaya_lain SET nama = ?, nominal = ?, is_active = ? WHERE id = ?');
            $stmt->bind_param('sdii', $nama, $nominal, $isActive, $id);
            $berhasil = $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = $berhasil
                ? ['type' => 'success', 'msg' => "Biaya $nama berhasil diperbarui. Transaksi lama tetap memakai tarif sebelumnya."]
                : ['type' => 'error', 'msg' => 'Master biaya gagal diperbarui.'];
        }
        master_redirect();
    }

    if ($aksi === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $koneksi->prepare('UPDATE master_biaya_lain SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status master biaya berhasil diubah.'];
        master_redirect();
    }

    if ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        $stmtCount = $koneksi->prepare('SELECT COUNT(*) AS jumlah FROM bayar_biaya_lain WHERE master_biaya_lain_id = ?');
        $stmtCount->bind_param('i', $id);
        $stmtCount->execute();
        $jumlahPemakaian = (int)$stmtCount->get_result()->fetch_assoc()['jumlah'];
        $stmtCount->close();

        if ($jumlahPemakaian > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Master sudah dipakai pada transaksi dan tidak dapat dihapus. Nonaktifkan agar tidak muncul di pembayaran baru.'];
        } else {
            $stmt = $koneksi->prepare('DELETE FROM master_biaya_lain WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Master biaya berhasil dihapus.'];
        }
        master_redirect();
    }
}

$editData = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $koneksi->prepare('SELECT * FROM master_biaya_lain WHERE id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$masterList = $koneksi->query("
    SELECT m.*, COUNT(d.id) AS jumlah_pemakaian
    FROM master_biaya_lain m
    LEFT JOIN bayar_biaya_lain d ON d.master_biaya_lain_id = m.id
    GROUP BY m.id
    ORDER BY m.is_active DESC, m.nama ASC
");
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Master Biaya Lain | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css?v=3.9" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
  <div class="layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" title="Toggle Sidebar">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title"><h2>Master Biaya Lain</h2><span class="breadcrumb">SistemSPP / Master Biaya Lain</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title"><?= $editData ? 'Edit Master Biaya' : 'Tambah Master Biaya' ?></div>
        </div>
        <form method="POST" action="master_biaya_lain.php" id="form-master-biaya">
          <input type="hidden" name="aksi" value="<?= $editData ? 'update' : 'tambah' ?>" />
          <input type="hidden" name="id" value="<?= (int)($editData['id'] ?? 0) ?>" />
          <div class="fields-grid master-fee-form">
            <div class="field-row">
              <label class="field-label" for="nama-biaya">Nama Biaya</label>
              <input class="field-input" id="nama-biaya" name="nama" maxlength="100" required
                placeholder="Contoh: Biaya Ekstrakurikuler" value="<?= htmlspecialchars($editData['nama'] ?? '') ?>" />
            </div>
            <div class="field-row">
              <label class="field-label" for="nominal-biaya">Nominal</label>
              <input class="field-input rupiah-input" id="nominal-biaya" name="nominal" inputmode="numeric" required
                placeholder="Rp 0" value="<?= $editData ? number_format((float)$editData['nominal'], 0, ',', '.') : '' ?>" />
            </div>
            <label class="master-active-check">
              <input type="checkbox" name="is_active" value="1" <?= !$editData || (int)$editData['is_active'] === 1 ? 'checked' : '' ?> />
              <span>Aktif dan tampil di pembayaran</span>
            </label>
          </div>
          <div class="action-bar" style="margin-top:16px">
            <button type="submit" class="btn btn-primary"><?= $editData ? 'Simpan Perubahan' : 'Tambah Biaya' ?></button>
            <?php if ($editData): ?><a href="master_biaya_lain.php" class="btn btn-ghost">Batal</a><?php endif; ?>
          </div>
        </form>
      </div>

      <div class="main-card" style="margin-top:0">
        <div class="card-title-row"><div class="card-title">Daftar Master Biaya (<?= $masterList->num_rows ?>)</div></div>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead><tr><th>No</th><th>Nama Biaya</th><th>Nominal</th><th>Status</th><th>Dipakai</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php if ($masterList->num_rows === 0): ?>
              <tr><td colspan="6"><div class="empty-state"><p>Belum ada master biaya lain</p><span>Tambahkan biaya dari formulir di atas.</span></div></td></tr>
              <?php else: $no = 1; while ($item = $masterList->fetch_assoc()): ?>
              <tr>
                <td data-label="No"><?= $no++ ?></td>
                <td data-label="Nama Biaya"><strong><?= htmlspecialchars($item['nama']) ?></strong></td>
                <td data-label="Nominal" class="nominal">Rp <?= number_format((float)$item['nominal'], 0, ',', '.') ?></td>
                <td data-label="Status"><span class="master-status <?= $item['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $item['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                <td data-label="Dipakai"><span class="badge-count"><?= (int)$item['jumlah_pemakaian'] ?>x</span></td>
                <td data-label="Aksi" class="aksi-col">
                  <a class="btn-tbl btn-tbl-edit" href="master_biaya_lain.php?edit=<?= (int)$item['id'] ?>">Edit</a>
                  <form method="POST" action="master_biaya_lain.php" style="display:inline">
                    <input type="hidden" name="aksi" value="toggle" /><input type="hidden" name="id" value="<?= (int)$item['id'] ?>" />
                    <button class="btn-tbl btn-tbl-toggle" type="submit"><?= $item['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                  </form>
                  <form method="POST" action="master_biaya_lain.php" style="display:inline" onsubmit="return confirm('Hapus master biaya <?= htmlspecialchars(addslashes($item['nama'])) ?>?')">
                    <input type="hidden" name="aksi" value="hapus" /><input type="hidden" name="id" value="<?= (int)$item['id'] ?>" />
                    <button class="btn-tbl btn-tbl-del" type="submit" <?= (int)$item['jumlah_pemakaian'] > 0 ? 'disabled title="Sudah digunakan pada transaksi"' : '' ?>>Hapus</button>
                  </form>
                </td>
              </tr>
              <?php endwhile; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
  <script src="assets/js/app.js?v=3.1"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var nominal = document.getElementById('nominal-biaya');
      if (nominal) nominal.addEventListener('input', function () {
        var clean = this.value.replace(/\D/g, '');
        this.value = clean ? Number(clean).toLocaleString('id-ID') : '';
      });
      document.getElementById('form-master-biaya')?.addEventListener('submit', function () {
        nominal.value = nominal.value.replace(/\./g, '');
      });
      autoHideFlash();
    });
  </script>
</body>
</html>
