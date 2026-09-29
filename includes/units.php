<?php

function unit_schema_ready(mysqli $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $row = $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='unit_sekolah'")->fetch_assoc();
    return $ready = (int)$row['n'] > 0;
}

function unit_set_context(mysqli $db, int $unitId): void {
    if ($unitId < 0 || $unitId > 4) throw new InvalidArgumentException('Unit tidak valid.');
    $db->query('SET @app_unit_id=' . $unitId);
    $GLOBALS['app_unit_id'] = $unitId;
}

function unit_active_id(): int {
    return (int)($_SESSION['active_unit_id'] ?? $_SESSION['admin_unit_id'] ?? 1);
}

function unit_is_super(): bool {
    return ($_SESSION['admin_role'] ?? '') === 'super_admin';
}

function unit_palette_for_view(?int $reportUnitId = null): string {
    if (unit_is_super() && $reportUnitId === 0) return 'super';
    return match (unit_active_id()) { 2 => 'smp', 3 => 'sma', default => 'sd' };
}

function unit_label(int $unitId): string {
    return [0=>'Semua Unit',1=>'SD',2=>'SMP',3=>'SMA'][$unitId] ?? 'Unit tidak dikenal';
}

function unit_school_name(int $unitId): string {
    return match ($unitId) {
        1 => "SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH",
        2 => 'SEKOLAH MENENGAH PERTAMA (SMP) MUTIARA HIKMAH',
        3 => 'SEKOLAH MENENGAH ATAS (SMA) MUTIARA HIKMAH',
        default => 'MUTIARA HIKMAH · SD, SMP, SMA',
    };
}

function unit_level_bounds(?int $unitId = null): array {
    return match ($unitId ?? unit_active_id()) {
        2 => [7,9], 3 => [10,12], default => [1,6],
    };
}

function unit_level_in_sql(): string {
    [$first,$last] = unit_level_bounds();
    return '(' . implode(',', array_map(static fn($level) => "'{$level}'", range($first,$last))) . ')';
}

function unit_level_between_sql(): string {
    [$first,$last] = unit_level_bounds();
    return "BETWEEN {$first} AND {$last}";
}

function unit_bootstrap_context(mysqli $db): void {
    if (!unit_schema_ready($db)) {
        unit_set_context($db, 1);
        return;
    }
    if (empty($_SESSION['admin_id'])) {
        unit_set_context($db, PHP_SAPI === 'cli' ? 1 : 4);
        return;
    }
    $id = (int)$_SESSION['admin_id'];
    $stmt = $db->prepare('SELECT id,nama,role,unit_id,is_active FROM admin WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $id); $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$account || (int)$account['is_active'] !== 1) {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['admin_unit_id'], $_SESSION['active_unit_id']);
        unit_set_context($db, 4);
        return;
    }
    $_SESSION['admin_role'] = $account['role'];
    $_SESSION['admin_nama'] = $account['nama'];
    $_SESSION['admin_unit_id'] = $account['unit_id'] === null ? null : (int)$account['unit_id'];
    if ($account['role'] === 'super_admin') {
        $selected = (int)($_SESSION['active_unit_id'] ?? 1);
        if ($selected < 1 || $selected > 3) $selected = 1;
    } else {
        $selected = (int)$account['unit_id'];
    }
    $_SESSION['active_unit_id'] = $selected;
    unit_set_context($db, $selected);
}

function unit_report_scope(mysqli $db, string $choice): int {
    $unitId = unit_active_id();
    if (unit_is_super() && $choice === 'all') $unitId = 0;
    unit_set_context($db, $unitId);
    return $unitId;
}

function unit_report_selector(int $reportUnitId): string {
    if (!unit_is_super()) return '';
    $selected = $reportUnitId === 0 ? 'all' : 'active';
    return '<label class="unit-report-picker">Cakupan rekap <select class="field-input field-select" name="unit" onchange="this.form.submit()">'
        . '<option value="active"' . ($selected === 'active' ? ' selected' : '') . '>Unit aktif: ' . unit_label(unit_active_id()) . '</option>'
        . '<option value="all"' . ($selected === 'all' ? ' selected' : '') . '>Semua Unit</option>'
        . '</select></label>';
}
