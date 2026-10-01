-- Jalankan hanya sesudah backup dan simulasi pada database latihan.
-- MySQL CLI: mysql --database=DB_YANG_DISETUJUI < sql/add_financial_request_guard.sql
-- Satu kunci global per permintaan pembayaran, tabungan, atau pengembalian Titipan SPP.
-- Versi awal tabel menggunakan referensi_id INT; titipan_spp_mutasi.id adalah BIGINT.

DELIMITER //
DROP PROCEDURE IF EXISTS install_keuangan_request_guard//
CREATE PROCEDURE install_keuangan_request_guard()
BEGIN
  DECLARE v_table_count INT DEFAULT 0;
  DECLARE v_valid_table INT DEFAULT 0;
  DECLARE v_column_count INT DEFAULT 0;
  DECLARE v_valid_columns INT DEFAULT 0;
  DECLARE v_valid_indexes INT DEFAULT 0;
  DECLARE v_unique_indexes INT DEFAULT 0;
  DECLARE v_action_type VARCHAR(255);
  DECLARE v_reference_type VARCHAR(64);

  IF DATABASE() IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pilih database tujuan sebelum migrasi keuangan_request.';
  END IF;

  SELECT COUNT(*), COALESCE(SUM(TABLE_TYPE = 'BASE TABLE' AND ENGINE = 'InnoDB'), 0)
    INTO v_table_count, v_valid_table
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keuangan_request';

  IF v_table_count = 0 THEN
    CREATE TABLE keuangan_request (
      request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
      unit_id TINYINT UNSIGNED NOT NULL,
      aksi ENUM('pembayaran','tabungan_masuk','tabungan_keluar','titipan_pengembalian') NOT NULL,
      operator_id INT NOT NULL,
      referensi_id BIGINT NULL,
      dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_keuangan_request_unit_waktu (unit_id,dibuat_pada),
      KEY idx_keuangan_request_operator (operator_id,dibuat_pada)
    ) ENGINE=InnoDB;
  ELSEIF v_table_count <> 1 OR v_valid_table <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'keuangan_request tidak kompatibel: harus tabel InnoDB.';
  END IF;

  SELECT COUNT(*), COALESCE(SUM(
      (COLUMN_NAME = 'request_key' AND COLUMN_TYPE = 'char(32)' AND IS_NULLABLE = 'NO'
        AND CHARACTER_SET_NAME = 'ascii' AND COLLATION_NAME = 'ascii_bin')
      OR (COLUMN_NAME = 'unit_id' AND COLUMN_TYPE = 'tinyint unsigned' AND IS_NULLABLE = 'NO')
      OR (COLUMN_NAME = 'aksi' AND COLUMN_TYPE IN (
          'enum(''pembayaran'',''tabungan_masuk'',''tabungan_keluar'')',
          'enum(''pembayaran'',''tabungan_masuk'',''tabungan_keluar'',''titipan_pengembalian'')')
        AND IS_NULLABLE = 'NO')
      OR (COLUMN_NAME = 'operator_id' AND COLUMN_TYPE = 'int' AND IS_NULLABLE = 'NO')
      OR (COLUMN_NAME = 'referensi_id' AND COLUMN_TYPE IN ('int','bigint') AND IS_NULLABLE = 'YES')
      OR (COLUMN_NAME = 'dibuat_pada' AND COLUMN_TYPE = 'timestamp' AND IS_NULLABLE = 'NO'
        AND UPPER(COLUMN_DEFAULT) IN ('CURRENT_TIMESTAMP','CURRENT_TIMESTAMP()'))
    ), 0)
    INTO v_column_count, v_valid_columns
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keuangan_request';
  IF v_column_count <> 6 OR v_valid_columns <> 6 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'keuangan_request tidak kompatibel: kolom atau tipe berbeda.';
  END IF;

  SELECT COUNT(*) INTO v_valid_indexes FROM (
    SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_order,
           MIN(NON_UNIQUE) AS min_non_unique, MAX(NON_UNIQUE) AS max_non_unique,
           SUM(SUB_PART IS NOT NULL) AS prefix_columns,
           MIN(INDEX_TYPE) AS min_index_type, MAX(INDEX_TYPE) AS max_index_type
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keuangan_request'
      AND INDEX_NAME IN ('PRIMARY','idx_keuangan_request_unit_waktu','idx_keuangan_request_operator')
    GROUP BY INDEX_NAME
  ) AS idx
  WHERE prefix_columns = 0 AND min_index_type = 'BTREE' AND max_index_type = 'BTREE'
    AND ((INDEX_NAME = 'PRIMARY' AND columns_in_order = 'request_key'
         AND min_non_unique = 0 AND max_non_unique = 0)
     OR (INDEX_NAME = 'idx_keuangan_request_unit_waktu'
         AND columns_in_order = 'unit_id,dibuat_pada'
         AND min_non_unique = 1 AND max_non_unique = 1)
     OR (INDEX_NAME = 'idx_keuangan_request_operator'
         AND columns_in_order = 'operator_id,dibuat_pada'
         AND min_non_unique = 1 AND max_non_unique = 1));
  IF v_valid_indexes <> 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'keuangan_request tidak kompatibel: primary key atau indeks berbeda.';
  END IF;
  SELECT COUNT(DISTINCT INDEX_NAME) INTO v_unique_indexes
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keuangan_request' AND NON_UNIQUE = 0;
  IF v_unique_indexes <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'keuangan_request tidak kompatibel: indeks unik tambahan.';
  END IF;

  SELECT COLUMN_TYPE INTO v_action_type FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keuangan_request' AND COLUMN_NAME = 'aksi';
  SELECT COLUMN_TYPE INTO v_reference_type FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keuangan_request' AND COLUMN_NAME = 'referensi_id';
  IF v_action_type = 'enum(''pembayaran'',''tabungan_masuk'',''tabungan_keluar'')' THEN
    ALTER TABLE keuangan_request MODIFY aksi
      ENUM('pembayaran','tabungan_masuk','tabungan_keluar','titipan_pengembalian') NOT NULL;
  END IF;
  IF v_reference_type = 'int' THEN
    ALTER TABLE keuangan_request MODIFY referensi_id BIGINT NULL;
  END IF;
END//
CALL install_keuangan_request_guard()//
DROP PROCEDURE install_keuangan_request_guard//
DELIMITER ;
