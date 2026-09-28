<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'session_expired', 'message' => 'Sesi habis. Silakan masuk kembali.']);
    exit;
}

require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/spp_payment_status.php';
require_once '../includes/spp_billing.php';
require_once '../includes/komite_billing.php';
requireRole(['admin', 'kasir']);

$noInduk = trim((string)($_GET['no_induk'] ?? ''));
$bulan = spp_sequence_month_code((string)($_GET['bulan'] ?? ''));
$tahun = trim((string)($_GET['tahun'] ?? ''));
$editId = max(0, (int)($_GET['edit_id'] ?? 0));
$transactionStarted = false;

try {
    if ($noInduk === '' || $bulan === '' || !preg_match('/^\d{4}$/', $tahun)) {
        throw new InvalidArgumentException('Data siswa dan periode SPP belum lengkap.');
    }

    $koneksi->begin_transaction();
    $transactionStarted = true;
    $allowInactive = false;
    if ($editId > 0) {
        $stmt = $koneksi->prepare('SELECT NO_INDUK FROM bayar WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editedPayment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$editedPayment) {
            $koneksi->rollback();
            $transactionStarted = false;
            http_response_code(404);
            echo json_encode(['ok' => false, 'code' => 'payment_not_found', 'message' => 'Data pembayaran tidak ditemukan.']);
            exit;
        }
        $allowInactive = (string)$editedPayment['NO_INDUK'] === $noInduk;
    }

    $status = spp_billing_schema_ready($koneksi)
        ? spp_published_period_status($koneksi,$noInduk,$bulan,$tahun,$editId)
        : spp_payment_status($koneksi, $noInduk, $bulan, $tahun, $editId, false, $allowInactive);
    if (spp_billing_schema_ready($koneksi)) {
        if ($editId === 0) $status['saldo_titipan'] = spp_deposit_balance($koneksi,$noInduk);
        $komite = komite_bill($koneksi,$noInduk,$bulan,$tahun);
        $komitePaid = (float)($komite['paid'] ?? 0);
        if ($komite && $editId > 0) {
            $stmt = $koneksi->prepare('SELECT COALESCE(SUM(nominal),0) nominal FROM bayar_komite WHERE bayar_id=? AND tagihan_komite_id=?');
            $komiteId=(int)$komite['id'];$stmt->bind_param('ii',$editId,$komiteId);$stmt->execute();
            $komitePaid -= (float)($stmt->get_result()->fetch_assoc()['nominal'] ?? 0);$stmt->close();
        }
        $status['komite'] = ['exists'=>(bool)$komite,'total'=>(float)($komite['nominal_tagihan'] ?? 0),'paid'=>max(0,$komitePaid)];
    }
    $status['edit_dependency'] = spp_billing_schema_ready($koneksi) ? null : ($editId > 0 ? spp_edit_dependency($koneksi, $editId, false) : null);
    $koneksi->commit();
    $transactionStarted = false;
    echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $error) {
    if ($transactionStarted) $koneksi->rollback();
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'code' => 'invalid_request',
        'message' => 'Data siswa dan periode SPP belum lengkap.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if ($transactionStarted) $koneksi->rollback();
    http_response_code(500);
    error_log('SPP status check failed: ' . $error->getMessage());
    echo json_encode([
        'ok' => false,
        'code' => 'status_unavailable',
        'message' => 'Status SPP belum dapat diperiksa. Coba lagi.',
    ], JSON_UNESCAPED_UNICODE);
}
