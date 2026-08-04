-- =========================================================
-- Migrasi master Daftar Ulang per kelas dan tahun ajaran
-- =========================================================

USE `db_spp`;

DELIMITER $$

DROP PROCEDURE IF EXISTS `migrate_master_daftar_ulang`$$
CREATE PROCEDURE `migrate_master_daftar_ulang`()
BEGIN
  CREATE TABLE IF NOT EXISTS `Daftar_ulang` (
    `id`        INT AUTO_INCREMENT PRIMARY KEY,
    `th_ajaran` CHAR(9) DEFAULT NULL,
    `kelas`     CHAR(1) DEFAULT NULL,
    `Jumlah`    DECIMAL(18,2) DEFAULT 0
  ) ENGINE=InnoDB;

  DELETE du_old
  FROM `Daftar_ulang` du_old
  JOIN `Daftar_ulang` du_new
    ON du_old.th_ajaran = du_new.th_ajaran
   AND du_old.kelas = du_new.kelas
   AND du_old.id < du_new.id
  WHERE du_old.th_ajaran IS NOT NULL AND du_old.th_ajaran <> ''
    AND du_old.kelas IS NOT NULL AND du_old.kelas <> '';

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND LOWER(TABLE_NAME) = 'daftar_ulang'
      AND INDEX_NAME = 'uk_daftar_ulang_period_class'
      AND NON_UNIQUE = 0
  ) THEN
    ALTER TABLE `Daftar_ulang`
      ADD UNIQUE KEY `uk_daftar_ulang_period_class` (`th_ajaran`, `kelas`);
  END IF;
END$$

CALL `migrate_master_daftar_ulang`()$$
DROP PROCEDURE `migrate_master_daftar_ulang`$$

DELIMITER ;
