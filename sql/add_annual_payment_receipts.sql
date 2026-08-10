-- =========================================================
-- Dukungan pembayaran SPP tahunan menjadi 12 struk terpisah.
-- Aman dijalankan ulang pada database lama.
-- =========================================================

USE `db_spp`;

DELIMITER $$
DROP PROCEDURE IF EXISTS `migrate_annual_payment_receipts`$$
CREATE PROCEDURE `migrate_annual_payment_receipts`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
      AND COLUMN_NAME = 'payment_batch_token'
  ) THEN
    ALTER TABLE `bayar`
      ADD COLUMN `payment_batch_token` CHAR(32) DEFAULT NULL AFTER `payment_link_version`,
      ADD COLUMN `payment_batch_sequence` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `payment_batch_token`,
      ADD COLUMN `payment_batch_count` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `payment_batch_sequence`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
      AND INDEX_NAME = 'idx_bayar_payment_batch'
  ) THEN
    ALTER TABLE `bayar`
      ADD KEY `idx_bayar_payment_batch` (`payment_batch_token`, `payment_batch_sequence`);
  END IF;

  CREATE TABLE IF NOT EXISTS `bayar_spp_periode` (
    `bayar_id` INT NOT NULL,
    `no_induk` VARCHAR(10) NOT NULL,
    `bulan` CHAR(2) NOT NULL,
    `tahun` CHAR(4) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`bayar_id`),
    KEY `idx_bayar_spp_siswa_periode` (`no_induk`, `tahun`, `bulan`),
    CONSTRAINT `fk_bayar_spp_periode_bayar`
      FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`)
      ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bayar_spp_periode_siswa`
      FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`)
      ON DELETE CASCADE ON UPDATE CASCADE
  ) ENGINE=InnoDB;
END$$
CALL `migrate_annual_payment_receipts`()$$
DROP PROCEDURE `migrate_annual_payment_receipts`$$
DELIMITER ;

-- Backfill pemetaan periode untuk setiap transaksi SPP lama.
INSERT IGNORE INTO `bayar_spp_periode` (`bayar_id`, `no_induk`, `bulan`, `tahun`)
SELECT
  b.id,
  b.NO_INDUK,
  CASE b.BULAN
    WHEN 'Januari' THEN '01' WHEN 'Februari' THEN '02' WHEN 'Maret' THEN '03'
    WHEN 'April' THEN '04' WHEN 'Mei' THEN '05' WHEN 'Juni' THEN '06'
    WHEN 'Juli' THEN '07' WHEN 'Agustus' THEN '08' WHEN 'September' THEN '09'
    WHEN 'Oktober' THEN '10' WHEN 'November' THEN '11' WHEN 'Desember' THEN '12'
    ELSE LPAD(CAST(b.BULAN AS UNSIGNED), 2, '0')
  END,
  b.TAHUN
FROM `bayar` b
WHERE b.NO_INDUK IS NOT NULL
  AND b.U_SPP > 0
  AND b.TAHUN REGEXP '^[0-9]{4}$'
  AND (b.BULAN IN ('01','02','03','04','05','06','07','08','09','10','11','12')
       OR b.BULAN IN ('Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'));
