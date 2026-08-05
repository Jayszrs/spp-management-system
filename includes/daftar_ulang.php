<?php

function du_academic_year_label(int $month, int $year): string {
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
        throw new RuntimeException('Periode pembayaran tidak valid untuk menentukan tahun ajaran.');
    }
    $start = $month >= 7 ? $year : $year - 1;
    return $start . '/' . ($start + 1);
}

function du_normalize_academic_year(string $value): string {
    $value = trim($value);
    if (!preg_match('/^(\d{4})\/(\d{4})$/', $value, $match) || (int)$match[2] !== (int)$match[1] + 1) {
        throw new RuntimeException('Tahun ajaran harus berformat YYYY/YYYY dan berurutan, contoh 2026/2027.');
    }
    return $value;
}

function du_year_dates(string $label): array {
    $label = du_normalize_academic_year($label);
    $start = (int)substr($label, 0, 4);
    return [$start . '-07-01', ($start + 1) . '-06-30'];
}

function du_current_academic_year(): string {
    return du_academic_year_label((int)date('n'), (int)date('Y'));
}

function du_find_bill(mysqli $db, string $noInduk, int $month, int $year, bool $forUpdate = false): ?array {
    $label = du_academic_year_label($month, $year);
    $sql = "
        SELECT tdu.id, tdu.no_induk, tdu.kelas_snapshot AS kelas,
               tdu.tahun_ajaran_snapshot AS tahun_ajaran,
               tdu.nominal_awal, tdu.nominal_tagihan, tdu.status,
               ta.id AS tahun_ajaran_id, ta.status AS tahun_status,
               sta.id AS penempatan_id, sta.status AS penempatan_status,
               COALESCE(SUM(CASE WHEN bd.id IS NOT NULL THEN bd.jumlah ELSE 0 END), 0) AS terbayar
        FROM tagihan_daftar_ulang tdu
        JOIN tahun_ajaran ta ON ta.id = tdu.tahun_ajaran_id
        JOIN siswa_tahun_ajaran sta ON sta.id = tdu.penempatan_id
        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
        WHERE tdu.no_induk = ? AND ta.label = ?
        GROUP BY tdu.id, tdu.no_induk, tdu.kelas_snapshot, tdu.tahun_ajaran_snapshot,
                 tdu.nominal_awal, tdu.nominal_tagihan, tdu.status,
                 ta.id, ta.status, sta.id, sta.status
        LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ss', $noInduk, $label);
    $stmt->execute();
    $bill = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($bill) {
        $bill['nominal_awal'] = (float)$bill['nominal_awal'];
        $bill['nominal_tagihan'] = (float)$bill['nominal_tagihan'];
        $bill['terbayar'] = (float)$bill['terbayar'];
        $bill['sisa'] = max(0, $bill['nominal_tagihan'] - $bill['terbayar']);
    }
    return $bill;
}

function du_require_bill(mysqli $db, string $noInduk, int $month, int $year, bool $forUpdate = false): array {
    $label = du_academic_year_label($month, $year);
    $bill = du_find_bill($db, $noInduk, $month, $year, $forUpdate);
    if (!$bill) {
        throw new RuntimeException('Tagihan Daftar Ulang siswa untuk tahun ajaran ' . $label . ' belum diterbitkan.');
    }
    if ($bill['status'] !== 'open') {
        throw new RuntimeException('Tagihan Daftar Ulang tahun ajaran ' . $label . ' sudah dibatalkan.');
    }
    return $bill;
}

function du_write_audit(
    mysqli $db,
    ?int $yearId,
    ?int $masterId,
    string $action,
    ?array $before,
    ?array $after,
    int $affectedCount = 0
): void {
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $adminName = (string)($_SESSION['admin_nama'] ?? $_SESSION['admin_username'] ?? 'system');
    $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('INSERT INTO daftar_ulang_audit_log (tahun_ajaran_id, master_id, aksi, before_data, after_data, affected_count, admin_id, admin_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iisssiis', $yearId, $masterId, $action, $beforeJson, $afterJson, $affectedCount, $adminId, $adminName);
    $stmt->execute();
    $stmt->close();
}

function du_create_bill_for_placement(mysqli $db, int $placementId): ?int {
    $stmt = $db->prepare("SELECT sta.id, sta.tahun_ajaran_id, sta.no_induk, sta.kelas, sta.status AS placement_status,
                                ta.label, ta.status AS year_status, du.id AS master_id, du.Jumlah
                         FROM siswa_tahun_ajaran sta
                         JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
                         LEFT JOIN Daftar_ulang du ON du.tahun_ajaran_id = ta.id AND du.kelas = sta.kelas
                         WHERE sta.id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('i', $placementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['placement_status'] !== 'aktif' || $row['year_status'] !== 'published') return null;
    if (!$row['master_id'] || (float)$row['Jumlah'] <= 0) {
        throw new RuntimeException('Tarif Daftar Ulang untuk kelas penempatan siswa belum tersedia.');
    }
    $yearId = (int)$row['tahun_ajaran_id'];
    $rowPlacementId = (int)$row['id'];
    $masterId = (int)$row['master_id'];
    $studentNumber = (string)$row['no_induk'];
    $class = (string)$row['kelas'];
    $label = (string)$row['label'];
    $stmt = $db->prepare("INSERT INTO tagihan_daftar_ulang
        (tahun_ajaran_id, penempatan_id, master_daftar_ulang_id, no_induk, kelas_snapshot, tahun_ajaran_snapshot, nominal_awal, nominal_tagihan)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $amount = (float)$row['Jumlah'];
    $stmt->bind_param('iiisssdd', $yearId, $rowPlacementId, $masterId, $studentNumber, $class, $label, $amount, $amount);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id ?: null;
}
