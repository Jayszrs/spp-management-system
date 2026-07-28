<?php
// ============================================
// pembayaran/edit.php
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: lihat.php'); exit; }

$stmt = $koneksi->prepare("SELECT p.*, s.NO_INDUK, s.NAMA, s.KELAS FROM bayar p JOIN siswa s ON s.NO_INDUK = p.NO_INDUK WHERE p.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$d) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Data tidak ditemukan!']; header('Location: lihat.php'); exit; }

// Ambil uang daftar ulang dari bayar_du jika ada
$stmt_du = $koneksi->prepare("SELECT jumlah FROM bayar_du WHERE no_induk = ? AND th_ajaran = ? LIMIT 1");
$stmt_du->bind_param('ss', $d['NO_INDUK'], $d['th_ajaran']);
$stmt_du->execute();
$res_du = $stmt_du->get_result()->fetch_assoc();
$d['uang_du'] = $res_du ? (float)$res_du['jumlah'] : 0.0;
$stmt_du->close();

// Ambil tabungan wajib dari transaksi_m jika ada
$stmt_tab = $koneksi->prepare("SELECT MASUK FROM transaksi_m WHERE NO_INDUK = ? AND DATE(TANGGAL) = DATE(?) LIMIT 1");
$stmt_tab->bind_param('ss', $d['NO_INDUK'], $d['TGL_BYR']);
$stmt_tab->execute();
$res_tab = $stmt_tab->get_result()->fetch_assoc();
$d['tabungan_wajib'] = $res_tab ? (float)$res_tab['MASUK'] : 0.0;
$stmt_tab->close();

$d['kewajiban_spp'] = max(0, $d['U_SPP'] - $d['potong_spp'] - $d['tabungan_wajib']);

$siswa_list = $koneksi->query("SELECT id, NO_INDUK, NAMA, KELAS FROM siswa ORDER BY NAMA ASC");
$bln = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Pembayaran | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Edit data transaksi pembayaran siswa." />
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
          <h2>Edit Pembayaran</h2>
          <span class="breadcrumb">SistemSPP / Pembayaran / Edit</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit Data Pembayaran
          </div>
          <span class="badge-id">ID #<?= $d['id'] ?></span>
        </div>

        <form method="POST" action="../pembayaran/proses.php" id="form-bayar">
          <input type="hidden" name="aksi" value="update" />
          <input type="hidden" name="id" value="<?= $d['id'] ?>" />

          <!-- Tanggal + Jumlah -->
          <div class="top-info-row">
            <div class="info-group">
              <div class="field-row">
                <label class="field-label" for="tgl-bayar">Tanggal Bayar</label>
                <input class="field-input" type="date" id="tgl-bayar" name="tanggal_bayar"
                  value="<?= date('Y-m-d', strtotime($d['TGL_BYR'])) ?>" required />
              </div>
              <div class="field-row">
                <label class="field-label">Pembayaran Bulan</label>
                <div class="field-group-inline">
                  <select class="field-input field-select" name="bulan_bayar" id="bulan-bayar" required>
                    <?php foreach ($bln as $b): ?>
                    <option <?= $d['BULAN'] === $b ? 'selected' : '' ?>><?=$b?></option>
                    <?php endforeach; ?>
                  </select>
                  <select class="field-input field-select" name="tahun_bayar" id="tahun-bayar" style="max-width:90px">
                    <?php for ($y = date('Y')-1; $y <= date('Y')+1; $y++): ?>
                    <option <?= $d['TAHUN'] == $y ? 'selected' : '' ?>><?=$y?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="jumlah-box">
              <span class="jumlah-label">Total Jumlah</span>
              <span class="jumlah-value" id="totalJumlah">Rp <?= number_format($d['total_jumlah'],0,',','.') ?></span>
              <input type="hidden" name="total_jumlah" id="hidden-total" value="<?= $d['total_jumlah'] ?>" />
            </div>
          </div>

          <!-- Data Siswa -->
          <div class="section-divider"><span>Data Siswa</span></div>
          <div class="fields-grid">
            <div class="field-row full-span">
              <label class="field-label" for="siswa-search">Cari Siswa (Ketik Nama / No. Induk)</label>
              <div class="search-box">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="siswa-search" list="siswa-list" 
                  value="<?= htmlspecialchars($d['NO_INDUK']) ?> — <?= htmlspecialchars($d['NAMA']) ?>" 
                  placeholder="Ketik nama atau No. Induk..." oninput="pilihSiswaDatalist(this)" autocomplete="off" />
              </div>
              <datalist id="siswa-list">
                <?php while ($s = $siswa_list->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($s['NO_INDUK']) ?> — <?= htmlspecialchars($s['NAMA']) ?>"
                  data-nis="<?= htmlspecialchars($s['NO_INDUK']) ?>"
                  data-nama="<?= htmlspecialchars($s['NAMA']) ?>"
                  data-kelas="<?= htmlspecialchars($s['KELAS']) ?>">
                  <?= htmlspecialchars($s['NAMA']) ?> (Kelas <?= htmlspecialchars($s['KELAS']) ?>)
                </option>
                <?php endwhile; ?>
              </datalist>
            </div>
            <div class="field-row">
              <label class="field-label">No. Induk</label>
              <input class="field-input" type="text" id="disp-nis" name="no_induk" value="<?= htmlspecialchars($d['NO_INDUK']) ?>" readonly required />
            </div>
            <div class="field-row">
              <label class="field-label">Nama Siswa</label>
              <input class="field-input" type="text" id="disp-nama" value="<?= htmlspecialchars($d['NAMA']) ?>" readonly />
            </div>
            <div class="field-row">
              <label class="field-label">Kelas</label>
              <input class="field-input" type="text" id="disp-kelas" value="<?= htmlspecialchars($d['KELAS']) ?>" readonly />
            </div>
          </div>

          <!-- Rincian Pembayaran -->
          <div class="section-divider"><span>Rincian Pembayaran</span></div>
          <div class="table-container">
            <table class="payment-table form-payment-table">
              <thead>
                <tr>
                  <th>Komponen Bayar</th>
                  <th>Total Sebelum (Rp)</th>
                  <th>Sudah Terbayar (Rp)</th>
                  <th>Sisa (Rp)</th>
                  <th>Input Bayar (Rp)</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $komp = [
                  ['pangkal', '💰 Uang Pangkal', 'U_PANGKAL', 'uang_pangkal'],
                  ['bangunan', '🏗️ Uang Bangunan', 'U_BANGUNAN', 'uang_bangunan'],
                  ['seragam', '👔 Uang Seragam', 'U_SERAGAM', 'uang_seragam'],
                  ['kegiatan', '🎡 Uang Kegiatan', 'U_KEGIATAN', 'uang_kegiatan'],
                  ['spp', '🎓 Uang SPP', 'U_SPP', 'uang_spp'],
                  ['makan', '🍽️ Uang Makan', 'U_MAKAN', 'uang_makan'],
                  ['sorga', '🌅 Uang Sorga', 'U_SORGA', 'uang_sorga'],
                  ['infaq', '🕌 Uang Infaq', 'U_INFAQ', 'uang_infaq'],
                  ['lain', '💵 Uang Lain', 'U_LAIN', 'uang_lain'],
                  ['du', '📚 Uang Daftar Ulang', 'uang_du', 'uang_du']
                ];
                foreach ($komp as $i => [$key,$label,$col,$inputName]):
                ?>
                <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                  <td><span class="comp-label"><?=$label?></span></td>
                  <td data-label="Total Sebelum"><input class="tbl-input" type="text" value="0" id="<?=$key?>-total" /></td>
                  <td data-label="Sudah Terbayar"><input class="tbl-input" type="text" value="0" id="<?=$key?>-bayar" /></td>
                  <td data-label="Sisa"><input class="tbl-input tbl-readonly" type="text" value="0" id="<?=$key?>-sisa" readonly /></td>
                  <td data-label="Input Bayar"><input class="tbl-input tbl-pay" type="text"
                        id="<?=$key?>-input" name="<?=$inputName?>"
                        value="<?= number_format((float)$d[$col], 0, ',', '.') ?>" /></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Lain-lain -->
          <div class="section-divider"><span>Lain-lain</span></div>
          <div class="lainlain-grid">
             <?php for ($ll = 1; $ll <= 4; $ll++): ?>
             <div class="lainlain-row">
               <span class="ll-num"><?=$ll?></span>
               <input class="field-input" type="text" name="ll_<?=$ll?>_ket"
                 value="<?= htmlspecialchars($d["LAIN_LAIN{$ll}"] ?? '') ?>" placeholder="Keterangan..." />
               <select class="field-input field-select" name="ll_<?=$ll?>_sel">
                 <option value="">-- Pilih --</option>
                 <option>Biaya Ekskul</option><option>Biaya Seragam</option>
                 <option>Biaya Buku</option><option>Lainnya</option>
               </select>
               <input class="field-input" type="text" name="ll_<?=$ll?>_nom" id="ll-nom-<?=$ll?>"
                 value="<?= number_format((float)($d["JUMLAH{$ll}"] ?? 0), 0, ',', '.') ?>" />
             </div>
             <?php endfor; ?>
          </div>

          <!-- Potongan & Tabungan -->
          <div class="section-divider"><span>Potongan & Tabungan</span></div>
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label">Potongan SPP</label>
              <input class="field-input" type="text" name="potongan_spp" id="potongan-spp"
                value="<?= number_format((float)$d['potong_spp'], 0, ',', '.') ?>" />
            </div>
            <div class="field-row">
              <label class="field-label">Tabungan Wajib</label>
              <input class="field-input" type="text" name="tabungan_wajib" id="tab-wajib"
                value="<?= number_format((float)$d['tabungan_wajib'], 0, ',', '.') ?>" />
            </div>
            <div class="field-row">
              <label class="field-label">Kewajiban SPP</label>
              <input class="field-input" type="text" name="kewajiban_spp" id="kewajiban-spp" readonly
                value="<?= number_format((float)$d['kewajiban_spp'], 0, ',', '.') ?>"
                style="background:rgba(99,102,241,0.08);color:var(--accent)" />
            </div>
          </div>

          <!-- Daftar Ulang -->
          <div class="section-divider"><span>Info Daftar Ulang</span></div>
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label">Kelas Daftar Ulang</label>
              <select class="field-input field-select" name="kelas_du">
                <option value="">-- Pilih Kelas --</option>
                <?php foreach(['Kelas 7','Kelas 8','Kelas 9','Kelas 10','Kelas 11','Kelas 12'] as $kl): ?>
                <option <?= $d['kelas_du'] === $kl ? 'selected' : '' ?>><?=$kl?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label">Tahun Ajaran</label>
              <select class="field-input field-select" name="tahun_ajaran_du">
                <?php foreach(['2024/2025','2025/2026','2026/2027'] as $ta): ?>
                <option <?= $d['th_ajaran'] === $ta ? 'selected' : '' ?>><?=$ta?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Catatan -->
          <div class="section-divider"><span>Catatan</span></div>
          <textarea class="field-input field-textarea" name="catatan"
            placeholder="Catatan..."><?= htmlspecialchars($d['KETERANGAN'] ?? '') ?></textarea>

          <!-- Action Buttons -->
          <div class="action-bar">
            <button type="submit" class="btn btn-warning" id="btn-update">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Update
            </button>
            <a href="lihat.php" class="btn btn-ghost" id="btn-batal">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Batal
            </a>
          </div>
        </form>
      </div>
    </main>
  </div>

  <script>
    // Pre-fill total on load
    window.addEventListener('DOMContentLoaded', function() {
      updateTotal();
    });
  </script>
  <script src="../assets/js/app.js?v=2.8"></script>
</body>
</html>

