<?php
session_start();
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/kelas.php';
requireRole(['admin']);

if (empty($_SESSION['csrf_master_kelas'])) $_SESSION['csrf_master_kelas'] = bin2hex(random_bytes(32));

function class_master_redirect(): void {
    header('Location: master_kelas.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_master_kelas'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Permintaan tidak valid atau sesi telah kedaluwarsa.');
        }
        $action = (string)($_POST['aksi'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if (in_array($action, ['tambah', 'update'], true)) {
            $level = (int)($_POST['tingkat'] ?? 0);
            $code = strtoupper(trim((string)($_POST['kode_rombel'] ?? '')));
            $code = preg_replace('/\s+/', '', $code);
            if ($level < 1 || $level > 6) throw new RuntimeException('Tingkat kelas harus 1 sampai 6.');
            if (!preg_match('/^[A-Z0-9]{1,10}$/', $code)) throw new RuntimeException('Kode rombel hanya boleh berisi huruf/angka, maksimal 10 karakter.');

            $stmt = $koneksi->prepare('SELECT id FROM master_kelas WHERE tingkat = ? AND kode_rombel = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('isi', $level, $code, $id);
            $stmt->execute(); $duplicate = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($duplicate) throw new RuntimeException('Rombel ' . $level . $code . ' sudah tersedia.');

            if ($action === 'tambah') {
                $stmt = $koneksi->prepare('INSERT INTO master_kelas (tingkat, kode_rombel, is_placeholder, is_active) VALUES (?, ?, 0, 1)');
                $stmt->bind_param('is', $level, $code);
                $stmt->execute(); $stmt->close();
                $message = 'Rombel ' . $level . $code . ' berhasil ditambahkan.';
            } else {
                $class = class_find($koneksi, $id);
                if (!$class || (int)$class['is_placeholder'] === 1) throw new RuntimeException('Rombel placeholder tidak dapat diubah.');
                if ((int)$class['tingkat'] !== $level) {
                    $stmt = $koneksi->prepare('SELECT (SELECT COUNT(*) FROM siswa WHERE master_kelas_id=?) + (SELECT COUNT(*) FROM siswa_tahun_ajaran WHERE master_kelas_id=?) total');
                    $stmt->bind_param('ii',$id,$id);$stmt->execute();$usage=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
                    if ($usage > 0) throw new RuntimeException('Tingkat rombel yang sudah dipakai tidak dapat diubah. Buat rombel baru agar histori tetap benar.');
                }
                $stmt = $koneksi->prepare('UPDATE master_kelas SET tingkat = ?, kode_rombel = ? WHERE id = ?');
                $stmt->bind_param('isi', $level, $code, $id);
                $stmt->execute(); $stmt->close();
                $message = 'Rombel berhasil diperbarui. Snapshot histori lama tetap dipertahankan.';
            }
        } elseif ($action === 'toggle') {
            $class = class_find($koneksi, $id);
            if (!$class || (int)$class['is_placeholder'] === 1) throw new RuntimeException('Rombel placeholder harus selalu aktif.');
            if ((int)$class['is_active'] === 1) {
                $stmt=$koneksi->prepare('SELECT COUNT(*) total FROM siswa WHERE master_kelas_id=? AND is_active=1');$stmt->bind_param('i',$id);$stmt->execute();$activeStudents=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
                if($activeStudents>0)throw new RuntimeException('Rombel masih dipakai '.$activeStudents.' siswa aktif dan belum dapat dinonaktifkan.');
            }
            $stmt = $koneksi->prepare('UPDATE master_kelas SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
            $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
            $message = 'Status rombel berhasil diubah.';
        } elseif ($action === 'promote_year') {
            $sourceLabel = (string)($_POST['source_tahun_ajaran'] ?? '');
            $targetLabel = (string)($_POST['target_tahun_ajaran'] ?? '');
            $koneksi->begin_transaction();
            $result = class_promote_academic_year($koneksi, $sourceLabel, $targetLabel);
            $koneksi->commit();
            $message = 'Naik kelas ' . $result['source'] . ' ke ' . $result['target'] . ' selesai: '
                . number_format((int)$result['promoted']) . ' siswa naik kelas dan '
                . number_format((int)$result['graduated']) . ' siswa kelas 6 diluluskan.';
        } else {
            throw new RuntimeException('Aksi Master Kelas tidak dikenali.');
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $message];
    } catch (Throwable $error) {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $error->getMessage()];
    }
    class_master_redirect();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$editId = (int)($_GET['edit'] ?? 0);
$editClass = $editId > 0 ? class_find($koneksi, $editId) : null;
$classes = $koneksi->query("SELECT mk.*,
    (SELECT COUNT(*) FROM siswa s WHERE s.master_kelas_id = mk.id AND s.is_active=1) AS siswa_count,
    (SELECT COUNT(*) FROM siswa_tahun_ajaran sta WHERE sta.master_kelas_id = mk.id) AS history_count
    FROM master_kelas mk ORDER BY mk.tingkat, mk.is_placeholder, mk.kode_rombel")->fetch_all(MYSQLI_ASSOC);
$academicYears = $koneksi->query("SELECT label,status FROM tahun_ajaran ORDER BY label DESC")->fetch_all(MYSQLI_ASSOC);
$promotionSource = (string)($_GET['source_tahun_ajaran'] ?? ($academicYears[0]['label'] ?? du_current_academic_year()));
try { $promotionSource = du_normalize_academic_year($promotionSource); }
catch (Throwable $ignored) { $promotionSource = du_current_academic_year(); }
$promotionTarget = class_next_academic_year_label($promotionSource);
try { $promotionPreview = class_promotion_preview($koneksi, $promotionSource, $promotionTarget); $promotionPreviewError = ''; }
catch (Throwable $error) { $promotionPreview = ['source'=>$promotionSource,'target'=>$promotionTarget,'total'=>0,'graduated'=>0,'summary'=>[],'missing_classes'=>[]]; $promotionPreviewError = $error->getMessage(); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Master Kelas | SistemSPP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link rel="stylesheet" href="assets/css/style.css?v=7.4">
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
<div class="layout">
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="topbar">
      <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <div class="topbar-title"><h2>Master Kelas &amp; Rombel</h2><span class="breadcrumb">SistemSPP / Data Master / Kelas</span></div>
      <div class="clock-badge" id="liveClock">--:--:--</div>
    </div>
    <?php if ($flash): ?><div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

    <div class="main-card">
      <div class="card-title-row"><div><div class="card-title"><?= $editClass ? 'Edit Rombel' : 'Tambah Rombel' ?></div><p class="payment-auto-note">Tingkat tetap 1–6. Kode rombel membentuk label seperti 1A, 1B, atau 2C.</p></div></div>
      <form method="post" class="report-filter-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
        <input type="hidden" name="aksi" value="<?= $editClass ? 'update' : 'tambah' ?>"><input type="hidden" name="id" value="<?= (int)($editClass['id'] ?? 0) ?>">
        <div class="field-row"><label class="field-label">Tingkat</label><select class="field-input field-select" name="tingkat" required><?php for($i=1;$i<=6;$i++): ?><option value="<?= $i ?>" <?= (int)($editClass['tingkat'] ?? 1)===$i?'selected':'' ?>>Kelas <?= $i ?></option><?php endfor; ?></select></div>
        <div class="field-row"><label class="field-label">Kode Rombel</label><input class="field-input" name="kode_rombel" maxlength="10" required placeholder="Contoh: A" value="<?= htmlspecialchars((string)($editClass['kode_rombel'] ?? '')) ?>" <?= $editClass && (int)$editClass['is_placeholder']===1?'disabled':'' ?>></div>
        <div class="report-filter-actions"><button class="btn btn-primary" type="submit" <?= $editClass && (int)$editClass['is_placeholder']===1?'disabled':'' ?>><?= $editClass?'Simpan Perubahan':'Tambah Rombel' ?></button><?php if($editClass): ?><a class="btn btn-ghost" href="master_kelas.php">Batal</a><?php endif; ?></div>
      </form>
    </div>

    <div class="main-card">
      <div class="card-title-row"><div><div class="card-title">Daftar Kelas/Rombel</div><p class="payment-auto-note">Placeholder menjaga data lama tetap valid sampai siswa dipindahkan ke rombel sebenarnya.</p></div></div>
      <div class="table-container"><table class="payment-table responsive-table"><thead><tr><th>No</th><th>Label</th><th>Tingkat</th><th>Status</th><th>Siswa Aktif</th><th>Histori</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach($classes as $i=>$class): $label=class_label($class); ?>
        <tr><td data-label="No"><?= $i+1 ?></td><td data-label="Label"><strong><?= htmlspecialchars($label) ?></strong></td><td data-label="Tingkat">Kelas <?= (int)$class['tingkat'] ?></td><td data-label="Status"><span class="master-status <?= (int)$class['is_active']===1?'is-active':'is-inactive' ?>"><?= (int)$class['is_placeholder']===1?'Placeholder':((int)$class['is_active']===1?'Aktif':'Nonaktif') ?></span></td><td data-label="Siswa Aktif"><?= number_format((int)$class['siswa_count']) ?></td><td data-label="Histori"><?= number_format((int)$class['history_count']) ?></td><td data-label="Aksi" class="aksi-col">
        <?php if((int)$class['is_placeholder']!==1): ?><a class="btn-tbl btn-tbl-edit" href="master_kelas.php?edit=<?= (int)$class['id'] ?>">Edit</a><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>"><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int)$class['id'] ?>"><button class="btn-tbl btn-tbl-toggle" type="submit"><?= (int)$class['is_active']===1?'Nonaktifkan':'Aktifkan' ?></button></form><?php else: ?><span class="payment-auto-note">Dikelola sistem</span><?php endif; ?>
        </td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </div>

    <div class="main-card">
      <div class="card-title-row">
        <div><div class="card-title">Naik Kelas Tahun Ajaran</div><p class="payment-auto-note">Siswa kelas 1 sampai 5 naik otomatis dengan huruf rombel yang sama. Kelas 6 diluluskan dan diarsipkan.</p></div>
      </div>
      <form method="get" class="report-filter-grid">
        <div class="field-row"><label class="field-label">Tahun Ajaran Sumber</label><select class="field-input field-select" name="source_tahun_ajaran" onchange="this.form.submit()"><?php foreach($academicYears as $year): ?><option value="<?= htmlspecialchars($year['label']) ?>" <?= $promotionSource===$year['label']?'selected':'' ?>><?= htmlspecialchars($year['label'].' · '.$year['status']) ?></option><?php endforeach; ?></select></div>
        <div class="field-row"><label class="field-label">Target Berikutnya</label><input class="field-input" value="<?= htmlspecialchars($promotionTarget) ?>" readonly></div>
      </form>
      <?php if($promotionPreviewError): ?><div class="alert alert-error"><?= htmlspecialchars($promotionPreviewError) ?></div><?php endif; ?>
      <div class="report-summary-grid" style="margin-top:16px">
        <div class="report-summary-card"><span>Total Diproses</span><strong><?= number_format((int)$promotionPreview['total']) ?> siswa</strong></div>
        <div class="report-summary-card"><span>Kelas 6 Lulus</span><strong><?= number_format((int)$promotionPreview['graduated']) ?> siswa</strong></div>
        <div class="report-summary-card"><span>Rombel Dibuat</span><strong><?= number_format(count($promotionPreview['missing_classes'])) ?></strong></div>
      </div>
      <div class="table-container" style="margin-top:16px"><table class="payment-table responsive-table"><thead><tr><th>No</th><th>Dari</th><th>Menjadi</th><th>Jumlah Siswa</th></tr></thead><tbody>
        <?php if(empty($promotionPreview['summary'])): ?><tr><td colspan="4"><div class="empty-state"><p>Tidak ada siswa aktif pada tahun ajaran sumber.</p></div></td></tr><?php else: foreach($promotionPreview['summary'] as $index=>$row): ?>
        <tr><td data-label="No"><?= $index+1 ?></td><td data-label="Dari"><?= htmlspecialchars($row['source']) ?></td><td data-label="Menjadi"><?= htmlspecialchars($row['target']) ?></td><td data-label="Jumlah Siswa"><?= number_format((int)$row['count']) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
      <?php if(!empty($promotionPreview['missing_classes'])): ?><p class="payment-auto-note" style="margin-top:10px">Rombel target yang belum ada akan dibuat otomatis: <?= htmlspecialchars(implode(', ', array_map(static fn($row) => $row['tingkat'].$row['kode_rombel'], $promotionPreview['missing_classes']))) ?>.</p><?php endif; ?>
      <form method="post" style="margin-top:16px" onsubmit="return confirm('Proses naik kelas dari <?= htmlspecialchars($promotionSource, ENT_QUOTES) ?> ke <?= htmlspecialchars($promotionTarget, ENT_QUOTES) ?>? Pastikan tagihan tahun target belum diterbitkan.')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_master_kelas']) ?>">
        <input type="hidden" name="aksi" value="promote_year">
        <input type="hidden" name="source_tahun_ajaran" value="<?= htmlspecialchars($promotionSource) ?>">
        <input type="hidden" name="target_tahun_ajaran" value="<?= htmlspecialchars($promotionTarget) ?>">
        <button class="btn btn-primary" type="submit" <?= $promotionPreviewError || (int)$promotionPreview['total'] <= 0 ? 'disabled' : '' ?>>Proses Naik Kelas</button>
      </form>
    </div>
  </main>
</div>
<script src="assets/js/app.js?v=7.4"></script><script>document.addEventListener('DOMContentLoaded',function(){autoHideFlash();});</script>
</body></html>
