<?php
// tabungan/get_saldo.php — AJAX: ambil saldo tabungan siswa
session_start();
require_once '../koneksi.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Silakan masuk kembali.']);
    exit;
}
if (!hasRole(['admin', 'kasir'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Akses saldo tabungan tidak diizinkan.']);
    exit;
}

$nisParam = $_GET['nis'] ?? '';
if (!is_string($nisParam) || strlen($nisParam) > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'NIS siswa tidak valid.']);
    exit;
}
$nis  = trim($nisParam);
$saldo = 0;
$rawSaldo = 0;
$saldoMinus = false;

if ($nis) {
    if(unit_active_id()===0 || (int)($_GET['student_id']??0)>0){try {unit_resolve_student($koneksi,$nis,(int)($_GET['student_id']??0));}catch(Throwable $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);exit;}}
    $stmt = $koneksi->prepare("SELECT SALDO FROM tabungan WHERE NO_INDUK = ? LIMIT 1");
    $stmt->bind_param('s', $nis);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $rawSaldo = (float)($row['SALDO'] ?? 0);
    $saldoMinus = $rawSaldo < 0;
    $saldo = max(0, $rawSaldo);
    $stmt->close();
}

echo json_encode([
    'saldo' => $saldo,
    'raw_saldo' => $rawSaldo,
    'saldo_minus' => $saldoMinus,
    'warning' => $saldoMinus ? 'Saldo tabungan terdeteksi minus dan perlu rekonsiliasi.' : ''
]);
