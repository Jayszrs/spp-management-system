<?php
require_once __DIR__ . '/../koneksi.php';

function payment_process_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function payment_process_request(string $url, array $data, array &$cookies): array {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create([
        'http' => [
            'method' => $data ? 'POST' : 'GET',
            'header' => implode("\r\n", $headers),
            'content' => $data ? http_build_query($data) : '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('HTTP request ke aplikasi gagal.');

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return ['status' => $status, 'body' => $body];
}

do {
    $noInduk = (string)random_int(9900000000, 9999999999);
    $stmtCheck = $koneksi->prepare('SELECT 1 FROM siswa WHERE NO_INDUK = ?');
    $stmtCheck->bind_param('s', $noInduk);
    $stmtCheck->execute();
    $exists = (bool)$stmtCheck->get_result()->fetch_row();
    $stmtCheck->close();
} while ($exists);
do {
    $targetNoInduk = (string)random_int(9900000000, 9999999999);
    $stmtTargetCheck = $koneksi->prepare('SELECT 1 FROM siswa WHERE NO_INDUK = ?');
    $stmtTargetCheck->bind_param('s', $targetNoInduk);
    $stmtTargetCheck->execute();
    $targetExists = $targetNoInduk === $noInduk || (bool)$stmtTargetCheck->get_result()->fetch_row();
    $stmtTargetCheck->close();
} while ($targetExists);

$failure = null;
$otherFeeMasterIds = [];
$otherFeeBillIds = [];
try {
    $name = 'UJI INTEGRASI CICILAN';
    $class = '1';
    $monthlyFee = 250000.0;
    $classId=(int)$koneksi->query("SELECT id FROM master_kelas WHERE tingkat=1 AND is_placeholder=1 LIMIT 1")->fetch_assoc()['id'];
    $stmtStudent = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, master_kelas_id, SPP_PERBULAN) VALUES (?, ?, ?, ?, ?)');
    $stmtStudent->bind_param('sssid', $noInduk, $name, $class, $classId, $monthlyFee);
    $stmtStudent->execute();
    $stmtStudent->close();
    $targetName = 'UJI TARGET CICILAN';
    $stmtTarget = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, master_kelas_id, SPP_PERBULAN) VALUES (?, ?, ?, ?, ?)');
    $stmtTarget->bind_param('sssid', $targetNoInduk, $targetName, $class, $classId, $monthlyFee);
    $stmtTarget->execute();
    $stmtTarget->close();

    $stmtOtherFee = $koneksi->prepare('INSERT INTO master_biaya_lain (nama, nominal, is_active) VALUES (?, ?, 1)');
    for ($index = 1; $index <= 5; $index++) {
        $otherFeeName = 'UJI BIAYA LEGACY ' . $noInduk . ' #' . $index;
        $otherFeeLimit = 100000.0;
        $stmtOtherFee->bind_param('sd', $otherFeeName, $otherFeeLimit);
        $stmtOtherFee->execute();
        $otherFeeMasterIds[] = (int)$koneksi->insert_id;
    }
    $stmtOtherFee->close();

    $stmtBill=$koneksi->prepare("INSERT INTO tagihan_biaya_lain (master_biaya_lain_id,no_induk,master_kelas_id,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot,status) SELECT id,?,?,nama,nominal,'Kelas 1 (Belum Ditentukan)','open' FROM master_biaya_lain WHERE id=?");
    foreach($otherFeeMasterIds as $masterId){$stmtBill->bind_param('sii',$noInduk,$classId,$masterId);$stmtBill->execute();$otherFeeBillIds[]=(int)$koneksi->insert_id;}$stmtBill->close();

    $baseUrl = getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1/spp-management-system';
    $password = getenv('SPP_TEST_ADMIN_PASSWORD') ?: 'admin123';
    $cookies = [];
    $login = payment_process_request($baseUrl . '/login.php', [
        'username' => 'admin',
        'password' => $password,
    ], $cookies);
    payment_process_assert($login['status'] === 302 && isset($cookies['PHPSESSID']), 'Login admin untuk tes integrasi gagal.');

    $common = [
        'aksi' => 'input',
        'payment_plan' => 'monthly',
        'no_induk' => $noInduk,
        'tanggal_bayar' => '2000-01-01',
        'bulan_bayar' => '08',
        'tahun_bayar' => '2026',
        'sistem_pembayaran' => 'Tunai',
    ];
    $legacySavingsResponse = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + [
        'uang_spp' => 100000,
        'tabungan_wajib' => 20000,
    ], $cookies);
    payment_process_assert($legacySavingsResponse['status'] === 302, 'POST tabungan legacy tidak mengembalikan redirect yang diharapkan.');
    $legacySavingsFeedback = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    payment_process_assert(str_contains($legacySavingsFeedback['body'], 'Input tabungan lewat pembayaran sudah dinonaktifkan'), 'POST tabungan legacy tidak ditolak backend.');
    payment_process_assert(!str_contains($legacySavingsFeedback['body'], 'name="tabungan_wajib"'), 'Form input masih memiliki field tabungan pembayaran.');
    payment_process_assert(!str_contains($legacySavingsFeedback['body'], 'id="tab-wajib"'), 'Form input masih memiliki kontrol tabungan pembayaran.');

    $blockedAugust = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'no_induk' => $targetNoInduk,
        'uang_spp' => 50000,
    ]), $cookies);
    payment_process_assert($blockedAugust['status'] === 302, 'POST SPP Agustus tanpa Juli lunas tidak mengembalikan redirect.');
    $blockedFeedback = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    payment_process_assert(
        str_contains($blockedFeedback['body'], 'belum bisa dibayar karena Juli 2026 belum lunas'),
        'SPP Agustus tidak ditolak saat Juli belum lunas.'
    );

    $julyResponse = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'bulan_bayar' => '07',
        'uang_spp' => 250000,
    ]), $cookies);
    payment_process_assert($julyResponse['status'] === 302, 'Pelunasan SPP Juli sebagai prasyarat Agustus gagal.');

    $response = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + [
        'uang_spp' => 100000,
        'biaya_lain_detail_id' => [0, 0, 0, 0, 0],
        'biaya_lain_tagihan_id' => $otherFeeBillIds,
        'biaya_lain_nominal' => [11000, 12000, 13000, 14000, 15000],
        'biaya_lain_keterangan' => ['', '', '', '', ''],
    ], $cookies);
    payment_process_assert($response['status'] === 302, 'Endpoint pembayaran tidak mengembalikan redirect yang diharapkan.');
    foreach ([150000, 10000] as $amount) {
        $response = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + ['uang_spp' => $amount], $cookies);
        payment_process_assert($response['status'] === 302, 'Endpoint pembayaran tidak mengembalikan redirect yang diharapkan.');
    }

    $stmtResult = $koneksi->prepare("
        SELECT COUNT(*) AS payment_count, COALESCE(SUM(U_SPP), 0) AS paid,
               MIN(DATE(TGL_BYR)) AS payment_date
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtResult->bind_param('s', $noInduk);
    $stmtResult->execute();
    $result = $stmtResult->get_result()->fetch_assoc();
    $stmtResult->close();
    payment_process_assert((int)$result['payment_count'] === 2, 'Pembayaran ketiga setelah lunas tidak ditolak.');
    payment_process_assert(abs((float)$result['paid'] - 250000.0) < 0.001, 'Dua cicilan tidak berjumlah Rp250.000.');
    payment_process_assert($result['payment_date'] === date('Y-m-d'), 'Tanggal manipulasi dari browser tidak diabaikan backend.');

    $stmtClaims = $koneksi->prepare("SELECT COUNT(*) AS total FROM bayar_spp_periode WHERE no_induk = ? AND tahun = '2026' AND bulan = '08'");
    $stmtClaims->bind_param('s', $noInduk);
    $stmtClaims->execute();
    $claimCount = (int)$stmtClaims->get_result()->fetch_assoc()['total'];
    $stmtClaims->close();
    payment_process_assert($claimCount === 2, 'Dua cicilan tidak memiliki dua pemetaan periode.');

    $form = payment_process_request($baseUrl . '/pembayaran/form.php', [], $cookies);
    payment_process_assert(str_contains($form['body'], 'melebihi sisa tagihan'), 'Pesan penolakan pembayaran setelah lunas tidak tampil.');

    $stmtJuly = $koneksi->prepare("
        SELECT MIN(id) AS id
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '07' OR BULAN = '7' OR BULAN = 'Juli')
    ");
    $stmtJuly->bind_param('s', $noInduk);
    $stmtJuly->execute();
    $julyPaymentId = (int)$stmtJuly->get_result()->fetch_assoc()['id'];
    $stmtJuly->close();
    $deleteJuly = payment_process_request($baseUrl . '/pembayaran/proses.php?aksi=hapus&id=' . $julyPaymentId, [], $cookies);
    payment_process_assert($deleteJuly['status'] === 302, 'Hapus SPP Juli yang menjadi prasyarat tidak mengembalikan redirect.');
    $deleteJulyFeedback = payment_process_request($baseUrl . '/pembayaran/lihat.php', [], $cookies);
    payment_process_assert(
        str_contains($deleteJulyFeedback['body'], 'tidak bisa dihapus karena Agustus 2026 sudah memiliki pembayaran'),
        'Hapus SPP Juli tidak ditolak saat Agustus sudah memiliki pembayaran.'
    );

    $stmtFirst = $koneksi->prepare("
        SELECT MIN(id) AS id
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtFirst->bind_param('s', $noInduk);
    $stmtFirst->execute();
    $firstPaymentId = (int)$stmtFirst->get_result()->fetch_assoc()['id'];
    $stmtFirst->close();

    $adminId = (string)$koneksi->query("SELECT id FROM admin WHERE username='admin' LIMIT 1")->fetch_assoc()['id'];
    $stmtOperator = $koneksi->prepare("
        SELECT b.user_id AS payment_operator,
               (SELECT COUNT(*) FROM transaksi_m WHERE bayar_id = b.id) AS linked_savings
        FROM bayar b
        WHERE b.id = ?
    ");
    $stmtOperator->bind_param('i', $firstPaymentId);
    $stmtOperator->execute();
    $operatorRow = $stmtOperator->get_result()->fetch_assoc();
    $stmtOperator->close();
    payment_process_assert(
        (string)$operatorRow['payment_operator'] === $adminId
        && (int)$operatorRow['linked_savings'] === 0,
        'Transaksi pembayaran belum menyimpan ID kasir atau masih membuat tabungan terkait.'
    );

    $stmtLegacyOther = $koneksi->prepare("\n        SELECT U_LAIN, LAIN_LAIN1, JUMLAH1, LAIN_LAIN2, JUMLAH2,\n               LAIN_LAIN3, JUMLAH3, LAIN_LAIN4, JUMLAH4,\n               (SELECT COUNT(*) FROM bayar_biaya_lain WHERE bayar_id = bayar.id) AS detail_count\n        FROM bayar WHERE id = ?\n    ");
    $stmtLegacyOther->bind_param('i', $firstPaymentId);
    $stmtLegacyOther->execute();
    $legacyOther = $stmtLegacyOther->get_result()->fetch_assoc();
    $stmtLegacyOther->close();
    payment_process_assert(
        abs((float)$legacyOther['U_LAIN'] - 65000.0) < 0.001
        && (int)$legacyOther['detail_count'] === 5
        && str_ends_with((string)$legacyOther['LAIN_LAIN1'], '#1')
        && str_ends_with((string)$legacyOther['LAIN_LAIN4'], '#4')
        && abs((float)$legacyOther['JUMLAH4'] - 14000.0) < 0.001,
        'Input Biaya Lain tidak mencerminkan total dan empat detail pertama ke kolom legacy.'
    );

    $stmtSavings = $koneksi->prepare("
        SELECT
            COALESCE((SELECT SALDO FROM tabungan WHERE NO_INDUK = ?), 0) AS saldo,
            COALESCE((SELECT MASUK FROM transaksi_m WHERE bayar_id = ?), 0) AS linked_saving
    ");
    $stmtSavings->bind_param('si', $noInduk, $firstPaymentId);
    $stmtSavings->execute();
    $savings = $stmtSavings->get_result()->fetch_assoc();
    $stmtSavings->close();
    payment_process_assert(
        abs((float)$savings['saldo']) < 0.001
        && abs((float)$savings['linked_saving']) < 0.001,
        'Pembayaran masih membuat saldo atau jurnal tabungan terkait.'
    );

    $receipt = payment_process_request($baseUrl . '/laporan/cetak_struk.php?id=' . $firstPaymentId, [], $cookies);
    payment_process_assert($receipt['status'] === 200, 'Struk pembayaran tidak dapat dibuka.');
    payment_process_assert(!str_contains($receipt['body'], 'Tabungan'), 'Struk pembayaran masih menampilkan tabungan.');
    payment_process_assert(!str_contains($receipt['body'], 'Sisa SPP'), 'Struk masih menampilkan Sisa SPP pada bagian Sisa Pembayaran.');
    payment_process_assert(str_contains($receipt['body'], 'Administrator'), 'Struk belum menampilkan operator dari ID transaksi.');

    $update = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'aksi' => 'update',
        'id' => $firstPaymentId,
        'tanggal_bayar' => date('Y-m-d'),
        'uang_spp' => 50000,
        'biaya_lain_detail_id' => [0, 0],
        'biaya_lain_tagihan_id' => array_slice($otherFeeBillIds, 0, 2),
        'biaya_lain_nominal' => [21000, 22000],
        'biaya_lain_keterangan' => ['', ''],
    ]), $cookies);
    payment_process_assert($update['status'] === 302, 'Edit cicilan tidak mengembalikan redirect yang diharapkan.');

    $stmtAfterEdit = $koneksi->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(U_SPP), 0) AS paid
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtAfterEdit->bind_param('s', $noInduk);
    $stmtAfterEdit->execute();
    $afterEdit = $stmtAfterEdit->get_result()->fetch_assoc();
    $stmtAfterEdit->close();
    payment_process_assert((int)$afterEdit['total'] === 2 && abs((float)$afterEdit['paid'] - 200000.0) < 0.001, 'Edit cicilan tidak menyesuaikan total menjadi Rp200.000.');

    $stmtLegacyEdit = $koneksi->prepare("\n        SELECT U_LAIN, LAIN_LAIN1, JUMLAH1, LAIN_LAIN2, JUMLAH2,\n               LAIN_LAIN3, JUMLAH3, LAIN_LAIN4, JUMLAH4,\n               (SELECT COUNT(*) FROM bayar_biaya_lain WHERE bayar_id = bayar.id) AS detail_count\n        FROM bayar WHERE id = ?\n    ");
    $stmtLegacyEdit->bind_param('i', $firstPaymentId);
    $stmtLegacyEdit->execute();
    $legacyEdit = $stmtLegacyEdit->get_result()->fetch_assoc();
    $stmtLegacyEdit->close();
    payment_process_assert(
        abs((float)$legacyEdit['U_LAIN'] - 43000.0) < 0.001
        && (int)$legacyEdit['detail_count'] === 2
        && abs((float)$legacyEdit['JUMLAH2'] - 22000.0) < 0.001
        && $legacyEdit['LAIN_LAIN3'] === null
        && abs((float)$legacyEdit['JUMLAH3']) < 0.001
        && $legacyEdit['LAIN_LAIN4'] === null
        && abs((float)$legacyEdit['JUMLAH4']) < 0.001,
        'Edit Biaya Lain tidak memperbarui total atau membersihkan slot legacy yang tidak dipakai.'
    );

    $stmtSavingsEdit = $koneksi->prepare("
        SELECT
            COALESCE((SELECT SALDO FROM tabungan WHERE NO_INDUK = ?), 0) AS saldo,
            COALESCE((SELECT MASUK FROM transaksi_m WHERE bayar_id = ?), 0) AS linked_saving
    ");
    $stmtSavingsEdit->bind_param('si', $noInduk, $firstPaymentId);
    $stmtSavingsEdit->execute();
    $savingsEdit = $stmtSavingsEdit->get_result()->fetch_assoc();
    $stmtSavingsEdit->close();
    payment_process_assert(
        abs((float)$savingsEdit['saldo']) < 0.001
        && abs((float)$savingsEdit['linked_saving']) < 0.001,
        'Edit pembayaran masih membuat saldo atau jurnal tabungan terkait.'
    );

    $finalInstallment = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + ['uang_spp' => 50000], $cookies);
    payment_process_assert($finalInstallment['status'] === 302, 'Pelunasan sisa setelah edit gagal disimpan.');
    $stmtLast = $koneksi->prepare('SELECT MAX(id) AS id FROM bayar WHERE NO_INDUK = ?');
    $stmtLast->bind_param('s', $noInduk);
    $stmtLast->execute();
    $lastPaymentId = (int)$stmtLast->get_result()->fetch_assoc()['id'];
    $stmtLast->close();

    $delete = payment_process_request($baseUrl . '/pembayaran/proses.php?aksi=hapus&id=' . $lastPaymentId, [], $cookies);
    payment_process_assert($delete['status'] === 302, 'Hapus cicilan tidak mengembalikan redirect yang diharapkan.');
    $stmtAfterDelete = $koneksi->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(U_SPP), 0) AS paid,
               (SELECT COUNT(*) FROM bayar_spp_periode WHERE no_induk = ? AND tahun = '2026' AND bulan = '08') AS claims
        FROM bayar
        WHERE NO_INDUK = ? AND TAHUN = '2026'
          AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')
    ");
    $stmtAfterDelete->bind_param('ss', $noInduk, $noInduk);
    $stmtAfterDelete->execute();
    $afterDelete = $stmtAfterDelete->get_result()->fetch_assoc();
    $stmtAfterDelete->close();
    payment_process_assert(
        (int)$afterDelete['total'] === 2
        && abs((float)$afterDelete['paid'] - 200000.0) < 0.001
        && (int)$afterDelete['claims'] === 2,
        'Hapus cicilan tidak memulihkan total atau pemetaan periode.'
    );

    $move = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'aksi' => 'update',
        'id' => $firstPaymentId,
        'no_induk' => $targetNoInduk,
        'tanggal_bayar' => date('Y-m-d'),
        'bulan_bayar' => '07',
        'uang_spp' => 50000,
    ]), $cookies);
    payment_process_assert($move['status'] === 302, 'Pemindahan cicilan ke siswa atau periode lain gagal.');
    $moveFeedback = payment_process_request($baseUrl . '/pembayaran/lihat.php', [], $cookies);
    $moveMessage = '';
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $moveFeedback['body'], $match)) {
        $moveMessage = trim(html_entity_decode(strip_tags($match[1])));
    }
    $stmtMoved = $koneksi->prepare("
        SELECT
          (SELECT COALESCE(SUM(U_SPP), 0) FROM bayar WHERE NO_INDUK = ? AND TAHUN = '2026' AND (BULAN = '08' OR BULAN = '8' OR BULAN = 'Agustus')) AS old_paid,
          (SELECT COALESCE(SUM(U_SPP), 0) FROM bayar WHERE NO_INDUK = ? AND TAHUN = '2026' AND (BULAN = '07' OR BULAN = '7' OR BULAN = 'Juli')) AS new_paid,
          (SELECT COUNT(*) FROM bayar_spp_periode WHERE no_induk = ? AND bulan = '08') AS old_claims,
          (SELECT COUNT(*) FROM bayar_spp_periode WHERE no_induk = ? AND bulan = '07') AS new_claims
    ");
    $stmtMoved->bind_param('ssss', $noInduk, $targetNoInduk, $noInduk, $targetNoInduk);
    $stmtMoved->execute();
    $moved = $stmtMoved->get_result()->fetch_assoc();
    $stmtMoved->close();
    payment_process_assert(
        abs((float)$moved['old_paid'] - 150000.0) < 0.001
        && abs((float)$moved['new_paid'] - 50000.0) < 0.001
        && (int)$moved['old_claims'] === 1
        && (int)$moved['new_claims'] === 1,
        'Pemindahan cicilan tidak memperbarui siswa lama, siswa baru, atau periode: '
        . json_encode($moved) . ($moveMessage !== '' ? ' | ' . $moveMessage : '')
    );

    $deleteMoved = payment_process_request($baseUrl . '/pembayaran/proses.php?aksi=hapus&id=' . $firstPaymentId, [], $cookies);
    payment_process_assert($deleteMoved['status'] === 302, 'Hapus pembayaran tidak mengembalikan redirect yang diharapkan.');
    $stmtSavingsDelete = $koneksi->prepare('SELECT COUNT(*) AS linked_count FROM transaksi_m WHERE bayar_id = ?');
    $stmtSavingsDelete->bind_param('i', $firstPaymentId);
    $stmtSavingsDelete->execute();
    $linkedAfterDelete = (int)$stmtSavingsDelete->get_result()->fetch_assoc()['linked_count'];
    $stmtSavingsDelete->close();
    payment_process_assert(
        $linkedAfterDelete === 0,
        'Hapus pembayaran masih meninggalkan jurnal tabungan terkait.'
    );
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $stmtPaymentCleanup=$koneksi->prepare('DELETE FROM bayar WHERE NO_INDUK IN (?,?)');$stmtPaymentCleanup->bind_param('ss',$noInduk,$targetNoInduk);$stmtPaymentCleanup->execute();$stmtPaymentCleanup->close();
    $stmtBillCleanup=$koneksi->prepare('DELETE FROM tagihan_biaya_lain WHERE no_induk IN (?,?)');$stmtBillCleanup->bind_param('ss',$noInduk,$targetNoInduk);$stmtBillCleanup->execute();$stmtBillCleanup->close();
    $stmtCleanup = $koneksi->prepare("
        DELETE FROM siswa
        WHERE (NO_INDUK = ? AND NAMA = 'UJI INTEGRASI CICILAN')
           OR (NO_INDUK = ? AND NAMA = 'UJI TARGET CICILAN')
    ");
    $stmtCleanup->bind_param('ss', $noInduk, $targetNoInduk);
    $stmtCleanup->execute();
    $stmtCleanup->close();
    if ($otherFeeMasterIds) {
        $placeholders = implode(',', array_fill(0, count($otherFeeMasterIds), '?'));
        $types = str_repeat('i', count($otherFeeMasterIds));
        $stmtMasterCleanup = $koneksi->prepare("DELETE FROM master_biaya_lain WHERE id IN ($placeholders)");
        $stmtMasterCleanup->bind_param($types, ...$otherFeeMasterIds);
        $stmtMasterCleanup->execute();
        $stmtMasterCleanup->close();
    }
}

$stmtRemaining = $koneksi->prepare('SELECT COUNT(*) AS total FROM siswa WHERE NO_INDUK IN (?, ?)');
$stmtRemaining->bind_param('ss', $noInduk, $targetNoInduk);
$stmtRemaining->execute();
$remaining = (int)$stmtRemaining->get_result()->fetch_assoc()['total'];
$stmtRemaining->close();
if ($remaining !== 0) {
    fwrite(STDERR, "FAILED: data siswa uji tidak terhapus.\n");
    exit(1);
}
if ($failure) {
    fwrite(STDERR, 'FAILED: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "OK: endpoint cicilan menangani input, overlimit, edit, pindah periode/siswa, hapus, tanggal server, penolakan tabungan legacy, Biaya Lain legacy, dan struk.\n";
