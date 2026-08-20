<?php
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/kelas.php';

function promotion_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$failure = null;
try {
    $koneksi->begin_transaction();
    $suffix = (string)random_int(1000, 9999);
    $source = '2080/2081';
    $target = '2081/2082';
    $code = 'P' . $suffix;
    $sourceStart = '2080-07-01';
    $sourceEnd = '2081-06-30';
    $targetStart = '2081-07-01';
    $targetEnd = '2082-06-30';

    $stmt = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status,published_at) VALUES(?,?,?,'published',NOW())");
    $stmt->bind_param('sss', $source, $sourceStart, $sourceEnd);
    $stmt->execute();
    $stmt->close();
    $stmt = $koneksi->prepare("INSERT INTO tahun_ajaran(label,tanggal_mulai,tanggal_selesai,status) VALUES(?,?,?,'draft')");
    $stmt->bind_param('sss', $target, $targetStart, $targetEnd);
    $stmt->execute();
    $targetYearId = (int)$koneksi->insert_id;
    $stmt->close();
    $sourceYearId = (int)$koneksi->query("SELECT id FROM tahun_ajaran WHERE label='$source'")->fetch_assoc()['id'];

    $stmt = $koneksi->prepare('INSERT INTO master_kelas(tingkat,kode_rombel,is_placeholder,is_active) VALUES(1,?,0,1)');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $class1 = (int)$koneksi->insert_id;
    $stmt->close();
    $stmt = $koneksi->prepare('INSERT INTO master_kelas(tingkat,kode_rombel,is_placeholder,is_active) VALUES(6,?,0,1)');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $class6 = (int)$koneksi->insert_id;
    $stmt->close();

    $nis1 = (string)random_int(9700000000, 9799999999);
    $nis6 = (string)random_int(9600000000, 9699999999);
    $name1 = 'UJI NAIK KELAS 1';
    $name6 = 'UJI NAIK KELAS 6';
    $level1 = '1';
    $level6 = '6';
    $spp = 250000.0;
    $komite = 100000.0;
    $stmt = $koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,POMG,is_active) VALUES(?,?,?,?,?,?,1)');
    $stmt->bind_param('sssidd', $nis1, $name1, $level1, $class1, $spp, $komite);
    $stmt->execute();
    $stmt->bind_param('sssidd', $nis6, $name6, $level6, $class6, $spp, $komite);
    $stmt->execute();
    $stmt->close();

    $label1 = '1' . $code;
    $label6 = '6' . $code;
    $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,status) VALUES(?,?,?,?,?,?,?,'aktif')");
    $stmt->bind_param('issisdd', $sourceYearId, $nis1, $level1, $class1, $label1, $spp, $komite);
    $stmt->execute();
    $stmt->bind_param('issisdd', $sourceYearId, $nis6, $level6, $class6, $label6, $spp, $komite);
    $stmt->execute();
    $stmt->close();

    $preview = class_promotion_preview($koneksi, $source, $target);
    promotion_assert($preview['total'] === 2, 'Preview tidak menghitung dua siswa sumber.');
    promotion_assert(count($preview['missing_classes']) === 1, 'Preview tidak mendeteksi rombel target yang perlu dibuat.');

    $result = class_promote_academic_year($koneksi, $source, $target);
    promotion_assert($result['promoted'] === 1 && $result['graduated'] === 1, 'Jumlah siswa naik/lulus tidak sesuai.');
    $student1 = $koneksi->query("SELECT s.KELAS,s.master_kelas_id,mk.tingkat,mk.kode_rombel,s.is_active FROM siswa s JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.NO_INDUK='$nis1'")->fetch_assoc();
    promotion_assert($student1['KELAS'] === '2' && (int)$student1['tingkat'] === 2 && $student1['kode_rombel'] === $code && (int)$student1['is_active'] === 1, 'Siswa kelas 1 tidak naik ke 2 dengan rombel yang sama.');
    $student6 = $koneksi->query("SELECT is_active FROM siswa WHERE NO_INDUK='$nis6'")->fetch_assoc();
    promotion_assert((int)$student6['is_active'] === 0, 'Siswa kelas 6 tidak diarsipkan sebagai lulus.');
    $placements = (int)$koneksi->query("SELECT COUNT(*) total FROM siswa_tahun_ajaran WHERE tahun_ajaran_id=$targetYearId")->fetch_assoc()['total'];
    promotion_assert($placements === 2, 'Penempatan target tidak terbentuk lengkap.');
    class_promote_academic_year($koneksi, $source, $target);
    $placementsAfterRerun = (int)$koneksi->query("SELECT COUNT(*) total FROM siswa_tahun_ajaran WHERE tahun_ajaran_id=$targetYearId")->fetch_assoc()['total'];
    promotion_assert($placementsAfterRerun === 2, 'Proses ulang menggandakan penempatan target.');

    $date = '2081-08-01 08:00:00';
    $month = '08';
    $year = '2081';
    $classLabel = '2' . $code;
    $amount = 1.0;
    $stmt = $koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,U_SPP,TGL_BYR,BULAN,TAHUN,th_ajaran,total_jumlah,payment_link_version) VALUES(?,?,?,?,?,?,?,?,?,?,1)");
    $targetClassId = (int)$student1['master_kelas_id'];
    $stmt->bind_param('ssisdssssd', $nis1, $student1['KELAS'], $targetClassId, $classLabel, $amount, $date, $month, $year, $target, $amount);
    $stmt->execute();
    $stmt->close();
    $blocked = false;
    try { class_promote_academic_year($koneksi, $source, $target); }
    catch (RuntimeException $error) { $blocked = true; }
    promotion_assert($blocked, 'Promosi tidak ditolak saat target sudah punya pembayaran.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try { $koneksi->rollback(); } catch (Throwable $ignored) {}
}
if ($failure) { fwrite(STDERR, 'FAILED: '.$failure->getMessage().PHP_EOL); exit(1); }
echo "OK: naik kelas otomatis menjaga rombel, meluluskan kelas 6, idempoten, dan menolak target berbayar.\n";
