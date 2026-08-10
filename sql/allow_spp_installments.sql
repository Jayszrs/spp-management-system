-- =========================================================
-- Izinkan cicilan SPP pada bulan yang sama.
-- Aman dijalankan ulang pada database lama.
-- =========================================================

USE `db_spp`;

DELIMITER $$
DROP PROCEDURE IF EXISTS `migrate_spp_installments`$$
CREATE PROCEDURE `migrate_spp_installments`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bayar_spp_periode'
      AND INDEX_NAME = 'idx_bayar_spp_siswa_periode'
  ) THEN
    ALTER TABLE `bayar_spp_periode`
      ADD KEY `idx_bayar_spp_siswa_periode` (`no_induk`, `tahun`, `bulan`);
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bayar_spp_periode'
      AND INDEX_NAME = 'uk_bayar_spp_siswa_periode'
  ) THEN
    ALTER TABLE `bayar_spp_periode`
      DROP INDEX `uk_bayar_spp_siswa_periode`;
  END IF;
END$$
CALL `migrate_spp_installments`()$$
DROP PROCEDURE `migrate_spp_installments`$$
DELIMITER ;

INSERT INTO `bayar_spp_periode` (`bayar_id`, `no_induk`, `bulan`, `tahun`)
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
       OR b.BULAN IN ('1','2','3','4','5','6','7','8','9')
       OR b.BULAN IN ('Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'))
ON DUPLICATE KEY UPDATE
  `no_induk` = VALUES(`no_induk`),
  `bulan` = VALUES(`bulan`),
  `tahun` = VALUES(`tahun`);
