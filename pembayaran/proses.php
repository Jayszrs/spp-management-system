<?php
// ============================================
// pembayaran/proses.php - Insert / Update / Delete
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';

$aksi = $_POST['aksi'] ?? $_GET['aksi'] ?? '';

function parse_amount($value) {
    if ($value === null || $value === '') return 0.0;
    return (float)str_replace(['.', ','], ['', '.'], (string)$value);
}

function normalize_month_code($value) {
    $map = [
        'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
        'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
        'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12'
    ];
    if (isset($map[$value])) return $map[$value];
    return str_pad((string)$value, 2, '0', STR_PAD_LEFT);
}

// ── INSERT ──────────────────────────────────
if ($aksi === 'input') {
    $no_induk        = trim($_POST['no_induk'] ?? '');
    $tanggal_bayar   = $_POST['tanggal_bayar'] ?? date('Y-m-d H:i:s');
    // Jika tanggal tidak mengandung waktu, tambahkan waktu sekarang agar tipe data DATETIME pas
    if (strlen($tanggal_bayar) === 10) {
        $tanggal_bayar .= ' ' . date('H:i:s');
    }
    $bulan_bayar     = normalize_month_code($_POST['bulan_bayar'] ?? '');
    $tahun_bayar     = $_POST['tahun_bayar'] ?? date('Y');
    
    $uang_pangkal    = parse_amount($_POST['uang_pangkal'] ?? 0);
    $uang_bangunan   = parse_amount($_POST['uang_bangunan'] ?? 0);
    $uang_seragam    = parse_amount($_POST['uang_seragam'] ?? 0);
    $uang_kegiatan   = parse_amount($_POST['uang_kegiatan'] ?? 0);
    $uang_spp        = parse_amount($_POST['uang_spp'] ?? 0);
    $uang_makan      = parse_amount($_POST['uang_makan'] ?? 0);
    $uang_sorga      = parse_amount($_POST['uang_sorga'] ?? 0);
    $uang_infaq      = parse_amount($_POST['uang_infaq'] ?? 0);
    $uang_lain       = parse_amount($_POST['uang_lain'] ?? 0);
    $uang_du         = parse_amount($_POST['uang_du'] ?? 0);
    
    $ll_1_ket        = $_POST['ll_1_ket'] ?? '';
    $ll_1_nom        = parse_amount($_POST['ll_1_nom'] ?? 0);
    $ll_2_ket        = $_POST['ll_2_ket'] ?? '';
    $ll_2_nom        = parse_amount($_POST['ll_2_nom'] ?? 0);
    $ll_3_ket        = $_POST['ll_3_ket'] ?? '';
    $ll_3_nom        = parse_amount($_POST['ll_3_nom'] ?? 0);
    $ll_4_ket        = $_POST['ll_4_ket'] ?? '';
    $ll_4_nom        = parse_amount($_POST['ll_4_nom'] ?? 0);
    
    $potongan_spp    = parse_amount($_POST['potongan_spp'] ?? 0);
    $tabungan_wajib  = parse_amount($_POST['tabungan_wajib'] ?? 0);
    $total_jumlah    = parse_amount($_POST['total_jumlah'] ?? 0);
    $catatan         = $_POST['catatan'] ?? '';
    $kelas_du        = $_POST['kelas_du'] ?? '';
    $tahun_ajaran_du = $_POST['tahun_ajaran_du'] ?? '';

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: form.php');
        exit;
    }

    // Ambil kelas siswa saat ini dari tabel siswa
    $stmt_siswa = $koneksi->prepare("SELECT KELAS FROM siswa WHERE NO_INDUK = ?");
    $stmt_siswa->bind_param('s', $no_induk);
    $stmt_siswa->execute();
    $siswa_data = $stmt_siswa->get_result()->fetch_assoc();
    $kelas_siswa = $siswa_data ? $siswa_data['KELAS'] : '';
    $stmt_siswa->close();

    $koneksi->begin_transaction();

    try {
        // 1. Simpan transaksi utama ke tabel bayar
        $sql = "INSERT INTO bayar (
            NO_INDUK, KELAS, U_PANGKAL, U_BANGUNAN, U_SERAGAM, U_KEGIATAN,
            U_SPP, U_MAKAN, U_SORGA, U_INFAQ, U_LAIN, KETERANGAN,
            TGL_BYR, BULAN, TAHUN, user_id,
            LAIN_LAIN1, JUMLAH1, LAIN_LAIN2, JUMLAH2, LAIN_LAIN3, JUMLAH3, LAIN_LAIN4, JUMLAH4,
            th_ajaran, kelas_du, potong_spp, total_jumlah
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $koneksi->prepare($sql);
        $user_id = $_SESSION['admin_nama'] ?? 'admin';
        
        $stmt->bind_param(
            'ssdddddddddssssssdsdsdsdssdd',
            $no_induk, $kelas_siswa, $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_makan, $uang_sorga, $uang_infaq, $uang_lain, $catatan,
            $tanggal_bayar, $bulan_bayar, $tahun_bayar, $user_id,
            $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
            $tahun_ajaran_du, $kelas_du, $potongan_spp, $total_jumlah
        );
        $stmt->execute();
        $stmt->close();

        // 2. Simpan daftar ulang ke tabel bayar_du jika ada nominal
        if ($uang_du > 0) {
            $stmt_du = $koneksi->prepare("INSERT INTO bayar_du (no_induk, kelas, th_ajaran, jumlah) VALUES (?, ?, ?, ?)");
            $stmt_du->bind_param('sssd', $no_induk, $kelas_du, $tahun_ajaran_du, $uang_du);
            $stmt_du->execute();
            $stmt_du->close();
        }

        // 3. Simpan tabungan ke tabel tabungan & log transaksi_m jika ada nominal
        if ($tabungan_wajib > 0) {
            // Cek data tabungan siswa
            $stmt_check = $koneksi->prepare("SELECT SALDO FROM tabungan WHERE NO_INDUK = ?");
            $stmt_check->bind_param('s', $no_induk);
            $stmt_check->execute();
            $tab_data = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($tab_data) {
                $stmt_up = $koneksi->prepare("UPDATE tabungan SET SALDO = SALDO + ? WHERE NO_INDUK = ?");
                $stmt_up->bind_param('ds', $tabungan_wajib, $no_induk);
                $stmt_up->execute();
                $stmt_up->close();
            } else {
                $stmt_ins = $koneksi->prepare("INSERT INTO tabungan (NO_INDUK, SALDO) VALUES (?, ?)");
                $stmt_ins->bind_param('sd', $no_induk, $tabungan_wajib);
                $stmt_ins->execute();
                $stmt_ins->close();
            }

            // Log transaksi masuk tabungan
            $stmt_trans = $koneksi->prepare("INSERT INTO transaksi_m (NO_INDUK, TANGGAL, MASUK, KELUAR, user_id) VALUES (?, ?, ?, 0, ?)");
            $stmt_trans->bind_param('ssds', $no_induk, $tanggal_bayar, $tabungan_wajib, $user_id);
            $stmt_trans->execute();
            $stmt_trans->close();
        }

        $koneksi->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Data pembayaran berhasil disimpan!'];
        header('Location: lihat.php');
        exit;
    } catch (Exception $e) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal menyimpan: ' . $e->getMessage()];
        header('Location: form.php');
        exit;
    }
}

// ── UPDATE ──────────────────────────────────
if ($aksi === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { header('Location: lihat.php'); exit; }

    $no_induk        = trim($_POST['no_induk'] ?? '');
    $tanggal_bayar   = $_POST['tanggal_bayar'] ?? date('Y-m-d H:i:s');
    if (strlen($tanggal_bayar) === 10) {
        $tanggal_bayar .= ' ' . date('H:i:s');
    }
    $bulan_bayar     = normalize_month_code($_POST['bulan_bayar'] ?? '');
    $tahun_bayar     = $_POST['tahun_bayar'] ?? date('Y');
    
    $uang_pangkal    = parse_amount($_POST['uang_pangkal'] ?? 0);
    $uang_bangunan   = parse_amount($_POST['uang_bangunan'] ?? 0);
    $uang_seragam    = parse_amount($_POST['uang_seragam'] ?? 0);
    $uang_kegiatan   = parse_amount($_POST['uang_kegiatan'] ?? 0);
    $uang_spp        = parse_amount($_POST['uang_spp'] ?? 0);
    $uang_makan      = parse_amount($_POST['uang_makan'] ?? 0);
    $uang_sorga      = parse_amount($_POST['uang_sorga'] ?? 0);
    $uang_infaq      = parse_amount($_POST['uang_infaq'] ?? 0);
    $uang_lain       = parse_amount($_POST['uang_lain'] ?? 0);
    $uang_du         = parse_amount($_POST['uang_du'] ?? 0);
    
    $ll_1_ket        = $_POST['ll_1_ket'] ?? '';
    $ll_1_nom        = parse_amount($_POST['ll_1_nom'] ?? 0);
    $ll_2_ket        = $_POST['ll_2_ket'] ?? '';
    $ll_2_nom        = parse_amount($_POST['ll_2_nom'] ?? 0);
    $ll_3_ket        = $_POST['ll_3_ket'] ?? '';
    $ll_3_nom        = parse_amount($_POST['ll_3_nom'] ?? 0);
    $ll_4_ket        = $_POST['ll_4_ket'] ?? '';
    $ll_4_nom        = parse_amount($_POST['ll_4_nom'] ?? 0);
    
    $potongan_spp    = parse_amount($_POST['potongan_spp'] ?? 0);
    $tabungan_wajib  = parse_amount($_POST['tabungan_wajib'] ?? 0);
    $total_jumlah    = parse_amount($_POST['total_jumlah'] ?? 0);
    $catatan         = $_POST['catatan'] ?? '';
    $kelas_du        = $_POST['kelas_du'] ?? '';
    $tahun_ajaran_du = $_POST['tahun_ajaran_du'] ?? '';

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: edit.php?id=' . $id);
        exit;
    }

    // Ambil data pembayaran lama untuk penyesuaian saldo tabungan & daftar ulang
    $stmt_old = $koneksi->prepare("SELECT * FROM bayar WHERE id = ?");
    $stmt_old->bind_param('i', $id);
    $stmt_old->execute();
    $old_bayar = $stmt_old->get_result()->fetch_assoc();
    $stmt_old->close();

    if (!$old_bayar) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Data lama tidak ditemukan!'];
        header('Location: lihat.php');
        exit;
    }

    // Ambil nilai tabungan lama dari transaksi_m
    $stmt_old_tab = $koneksi->prepare("SELECT MASUK FROM transaksi_m WHERE NO_INDUK = ? AND DATE(TANGGAL) = DATE(?) LIMIT 1");
    $stmt_old_tab->bind_param('ss', $old_bayar['NO_INDUK'], $old_bayar['TGL_BYR']);
    $stmt_old_tab->execute();
    $old_tab_data = $stmt_old_tab->get_result()->fetch_assoc();
    $old_tab_val = $old_tab_data ? (float)$old_tab_data['MASUK'] : 0.0;
    $stmt_old_tab->close();

    // Ambil kelas siswa saat ini dari tabel siswa
    $stmt_siswa = $koneksi->prepare("SELECT KELAS FROM siswa WHERE NO_INDUK = ?");
    $stmt_siswa->bind_param('s', $no_induk);
    $stmt_siswa->execute();
    $siswa_data = $stmt_siswa->get_result()->fetch_assoc();
    $kelas_siswa = $siswa_data ? $siswa_data['KELAS'] : '';
    $stmt_siswa->close();

    $koneksi->begin_transaction();

    try {
        // 1. Update data utama ke tabel bayar
        $sql = "UPDATE bayar SET
            NO_INDUK=?, KELAS=?, U_PANGKAL=?, U_BANGUNAN=?, U_SERAGAM=?, U_KEGIATAN=?,
            U_SPP=?, U_MAKAN=?, U_SORGA=?, U_INFAQ=?, U_LAIN=?, KETERANGAN=?,
            TGL_BYR=?, BULAN=?, TAHUN=?, user_id=?,
            LAIN_LAIN1=?, JUMLAH1=?, LAIN_LAIN2=?, JUMLAH2=?, LAIN_LAIN3=?, JUMLAH3=?, LAIN_LAIN4=?, JUMLAH4=?,
            th_ajaran=?, kelas_du=?, potong_spp=?, total_jumlah=?
            WHERE id=?";

        $stmt = $koneksi->prepare($sql);
        $user_id = $_SESSION['admin_nama'] ?? 'admin';
        
        $stmt->bind_param(
            'ssdddddddddssssssdsdsdsdssddi',
            $no_induk, $kelas_siswa, $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_makan, $uang_sorga, $uang_infaq, $uang_lain, $catatan,
            $tanggal_bayar, $bulan_bayar, $tahun_bayar, $user_id,
            $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
            $tahun_ajaran_du, $kelas_du, $potongan_spp, $total_jumlah, $id
        );
        $stmt->execute();
        $stmt->close();

        // 2. Sesuaikan daftar ulang (Hapus record lama, lalu masukkan yang baru jika ada nominal)
        $stmt_del_du = $koneksi->prepare("DELETE FROM bayar_du WHERE no_induk = ? AND th_ajaran = ?");
        $stmt_del_du->bind_param('ss', $old_bayar['NO_INDUK'], $old_bayar['th_ajaran']);
        $stmt_del_du->execute();
        $stmt_del_du->close();

        if ($uang_du > 0) {
            $stmt_ins_du = $koneksi->prepare("INSERT INTO bayar_du (no_induk, kelas, th_ajaran, jumlah) VALUES (?, ?, ?, ?)");
            $stmt_ins_du->bind_param('sssd', $no_induk, $kelas_du, $tahun_ajaran_du, $uang_du);
            $stmt_ins_du->execute();
            $stmt_ins_du->close();
        }

        // 3. Sesuaikan tabungan
        // Kurangi saldo tabungan lama
        $stmt_sub_tab = $koneksi->prepare("UPDATE tabungan SET SALDO = SALDO - ? WHERE NO_INDUK = ?");
        $stmt_sub_tab->bind_param('ds', $old_tab_val, $old_bayar['NO_INDUK']);
        $stmt_sub_tab->execute();
        $stmt_sub_tab->close();

        // Hapus log transaksi tabungan lama
        $stmt_del_log = $koneksi->prepare("DELETE FROM transaksi_m WHERE NO_INDUK = ? AND DATE(TANGGAL) = DATE(?)");
        $stmt_del_log->bind_param('ss', $old_bayar['NO_INDUK'], $old_bayar['TGL_BYR']);
        $stmt_del_log->execute();
        $stmt_del_log->close();

        // Terapkan tabungan baru jika ada nominal
        if ($tabungan_wajib > 0) {
            $stmt_check = $koneksi->prepare("SELECT SALDO FROM tabungan WHERE NO_INDUK = ?");
            $stmt_check->bind_param('s', $no_induk);
            $stmt_check->execute();
            $tab_data = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($tab_data) {
                $stmt_up = $koneksi->prepare("UPDATE tabungan SET SALDO = SALDO + ? WHERE NO_INDUK = ?");
                $stmt_up->bind_param('ds', $tabungan_wajib, $no_induk);
                $stmt_up->execute();
                $stmt_up->close();
            } else {
                $stmt_ins = $koneksi->prepare("INSERT INTO tabungan (NO_INDUK, SALDO) VALUES (?, ?)");
                $stmt_ins->bind_param('sd', $no_induk, $tabungan_wajib);
                $stmt_ins->execute();
                $stmt_ins->close();
            }

            // Log transaksi masuk tabungan yang baru
            $stmt_trans = $koneksi->prepare("INSERT INTO transaksi_m (NO_INDUK, TANGGAL, MASUK, KELUAR, user_id) VALUES (?, ?, ?, 0, ?)");
            $stmt_trans->bind_param('ssds', $no_induk, $tanggal_bayar, $tabungan_wajib, $user_id);
            $stmt_trans->execute();
            $stmt_trans->close();
        }

        $koneksi->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Data pembayaran berhasil diperbarui!'];
        header('Location: lihat.php');
        exit;
    } catch (Exception $e) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal memperbarui: ' . $e->getMessage()];
        header('Location: edit.php?id=' . $id);
        exit;
    }
}

// ── DELETE ──────────────────────────────────
if ($aksi === 'hapus') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { header('Location: lihat.php'); exit; }

    // Ambil data pembayaran untuk membatalkan tabungan dan daftar ulang
    $stmt_old = $koneksi->prepare("SELECT * FROM bayar WHERE id = ?");
    $stmt_old->bind_param('i', $id);
    $stmt_old->execute();
    $old_bayar = $stmt_old->get_result()->fetch_assoc();
    $stmt_old->close();

    if (!$old_bayar) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Data tidak ditemukan!'];
        header('Location: lihat.php');
        exit;
    }

    // Ambil nilai tabungan lama dari transaksi_m
    $stmt_old_tab = $koneksi->prepare("SELECT MASUK FROM transaksi_m WHERE NO_INDUK = ? AND DATE(TANGGAL) = DATE(?) LIMIT 1");
    $stmt_old_tab->bind_param('ss', $old_bayar['NO_INDUK'], $old_bayar['TGL_BYR']);
    $stmt_old_tab->execute();
    $old_tab_data = $stmt_old_tab->get_result()->fetch_assoc();
    $old_tab_val = $old_tab_data ? (float)$old_tab_data['MASUK'] : 0.0;
    $stmt_old_tab->close();

    $koneksi->begin_transaction();

    try {
        // 1. Kurangi saldo tabungan
        $stmt_sub_tab = $koneksi->prepare("UPDATE tabungan SET SALDO = SALDO - ? WHERE NO_INDUK = ?");
        $stmt_sub_tab->bind_param('ds', $old_tab_val, $old_bayar['NO_INDUK']);
        $stmt_sub_tab->execute();
        $stmt_sub_tab->close();

        // 2. Hapus log transaksi tabungan
        $stmt_del_log = $koneksi->prepare("DELETE FROM transaksi_m WHERE NO_INDUK = ? AND DATE(TANGGAL) = DATE(?)");
        $stmt_del_log->bind_param('ss', $old_bayar['NO_INDUK'], $old_bayar['TGL_BYR']);
        $stmt_del_log->execute();
        $stmt_del_log->close();

        // 3. Hapus data daftar ulang
        $stmt_del_du = $koneksi->prepare("DELETE FROM bayar_du WHERE no_induk = ? AND th_ajaran = ?");
        $stmt_del_du->bind_param('ss', $old_bayar['NO_INDUK'], $old_bayar['th_ajaran']);
        $stmt_del_du->execute();
        $stmt_del_du->close();

        // 4. Hapus data utama di bayar
        $stmt_del = $koneksi->prepare("DELETE FROM bayar WHERE id = ?");
        $stmt_del->bind_param('i', $id);
        $stmt_del->execute();
        $stmt_del->close();

        $koneksi->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Data pembayaran berhasil dihapus!'];
    } catch (Exception $e) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Gagal menghapus data: ' . $e->getMessage()];
    }

    header('Location: lihat.php');
    exit;
}

header('Location: lihat.php');
exit;
?>
