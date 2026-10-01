<?php
/** Read-only preflight: expected result supplied by the caller. */
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../includes/savings_notes.php';
$expected = getenv('SPP_EXPECT_SAVINGS_NOTES');
if (!in_array($expected, ['0', '1'], true)) {
    throw new RuntimeException('Set SPP_EXPECT_SAVINGS_NOTES=0 atau 1.');
}
$ready = savings_notes_ready($koneksi);
if ($ready !== ($expected === '1')) {
    throw new RuntimeException('Status migrasi keterangan tabungan tidak sesuai ekspektasi untuk database ' . DB_NAME);
}
echo 'OK: preflight catatan tabungan ' . ($ready ? 'siap' : 'belum siap') . ' pada ' . DB_NAME . ".\n";
