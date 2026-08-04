<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin', 'bendahara']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$batchToken = strtolower(trim((string)($_GET['batch'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $batchToken)) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Kelompok struk tahunan tidak valid.'];
    header('Location: index.php');
    exit;
}

function annual_receipt_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function annual_receipt_money($value, bool $showZero = false): string {
    $amount = (float)$value;
    if (!$showZero && abs($amount) < 0.005) return '';
    return number_format($amount, 0, ',', '.');
}

function annual_receipt_month($value): string {
    $months = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $code = str_pad((string)(int)$value, 2, '0', STR_PAD_LEFT);
    return $months[$code] ?? (string)$value;
}

function annual_receipt_date($value): string {
    $timestamp = strtotime((string)$value);
    if (!$timestamp) return '-';
    $months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return date('j', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}

function annual_receipt_words(int $number): string {
    $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($number < 12) return $words[$number];
    if ($number < 20) return annual_receipt_words($number - 10) . ' belas';
    if ($number < 100) return annual_receipt_words(intdiv($number, 10)) . ' puluh' . ($number % 10 ? ' ' . annual_receipt_words($number % 10) : '');
    if ($number < 200) return 'seratus' . ($number - 100 ? ' ' . annual_receipt_words($number - 100) : '');
    if ($number < 1000) return annual_receipt_words(intdiv($number, 100)) . ' ratus' . ($number % 100 ? ' ' . annual_receipt_words($number % 100) : '');
    if ($number < 2000) return 'seribu' . ($number - 1000 ? ' ' . annual_receipt_words($number - 1000) : '');
    if ($number < 1000000) return annual_receipt_words(intdiv($number, 1000)) . ' ribu' . ($number % 1000 ? ' ' . annual_receipt_words($number % 1000) : '');
    if ($number < 1000000000) return annual_receipt_words(intdiv($number, 1000000)) . ' juta' . ($number % 1000000 ? ' ' . annual_receipt_words($number % 1000000) : '');
    return annual_receipt_words(intdiv($number, 1000000000)) . ' miliar' . ($number % 1000000000 ? ' ' . annual_receipt_words($number % 1000000000) : '');
}

$stmt = $koneksi->prepare("SELECT id FROM bayar WHERE payment_batch_token = ? ORDER BY payment_batch_sequence, id");
$stmt->bind_param('s', $batchToken);
$stmt->execute();
$ids = array_map(fn($row) => (int)$row['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
$stmt->close();
if (!$ids) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Struk tahunan tidak ditemukan.'];
    header('Location: index.php');
    exit;
}

$paymentStmt = $koneksi->prepare("
    SELECT b.*, s.NAMA, s.KELAS AS KELAS_SISWA, s.PANGKAL, s.PANGKAL_BAYAR,
           s.potong_pangkal, s.tot_pangkal, s.DAFTAR_ULANG, s.potong_du, s.tot_du,
           COALESCE(du.jumlah, 0) AS uang_du, COALESCE(tab.MASUK, 0) AS tabungan_wajib,
           COALESCE((SELECT SUM(bp.U_PANGKAL) FROM bayar bp WHERE bp.NO_INDUK = b.NO_INDUK), 0) AS total_pangkal_bayar,
           COALESCE((SELECT SUM(bd.jumlah) FROM bayar_du bd WHERE bd.no_induk = b.NO_INDUK), 0) AS total_du_bayar
    FROM bayar b
    JOIN siswa s ON s.NO_INDUK = b.NO_INDUK
    LEFT JOIN bayar_du du ON du.bayar_id = b.id
    LEFT JOIN transaksi_m tab ON tab.bayar_id = b.id
    WHERE b.id = ? LIMIT 1
");
$otherStmt = $koneksi->prepare('SELECT nama_biaya_snapshot, nominal_snapshot, keterangan FROM bayar_biaya_lain WHERE bayar_id = ? ORDER BY urutan, id');
$receipts = [];
foreach ($ids as $paymentId) {
    $paymentStmt->bind_param('i', $paymentId);
    $paymentStmt->execute();
    $payment = $paymentStmt->get_result()->fetch_assoc();
    if (!$payment) continue;
    $otherStmt->bind_param('i', $paymentId);
    $otherStmt->execute();
    $otherDetails = $otherStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $payment['primary_lines'] = array_values(array_filter([
        ['Uang PSB', $payment['U_PANGKAL']], ['Uang Daftar Ulang', $payment['uang_du']],
        ['Uang SPP', $payment['U_SPP']], ['Komite Sekolah', $payment['U_KOMITE']],
        ['Tabungan Wajib', $payment['tabungan_wajib']],
    ], fn($line) => abs((float)$line[1]) >= 0.005));
    $payment['other_lines'] = [
        ['Uang Bangunan', $payment['U_BANGUNAN']], ['Uang Seragam', $payment['U_SERAGAM']],
        ['Uang Kegiatan', $payment['U_KEGIATAN']], ['Uang Makan', $payment['U_MAKAN']],
        ['Uang Sorga', $payment['U_SORGA']], ['Uang Infaq', $payment['U_INFAQ']],
    ];
    foreach ($otherDetails as $detail) {
        $label = $detail['nama_biaya_snapshot'];
        if (trim((string)$detail['keterangan']) !== '') $label .= ' - ' . $detail['keterangan'];
        $payment['other_lines'][] = [$label, $detail['nominal_snapshot']];
    }
    if ((float)$payment['potong_spp'] > 0) $payment['other_lines'][] = ['Potongan SPP', -(float)$payment['potong_spp']];
    $payment['other_lines'] = array_values(array_filter($payment['other_lines'], fn($line) => abs((float)$line[1]) >= 0.005));
    $psbBill = (float)$payment['tot_pangkal'] > 0 ? (float)$payment['tot_pangkal'] : max(0, (float)$payment['PANGKAL'] - (float)$payment['potong_pangkal']);
    $duBill = (float)$payment['tot_du'] > 0 ? (float)$payment['tot_du'] : max(0, (float)$payment['DAFTAR_ULANG'] - (float)$payment['potong_du']);
    $payment['remaining_psb'] = max(0, $psbBill - max((float)$payment['PANGKAL_BAYAR'], (float)$payment['total_pangkal_bayar']));
    $payment['remaining_du'] = max(0, $duBill - (float)$payment['total_du_bayar']);
    $receipts[] = $payment;
}
$paymentStmt->close();
$otherStmt->close();
$signer = $_SESSION['admin_nama'] ?? 'Bagian Keuangan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>12 Struk Tahunan - <?= annual_receipt_e($receipts[0]['NAMA'] ?? '') ?></title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png" />
  <style>
    @page { size: A5 landscape; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e7ece9; color: #000; font: 11.5px/1.25 "Times New Roman",serif; }
    .print-toolbar { position: sticky; top: 0; z-index: 3; min-height: 54px; padding: 9px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px; background:#143f31; color:#fff; font:600 13px Arial,sans-serif; }
    .print-toolbar-actions { display:flex; gap:8px; } .print-toolbar button { border:0; border-radius:8px; padding:9px 14px; cursor:pointer; font-weight:700; }
    .print-primary { background:#22a866; color:#fff; } .print-secondary { background:#fff; color:#143f31; }
    .receipt-sheet { width:210mm; height:148mm; margin:16px auto; padding:8mm 12mm 7mm; background:#fff; box-shadow:0 8px 30px rgba(20,63,49,.16); overflow:hidden; page-break-after:always; break-after:page; }
    .receipt-sheet:last-child { page-break-after:auto; break-after:auto; }
    h1 { margin:0; text-align:center; font:800 15px/1.15 Arial,sans-serif; } .address { margin:6px 0 5px; text-align:center; font-size:10.5px; }
    .rule { border-top:1px solid #000; } .document-title { margin:5px 0 9px; text-align:center; font-weight:800; letter-spacing:1px; }
    table { width:100%; border-collapse:collapse; } td { vertical-align:top; } .info { margin-bottom:5px; } .info>tbody>tr>td,.detail>tbody>tr>td { width:50%; padding:0 8mm; }
    .mini td,.payments td { padding:1.6px 0; } .label { width:35mm; } .separator { width:5mm; text-align:center; } .split { margin:4px 0 8px; }
    .section-label { margin-bottom:4px; text-decoration:underline; } .number { width:7mm; } .payment-label { width:47mm; } .amount { width:28mm; text-align:right; white-space:nowrap; }
    .total { margin-top:8px; border-top:1px solid #000; border-bottom:1px solid #000; font:800 11px Arial,sans-serif; letter-spacing:.5px; } .total td { padding:5px 0; }
    .footer { margin-top:8px; } .footer-left { width:68%; } .footer-right { width:32%; text-align:center; } .words { min-height:34px; font-size:12px; font-style:italic; } .signature-space { height:20px; }
    @media(max-width:900px){.receipt-sheet{width:100%;height:auto;min-height:148mm;margin:0 0 10px;box-shadow:none}}
    @media print { body{background:#fff}.print-toolbar{display:none!important}.receipt-sheet{width:210mm;height:148mm;margin:0;box-shadow:none} }
  </style>
</head>
<body>
  <div class="print-toolbar"><span><?= count($receipts) ?> struk tahunan siap dicetak</span><div class="print-toolbar-actions"><button class="print-secondary" onclick="window.close()">Tutup</button><button class="print-primary" onclick="window.print()">Cetak <?= count($receipts) ?> Struk</button></div></div>
  <?php foreach ($receipts as $receipt): ?>
  <main class="receipt-sheet">
    <h1>SEKOLAH DASAR AL-QUR'AN<br>( SDA ) MUTIARA HIKMAH</h1>
    <p class="address">Perum Bekasi Griya Asri II, Blok E Jl.H.Nabrih Ds. Sumber Jaya Kp.Buwek Tambun Selatan Telp. 021.88363466</p>
    <div class="rule"></div><div class="document-title">SLIP PEMBAYARAN SEKOLAH · No. #<?= (int)$receipt['id'] ?> · <?= (int)$receipt['payment_batch_sequence'] ?>/<?= (int)$receipt['payment_batch_count'] ?></div>
    <table class="info"><tr><td><table class="mini"><tr><td class="label">No. Induk</td><td class="separator">:</td><td><?= annual_receipt_e($receipt['NO_INDUK']) ?></td></tr><tr><td class="label">Nama Siswa</td><td class="separator">:</td><td><?= annual_receipt_e($receipt['NAMA']) ?></td></tr></table></td><td><table class="mini"><tr><td class="label">Kelas</td><td class="separator">:</td><td><?= annual_receipt_e($receipt['KELAS_SISWA']) ?></td></tr><tr><td class="label">Periode</td><td class="separator">:</td><td><?= annual_receipt_e(annual_receipt_month($receipt['BULAN'])) ?> <?= annual_receipt_e($receipt['TAHUN']) ?></td></tr></table></td></tr></table>
    <div class="rule split"></div>
    <table class="detail"><tr><td><div class="section-label">Data Pembayaran:</div><table class="payments"><?php foreach ($receipt['primary_lines'] as $index => [$label,$amount]): ?><tr><td class="number"><?= $index+1 ?>.</td><td class="payment-label"><?= annual_receipt_e($label) ?></td><td class="separator">:</td><td class="amount"><?= annual_receipt_e(annual_receipt_money($amount)) ?></td></tr><?php endforeach; ?></table></td><td><div class="section-label">Sisa Pembayaran:</div><table class="payments"><tr><td><strong>Sisa PSB</strong></td><td class="separator">:</td><td class="amount"><?= annual_receipt_e(annual_receipt_money($receipt['remaining_psb'])) ?></td></tr><tr><td><strong>Sisa DU</strong></td><td class="separator">:</td><td class="amount"><?= annual_receipt_e(annual_receipt_money($receipt['remaining_du'])) ?></td></tr></table><div class="section-label" style="margin-top:8px">Pembayaran Lain-lain:</div><table class="payments"><?php if ($receipt['other_lines']): foreach ($receipt['other_lines'] as [$label,$amount]): ?><tr><td><?= annual_receipt_e($label) ?></td><td class="separator">:</td><td class="amount"><?= $amount<0?'-':'' ?><?= annual_receipt_e(annual_receipt_money(abs((float)$amount))) ?></td></tr><?php endforeach; else: ?><tr><td>—</td></tr><?php endif; ?></table></td></tr></table>
    <table class="total"><tr><td>JUMLAH TOTAL</td><td class="amount"><?= annual_receipt_e(annual_receipt_money($receipt['total_jumlah'],true)) ?></td></tr></table>
    <table class="footer"><tr><td class="footer-left"><div class="words"><strong>Terbilang:</strong> <?= annual_receipt_e(ucfirst(annual_receipt_words((int)round($receipt['total_jumlah']))) . ' rupiah') ?></div><div><strong>Sistem Pembayaran:</strong> <?= annual_receipt_e($receipt['sistem_pembayaran'] ?? 'VA') ?></div></td><td class="footer-right"><div>Bekasi, <?= annual_receipt_e(annual_receipt_date($receipt['TGL_BYR'])) ?></div><div>Bagian Keuangan</div><div class="signature-space"></div><strong><?= annual_receipt_e($signer) ?></strong></td></tr></table>
  </main>
  <?php endforeach; ?>
  <script>window.addEventListener('load',function(){window.setTimeout(function(){window.print()},40)});</script>
</body>
</html>
