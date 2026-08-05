<?php
// ============================================
// pembayaran/proses.php - Insert / Update / Delete
// ============================================
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/daftar_ulang.php';
requireRole(['admin', 'kasir']);

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

function validate_payment_context(string $tanggal, string $bulan, string $tahun): void {
    if (!in_array($bulan, ['01','02','03','04','05','06','07','08','09','10','11','12'], true)) {
        throw new RuntimeException('Bulan pembayaran tidak valid.');
    }
    if (!preg_match('/^\d{4}$/', $tahun)) throw new RuntimeException('Tahun pembayaran tidak valid.');
    $datePart = substr($tanggal, 0, 10);
    $parsedDate = DateTime::createFromFormat('!Y-m-d', $datePart);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $datePart) {
        throw new RuntimeException('Tanggal pembayaran tidak valid.');
    }
    $periodStart = DateTime::createFromFormat('!Y-m-d', $tahun . '-' . $bulan . '-01');
    $paymentMonth = DateTime::createFromFormat('!Y-m-d', $parsedDate->format('Y-m-01'));
    if (!$periodStart || $periodStart > $paymentMonth) {
        throw new RuntimeException('Periode pembayaran masa depan belum dapat dicatat. Pilih bulan yang sama atau lebih lama dari tanggal bayar.');
    }
}

function payment_month_label(string $bulan): string {
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
    return $months[$bulan] ?? $bulan;
}

function split_payment_amount(float $amount, int $parts): array {
    $totalCents = (int)round($amount * 100);
    $baseCents = intdiv($totalCents, $parts);
    $remainder = $totalCents % $parts;
    $result = [];
    for ($index = 0; $index < $parts; $index++) {
        $result[] = ($baseCents + ($index < $remainder ? 1 : 0)) / 100;
    }
    return $result;
}

function assert_spp_period_available(mysqli $db, string $noInduk, string $bulan, string $tahun, int $excludePaymentId = 0): void {
    $monthLabel = payment_month_label($bulan);
    $legacyMonth = (string)(int)$bulan;
    $stmt = $db->prepare('SELECT id FROM bayar WHERE NO_INDUK = ? AND TAHUN = ? AND (BULAN = ? OR BULAN = ? OR BULAN = ?) AND U_SPP > 0 AND id <> ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('sssssi', $noInduk, $tahun, $bulan, $monthLabel, $legacyMonth, $excludePaymentId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($exists) {
        throw new RuntimeException('SPP ' . $monthLabel . ' ' . $tahun . ' sudah dibayar. Periode yang sama tidak dapat dibayar dua kali.');
    }
}

function sync_spp_period_claim(mysqli $db, int $bayarId, string $noInduk, string $bulan, string $tahun, float $uangSpp): void {
    $stmt = $db->prepare('DELETE FROM bayar_spp_periode WHERE bayar_id = ?');
    $stmt->bind_param('i', $bayarId);
    $stmt->execute();
    $stmt->close();
    if ($uangSpp <= 0) return;

    assert_spp_period_available($db, $noInduk, $bulan, $tahun, $bayarId);
    $stmt = $db->prepare('INSERT INTO bayar_spp_periode (bayar_id, no_induk, bulan, tahun) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $bayarId, $noInduk, $bulan, $tahun);
    $stmt->execute();
    $stmt->close();
}

function academic_year_from_payment_period(string $bulan, string $tahun): string {
    $month = (int)$bulan;
    $year = (int)$tahun;
    $start = $month >= 7 ? $year : $year - 1;
    return $start . '/' . ($start + 1);
}

function normalize_student_class_for_du(array $student): string {
    $kelas = preg_replace('/\D+/', '', (string)($student['KELAS'] ?? ''));
    if (!in_array($kelas, ['1','2','3','4','5','6'], true)) {
        throw new RuntimeException('Kelas siswa tidak valid untuk Daftar Ulang.');
    }
    return $kelas;
}

function normalize_payment_method($value): string {
    $method = trim((string)$value);
    $allowed = ['Tunai', 'VA', 'Qris'];
    if (!in_array($method, $allowed, true)) {
        throw new RuntimeException('Sistem pembayaran tidak valid.');
    }
    return $method;
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
    $stmt = $db->prepare('SELECT KELAS, POMG, SPP_PERBULAN, is_active FROM siswa WHERE NO_INDUK = ? FOR UPDATE');
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

function payable_total(float $total, float $discount = 0, float $derivedTotal = 0): float {
    return $derivedTotal > 0 ? $derivedTotal : max(0, $total - $discount);
}

function daftar_ulang_total(mysqli $db, array $student, string $kelasDu, string $tahunAjaran): float {
    if ($kelasDu !== '' && $tahunAjaran !== '') {
        $stmt = $db->prepare('
            SELECT Jumlah
            FROM Daftar_ulang
            WHERE kelas = ? AND th_ajaran = ? AND Jumlah > 0
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->bind_param('ss', $kelasDu, $tahunAjaran);
        $stmt->execute();
        $master = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($master) return (float)$master['Jumlah'];
    }

    $masterCount = $db->query("
        SELECT COUNT(*) AS jumlah
        FROM Daftar_ulang
        WHERE kelas IS NOT NULL AND kelas <> ''
          AND th_ajaran IS NOT NULL AND th_ajaran <> ''
          AND Jumlah > 0
    ")->fetch_assoc();
    if ((int)($masterCount['jumlah'] ?? 0) > 0) {
        return 0.0;
    }

    return payable_total((float)$student['DAFTAR_ULANG'], (float)$student['potong_du'], (float)$student['tot_du']);
}

function validate_component_remaining(
    mysqli $db,
    string $noInduk,
    string $bulan,
    string $tahun,
    array $components,
    float $uangDu,
    string $kelasDu = '',
    string $tahunAjaran = '',
    int $excludePaymentId = 0
): void {
    $stmt = $db->prepare('
        SELECT PANGKAL, potong_pangkal, tot_pangkal, BANGUNAN, SERAGAM, KEGIATAN,
               SPP_PERBULAN, POMG, DAFTAR_ULANG, potong_du, tot_du
        FROM siswa
        WHERE NO_INDUK = ?
        FOR UPDATE
    ');
    $stmt->bind_param('s', $noInduk);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');

    $stmtPaid = $db->prepare('
        SELECT
            COALESCE(SUM(U_PANGKAL), 0) AS pangkal,
            COALESCE(SUM(U_BANGUNAN), 0) AS bangunan,
            COALESCE(SUM(U_SERAGAM), 0) AS seragam,
            COALESCE(SUM(U_KEGIATAN), 0) AS kegiatan,
            COALESCE(SUM(CASE WHEN BULAN = ? AND TAHUN = ? THEN U_SPP ELSE 0 END), 0) AS spp,
            COALESCE(SUM(CASE WHEN BULAN = ? AND TAHUN = ? THEN U_KOMITE ELSE 0 END), 0) AS komite,
            COALESCE(SUM(U_MAKAN), 0) AS makan,
            COALESCE(SUM(U_SORGA), 0) AS sorga,
            COALESCE(SUM(U_INFAQ), 0) AS infaq
        FROM bayar
        WHERE NO_INDUK = ? AND id <> ?
    ');
    $stmtPaid->bind_param('sssssi', $bulan, $tahun, $bulan, $tahun, $noInduk, $excludePaymentId);
    $stmtPaid->execute();
    $paid = $stmtPaid->get_result()->fetch_assoc() ?: [];
    $stmtPaid->close();

    $duTotal = 0.0;
    $paid['du'] = 0.0;
    if ($uangDu > 0) {
        $bill = du_require_bill($db, $noInduk, (int)$bulan, (int)$tahun, true);
        $duTotal = (float)$bill['nominal_tagihan'];
        $billId = (int)$bill['id'];
        $stmtDu = $db->prepare('SELECT COALESCE(SUM(jumlah), 0) AS du FROM bayar_du WHERE tagihan_daftar_ulang_id = ? AND (bayar_id IS NULL OR bayar_id <> ?)');
        $stmtDu->bind_param('ii', $billId, $excludePaymentId);
        $stmtDu->execute();
        $paid['du'] = (float)($stmtDu->get_result()->fetch_assoc()['du'] ?? 0);
        $stmtDu->close();
    }

    $limits = [
        'pangkal' => ['label' => 'Uang Pangkal', 'total' => payable_total((float)$student['PANGKAL'], (float)$student['potong_pangkal'], (float)$student['tot_pangkal']), 'paid' => (float)($paid['pangkal'] ?? 0), 'input' => (float)($components['pangkal'] ?? 0)],
        'bangunan' => ['label' => 'Uang Bangunan', 'total' => (float)$student['BANGUNAN'], 'paid' => (float)($paid['bangunan'] ?? 0), 'input' => (float)($components['bangunan'] ?? 0)],
        'seragam' => ['label' => 'Uang Seragam', 'total' => (float)$student['SERAGAM'], 'paid' => (float)($paid['seragam'] ?? 0), 'input' => (float)($components['seragam'] ?? 0)],
        'kegiatan' => ['label' => 'Uang Kegiatan', 'total' => (float)$student['KEGIATAN'], 'paid' => (float)($paid['kegiatan'] ?? 0), 'input' => (float)($components['kegiatan'] ?? 0)],
        'spp' => ['label' => 'Uang SPP', 'total' => (float)$student['SPP_PERBULAN'], 'paid' => (float)($paid['spp'] ?? 0), 'input' => (float)($components['spp'] ?? 0)],
        'komite' => ['label' => 'Uang Komite', 'total' => (float)$student['POMG'], 'paid' => (float)($paid['komite'] ?? 0), 'input' => (float)($components['komite'] ?? 0)],
        'makan' => ['label' => 'Uang Makan', 'total' => 0.0, 'paid' => (float)($paid['makan'] ?? 0), 'input' => (float)($components['makan'] ?? 0)],
        'sorga' => ['label' => 'Uang Sorga', 'total' => 0.0, 'paid' => (float)($paid['sorga'] ?? 0), 'input' => (float)($components['sorga'] ?? 0)],
        'infaq' => ['label' => 'Uang Infaq', 'total' => 0.0, 'paid' => (float)($paid['infaq'] ?? 0), 'input' => (float)($components['infaq'] ?? 0)],
        'du' => ['label' => 'Daftar Ulang', 'total' => $duTotal, 'paid' => (float)($paid['du'] ?? 0), 'input' => $uangDu],
    ];

    foreach ($limits as $limit) {
        if ($limit['input'] <= 0) continue;
        if ($limit['total'] <= 0) {
            throw new RuntimeException($limit['label'] . ' belum memiliki total tagihan. Lengkapi master atau data tagihan terlebih dahulu.');
        }
        $remaining = max(0, $limit['total'] - $limit['paid']);
        if ($limit['paid'] > $limit['total'] + 0.001) {
            throw new RuntimeException($limit['label'] . ' sudah melebihi total tagihan. Total: Rp ' . number_format($limit['total'], 0, ',', '.') . ', sudah terbayar: Rp ' . number_format($limit['paid'], 0, ',', '.') . '. Cek ulang transaksi sebelumnya.');
        }
        if ($limit['input'] > $remaining + 0.001) {
            throw new RuntimeException('Pembayaran ' . $limit['label'] . ' melebihi sisa tagihan. Sisa: Rp ' . number_format($remaining, 0, ',', '.') . '.');
        }
    }
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

function get_biaya_lain_master(mysqli $koneksi, int $masterId, bool $requireActive): ?array {
    $sql = 'SELECT id, nama, nominal, is_active FROM master_biaya_lain WHERE id = ? AND nominal > 0';
    if ($requireActive) $sql .= ' AND is_active = 1';
    $sql .= ' LIMIT 1';

    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param('i', $masterId);
    $stmt->execute();
    $master = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $master ?: null;
}

function paid_biaya_lain_total(mysqli $koneksi, string $noInduk, int $masterId, int $excludePaymentId = 0): float {
    $stmt = $koneksi->prepare('
        SELECT COALESCE(SUM(d.nominal_snapshot), 0) AS paid
        FROM bayar_biaya_lain d
        JOIN bayar b ON b.id = d.bayar_id
        WHERE b.NO_INDUK = ? AND d.master_biaya_lain_id = ? AND b.id <> ?
    ');
    $stmt->bind_param('sii', $noInduk, $masterId, $excludePaymentId);
    $stmt->execute();
    $paid = (float)($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();
    return $paid;
}

function collect_biaya_lain(mysqli $koneksi, string $noInduk, int $bayarId = 0): array {
    $detailIds = $_POST['biaya_lain_detail_id'] ?? [];
    $masterIds = $_POST['biaya_lain_master_id'] ?? [];
    $nominals = $_POST['biaya_lain_nominal'] ?? [];
    $notes = $_POST['biaya_lain_keterangan'] ?? [];
    if (!is_array($detailIds) || !is_array($masterIds) || !is_array($nominals) || !is_array($notes)) {
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
    $submittedByMaster = [];
    $rowCount = max(count($detailIds), count($masterIds), count($nominals), count($notes));
    for ($index = 0; $index < $rowCount; $index++) {
        $detailId = (int)($detailIds[$index] ?? 0);
        $masterId = (int)($masterIds[$index] ?? 0);
        $nominalInput = parse_amount($nominals[$index] ?? null);
        $note = trim((string)($notes[$index] ?? ''));
        if (mb_strlen($note) > 255) $note = mb_substr($note, 0, 255);

        $oldLine = $detailId > 0 && isset($existing[$detailId]) ? $existing[$detailId] : null;
        if (!is_finite($nominalInput) || $nominalInput < 0) {
            throw new RuntimeException('Nominal biaya lain harus berupa angka positif atau nol.');
        }

        if ($masterId <= 0) {
            // Detail hasil migrasi tidak memiliki master, tetapi snapshot-nya tetap sah.
            if ($oldLine && $oldLine['master_biaya_lain_id'] === null) {
                if ($nominalInput <= 0) continue;
                $lines[] = [
                    'master_id' => null,
                    'nama' => $oldLine['nama_biaya_snapshot'],
                    'nominal' => $nominalInput,
                    'keterangan' => $note,
                ];
            }
            continue;
        }

        if ($nominalInput <= 0) continue;

        $sameMasterAsOldLine = $oldLine && (int)$oldLine['master_biaya_lain_id'] === $masterId;
        $master = get_biaya_lain_master($koneksi, $masterId, !$sameMasterAsOldLine);
        if (!$master) throw new RuntimeException('Pilihan master biaya lain tidak tersedia atau sudah nonaktif.');

        $masterTotal = (float)$master['nominal'];
        $paidBefore = paid_biaya_lain_total($koneksi, $noInduk, $masterId, $bayarId);
        if ($paidBefore > $masterTotal + 0.001) {
            throw new RuntimeException($master['nama'] . ' sudah melebihi total tagihan. Total: Rp ' . number_format($masterTotal, 0, ',', '.') . ', sudah terbayar: Rp ' . number_format($paidBefore, 0, ',', '.') . '. Cek ulang transaksi sebelumnya.');
        }

        if ($paidBefore >= $masterTotal - 0.001) {
            throw new RuntimeException($master['nama'] . ' sudah lunas dan tidak dapat ditambahkan lagi.');
        }

        if (array_key_exists($masterId, $submittedByMaster)) {
            throw new RuntimeException($master['nama'] . ' hanya boleh dipilih satu kali dalam satu transaksi.');
        }

        $submittedBefore = (float)($submittedByMaster[$masterId] ?? 0);
        $remaining = max(0, $masterTotal - $paidBefore - $submittedBefore);
        if ($nominalInput > $remaining + 0.001) {
            throw new RuntimeException('Pembayaran ' . $master['nama'] . ' melebihi sisa tagihan. Sisa: Rp ' . number_format($remaining, 0, ',', '.') . '.');
        }
        $submittedByMaster[$masterId] = $submittedBefore + $nominalInput;

        $lines[] = [
            'master_id' => (int)$master['id'],
            'nama' => $sameMasterAsOldLine ? $oldLine['nama_biaya_snapshot'] : $master['nama'],
            'nominal' => $nominalInput,
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
    $sistem_pembayaran = $_POST['sistem_pembayaran'] ?? 'VA';
    
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
    $payment_plan    = $_POST['payment_plan'] ?? 'monthly';

    if (empty($no_induk)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih siswa terlebih dahulu!'];
        header('Location: form.php');
        exit;
    }

    $koneksi->begin_transaction();

    try {
        if ($payment_plan !== 'monthly') throw new RuntimeException('Pembayaran banyak bulan sedang ditangguhkan. Gunakan transaksi bulanan.');
        $sistem_pembayaran = normalize_payment_method($sistem_pembayaran);
        validate_payment_amounts([
            'Pangkal' => $uang_pangkal, 'Bangunan' => $uang_bangunan,
            'Seragam' => $uang_seragam, 'Kegiatan' => $uang_kegiatan,
            'SPP' => $uang_spp, 'Komite' => $uang_komite, 'Makan' => $uang_makan,
            'Sorga' => $uang_sorga, 'Infaq' => $uang_infaq, 'Daftar Ulang' => $uang_du,
            'Potongan SPP' => $potongan_spp, 'Tabungan Wajib' => $tabungan_wajib
        ]);
        validate_payment_context($tanggal_bayar, $bulan_bayar, (string)$tahun_bayar);
        if ($potongan_spp > $uang_spp) throw new RuntimeException('Potongan SPP tidak boleh melebihi pembayaran SPP.');
        $siswa_data = validate_student_and_komite($koneksi, $no_induk, $bulan_bayar, $tahun_bayar, $uang_komite);
        $kelas_siswa = $siswa_data['KELAS'];
        $du_bill_id = null;
        $tahun_ajaran_du = du_academic_year_label((int)$bulan_bayar, (int)$tahun_bayar);
        $kelas_du = '';
        if ($uang_du > 0) {
            $du_bill = du_require_bill($koneksi, $no_induk, (int)$bulan_bayar, (int)$tahun_bayar, true);
            $du_bill_id = (int)$du_bill['id'];
            $kelas_du = (string)$du_bill['kelas'];
            $tahun_ajaran_du = (string)$du_bill['tahun_ajaran'];
        }

        $periods = [['bulan' => $bulan_bayar, 'tahun' => (string)$tahun_bayar]];
        $spp_parts = [$uang_spp];
        $discount_parts = [$potongan_spp];
        $batch_token = null;
        $batch_count = 1;
        if ($payment_plan === 'annual') {
            $monthlyBill = (float)$siswa_data['SPP_PERBULAN'];
            $annualBill = $monthlyBill * 12;
            if ($monthlyBill <= 0) throw new RuntimeException('Tagihan SPP bulanan siswa belum diatur.');
            if (abs($uang_spp - $annualBill) > 0.001) {
                throw new RuntimeException('Pembayaran tahunan harus senilai 12 bulan penuh, yaitu Rp ' . number_format($annualBill, 0, ',', '.') . '.');
            }
            $periods = [];
            for ($month = 1; $month <= 12; $month++) {
                $periods[] = ['bulan' => str_pad((string)$month, 2, '0', STR_PAD_LEFT), 'tahun' => (string)$tahun_bayar];
            }
            foreach ($periods as $period) {
                assert_spp_period_available($koneksi, $no_induk, $period['bulan'], $period['tahun']);
            }
            $spp_parts = split_payment_amount($uang_spp, 12);
            $discount_parts = split_payment_amount($potongan_spp, 12);
            $batch_token = bin2hex(random_bytes(16));
            $batch_count = 12;
        } elseif ($uang_spp > 0) {
            assert_spp_period_available($koneksi, $no_induk, $bulan_bayar, (string)$tahun_bayar);
        }

        foreach ($periods as $index => $period) {
            $isFirst = $index === 0;
            validate_component_remaining($koneksi, $no_induk, $period['bulan'], $period['tahun'], [
                'pangkal' => $isFirst ? $uang_pangkal : 0,
                'bangunan' => $isFirst ? $uang_bangunan : 0,
                'seragam' => $isFirst ? $uang_seragam : 0,
                'kegiatan' => $isFirst ? $uang_kegiatan : 0,
                'spp' => $spp_parts[$index],
                'komite' => $isFirst ? $uang_komite : 0,
                'makan' => $isFirst ? $uang_makan : 0,
                'sorga' => $isFirst ? $uang_sorga : 0,
                'infaq' => $isFirst ? $uang_infaq : 0,
            ], $isFirst ? $uang_du : 0, $kelas_du, $tahun_ajaran_du);
        }

        $biaya_lain = collect_biaya_lain($koneksi, $no_induk);

        // Satu pembayaran tahunan disimpan sebagai 12 header transaksi agar
        // setiap bulan memiliki nomor dan halaman struk sendiri.
        $sql = "INSERT INTO bayar (
            NO_INDUK, KELAS, U_PANGKAL, U_BANGUNAN, U_SERAGAM, U_KEGIATAN,
            U_SPP, U_MAKAN, U_SORGA, U_INFAQ, U_KOMITE, U_LAIN, KETERANGAN,
            TGL_BYR, BULAN, TAHUN, user_id, sistem_pembayaran,
            LAIN_LAIN1, JUMLAH1, LAIN_LAIN2, JUMLAH2, LAIN_LAIN3, JUMLAH3, LAIN_LAIN4, JUMLAH4,
            th_ajaran, kelas_du, potong_spp, total_jumlah, payment_link_version,
            payment_batch_token, payment_batch_sequence, payment_batch_count
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)";

        $stmt = $koneksi->prepare($sql);
        $user_id = $_SESSION['admin_nama'] ?? 'admin';
        $receipt_ids = [];
        foreach ($periods as $index => $period) {
            $isFirst = $index === 0;
            $row_pangkal = $isFirst ? $uang_pangkal : 0.0;
            $row_bangunan = $isFirst ? $uang_bangunan : 0.0;
            $row_seragam = $isFirst ? $uang_seragam : 0.0;
            $row_kegiatan = $isFirst ? $uang_kegiatan : 0.0;
            $row_spp = $spp_parts[$index];
            $row_komite = $isFirst ? $uang_komite : 0.0;
            $row_makan = $isFirst ? $uang_makan : 0.0;
            $row_sorga = $isFirst ? $uang_sorga : 0.0;
            $row_infaq = $isFirst ? $uang_infaq : 0.0;
            $row_du = $isFirst ? $uang_du : 0.0;
            $row_discount = $discount_parts[$index];
            $row_other = $isFirst ? $biaya_lain : [];
            $row_total = calculate_payment_total([
                $row_pangkal, $row_bangunan, $row_seragam, $row_kegiatan, $row_spp,
                $row_komite, $row_makan, $row_sorga, $row_infaq
            ], $row_du, $row_discount, $row_other);
            $row_month = $period['bulan'];
            $row_year = $period['tahun'];
            $batch_sequence = $index + 1;

            $stmt->bind_param(
                'ssddddddddddsssssssdsdsdsdssddsii',
                $no_induk, $kelas_siswa, $row_pangkal, $row_bangunan, $row_seragam, $row_kegiatan,
                $row_spp, $row_makan, $row_sorga, $row_infaq, $row_komite, $uang_lain, $catatan,
                $tanggal_bayar, $row_month, $row_year, $user_id, $sistem_pembayaran,
                $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
                $tahun_ajaran_du, $kelas_du, $row_discount, $row_total,
                $batch_token, $batch_sequence, $batch_count
            );
            $stmt->execute();
            $bayar_id = $koneksi->insert_id;
            $receipt_ids[] = $bayar_id;
            sync_spp_period_claim($koneksi, $bayar_id, $no_induk, $row_month, $row_year, $row_spp);

            if (!$isFirst) continue;
            save_biaya_lain($koneksi, $bayar_id, $biaya_lain);
            if ($uang_du > 0) {
                $stmt_du = $koneksi->prepare("INSERT INTO bayar_du (bayar_id, tagihan_daftar_ulang_id, no_induk, kelas, th_ajaran, jumlah) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_du->bind_param('iisssd', $bayar_id, $du_bill_id, $no_induk, $kelas_du, $tahun_ajaran_du, $uang_du);
                $stmt_du->execute();
                $stmt_du->close();
            }
            save_linked_savings($koneksi, $bayar_id, $no_induk, $tanggal_bayar, $tabungan_wajib, $user_id);
        }
        $stmt->close();

        $koneksi->commit();
        $_SESSION['flash'] = [
            'type' => 'success',
            'msg' => $payment_plan === 'annual'
                ? 'Pembayaran tahunan berhasil dibagi menjadi 12 transaksi dan 12 struk!'
                : 'Data pembayaran berhasil disimpan!',
            'print_payment' => [
                'id' => $receipt_ids[0],
                'batch' => $batch_token,
                'count' => $batch_count,
                'bulan' => $payment_plan === 'annual' ? '01' : $bulan_bayar,
                'tahun' => (string)$tahun_bayar,
                'source' => 'input',
            ],
        ];
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
    $sistem_pembayaran = $_POST['sistem_pembayaran'] ?? 'VA';
    
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
        $sistem_pembayaran = normalize_payment_method($sistem_pembayaran);
        validate_payment_amounts([
            'Pangkal' => $uang_pangkal, 'Bangunan' => $uang_bangunan,
            'Seragam' => $uang_seragam, 'Kegiatan' => $uang_kegiatan,
            'SPP' => $uang_spp, 'Komite' => $uang_komite, 'Makan' => $uang_makan,
            'Sorga' => $uang_sorga, 'Infaq' => $uang_infaq, 'Daftar Ulang' => $uang_du,
            'Potongan SPP' => $potongan_spp, 'Tabungan Wajib' => $tabungan_wajib
        ]);
        validate_payment_context($tanggal_bayar, $bulan_bayar, (string)$tahun_bayar);
        if ($potongan_spp > $uang_spp) throw new RuntimeException('Potongan SPP tidak boleh melebihi pembayaran SPP.');
        $allowedArchived = $no_induk === $old_bayar['NO_INDUK'] ? $old_bayar['NO_INDUK'] : null;
        $siswa_data = validate_student_and_komite($koneksi, $no_induk, $bulan_bayar, $tahun_bayar, $uang_komite, $id, $allowedArchived);
        $du_bill_id = null;
        $tahun_ajaran_du = du_academic_year_label((int)$bulan_bayar, (int)$tahun_bayar);
        $kelas_du = '';
        if ($uang_du > 0) {
            $du_bill = du_require_bill($koneksi, $no_induk, (int)$bulan_bayar, (int)$tahun_bayar, true);
            $du_bill_id = (int)$du_bill['id'];
            $kelas_du = (string)$du_bill['kelas'];
            $tahun_ajaran_du = (string)$du_bill['tahun_ajaran'];
        }
        validate_component_remaining($koneksi, $no_induk, $bulan_bayar, (string)$tahun_bayar, [
            'pangkal' => $uang_pangkal,
            'bangunan' => $uang_bangunan,
            'seragam' => $uang_seragam,
            'kegiatan' => $uang_kegiatan,
            'spp' => $uang_spp,
            'komite' => $uang_komite,
            'makan' => $uang_makan,
            'sorga' => $uang_sorga,
            'infaq' => $uang_infaq,
        ], $uang_du, $kelas_du, $tahun_ajaran_du, $id);
        $kelas_siswa = $siswa_data['KELAS'];
        $biaya_lain = collect_biaya_lain($koneksi, $no_induk, $id);
        $total_jumlah = calculate_payment_total([
            $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_komite, $uang_makan, $uang_sorga, $uang_infaq
        ], $uang_du, $potongan_spp, $biaya_lain);

        // 1. Update data utama ke tabel bayar
        $sql = "UPDATE bayar SET
            NO_INDUK=?, KELAS=?, U_PANGKAL=?, U_BANGUNAN=?, U_SERAGAM=?, U_KEGIATAN=?,
            U_SPP=?, U_MAKAN=?, U_SORGA=?, U_INFAQ=?, U_KOMITE=?, U_LAIN=?, KETERANGAN=?,
            TGL_BYR=?, BULAN=?, TAHUN=?, user_id=?, sistem_pembayaran=?,
            LAIN_LAIN1=?, JUMLAH1=?, LAIN_LAIN2=?, JUMLAH2=?, LAIN_LAIN3=?, JUMLAH3=?, LAIN_LAIN4=?, JUMLAH4=?,
            th_ajaran=?, kelas_du=?, potong_spp=?, total_jumlah=?, payment_link_version=1
            WHERE id=?";

        $stmt = $koneksi->prepare($sql);
        $user_id = $_SESSION['admin_nama'] ?? 'admin';
        
        $stmt->bind_param(
            'ssddddddddddsssssssdsdsdsdssddi',
            $no_induk, $kelas_siswa, $uang_pangkal, $uang_bangunan, $uang_seragam, $uang_kegiatan,
            $uang_spp, $uang_makan, $uang_sorga, $uang_infaq, $uang_komite, $uang_lain, $catatan,
            $tanggal_bayar, $bulan_bayar, $tahun_bayar, $user_id, $sistem_pembayaran,
            $ll_1_ket, $ll_1_nom, $ll_2_ket, $ll_2_nom, $ll_3_ket, $ll_3_nom, $ll_4_ket, $ll_4_nom,
            $tahun_ajaran_du, $kelas_du, $potongan_spp, $total_jumlah, $id
        );
        $stmt->execute();
        $stmt->close();

        // Klaim periode ikut berpindah/dihapus ketika bulan, tahun, siswa,
        // atau nominal SPP pada transaksi diedit.
        sync_spp_period_claim($koneksi, $id, $no_induk, $bulan_bayar, (string)$tahun_bayar, $uang_spp);

        save_biaya_lain($koneksi, $id, $biaya_lain);

        // 2. Hapus hanya Daftar Ulang yang dimiliki pembayaran ini, lalu simpan nilai baru.
        $stmt_del_du = $koneksi->prepare("DELETE FROM bayar_du WHERE bayar_id = ?");
        $stmt_del_du->bind_param('i', $id);
        $stmt_del_du->execute();
        $stmt_del_du->close();

        if ($uang_du > 0) {
            $stmt_ins_du = $koneksi->prepare("INSERT INTO bayar_du (bayar_id, tagihan_daftar_ulang_id, no_induk, kelas, th_ajaran, jumlah) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins_du->bind_param('iisssd', $id, $du_bill_id, $no_induk, $kelas_du, $tahun_ajaran_du, $uang_du);
            $stmt_ins_du->execute();
            $stmt_ins_du->close();
        }

        // 3. Balikkan dan buat ulang hanya setoran tabungan milik pembayaran ini.
        reverse_linked_savings($koneksi, $id);
        save_linked_savings($koneksi, $id, $no_induk, $tanggal_bayar, $tabungan_wajib, $user_id);

        $koneksi->commit();
        $_SESSION['flash'] = [
            'type' => 'success',
            'msg' => 'Data pembayaran berhasil diperbarui!',
            'print_payment' => [
                'id' => $id,
                'bulan' => $bulan_bayar,
                'tahun' => (string)$tahun_bayar,
                'source' => 'update',
            ],
        ];
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
