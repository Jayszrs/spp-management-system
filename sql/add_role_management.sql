-- Migrasi untuk instalasi lama yang belum memiliki kolom role.
USE `db_spp`;

ALTER TABLE `admin`
  ADD COLUMN IF NOT EXISTS `role`
  ENUM('admin','bendahara','kasir') NOT NULL DEFAULT 'admin'
  AFTER `nama`;

UPDATE `admin`
SET `role` = 'admin'
WHERE `role` IS NULL OR `role` NOT IN ('admin', 'bendahara', 'kasir');
