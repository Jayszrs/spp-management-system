-- =========================================================
-- Migrasi master dan detail biaya lain untuk database db_spp
-- Aman dijalankan ulang: CREATE IF NOT EXISTS + legacy_key unik
-- =========================================================

USE `db_spp`;

CREATE TABLE IF NOT EXISTS `master_biaya_lain` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `nama`       VARCHAR(100) NOT NULL UNIQUE,
  `nominal`    DECIMAL(15,2) NOT NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `chk_master_biaya_lain_nominal` CHECK (`nominal` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bayar_biaya_lain` (
  `id`                         INT AUTO_INCREMENT PRIMARY KEY,
  `bayar_id`                   INT NOT NULL,
  `master_biaya_lain_id`       INT DEFAULT NULL,
  `nama_biaya_snapshot`        VARCHAR(100) NOT NULL,
  `nominal_snapshot`           DECIMAL(15,2) NOT NULL,
  `keterangan`                 VARCHAR(255) DEFAULT NULL,
  `urutan`                     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `legacy_key`                 VARCHAR(10) DEFAULT NULL,
  `created_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_bayar_biaya_lain_legacy` (`bayar_id`, `legacy_key`),
  KEY `idx_bayar_biaya_lain_master` (`master_biaya_lain_id`),
  CONSTRAINT `fk_bayar_biaya_lain_bayar`
    FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_biaya_lain_master`
    FOREIGN KEY (`master_biaya_lain_id`) REFERENCES `master_biaya_lain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrasikan nilai U_LAIN lama sebagai satu detail transaksi.
INSERT INTO `bayar_biaya_lain`
  (`bayar_id`, `master_biaya_lain_id`, `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`)
SELECT `id`, NULL, 'Uang Lain Lama', `U_LAIN`, NULL, 1, 'U_LAIN'
FROM `bayar`
WHERE COALESCE(`U_LAIN`, 0) > 0
ON DUPLICATE KEY UPDATE `legacy_key` = VALUES(`legacy_key`);

-- Migrasikan empat slot lain-lain lama. Nama lama mungkin telah terpotong
-- karena kolom asal hanya VARCHAR(5), tetapi nominal tetap dipertahankan.
INSERT INTO `bayar_biaya_lain`
  (`bayar_id`, `master_biaya_lain_id`, `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`)
SELECT `id`, NULL, COALESCE(NULLIF(TRIM(`LAIN_LAIN1`), ''), 'Biaya Lain Lama'), `JUMLAH1`, NULL, 2, 'LL1'
FROM `bayar` WHERE COALESCE(`JUMLAH1`, 0) > 0
ON DUPLICATE KEY UPDATE `legacy_key` = VALUES(`legacy_key`);

INSERT INTO `bayar_biaya_lain`
  (`bayar_id`, `master_biaya_lain_id`, `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`)
SELECT `id`, NULL, COALESCE(NULLIF(TRIM(`LAIN_LAIN2`), ''), 'Biaya Lain Lama'), `JUMLAH2`, NULL, 3, 'LL2'
FROM `bayar` WHERE COALESCE(`JUMLAH2`, 0) > 0
ON DUPLICATE KEY UPDATE `legacy_key` = VALUES(`legacy_key`);

INSERT INTO `bayar_biaya_lain`
  (`bayar_id`, `master_biaya_lain_id`, `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`)
SELECT `id`, NULL, COALESCE(NULLIF(TRIM(`LAIN_LAIN3`), ''), 'Biaya Lain Lama'), `JUMLAH3`, NULL, 4, 'LL3'
FROM `bayar` WHERE COALESCE(`JUMLAH3`, 0) > 0
ON DUPLICATE KEY UPDATE `legacy_key` = VALUES(`legacy_key`);

INSERT INTO `bayar_biaya_lain`
  (`bayar_id`, `master_biaya_lain_id`, `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`)
SELECT `id`, NULL, COALESCE(NULLIF(TRIM(`LAIN_LAIN4`), ''), 'Biaya Lain Lama'), `JUMLAH4`, NULL, 5, 'LL4'
FROM `bayar` WHERE COALESCE(`JUMLAH4`, 0) > 0
ON DUPLICATE KEY UPDATE `legacy_key` = VALUES(`legacy_key`);
