<?php
/** An active student cannot have unpublished future SPP debt cancelled as "left school". */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
session_start();
require_once __DIR__ . '/../koneksi.php';
if (getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/', DB_NAME)) {
    throw new RuntimeException('Tes pembatalan SPP hanya untuk clone audit dengan flag mutasi.');
}

$closed = in_array('--closed', $argv, true);
$crossUnit = in_array('--cross-unit', $argv, true);
$archived = $closed || $crossUnit || in_array('--archived', $argv, true);
$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'admin';
$_SESSION['active_unit_id'] = 1;
unit_set_context($koneksi, 1);

$crossFixture = null;
if ($crossUnit) {
    $fixtureNis = (string)random_int(9900000000, 9999999999);
    unit_set_context($koneksi, 2);
    $year = $koneksi->query("SELECT ta.id year_id,m.id master_id FROM tahun_ajaran ta
        JOIN master_spp_tahun m ON m.tahun_ajaran_id=ta.id
        WHERE ta.label='2026/2027' AND m.status='published'")->fetch_assoc();
    if (!$year) throw new RuntimeException('Master SPP fixture tidak tersedia.');
    $yearId = (int)$year['year_id'];
    $fixtureMasterId = (int)$year['master_id'];
    $koneksi->begin_transaction();
    try {
        $stmt = $koneksi->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active,unit_id)
            VALUES(?,'UJI BATAL SPP UNIT SMP','7',0,2)");
        $stmt->bind_param('s', $fixtureNis); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,kelas_rombel_snapshot,unit_id)
            VALUES(?,?,'7','7A',2)");
        $stmt->bind_param('is', $yearId, $fixtureNis); $stmt->execute();
        $placementId = (int)$koneksi->insert_id; $stmt->close();
        $stmt = $koneksi->prepare("INSERT INTO tagihan_spp(master_spp_tahun_id,tahun_ajaran_id,penempatan_id,no_induk,
            tingkat_snapshot,kelas_rombel_snapshot,bulan,tahun,tarif_dasar_snapshot,nominal_tagihan,status,unit_id)
            VALUES(?,?,?, ?,7,'7A','06','2027',250000,250000,'open',2)");
        $stmt->bind_param('iiis', $fixtureMasterId, $yearId, $placementId, $fixtureNis);
        $stmt->execute(); $fixtureBillId = (int)$koneksi->insert_id; $stmt->close();
        $koneksi->commit();
    } catch (Throwable $error) {
        $koneksi->rollback();
        throw $error;
    }
    // The session still selects SD; the differing DB context tests the explicit level guard.
    $crossFixture = ['bill_id'=>$fixtureBillId, 'placement_id'=>$placementId, 'nis'=>$fixtureNis];
    $candidate = ['bill_id'=>$fixtureBillId, 'no_induk'=>$fixtureNis, 'master_id'=>$fixtureMasterId];
} else {
    $candidate = $koneksi->query("SELECT ts.id bill_id,ts.no_induk,m.id master_id
        FROM tagihan_spp ts JOIN master_spp_tahun m ON m.id=ts.master_spp_tahun_id
        JOIN tahun_ajaran y ON y.id=m.tahun_ajaran_id
        JOIN siswa_tahun_ajaran sta ON sta.id=ts.penempatan_id AND sta.tahun_ajaran_id=y.id
        JOIN siswa s ON s.NO_INDUK=ts.no_induk
        WHERE y.label='2026/2027' AND m.status='published' AND ts.status='open'
          AND ts.bulan='06' AND ts.tahun='2027' AND s.is_active=1
          AND CAST(sta.kelas AS UNSIGNED) BETWEEN 1 AND 6
          AND NOT EXISTS(SELECT 1 FROM spp_alokasi a
            JOIN spp_alokasi_batch ab ON ab.id=a.batch_id AND ab.status='active'
            WHERE a.tagihan_spp_id=ts.id)
        ORDER BY ts.id LIMIT 1")->fetch_assoc();
}
if (!$candidate) throw new RuntimeException('Tagihan SPP aktif tanpa alokasi untuk tes tidak ditemukan.');
$billId = (int)$candidate['bill_id'];
$masterId = (int)$candidate['master_id'];
$nis = (string)$candidate['no_induk'];
$lastAuditId = (int)$koneksi->query('SELECT COALESCE(MAX(id),0) FROM spp_audit_log')->fetch_row()[0];
if ($archived && !$crossUnit) {
    $stmt = $koneksi->prepare('UPDATE siswa SET is_active=0 WHERE NO_INDUK=? AND is_active=1');
    $stmt->bind_param('s', $nis); $stmt->execute();
    if ($stmt->affected_rows !== 1) throw new RuntimeException('Fixture siswa tidak dapat diarsipkan.');
    $stmt->close();
}
if ($closed) {
    $stmt = $koneksi->prepare("UPDATE master_spp_tahun SET status='closed' WHERE id=? AND status='published'");
    $stmt->bind_param('i', $masterId); $stmt->execute();
    if ($stmt->affected_rows !== 1) throw new RuntimeException('Fixture Master SPP tidak dapat ditutup.');
    $stmt->close();
}

$token = bin2hex(random_bytes(32));
$_SESSION['csrf_master_spp'] = $token;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = '/master_spp.php';
$_GET = ['tahun'=>'2026/2027'];
$_POST = ['tahun_ajaran'=>'2026/2027', 'aksi'=>'batalkan_mulai_bulan',
    'selected_students'=>[$nis], 'cancel_start_month'=>'06',
    'cancel_reason'=>'Uji keluar sekolah pada clone', 'csrf_token'=>$token];

register_shutdown_function(static function () use ($koneksi, $archived, $closed, $crossUnit, $crossFixture, $billId, $masterId, $nis, $lastAuditId): void {
    while (ob_get_level() > 0) ob_end_clean();
    try {
        $stmt = $koneksi->prepare('SELECT status FROM tagihan_spp WHERE id=?');
        $stmt->bind_param('i', $billId); $stmt->execute();
        $status = (string)($stmt->get_result()->fetch_row()[0] ?? ''); $stmt->close();
        $stmt = $koneksi->prepare("SELECT COUNT(*) FROM spp_audit_log WHERE id>? AND master_spp_tahun_id=? AND no_induk=? AND aksi='batalkan_mulai_bulan'");
        $stmt->bind_param('iis', $lastAuditId, $masterId, $nis); $stmt->execute();
        $auditCount = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
        $flashType = (string)($_SESSION['flash']['type'] ?? '');
        $valid = ($closed || $crossUnit)
            ? $status === 'open' && $auditCount === 0 && $flashType === 'error'
            : ($archived
            ? $status === 'cancelled' && $auditCount === 1 && $flashType === 'success'
            : $status === 'open' && $auditCount === 0 && $flashType === 'error');

        $stmt = $koneksi->prepare('UPDATE tagihan_spp SET status=\'open\',cancel_reason=NULL WHERE id=?');
        $stmt->bind_param('i', $billId); $stmt->execute(); $stmt->close();
        $stmt = $koneksi->prepare("DELETE FROM spp_audit_log WHERE id>? AND master_spp_tahun_id=? AND no_induk=? AND aksi='batalkan_mulai_bulan'");
        $stmt->bind_param('iis', $lastAuditId, $masterId, $nis); $stmt->execute(); $stmt->close();
        if ($archived && !$crossUnit) {
            $stmt = $koneksi->prepare('UPDATE siswa SET is_active=1 WHERE NO_INDUK=?');
            $stmt->bind_param('s', $nis); $stmt->execute(); $stmt->close();
        }
        if ($closed) {
            $stmt = $koneksi->prepare("UPDATE master_spp_tahun SET status='published' WHERE id=?");
            $stmt->bind_param('i', $masterId); $stmt->execute(); $stmt->close();
        }
        if ($crossFixture !== null) {
            $stmt = $koneksi->prepare('DELETE FROM tagihan_spp WHERE id=?');
            $stmt->bind_param('i', $crossFixture['bill_id']); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare('DELETE FROM siswa_tahun_ajaran WHERE id=?');
            $stmt->bind_param('i', $crossFixture['placement_id']); $stmt->execute(); $stmt->close();
            $stmt = $koneksi->prepare('DELETE FROM siswa WHERE NO_INDUK=?');
            $stmt->bind_param('s', $crossFixture['nis']); $stmt->execute(); $stmt->close();
        }
        if (!$valid) {
            fwrite(STDERR, 'FAILED: status tagihan atau audit pembatalan siswa aktif/arsip salah.' . PHP_EOL);
            exit(1);
        }
        echo $closed
            ? "PASS: tahun SPP tertutup menolak pembatalan tagihan.\n"
            : ($crossUnit
            ? "PASS: unit SD menolak pembatalan tagihan siswa SMP.\n"
            : ($archived
            ? "PASS: siswa yang diarsipkan dapat membatalkan tagihan SPP belum dibayar.\n"
            : "PASS: siswa aktif tidak dapat membatalkan tagihan SPP.\n"));
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAILED: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
});

ob_start();
include __DIR__ . '/../master_spp.php';
