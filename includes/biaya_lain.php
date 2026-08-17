<?php

require_once __DIR__ . '/kelas.php';

function other_fee_bill_find(mysqli $db, int $billId, string $noInduk, bool $forUpdate = false): ?array {
    $stmt = $db->prepare("SELECT t.*, COALESCE(SUM(d.nominal_snapshot), 0) AS terbayar
        FROM tagihan_biaya_lain t
        LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id = t.id
        WHERE t.id = ? AND t.no_induk = ?
        GROUP BY t.id
        LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('is', $billId, $noInduk);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($bill) {
        $bill['nominal_tagihan'] = (float)$bill['nominal_tagihan'];
        $bill['terbayar'] = (float)$bill['terbayar'];
        $bill['sisa'] = max(0, $bill['nominal_tagihan'] - $bill['terbayar']);
    }
    return $bill;
}

function other_fee_open_bills(mysqli $db, string $noInduk): array {
    $stmt = $db->prepare("SELECT t.id, t.master_biaya_lain_id, t.nama_snapshot AS nama,
               t.nominal_tagihan AS nominal, t.status,
               COALESCE(SUM(d.nominal_snapshot), 0) AS terbayar
        FROM tagihan_biaya_lain t
        LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id = t.id
        WHERE t.no_induk = ? AND t.status = 'open'
        GROUP BY t.id
        ORDER BY t.nama_snapshot");
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['nominal'] = (float)$row['nominal'];
        $row['terbayar'] = (float)$row['terbayar'];
        $row['sisa'] = max(0, $row['nominal'] - $row['terbayar']);
    }
    unset($row);
    return $rows;
}

function other_fee_write_audit(
    mysqli $db,
    int $masterId,
    string $target,
    ?string $targetValue,
    int $count,
    float $total
): void {
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $adminName = (string)($_SESSION['admin_nama'] ?? $_SESSION['admin_username'] ?? 'system');
    $action = 'terbitkan_tagihan';
    $stmt = $db->prepare('INSERT INTO tagihan_biaya_lain_audit_log
        (master_biaya_lain_id, aksi, target, target_value, affected_count, total_nominal, admin_id, admin_name)
        VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?)');
    $stmt->bind_param('isssidis', $masterId, $action, $target, $targetValue, $count, $total, $adminId, $adminName);
    $stmt->execute();
    $stmt->close();
}
