<?php
/** Fixture and verifier for the real-browser promotion flow on an isolated clone. */
if (PHP_SAPI !== 'cli'
    || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) {
    fwrite(STDERR, "FAILED: fixture hanya boleh berjalan lewat CLI pada clone audit dengan flag tes.\n");
    exit(1);
}

$action = $argv[1] ?? '';
if (!in_array($action, ['setup', 'verify'], true)) {
    fwrite(STDERR, "Usage: php tests/promotion_browser_fixture.php setup|verify\n");
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';
unit_set_context($koneksi, 1);

function promotion_browser_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$database = (string)$koneksi->query('SELECT DATABASE()')->fetch_row()[0];
promotion_browser_assert($database === DB_NAME, 'Koneksi tidak menuju clone yang diminta.');

$sourceYear = (string)(getenv('SPP_TEST_PROMOTION_SOURCE_YEAR') ?: '2098/2099');
promotion_browser_assert(preg_match('/^(\d{4})\/(\d{4})$/D',$sourceYear,$yearParts)===1
    && (int)$yearParts[2]===(int)$yearParts[1]+1,'Tahun sumber fixture tidak valid.');
$targetYear = $yearParts[2].'/'.((int)$yearParts[2]+1);
$students = [
    '9988000001' => ['name' => 'UJI BROWSER LULUS', 'level' => 6],
    '9988000002' => ['name' => 'UJI BROWSER NAIK SATU', 'level' => 5],
    '9988000003' => ['name' => 'UJI BROWSER NAIK DUA', 'level' => 5],
];

try {
    if ($action === 'setup') {
        $passwordFile = (string)getenv('SPP_TEST_ADMIN_PASSWORD_FILE');
        promotion_browser_assert($passwordFile !== '' && is_file($passwordFile), 'File sandi admin latihan wajib tersedia.');
        $password = trim((string)file_get_contents($passwordFile));
        promotion_browser_assert($password !== '', 'Sandi admin latihan kosong.');

        $koneksi->begin_transaction();
        $yearStmt = $koneksi->prepare('SELECT COUNT(*) AS n FROM tahun_ajaran WHERE label IN (?, ?)');
        $yearStmt->bind_param('ss', $sourceYear, $targetYear);
        $yearStmt->execute();
        $existingYears = (int)$yearStmt->get_result()->fetch_assoc()['n'];
        $yearStmt->close();
        promotion_browser_assert($existingYears === 0, 'Tahun fixture sudah dipakai; gunakan clone baru.');

        $nisList = array_keys($students);
        $checkStudent = $koneksi->prepare('SELECT COUNT(*) AS n FROM siswa WHERE NO_INDUK IN (?, ?, ?)');
        $checkStudent->bind_param('sss', $nisList[0], $nisList[1], $nisList[2]);
        $checkStudent->execute();
        $existingStudents = (int)$checkStudent->get_result()->fetch_assoc()['n'];
        $checkStudent->close();
        promotion_browser_assert($existingStudents === 0, 'NIS fixture sudah dipakai; gunakan clone baru.');

        $account = $koneksi->query("SELECT id FROM admin WHERE username='admin' AND role='admin' AND unit_id=1 AND is_active=1 LIMIT 1")->fetch_assoc();
        promotion_browser_assert((bool)$account, 'Akun admin SD aktif tidak tersedia.');
        $accountId = (int)$account['id'];
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $updateAccount = $koneksi->prepare('UPDATE admin SET password=? WHERE id=?');
        $updateAccount->bind_param('si', $hash, $accountId);
        $updateAccount->execute();
        $updateAccount->close();

        $classes = [];
        foreach ([5, 6] as $level) {
            $findClass = $koneksi->prepare("SELECT id,tingkat,kode_rombel,is_placeholder FROM master_kelas WHERE tingkat=? AND kode_rombel='A' AND is_active=1 AND is_placeholder=0 LIMIT 1");
            $findClass->bind_param('i', $level);
            $findClass->execute();
            $classes[$level] = $findClass->get_result()->fetch_assoc();
            $findClass->close();
            promotion_browser_assert((bool)$classes[$level], 'Rombel fixture tidak tersedia.');
        }

        $yearId = class_ensure_academic_year($koneksi, $sourceYear);
        $insertStudent = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active) VALUES(?,?,?,?,1)');
        $insertPlacement = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'aktif')");
        foreach ($students as $nis => $student) {
            $level = (int)$student['level'];
            $levelText = (string)$level;
            $classId = (int)$classes[$level]['id'];
            $snapshot = class_label($classes[$level]);
            $name = $student['name'];
            $insertStudent->bind_param('sssi', $nis, $name, $levelText, $classId);
            $insertStudent->execute();
            $insertPlacement->bind_param('issis', $yearId, $nis, $levelText, $classId, $snapshot);
            $insertPlacement->execute();
        }
        $insertStudent->close();
        $insertPlacement->close();
        promotion_browser_assert(class_highest_active_regular_level($koneksi, $targetYear) === 6, 'Tahap awal harus kelulusan kelas 6.');
        $koneksi->commit();
        echo "OK: fixture browser kelas 6 dan dua siswa kelas 5 siap pada clone.\n";
    } else {
        $lookup = $koneksi->prepare('SELECT s.KELAS,s.is_active,ta.label,sta.kelas AS historical_class,sta.status,sta.master_kelas_id
            FROM siswa s JOIN siswa_tahun_ajaran sta ON sta.no_induk=s.NO_INDUK
            JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id
            WHERE s.NO_INDUK=? ORDER BY ta.label');
        foreach ($students as $nis => $student) {
            $lookup->bind_param('s', $nis);
            $lookup->execute();
            $rows = $lookup->get_result()->fetch_all(MYSQLI_ASSOC);
            if ((int)$student['level'] === 6) {
                promotion_browser_assert(count($rows) === 1 && $rows[0]['label'] === $sourceYear
                    && $rows[0]['historical_class'] === '6' && $rows[0]['status'] === 'lulus'
                    && (int)$rows[0]['is_active'] === 0,
                    'Kelulusan browser mengubah riwayat atau status secara keliru.');
            } else {
                promotion_browser_assert(count($rows) === 2 && $rows[0]['label'] === $sourceYear
                    && $rows[0]['historical_class'] === '5' && $rows[0]['status'] === 'pindah'
                    && $rows[1]['label'] === $targetYear && $rows[1]['historical_class'] === '6'
                    && $rows[1]['status'] === 'aktif' && $rows[1]['KELAS'] === '6'
                    && (int)$rows[1]['is_active'] === 1,
                    'Kenaikan browser menggandakan atau merusak riwayat kelas.');
            }
        }
        $lookup->close();
        promotion_browser_assert(class_highest_active_regular_level($koneksi, $targetYear) === 0,
            'Siswa asal masih dianggap belum diproses.');
        echo "OK: database mencatat satu kelulusan, dua kenaikan, dan riwayat asal/tujuan tepat satu kali.\n";
    }
} catch (Throwable $error) {
    if ($action === 'setup') $koneksi->rollback();
    fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
