<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin']);

if (empty($_SESSION['csrf_student'])) {
    $_SESSION['csrf_student'] = bin2hex(random_bytes(32));
}

function student_amount($value): float {
    if ($value === null || $value === '') return 0.0;
    $normalized = str_replace(['.', ','], ['', '.'], trim((string)$value));
    $amount = is_numeric($normalized) ? (float)$normalized : NAN;
    if (!is_finite($amount) || $amount < 0 || $amount > 9999999999999.99) {
        throw new RuntimeException('Nominal harus berupa angka positif atau nol.');
    }
    return $amount;
}

function student_history_count(mysqli $db, string $noInduk): int {
    $sql = "
        SELECT
          (SELECT COUNT(*) FROM bayar WHERE NO_INDUK = ?) +
          (SELECT COUNT(*) FROM bayar_du WHERE no_induk = ?) +
          (SELECT COUNT(*) FROM transaksi_m WHERE NO_INDUK = ?) +
          (SELECT COUNT(*) FROM transaksi_k WHERE NO_INDUK = ?) AS jumlah
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ssss', $noInduk, $noInduk, $noInduk, $noInduk);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['jumlah'];
    $stmt->close();
    return $count;
}

function find_student(mysqli $db, int $id, bool $forUpdate = false): ?array {
    $stmt = $db->prepare('SELECT * FROM siswa WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($student) $student['history_count'] = student_history_count($db, $student['NO_INDUK']);
    return $student;
}

function student_snapshot(array $student): array {
    $keys = [
        'id', 'NO_INDUK', 'NAMA', 'KELAS', 'SPP_PERBULAN', 'PANGKAL', 'BANGUNAN',
        'SERAGAM', 'KEGIATAN', 'PANGKAL_BAYAR', 'BANGUNAN_BAYAR', 'SERAGAM_BAYAR',
        'KEGIATAN_BAYAR', 'POMG', 'DAFTAR_ULANG', 'NO_induk_diknas',
        'potong_pangkal', 'tot_pangkal', 'tot_du', 'potong_du', 'is_active'
    ];
    return array_intersect_key($student, array_flip($keys));
}

function write_student_audit(mysqli $db, int $studentId, string $noInduk, string $action, ?array $before, ?array $after): void {
    $beforeJson = $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $afterJson = $after ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $adminName = (string)($_SESSION['admin_nama'] ?? 'Administrator');
    $stmt = $db->prepare("
        INSERT INTO siswa_audit_log
          (siswa_id, no_induk_snapshot, aksi, before_data, after_data, admin_id, admin_name)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('issssis', $studentId, $noInduk, $action, $beforeJson, $afterJson, $adminId, $adminName);
    $stmt->execute();
    $stmt->close();
}

function student_redirect(string $location = 'daftar.php'): void {
    header('Location: ' . $location);
    exit;
}

function fail_student(string $message, array $oldInput, string $location): void {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $message];
    $_SESSION['student_old_input'] = $oldInput;
    student_redirect($location);
}

function validate_student_identity(array $source, ?string $legacyClass = null): array {
    $noInduk = trim((string)($source['no_induk'] ?? ''));
    $name = trim((string)($source['nama'] ?? ''));
    $class = trim((string)($source['kelas'] ?? ''));
    if (!preg_match('/^[0-9]{1,10}$/', $noInduk)) {
        throw new RuntimeException('Nomor induk wajib berupa 1 sampai 10 digit.');
    }
    if ($name === '' || mb_strlen($name) > 100) {
        throw new RuntimeException('Nama siswa wajib diisi dan maksimal 100 karakter.');
    }
    $isExistingLegacyClass = $legacyClass !== null && $class === $legacyClass;
    if (!in_array($class, ['1','2','3','4','5','6'], true) && !$isExistingLegacyClass) {
        throw new RuntimeException('Kelas siswa hanya boleh 1 sampai 6. Label kelas lama hanya dapat dipertahankan pada data yang sudah ada.');
    }
    return [$noInduk, $name, $class];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$oldInput = $_SESSION['student_old_input'] ?? [];
unset($_SESSION['student_old_input']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['aksi'] ?? '';
    $returnLocation = 'daftar.php';
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_student'], $postedToken)) {
        fail_student('Permintaan tidak valid atau sesi telah kedaluwarsa.', $_POST, $returnLocation);
    }

    try {
        if ($action === 'tambah' || $action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($action === 'update') $returnLocation .= '?edit=' . $id;
            $koneksi->begin_transaction();
            $oldStudent = $action === 'update' ? find_student($koneksi, $id, true) : null;
            if ($action === 'update' && !$oldStudent) throw new RuntimeException('Data siswa tidak ditemukan.');
            [$noInduk, $name, $class] = validate_student_identity($_POST, $oldStudent['KELAS'] ?? null);

            $stmtDuplicate = $koneksi->prepare('SELECT id FROM siswa WHERE NO_INDUK = ? AND id <> ? LIMIT 1');
            $stmtDuplicate->bind_param('si', $noInduk, $id);
            $stmtDuplicate->execute();
            $duplicate = $stmtDuplicate->get_result()->fetch_assoc();
            $stmtDuplicate->close();
            if ($duplicate) throw new RuntimeException('Nomor induk sudah digunakan siswa lain.');

            $advanced = isset($_POST['advanced_enabled']) && $_POST['advanced_enabled'] === '1';
            $advancedColumns = [
                'SPP_PERBULAN', 'PANGKAL', 'BANGUNAN', 'SERAGAM', 'KEGIATAN',
                'POMG', 'DAFTAR_ULANG', 'potong_pangkal', 'potong_du'
            ];
            $postMap = [
                'SPP_PERBULAN' => 'spp_perbulan', 'PANGKAL' => 'pangkal',
                'BANGUNAN' => 'bangunan', 'SERAGAM' => 'seragam',
                'KEGIATAN' => 'kegiatan', 'POMG' => 'pomg',
                'DAFTAR_ULANG' => 'daftar_ulang', 'potong_pangkal' => 'potong_pangkal',
                'potong_du' => 'potong_du'
            ];
            $values = [];
            foreach ($advancedColumns as $column) {
                $values[$column] = $advanced
                    ? student_amount($_POST[$postMap[$column]] ?? 0)
                    : (float)($oldStudent[$column] ?? 0);
            }
            $nisDiknas = $advanced
                ? trim((string)($_POST['no_induk_diknas'] ?? ''))
                : (string)($oldStudent['NO_induk_diknas'] ?? '');
            if ($nisDiknas !== '' && !preg_match('/^[0-9]{10}$/', $nisDiknas)) {
                throw new RuntimeException('No. Induk Diknas harus tepat 10 digit jika diisi.');
            }
            if ($values['potong_pangkal'] > $values['PANGKAL']) {
                throw new RuntimeException('Potongan uang pangkal tidak boleh melebihi tagihan pangkal.');
            }
            if ($values['potong_du'] > $values['DAFTAR_ULANG']) {
                throw new RuntimeException('Potongan daftar ulang tidak boleh melebihi tagihan daftar ulang.');
            }
            $values['tot_pangkal'] = max(0, $values['PANGKAL'] - $values['potong_pangkal']);
            $values['tot_du'] = max(0, $values['DAFTAR_ULANG'] - $values['potong_du']);

            $openingMap = [
                'PANGKAL_BAYAR' => 'pangkal_bayar', 'BANGUNAN_BAYAR' => 'bangunan_bayar',
                'SERAGAM_BAYAR' => 'seragam_bayar', 'KEGIATAN_BAYAR' => 'kegiatan_bayar'
            ];
            $canEditOpening = !$oldStudent || (int)$oldStudent['history_count'] === 0;
            foreach ($openingMap as $column => $postName) {
                $oldValue = (float)($oldStudent[$column] ?? 0);
                if ($advanced && $canEditOpening) {
                    $values[$column] = student_amount($_POST[$postName] ?? 0);
                } else {
                    $values[$column] = $oldValue;
                    if (!$canEditOpening && isset($_POST[$postName]) && student_amount($_POST[$postName]) !== $oldValue) {
                        throw new RuntimeException('Saldo awal tidak dapat diubah setelah siswa memiliki histori transaksi.');
                    }
                }
            }
            $openingLimits = [
                'PANGKAL_BAYAR' => $values['tot_pangkal'],
                'BANGUNAN_BAYAR' => $values['BANGUNAN'],
                'SERAGAM_BAYAR' => $values['SERAGAM'],
                'KEGIATAN_BAYAR' => $values['KEGIATAN']
            ];
            foreach ($openingLimits as $column => $limit) {
                if ($values[$column] > $limit) throw new RuntimeException('Saldo awal tidak boleh melebihi nilai tagihan.');
            }

            $spp = $values['SPP_PERBULAN'];
            $pangkal = $values['PANGKAL'];
            $bangunan = $values['BANGUNAN'];
            $seragam = $values['SERAGAM'];
            $kegiatan = $values['KEGIATAN'];
            $pangkalBayar = $values['PANGKAL_BAYAR'];
            $bangunanBayar = $values['BANGUNAN_BAYAR'];
            $seragamBayar = $values['SERAGAM_BAYAR'];
            $kegiatanBayar = $values['KEGIATAN_BAYAR'];
            $pomg = $values['POMG'];
            $daftarUlang = $values['DAFTAR_ULANG'];
            $potongPangkal = $values['potong_pangkal'];
            $totPangkal = $values['tot_pangkal'];
            $totDu = $values['tot_du'];
            $potongDu = $values['potong_du'];
            $active = (int)($oldStudent['is_active'] ?? 1);

            if ($action === 'tambah') {
                $stmt = $koneksi->prepare("
                    INSERT INTO siswa (
                      NO_INDUK, NAMA, KELAS, SPP_PERBULAN, PANGKAL, BANGUNAN, SERAGAM,
                      KEGIATAN, PANGKAL_BAYAR, BANGUNAN_BAYAR, SERAGAM_BAYAR, KEGIATAN_BAYAR,
                      POMG, DAFTAR_ULANG, NO_induk_diknas, potong_pangkal, tot_pangkal,
                      tot_du, potong_du, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    'sssdddddddddddsddddi',
                    $noInduk, $name, $class, $spp, $pangkal, $bangunan, $seragam, $kegiatan,
                    $pangkalBayar, $bangunanBayar, $seragamBayar, $kegiatanBayar, $pomg,
                    $daftarUlang, $nisDiknas, $potongPangkal, $totPangkal, $totDu, $potongDu, $active
                );
                $stmt->execute();
                $id = $koneksi->insert_id;
                $stmt->close();
                $after = find_student($koneksi, $id);
                write_student_audit($koneksi, $id, $noInduk, 'tambah', null, student_snapshot($after));
                $successMessage = "Siswa $name berhasil ditambahkan.";
            } else {
                $before = student_snapshot($oldStudent);
                $stmt = $koneksi->prepare("
                    UPDATE siswa SET
                      NO_INDUK=?, NAMA=?, KELAS=?, SPP_PERBULAN=?, PANGKAL=?, BANGUNAN=?,
                      SERAGAM=?, KEGIATAN=?, PANGKAL_BAYAR=?, BANGUNAN_BAYAR=?,
                      SERAGAM_BAYAR=?, KEGIATAN_BAYAR=?, POMG=?, DAFTAR_ULANG=?,
                      NO_induk_diknas=NULLIF(?, ''), potong_pangkal=?, tot_pangkal=?,
                      tot_du=?, potong_du=? WHERE id=?
                ");
                $stmt->bind_param(
                    'sssdddddddddddsddddi',
                    $noInduk, $name, $class, $spp, $pangkal, $bangunan, $seragam, $kegiatan,
                    $pangkalBayar, $bangunanBayar, $seragamBayar, $kegiatanBayar, $pomg,
                    $daftarUlang, $nisDiknas, $potongPangkal, $totPangkal, $totDu, $potongDu, $id
                );
                $stmt->execute();
                $stmt->close();
                $after = find_student($koneksi, $id);
                write_student_audit($koneksi, $id, $noInduk, 'update', $before, student_snapshot($after));
                $successMessage = "Data siswa $name berhasil diperbarui.";
            }
            $koneksi->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $successMessage];
            student_redirect('daftar.php');
        }

        if ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $koneksi->begin_transaction();
            $student = find_student($koneksi, $id, true);
            if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');
            $before = student_snapshot($student);
            $newStatus = (int)$student['is_active'] === 1 ? 0 : 1;
            $stmt = $koneksi->prepare('UPDATE siswa SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $newStatus, $id);
            $stmt->execute();
            $stmt->close();
            $after = find_student($koneksi, $id);
            $auditAction = $newStatus ? 'pulihkan' : 'arsipkan';
            write_student_audit($koneksi, $id, $student['NO_INDUK'], $auditAction, $before, student_snapshot($after));
            $koneksi->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $newStatus ? 'Siswa berhasil dipulihkan.' : 'Siswa berhasil diarsipkan.'];
            student_redirect('daftar.php');
        }
    } catch (Throwable $error) {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
        fail_student($error->getMessage(), $_POST, $returnLocation);
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editStudent = $editId > 0 ? find_student($koneksi, $editId) : null;
if ($editId > 0 && !$editStudent) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Data siswa tidak ditemukan.'];
    student_redirect('daftar.php');
}

$query = trim((string)($_GET['q'] ?? ''));
$filterClass = (string)($_GET['kelas'] ?? '');
$classOptions = [];
$classResult = $koneksi->query("SELECT DISTINCT KELAS FROM siswa WHERE KELAS <> '' ORDER BY KELAS");
while ($classRow = $classResult->fetch_assoc()) $classOptions[] = (string)$classRow['KELAS'];
if ($filterClass !== '' && !in_array($filterClass, $classOptions, true)) $filterClass = '';
$filterStatus = (string)($_GET['status'] ?? 'active');
if (!in_array($filterStatus, ['active', 'archived', 'all'], true)) $filterStatus = 'active';

$stmtList = $koneksi->prepare("
    SELECT s.*,
      (SELECT COUNT(*) FROM bayar p WHERE p.NO_INDUK = s.NO_INDUK) AS jml_bayar,
      ((SELECT COUNT(*) FROM bayar p WHERE p.NO_INDUK = s.NO_INDUK) +
       (SELECT COUNT(*) FROM bayar_du du WHERE du.no_induk = s.NO_INDUK) +
       (SELECT COUNT(*) FROM transaksi_m tm WHERE tm.NO_INDUK = s.NO_INDUK) +
       (SELECT COUNT(*) FROM transaksi_k tk WHERE tk.NO_INDUK = s.NO_INDUK)) AS history_count
    FROM siswa s
    WHERE (? = '' OR s.NO_INDUK LIKE CONCAT('%', ?, '%') OR s.NAMA LIKE CONCAT('%', ?, '%'))
      AND (? = '' OR s.KELAS = ?)
      AND (? = 'all' OR s.is_active = IF(? = 'archived', 0, 1))
    ORDER BY s.is_active DESC,
      CASE WHEN s.KELAS REGEXP '^[1-6]$' THEN 0 ELSE 1 END,
      CAST(s.KELAS AS UNSIGNED), s.KELAS, s.NAMA ASC
");
$stmtList->bind_param('sssssss', $query, $query, $query, $filterClass, $filterClass, $filterStatus, $filterStatus);
$stmtList->execute();
$studentRows = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtList->close();

$formStudent = $editStudent ?? [];
$fieldMap = [
    'no_induk' => 'NO_INDUK', 'nama' => 'NAMA', 'kelas' => 'KELAS',
    'no_induk_diknas' => 'NO_induk_diknas', 'spp_perbulan' => 'SPP_PERBULAN',
    'pangkal' => 'PANGKAL', 'bangunan' => 'BANGUNAN', 'seragam' => 'SERAGAM',
    'kegiatan' => 'KEGIATAN', 'pomg' => 'POMG', 'daftar_ulang' => 'DAFTAR_ULANG',
    'potong_pangkal' => 'potong_pangkal', 'potong_du' => 'potong_du',
    'pangkal_bayar' => 'PANGKAL_BAYAR', 'bangunan_bayar' => 'BANGUNAN_BAYAR',
    'seragam_bayar' => 'SERAGAM_BAYAR', 'kegiatan_bayar' => 'KEGIATAN_BAYAR'
];
function form_student_value(string $key, array $oldInput, array $student, array $fieldMap, $default = '') {
    if (array_key_exists($key, $oldInput)) return $oldInput[$key];
    $column = $fieldMap[$key] ?? $key;
    return $student[$column] ?? $default;
}
function rupiah_value($value): string {
    return number_format((float)$value, 0, ',', '.');
}
$advancedOpen = isset($oldInput['advanced_enabled']) && $oldInput['advanced_enabled'] === '1';
$canEditOpening = !$editStudent || (int)($editStudent['history_count'] ?? 0) === 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Data Siswa | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=4.7" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
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
        <div class="topbar-title"><h2>Data Siswa</h2><span class="breadcrumb">SistemSPP / Data Siswa</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title"><?= $editStudent ? 'Edit Siswa' : 'Tambah Siswa Baru' ?></div>
          <?php if ($editStudent): ?><span class="master-status <?= $editStudent['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $editStudent['is_active'] ? 'Aktif' : 'Diarsipkan' ?></span><?php endif; ?>
        </div>
        <form method="POST" action="daftar.php" id="form-master-siswa" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_student']) ?>" />
          <input type="hidden" name="aksi" value="<?= $editStudent ? 'update' : 'tambah' ?>" />
          <input type="hidden" name="id" value="<?= (int)($editStudent['id'] ?? 0) ?>" />
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label" for="nis-baru">No. Induk</label>
              <input class="field-input" type="text" inputmode="numeric" maxlength="10" id="nis-baru" name="no_induk" required
                value="<?= htmlspecialchars((string)form_student_value('no_induk', $oldInput, $formStudent, $fieldMap)) ?>" />
            </div>
            <div class="field-row">
              <label class="field-label" for="nama-baru">Nama Lengkap</label>
              <input class="field-input" type="text" maxlength="100" id="nama-baru" name="nama" required
                value="<?= htmlspecialchars((string)form_student_value('nama', $oldInput, $formStudent, $fieldMap)) ?>" />
            </div>
            <div class="field-row">
              <label class="field-label" for="kelas-baru">Kelas</label>
              <select class="field-input field-select" id="kelas-baru" name="kelas" required>
                <option value="">-- Pilih Kelas --</option>
                <?php
                $selectedClass = (string)form_student_value('kelas', $oldInput, $formStudent, $fieldMap);
                $isLegacySelectedClass = $selectedClass !== '' && !in_array($selectedClass, ['1','2','3','4','5','6'], true);
                if ($isLegacySelectedClass):
                ?>
                <option value="<?= htmlspecialchars($selectedClass) ?>" selected>Kelas <?= htmlspecialchars($selectedClass) ?> (data lama)</option>
                <?php endif; for ($class = 1; $class <= 6; $class++): ?>
                <option value="<?= $class ?>" <?= $selectedClass === (string)$class ? 'selected' : '' ?>>Kelas <?= $class ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>

          <label class="advanced-switch" for="advanced-enabled">
            <span><strong>Advance</strong><small>Data tarif dan saldo awal siswa</small></span>
            <input type="checkbox" id="advanced-enabled" name="advanced_enabled" value="1" <?= $advancedOpen ? 'checked' : '' ?> />
            <span class="advanced-switch-track"><span></span></span>
          </label>

          <div class="student-advanced-panel <?= $advancedOpen ? 'is-open' : '' ?>" id="student-advanced-panel">
            <div class="section-divider"><span>Identitas Sekolah</span></div>
            <div class="fields-grid">
              <div class="field-row">
                <label class="field-label" for="nis-diknas">No. Induk Diknas</label>
                <input class="field-input advanced-field" type="text" inputmode="numeric" maxlength="10" id="nis-diknas" name="no_induk_diknas"
                  value="<?= htmlspecialchars((string)form_student_value('no_induk_diknas', $oldInput, $formStudent, $fieldMap)) ?>" />
              </div>
            </div>

            <div class="section-divider"><span>Tarif Siswa</span></div>
            <div class="fields-grid student-money-grid">
              <?php
              $feeFields = [
                'spp_perbulan' => 'SPP per Bulan', 'pangkal' => 'Uang Pangkal',
                'bangunan' => 'Uang Bangunan', 'seragam' => 'Uang Seragam',
                'kegiatan' => 'Uang Kegiatan', 'pomg' => 'Uang Komite',
                'daftar_ulang' => 'Uang Daftar Ulang'
              ];
              foreach ($feeFields as $key => $label):
              ?>
              <div class="field-row">
                <label class="field-label" for="student-<?= $key ?>"><?= $label ?></label>
                <input class="field-input rupiah-input advanced-field student-fee-input" type="text" inputmode="numeric" id="student-<?= $key ?>" name="<?= $key ?>"
                  value="<?= rupiah_value(form_student_value($key, $oldInput, $formStudent, $fieldMap, 0)) ?>" />
              </div>
              <?php endforeach; ?>
            </div>

            <div class="section-divider"><span>Potongan</span></div>
            <div class="fields-grid student-money-grid">
              <div class="field-row">
                <label class="field-label" for="student-potong-pangkal">Potongan Pangkal</label>
                <input class="field-input rupiah-input advanced-field derived-source" type="text" inputmode="numeric" id="student-potong-pangkal" name="potong_pangkal"
                  value="<?= rupiah_value(form_student_value('potong_pangkal', $oldInput, $formStudent, $fieldMap, 0)) ?>" />
              </div>
              <div class="field-row">
                <label class="field-label" for="student-total-pangkal">Total Pangkal Setelah Potongan</label>
                <input class="field-input student-derived" type="text" id="student-total-pangkal" readonly value="<?= rupiah_value($editStudent['tot_pangkal'] ?? 0) ?>" />
              </div>
              <div class="field-row">
                <label class="field-label" for="student-potong-du">Potongan Daftar Ulang</label>
                <input class="field-input rupiah-input advanced-field derived-source" type="text" inputmode="numeric" id="student-potong-du" name="potong_du"
                  value="<?= rupiah_value(form_student_value('potong_du', $oldInput, $formStudent, $fieldMap, 0)) ?>" />
              </div>
              <div class="field-row">
                <label class="field-label" for="student-total-du">Total Daftar Ulang Setelah Potongan</label>
                <input class="field-input student-derived" type="text" id="student-total-du" readonly value="<?= rupiah_value($editStudent['tot_du'] ?? 0) ?>" />
              </div>
            </div>

            <div class="section-divider"><span>Migrasi Saldo Awal</span></div>
            <div class="fields-grid student-money-grid">
              <?php
              $openingFields = [
                'pangkal_bayar' => 'Pangkal Sudah Dibayar', 'bangunan_bayar' => 'Bangunan Sudah Dibayar',
                'seragam_bayar' => 'Seragam Sudah Dibayar', 'kegiatan_bayar' => 'Kegiatan Sudah Dibayar'
              ];
              foreach ($openingFields as $key => $label):
              ?>
              <div class="field-row">
                <label class="field-label" for="student-<?= $key ?>"><?= $label ?></label>
                <input class="field-input rupiah-input advanced-field opening-balance" type="text" inputmode="numeric" id="student-<?= $key ?>" name="<?= $key ?>"
                  value="<?= rupiah_value(form_student_value($key, $oldInput, $formStudent, $fieldMap, 0)) ?>" <?= $canEditOpening ? '' : 'disabled' ?> />
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="action-bar" style="margin-top:18px">
            <button type="submit" class="btn btn-primary"><?= $editStudent ? 'Simpan Perubahan' : 'Tambah Siswa' ?></button>
            <?php if ($editStudent): ?><a href="daftar.php" class="btn btn-ghost">Batal</a><?php endif; ?>
          </div>
        </form>
      </div>

      <div class="main-card" style="margin-top:0">
        <div class="card-title-row"><div class="card-title">Daftar Siswa (<?= count($studentRows) ?>)</div></div>
        <form method="GET" action="daftar.php" class="filter-bar student-filter-bar">
          <div class="search-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Cari nama atau No. Induk..." />
          </div>
          <select class="field-input field-select filter-sel" name="kelas">
            <option value="">Semua Kelas</option>
            <?php foreach ($classOptions as $class): ?><option value="<?= htmlspecialchars($class) ?>" <?= $filterClass === $class ? 'selected' : '' ?>>Kelas <?= htmlspecialchars($class) ?></option><?php endforeach; ?>
          </select>
          <select class="field-input field-select filter-sel" name="status">
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Aktif</option>
            <option value="archived" <?= $filterStatus === 'archived' ? 'selected' : '' ?>>Diarsipkan</option>
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
          </select>
          <button class="btn btn-primary" type="submit">Filter</button>
        </form>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead><tr><th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th><th>SPP/Bulan</th><th>Status</th><th class="student-history-col">Riwayat Transaksi</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php if (!$studentRows): ?>
              <tr><td colspan="8"><div class="empty-state"><p>Data siswa tidak ditemukan</p></div></td></tr>
              <?php else: foreach ($studentRows as $index => $student):
                $editUrl = 'daftar.php?edit=' . (int)$student['id'];
              ?>
              <tr class="clickable-payment-row" data-edit-url="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" tabindex="0" role="link" aria-label="Edit siswa <?= htmlspecialchars($student['NAMA'], ENT_QUOTES, 'UTF-8') ?>">
                <td data-label="No"><?= $index + 1 ?></td>
                <td data-label="No. Induk"><span class="badge-nis"><?= htmlspecialchars($student['NO_INDUK']) ?></span></td>
                <td data-label="Nama Siswa"><?= htmlspecialchars($student['NAMA']) ?></td>
                <td data-label="Kelas">Kelas <?= htmlspecialchars($student['KELAS']) ?></td>
                <td data-label="SPP/Bulan" class="nominal">Rp <?= number_format((float)$student['SPP_PERBULAN'], 0, ',', '.') ?></td>
                <td data-label="Status"><span class="master-status <?= $student['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $student['is_active'] ? 'Aktif' : 'Diarsipkan' ?></span></td>
                <td data-label="Riwayat Transaksi" class="student-history-col"><span class="badge-count"><?= (int)$student['history_count'] ?>x</span></td>
                <td data-label="Aksi" class="aksi-col">
                  <a class="btn-tbl btn-tbl-edit" href="<?= htmlspecialchars($editUrl) ?>">Edit</a>
                  <form method="POST" action="daftar.php" style="display:inline" onsubmit="return confirm('<?= $student['is_active'] ? 'Arsipkan' : 'Pulihkan' ?> siswa <?= htmlspecialchars(addslashes($student['NAMA'])) ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_student']) ?>" />
                    <input type="hidden" name="aksi" value="toggle_status" /><input type="hidden" name="id" value="<?= (int)$student['id'] ?>" />
                    <button type="submit" class="btn-tbl btn-tbl-toggle"><?= $student['is_active'] ? 'Arsipkan' : 'Pulihkan' ?></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <script src="../assets/js/app.js?v=3.8"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const toggle = document.getElementById('advanced-enabled');
      const panel = document.getElementById('student-advanced-panel');
      const moneyInputs = Array.from(document.querySelectorAll('.rupiah-input:not(:disabled)'));
      const format = value => {
        const clean = String(value || '').replace(/\D/g, '');
        return clean ? Number(clean).toLocaleString('id-ID') : '0';
      };
      const number = id => Number((document.getElementById(id)?.value || '0').replace(/\./g, '')) || 0;
      const updateDerived = () => {
        document.getElementById('student-total-pangkal').value = format(Math.max(0, number('student-pangkal') - number('student-potong-pangkal')));
        document.getElementById('student-total-du').value = format(Math.max(0, number('student-daftar_ulang') - number('student-potong-du')));
      };
      const syncPanel = () => panel.classList.toggle('is-open', toggle.checked);
      toggle.addEventListener('change', syncPanel);
      moneyInputs.forEach(input => input.addEventListener('input', function () { this.value = format(this.value); updateDerived(); }));
      document.getElementById('form-master-siswa').addEventListener('submit', function () {
        if (toggle.checked) moneyInputs.forEach(input => input.value = input.value.replace(/\./g, ''));
      });
      syncPanel();
      updateDerived();
      autoHideFlash();
    });
  </script>
</body>
</html>
