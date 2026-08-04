<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin']);

function du_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function du_money($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function du_full_date($value): array {
    $timestamp = strtotime((string)$value);
    if (!$timestamp) return ['date' => '-', 'time' => '-'];
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return [
        'date' => $days[(int)date('w', $timestamp)] . ', ' . date('j', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp),
        'time' => date('H:i', $timestamp) . ' WIB',
    ];
}

$search = trim((string)($_GET['q'] ?? ''));
$filterClass = trim((string)($_GET['kelas'] ?? ''));
$filterYear = trim((string)($_GET['tahun_ajaran'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$allowedClasses = ['1', '2', '3', '4', '5', '6'];
$allowedStatuses = ['', 'lunas', 'cicilan'];
if (!in_array($filterClass, array_merge([''], $allowedClasses), true)) $filterClass = '';
if (!in_array($filterStatus, $allowedStatuses, true)) $filterStatus = '';
if ($filterYear !== '' && !preg_match('/^\d{4}\/\d{4}$/', $filterYear)) $filterYear = '';

$academicYears = [];
$yearResult = $koneksi->query("SELECT th_ajaran FROM Daftar_ulang WHERE th_ajaran IS NOT NULL AND th_ajaran <> '' UNION SELECT th_ajaran FROM bayar_du WHERE th_ajaran IS NOT NULL AND th_ajaran <> '' ORDER BY th_ajaran DESC");
while ($year = $yearResult->fetch_row()) $academicYears[] = $year[0];

$where = ["bd.bayar_id IS NOT NULL", "bd.jumlah > 0"];
$params = [];
$types = '';
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(s.NO_INDUK LIKE ? OR s.NAMA LIKE ?)';
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
if ($filterClass !== '') {
    $where[] = 'bd.kelas = ?';
    $params[] = $filterClass; $types .= 's';
}
if ($filterYear !== '') {
    $where[] = 'bd.th_ajaran = ?';
    $params[] = $filterYear; $types .= 's';
}

$sql = "
    SELECT bd.id AS detail_id, bd.bayar_id, bd.no_induk, bd.kelas, bd.th_ajaran, bd.jumlah,
           b.TGL_BYR, b.BULAN, b.TAHUN, b.sistem_pembayaran, b.payment_link_version,
           s.NAMA, s.KELAS AS kelas_siswa, s.DAFTAR_ULANG, s.potong_du, s.tot_du,
           COALESCE(m.Jumlah, 0) AS master_total
    FROM bayar_du bd
    JOIN bayar b ON b.id = bd.bayar_id
    JOIN siswa s ON s.NO_INDUK = bd.no_induk
    LEFT JOIN Daftar_ulang m ON m.kelas = bd.kelas AND m.th_ajaran = bd.th_ajaran
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.TGL_BYR DESC, b.id DESC
";
$stmt = $koneksi->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rawRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$groups = [];
foreach ($rawRows as $row) {
    $key = $row['no_induk'] . '|' . $row['kelas'] . '|' . $row['th_ajaran'];
    if (!isset($groups[$key])) {
        $fallback = (float)$row['tot_du'];
        if ($fallback <= 0) $fallback = max(0, (float)$row['DAFTAR_ULANG'] - (float)$row['potong_du']);
        $groups[$key] = [
            'no_induk' => $row['no_induk'], 'nama' => $row['NAMA'], 'kelas' => $row['kelas'],
            'kelas_siswa' => $row['kelas_siswa'], 'th_ajaran' => $row['th_ajaran'],
            'total' => (float)$row['master_total'] > 0 ? (float)$row['master_total'] : $fallback,
            'paid' => 0.0, 'transactions' => [],
        ];
    }
    $row['full_date'] = du_full_date($row['TGL_BYR']);
    $groups[$key]['paid'] += (float)$row['jumlah'];
    $groups[$key]['transactions'][] = $row;
}

$visibleGroups = [];
foreach ($groups as $group) {
    if ($group['total'] <= 0) $group['total'] = $group['paid'];
    $group['remaining'] = max(0, $group['total'] - $group['paid']);
    $group['status'] = $group['remaining'] <= 0.001 ? 'lunas' : 'cicilan';
    if ($filterStatus !== '' && $group['status'] !== $filterStatus) continue;
    $visibleGroups[] = $group;
}

$summary = ['students' => count($visibleGroups), 'bill' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];
foreach ($visibleGroups as $group) {
    $summary['bill'] += $group['total'];
    $summary['paid'] += $group['paid'];
    $summary['remaining'] += $group['remaining'];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Daftar Ulang | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <meta name="description" content="Rekap pembayaran dan cicilan daftar ulang siswa." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=5.0" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div class="topbar-title"><h2>Riwayat Daftar Ulang</h2><span class="breadcrumb">SistemSPP / Pembayaran / Daftar Ulang</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?><div class="alert alert-<?= du_e($flash['type'] ?? 'error') ?>" id="flash-msg"><?= du_e($flash['msg'] ?? '') ?></div><?php endif; ?>

      <div class="main-card du-history-card">
        <div class="card-title-row">
          <div class="card-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z"/><path d="M9 7h6M9 11h6"/></svg>Rekap Daftar Ulang Siswa</div>
          <a href="form.php" class="btn btn-primary">+ Input Pembayaran</a>
        </div>

        <form method="GET" class="filter-bar du-history-filter">
          <div class="search-box"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" name="q" value="<?= du_e($search) ?>" placeholder="Cari nama / NIS..." /></div>
          <select class="field-input field-select filter-sel" name="kelas"><option value="">Kelas 1–6</option><?php foreach ($allowedClasses as $class): ?><option value="<?= $class ?>" <?= $filterClass === $class ? 'selected' : '' ?>>Kelas <?= $class ?></option><?php endforeach; ?></select>
          <select class="field-input field-select filter-sel" name="tahun_ajaran"><option value="">Semua Tahun Ajaran</option><?php foreach ($academicYears as $year): ?><option value="<?= du_e($year) ?>" <?= $filterYear === $year ? 'selected' : '' ?>><?= du_e($year) ?></option><?php endforeach; ?></select>
          <select class="field-input field-select filter-sel" name="status"><option value="">Semua Status</option><option value="cicilan" <?= $filterStatus === 'cicilan' ? 'selected' : '' ?>>Belum Lunas</option><option value="lunas" <?= $filterStatus === 'lunas' ? 'selected' : '' ?>>Lunas</option></select>
          <button class="btn btn-primary" type="submit">Tampilkan</button><a class="btn btn-ghost" href="riwayat_daftar_ulang.php">Reset</a>
        </form>

        <div class="history-summary-grid du-history-summary">
          <div><span>Siswa / Periode</span><strong><?= number_format($summary['students']) ?></strong></div>
          <div><span>Total Tagihan</span><strong><?= du_money($summary['bill']) ?></strong></div>
          <div><span>Sudah Dibayar</span><strong><?= du_money($summary['paid']) ?></strong></div>
          <div><span>Sisa Cicilan</span><strong><?= du_money($summary['remaining']) ?></strong></div>
        </div>

        <div class="table-container">
          <table class="payment-table responsive-table du-history-table">
            <thead><tr><th>No</th><th>Siswa</th><th>Kelas / Tahun Ajaran</th><th>Tagihan</th><th>Terbayar</th><th>Sisa</th><th>Status</th><th>Rincian Pembayaran</th></tr></thead>
            <tbody>
            <?php if (!$visibleGroups): ?><tr><td colspan="8" class="text-center recap-empty">Belum ada pembayaran daftar ulang untuk filter ini.</td></tr>
            <?php else: foreach ($visibleGroups as $index => $group): ?>
              <tr>
                <td data-label="No"><?= $index + 1 ?></td>
                <td data-label="Siswa"><strong><?= du_e($group['nama']) ?></strong><small class="du-history-nis">NIS <?= du_e($group['no_induk']) ?></small></td>
                <td data-label="Kelas / Tahun"><strong>Kelas <?= du_e($group['kelas']) ?></strong><small class="du-history-nis"><?= du_e($group['th_ajaran']) ?></small></td>
                <td data-label="Tagihan" class="nominal"><?= du_money($group['total']) ?></td>
                <td data-label="Terbayar" class="nominal"><?= du_money($group['paid']) ?></td>
                <td data-label="Sisa" class="nominal"><?= du_money($group['remaining']) ?></td>
                <td data-label="Status"><span class="recap-status <?= $group['status'] === 'lunas' ? 'is-paid' : 'is-partial' ?>"><?= $group['status'] === 'lunas' ? 'Lunas' : 'Belum Lunas' ?></span></td>
                <td data-label="Rincian">
                  <details class="du-payment-details">
                    <summary><?= count($group['transactions']) ?> pembayaran</summary>
                    <div class="du-payment-timeline">
                    <?php foreach ($group['transactions'] as $transaction): ?>
                      <div class="du-payment-entry">
                        <div><strong><?= du_money($transaction['jumlah']) ?></strong><span><?= du_e($transaction['full_date']['date']) ?> · <?= du_e($transaction['full_date']['time']) ?></span></div>
                        <div class="du-payment-actions"><a class="btn-tbl btn-tbl-print" href="../laporan/cetak_struk.php?id=<?= (int)$transaction['bayar_id'] ?>" target="_blank" rel="noopener">Cetak</a><?php if ((int)$transaction['payment_link_version'] === 1): ?><a class="btn-tbl btn-tbl-edit" href="edit.php?id=<?= (int)$transaction['bayar_id'] ?>">Edit</a><?php endif; ?></div>
                      </div>
                    <?php endforeach; ?>
                    </div>
                  </details>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
  <script src="../assets/js/app.js?v=4.4"></script>
</body>
</html>
