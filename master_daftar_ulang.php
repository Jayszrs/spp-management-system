<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/daftar_ulang.php';
requireRole(['admin']);

if (empty($_SESSION['csrf_master_du'])) $_SESSION['csrf_master_du'] = bin2hex(random_bytes(32));

function master_du_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function master_du_amount($value): float { return (float)str_replace(['.', ','], ['', '.'], trim((string)$value)); }
function master_du_redirect(string $year): void { header('Location: master_daftar_ulang.php?tahun=' . urlencode($year)); exit; }
function master_du_year(mysqli $db, string $label, bool $lock = false): array {
    $stmt = $db->prepare('SELECT * FROM tahun_ajaran WHERE label = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $label); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) throw new RuntimeException('Tahun ajaran tidak ditemukan.');
    return $row;
}
function master_du_ensure_year(mysqli $db, string $label): array {
    [$start, $end] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, 'draft') ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $stmt->bind_param('sss', $label, $start, $end); $stmt->execute(); $stmt->close();
    return master_du_year($db, $label);
}

$selectedYear = trim((string)($_GET['tahun'] ?? $_POST['tahun_ajaran'] ?? du_current_academic_year()));
try { $selectedYear = du_normalize_academic_year($selectedYear); }
catch (Throwable $e) { $selectedYear = du_current_academic_year(); }
master_du_ensure_year($koneksi, $selectedYear);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_master_du'], $token)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Permintaan tidak valid atau sesi telah kedaluwarsa.'];
        master_du_redirect($selectedYear);
    }
    $action = (string)($_POST['aksi'] ?? '');
    try {
        $koneksi->begin_transaction();
        $year = master_du_ensure_year($koneksi, $selectedYear);
        $year = master_du_year($koneksi, $selectedYear, true);
        $yearId = (int)$year['id'];

        if ($action === 'simpan_tarif') {
            if ($year['status'] === 'closed') throw new RuntimeException('Tahun ajaran sudah ditutup; tarif tidak dapat diubah.');
            $amounts = $_POST['jumlah'] ?? [];
            for ($class = 1; $class <= 6; $class++) {
                $amount = master_du_amount($amounts[(string)$class] ?? $amounts[$class] ?? 0);
                if ($amount <= 0) throw new RuntimeException('Nominal kelas ' . $class . ' harus lebih dari Rp 0.');

                $stmt = $koneksi->prepare('SELECT id, Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id = ? AND kelas = ? LIMIT 1 FOR UPDATE');
                $classText = (string)$class;
                $stmt->bind_param('is', $yearId, $classText); $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();

                if (!$existing) {
                    if ($year['status'] !== 'draft') throw new RuntimeException('Tarif kelas ' . $class . ' tidak dapat ditambahkan setelah tahun ajaran diterbitkan.');
                    $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang (tahun_ajaran_id, th_ajaran, kelas, Jumlah) VALUES (?, ?, ?, ?)');
                    $stmt->bind_param('issd', $yearId, $selectedYear, $classText, $amount); $stmt->execute();
                    $masterId = (int)$koneksi->insert_id; $stmt->close();
                    du_write_audit($koneksi, (int)$year['id'], $masterId, 'buat_tarif', null, ['kelas' => $classText, 'jumlah' => $amount]);
                    continue;
                }

                $oldAmount = (float)$existing['Jumlah'];
                if (abs($oldAmount - $amount) < 0.001) continue;
                $affected = 0;
                if ($year['status'] === 'published') {
                    $stmt = $koneksi->prepare("SELECT tdu.id, tdu.nominal_tagihan, COALESCE(SUM(bd.jumlah),0) AS paid
                        FROM tagihan_daftar_ulang tdu
                        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
                        WHERE tdu.master_daftar_ulang_id = ? AND tdu.status = 'open'
                        GROUP BY tdu.id, tdu.nominal_tagihan FOR UPDATE");
                    $existingId = (int)$existing['id'];
                    $stmt->bind_param('i', $existingId); $stmt->execute();
                    $bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
                    foreach ($bills as $bill) {
                        $paid = (float)$bill['paid']; $currentTotal = (float)$bill['nominal_tagihan'];
                        if ($paid + 0.001 >= $currentTotal) continue;
                        if ($paid > $amount + 0.001) throw new RuntimeException('Nominal baru kelas ' . $class . ' lebih kecil daripada cicilan siswa yang sudah masuk.');
                        $stmtUpdate = $koneksi->prepare('UPDATE tagihan_daftar_ulang SET nominal_tagihan = ? WHERE id = ?');
                        $billId = (int)$bill['id'];
                        $stmtUpdate->bind_param('di', $amount, $billId); $stmtUpdate->execute(); $stmtUpdate->close();
                        $affected++;
                    }
                }
                $stmt = $koneksi->prepare('UPDATE Daftar_ulang SET Jumlah = ? WHERE id = ?');
                $existingId = (int)$existing['id'];
                $stmt->bind_param('di', $amount, $existingId); $stmt->execute(); $stmt->close();
                du_write_audit($koneksi, (int)$year['id'], (int)$existing['id'], 'ubah_tarif', ['jumlah' => $oldAmount], ['jumlah' => $amount], $affected);
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $year['status'] === 'draft' ? 'Enam tarif Daftar Ulang berhasil disimpan sebagai draf.' : 'Tarif dan seluruh tagihan yang belum lunas berhasil diperbarui.'];
        } elseif ($action === 'siapkan_penempatan') {
            if ($year['status'] !== 'draft') throw new RuntimeException('Penempatan hanya dapat disiapkan saat tahun ajaran masih draf.');
            $start = (int)substr($selectedYear, 0, 4);
            $previous = ($start - 1) . '/' . $start;
            $stmt = $koneksi->prepare("INSERT IGNORE INTO siswa_tahun_ajaran (tahun_ajaran_id, no_induk, kelas, status)
                SELECT ?, prev.no_induk,
                       CASE WHEN prev.kelas IN ('1','2','3','4','5') THEN CAST(CAST(prev.kelas AS UNSIGNED)+1 AS CHAR) ELSE '6' END,
                       CASE WHEN prev.kelas = '6' THEN 'lulus' ELSE 'aktif' END
                FROM siswa_tahun_ajaran prev JOIN tahun_ajaran pta ON pta.id = prev.tahun_ajaran_id
                WHERE pta.label = ? AND prev.status = 'aktif'");
            $stmt->bind_param('is', $yearId, $previous); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare("INSERT IGNORE INTO siswa_tahun_ajaran (tahun_ajaran_id, no_induk, kelas, status)
                SELECT ?, s.NO_INDUK, s.KELAS, 'aktif' FROM siswa s
                WHERE s.is_active = 1 AND s.KELAS IN ('1','2','3','4','5','6')");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
            du_write_audit($koneksi, (int)$year['id'], null, 'siapkan_penempatan', null, ['tahun_sebelumnya' => $previous]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Pratinjau penempatan disiapkan. Periksa kelas, siswa tinggal kelas, pindah, dan lulusan sebelum menerbitkan tagihan.'];
        } elseif ($action === 'simpan_penempatan') {
            if ($year['status'] === 'closed') throw new RuntimeException('Penempatan tahun ajaran yang ditutup tidak dapat diubah.');
            $placements = $_POST['penempatan'] ?? [];
            foreach ($placements as $id => $values) {
                $id = (int)$id; $class = (string)($values['kelas'] ?? ''); $status = (string)($values['status'] ?? 'aktif');
                if (!in_array($class, ['1','2','3','4','5','6'], true) || !in_array($status, ['aktif','pindah','lulus'], true)) throw new RuntimeException('Data penempatan tidak valid.');
                $stmt = $koneksi->prepare("SELECT sta.kelas,sta.status,tdu.id AS bill_id,COALESCE(SUM(bd.jumlah),0) paid
                    FROM siswa_tahun_ajaran sta
                    LEFT JOIN tagihan_daftar_ulang tdu ON tdu.penempatan_id=sta.id
                    LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id=tdu.id
                    WHERE sta.id=? AND sta.tahun_ajaran_id=?
                    GROUP BY sta.id,sta.kelas,sta.status,tdu.id LIMIT 1 FOR UPDATE");
                $stmt->bind_param('ii', $id, $yearId); $stmt->execute(); $oldPlacement=$stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$oldPlacement) throw new RuntimeException('Penempatan siswa tidak ditemukan.');
                if ($oldPlacement['kelas']===$class && $oldPlacement['status']===$status) continue;
                if ($year['status']==='published' && (float)$oldPlacement['paid'] > 0) throw new RuntimeException('Penempatan yang sudah memiliki pembayaran tidak dapat diubah. Edit/hapus pembayaran terkait terlebih dahulu.');
                $stmt = $koneksi->prepare('UPDATE siswa_tahun_ajaran SET kelas = ?, status = ? WHERE id = ? AND tahun_ajaran_id = ?');
                $stmt->bind_param('ssii', $class, $status, $id, $yearId); $stmt->execute(); $stmt->close();
                if ($year['status']==='published') {
                    $billId = (int)($oldPlacement['bill_id'] ?? 0);
                    if ($status !== 'aktif') {
                        if ($billId > 0) {
                            $reason = $status === 'lulus' ? 'Siswa berstatus lulus' : 'Siswa berstatus pindah';
                            $stmt = $koneksi->prepare("UPDATE tagihan_daftar_ulang SET status='cancelled',cancel_reason=? WHERE id=?");
                            $stmt->bind_param('si', $reason, $billId); $stmt->execute(); $stmt->close();
                        }
                    } else {
                        $stmt = $koneksi->prepare('SELECT id,Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id=? AND kelas=? AND Jumlah>0 LIMIT 1');
                        $stmt->bind_param('is', $yearId, $class); $stmt->execute(); $newMaster=$stmt->get_result()->fetch_assoc(); $stmt->close();
                        if (!$newMaster) throw new RuntimeException('Tarif kelas ' . $class . ' belum tersedia.');
                        if ($billId > 0) {
                            $newMasterId=(int)$newMaster['id']; $newAmount=(float)$newMaster['Jumlah'];
                            $stmt=$koneksi->prepare("UPDATE tagihan_daftar_ulang SET master_daftar_ulang_id=?,kelas_snapshot=?,nominal_awal=?,nominal_tagihan=?,status='open',cancel_reason=NULL WHERE id=?");
                            $stmt->bind_param('isddi',$newMasterId,$class,$newAmount,$newAmount,$billId); $stmt->execute(); $stmt->close();
                        } else {
                            du_create_bill_for_placement($koneksi, $id);
                        }
                    }
                }
            }
            du_write_audit($koneksi, (int)$year['id'], null, 'ubah_penempatan', null, ['jumlah' => count($placements)], count($placements));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Penempatan siswa berhasil disimpan.'];
        } elseif ($action === 'terbitkan') {
            if ($year['status'] !== 'draft') throw new RuntimeException('Tahun ajaran ini sudah pernah diterbitkan.');
            $stmt = $koneksi->prepare("SELECT COUNT(DISTINCT kelas) AS total FROM Daftar_ulang WHERE tahun_ajaran_id = ? AND kelas IN ('1','2','3','4','5','6') AND Jumlah > 0");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $masterCount = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close();
            if ($masterCount !== 6) throw new RuntimeException('Lengkapi nominal Daftar Ulang kelas 1 sampai 6 sebelum menerbitkan.');
            $stmt = $koneksi->prepare("SELECT COUNT(*) AS missing FROM siswa s LEFT JOIN siswa_tahun_ajaran sta ON sta.no_induk=s.NO_INDUK AND sta.tahun_ajaran_id=? WHERE s.is_active=1 AND sta.id IS NULL");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $missing = (int)$stmt->get_result()->fetch_assoc()['missing']; $stmt->close();
            if ($missing > 0) throw new RuntimeException($missing . ' siswa aktif belum memiliki penempatan tahun ajaran.');
            $stmt = $koneksi->prepare("INSERT IGNORE INTO tagihan_daftar_ulang
                (tahun_ajaran_id, penempatan_id, master_daftar_ulang_id, no_induk, kelas_snapshot, tahun_ajaran_snapshot, nominal_awal, nominal_tagihan)
                SELECT sta.tahun_ajaran_id, sta.id, du.id, sta.no_induk, sta.kelas, ?, du.Jumlah, du.Jumlah
                FROM siswa_tahun_ajaran sta JOIN Daftar_ulang du ON du.tahun_ajaran_id=sta.tahun_ajaran_id AND du.kelas=sta.kelas
                WHERE sta.tahun_ajaran_id=? AND sta.status='aktif'");
            $stmt->bind_param('si', $selectedYear, $yearId); $stmt->execute(); $created = $stmt->affected_rows; $stmt->close();
            $stmt = $koneksi->prepare("UPDATE tahun_ajaran SET status='published', published_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
            du_write_audit($koneksi, (int)$year['id'], null, 'terbitkan_tagihan', ['status' => 'draft'], ['status' => 'published'], $created);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $created . ' tagihan Daftar Ulang berhasil diterbitkan.'];
        } elseif ($action === 'tutup') {
            if ($year['status'] !== 'published') throw new RuntimeException('Hanya tahun ajaran terbit yang dapat ditutup.');
            $stmt = $koneksi->prepare("UPDATE tahun_ajaran SET status='closed', closed_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
            du_write_audit($koneksi, (int)$year['id'], null, 'tutup_tahun', ['status'=>'published'], ['status'=>'closed']);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Tahun ajaran ditutup. Tunggakan yang sudah terbit tetap dapat dilunasi.'];
        } else {
            throw new RuntimeException('Aksi Master Daftar Ulang tidak dikenal.');
        }
        $koneksi->commit();
    } catch (Throwable $error) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $error->getMessage()];
    }
    master_du_redirect($selectedYear);
}

$yearRowsRaw = $koneksi->query('SELECT * FROM tahun_ajaran ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);
$yearRowsByLabel = [];
foreach ($yearRowsRaw as $yearRow) $yearRowsByLabel[$yearRow['label']] = $yearRow;
$currentStart = (int)substr(du_current_academic_year(), 0, 4);
for ($offset = -2; $offset <= 3; $offset++) {
    $start = $currentStart + $offset; $label = $start . '/' . ($start + 1);
    if (!isset($yearRowsByLabel[$label])) $yearRowsByLabel[$label] = ['label'=>$label, 'status'=>'draft'];
}
krsort($yearRowsByLabel);
$yearRows = array_values($yearRowsByLabel);
$year = master_du_year($koneksi, $selectedYear);
$yearId = (int)$year['id'];
$masters = array_fill(1, 6, 0.0);
$stmt = $koneksi->prepare('SELECT kelas, Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id=? ORDER BY CAST(kelas AS UNSIGNED)');
$stmt->bind_param('i', $yearId); $stmt->execute(); $result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $masters[(int)$row['kelas']] = (float)$row['Jumlah'];
$stmt->close();
$stmt = $koneksi->prepare("SELECT sta.id,sta.no_induk,sta.kelas,sta.status,s.NAMA,
    (SELECT COUNT(*) FROM tagihan_daftar_ulang tdu JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id=tdu.id WHERE tdu.penempatan_id=sta.id) payment_count
    FROM siswa_tahun_ajaran sta JOIN siswa s ON s.NO_INDUK=sta.no_induk WHERE sta.tahun_ajaran_id=? ORDER BY FIELD(sta.status,'aktif','pindah','lulus'),CAST(sta.kelas AS UNSIGNED),s.NAMA");
$stmt->bind_param('i', $yearId); $stmt->execute(); $placements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$summary = array_fill(1, 6, ['students'=>0,'total'=>0]);
foreach ($placements as $placement) if ($placement['status']==='aktif') { $c=(int)$placement['kelas']; $summary[$c]['students']++; $summary[$c]['total'] += $masters[$c]; }
$stmt = $koneksi->prepare("SELECT COUNT(*) bills, COALESCE(SUM(tdu.nominal_tagihan),0) total,
    COALESCE(SUM(p.paid),0) paid FROM tagihan_daftar_ulang tdu
    LEFT JOIN (SELECT tagihan_daftar_ulang_id,SUM(jumlah) paid FROM bayar_du GROUP BY tagihan_daftar_ulang_id) p ON p.tagihan_daftar_ulang_id=tdu.id
    WHERE tdu.tahun_ajaran_id=? AND tdu.status='open'");
$stmt->bind_param('i', $yearId); $stmt->execute(); $billSummary=$stmt->get_result()->fetch_assoc(); $stmt->close();
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Master Daftar Ulang | SistemSPP</title><link rel="icon" type="image/png" href="assets/img/favicon.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css?v=5.5"><script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script></head><body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div><div class="layout"><?php include 'includes/sidebar.php'; ?><main class="main-content">
<div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button><div class="topbar-title"><h2>Master Daftar Ulang</h2><span class="breadcrumb">SistemSPP / Tahun Ajaran / Daftar Ulang</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
<?php if ($flash): ?><div class="alert alert-<?= master_du_e($flash['type']) ?>" id="flash-msg"><?= master_du_e($flash['msg']) ?></div><?php endif; ?>
<div class="main-card du-master-hero"><div><span class="recap-class-overline">Konfigurasi Tahun Ajaran</span><h1><?= master_du_e($selectedYear) ?></h1><p>Periode 1 Juli <?= substr($selectedYear,0,4) ?> sampai 30 Juni <?= substr($selectedYear,5,4) ?>.</p></div><div class="du-master-year-tools"><form method="get"><select class="field-input field-select" name="tahun" onchange="this.form.submit()"><?php foreach($yearRows as $yr): ?><option value="<?= master_du_e($yr['label']) ?>" <?= $yr['label']===$selectedYear?'selected':'' ?>><?= master_du_e($yr['label']) ?> · <?= master_du_e(strtoupper($yr['status'])) ?></option><?php endforeach; ?></select></form><span class="recap-status <?= $year['status']==='draft'?'is-partial':($year['status']==='published'?'is-paid':'is-unpaid') ?>"><?= master_du_e(strtoupper($year['status'])) ?></span></div></div>

<div class="main-card"><div class="card-title-row"><div class="card-title">Tarif Kelas 1–6</div></div><form method="post" id="du-rate-form"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="simpan_tarif"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><div class="du-rate-grid"><?php for($c=1;$c<=6;$c++): ?><label class="field-row"><span class="field-label">Kelas <?= $c ?></span><input class="field-input rupiah-input" name="jumlah[<?= $c ?>]" inputmode="numeric" value="<?= $masters[$c]>0?number_format($masters[$c],0,',','.') : '' ?>" placeholder="Rp 0" <?= $year['status']==='closed'?'disabled':'' ?>><small><?= $summary[$c]['students'] ?> siswa · estimasi Rp <?= number_format($summary[$c]['total'],0,',','.') ?></small></label><?php endfor; ?></div><?php if($year['status']!=='closed'): ?><div class="action-bar"><button class="btn btn-primary" type="submit">Simpan Enam Tarif</button></div><?php endif; ?></form></div>

<div class="main-card"><div class="card-title-row"><div><div class="card-title">Penempatan Siswa</div><p class="payment-auto-note">Kelas disimpan per tahun ajaran. Status lulus/pindah tidak menerima tagihan baru. Penempatan yang sudah dibayar harus dikoreksi dari transaksinya terlebih dahulu.</p></div><?php if($year['status']==='draft'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="siapkan_penempatan"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-ghost" type="submit">Siapkan / Lengkapi Pratinjau</button></form><?php endif; ?></div>
<?php if(!$placements): ?><div class="empty-state"><p>Penempatan belum disiapkan</p><span>Gunakan tombol pratinjau untuk menyalin dan menaikkan kelas siswa.</span></div><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="simpan_penempatan"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><div class="table-container"><table class="payment-table responsive-table"><thead><tr><th>No</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Status</th><th>Pembayaran</th></tr></thead><tbody><?php foreach($placements as $i=>$p): ?><tr><td><?= $i+1 ?></td><td><?= master_du_e($p['no_induk']) ?></td><td><strong><?= master_du_e($p['NAMA']) ?></strong></td><td><select class="field-input field-select" name="penempatan[<?= (int)$p['id'] ?>][kelas]" <?= $year['status']==='closed'?'disabled':'' ?>><?php for($c=1;$c<=6;$c++): ?><option value="<?= $c ?>" <?= (string)$p['kelas']===(string)$c?'selected':'' ?>>Kelas <?= $c ?></option><?php endfor; ?></select></td><td><select class="field-input field-select" name="penempatan[<?= (int)$p['id'] ?>][status]" <?= $year['status']==='closed'?'disabled':'' ?>><?php foreach(['aktif'=>'Aktif','pindah'=>'Pindah','lulus'=>'Lulus'] as $v=>$l): ?><option value="<?= $v ?>" <?= $p['status']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></td><td><?= (int)$p['payment_count'] ?> transaksi</td></tr><?php endforeach; ?></tbody></table></div><?php if($year['status']!=='closed'): ?><div class="action-bar"><button class="btn btn-primary" type="submit">Simpan Penempatan</button></div><?php endif; ?></form><?php endif; ?></div>

<div class="main-card"><div class="card-title-row"><div><div class="card-title">Penerbitan Tagihan</div><p class="payment-auto-note"><?= (int)$billSummary['bills'] ?> tagihan · Total Rp <?= number_format((float)$billSummary['total'],0,',','.') ?> · Terbayar Rp <?= number_format((float)$billSummary['paid'],0,',','.') ?></p></div><div class="action-bar"><?php if($year['status']==='draft'): ?><form method="post" onsubmit="return confirm('Terbitkan seluruh tagihan <?= master_du_e($selectedYear) ?>? Tahun dan kelas tidak dapat dipindahkan setelah terbit.')"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="terbitkan"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-primary" type="submit">Terbitkan Tagihan</button></form><?php elseif($year['status']==='published'): ?><form method="post" onsubmit="return confirm('Tutup tahun ajaran? Tunggakan tetap dapat dibayar, tetapi tarif tidak dapat diubah lagi.')"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="tutup"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-warning" type="submit">Tutup Tahun Ajaran</button></form><?php endif; ?></div></div></div>
</main></div><script src="assets/js/app.js?v=4.6"></script><script>document.querySelectorAll('.rupiah-input').forEach(function(el){el.addEventListener('input',function(){var n=this.value.replace(/\D/g,'');this.value=n?Number(n).toLocaleString('id-ID'):'';});});document.getElementById('du-rate-form')?.addEventListener('submit',function(){this.querySelectorAll('.rupiah-input').forEach(function(el){el.value=el.value.replace(/\./g,'');});});autoHideFlash();</script></body></html>
