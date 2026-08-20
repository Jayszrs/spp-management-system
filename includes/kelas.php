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

function class_next_academic_year_label(string $label): string {
    $label = du_normalize_academic_year($label);
    $start = (int)substr($label, 0, 4) + 1;
    return $start . '/' . ($start + 1);
}

function class_promotion_source_rows(mysqli $db, string $sourceLabel, bool $forUpdate = false): array {
    $sourceLabel = du_normalize_academic_year($sourceLabel);
    $sql = "SELECT sta.id AS placement_id, sta.no_induk, sta.kelas, sta.master_kelas_id,
                   sta.kelas_rombel_snapshot, sta.status AS placement_status,
                   sta.spp_perbulan_snapshot, sta.komite_snapshot,
                   s.id AS siswa_id, s.NAMA, s.KELAS AS siswa_kelas, s.master_kelas_id AS siswa_master_kelas_id,
                   s.SPP_PERBULAN, s.POMG, s.is_active,
                   mk.tingkat, mk.kode_rombel, mk.is_placeholder
            FROM siswa_tahun_ajaran sta
            JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
            JOIN siswa s ON s.NO_INDUK = sta.no_induk
            LEFT JOIN master_kelas mk ON mk.id = sta.master_kelas_id
            WHERE ta.label = ? AND sta.status = 'aktif' AND s.is_active = 1
            ORDER BY COALESCE(mk.tingkat, CAST(sta.kelas AS UNSIGNED)), mk.is_placeholder, mk.kode_rombel, s.NAMA"
            . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $sourceLabel);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function class_promotion_preview(mysqli $db, string $sourceLabel, ?string $targetLabel = null): array {
    $sourceLabel = du_normalize_academic_year($sourceLabel);
    $targetLabel = du_normalize_academic_year($targetLabel ?: class_next_academic_year_label($sourceLabel));
    if ($targetLabel !== class_next_academic_year_label($sourceLabel)) {
        throw new RuntimeException('Target tahun ajaran harus tepat satu tahun setelah sumber.');
    }
    $rows = class_promotion_source_rows($db, $sourceLabel);
    $summary = [];
    $missingClasses = [];
    foreach ($rows as $row) {
        $level = (int)($row['tingkat'] ?: $row['kelas']);
        $code = strtoupper((string)($row['kode_rombel'] ?: 'BELUM'));
        $sourceClass = class_label([
            'tingkat' => $level,
            'kode_rombel' => $code,
            'is_placeholder' => (int)($row['is_placeholder'] ?? 1),
        ]);
        if ($level >= 6) {
            $targetClass = 'Lulus';
            $key = $sourceClass . '->Lulus';
        } else {
            $targetLevel = $level + 1;
            $targetClass = ((int)($row['is_placeholder'] ?? 1) === 1)
                ? 'Kelas ' . $targetLevel . ' (Belum Ditentukan)'
                : $targetLevel . $code;
            $key = $sourceClass . '->' . $targetClass;
            $stmt = $db->prepare('SELECT id FROM master_kelas WHERE tingkat=? AND kode_rombel=? LIMIT 1');
            $stmt->bind_param('is', $targetLevel, $code);
            $stmt->execute();
            $exists = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) $missingClasses[$targetLevel . $code] = ['tingkat' => $targetLevel, 'kode_rombel' => $code];
        }
        if (!isset($summary[$key])) {
            $summary[$key] = ['source' => $sourceClass, 'target' => $targetClass, 'count' => 0];
        }
        $summary[$key]['count']++;
    }
    return [
        'source' => $sourceLabel,
        'target' => $targetLabel,
        'total' => count($rows),
        'graduated' => array_sum(array_map(static fn($item) => $item['target'] === 'Lulus' ? (int)$item['count'] : 0, $summary)),
        'summary' => array_values($summary),
        'missing_classes' => array_values($missingClasses),
    ];
}

function class_ensure_academic_year(mysqli $db, string $label): int {
    $label = du_normalize_academic_year($label);
    [$startDate, $endDate] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status)
        VALUES (?, ?, ?, 'draft')
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), tanggal_mulai = VALUES(tanggal_mulai), tanggal_selesai = VALUES(tanggal_selesai)");
    $stmt->bind_param('sss', $label, $startDate, $endDate);
    $stmt->execute();
    $yearId = (int)$db->insert_id;
    $stmt->close();
    if ($yearId <= 0) {
        $stmt = $db->prepare('SELECT id FROM tahun_ajaran WHERE label=? LIMIT 1');
        $stmt->bind_param('s', $label);
        $stmt->execute();
        $yearId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
    }
    return $yearId;
}

function class_target_year_has_financial_data(mysqli $db, int $targetYearId, string $targetLabel): bool {
    $stmt = $db->prepare("SELECT
        (SELECT COUNT(*) FROM tagihan_daftar_ulang WHERE tahun_ajaran_id=? AND status='open') +
        (SELECT COUNT(*) FROM bayar WHERE th_ajaran=? AND total_jumlah>0) +
        (SELECT COUNT(*) FROM bayar_du WHERE th_ajaran=? AND jumlah>0) AS total");
    $stmt->bind_param('iss', $targetYearId, $targetLabel, $targetLabel);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total > 0;
}

function class_promote_academic_year(mysqli $db, string $sourceLabel, string $targetLabel): array {
    $sourceLabel = du_normalize_academic_year($sourceLabel);
    $targetLabel = du_normalize_academic_year($targetLabel);
    if ($targetLabel !== class_next_academic_year_label($sourceLabel)) {
        throw new RuntimeException('Target tahun ajaran harus tepat satu tahun setelah sumber.');
    }

    $stmt = $db->prepare("SELECT id, status FROM tahun_ajaran WHERE label=? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('s', $sourceLabel);
    $stmt->execute();
    $sourceYear = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sourceYear) throw new RuntimeException('Tahun ajaran sumber tidak ditemukan.');

    $targetYearId = class_ensure_academic_year($db, $targetLabel);
    $stmt = $db->prepare('SELECT id, status FROM tahun_ajaran WHERE id=? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $targetYearId);
    $stmt->execute();
    $targetYear = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$targetYear || $targetYear['status'] !== 'draft') {
        throw new RuntimeException('Target tahun ajaran harus berstatus draft.');
    }
    if (class_target_year_has_financial_data($db, $targetYearId, $targetLabel)) {
        throw new RuntimeException('Target tahun ajaran sudah memiliki tagihan atau pembayaran. Batalkan/koreksi data target terlebih dahulu.');
    }

    $rows = class_promotion_source_rows($db, $sourceLabel, true);
    if (!$rows) throw new RuntimeException('Tidak ada siswa aktif pada tahun ajaran sumber.');

    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $adminName = (string)($_SESSION['admin_nama'] ?? $_SESSION['admin_username'] ?? 'system');
    $promoted = 0;
    $graduated = 0;

    foreach ($rows as $row) {
        $studentId = (int)$row['siswa_id'];
        $noInduk = (string)$row['no_induk'];
        $oldClassId = (int)($row['siswa_master_kelas_id'] ?: $row['master_kelas_id']);
        $level = (int)($row['tingkat'] ?: $row['kelas']);
        $code = strtoupper((string)($row['kode_rombel'] ?: 'BELUM'));
        $isPlaceholder = (int)($row['is_placeholder'] ?? 1);
        $before = [
            'NO_INDUK' => $noInduk,
            'NAMA' => $row['NAMA'],
            'KELAS' => $row['siswa_kelas'],
            'master_kelas_id' => $oldClassId,
            'is_active' => (int)$row['is_active'],
            'tahun_ajaran' => $sourceLabel,
        ];

        if ($level >= 6) {
            $stmt = $db->prepare('UPDATE siswa SET is_active=0 WHERE id=?');
            $stmt->bind_param('i', $studentId);
            $stmt->execute();
            $stmt->close();
            $targetClassId = $oldClassId;
            $targetClass = class_find($db, $targetClassId) ?: [
                'tingkat' => 6,
                'kode_rombel' => $code,
                'is_placeholder' => $isPlaceholder,
            ];
            $targetLevel = '6';
            $targetStatus = 'lulus';
            $graduated++;
        } else {
            $targetLevelInt = $level + 1;
            $stmt = $db->prepare("INSERT INTO master_kelas (tingkat, kode_rombel, is_placeholder, is_active)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), is_active = 1");
            $stmt->bind_param('isi', $targetLevelInt, $code, $isPlaceholder);
            $stmt->execute();
            $targetClassId = (int)$db->insert_id;
            $stmt->close();
            $targetClass = class_find($db, $targetClassId);
            if (!$targetClass) throw new RuntimeException('Rombel target tidak dapat disiapkan.');
            $targetLevel = (string)$targetLevelInt;
            $targetStatus = 'aktif';
            $stmt = $db->prepare('UPDATE siswa SET KELAS=?, master_kelas_id=?, is_active=1 WHERE id=?');
            $stmt->bind_param('sii', $targetLevel, $targetClassId, $studentId);
            $stmt->execute();
            $stmt->close();
            $promoted++;
        }

        $targetLabelClass = class_label($targetClass);
        $spp = (float)$row['SPP_PERBULAN'];
        $komite = (float)$row['POMG'];
        $stmt = $db->prepare("INSERT INTO siswa_tahun_ajaran
            (tahun_ajaran_id, no_induk, kelas, master_kelas_id, kelas_rombel_snapshot, spp_perbulan_snapshot, komite_snapshot, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              kelas=VALUES(kelas), master_kelas_id=VALUES(master_kelas_id),
              kelas_rombel_snapshot=VALUES(kelas_rombel_snapshot),
              spp_perbulan_snapshot=VALUES(spp_perbulan_snapshot),
              komite_snapshot=VALUES(komite_snapshot), status=VALUES(status)");
        $stmt->bind_param('issisdds', $targetYearId, $noInduk, $targetLevel, $targetClassId, $targetLabelClass, $spp, $komite, $targetStatus);
        $stmt->execute();
        $stmt->close();

        $after = $before;
        $after['KELAS'] = $targetLevel;
        $after['master_kelas_id'] = $targetClassId;
        $after['is_active'] = $targetStatus === 'aktif' ? 1 : 0;
        $after['tahun_ajaran'] = $targetLabel;
        $afterJson = json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $beforeJson = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $action = 'naik_kelas_otomatis';
        $stmt = $db->prepare("INSERT INTO siswa_audit_log
            (siswa_id, no_induk_snapshot, aksi, before_data, after_data, admin_id, admin_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issssis', $studentId, $noInduk, $action, $beforeJson, $afterJson, $adminId, $adminName);
        $stmt->execute();
        $stmt->close();
    }

    return ['promoted' => $promoted, 'graduated' => $graduated, 'target' => $targetLabel, 'source' => $sourceLabel];
}
