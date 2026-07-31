-- =========================================================
-- Migrasi Sistem Pembayaran
-- Database: db_spp
-- =========================================================

USE `db_spp`;

ALTER TABLE `bayar`
  ADD COLUMN IF NOT EXISTS `sistem_pembayaran` ENUM('Tunai','VA','Qris') NOT NULL DEFAULT 'VA' AFTER `user_id`;

UPDATE `bayar`
SET `sistem_pembayaran` = 'VA'
WHERE `sistem_pembayaran` IS NULL OR `sistem_pembayaran` = '';
