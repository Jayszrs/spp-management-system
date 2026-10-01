<?php

/** Restore schema.sql foreign keys on a disposable multiunit database only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/audit_foreign_keys.php';

$apply = in_array('--apply', $argv, true);
if ($apply && (DB_NAME !== 'db_spp_audit_schema_20261001'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1')) {
    throw new RuntimeException('DDL FK hanya diizinkan pada clone skema khusus dengan SPP_TEST_ALLOW_MUTATION=1.');
}

$views = $koneksi->query("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('siswa','master_kelas')")->fetch_all(MYSQLI_ASSOC);
if (count($views) !== 2 || count(array_filter($views,
    static fn($row) => $row['TABLE_TYPE'] === 'VIEW')) !== 2) {
    throw new RuntimeException('Database bukan skema multiunit dengan view siswa dan master_kelas.');
}

$report = fk_audit_report($koneksi);
$requiresDdl = array_merge($report['missing'], $report['mismatched']);
$orphanCount = array_sum(array_column($requiresDdl, 'orphans'));
$issueCount = array_sum(array_map(static fn($item) => count($item['preflight_issues']), $requiresDdl));
echo 'PREFLIGHT database=' . $report['database']
    . ' expected=' . $report['expected']
    . ' present=' . $report['present']
    . ' missing=' . count($report['missing'])
    . ' mismatched=' . count($report['mismatched'])
    . ' equivalent_rule_spellings=' . count($report['equivalent_rule_spellings'])
    . ' unresolved=' . count($report['unresolved'])
    . ' orphans=' . $orphanCount
    . ' issues=' . $issueCount
    . ' mode=' . ($apply ? 'apply' : 'read-only') . "\n";

if ($report['unresolved'] || $report['mismatched'] || $orphanCount || $issueCount) {
    foreach ($report['unresolved'] as $item) {
        echo 'UNRESOLVED ' . $item['child'] . '.' . $item['column'] . "\n";
    }
    foreach ($report['missing'] as $item) {
        if (!$item['orphans'] && !$item['preflight_issues']) continue;
        echo 'BLOCKED ' . $item['physical_child'] . '.' . $item['column']
            . ' orphans=' . $item['orphans']
            . ' issues=' . implode(',', $item['preflight_issues']) . "\n";
    }
    foreach ($report['mismatched'] as $item) {
        foreach ($item['actual'] as $actual) {
            echo 'MISMATCH ' . $item['physical_child'] . '.' . $item['column']
                . ' expected=' . $item['physical_parent'] . '.' . $item['parent_column']
                . ' ON DELETE ' . $item['on_delete'] . ' ON UPDATE ' . $item['on_update']
                . ' actual=' . $actual['REFERENCED_TABLE_SCHEMA'] . '.' . $actual['REFERENCED_TABLE_NAME']
                . '.' . $actual['REFERENCED_COLUMN_NAME']
                . ' ON DELETE ' . $actual['DELETE_RULE'] . ' ON UPDATE ' . $actual['UPDATE_RULE']
                . ' columns=' . $actual['column_count']
                . ' orphans=' . $item['orphans']
                . ' issues=' . implode(',', $item['preflight_issues']) . "\n";
            if (count($item['actual']) === 1 && (int)$actual['column_count'] === 1
                && $item['orphans'] === 0 && !$item['preflight_issues']) {
                $child = fk_audit_identifier($item['physical_child']);
                $column = fk_audit_identifier($item['column']);
                $parent = fk_audit_identifier($item['physical_parent']);
                $parentColumn = fk_audit_identifier($item['parent_column']);
                $name = fk_audit_identifier($actual['CONSTRAINT_NAME']);
                // MySQL rejects DROP+ADD with the same constraint name inside
                // one ALTER. A fresh deterministic name keeps each table's
                // replacement atomic and interrupted plans repeatable.
                $newName = 'fk_repaired_' . substr(sha1(strtolower($child . '.' . $column
                    . '>' . $parent . '.' . $parentColumn . ':' . $item['on_delete']
                    . ':' . $item['on_update'])), 0, 16);
                echo 'REVIEW_REPLACEMENT_ONLY ALTER TABLE `' . $child . '` DROP FOREIGN KEY `' . $name
                    . '`, ADD CONSTRAINT `' . $newName . '` FOREIGN KEY (`' . $column . '`) REFERENCES `'
                    . $parent . '` (`' . $parentColumn . '`) ON DELETE ' . $item['on_delete']
                    . ' ON UPDATE ' . $item['on_update'] . ";\n";
            }
        }
    }
    throw new RuntimeException('Pemeriksaan FK belum bersih; tidak ada DDL yang diterapkan.');
}
if (!$apply) exit;

foreach ($report['missing'] as $relation) {
    $child = fk_audit_identifier($relation['physical_child']);
    $column = fk_audit_identifier($relation['column']);
    $parent = fk_audit_identifier($relation['physical_parent']);
    $parentColumn = fk_audit_identifier($relation['parent_column']);
    $delete = $relation['on_delete'];
    $update = $relation['on_update'];
    $name = 'fk_restore_' . substr(sha1(strtolower($child . '.' . $column . '>' . $parent . '.' . $parentColumn)), 0, 16);
    // DDL implicitly commits. Keep each relation deterministic so interrupted runs can resume.
    $koneksi->query("ALTER TABLE `{$child}` ADD CONSTRAINT `{$name}`
        FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`{$parentColumn}`)
        ON DELETE {$delete} ON UPDATE {$update}");
    echo 'ADDED ' . $child . '.' . $column . ' -> ' . $parent . '.' . $parentColumn . "\n";
}

$after = fk_audit_report($koneksi);
echo 'RESULT present=' . $after['present'] . ' missing=' . count($after['missing'])
    . ' mismatched=' . count($after['mismatched']) . "\n";
if ($after['missing'] || $after['mismatched'] || $after['unresolved']) {
    throw new RuntimeException('Verifikasi FK setelah DDL belum lengkap.');
}
