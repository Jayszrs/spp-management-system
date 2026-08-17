<?php

require_once __DIR__ . '/daftar_ulang.php';

function class_label(array $class): string {
    $level = (int)($class['tingkat'] ?? 0);
    if ((int)($class['is_placeholder'] ?? 0) === 1) {
        return 'Kelas ' . $level . ' (Belum Ditentukan)';
    }
    return $level . strtoupper(trim((string)($class['kode_rombel'] ?? '')));
}

function class_find(mysqli $db, int $classId, bool $activeOnly = false): ?array {
    $sql = 'SELECT id, tingkat, kode_rombel, is_placeholder, is_active FROM master_kelas WHERE id = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($class) $class['label'] = class_label($class);
    return $class;
}

function class_all(mysqli $db, bool $activeOnly = true, bool $includePlaceholder = true): array {
    $where = [];
    if ($activeOnly) $where[] = 'is_active = 1';
    if (!$includePlaceholder) $where[] = 'is_placeholder = 0';
    $sql = 'SELECT id, tingkat, kode_rombel, is_placeholder, is_active FROM master_kelas';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY tingkat, is_placeholder, kode_rombel';
    $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['label'] = class_label($row);
    unset($row);
    return $rows;
}

function class_current_academic_year_id(mysqli $db, bool $forUpdate = false): ?int {
    $label = du_current_academic_year();
    $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $label);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function class_component_paid_in_academic_year(mysqli $db, string $noInduk, string $academicYear, string $component): float {
    $academicYear = du_normalize_academic_year($academicYear);
    $startYear = (int)substr($academicYear, 0, 4);
    $column = $component === 'komite' ? 'U_KOMITE' : 'U_SPP';
    $monthSql = "CASE LOWER(BULAN)
      WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4
      WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8
      WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12
      ELSE CAST(BULAN AS UNSIGNED) END";
    $stmt = $db->prepare("SELECT COALESCE(SUM($column), 0) AS paid
        FROM bayar
        WHERE NO_INDUK = ?
          AND ((CAST(TAHUN AS UNSIGNED) = ? AND $monthSql BETWEEN 7 AND 12)
            OR (CAST(TAHUN AS UNSIGNED) = ? AND $monthSql BETWEEN 1 AND 6))");
    $endYear = $startYear + 1;
    $stmt->bind_param('sii', $noInduk, $startYear, $endYear);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function class_validate_tariff_snapshot_change(
    mysqli $db,
    string $noInduk,
    float $oldSpp,
    float $newSpp,
    float $oldKomite,
    float $newKomite
): void {
    $label = du_current_academic_year();
    if (abs($oldSpp - $newSpp) > .001 && class_component_paid_in_academic_year($db, $noInduk, $label, 'spp') > 0) {
        throw new RuntimeException('Tarif SPP tahun ajaran ' . $label . ' sudah memiliki pembayaran dan tidak dapat diubah. Koreksi transaksi terlebih dahulu.');
    }
    if (abs($oldKomite - $newKomite) > .001 && class_component_paid_in_academic_year($db, $noInduk, $label, 'komite') > 0) {
        throw new RuntimeException('Tarif Komite tahun ajaran ' . $label . ' sudah memiliki pembayaran dan tidak dapat diubah. Koreksi transaksi terlebih dahulu.');
    }
}

function class_sync_student_current_year(
    mysqli $db,
    string $noInduk,
    int $classId,
    float $spp,
    float $komite,
    bool $active = true
): ?int {
    $class = class_find($db, $classId);
    if (!$class) throw new RuntimeException('Master kelas/rombel siswa tidak ditemukan.');
    $yearId = class_current_academic_year_id($db, true);
    if (!$yearId) return null;

    $academicLabel = du_current_academic_year();
    $stmt = $db->prepare('SELECT id, master_kelas_id FROM siswa_tahun_ajaran WHERE tahun_ajaran_id=? AND no_induk=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('is', $yearId, $noInduk); $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($existing && (int)$existing['master_kelas_id'] !== $classId) {
        $stmt = $db->prepare("SELECT EXISTS(SELECT 1 FROM bayar WHERE NO_INDUK=? AND th_ajaran=? AND total_jumlah>0)
          OR EXISTS(SELECT 1 FROM bayar_du WHERE no_induk=? AND th_ajaran=? AND jumlah>0) AS paid");
        $stmt->bind_param('ssss', $noInduk, $academicLabel, $noInduk, $academicLabel); $stmt->execute();
        $hasPayment = (int)$stmt->get_result()->fetch_assoc()['paid'] === 1; $stmt->close();
        if ($hasPayment) {
            $status = $active ? 'aktif' : 'pindah';
            $stmt = $db->prepare('UPDATE siswa_tahun_ajaran SET status=? WHERE id=?');
            $existingId=(int)$existing['id'];$stmt->bind_param('si',$status,$existingId);$stmt->execute();$stmt->close();
            return $existingId;
        }
    }

    $level = (string)$class['tingkat'];
    $label = $class['label'];
    $status = $active ? 'aktif' : 'pindah';
    $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
        (tahun_ajaran_id, no_induk, kelas, master_kelas_id, kelas_rombel_snapshot, spp_perbulan_snapshot, komite_snapshot, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          id = LAST_INSERT_ID(id), kelas = VALUES(kelas), master_kelas_id = VALUES(master_kelas_id),
          kelas_rombel_snapshot = VALUES(kelas_rombel_snapshot),
          spp_perbulan_snapshot = VALUES(spp_perbulan_snapshot), komite_snapshot = VALUES(komite_snapshot),
          status = VALUES(status)");
    $stmt->bind_param('issisdds', $yearId, $noInduk, $level, $classId, $label, $spp, $komite, $status);
    $stmt->execute();
    $placementId = (int)$db->insert_id;
    $stmt->close();
    if ($placementId <= 0) {
        $stmt = $db->prepare('SELECT id FROM siswa_tahun_ajaran WHERE tahun_ajaran_id = ? AND no_induk = ? LIMIT 1');
        $stmt->bind_param('is', $yearId, $noInduk);
        $stmt->execute();
        $placementId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
    }
    return $placementId > 0 ? $placementId : null;
}
