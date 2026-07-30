<?php
// ============================================
// pembayaran/proses.php - Insert / Update / Delete
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
requireRole(['admin']);

$aksi = $_POST['aksi'] ?? $_GET['aksi'] ?? '';

function parse_amount($value) {
    if ($value === null || $value === '') return 0.0;
    $normalized = str_replace(['.', ','], ['', '.'], trim((string)$value));
    return is_numeric($normalized) ? (float)$normalized : NAN;
}

function validate_payment_amounts(array $amounts): void {
    foreach ($amounts as $label => $amount) {
        if (!is_finite($amount) || $amount < 0) {
            throw new RuntimeException("Nominal $label harus berupa angka positif atau nol.");
        }
    }
}

function validate_payment_context(string $tanggal, string $bulan, string $tahun, float $uangDu, string $kelasDu, string $tahunAjaran): void {
    if (!in_array($bulan, ['01','02','03','04','05','06','07','08','09','10','11','12'], true)) {
        throw new RuntimeException('Bulan pembayaran tidak valid.');
    }
    if (!preg_match('/^\d{4}$/', $tahun)) throw new RuntimeException('Tahun pembayaran tidak valid.');
    $datePart = substr($tanggal, 0, 10);
    $parsedDate = DateTime::createFromFormat('!Y-m-d', $datePart);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $datePart) {
        throw new RuntimeException('Tanggal pembayaran tidak valid.');
    }
    if ($uangDu > 0 && !in_array($kelasDu, ['1','2','3','4','5','6'], true)) {
        throw new RuntimeException('Kelas daftar ulang harus dipilih dari kelas 1 sampai 6.');
    }
    if ($uangDu > 0 && !preg_match('/^\d{4}\/\d{4}$/', $tahunAjaran)) {
        throw new RuntimeException('Tahun ajaran daftar ulang tidak valid.');
    }
}

function validate_student_and_komite(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    float $uangKomite,
    int $excludePaymentId = 0,
    ?string $archivedStudentAllowed = null
): array {
    $stmt = $db->prepare('SELECT KELAS, POMG, is_active FROM siswa WHERE NO_INDUK = ? FOR UPDATE');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');
    if ((int)$student['is_active'] !== 1 && $noInduk !== $archivedStudentAllowed) {
        throw new RuntimeException('Siswa yang diarsipkan tidak dapat dipakai untuk transaksi baru.');
    }

    $stmt = $db->prepare('SELECT U_KOMITE FROM bayar WHERE NO_INDUK = ? AND BULAN = ? AND TAHUN = ? AND id <> ? FOR UPDATE');
    $stmt->bind_param('sssi', $noInduk, $bulan, $tahun, $excludePaymentId);
    $stmt->execute();
    $paid = 0.0;
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $paid += (float)$row['U_KOMITE'];
    $stmt->close();

    $remaining = max(0, (float)$student['POMG'] - $paid);
    if ($uangKomite > $remaining + 0.001) {
        throw new RuntimeException('Pembayaran Uang Komite melebihi sisa periode. Sisa: Rp ' . number_format($remaining, 0, ',', '.'));
    }
    return $student;
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

function collect_biaya_lain(mysqli $koneksi, int $bayarId = 0): array {
    $detailIds = $_POST['biaya_lain_detail_id'] ?? [];
    $masterIds = $_POST['biaya_lain_master_id'] ?? [];
    $notes = $_POST['biaya_lain_keterangan'] ?? [];
    if (!is_array($detailIds) || !is_array($masterIds) || !is_array($notes)) {
        throw new RuntimeException('Format biaya lain tidak valid.');
    }

    $existing = [];
    if ($bayarId > 0) {
        $stmt = $koneksi->prepare('SELECT * FROM bayar_biaya_lain WHERE bayar_id = ?');
        $stmt->bind_param('i', $bayarId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $existing[(int)$row['id']] = $row;
        $stmt->close();
    }

    $lines = [];
    $rowCount = max(count($detailIds), count($masterIds), count($notes));
    for ($index = 0; $index < $rowCount; $index++) {
        $detailId = (int)($detailIds[$index] ?? 0);
        $masterId = (int)($masterIds[$index] ?? 0);
        $note = trim((string)($notes[$index] ?? ''));
        if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

        $oldLine = $detailId > 0 && isset($existing[$detailId]) ? $existing[$detailId] : null;
        if ($masterId <= 0) {
            // Detail hasil migrasi tidak memiliki master, tetapi snapshot-nya tetap sah.
            if ($oldLine && $oldLine['master_biaya_lain_id'] === null) {
                $lines[] = [
                    'master_id' => null,
                    'nama' => $oldLine['nama_biaya_snapshot'],
                    'nominal' => (float)$oldLine['nominal_snapshot'],
                    'keterangan' => $note,
                ];
            }
            continue;
        }

        if ($oldLine && (int)$oldLine['master_biaya_lain_id'] === $masterId) {
            $lines[] = [
                'master_id' => $masterId,
                'nama' => $oldLine['nama_biaya_snapshot'],
                'nominal' => (float)$oldLine['nominal_snapshot'],
                'keterangan' => $note,
            ];
            continue;
        }

        $stmt = $koneksi->prepare('SELECT id, nama, nominal FROM master_biaya_lain WHERE id = ? AND is_active = 1 AND nominal > 0 LIMIT 1');
        $stmt->bind_param('i', $masterId);
        $stmt->execute();
        $master = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$master) throw new RuntimeException('Pilihan master biaya lain tidak tersedia atau sudah nonaktif.');

        $lines[] = [
            'master_id' => (int)$master['id'],
            'nama' => $master['nama'],
            'nominal' => (float)$master['nominal'],
            'keterangan' => $note,
        ];
    }
    return $lines;
}

function save_biaya_lain(mysqli $koneksi, int $bayarId, array $lines): void {
    $stmtDelete = $koneksi->prepare('DELETE FROM bayar_biaya_lain WHERE bayar_id = ?');
    $stmtDelete->bind_param('i', $bayarId);
    $stmtDelete->execute();
    $stmtDelete->close();

    if (!$lines) return;
    $stmt = $koneksi->prepare("
        INSERT INTO bayar_biaya_lain
            (bayar_id, master_biaya_lain_id, nama_biaya_snapshot, nominal_snapshot, keterangan, urutan)
        VALUES (?, ?, ?, ?, NULLIF(?, ''), ?)
    ");
    foreach ($lines as $index => $line) {
        $masterId = $line['master_id'];
        $nama = $line['nama'];
        $nominal = $line['nominal'];
        $keterangan = $line['keterangan'];
        $urutan = $index + 1;
        $stmt->bind_param('iisdsi', $bayarId, $masterId, $nama, $nominal, $keterangan, $urutan);
        $stmt->execute();
    }
    $stmt->close();
}

function calculate_payment_total(array $components, float $uangDu, float $potonganSpp, array $biayaLain): float {
    $total = array_sum($components) + $uangDu;
    foreach ($biayaLain as $line) $total += (float)$line['nominal'];
    return max(0, $total - $potonganSpp);
}

/**
 * Pastikan pembayaran bukan histori legacy dan kunci header sebelum dimutasi.
 */
function find_linked_payment(mysqli $db, int $bayarId): array {
    $stmt = $db->prepare('SELECT * FROM bayar WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payment) throw new RuntimeException('Data pembayaran tidak ditemukan.');
    if ((int)($payment['payment_link_version'] ?? 0) !== 1) {
        throw new RuntimeException('Pembayaran legacy tidak dapat diubah atau dihapus. Rekonsiliasi manual diperlukan terlebih dahulu.');
    }
    return $payment;
}

/**
 * Membatalkan setoran tabungan wajib yang benar-benar terhubung ke pembayaran.
 * Mutasi dibatalkan bila saldo saat ini tidak cukup untuk dibalikkan.
 */
function reverse_linked_savings(mysqli $db, int $bayarId): void {
    $stmt = $db->prepare('SELECT id, NO_INDUK, MASUK FROM transaksi_m WHERE bayar_id = ? FOR UPDATE');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $saving = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$saving) return;

    $amount = (float)$saving['MASUK'];
    if ($amount < 0) throw new RuntimeException('Jurnal tabungan pembayaran tidak valid.');

    $stmtSaldo = $db->prepare('SELECT SALDO FROM tabungan WHERE NO_INDUK = ? FOR UPDATE');
    $stmtSaldo->bind_param('s', $saving['NO_INDUK']);
    $stmtSaldo->execute();
    $saldoRow = $stmtSaldo->get_result()->fetch_assoc();
    $stmtSaldo->close();
    if (!$saldoRow) throw new RuntimeException('Saldo tabungan terkait pembayaran tidak ditemukan.');
    if ((float)$saldoRow['SALDO'] + 0.001 < $amount) {
        throw new RuntimeException('Pembayaran tidak dapat diubah atau dihapus karena tabungan wajibnya sudah dipakai. Selesaikan rekonsiliasi tabungan terlebih dahulu.');
    }

    $stmtUpdate = $db->prepare('UPDATE tabungan SET SALDO = SALDO - ? WHERE NO_INDUK = ?');
    $stmtUpdate->bind_param('ds', $amount, $saving['NO_INDUK']);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    $stmtDelete = $db->prepare('DELETE FROM transaksi_m WHERE bayar_id = ?');
    $stmtDelete->bind_param('i', $bayarId);
    $stmtDelete->execute();
    $stmtDelete->close();
}

/**
 * Menambah saldo dan jurnal setoran tabungan wajib untuk satu pembayaran.
 */
function save_linked_savings(mysqli $db, int $bayarId, string $noInduk, string $tanggal, float $amount, string $userId): void {
    if ($amount <= 0) return;

    $stmtCheck = $db->prepare('SELECT SALDO FROM tabungan WHERE NO_INDUK = ? FOR UPDATE');
    $stmtCheck->bind_param('s', $noInduk);
    $stmtCheck->execute();
    $tabungan = $stmtCheck->get_result()->fetch_assoc();
    $stmtCheck->close();

    if ($tabungan) {
        $stmtUpdate = $db->prepare('UPDATE tabungan SET SALDO = SALDO + ? WHERE NO_INDUK = ?');
        $stmtUpdate->bind_param('ds', $amount, $noInduk);
        $stmtUpdate->execute();
        $stmtUpdate->close();
    } else {
        $stmtInsert = $db->prepare('INSERT INTO tabungan (NO_INDUK, SALDO) VALUES (?, ?)');
        $stmtInsert->bind_param('sd', $noInduk, $amount);
        $stmtInsert->execute();
        $stmtInsert->close();
    }

    $stmtJournal = $db->prepare('INSERT INTO transaksi_m (bayar_id, NO_INDUK, TANGGAL, MASUK, KELUAR, user_id) VALUES (?, ?, ?, ?, 0, ?)');
    $stmtJournal->bind_param('issds', $bayarId, $noInduk, $tanggal, $amount, $userId);
    $stmtJournal->execute();
    $stmtJournal->close();
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
    $uang_komite     = parse_amount($_POST['uang_komite'] ?? 0);
    $uang_makan      = parse_amount($_POST['uang_makan'] ?? 0);
    $uang_sorga      = parse_amount($_POST['uang_sorga'] ?? 0);
    $uang_infaq      = parse_amount($_POST['uang_infaq'] ?? 0);
    $uang_lain       = 0.0;
    $uang_du         = parse_amount($_POST['uang_du'] ?? 0);
    $ll_1_ket = $ll_2_ket = $ll_3_ket = $ll_4_ket = '';
    $ll_1_nom = $ll_2_nom = $ll_3_nom = $ll_4_nom = 0.0;
    
    $potongan_spp    = parse_amount($_POST['potongan_spp'] ?? 0);
    $tabungan_wajib  = parse_amount($_POST['tabungan_wajib'] ?? 0);
    $total_jumlah    = 0.0;
    $catatan         = $_POST['catatan'] ?? '';
    $kelas_du        = $_POST['kelas_du'] ?? '';
    $tahun_ajaran_du = $_POST['tahun_ajaran_du'] ?? '';

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: form.php');
        exit;
    }

    $koneksi->begin_transaction();

    try {
        validate_payment_amounts([
            'Pangkal' => $uang_pangkal, 'Bangunan' => $uang_bangunan,
            'Seragam' => $uang_seragam, 'Kegiatan' => $uang_kegiatan,
            'SPP' => $uang_spp, 'Komite' => $uang_komite, 'Makan' => $uang_makan,
            'Sorga' => $uang_sorga, 'Infaq' => $uang_infaq, 'Daftar Ulang' => $uang_du,
            'Potongan SPP' => $potongan_spp, 'Tabungan Wajib' => $tabungan_wajib
        ]);
        validate_payment_context($tanggal_bayar, $bulan_bayar, (string)$tahun_bayar, $uang_du, $kelas_du, $tahun_ajaran_du);
        if ($potongan_spp > $uang_spp) throw new RuntimeException('Potongan SPP tidak boleh melebihi pembayaran SPP.');
        $siswa_data = validate_student_and_komite($koneksi, $no_induk, $bulan_bayar, $tahun_bayar, $uang_komite);
        $kelas_siswa = $siswa_data['KELAS'];
        $biaya_lain = collect_biaya_lain($koneksi);
        $total_jumlah = calculate_payment_total([
            $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_komite, $uang_makan, $uang_sorga, $uang_infaq
        ], $uang_du, $potongan_spp, $biaya_lain);

        // 1. Simpan transaksi utama ke tabel bayar
        $sql = "INSERT INTO bayar (
            NO_INDUK, KELAS, U_PANGKAL, U_BANGUNAN, U_SERAGAM, U_KEGIATAN,
            U_SPP, U_MAKAN, U_SORGA, U_INFAQ, U_KOMITE, U_LAIN, KETERANGAN,
            TGL_BYR, BULAN, TAHUN, user_id,
            LAIN_LAIN1, JUMLAH1, LAIN_LAIN2, JUMLAH2, LAIN_LAIN3, JUMLAH3, LAIN_LAIN4, JUMLAH4,
            th_ajaran, kelas_du, potong_spp, total_jumlah, payment_link_version
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

        $stmt = $koneksi->prepare($sql);
        $user_id = $_SESSION['admin_nama'] ?? 'admin';
        
        $stmt->bind_param(
            'ssddddddddddssssssdsdsdsdssdd',
            $no_induk, $kelas_siswa, $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_makan, $uang_sorga, $uang_infaq, $uang_komite, $uang_lain, $catatan,
            $tanggal_bayar, $bulan_bayar, $tahun_bayar, $user_id,
            $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
            $tahun_ajaran_du, $kelas_du, $potongan_spp, $total_jumlah
        );
        $stmt->execute();
        $bayar_id = $koneksi->insert_id;
        $stmt->close();

        save_biaya_lain($koneksi, $bayar_id, $biaya_lain);

        // 2. Simpan daftar ulang ke tabel bayar_du jika ada nominal
        if ($uang_du > 0) {
            $stmt_du = $koneksi->prepare("INSERT INTO bayar_du (bayar_id, no_induk, kelas, th_ajaran, jumlah) VALUES (?, ?, ?, ?, ?)");
            $stmt_du->bind_param('isssd', $bayar_id, $no_induk, $kelas_du, $tahun_ajaran_du, $uang_du);
            $stmt_du->execute();
            $stmt_du->close();
        }

        // 3. Simpan tabungan wajib dengan referensi eksplisit ke pembayaran.
        save_linked_savings($koneksi, $bayar_id, $no_induk, $tanggal_bayar, $tabungan_wajib, $user_id);

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
    $uang_komite     = parse_amount($_POST['uang_komite'] ?? 0);
    $uang_makan      = parse_amount($_POST['uang_makan'] ?? 0);
    $uang_sorga      = parse_amount($_POST['uang_sorga'] ?? 0);
    $uang_infaq      = parse_amount($_POST['uang_infaq'] ?? 0);
    $uang_lain       = 0.0;
    $uang_du         = parse_amount($_POST['uang_du'] ?? 0);
    $ll_1_ket = $ll_2_ket = $ll_3_ket = $ll_4_ket = '';
    $ll_1_nom = $ll_2_nom = $ll_3_nom = $ll_4_nom = 0.0;
    
    $potongan_spp    = parse_amount($_POST['potongan_spp'] ?? 0);
    $tabungan_wajib  = parse_amount($_POST['tabungan_wajib'] ?? 0);
    $total_jumlah    = 0.0;
    $catatan         = $_POST['catatan'] ?? '';
    $kelas_du        = $_POST['kelas_du'] ?? '';
    $tahun_ajaran_du = $_POST['tahun_ajaran_du'] ?? '';

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: edit.php?id=' . $id);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        $old_bayar = find_linked_payment($koneksi, $id);
        validate_payment_amounts([
            'Pangkal' => $uang_pangkal, 'Bangunan' => $uang_bangunan,
            'Seragam' => $uang_seragam, 'Kegiatan' => $uang_kegiatan,
            'SPP' => $uang_spp, 'Komite' => $uang_komite, 'Makan' => $uang_makan,
            'Sorga' => $uang_sorga, 'Infaq' => $uang_infaq, 'Daftar Ulang' => $uang_du,
            'Potongan SPP' => $potongan_spp, 'Tabungan Wajib' => $tabungan_wajib
        ]);
        validate_payment_context($tanggal_bayar, $bulan_bayar, (string)$tahun_bayar, $uang_du, $kelas_du, $tahun_ajaran_du);
        if ($potongan_spp > $uang_spp) throw new RuntimeException('Potongan SPP tidak boleh melebihi pembayaran SPP.');
        $allowedArchived = $no_induk === $old_bayar['NO_INDUK'] ? $old_bayar['NO_INDUK'] : null;
        $siswa_data = validate_student_and_komite($koneksi, $no_induk, $bulan_bayar, $tahun_bayar, $uang_komite, $id, $allowedArchived);
        $kelas_siswa = $siswa_data['KELAS'];
        $biaya_lain = collect_biaya_lain($koneksi, $id);
        $total_jumlah = calculate_payment_total([
            $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_komite, $uang_makan, $uang_sorga, $uang_infaq
        ], $uang_du, $potongan_spp, $biaya_lain);

        // 1. Update data utama ke tabel bayar
        $sql = "UPDATE bayar SET
            NO_INDUK=?, KELAS=?, U_PANGKAL=?, U_BANGUNAN=?, U_SERAGAM=?, U_KEGIATAN=?,
            U_SPP=?, U_MAKAN=?, U_SORGA=?, U_INFAQ=?, U_KOMITE=?, U_LAIN=?, KETERANGAN=?,
            TGL_BYR=?, BULAN=?, TAHUN=?, user_id=?,
            LAIN_LAIN1=?, JUMLAH1=?, LAIN_LAIN2=?, JUMLAH2=?, LAIN_LAIN3=?, JUMLAH3=?, LAIN_LAIN4=?, JUMLAH4=?,
            th_ajaran=?, kelas_du=?, potong_spp=?, total_jumlah=?, payment_link_version=1
            WHERE id=?";

        $stmt = $koneksi->prepare($sql);
        $user_id = $_SESSION['admin_nama'] ?? 'admin';
        
        $stmt->bind_param(
            'ssddddddddddssssssdsdsdsdssddi',
            $no_induk, $kelas_siswa, $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_makan, $uang_sorga, $uang_infaq, $uang_komite, $uang_lain, $catatan,
            $tanggal_bayar, $bulan_bayar, $tahun_bayar, $user_id,
            $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
            $tahun_ajaran_du, $kelas_du, $potongan_spp, $total_jumlah, $id
        );
        $stmt->execute();
        $stmt->close();

        save_biaya_lain($koneksi, $id, $biaya_lain);

        // 2. Hapus hanya Daftar Ulang yang dimiliki pembayaran ini, lalu simpan nilai baru.
        $stmt_del_du = $koneksi->prepare("DELETE FROM bayar_du WHERE bayar_id = ?");
        $stmt_del_du->bind_param('i', $id);
        $stmt_del_du->execute();
        $stmt_del_du->close();

        if ($uang_du > 0) {
            $stmt_ins_du = $koneksi->prepare("INSERT INTO bayar_du (bayar_id, no_induk, kelas, th_ajaran, jumlah) VALUES (?, ?, ?, ?, ?)");
            $stmt_ins_du->bind_param('isssd', $id, $no_induk, $kelas_du, $tahun_ajaran_du, $uang_du);
            $stmt_ins_du->execute();
            $stmt_ins_du->close();
        }

        // 3. Balikkan dan buat ulang hanya setoran tabungan milik pembayaran ini.
        reverse_linked_savings($koneksi, $id);
        save_linked_savings($koneksi, $id, $no_induk, $tanggal_bayar, $tabungan_wajib, $user_id);

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

    $koneksi->begin_transaction();

    try {
        find_linked_payment($koneksi, $id);

        // 1. Balikkan setoran milik pembayaran ini. Jika saldo tidak cukup,
        // seluruh penghapusan akan di-rollback.
        reverse_linked_savings($koneksi, $id);

        // 2. Hapus header; FK cascade hanya akan menghapus child dengan bayar_id ini.
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
