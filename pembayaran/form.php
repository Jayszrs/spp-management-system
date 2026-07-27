<?php
// ============================================
// pembayaran/form.php - Form Input Pembayaran
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';

$siswa_list = $koneksi->query("SELECT id, NO_INDUK, NAMA, KELAS FROM siswa ORDER BY NAMA ASC");

// Flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Input Pembayaran | SistemSPP</title>
  <meta name="description" content="Form input transaksi pembayaran siswa." />
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
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main -->
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
          <h2>Form Transaksi Pembayaran</h2>
          <span class="breadcrumb">SistemSPP / Pembayaran / Input</span>
        </div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>" id="flash-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($flash['type'] === 'success'): ?>
            <polyline points="20 6 9 17 4 12"/>
          <?php else: ?>
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          <?php endif; ?>
        </svg>
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
      <?php endif; ?>

      <div class="main-card">
        <div class="card-title-row">
          <div class="card-title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            Input Pembayaran Siswa
          </div>
        </div>

        <form method="POST" action="../pembayaran/proses.php" id="form-bayar">
          <input type="hidden" name="aksi" value="input" />

          <!-- Tanggal + Jumlah Box -->
          <div class="top-info-row">
            <div class="info-group">
              <div class="field-row">
                <label class="field-label" for="tgl-bayar">Tanggal Bayar</label>
                <input class="field-input" type="date" id="tgl-bayar" name="tanggal_bayar"
                  value="<?= date('Y-m-d') ?>" required />
              </div>
              <div class="field-row">
                <label class="field-label" for="bulan-bayar">Pembayaran Bulan</label>
                <div class="field-group-inline">
                  <select class="field-input field-select" id="bulan-bayar" name="bulan_bayar" required>
                    <?php
                    $bln = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                    $cur = (int)date('n') - 1;
                    foreach ($bln as $i => $b) echo "<option value=\"$b\"" . ($i === $cur ? ' selected' : '') . ">$b</option>";
                    ?>
                  </select>
                  <select class="field-input field-select" id="tahun-bayar" name="tahun_bayar" style="max-width:90px">
                    <?php for ($y = date('Y')-1; $y <= date('Y')+1; $y++) echo "<option" . ($y == date('Y') ? ' selected' : '') . ">$y</option>"; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="jumlah-box">
              <span class="jumlah-label">Total Jumlah</span>
              <span class="jumlah-value" id="totalJumlah">Rp 0</span>
              <input type="hidden" name="total_jumlah" id="hidden-total" value="0" />
            </div>
          </div>

          <!-- Data Siswa -->
          <div class="section-divider"><span>Data Siswa</span></div>
          <div class="fields-grid">
            <div class="field-row full-span">
              <label class="field-label" for="siswa-search">Cari Siswa (Ketik Nama / No. Induk)</label>
              <input class="field-input" type="text" id="siswa-search" list="siswa-list" placeholder="Ketik nama atau No. Induk..." oninput="pilihSiswaDatalist(this)" autocomplete="off" />
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
              <label class="field-label" for="disp-nis">No. Induk</label>
              <input class="field-input" type="text" id="disp-nis" name="no_induk" placeholder="Otomatis terisi" readonly required />
            </div>
            <div class="field-row">
              <label class="field-label" for="disp-nama">Nama Siswa</label>
              <input class="field-input" type="text" id="disp-nama" placeholder="Otomatis terisi" readonly />
            </div>
            <div class="field-row">
              <label class="field-label" for="disp-kelas">Kelas</label>
              <input class="field-input" type="text" id="disp-kelas" placeholder="Otomatis terisi" readonly />
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
                $komponen = [
                  ['pangkal', '💰 Uang Pangkal', 'uang_pangkal'],
                  ['bangunan', '🏗️ Uang Bangunan', 'uang_bangunan'],
                  ['seragam', '👔 Uang Seragam', 'uang_seragam'],
                  ['kegiatan', '🎡 Uang Kegiatan', 'uang_kegiatan'],
                  ['spp', '🎓 Uang SPP', 'uang_spp'],
                  ['makan', '🍽️ Uang Makan', 'uang_makan'],
                  ['sorga', '🌅 Uang Sorga', 'uang_sorga'],
                  ['infaq', '🕌 Uang Infaq', 'uang_infaq'],
                  ['lain', '💵 Uang Lain', 'uang_lain'],
                  ['du', '📚 Uang Daftar Ulang', 'uang_du']
                ];
                foreach ($komponen as $i => $k):
                  [$key, $label, $name] = $k;
                ?>
                <tr class="<?= $i % 2 === 0 ? 'row-highlight' : '' ?>">
                  <td><span class="comp-label"><?= $label ?></span></td>
                  <td data-label="Total Sebelum"><input class="tbl-input" type="text" value="0" id="<?=$key?>-total" /></td>
                  <td data-label="Sudah Terbayar"><input class="tbl-input" type="text" value="0" id="<?=$key?>-bayar" /></td>
                  <td data-label="Sisa"><input class="tbl-input tbl-readonly" type="text" value="0" id="<?=$key?>-sisa" readonly /></td>
                  <td data-label="Input Bayar"><input class="tbl-input tbl-pay" type="text" value="0"
                        id="<?=$key?>-input" name="<?= $name ?>" placeholder="0" /></td>
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
              <span class="ll-num"><?= $ll ?></span>
              <input class="field-input" type="text" placeholder="Keterangan..." name="ll_<?=$ll?>_ket" id="ll-ket-<?=$ll?>" />
              <select class="field-input field-select" name="ll_<?=$ll?>_sel" id="ll-sel-<?=$ll?>">
                <option value="">-- Pilih --</option>
                <option>Biaya Ekskul</option><option>Biaya Seragam</option>
                <option>Biaya Buku</option><option>Lainnya</option>
              </select>
              <input class="field-input" type="text" placeholder="Rp 0"
                     name="ll_<?=$ll?>_nom" id="ll-nom-<?=$ll?>" />
            </div>
            <?php endfor; ?>
          </div>

          <!-- Potongan & Tabungan -->
          <div class="section-divider"><span>Potongan & Tabungan</span></div>
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label" for="potongan-spp">Potongan SPP</label>
              <input class="field-input" type="text" id="potongan-spp" name="potongan_spp" placeholder="Rp 0" />
            </div>
            <div class="field-row">
              <label class="field-label" for="tab-wajib">Tabungan Wajib</label>
              <input class="field-input" type="text" id="tab-wajib" name="tabungan_wajib" placeholder="Rp 0" />
            </div>
            <div class="field-row">
              <label class="field-label" for="kewajiban-spp">Kewajiban SPP</label>
              <input class="field-input" type="text" id="kewajiban-spp" name="kewajiban_spp" placeholder="Rp 0" readonly
                style="background:rgba(99,102,241,0.08);color:var(--accent)" />
            </div>
          </div>

          <!-- Daftar Ulang -->
          <div class="section-divider"><span>Info Daftar Ulang</span></div>
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label" for="kelas-du">Kelas Daftar Ulang</label>
              <select class="field-input field-select" id="kelas-du" name="kelas_du">
                <option value="">-- Pilih Kelas --</option>
                <?php foreach(['Kelas 7','Kelas 8','Kelas 9','Kelas 10','Kelas 11','Kelas 12'] as $kl): ?>
                <option><?= $kl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label" for="tahun-ajaran-du">Tahun Ajaran</label>
              <select class="field-input field-select" id="tahun-ajaran-du" name="tahun_ajaran_du">
                <option>2024/2025</option><option selected>2025/2026</option><option>2026/2027</option>
              </select>
            </div>
          </div>

          <!-- Catatan -->
          <div class="section-divider"><span>Catatan</span></div>
          <textarea class="field-input field-textarea" id="catatan" name="catatan"
            placeholder="Tambahkan catatan jika diperlukan..."></textarea>

          <!-- Action Buttons -->
          <div class="action-bar">
            <button type="submit" class="btn btn-primary" id="btn-input">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Simpan
            </button>
            <button type="reset" class="btn btn-ghost" id="btn-reset" onclick="resetForm()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.59"/></svg>
              Reset
            </button>
            <a href="../pembayaran/lihat.php" class="btn btn-warning" id="btn-lihat">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              Lihat Data
            </a>
            <a href="../dashboard.php" class="btn btn-ghost" id="btn-keluar">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              Keluar
            </a>
          </div>
        </form>
      </div>
    </main>
  </div>

  <script src="../assets/js/app.js?v=2.8"></script>
</body>
</html>
