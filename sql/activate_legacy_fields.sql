-- =========================================================
-- Aktivasi field legacy tanpa mengganti alur transaksi baru.
-- Aman dijalankan ulang pada database db_spp.
-- =========================================================

USE `db_spp`;

DROP PROCEDURE IF EXISTS `activate_legacy_fields`;
DELIMITER $$
CREATE PROCEDURE `activate_legacy_fields`()
BEGIN
  DECLARE duplicate_diknas INT DEFAULT 0;
  DECLARE daftar_ulang_conflicts INT DEFAULT 0;
  DECLARE current_ta CHAR(9);

  SET current_ta = IF(
    MONTH(CURDATE()) >= 7,
    CONCAT(YEAR(CURDATE()), '/', YEAR(CURDATE()) + 1),
    CONCAT(YEAR(CURDATE()) - 1, '/', YEAR(CURDATE()))
  );

  SELECT COUNT(*) INTO duplicate_diknas
  FROM (
    SELECT `NO_induk_diknas`
    FROM `siswa`
    WHERE `NO_induk_diknas` IS NOT NULL AND TRIM(`NO_induk_diknas`) <> ''
    GROUP BY `NO_induk_diknas`
    HAVING COUNT(*) > 1
  ) duplicate_values;

  IF duplicate_diknas > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migrasi dibatalkan: terdapat No. Induk Diknas duplikat.';
  END IF;

  SELECT COUNT(*) INTO daftar_ulang_conflicts
  FROM `siswa` s
  JOIN `tagihan_daftar_ulang` tdu
    ON tdu.no_induk = s.NO_INDUK
   AND tdu.tahun_ajaran_snapshot = current_ta
   AND tdu.status = 'open'
  LEFT JOIN (
    SELECT tagihan_daftar_ulang_id, SUM(jumlah) AS paid
    FROM `bayar_du`
    GROUP BY tagihan_daftar_ulang_id
  ) paid ON paid.tagihan_daftar_ulang_id = tdu.id
  WHERE (s.DAFTAR_ULANG > 0 OR s.potong_du > 0 OR s.tot_du > 0)
    AND GREATEST(s.DAFTAR_ULANG - s.potong_du, 0) + 0.001 < COALESCE(paid.paid, 0);

  IF daftar_ulang_conflicts > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migrasi dibatalkan: override Daftar Ulang lebih kecil dari pembayaran yang sudah masuk.';
  END IF;

  ALTER TABLE `bayar`
    MODIFY `KETERANGAN` VARCHAR(255) DEFAULT NULL,
    MODIFY `user_id` VARCHAR(100) DEFAULT NULL,
    MODIFY `LAIN_LAIN1` VARCHAR(100) DEFAULT NULL,
    MODIFY `LAIN_LAIN2` VARCHAR(100) DEFAULT NULL,
    MODIFY `LAIN_LAIN3` VARCHAR(100) DEFAULT NULL,
    MODIFY `LAIN_LAIN4` VARCHAR(100) DEFAULT NULL;

  ALTER TABLE `transaksi_m`
    MODIFY `user_id` VARCHAR(100) DEFAULT NULL;

  ALTER TABLE `transaksi_k`
    MODIFY `user_id` VARCHAR(100) DEFAULT NULL;

  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'siswa'
      AND INDEX_NAME = 'uk_siswa_no_induk_diknas'
  ) THEN
    ALTER TABLE `siswa`
      ADD UNIQUE KEY `uk_siswa_no_induk_diknas` (`NO_induk_diknas`);
  END IF;

  START TRANSACTION;

  UPDATE `siswa`
  SET `tot_pangkal` = GREATEST(`PANGKAL` - `potong_pangkal`, 0),
      `tot_du` = GREATEST(`DAFTAR_ULANG` - `potong_du`, 0);

  UPDATE `tagihan_daftar_ulang` tdu
  JOIN `siswa` s ON s.NO_INDUK = tdu.no_induk
  SET tdu.nominal_awal = s.DAFTAR_ULANG,
      tdu.nominal_tagihan = s.tot_du
  WHERE tdu.tahun_ajaran_snapshot = current_ta
    AND tdu.status = 'open'
    AND (s.DAFTAR_ULANG > 0 OR s.potong_du > 0 OR s.tot_du > 0);

  UPDATE `siswa` s
  JOIN `tagihan_daftar_ulang` tdu
    ON tdu.no_induk = s.NO_INDUK
   AND tdu.tahun_ajaran_snapshot = current_ta
   AND tdu.status = 'open'
  SET s.DAFTAR_ULANG = tdu.nominal_awal,
      s.potong_du = GREATEST(tdu.nominal_awal - tdu.nominal_tagihan, 0),
      s.tot_du = tdu.nominal_tagihan;

  DROP TEMPORARY TABLE IF EXISTS `tmp_legacy_biaya_lain`;
  CREATE TEMPORARY TABLE `tmp_legacy_biaya_lain` AS
  SELECT bayar_id,
         SUM(nominal_snapshot) AS total_nominal,
         MAX(CASE WHEN row_number = 1 THEN nama_biaya_snapshot END) AS nama_1,
         MAX(CASE WHEN row_number = 1 THEN nominal_snapshot END) AS nominal_1,
         MAX(CASE WHEN row_number = 2 THEN nama_biaya_snapshot END) AS nama_2,
         MAX(CASE WHEN row_number = 2 THEN nominal_snapshot END) AS nominal_2,
         MAX(CASE WHEN row_number = 3 THEN nama_biaya_snapshot END) AS nama_3,
         MAX(CASE WHEN row_number = 3 THEN nominal_snapshot END) AS nominal_3,
         MAX(CASE WHEN row_number = 4 THEN nama_biaya_snapshot END) AS nama_4,
         MAX(CASE WHEN row_number = 4 THEN nominal_snapshot END) AS nominal_4
  FROM (
    SELECT detail.*,
           ROW_NUMBER() OVER (PARTITION BY bayar_id ORDER BY urutan, id) AS row_number
    FROM `bayar_biaya_lain` detail
  ) ordered_details
  GROUP BY bayar_id;

  UPDATE `bayar` b
  LEFT JOIN `tmp_legacy_biaya_lain` legacy ON legacy.bayar_id = b.id
  SET b.U_LAIN = COALESCE(legacy.total_nominal, 0),
      b.LAIN_LAIN1 = legacy.nama_1,
      b.JUMLAH1 = COALESCE(legacy.nominal_1, 0),
      b.LAIN_LAIN2 = legacy.nama_2,
      b.JUMLAH2 = COALESCE(legacy.nominal_2, 0),
      b.LAIN_LAIN3 = legacy.nama_3,
      b.JUMLAH3 = COALESCE(legacy.nominal_3, 0),
      b.LAIN_LAIN4 = legacy.nama_4,
      b.JUMLAH4 = COALESCE(legacy.nominal_4, 0);

  DROP TEMPORARY TABLE `tmp_legacy_biaya_lain`;
  COMMIT;
END$$
DELIMITER ;

CALL `activate_legacy_fields`();
DROP PROCEDURE `activate_legacy_fields`;

-- Read-only reconciliation output. Rows indicate data that needs review.
SELECT s.NO_INDUK,
       s.PANGKAL_BAYAR, COALESCE(SUM(b.U_PANGKAL), 0) AS transaksi_pangkal,
       s.BANGUNAN_BAYAR, COALESCE(SUM(b.U_BANGUNAN), 0) AS transaksi_bangunan,
       s.SERAGAM_BAYAR, COALESCE(SUM(b.U_SERAGAM), 0) AS transaksi_seragam,
       s.KEGIATAN_BAYAR, COALESCE(SUM(b.U_KEGIATAN), 0) AS transaksi_kegiatan
FROM `siswa` s
LEFT JOIN `bayar` b ON b.NO_INDUK = s.NO_INDUK
GROUP BY s.NO_INDUK, s.PANGKAL_BAYAR, s.BANGUNAN_BAYAR,
         s.SERAGAM_BAYAR, s.KEGIATAN_BAYAR
HAVING ABS(s.PANGKAL_BAYAR - COALESCE(SUM(b.U_PANGKAL), 0)) > 0.001
    OR ABS(s.BANGUNAN_BAYAR - COALESCE(SUM(b.U_BANGUNAN), 0)) > 0.001
    OR ABS(s.SERAGAM_BAYAR - COALESCE(SUM(b.U_SERAGAM), 0)) > 0.001
    OR ABS(s.KEGIATAN_BAYAR - COALESCE(SUM(b.U_KEGIATAN), 0)) > 0.001;
