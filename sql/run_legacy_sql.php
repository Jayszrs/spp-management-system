<?php
/**
 * Explicit entrypoint for legacy SQL files. The .sql paths reject direct import;
 * their definitions live in definitions/ so this gate owns target selection.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/readiness_migration_guard.php';
require_once __DIR__ . '/legacy_sql_definition.php';

const LEGACY_SQL_SCRIPTS = [
    'activate_legacy_fields.sql' => 'historical',
    'add_academic_year_billing.sql' => 'migration',
    'add_annual_payment_receipts.sql' => 'migration',
    'add_annual_student_fees.sql' => 'migration',
    'add_financial_request_guard.sql' => 'dedicated',
    'add_master_biaya_lain.sql' => 'migration',
    'add_master_daftar_ulang.sql' => 'migration',
    'add_modular_global_reports.sql' => 'migration',
    'add_payment_method.sql' => 'migration',
    'add_payment_references.sql' => 'migration',
    'add_payment_updated_at.sql' => 'migration',
    'add_psb_and_spp_full_rules.sql' => 'migration',
    'add_role_management.sql' => 'migration',
    'add_spp_billing_and_deposit.sql' => 'migration',
    'add_student_advanced.sql' => 'migration',
    'add_student_optional_fees.sql' => 'migration',
    'add_transaction_authorization.sql' => 'migration',
    'allow_spp_installments.sql' => 'retired',
    'fix_spp_allocation_edit.sql' => 'migration',
    'migrate_komite_bulanan.sql' => 'historical',
    'remove_payment_linked_savings.sql' => 'historical',
    'repair_negative_savings_balances.sql' => 'historical',
    'repair_one_time_fees.sql' => 'historical',
    'reset_demo_students_and_finance.sql' => 'demo',
    'restore_mysql84_checks.sql' => 'historical',
    'seed_demo_payments.sql' => 'demo',
    'seed_students_psb.sql' => 'demo',
    'simplify_payment_components_and_add_psb.sql' => 'historical',
    'sync_student_initial_fee_paid_totals.sql' => 'historical',
];
function legacy_sql_statements(string $sql): array {
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\r\n|\n|\r/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
            if (trim(preg_replace('/^\s*(?:--|#).*$/m', '', $buffer)) !== '') {
                throw new RuntimeException('DELIMITER ditemukan di tengah statement SQL.');
            }
            $buffer = '';
            $delimiter = $match[1];
            continue;
        }
        $trimmed = rtrim($line);
        if ($trimmed !== '' && str_ends_with($trimmed, $delimiter)
            && !preg_match('/^\s*(?:--|#)/', $trimmed)) {
            $buffer .= substr($trimmed, 0, -strlen($delimiter)) . "\n";
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
        } else {
            $buffer .= $line . "\n";
        }
    }
    if (trim(preg_replace('/^\s*(?:--|#).*$/m', '', $buffer)) !== '') {
        throw new RuntimeException('Statement SQL terakhir tidak diakhiri delimiter.');
    }
    return $statements;
}

function legacy_sql_run(mysqli $db, array $statements): void {
    foreach ($statements as $number => $statement) {
        try {
            $result = $db->query($statement);
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        } catch (Throwable $error) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
            throw new RuntimeException('Statement ' . ($number + 1) . ' gagal; DDL sebelumnya mungkin sudah committed. '
                . $error->getMessage(), 0, $error);
        }
    }
}

function legacy_sql_main(array $arguments): void {
    if (in_array('--list', $arguments, true)) {
        foreach (LEGACY_SQL_SCRIPTS as $name => $type) {
            echo "$name\t$type\n";
        }
        return;
    }
    if (in_array('--check-definitions', $arguments, true)) {
        foreach (LEGACY_SQL_SCRIPTS as $name => $type) {
            $definition = legacy_sql_definition($name);
            $statements = legacy_sql_statements($definition);
            echo "$name\t" . count($statements) . " statement\n";
        }
        return;
    }

    $scriptArguments = array_values(array_filter($arguments,
        static fn(string $argument): bool => str_starts_with($argument, '--script=')));
    $script = count($scriptArguments) === 1 ? substr($scriptArguments[0], 9) : '';
    if (!isset(LEGACY_SQL_SCRIPTS[$script])) {
        throw new RuntimeException('Pilih satu --script=<nama.sql> dari --list.');
    }
    $type = LEGACY_SQL_SCRIPTS[$script];
    if ($type === 'retired') {
        throw new RuntimeException("$script sudah usang dan tidak boleh dijalankan.");
    }
    if ($type === 'dedicated') {
        throw new RuntimeException("Gunakan php sql/add_financial_request_guard.php untuk $script.");
    }

    $database = (string)getenv('SPP_DB_NAME');
    if ($database === '') {
        throw new RuntimeException('Set SPP_DB_NAME secara eksplisit sebelum memilih target.');
    }
    echo "Target: $database; script: $script; kategori: $type\n";
    if (!in_array('--apply', $arguments, true)) {
        echo "AUDIT ONLY: belum ada koneksi atau perubahan database.\n";
        return;
    }
    if (!in_array('--confirm-script=' . $script, $arguments, true)) {
        throw new RuntimeException("Tambahkan --confirm-script=$script untuk menerapkan script yang dipilih.");
    }
    if (($type === 'demo' || $type === 'historical')
        && !preg_match('/^db_spp_(?:audit|test)_[a-z0-9_]+$/iD', $database)) {
        throw new RuntimeException('Script demo/historis hanya boleh pada clone disposable.');
    }
    readiness_migration_assert_apply_allowed($arguments, $database);

    $sql = legacy_sql_definition($script);
    // Legacy USE db_spp lines must never redirect an approved clone to main.
    $sql = preg_replace('/^\s*USE\s+`db_spp`\s*;\s*$/mi', '', $sql);
    if (preg_match('/^\s*(?:USE|CREATE\s+DATABASE|DROP\s+DATABASE)\b/im', $sql)) {
        throw new RuntimeException('Definisi SQL mencoba mengganti target database.');
    }
    if ($script === 'reset_demo_students_and_finance.sql') {
        $sql = str_replace("SET @spp_reset_confirmation := '';",
            "SET @spp_reset_confirmation := 'RESET_DEMO_2026';", $sql, $count);
        if ($count !== 1) throw new RuntimeException('Token reset berubah.');
    } elseif ($script === 'seed_demo_payments.sql') {
        $sql = str_replace("SET @seed_demo_payment_confirmation := '';",
            "SET @seed_demo_payment_confirmation := 'SEED_PAYMENT_DEMO_2026';", $sql, $count);
        if ($count !== 1) throw new RuntimeException('Token seed pembayaran berubah.');
    }
    $statements = legacy_sql_statements($sql);

    $host = getenv('SPP_DB_HOST') ?: 'localhost';
    $user = getenv('SPP_DB_USER') ?: 'root';
    $pass = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
    $port = filter_var(getenv('SPP_DB_PORT') ?: '3306', FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) throw new RuntimeException('Port database tidak valid.');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $pass, $database, $port);
    try {
        $selected = $db->query('SELECT DATABASE() AS selected_db')->fetch_assoc()['selected_db'];
        if ($selected !== $database) {
            throw new RuntimeException('Nama database koneksi berbeda dari target yang dikonfirmasi.');
        }
        if (stripos($db->server_info, 'MariaDB') === false
            && preg_match('/\b(?:ADD\s+(?:COLUMN|INDEX|KEY)\s+IF\s+NOT\s+EXISTS|DROP\s+CONSTRAINT\s+IF\s+EXISTS)\b/i', $sql)) {
            throw new RuntimeException('Definisi ini memakai ALTER TABLE MariaDB yang tidak didukung MySQL ' .
                $db->server_info . '; tidak ada statement dijalankan. Tinjau migrasi untuk dialek target.');
        }
        $db->set_charset('utf8mb4');
        legacy_sql_run($db, $statements);
    } finally {
        $db->close();
    }
    echo 'OK: ' . count($statements) . " statement dijalankan pada $database. Verifikasi hasil dan simpan catatan eksekusi.\n";
}

try {
    legacy_sql_main(array_slice($argv, 1));
} catch (Throwable $error) {
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . "\n");
    exit(1);
}
