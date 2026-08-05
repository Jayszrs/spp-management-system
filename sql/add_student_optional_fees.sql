-- =========================================================
-- Tarif satu kali Uang Makan, Sorga, dan Infaq per siswa
-- Database: db_spp
-- Idempoten: aman dijalankan lebih dari satu kali
-- =========================================================

USE `db_spp`;

ALTER TABLE `siswa`
  ADD COLUMN IF NOT EXISTS `MAKAN` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `KEGIATAN`,
  ADD COLUMN IF NOT EXISTS `SORGA` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `MAKAN`,
  ADD COLUMN IF NOT EXISTS `INFAQ` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `SORGA`;
