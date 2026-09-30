<?php
/** Run only against a disposable, migrated copy of the database. */
if (PHP_SAPI !== 'cli' || !str_starts_with((string)getenv('SPP_DB_NAME'), 'db_spp_test_')) {
    throw new RuntimeException('Gunakan database pengujian db_spp_test_* melalui CLI.');
}
session_start();
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/kelas.php';
require_once __DIR__ . '/../includes/spp_billing.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/transaction_authorization.php';

function assert_unit(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$accounts = $koneksi->query("SELECT role,unit_id,is_active,COUNT(*) n FROM admin GROUP BY role,unit_id,is_active")->fetch_all(MYSQLI_ASSOC);
foreach ([1,2,3] as $unit) {
    foreach (['admin'=>1,'kasir'=>4,'bendahara'=>1] as $role=>$count) {
        $found = array_values(array_filter($accounts, static fn($row) => $row['role']===$role && (int)$row['unit_id']===$unit && (int)$row['is_active']===1));
        assert_unit(count($found)===1 && (int)$found[0]['n']===$count, "Jumlah akun {$role} unit {$unit} salah.");
    }
}

unit_set_context($koneksi, 1);
$sdStudent = $koneksi->query('SELECT id,NO_INDUK,NO_induk_diknas,master_kelas_id FROM siswa ORDER BY id LIMIT 1')->fetch_assoc();
assert_unit((bool)$sdStudent, 'Salinan database harus berisi siswa SD.');
$sdPaymentId = (int)$koneksi->query('SELECT id FROM bayar ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$originalStudents = (int)$koneksi->query('SELECT COUNT(*) n FROM siswa')->fetch_assoc()['n'];
$originalPayments = (float)$koneksi->query('SELECT COALESCE(SUM(total_jumlah),0) n FROM bayar')->fetch_assoc()['n'];
$originalSavings = (float)$koneksi->query('SELECT COALESCE(SUM(SALDO),0) n FROM tabungan')->fetch_assoc()['n'];
$sameDiknas = (string)($sdStudent['NO_induk_diknas'] ?: '9876543210');

$koneksi->begin_transaction();
try {
    foreach ([2=>7,3=>10] as $unit=>$level) {
        $_SESSION['active_unit_id'] = $unit;
        $_SESSION['admin_role'] = 'super_admin';
        unit_set_context($koneksi, $unit);
        $suffix = $unit===2 ? '.smp' : '.sma';
        foreach (report_operator_options($koneksi) as $operatorRow) {
            assert_unit(str_ends_with($operatorRow['username'], $suffix), 'Filter operator menampilkan akun unit lain.');
        }
        $class = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat={$level} AND kode_rombel='A' LIMIT 1")->fetch_assoc();
        assert_unit((bool)$class, "Kelas awal unit {$unit} hilang.");
        $nis = 'TESTUNIT' . $unit;
        $name = 'Uji Unit ' . $unit;
        $classId = (int)$class['id'];
        $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,master_kelas_id,NO_induk_diknas) VALUES (?,?,?,?,?)');
        $levelText = (string)$level;
        $stmt->bind_param('sssis', $nis, $name, $levelText, $classId, $sameDiknas);
        $stmt->execute(); $stmt->close();
        assert_unit((int)$koneksi->query("SELECT COUNT(*) n FROM siswa WHERE NO_INDUK='{$nis}'")->fetch_assoc()['n']===1, 'Siswa baru tidak terlihat di unit sendiri.');
        $paid = (float)($unit * 1000);
        $date = date('Y-m-d H:i:s');
        $month = date('m');
        $year = date('Y');
        $operator = 'uji_unit';
        $stmt = $koneksi->prepare("INSERT INTO bayar (NO_INDUK,KELAS,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,U_PANGKAL,total_jumlah) VALUES (?,?,?,?,?,?,'Tunai',?,?)");
        $stmt->bind_param('ssssssdd', $nis, $levelText, $date, $month, $year, $operator, $paid, $paid);
        $stmt->execute(); $stmt->close();
        assert_unit((float)$koneksi->query('SELECT COALESCE(SUM(total_jumlah),0) n FROM bayar')->fetch_assoc()['n']===$paid, 'Total pembayaran unit salah.');

        $lastLevel = $unit===2 ? 9 : 12;
        $lastClass = $koneksi->query("SELECT id FROM master_kelas WHERE tingkat={$lastLevel} AND kode_rombel='A' LIMIT 1")->fetch_assoc();
        assert_unit((bool)$lastClass, "Kelas akhir unit {$unit} hilang.");
        $lastNis = 'TESTLULUS' . $unit;
        $lastName = 'Uji Lulus Unit ' . $unit;
        $lastText = (string)$lastLevel;
        $lastClassId = (int)$lastClass['id'];
        $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,master_kelas_id) VALUES (?,?,?,?)');
        $stmt->bind_param('sssi', $lastNis, $lastName, $lastText, $lastClassId);
        $stmt->execute(); $stmt->close();
        $academicYear = du_current_academic_year();
        $yearId = class_ensure_academic_year($koneksi, $academicYear);
        $lastSnapshot = $lastText . 'A';
        $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,status) VALUES(?,?,?,?,?,'aktif')");
        $stmt->bind_param('issis', $yearId, $lastNis, $lastText, $lastClassId, $lastSnapshot);
        $stmt->execute(); $stmt->close();
        $targetYear = class_next_academic_year_label(du_current_academic_year());
        $graduation = class_manual_graduate_student($koneksi, $lastNis, $targetYear);
        assert_unit($graduation['action']==='lulus', "Kelulusan kelas {$lastLevel} gagal.");
        assert_unit((int)$koneksi->query("SELECT is_active FROM siswa WHERE NO_INDUK='{$lastNis}'")->fetch_assoc()['is_active']===0, 'Lulusan tidak diarsipkan.');

        foreach (range($level, $lastLevel) as $rateLevel) {
            $classText = (string)$rateLevel;
            $amount = 500000.0;
            $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang (tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES (?,?,?,?)');
            $stmt->bind_param('issd', $yearId, $academicYear, $classText, $amount);
            $stmt->execute(); $stmt->close();
        }
        assert_unit(du_publish_year_from_active_students($koneksi, $yearId, $academicYear)===2, "Penerbitan Daftar Ulang unit {$unit} gagal.");
        $master = spp_master_ensure_year($koneksi, $academicYear);
        $rates = array_fill_keys(range($level, $lastLevel), 250000.0);
        spp_master_save_rates($koneksi, (int)$master['id'], $rates);
        $published = spp_publish_students($koneksi, (int)$master['id'], [$nis]);
        assert_unit((int)$published['created']===12, "Penerbitan SPP unit {$unit} gagal.");
        assert_unit((int)$koneksi->query('SELECT COUNT(*) n FROM tagihan_spp')->fetch_assoc()['n']===12, 'Tagihan SPP unit lain terlihat.');
        $sppAmount = 250000.0;
        $sppMonth = '07';
        $sppYear = substr($academicYear, 0, 4);
        $stmt = $koneksi->prepare("INSERT INTO bayar (NO_INDUK,KELAS,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,U_SPP,total_jumlah) VALUES (?,?,?,?,?,?,'Tunai',?,?)");
        $stmt->bind_param('ssssssdd', $nis, $levelText, $date, $sppMonth, $sppYear, $operator, $sppAmount, $sppAmount);
        $stmt->execute();
        $sppPaymentId = (int)$koneksi->insert_id; $stmt->close();
        $allocation = spp_allocate_payment($koneksi, $nis, $sppPaymentId, $sppMonth, $sppYear, $sppAmount, false, $date, 'Tunai', $operator);
        assert_unit((int)$allocation['bill_count']===1, "Pembayaran SPP unit {$unit} gagal.");
        $ownSnapshot = transaction_authorization_snapshot($koneksi, $sppPaymentId);
        assert_unit((int)$ownSnapshot['data']['payment']['id']===$sppPaymentId, 'Otorisasi tidak menemukan transaksi unit sendiri.');
        $foreignPaymentRejected = false;
        try { transaction_authorization_snapshot($koneksi, $sdPaymentId); }
        catch (RuntimeException $error) { $foreignPaymentRejected = $error->getMessage()==='Transaksi pembayaran tidak ditemukan.'; }
        assert_unit($foreignPaymentRejected, 'Otorisasi dapat membaca transaksi SD dari unit lain.');
        $koneksi->query("INSERT INTO tabungan (NO_INDUK,SALDO) VALUES ('{$nis}',10000)");
        $stmt = $koneksi->prepare('INSERT INTO transaksi_m (NO_INDUK,TANGGAL,MASUK,user_id) VALUES (?,?,10000,?)');
        $stmt->bind_param('sss', $nis, $date, $operator); $stmt->execute(); $stmt->close();
        $koneksi->query("UPDATE tabungan SET SALDO=7000 WHERE NO_INDUK='{$nis}'");
        $stmt = $koneksi->prepare('INSERT INTO transaksi_k (NO_INDUK,TANGGAL,KELUAR,user_id) VALUES (?,?,3000,?)');
        $stmt->bind_param('sss', $nis, $date, $operator); $stmt->execute(); $stmt->close();
        assert_unit((float)$koneksi->query('SELECT COALESCE(SUM(SALDO),0) n FROM tabungan')->fetch_assoc()['n']===7000.0, 'Saldo tabungan unit salah.');

        $foreignId = (int)$sdStudent['id'];
        $stmt = $koneksi->prepare('UPDATE siswa SET NAMA=? WHERE id=?');
        $stmt->bind_param('si', $name, $foreignId); $stmt->execute();
        assert_unit($stmt->affected_rows===0, 'ID siswa SD dapat diubah oleh unit lain.');
        $stmt->close();
        assert_unit((int)$koneksi->query("SELECT COUNT(*) n FROM bayar")->fetch_assoc()['n']===2, 'Transaksi unit lain terlihat.');
    }

    unit_set_context($koneksi, 2);
    $_SESSION['active_unit_id'] = 2;
    $rejected = false;
    try {
        $nis = 'BADUNITID'; $name = 'Uji Lintas Unit'; $grade = '7'; $foreignClass = (int)$sdStudent['master_kelas_id'];
        $stmt = $koneksi->prepare('INSERT INTO siswa (NO_INDUK,NAMA,KELAS,master_kelas_id) VALUES (?,?,?,?)');
        $stmt->bind_param('sssi', $nis, $name, $grade, $foreignClass);
        $stmt->execute(); $stmt->close();
    } catch (mysqli_sql_exception $error) {
        $rejected = $error->getSqlState()==='45000';
    }
    assert_unit($rejected, 'Relasi kelas lintas unit diterima.');

    unit_set_context($koneksi, 0);
    assert_unit((int)$koneksi->query('SELECT COUNT(*) n FROM siswa')->fetch_assoc()['n']===$originalStudents+4, 'Rekap Semua Unit tidak mencakup semua siswa.');
    assert_unit((float)$koneksi->query('SELECT COALESCE(SUM(total_jumlah),0) n FROM bayar')->fetch_assoc()['n']===$originalPayments+505000, 'Total Semua Unit tidak sama dengan jumlah SD, SMP, dan SMA.');
    assert_unit((float)$koneksi->query('SELECT COALESCE(SUM(SALDO),0) n FROM tabungan')->fetch_assoc()['n']===$originalSavings+14000, 'Saldo Semua Unit tidak sama dengan jumlah per unit.');
    $rejected = false;
    try { $koneksi->query("UPDATE siswa SET NAMA='Tidak boleh' WHERE id=".(int)$sdStudent['id']); }
    catch (mysqli_sql_exception $error) { $rejected=$error->getSqlState()==='45000'; }
    assert_unit($rejected, 'Cakupan Semua Unit mengizinkan perubahan.');
} finally {
    $koneksi->rollback();
    $_SESSION['active_unit_id'] = 1;
    unit_set_context($koneksi, 1);
}
assert_unit((int)$koneksi->query('SELECT COUNT(*) n FROM siswa')->fetch_assoc()['n']===$originalStudents, 'Rollback siswa gagal.');
assert_unit((float)$koneksi->query('SELECT COALESCE(SUM(total_jumlah),0) n FROM bayar')->fetch_assoc()['n']===$originalPayments, 'Total SD berubah.');
assert_unit((float)$koneksi->query('SELECT COALESCE(SUM(SALDO),0) n FROM tabungan')->fetch_assoc()['n']===$originalSavings, 'Saldo SD berubah.');
echo "OK: akun, kelas 7/10, kelulusan 9/12, penerbitan DU/SPP, otorisasi, isolasi data, NIS Diknas lintas unit, rekap dan rollback.\n";
