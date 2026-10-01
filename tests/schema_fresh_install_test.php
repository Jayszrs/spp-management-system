<?php

if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1') {
    throw new RuntimeException('Jalankan hanya lewat CLI dengan SPP_TEST_ALLOW_MUTATION=1.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$database = 'db_spp_schema_install_test_' . bin2hex(random_bytes(6));
$host = getenv('SPP_DB_HOST') ?: 'localhost';
$user = getenv('SPP_DB_USER') ?: 'root';
$pass = getenv('SPP_DB_PASS') !== false ? getenv('SPP_DB_PASS') : '';
$db = new mysqli($host, $user, $pass);
$db->set_charset('utf8mb4');
$created = false;

try {
    // CREATE without IF NOT EXISTS reserves only a fresh disposable target.
    // If the unlikely random name already exists, leave that database untouched.
    $db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db->select_db($database);
    $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
    if ($sql === false) throw new RuntimeException('sql/schema.sql tidak dapat dibaca.');
    $sql = str_replace(
        ['CREATE DATABASE IF NOT EXISTS `db_spp`', 'USE `db_spp`'],
        ["CREATE DATABASE IF NOT EXISTS `{$database}`", "USE `{$database}`"],
        $sql,
        $replacementCount
    );
    if ($replacementCount !== 2) throw new RuntimeException('Kontrak nama database pada schema.sql berubah.');

    $db->multi_query($sql);
    while ($db->more_results()) $db->next_result();

    $required = ['master_spp_tahun', 'master_spp_tarif', 'tagihan_spp', 'spp_alokasi_batch', 'spp_alokasi', 'titipan_spp_mutasi', 'spp_audit_log', 'transaksi_otorisasi', 'keuangan_request'];
    $quoted = implode(',', array_fill(0, count($required), '?'));
    $stmt = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ({$quoted})");
    $params = array_merge([$database], $required);
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    $tables = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $stmt->close();
    sort($tables); sort($required);
    if ($tables !== $required) throw new RuntimeException('Tabel Master SPP pada instalasi baru tidak lengkap.');

    $empty = $db->query("SELECT
        (SELECT COUNT(*) FROM `{$database}`.siswa) siswa,
        (SELECT COUNT(*) FROM `{$database}`.bayar) pembayaran,
        (SELECT COUNT(*) FROM `{$database}`.tahun_ajaran) tahun_ajaran")->fetch_assoc();
    if ((int)$empty['siswa'] !== 0 || (int)$empty['pembayaran'] !== 0 || (int)$empty['tahun_ajaran'] !== 0) {
        throw new RuntimeException('schema.sql tidak boleh menyisipkan siswa, pembayaran, atau tahun ajaran demo.');
    }

    // The documented fresh install continues through migrate_units.php.
    // A schema-only check misses constraints that still limit SMP/SMA to grade 6.
    putenv('SPP_DB_NAME=' . $database);
    require __DIR__ . '/../sql/migrate_units.php';
    if ((int)$koneksi->query("SELECT COUNT(*) n FROM information_schema.VIEWS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa'")->fetch_assoc()['n'] !== 1) {
        throw new RuntimeException('Migrasi multiunit tidak membuat view operasional siswa.');
    }
    foreach ([2 => 7, 3 => 10] as $unitId => $grade) {
        unit_set_context($koneksi, $unitId);
        $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat={$grade} AND kode_rombel='A'")->fetch_assoc();
        if (!$class) throw new RuntimeException("Kelas {$grade}A unit {$unitId} tidak tersedia.");
        $koneksi->query("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status)
            VALUES('2026/2027','2026-07-01','2027-06-30','draft')");
        $yearId = (int)$koneksi->insert_id;
        $koneksi->query("INSERT INTO master_spp_tahun(tahun_ajaran_id) VALUES({$yearId})");
        $masterId = (int)$koneksi->insert_id;
        $koneksi->query("INSERT INTO master_spp_tarif(master_spp_tahun_id,tingkat,nominal_dasar)
            VALUES({$masterId},{$grade},250000)");
        $wrongGrade = $unitId === 2 ? 6 : 7;
        $rejected = false;
        try {
            $koneksi->query("INSERT INTO master_spp_tarif(master_spp_tahun_id,tingkat,nominal_dasar)
                VALUES({$masterId},{$wrongGrade},250000)");
        } catch (mysqli_sql_exception $error) {
            $rejected = $error->getSqlState() === '45000';
        }
        if (!$rejected) throw new RuntimeException("Tarif tingkat {$wrongGrade} diterima di unit {$unitId}.");
    }

    echo "OK: instalasi baru dan migrasi multiunit menerima tarif SPP serta kelas awal SMP/SMA.\n";
} finally {
    if ($created) {
        $currentDatabase = (string)$db->query('SELECT DATABASE()')->fetch_row()[0];
        if (!preg_match('/^db_spp_schema_install_test_[a-f0-9]{12}$/D', $database)
            || $currentDatabase !== $database) {
            throw new RuntimeException('Target penghapusan database latihan tidak sesuai; database dipertahankan.');
        }
        $db->query("DROP DATABASE `{$database}`");
    }
    $db->close();
}
