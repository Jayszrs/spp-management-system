-- =========================================================
-- Migrasi relasi eksplisit pembayaran ke Daftar Ulang dan
-- tabungan wajib. Aman dijalankan ulang pada database lama.
-- =========================================================

USE `db_spp`;

DELIMITER $$
DROP PROCEDURE IF EXISTS `migrate_payment_references`$$
CREATE PROCEDURE `migrate_payment_references`()
BEGIN
  -- Versi 0 berarti pembayaran legacy yang hubungan child-nya tidak
  -- boleh ditebak. Pembayaran baru akan ditandai versi 1 oleh aplikasi.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
      AND COLUMN_NAME = 'payment_link_version'
  ) THEN
    ALTER TABLE `bayar`
      ADD COLUMN `payment_link_version` TINYINT UNSIGNED NOT NULL DEFAULT 0
      AFTER `total_jumlah`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
      AND COLUMN_NAME = 'bayar_id'
  ) THEN
    ALTER TABLE `bayar_du`
      ADD COLUMN `bayar_id` INT DEFAULT NULL AFTER `id`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
      AND INDEX_NAME = 'uk_bayar_du_bayar_id'
  ) THEN
    ALTER TABLE `bayar_du`
      ADD UNIQUE KEY `uk_bayar_du_bayar_id` (`bayar_id`);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
      AND CONSTRAINT_NAME = 'fk_bayar_du_bayar'
  ) THEN
    ALTER TABLE `bayar_du`
      ADD CONSTRAINT `fk_bayar_du_bayar`
      FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`)
      ON DELETE CASCADE ON UPDATE CASCADE;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
      AND COLUMN_NAME = 'bayar_id'
  ) THEN
    ALTER TABLE `transaksi_m`
      ADD COLUMN `bayar_id` INT DEFAULT NULL AFTER `id`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
      AND INDEX_NAME = 'uk_transaksi_m_bayar_id'
  ) THEN
    ALTER TABLE `transaksi_m`
      ADD UNIQUE KEY `uk_transaksi_m_bayar_id` (`bayar_id`);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
      AND CONSTRAINT_NAME = 'fk_transaksi_m_bayar'
  ) THEN
    ALTER TABLE `transaksi_m`
      ADD CONSTRAINT `fk_transaksi_m_bayar`
      FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`)
      ON DELETE CASCADE ON UPDATE CASCADE;
  END IF;
END$$
CALL `migrate_payment_references`()$$
DROP PROCEDURE `migrate_payment_references`$$
DELIMITER ;

-- Tidak ada backfill otomatis. Semua pembayaran yang sudah ada tetap
-- payment_link_version = 0 dan child lama tetap bayar_id = NULL.
