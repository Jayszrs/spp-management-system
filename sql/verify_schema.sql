-- =========================================================
-- Verifikasi schema SistemSPP (read-only)
-- Jalankan setelah schema baru atau seluruh migrasi upgrade.
-- =========================================================

USE `db_spp`;

SELECT requirement,
       IF(is_present = 1, 'OK', 'MISSING') AS status
FROM (
  SELECT 'table.bayar' AS requirement,
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
         ) AS is_present
  UNION ALL
  SELECT 'table.bayar_du',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
         )
  UNION ALL
  SELECT 'table.transaksi_m',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
         )
  UNION ALL
  SELECT 'table.master_biaya_lain',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'master_biaya_lain'
         )
  UNION ALL
  SELECT 'table.Daftar_ulang',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'daftar_ulang'
         )
  UNION ALL
  SELECT 'table.bayar_biaya_lain',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_biaya_lain'
         )
  UNION ALL
  SELECT 'table.siswa_audit_log',
         EXISTS(
           SELECT 1 FROM information_schema.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa_audit_log'
         )
  UNION ALL
  SELECT 'admin.role',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin'
             AND COLUMN_NAME = 'role'
         )
  UNION ALL
  SELECT 'siswa.is_active',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
             AND COLUMN_NAME = 'is_active'
         )
  UNION ALL
  SELECT 'bayar.U_KOMITE',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
             AND COLUMN_NAME = 'U_KOMITE'
         )
  UNION ALL
  SELECT 'bayar.sistem_pembayaran',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
             AND COLUMN_NAME = 'sistem_pembayaran'
         )
  UNION ALL
  SELECT 'bayar.payment_link_version' AS requirement,
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
             AND COLUMN_NAME = 'payment_link_version'
         ) AS is_present
  UNION ALL
  SELECT 'bayar_du.bayar_id',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
             AND COLUMN_NAME = 'bayar_id'
         )
  UNION ALL
  SELECT 'transaksi_m.bayar_id',
         EXISTS(
           SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
             AND COLUMN_NAME = 'bayar_id'
         )
  UNION ALL
  SELECT 'uk_bayar_du_bayar_id',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar_du'
             AND INDEX_NAME = 'uk_bayar_du_bayar_id' AND NON_UNIQUE = 0
         )
  UNION ALL
  SELECT 'uk_transaksi_m_bayar_id',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaksi_m'
             AND INDEX_NAME = 'uk_transaksi_m_bayar_id' AND NON_UNIQUE = 0
         )
  UNION ALL
  SELECT 'uk_daftar_ulang_period_class',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'daftar_ulang'
             AND INDEX_NAME = 'uk_daftar_ulang_period_class' AND NON_UNIQUE = 0
         )
  UNION ALL
  SELECT 'idx_siswa_status_kelas_nama',
         EXISTS(
           SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'siswa'
             AND INDEX_NAME = 'idx_siswa_status_kelas_nama'
         )
  UNION ALL
  SELECT 'fk_bayar_du_bayar',
         EXISTS(
           SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
           JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA = TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA
            AND rc.CONSTRAINT_NAME = TABLE_CONSTRAINTS.CONSTRAINT_NAME
           WHERE TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_CONSTRAINTS.TABLE_NAME = 'bayar_du'
             AND TABLE_CONSTRAINTS.CONSTRAINT_NAME = 'fk_bayar_du_bayar'
             AND TABLE_CONSTRAINTS.CONSTRAINT_TYPE = 'FOREIGN KEY'
             AND rc.DELETE_RULE = 'CASCADE' AND rc.UPDATE_RULE = 'CASCADE'
         )
  UNION ALL
  SELECT 'fk_transaksi_m_bayar',
         EXISTS(
           SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
           JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA = TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA
            AND rc.CONSTRAINT_NAME = TABLE_CONSTRAINTS.CONSTRAINT_NAME
           WHERE TABLE_CONSTRAINTS.CONSTRAINT_SCHEMA = DATABASE()
             AND TABLE_CONSTRAINTS.TABLE_NAME = 'transaksi_m'
             AND TABLE_CONSTRAINTS.CONSTRAINT_NAME = 'fk_transaksi_m_bayar'
             AND TABLE_CONSTRAINTS.CONSTRAINT_TYPE = 'FOREIGN KEY'
             AND rc.DELETE_RULE = 'CASCADE' AND rc.UPDATE_RULE = 'CASCADE'
         )
) AS requirements
ORDER BY requirement;

SELECT id, NO_INDUK, TGL_BYR, payment_link_version
FROM bayar
WHERE payment_link_version NOT IN (0, 1)
ORDER BY id;
