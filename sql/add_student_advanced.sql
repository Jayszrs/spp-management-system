-- =========================================================
-- Migrasi Master Siswa Advance dan komponen Uang Komite
-- Database: db_spp
-- =========================================================

USE `db_spp`;

DELIMITER $$
DROP PROCEDURE IF EXISTS `migrate_student_advanced`$$
CREATE PROCEDURE `migrate_student_advanced`()
BEGIN
  IF EXISTS (
    SELECT 1 FROM `siswa`
    WHERE `KELAS` IS NULL OR TRIM(`KELAS`) NOT IN ('1','2','3','4','5','6')
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migrasi dibatalkan: masih ada kelas siswa di luar 1 sampai 6.';
  END IF;

  ALTER TABLE `siswa`
    MODIFY `NAMA` VARCHAR(100) NOT NULL,
    MODIFY `KELAS` CHAR(1) NOT NULL,
    MODIFY `SPP_PERBULAN` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `PANGKAL` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `BANGUNAN` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `SERAGAM` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `KEGIATAN` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `PANGKAL_BAYAR` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `BANGUNAN_BAYAR` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `SERAGAM_BAYAR` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `KEGIATAN_BAYAR` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `POMG` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `DAFTAR_ULANG` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `potong_pangkal` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `tot_pangkal` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `tot_du` DECIMAL(15,2) NOT NULL DEFAULT 0,
    MODIFY `potong_du` DECIMAL(15,2) NOT NULL DEFAULT 0;

  ALTER TABLE `siswa`
    ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `potong_du`;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
      AND INDEX_NAME = 'idx_siswa_status_kelas_nama'
  ) THEN
    ALTER TABLE `siswa`
      ADD INDEX `idx_siswa_status_kelas_nama` (`is_active`, `KELAS`, `NAMA`);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
      AND CONSTRAINT_NAME = 'chk_siswa_kelas_sd'
  ) THEN
    ALTER TABLE `siswa`
      ADD CONSTRAINT `chk_siswa_kelas_sd`
      CHECK (`KELAS` IN ('1','2','3','4','5','6'));
  END IF;

  ALTER TABLE `bayar`
    ADD COLUMN IF NOT EXISTS `U_KOMITE` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `U_INFAQ`;
END$$
CALL `migrate_student_advanced`()$$
DROP PROCEDURE `migrate_student_advanced`$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS `siswa_audit_log` (
  `id`                BIGINT AUTO_INCREMENT PRIMARY KEY,
  `siswa_id`          INT DEFAULT NULL,
  `no_induk_snapshot` VARCHAR(10) NOT NULL,
  `aksi`              VARCHAR(30) NOT NULL,
  `before_data`       LONGTEXT DEFAULT NULL,
  `after_data`        LONGTEXT DEFAULT NULL,
  `admin_id`          INT DEFAULT NULL,
  `admin_name`        VARCHAR(100) NOT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_siswa_audit_siswa` (`siswa_id`, `created_at`),
  KEY `idx_siswa_audit_no_induk` (`no_induk_snapshot`, `created_at`),
  CONSTRAINT `fk_siswa_audit_siswa`
    FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_siswa_audit_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sinkronkan nilai turunan tanpa mengubah tarif dasar.
UPDATE `siswa`
SET `tot_pangkal` = GREATEST(0, `PANGKAL` - `potong_pangkal`),
    `tot_du` = GREATEST(0, `DAFTAR_ULANG` - `potong_du`);
