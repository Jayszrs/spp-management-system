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
try {
    $name = 'UJI INTEGRASI CICILAN';
    $class = '1';
    $monthlyFee = 250000.0;
    $stmtStudent = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, SPP_PERBULAN) VALUES (?, ?, ?, ?)');
    $stmtStudent->bind_param('sssd', $noInduk, $name, $class, $monthlyFee);
    $stmtStudent->execute();
    $stmtStudent->close();
    $targetName = 'UJI TARGET CICILAN';
    $stmtTarget = $koneksi->prepare('INSERT INTO siswa (NO_INDUK, NAMA, KELAS, SPP_PERBULAN) VALUES (?, ?, ?, ?)');
    $stmtTarget->bind_param('sssd', $targetNoInduk, $targetName, $class, $monthlyFee);
    $stmtTarget->execute();
    $stmtTarget->close();

    $baseUrl = getenv('SPP_TEST_BASE_URL') ?: 'http://127.0.0.1/Project%20PHP';
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
    $response = payment_process_request($baseUrl . '/pembayaran/proses.php', $common + [
        'uang_spp' => 100000,
        'tabungan_wajib' => 20000,
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

    $stmtFirst = $koneksi->prepare('SELECT MIN(id) AS id FROM bayar WHERE NO_INDUK = ?');
    $stmtFirst->bind_param('s', $noInduk);
    $stmtFirst->execute();
    $firstPaymentId = (int)$stmtFirst->get_result()->fetch_assoc()['id'];
    $stmtFirst->close();

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
        abs((float)$savings['saldo'] - 20000.0) < 0.001
        && abs((float)$savings['linked_saving'] - 20000.0) < 0.001,
        'Tabungan dari input pembayaran tidak tersimpan ke saldo atau jurnal terkait.'
    );

    $receipt = payment_process_request($baseUrl . '/laporan/cetak_struk.php?id=' . $firstPaymentId, [], $cookies);
    payment_process_assert($receipt['status'] === 200, 'Struk pembayaran tidak dapat dibuka.');
    payment_process_assert(str_contains($receipt['body'], 'Tabungan'), 'Struk belum memakai label Tabungan.');
    payment_process_assert(!str_contains($receipt['body'], 'Tabungan Wajib'), 'Struk masih memakai label Tabungan Wajib.');
    payment_process_assert(str_contains($receipt['body'], 'Sisa SPP'), 'Struk belum menampilkan sisa SPP dari database.');

    $update = payment_process_request($baseUrl . '/pembayaran/proses.php', array_merge($common, [
        'aksi' => 'update',
        'id' => $firstPaymentId,
        'tanggal_bayar' => date('Y-m-d'),
        'uang_spp' => 50000,
        'tabungan_wajib' => 5000,
    ]), $cookies);
    payment_process_assert($update['status'] === 302, 'Edit cicilan tidak mengembalikan redirect yang diharapkan.');

    $stmtAfterEdit = $koneksi->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(U_SPP), 0) AS paid FROM bayar WHERE NO_INDUK = ?');
    $stmtAfterEdit->bind_param('s', $noInduk);
    $stmtAfterEdit->execute();
    $afterEdit = $stmtAfterEdit->get_result()->fetch_assoc();
    $stmtAfterEdit->close();
    payment_process_assert((int)$afterEdit['total'] === 2 && abs((float)$afterEdit['paid'] - 200000.0) < 0.001, 'Edit cicilan tidak menyesuaikan total menjadi Rp200.000.');

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
        abs((float)$savingsEdit['saldo'] - 5000.0) < 0.001
        && abs((float)$savingsEdit['linked_saving'] - 5000.0) < 0.001,
        'Edit tabungan pembayaran tidak menyesuaikan saldo atau jurnal terkait.'
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
               (SELECT COUNT(*) FROM bayar_spp_periode WHERE no_induk = ?) AS claims
        FROM bayar WHERE NO_INDUK = ?
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
        'tabungan_wajib' => 7000,
    ]), $cookies);
    payment_process_assert($move['status'] === 302, 'Pemindahan cicilan ke siswa atau periode lain gagal.');
    $moveFeedback = payment_process_request($baseUrl . '/pembayaran/lihat.php', [], $cookies);
    $moveMessage = '';
    if (preg_match('/id="flash-msg"[^>]*>(.*?)<\/div>/s', $moveFeedback['body'], $match)) {
        $moveMessage = trim(html_entity_decode(strip_tags($match[1])));
    }
    $stmtMoved = $koneksi->prepare("
        SELECT
          (SELECT COALESCE(SUM(U_SPP), 0) FROM bayar WHERE NO_INDUK = ?) AS old_paid,
          (SELECT COALESCE(SUM(U_SPP), 0) FROM bayar WHERE NO_INDUK = ?) AS new_paid,
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

    $stmtSavingsMove = $koneksi->prepare("
        SELECT
          COALESCE((SELECT SALDO FROM tabungan WHERE NO_INDUK = ?), 0) AS old_saldo,
          COALESCE((SELECT SALDO FROM tabungan WHERE NO_INDUK = ?), 0) AS new_saldo,
          COALESCE((SELECT MASUK FROM transaksi_m WHERE bayar_id = ?), 0) AS linked_amount,
          COALESCE((SELECT NO_INDUK FROM transaksi_m WHERE bayar_id = ?), '') AS linked_nis
    ");
    $stmtSavingsMove->bind_param('ssii', $noInduk, $targetNoInduk, $firstPaymentId, $firstPaymentId);
    $stmtSavingsMove->execute();
    $savingsMove = $stmtSavingsMove->get_result()->fetch_assoc();
    $stmtSavingsMove->close();
    payment_process_assert(
        abs((float)$savingsMove['old_saldo']) < 0.001
        && abs((float)$savingsMove['new_saldo'] - 7000.0) < 0.001
        && abs((float)$savingsMove['linked_amount'] - 7000.0) < 0.001
        && (string)$savingsMove['linked_nis'] === $targetNoInduk,
        'Pindah transaksi bertabungan tidak memindahkan saldo atau jurnal tabungan.'
    );

    $deleteMoved = payment_process_request($baseUrl . '/pembayaran/proses.php?aksi=hapus&id=' . $firstPaymentId, [], $cookies);
    payment_process_assert($deleteMoved['status'] === 302, 'Hapus pembayaran bertabungan tidak mengembalikan redirect yang diharapkan.');
    $stmtSavingsDelete = $koneksi->prepare("
        SELECT
          COALESCE((SELECT SALDO FROM tabungan WHERE NO_INDUK = ?), 0) AS saldo,
          (SELECT COUNT(*) FROM transaksi_m WHERE bayar_id = ?) AS linked_count
    ");
    $stmtSavingsDelete->bind_param('si', $targetNoInduk, $firstPaymentId);
    $stmtSavingsDelete->execute();
    $savingsDelete = $stmtSavingsDelete->get_result()->fetch_assoc();
    $stmtSavingsDelete->close();
    payment_process_assert(
        abs((float)$savingsDelete['saldo']) < 0.001
        && (int)$savingsDelete['linked_count'] === 0,
        'Hapus pembayaran bertabungan tidak membalik saldo atau menghapus jurnal terkait.'
    );
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $stmtCleanup = $koneksi->prepare("
        DELETE FROM siswa
        WHERE (NO_INDUK = ? AND NAMA = 'UJI INTEGRASI CICILAN')
           OR (NO_INDUK = ? AND NAMA = 'UJI TARGET CICILAN')
    ");
    $stmtCleanup->bind_param('ss', $noInduk, $targetNoInduk);
    $stmtCleanup->execute();
    $stmtCleanup->close();
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

echo "OK: endpoint cicilan menangani input, overlimit, edit, pindah periode/siswa, hapus, tanggal server, tabungan, dan struk.\n";
