<?php

/** Shared gate for DDL that changes the financial schema. Audit mode stays read-only. */
function readiness_migration_assert_apply_allowed(array $arguments, string $database): void {
    if (preg_match('/^db_spp_(?:audit|test)_[a-z0-9_]+$/i', $database)
        && getenv('SPP_TEST_ALLOW_MUTATION') === '1') {
        return;
    }

    $backupArguments = array_values(array_filter($arguments,
        static fn(string $argument): bool => str_starts_with($argument, '--backup-file=')));
    $backup = count($backupArguments) === 1
        ? substr($backupArguments[0], strlen('--backup-file=')) : '';
    $resolvedBackup = $backup !== '' ? realpath($backup) : false;
    $workspace = realpath(dirname(__DIR__));
    $outsideWorkspace = $resolvedBackup !== false && $workspace !== false
        && !str_starts_with(strtolower($resolvedBackup), strtolower($workspace . DIRECTORY_SEPARATOR));
    $recentBackup = $resolvedBackup !== false && is_file($resolvedBackup)
        && filesize($resolvedBackup) > 1000
        && filemtime($resolvedBackup) >= time() - 3600;

    if ($database === 'db_spp' && getenv('SPP_ALLOW_MAIN_MIGRATION') === '1'
        && in_array('--confirm-main=db_spp', $arguments, true)
        && $outsideWorkspace && $recentBackup) {
        return;
    }

    throw new RuntimeException('DDL hanya boleh pada clone db_spp_audit_*/db_spp_test_* dengan '
        . 'SPP_TEST_ALLOW_MUTATION=1. Database utama memerlukan persetujuan pemilik, '
        . 'SPP_ALLOW_MAIN_MIGRATION=1, --confirm-main=db_spp, dan '
        . '--backup-file=<dump baru di luar repository> (maksimal satu jam).');
}
