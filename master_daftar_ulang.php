<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
requireRole(['admin']);

if (empty($_SESSION['csrf_master_du'])) {
    $_SESSION['csrf_master_du'] = bin2hex(random_bytes(32));
}

function master_du_amount($value): float {
    if ($value === null || $value === '') return 0.0;
    return (float)str_replace(['.', ','], ['', '.'], (string)$value);
}

function master_du_redirect(): void {
    header('Location: master_daftar_ulang.php');
    exit;
}

function normalize_academic_year(string $value): string {
    $value = trim($value);
    if (!preg_match('/^(\d{4})\/(\d{4})$/', $value, $match)) {
        throw new RuntimeException('Tahun ajaran harus memakai format YYYY/YYYY, contoh 2026/2027.');
    }
    if ((int)$match[2] !== (int)$match[1] + 1) {
        throw new RuntimeException('Tahun ajaran tidak valid. Tahun kedua harus satu tahun setelah tahun pertama.');
    }
    return $value;
}

function current_academic_year(): string {
    $year = (int)date('Y');
    $month = (int)date('n');
    $start = $month >= 7 ? $year : $year - 1;
    return $start . '/' . ($start + 1);
}

function academic_year_options(array $existingYears = [], array $includeYears = [], int $range = 3): array {
    $activeYear = current_academic_year();
    $activeStart = (int)substr($activeYear, 0, 4);
    $options = [];

    for ($offset = -$range; $offset <= $range; $offset++) {
        $start = $activeStart + $offset;
        $label = $start . '/' . ($start + 1);
        $options[$label] = $label;
    }

    foreach (array_merge($existingYears, $includeYears) as $year) {
        $year = trim((string)$year);
        if (preg_match('/^\d{4}\/\d{4}$/', $year)) {
            $options[$year] = $year;
        }
    }

    krsort($options);
    return array_values($options);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_master_du'], $postedToken)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Permintaan tidak valid atau sesi telah kedaluwarsa.'];
        master_du_redirect();
    }

    $aksi = $_POST['aksi'] ?? '';

    try {
        if ($aksi === 'tambah' || $aksi === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $tahunAjaran = normalize_academic_year((string)($_POST['th_ajaran'] ?? ''));
            $kelas = trim((string)($_POST['kelas'] ?? ''));
            $nominal = master_du_amount($_POST['jumlah'] ?? 0);

            if (!in_array($kelas, ['1', '2', '3', '4', '5', '6'], true)) {
                throw new RuntimeException('Kelas daftar ulang harus dipilih dari kelas 1 sampai 6.');
            }
            if ($nominal <= 0) {
                throw new RuntimeException('Nominal daftar ulang harus lebih dari Rp 0.');
            }

            $stmtDuplikat = $koneksi->prepare('SELECT id FROM Daftar_ulang WHERE th_ajaran = ? AND kelas = ? AND id <> ? LIMIT 1');
            $stmtDuplikat->bind_param('ssi', $tahunAjaran, $kelas, $id);
            $stmtDuplikat->execute();
            $duplikat = $stmtDuplikat->get_result()->fetch_assoc();
            $stmtDuplikat->close();

            if ($duplikat) {
                throw new RuntimeException('Master daftar ulang untuk kelas dan tahun ajaran tersebut sudah tersedia.');
            }

            if ($aksi === 'tambah') {
                $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang (th_ajaran, kelas, Jumlah) VALUES (?, ?, ?)');
                $stmt->bind_param('ssd', $tahunAjaran, $kelas, $nominal);
                $berhasil = $stmt->execute();
                $stmt->close();
                $_SESSION['flash'] = $berhasil
                    ? ['type' => 'success', 'msg' => 'Master daftar ulang berhasil ditambahkan.']
                    : ['type' => 'error', 'msg' => 'Master daftar ulang gagal ditambahkan.'];
            } else {
                if ($id <= 0) master_du_redirect();
                $stmt = $koneksi->prepare('UPDATE Daftar_ulang SET th_ajaran = ?, kelas = ?, Jumlah = ? WHERE id = ?');
                $stmt->bind_param('ssdi', $tahunAjaran, $kelas, $nominal, $id);
                $berhasil = $stmt->execute();
                $stmt->close();
                $_SESSION['flash'] = $berhasil
                    ? ['type' => 'success', 'msg' => 'Master daftar ulang berhasil diperbarui. Histori transaksi lama tetap tersimpan.']
                    : ['type' => 'error', 'msg' => 'Master daftar ulang gagal diperbarui.'];
            }
            master_du_redirect();
        }

        if ($aksi === 'hapus') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) master_du_redirect();

            $stmtMaster = $koneksi->prepare('SELECT th_ajaran, kelas FROM Daftar_ulang WHERE id = ? LIMIT 1');
            $stmtMaster->bind_param('i', $id);
            $stmtMaster->execute();
            $master = $stmtMaster->get_result()->fetch_assoc();
            $stmtMaster->close();
            if (!$master) throw new RuntimeException('Master daftar ulang tidak ditemukan.');

            $stmtCount = $koneksi->prepare('SELECT COUNT(*) AS jumlah FROM bayar_du WHERE th_ajaran = ? AND kelas = ?');
            $stmtCount->bind_param('ss', $master['th_ajaran'], $master['kelas']);
            $stmtCount->execute();
            $jumlahPemakaian = (int)$stmtCount->get_result()->fetch_assoc()['jumlah'];
            $stmtCount->close();

            if ($jumlahPemakaian > 0) {
                throw new RuntimeException('Master sudah dipakai pada transaksi daftar ulang dan tidak dapat dihapus.');
            }

            $stmt = $koneksi->prepare('DELETE FROM Daftar_ulang WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Master daftar ulang berhasil dihapus.'];
            master_du_redirect();
        }
    } catch (Throwable $error) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $error->getMessage()];
        master_du_redirect();
    }
}

$editData = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $koneksi->prepare('SELECT * FROM Daftar_ulang WHERE id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$yearRows = $koneksi->query("
    SELECT DISTINCT th_ajaran
    FROM Daftar_ulang
    WHERE th_ajaran IS NOT NULL AND th_ajaran <> ''
    ORDER BY th_ajaran DESC
")->fetch_all(MYSQLI_ASSOC);
$yearOptions = array_column($yearRows, 'th_ajaran');
$activeAcademicYear = current_academic_year();
$yearOptions = academic_year_options($yearOptions, [$editData['th_ajaran'] ?? null]);
$defaultYear = $editData['th_ajaran'] ?? $activeAcademicYear;

$masterList = $koneksi->query("
    SELECT du.id, du.th_ajaran, du.kelas, du.Jumlah, COUNT(bd.id) AS jumlah_pemakaian
    FROM Daftar_ulang du
    LEFT JOIN bayar_du bd ON bd.th_ajaran = du.th_ajaran AND bd.kelas = du.kelas
    GROUP BY du.id, du.th_ajaran, du.kelas, du.Jumlah
    ORDER BY du.th_ajaran DESC, CAST(du.kelas AS UNSIGNED) ASC, du.id DESC
");
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Master Daftar Ulang | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css?v=4.7" />
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
        <div class="topbar-title"><h2>Master Daftar Ulang</h2><span class="breadcrumb">SistemSPP / Master Daftar Ulang</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title"><?= $editData ? 'Edit Master Daftar Ulang' : 'Tambah Master Daftar Ulang' ?></div>
        </div>
        <form method="POST" action="master_daftar_ulang.php" id="form-master-daftar-ulang">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_du']) ?>" />
          <input type="hidden" name="aksi" value="<?= $editData ? 'update' : 'tambah' ?>" />
          <input type="hidden" name="id" value="<?= (int)($editData['id'] ?? 0) ?>" />
          <div class="fields-grid master-fee-form">
            <div class="field-row">
              <label class="field-label" for="tahun-ajaran-master-du">Tahun Ajaran</label>
              <select class="field-input field-select" id="tahun-ajaran-master-du" name="th_ajaran" required>
                <?php foreach ($yearOptions as $year): ?>
                <option value="<?= htmlspecialchars($year) ?>" <?= $defaultYear === $year ? 'selected' : '' ?>>
                  <?= htmlspecialchars($year) ?><?= $activeAcademicYear === $year ? ' - Tahun ajaran aktif' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label" for="kelas-master-du">Kelas</label>
              <select class="field-input field-select" id="kelas-master-du" name="kelas" required>
                <option value="">-- Pilih Kelas --</option>
                <?php $selectedClass = (string)($editData['kelas'] ?? ''); for ($kl = 1; $kl <= 6; $kl++): ?>
                <option value="<?= $kl ?>" <?= $selectedClass === (string)$kl ? 'selected' : '' ?>>Kelas <?= $kl ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label" for="nominal-master-du">Nominal Daftar Ulang</label>
              <input class="field-input rupiah-input" id="nominal-master-du" name="jumlah" inputmode="numeric" required
                placeholder="Rp 0" value="<?= $editData ? number_format((float)$editData['Jumlah'], 0, ',', '.') : '' ?>" />
            </div>
          </div>
          <div class="action-bar" style="margin-top:16px">
            <button type="submit" class="btn btn-primary"><?= $editData ? 'Simpan Perubahan' : 'Tambah Master' ?></button>
            <?php if ($editData): ?><a href="master_daftar_ulang.php" class="btn btn-ghost">Batal</a><?php endif; ?>
          </div>
        </form>
      </div>

      <div class="main-card" style="margin-top:0">
        <div class="card-title-row"><div class="card-title">Daftar Master Daftar Ulang (<?= $masterList->num_rows ?>)</div></div>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead><tr><th>No</th><th>Tahun Ajaran</th><th>Kelas</th><th>Nominal</th><th>Dipakai</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php if ($masterList->num_rows === 0): ?>
              <tr><td colspan="6"><div class="empty-state"><p>Belum ada master daftar ulang</p><span>Tambahkan nominal daftar ulang per kelas dan tahun ajaran dari formulir di atas.</span></div></td></tr>
              <?php else: $no = 1; while ($item = $masterList->fetch_assoc()): ?>
              <tr>
                <td data-label="No"><?= $no++ ?></td>
                <td data-label="Tahun Ajaran"><strong><?= htmlspecialchars($item['th_ajaran']) ?></strong></td>
                <td data-label="Kelas">Kelas <?= htmlspecialchars($item['kelas']) ?></td>
                <td data-label="Nominal" class="nominal">Rp <?= number_format((float)$item['Jumlah'], 0, ',', '.') ?></td>
                <td data-label="Dipakai"><span class="badge-count"><?= (int)$item['jumlah_pemakaian'] ?>x</span></td>
                <td data-label="Aksi" class="aksi-col">
                  <a class="btn-tbl btn-tbl-edit" href="master_daftar_ulang.php?edit=<?= (int)$item['id'] ?>">Edit</a>
                  <form method="POST" action="master_daftar_ulang.php" style="display:inline" onsubmit="return confirm('Hapus master daftar ulang kelas <?= htmlspecialchars($item['kelas']) ?> tahun <?= htmlspecialchars($item['th_ajaran']) ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_du']) ?>" />
                    <input type="hidden" name="aksi" value="hapus" />
                    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>" />
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
  <script src="assets/js/app.js?v=4.2"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var nominal = document.getElementById('nominal-master-du');
      if (nominal) nominal.addEventListener('input', function () {
        var clean = this.value.replace(/\D/g, '');
        this.value = clean ? Number(clean).toLocaleString('id-ID') : '';
      });
      document.getElementById('form-master-daftar-ulang')?.addEventListener('submit', function () {
        if (nominal) nominal.value = nominal.value.replace(/\./g, '');
      });
      autoHideFlash();
    });
  </script>
</body>
</html>
