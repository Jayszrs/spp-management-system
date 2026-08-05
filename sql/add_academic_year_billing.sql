-- =========================================================
-- Tahun ajaran, penempatan siswa, dan tagihan Daftar Ulang
-- Idempoten untuk instalasi SistemSPP yang sudah berjalan.
-- =========================================================

USE `db_spp`;

CREATE TABLE IF NOT EXISTS `tahun_ajaran` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `label`        CHAR(9) NOT NULL,
  `tanggal_mulai` DATE NOT NULL,
  `tanggal_selesai` DATE NOT NULL,
  `status`       ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME DEFAULT NULL,
  `closed_at`    DATETIME DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tahun_ajaran_label` (`label`),
  CONSTRAINT `chk_tahun_ajaran_dates` CHECK (`tanggal_selesai` > `tanggal_mulai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `siswa_tahun_ajaran` (
  `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tahun_ajaran_id` INT NOT NULL,
  `no_induk`        VARCHAR(10) NOT NULL,
  `kelas`           CHAR(1) NOT NULL,
  `status`          ENUM('aktif','pindah','lulus') NOT NULL DEFAULT 'aktif',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_siswa_tahun_ajaran` (`tahun_ajaran_id`, `no_induk`),
  KEY `idx_penempatan_kelas_status` (`tahun_ajaran_id`, `kelas`, `status`),
  KEY `idx_penempatan_siswa` (`no_induk`, `tahun_ajaran_id`),
  CONSTRAINT `fk_penempatan_tahun_ajaran` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_penempatan_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_penempatan_kelas_sd` CHECK (`kelas` IN ('1','2','3','4','5','6'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `Daftar_ulang`
  ADD COLUMN IF NOT EXISTS `tahun_ajaran_id` INT DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS `tagihan_daftar_ulang` (
  `id`                    BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tahun_ajaran_id`       INT NOT NULL,
  `penempatan_id`         BIGINT NOT NULL,
  `master_daftar_ulang_id` INT DEFAULT NULL,
  `no_induk`              VARCHAR(10) NOT NULL,
  `kelas_snapshot`        CHAR(1) NOT NULL,
  `tahun_ajaran_snapshot` CHAR(9) NOT NULL,
  `nominal_awal`          DECIMAL(18,2) NOT NULL,
  `nominal_tagihan`       DECIMAL(18,2) NOT NULL,
  `status`                ENUM('open','cancelled') NOT NULL DEFAULT 'open',
  `cancel_reason`         VARCHAR(255) DEFAULT NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tagihan_du_siswa_tahun` (`tahun_ajaran_id`, `no_induk`),
  KEY `idx_tagihan_du_status` (`tahun_ajaran_id`, `kelas_snapshot`, `status`),
  KEY `idx_tagihan_du_penempatan` (`penempatan_id`),
  KEY `idx_tagihan_du_master` (`master_daftar_ulang_id`),
  CONSTRAINT `fk_tagihan_du_tahun` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_du_penempatan` FOREIGN KEY (`penempatan_id`) REFERENCES `siswa_tahun_ajaran` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_du_master` FOREIGN KEY (`master_daftar_ulang_id`) REFERENCES `Daftar_ulang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tagihan_du_siswa` FOREIGN KEY (`no_induk`) REFERENCES `siswa` (`NO_INDUK`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_tagihan_du_nominal` CHECK (`nominal_awal` >= 0 AND `nominal_tagihan` >= 0),
  CONSTRAINT `chk_tagihan_du_kelas` CHECK (`kelas_snapshot` IN ('1','2','3','4','5','6'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daftar_ulang_audit_log` (
  `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
  `tahun_ajaran_id` INT DEFAULT NULL,
  `master_id`       INT DEFAULT NULL,
  `aksi`            VARCHAR(40) NOT NULL,
  `before_data`     LONGTEXT DEFAULT NULL,
  `after_data`      LONGTEXT DEFAULT NULL,
  `affected_count`  INT NOT NULL DEFAULT 0,
  `admin_id`        INT DEFAULT NULL,
  `admin_name`      VARCHAR(100) NOT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_du_audit_year` (`tahun_ajaran_id`, `created_at`),
  KEY `idx_du_audit_master` (`master_id`, `created_at`),
  CONSTRAINT `fk_du_audit_year` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_du_audit_master` FOREIGN KEY (`master_id`) REFERENCES `Daftar_ulang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_du_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `bayar_du`
  ADD COLUMN IF NOT EXISTS `tagihan_daftar_ulang_id` BIGINT DEFAULT NULL AFTER `bayar_id`,
  ADD INDEX IF NOT EXISTS `idx_bayar_du_tagihan` (`tagihan_daftar_ulang_id`);

DELIMITER $$
DROP PROCEDURE IF EXISTS `migrate_academic_year_billing`$$
CREATE PROCEDURE `migrate_academic_year_billing`()
BEGIN
  DECLARE current_start INT;
  SET current_start = IF(MONTH(CURDATE()) >= 7, YEAR(CURDATE()), YEAR(CURDATE()) - 1);

  INSERT IGNORE INTO `tahun_ajaran` (`label`, `tanggal_mulai`, `tanggal_selesai`, `status`)
  VALUES (
    CONCAT(current_start, '/', current_start + 1),
    STR_TO_DATE(CONCAT(current_start, '-07-01'), '%Y-%m-%d'),
    STR_TO_DATE(CONCAT(current_start + 1, '-06-30'), '%Y-%m-%d'),
    'draft'
  );

  INSERT IGNORE INTO `tahun_ajaran` (`label`, `tanggal_mulai`, `tanggal_selesai`, `status`)
  SELECT DISTINCT du.th_ajaran,
         STR_TO_DATE(CONCAT(LEFT(du.th_ajaran, 4), '-07-01'), '%Y-%m-%d'),
         STR_TO_DATE(CONCAT(RIGHT(du.th_ajaran, 4), '-06-30'), '%Y-%m-%d'),
         'draft'
  FROM `Daftar_ulang` du
  WHERE du.th_ajaran REGEXP '^[0-9]{4}/[0-9]{4}$';

  INSERT IGNORE INTO `tahun_ajaran` (`label`, `tanggal_mulai`, `tanggal_selesai`, `status`)
  SELECT DISTINCT bd.th_ajaran,
         STR_TO_DATE(CONCAT(LEFT(bd.th_ajaran, 4), '-07-01'), '%Y-%m-%d'),
         STR_TO_DATE(CONCAT(RIGHT(bd.th_ajaran, 4), '-06-30'), '%Y-%m-%d'),
         'published'
  FROM `bayar_du` bd
  WHERE bd.th_ajaran REGEXP '^[0-9]{4}/[0-9]{4}$';

  UPDATE `Daftar_ulang` du
  JOIN `tahun_ajaran` ta ON ta.label = du.th_ajaran
  SET du.tahun_ajaran_id = ta.id
  WHERE du.tahun_ajaran_id IS NULL;

  INSERT IGNORE INTO `siswa_tahun_ajaran` (`tahun_ajaran_id`, `no_induk`, `kelas`, `status`)
  SELECT ta.id, s.NO_INDUK, s.KELAS, 'aktif'
  FROM `siswa` s
  JOIN `tahun_ajaran` ta ON ta.label = CONCAT(current_start, '/', current_start + 1)
  WHERE s.is_active = 1 AND s.KELAS IN ('1','2','3','4','5','6');

  INSERT IGNORE INTO `siswa_tahun_ajaran` (`tahun_ajaran_id`, `no_induk`, `kelas`, `status`)
  SELECT ta.id, bd.no_induk, bd.kelas, 'aktif'
  FROM `bayar_du` bd
  JOIN `tahun_ajaran` ta ON ta.label = bd.th_ajaran
  JOIN `siswa` s ON s.NO_INDUK = bd.no_induk
  WHERE bd.kelas IN ('1','2','3','4','5','6');

  INSERT IGNORE INTO `tagihan_daftar_ulang` (
    `tahun_ajaran_id`, `penempatan_id`, `master_daftar_ulang_id`, `no_induk`,
    `kelas_snapshot`, `tahun_ajaran_snapshot`, `nominal_awal`, `nominal_tagihan`
  )
  SELECT ta.id, sta.id, du.id, sta.no_induk, sta.kelas, ta.label, du.Jumlah, du.Jumlah
  FROM `tahun_ajaran` ta
  JOIN `siswa_tahun_ajaran` sta ON sta.tahun_ajaran_id = ta.id AND sta.status = 'aktif'
  JOIN `Daftar_ulang` du ON du.tahun_ajaran_id = ta.id AND du.kelas = sta.kelas AND du.Jumlah > 0
  WHERE ta.status IN ('published','closed');

  INSERT IGNORE INTO `tagihan_daftar_ulang` (
    `tahun_ajaran_id`, `penempatan_id`, `master_daftar_ulang_id`, `no_induk`,
    `kelas_snapshot`, `tahun_ajaran_snapshot`, `nominal_awal`, `nominal_tagihan`
  )
  SELECT ta.id, sta.id, du.id, bd.no_induk, bd.kelas, bd.th_ajaran,
         GREATEST(COALESCE(du.Jumlah, 0), SUM(bd.jumlah)),
         GREATEST(COALESCE(du.Jumlah, 0), SUM(bd.jumlah))
  FROM `bayar_du` bd
  JOIN `tahun_ajaran` ta ON ta.label = bd.th_ajaran
  JOIN `siswa_tahun_ajaran` sta ON sta.tahun_ajaran_id = ta.id AND sta.no_induk = bd.no_induk
  LEFT JOIN `Daftar_ulang` du ON du.tahun_ajaran_id = ta.id AND du.kelas = bd.kelas
  GROUP BY ta.id, sta.id, du.id, bd.no_induk, bd.kelas, bd.th_ajaran, du.Jumlah;

  UPDATE `bayar_du` bd
  JOIN `tahun_ajaran` ta ON ta.label = bd.th_ajaran
  JOIN `tagihan_daftar_ulang` tdu ON tdu.tahun_ajaran_id = ta.id AND tdu.no_induk = bd.no_induk
  SET bd.tagihan_daftar_ulang_id = tdu.id
  WHERE bd.tagihan_daftar_ulang_id IS NULL;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'Daftar_ulang'
      AND CONSTRAINT_NAME = 'fk_master_du_tahun'
  ) THEN
    ALTER TABLE `Daftar_ulang`
      ADD CONSTRAINT `fk_master_du_tahun` FOREIGN KEY (`tahun_ajaran_id`) REFERENCES `tahun_ajaran` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
      AND CONSTRAINT_NAME = 'fk_bayar_du_tagihan'
  ) THEN
    ALTER TABLE `bayar_du`
      ADD CONSTRAINT `fk_bayar_du_tagihan` FOREIGN KEY (`tagihan_daftar_ulang_id`) REFERENCES `tagihan_daftar_ulang` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
  END IF;
END$$
CALL `migrate_academic_year_billing`()$$
DROP PROCEDURE `migrate_academic_year_billing`$$
DELIMITER ;
