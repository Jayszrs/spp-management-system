<?php

/** Kedua jurnal harus mengekspos kolom melalui tabel/view yang dipakai aplikasi. */
function savings_notes_ready(mysqli $db): bool {
    $result = $db->query("SELECT COUNT(*) n FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('transaksi_m','transaksi_k')
          AND COLUMN_NAME='keterangan'");
    return (int)$result->fetch_assoc()['n'] === 2;
}
