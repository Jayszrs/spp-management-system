<?php
// tabungan/get_saldo.php — AJAX: ambil saldo tabungan siswa
session_start();
require_once '../koneksi.php';

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['saldo' => 0]);
    exit;
}

$nis  = trim($_GET['nis'] ?? '');
$saldo = 0;

if ($nis) {
    $stmt = $koneksi->prepare("SELECT SALDO FROM tabungan WHERE NO_INDUK = ? LIMIT 1");
    $stmt->bind_param('s', $nis);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $saldo = (float)($row['SALDO'] ?? 0);
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode(['saldo' => $saldo]);
