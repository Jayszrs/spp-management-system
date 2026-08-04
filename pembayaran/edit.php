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
if ((int)($d['payment_link_version'] ?? 0) !== 1) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Pembayaran legacy tidak dapat diedit. Rekonsiliasi manual diperlukan terlebih dahulu.'];
    header('Location: lihat.php');
    exit;
}

// Ambil Daftar Ulang dan tabungan wajib yang secara eksplisit milik pembayaran ini.
$stmt_du = $koneksi->prepare("SELECT jumlah, kelas, th_ajaran FROM bayar_du WHERE bayar_id = ? LIMIT 1");
$stmt_du->bind_param('i', $id);
$stmt_du->execute();
$res_du = $stmt_du->get_result()->fetch_assoc();
$d['uang_du'] = $res_du ? (float)$res_du['jumlah'] : 0.0;
if ($res_du) {
    $d['kelas_du'] = $res_du['kelas'];
    $d['th_ajaran'] = $res_du['th_ajaran'];
}
$stmt_du->close();

// Ambil tabungan wajib dari jurnal yang terhubung ke pembayaran ini.
$stmt_tab = $koneksi->prepare("SELECT MASUK FROM transaksi_m WHERE bayar_id = ? LIMIT 1");
$stmt_tab->bind_param('i', $id);
$stmt_tab->execute();
$res_tab = $stmt_tab->get_result()->fetch_assoc();
$d['tabungan_wajib'] = $res_tab ? (float)$res_tab['MASUK'] : 0.0;
$stmt_tab->close();

$d['kewajiban_spp'] = max(0, $d['U_SPP'] - $d['potong_spp'] - $d['tabungan_wajib']);

$siswa_sql = "
    SELECT
        s.*,
        GREATEST(COALESCE(p.paid_pangkal, 0), COALESCE(s.PANGKAL_BAYAR, 0)) AS paid_pangkal,
        GREATEST(COALESCE(p.paid_bangunan, 0), COALESCE(s.BANGUNAN_BAYAR, 0)) AS paid_bangunan,
        GREATEST(COALESCE(p.paid_seragam, 0), COALESCE(s.SERAGAM_BAYAR, 0)) AS paid_seragam,
        GREATEST(COALESCE(p.paid_kegiatan, 0), COALESCE(s.KEGIATAN_BAYAR, 0)) AS paid_kegiatan,
        COALESCE(p.paid_makan, 0) AS paid_makan,
        COALESCE(p.paid_sorga, 0) AS paid_sorga,
        COALESCE(p.paid_infaq, 0) AS paid_infaq,
        COALESCE(du.paid_du, 0) AS paid_du
    FROM siswa s
    LEFT JOIN (
        SELECT
            NO_INDUK,
            SUM(U_PANGKAL) AS paid_pangkal,
            SUM(U_BANGUNAN) AS paid_bangunan,
            SUM(U_SERAGAM) AS paid_seragam,
            SUM(U_KEGIATAN) AS paid_kegiatan,
            SUM(U_MAKAN) AS paid_makan,
            SUM(U_SORGA) AS paid_sorga,
            SUM(U_INFAQ) AS paid_infaq
        FROM bayar
        WHERE id <> ?
        GROUP BY NO_INDUK
    ) p ON p.NO_INDUK = s.NO_INDUK
    LEFT JOIN (
        SELECT no_induk, SUM(jumlah) AS paid_du
        FROM bayar_du
        WHERE bayar_id IS NULL OR bayar_id <> ?
        GROUP BY no_induk
    ) du ON du.no_induk = s.NO_INDUK
    WHERE s.is_active = 1 OR s.NO_INDUK = ?
    ORDER BY s.NAMA ASC
";
$stmt_siswa = $koneksi->prepare($siswa_sql);
$stmt_siswa->bind_param('iis', $id, $id, $d['NO_INDUK']);
$stmt_siswa->execute();
$siswa_list = $stmt_siswa->get_result();

$period_payments = [];
$stmt_period = $koneksi->prepare("
    SELECT NO_INDUK, BULAN, TAHUN, SUM(U_SPP) AS paid_spp, SUM(U_KOMITE) AS paid_komite
    FROM bayar
    WHERE id <> ?
    GROUP BY NO_INDUK, BULAN, TAHUN
");
$stmt_period->bind_param('i', $id);
$stmt_period->execute();
$spp_paid_result = $stmt_period->get_result();
while ($paid = $spp_paid_result->fetch_assoc()) {
    $bulan_key = month_code($paid['BULAN']);
    $periodKey = $bulan_key . '-' . $paid['TAHUN'];
    $period_payments[$paid['NO_INDUK']]['spp'][$periodKey] = (float)$paid['paid_spp'];
    $period_payments[$paid['NO_INDUK']]['komite'][$periodKey] = (float)$paid['paid_komite'];
}
$stmt_period->close();

$du_paid_periods = [];
$stmt_du_paid = $koneksi->prepare("
    SELECT no_induk, kelas, th_ajaran, COALESCE(SUM(jumlah), 0) AS paid_du
    FROM bayar_du
    WHERE (bayar_id IS NULL OR bayar_id <> ?)
      AND kelas IS NOT NULL AND kelas <> ''
      AND th_ajaran IS NOT NULL AND th_ajaran <> ''
    GROUP BY no_induk, kelas, th_ajaran
");
$stmt_du_paid->bind_param('i', $id);
$stmt_du_paid->execute();
$du_paid_result = $stmt_du_paid->get_result();
while ($paid = $du_paid_result->fetch_assoc()) {
    $periodKey = trim((string)$paid['kelas']) . '|' . trim((string)$paid['th_ajaran']);
    $du_paid_periods[$paid['no_induk']][$periodKey] = (float)$paid['paid_du'];
}
$stmt_du_paid->close();

$master_daftar_ulang = [];
$master_du_result = $koneksi->query("
    SELECT kelas, th_ajaran, Jumlah
    FROM Daftar_ulang
    WHERE kelas IS NOT NULL AND kelas <> '' AND th_ajaran IS NOT NULL AND th_ajaran <> '' AND Jumlah > 0
    ORDER BY th_ajaran ASC, kelas ASC, id ASC
");
while ($master = $master_du_result->fetch_assoc()) {
    $periodKey = trim((string)$master['kelas']) . '|' . trim((string)$master['th_ajaran']);
    $master_daftar_ulang[$periodKey] = (float)$master['Jumlah'];
}
$has_master_daftar_ulang = count($master_daftar_ulang) > 0;

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

$master_daftar_ulang_years = [];
foreach (array_keys($master_daftar_ulang) as $periodKey) {
    [, $year] = explode('|', $periodKey, 2);
    $master_daftar_ulang_years[$year] = $year;
}
$activeAcademicYear = current_academic_year();
$selectedAcademicYear = !empty($d['th_ajaran']) ? $d['th_ajaran'] : $activeAcademicYear;
$daftar_ulang_years = academic_year_options(array_values($master_daftar_ulang_years), [$selectedAcademicYear, $activeAcademicYear]);

$biaya_lain_paid = [];
$stmt_biaya_lain_paid = $koneksi->prepare("
    SELECT b.NO_INDUK, d.master_biaya_lain_id, COALESCE(SUM(d.nominal_snapshot), 0) AS paid
    FROM bayar_biaya_lain d
    JOIN bayar b ON b.id = d.bayar_id
    WHERE b.id <> ? AND d.master_biaya_lain_id IS NOT NULL
    GROUP BY b.NO_INDUK, d.master_biaya_lain_id
");
$stmt_biaya_lain_paid->bind_param('i', $id);
$stmt_biaya_lain_paid->execute();
$biaya_lain_paid_result = $stmt_biaya_lain_paid->get_result();
while ($paid = $biaya_lain_paid_result->fetch_assoc()) {
    $biaya_lain_paid[$paid['NO_INDUK']][(int)$paid['master_biaya_lain_id']] = (float)$paid['paid'];
}
$stmt_biaya_lain_paid->close();

$master_biaya_lain = $koneksi->query("
    SELECT id, nama, nominal, is_active
    FROM master_biaya_lain
    ORDER BY is_active DESC, nama ASC
")->fetch_all(MYSQLI_ASSOC);
$master_biaya_lain_by_id = [];
foreach ($master_biaya_lain as $biaya) {
    $master_biaya_lain_by_id[(int)$biaya['id']] = $biaya;
}

$stmt_biaya_lain = $koneksi->prepare("
    SELECT d.*
    FROM bayar_biaya_lain d
    WHERE d.bayar_id = ?
    ORDER BY d.urutan ASC, d.id ASC
");
$stmt_biaya_lain->bind_param('i', $id);
$stmt_biaya_lain->execute();
$biaya_lain_details = $stmt_biaya_lain->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_biaya_lain->close();
if (!$biaya_lain_details) {
    $biaya_lain_details[] = [
        'id' => '', 'master_biaya_lain_id' => '', 'nama_biaya_snapshot' => '',
        'nominal_snapshot' => 0, 'keterangan' => ''
    ];
}

function money_attr($value) {
    return htmlspecialchars((string)(float)$value, ENT_QUOTES, 'UTF-8');
}

function total_after_discount($total, $discount, $fallbackTotal = 0) {
    $fallbackTotal = (float)$fallbackTotal;
    if ($fallbackTotal > 0) return $fallbackTotal;
    return max(0, (float)$total - (float)$discount);
}

function month_code($value) {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

$selectedPaymentMethod = $d['sistem_pembayaran'] ?? 'VA';
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
  <link rel="stylesheet" href="../assets/css/style.css?v=4.7" />
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
                  <select class="field-input field-select month-code-select" name="bulan_bayar" id="bulan-bayar" required>
                    <?php
                    $month_labels = [
                        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
                        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
                    ];
                    $selectedMonth = month_code($d['BULAN']);
                    foreach ($month_labels as $code => $label):
                    ?>
                    <option value="<?= $code ?>" data-label="<?= $label ?>" <?= $selectedMonth === $code ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select class="field-input field-select" name="tahun_bayar" id="tahun-bayar" style="max-width:90px">
                    <?php for ($y = date('Y')-1; $y <= date('Y')+1; $y++): ?>
                    <option <?= $d['TAHUN'] == $y ? 'selected' : '' ?>><?=$y?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>
              <div class="field-row">
                <label class="field-label" for="sistem-pembayaran">Sistem Pembayaran</label>
                <select class="field-input field-select" id="sistem-pembayaran" name="sistem_pembayaran" required>
                  <?php foreach (['Tunai', 'VA', 'Qris'] as $method): ?>
                  <option value="<?= $method ?>" <?= $selectedPaymentMethod === $method ? 'selected' : '' ?>><?= $method ?></option>
                  <?php endforeach; ?>
                </select>
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
                  data-kelas="<?= htmlspecialchars($s['KELAS']) ?>"
                  data-total-pangkal="<?= money_attr(total_after_discount($s['PANGKAL'], $s['potong_pangkal'], $s['tot_pangkal'])) ?>"
                  data-total-bangunan="<?= money_attr($s['BANGUNAN']) ?>"
                  data-total-seragam="<?= money_attr($s['SERAGAM']) ?>"
                  data-total-kegiatan="<?= money_attr($s['KEGIATAN']) ?>"
                  data-total-spp="<?= money_attr($s['SPP_PERBULAN']) ?>"
                  data-total-komite="<?= money_attr($s['POMG']) ?>"
                  data-total-makan="0"
                  data-total-sorga="0"
                  data-total-infaq="0"
                  data-total-du="<?= money_attr(total_after_discount($s['DAFTAR_ULANG'], $s['potong_du'], $s['tot_du'])) ?>"
                  data-paid-pangkal="<?= money_attr($s['paid_pangkal']) ?>"
                  data-paid-bangunan="<?= money_attr($s['paid_bangunan']) ?>"
                  data-paid-seragam="<?= money_attr($s['paid_seragam']) ?>"
                  data-paid-kegiatan="<?= money_attr($s['paid_kegiatan']) ?>"
                  data-paid-spp-periods="<?= htmlspecialchars(json_encode($period_payments[$s['NO_INDUK']]['spp'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-paid-komite-periods="<?= htmlspecialchars(json_encode($period_payments[$s['NO_INDUK']]['komite'] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-paid-makan="<?= money_attr($s['paid_makan']) ?>"
                  data-paid-sorga="<?= money_attr($s['paid_sorga']) ?>"
                  data-paid-infaq="<?= money_attr($s['paid_infaq']) ?>"
                  data-paid-du-periods="<?= htmlspecialchars(json_encode($du_paid_periods[$s['NO_INDUK']] ?? []), ENT_QUOTES, 'UTF-8') ?>"
                  data-paid-biaya-lain="<?= htmlspecialchars(json_encode($biaya_lain_paid[$s['NO_INDUK']] ?? []), ENT_QUOTES, 'UTF-8') ?>">
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
          <p class="payment-auto-note">Kolom total, sudah terbayar, dan sisa dihitung otomatis dari riwayat transaksi.</p>
          <div class="alert alert-warning payment-overpaid-alert" id="payment-overpaid-alert" hidden></div>
          <div class="alert alert-warning payment-input-overlimit-alert" id="payment-input-overlimit-alert" hidden></div>
          <div class="table-container">
            <table class="payment-table form-payment-table">
              <thead>
                <tr>
                  <th>Komponen Bayar</th>
                  <th>Total Tagihan (Rp)</th>
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
                  ['du', '📚 Uang Daftar Ulang', 'uang_du', 'uang_du']
                ];
                array_splice($komp, 5, 0, [[ 'komite', 'Uang Komite', 'U_KOMITE', 'uang_komite' ]]);
                foreach ($komp as $i => [$key,$label,$col,$inputName]):
                ?>
                <tr class="<?= $i%2===0?'row-highlight':'' ?>">
                  <td><span class="comp-label"><?=$label?></span></td>
                  <td data-label="Total Tagihan"><input class="tbl-input tbl-system" type="text" value="0" id="<?=$key?>-total" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Sudah Terbayar"><input class="tbl-input tbl-system" type="text" value="0" id="<?=$key?>-bayar" readonly tabindex="-1" aria-readonly="true" /></td>
                  <td data-label="Sisa"><input class="tbl-input tbl-system tbl-system-sisa" type="text" value="0" id="<?=$key?>-sisa" readonly tabindex="-1" aria-readonly="true" /></td>
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
          <div class="alert alert-warning biaya-lain-overpaid-alert" id="biaya-lain-overpaid-alert" hidden></div>
          <div class="lainlain-grid" id="biaya-lain-list">
            <?php foreach ($biaya_lain_details as $index => $detail):
              $selectedMasterId = (int)($detail['master_biaya_lain_id'] ?? 0);
              $currentNominal = (float)($detail['nominal_snapshot'] ?? 0);
              $masterTotal = $selectedMasterId && isset($master_biaya_lain_by_id[$selectedMasterId])
                ? (float)$master_biaya_lain_by_id[$selectedMasterId]['nominal']
                : $currentNominal;
              $paidOther = $selectedMasterId ? (float)($biaya_lain_paid[$d['NO_INDUK']][$selectedMasterId] ?? 0) : 0.0;
              $remainingAfterCurrent = max(0, $masterTotal - $paidOther - $currentNominal);
            ?>
            <div class="lainlain-row biaya-lain-row">
              <span class="ll-num"><?= $index + 1 ?></span>
              <input type="hidden" name="biaya_lain_detail_id[]" value="<?= (int)($detail['id'] ?? 0) ?: '' ?>" />
              <label class="ll-field">
                <span class="ll-field-label">Jenis</span>
                <select class="field-input field-select biaya-lain-select" name="biaya_lain_master_id[]">
                  <option value="" <?= $selectedMasterId === 0 ? 'selected' : '' ?>><?= $selectedMasterId === 0 && !empty($detail['id']) ? htmlspecialchars($detail['nama_biaya_snapshot']) . ' (Data lama)' : '-- Pilih Biaya --' ?></option>
                  <?php foreach ($master_biaya_lain as $biaya):
                    $isSelected = (int)$biaya['id'] === $selectedMasterId;
                    if (!(int)$biaya['is_active'] && !$isSelected) continue;
                  ?>
                  <option value="<?= (int)$biaya['id'] ?>" data-nominal="<?= money_attr($biaya['nominal']) ?>" <?= $isSelected ? 'selected' : '' ?>>
                    <?= htmlspecialchars($isSelected ? $detail['nama_biaya_snapshot'] : $biaya['nama']) ?><?= !(int)$biaya['is_active'] ? ' (Nonaktif)' : '' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ll-field"><span class="ll-field-label">Total</span><input class="field-input biaya-lain-total" type="text" readonly aria-label="Total biaya lain"
                value="<?= number_format($masterTotal, 0, ',', '.') ?>" placeholder="Total" /></label>
              <label class="ll-field"><span class="ll-field-label">Sudah</span><input class="field-input biaya-lain-paid" type="text" readonly aria-label="Sudah dibayar biaya lain"
                value="<?= number_format($paidOther, 0, ',', '.') ?>" placeholder="Sudah" /></label>
              <label class="ll-field"><span class="ll-field-label">Sisa</span><input class="field-input biaya-lain-sisa" type="text" readonly aria-label="Sisa biaya lain"
                value="<?= number_format($remainingAfterCurrent, 0, ',', '.') ?>" placeholder="Sisa" /></label>
              <label class="ll-field"><span class="ll-field-label">Bayar</span><input class="field-input biaya-lain-nominal" type="text" name="biaya_lain_nominal[]" aria-label="Input bayar biaya lain"
                value="<?= number_format($currentNominal, 0, ',', '.') ?>" placeholder="Input bayar" /></label>
              <label class="ll-field ll-field-note"><span class="ll-field-label">Keterangan</span><input class="field-input biaya-lain-keterangan" type="text" name="biaya_lain_keterangan[]" maxlength="255"
                value="<?= htmlspecialchars($detail['keterangan'] ?? '') ?>" placeholder="Keterangan opsional..." /></label>
              <button class="btn-icon-danger btn-remove-biaya-lain" type="button" title="Hapus baris" aria-label="Hapus baris">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </div>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-ghost btn-add-biaya-lain" id="btn-add-biaya-lain" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Biaya Lain
          </button>
          <template id="biaya-lain-row-template">
            <div class="lainlain-row biaya-lain-row">
              <span class="ll-num"></span>
              <input type="hidden" name="biaya_lain_detail_id[]" value="" />
              <label class="ll-field">
                <span class="ll-field-label">Jenis</span>
                <select class="field-input field-select biaya-lain-select" name="biaya_lain_master_id[]">
                  <option value="">-- Pilih Biaya --</option>
                  <?php foreach ($master_biaya_lain as $biaya): if (!(int)$biaya['is_active']) continue; ?>
                  <option value="<?= (int)$biaya['id'] ?>" data-nominal="<?= money_attr($biaya['nominal']) ?>"><?= htmlspecialchars($biaya['nama']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="ll-field"><span class="ll-field-label">Total</span><input class="field-input biaya-lain-total" type="text" value="0" placeholder="Total" readonly aria-label="Total biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Sudah</span><input class="field-input biaya-lain-paid" type="text" value="0" placeholder="Sudah" readonly aria-label="Sudah dibayar biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Sisa</span><input class="field-input biaya-lain-sisa" type="text" value="0" placeholder="Sisa" readonly aria-label="Sisa biaya lain" /></label>
              <label class="ll-field"><span class="ll-field-label">Bayar</span><input class="field-input biaya-lain-nominal" type="text" value="0" placeholder="Input bayar" name="biaya_lain_nominal[]" aria-label="Input bayar biaya lain" /></label>
              <label class="ll-field ll-field-note"><span class="ll-field-label">Keterangan</span><input class="field-input biaya-lain-keterangan" type="text" placeholder="Keterangan opsional..." name="biaya_lain_keterangan[]" maxlength="255" /></label>
              <button class="btn-icon-danger btn-remove-biaya-lain" type="button" title="Hapus baris" aria-label="Hapus baris">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </div>
          </template>

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
              <select class="field-input field-select" id="kelas-du" name="kelas_du" <?= $selectedDuClass !== '' ? 'data-preserve-default="1"' : '' ?>>
                <option value="">-- Pilih Kelas --</option>
                <?php
                $selectedDuClass = preg_replace('/\D+/', '', (string)$d['kelas_du']);
                for ($kl = 1; $kl <= 6; $kl++):
                ?>
                <option value="<?= $kl ?>" <?= $selectedDuClass === (string)$kl ? 'selected' : '' ?>>Kelas <?= $kl ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="field-row">
              <label class="field-label">Tahun Ajaran</label>
              <select class="field-input field-select" id="tahun-ajaran-du" name="tahun_ajaran_du">
                <?php foreach($daftar_ulang_years as $ta): ?>
                <option value="<?= htmlspecialchars($ta) ?>" <?= $selectedAcademicYear === $ta ? 'selected' : '' ?>>
                  <?= htmlspecialchars($ta) ?><?= $activeAcademicYear === $ta ? ' - Tahun ajaran aktif' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="field-hint du-master-warning" id="du-master-warning" hidden></p>

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
    window.sppDaftarUlangMasters = <?= json_encode($master_daftar_ulang, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    window.sppDaftarUlangHasMasters = <?= $has_master_daftar_ulang ? 'true' : 'false' ?>;
    // Pre-fill total on load
    window.addEventListener('DOMContentLoaded', function() {
      updateTotal();
    });
  </script>
  <script src="../assets/js/app.js?v=4.2"></script>
</body>
</html>

