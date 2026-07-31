-- ============================================
-- SistemSPP - Database Schema (Revisi Baru)
-- Database: db_spp
-- ============================================

CREATE DATABASE IF NOT EXISTS `db_spp`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `db_spp`;

SET FOREIGN_KEY_CHECKS = 0;

-- Tabel Admin (Untuk Login, tetap dipertahankan)
CREATE TABLE IF NOT EXISTS `admin` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `username`   VARCHAR(50)  NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `nama`       VARCHAR(100) NOT NULL,
  `role`       ENUM('admin','bendahara','kasir') NOT NULL DEFAULT 'admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default users: admin / bendahara / kasir
INSERT INTO `admin` (`username`, `password`, `nama`, `role`) VALUES
('admin',      MD5('admin123'),      'Administrator', 'admin'),
('bendahara',  MD5('bendahara123'),  'Bendahara TU',  'bendahara'),
('kasir',      MD5('kasir123'),      'Kasir',         'kasir')
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`), `role`=VALUES(`role`);


-- Tabel Siswa (Revisi Baru)
DROP TABLE IF EXISTS `siswa`;
CREATE TABLE `siswa` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK`        VARCHAR(10) NOT NULL UNIQUE,
  `NAMA`            VARCHAR(100) NOT NULL,
  `KELAS`           CHAR(1) NOT NULL,
  `SPP_PERBULAN`    DECIMAL(15,2) NOT NULL DEFAULT 0,
  `PANGKAL`         DECIMAL(15,2) NOT NULL DEFAULT 0,
  `BANGUNAN`        DECIMAL(15,2) NOT NULL DEFAULT 0,
  `SERAGAM`         DECIMAL(15,2) NOT NULL DEFAULT 0,
  `KEGIATAN`        DECIMAL(15,2) NOT NULL DEFAULT 0,
  `PANGKAL_BAYAR`   DECIMAL(15,2) NOT NULL DEFAULT 0,
  `BANGUNAN_BAYAR`  DECIMAL(15,2) NOT NULL DEFAULT 0,
  `SERAGAM_BAYAR`   DECIMAL(15,2) NOT NULL DEFAULT 0,
  `KEGIATAN_BAYAR`  DECIMAL(15,2) NOT NULL DEFAULT 0,
  `POMG`            DECIMAL(15,2) NOT NULL DEFAULT 0,
  `DAFTAR_ULANG`    DECIMAL(15,2) NOT NULL DEFAULT 0,
  `NO_induk_diknas` CHAR(10) DEFAULT NULL,
  `potong_pangkal`  DECIMAL(15,2) NOT NULL DEFAULT 0,
  `tot_pangkal`     DECIMAL(15,2) NOT NULL DEFAULT 0,
  `tot_du`          DECIMAL(15,2) NOT NULL DEFAULT 0,
  `potong_du`       DECIMAL(15,2) NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_siswa_status_kelas_nama` (`is_active`, `KELAS`, `NAMA`),
  CONSTRAINT `chk_siswa_kelas_sd` CHECK (`KELAS` IN ('1','2','3','4','5','6'))
) ENGINE=InnoDB;

-- Audit perubahan master siswa
DROP TABLE IF EXISTS `siswa_audit_log`;
CREATE TABLE `siswa_audit_log` (
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
  CONSTRAINT `fk_siswa_audit_siswa` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_siswa_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Data siswa contoh
INSERT INTO `siswa` (`NO_INDUK`, `NAMA`, `KELAS`, `SPP_PERBULAN`, `PANGKAL`, `BANGUNAN`, `SERAGAM`, `KEGIATAN`) VALUES
('2024001', 'Ahmad Fauzi',    '1', 250000, 1000000, 1500000, 500000, 300000),
('2024002', 'Siti Rahayu',    '2', 250000, 1000000, 1500000, 500000, 300000),
('2024003', 'Budi Santoso',   '3', 275000, 1000000, 1500000, 500000, 300000),
('2024004', 'Dewi Lestari',   '4', 275000, 1000000, 1500000, 500000, 300000),
('2024005', 'Muhammad Rizky', '5', 300000, 1000000, 1500000, 500000, 300000),
('2024006', 'Ayu Putri',      '6', 300000, 1000000, 1500000, 500000, 300000);

-- Tabel Bayar (Revisi Baru)
DROP TABLE IF EXISTS `bayar`;
CREATE TABLE `bayar` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK`    VARCHAR(10) DEFAULT NULL,
  `KELAS`       VARCHAR(5) DEFAULT NULL,
  `U_PANGKAL`   DOUBLE DEFAULT 0,
  `U_BANGUNAN`  DOUBLE DEFAULT 0,
  `U_SERAGAM`   DOUBLE DEFAULT 0,
  `U_KEGIATAN`  DOUBLE DEFAULT 0,
  `U_SPP`       DOUBLE DEFAULT 0,
  `U_MAKAN`     DOUBLE DEFAULT 0,
  `U_SORGA`     DOUBLE DEFAULT 0,
  `U_INFAQ`     DOUBLE DEFAULT 0,
  `U_KOMITE`    DECIMAL(15,2) NOT NULL DEFAULT 0,
  `U_LAIN`      DOUBLE DEFAULT 0,
  `KETERANGAN`  VARCHAR(20) DEFAULT NULL,
  `TGL_BYR`     DATETIME DEFAULT NULL,
  `BULAN`       VARCHAR(20) DEFAULT NULL,
  `user_id`     VARCHAR(10) DEFAULT NULL,
  `sistem_pembayaran` ENUM('Tunai','VA','Qris') NOT NULL DEFAULT 'VA',
  `TAHUN`       CHAR(4) DEFAULT NULL,
  `LAIN_LAIN1`  VARCHAR(5) DEFAULT NULL,
  `LAIN_LAIN2`  VARCHAR(5) DEFAULT NULL,
  `LAIN_LAIN3`  VARCHAR(5) DEFAULT NULL,
  `LAIN_LAIN4`  VARCHAR(5) DEFAULT NULL,
  `JUMLAH1`     DOUBLE DEFAULT 0,
  `JUMLAH2`     DOUBLE DEFAULT 0,
  `JUMLAH3`     DOUBLE DEFAULT 0,
  `JUMLAH4`     DOUBLE DEFAULT 0,
  `th_ajaran`   CHAR(9) DEFAULT NULL,
  `kelas_du`    CHAR(5) DEFAULT NULL,
  `potong_spp`  DOUBLE DEFAULT 0,
  `total_jumlah` DOUBLE DEFAULT 0, -- Kolom bantu kalkulasi total pembayaran
  `payment_link_version` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Master jenis biaya lain
DROP TABLE IF EXISTS `master_biaya_lain`;
CREATE TABLE `master_biaya_lain` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `nama`       VARCHAR(100) NOT NULL UNIQUE,
  `nominal`    DECIMAL(15,2) NOT NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `chk_master_biaya_lain_nominal` CHECK (`nominal` > 0)
) ENGINE=InnoDB;

-- Detail biaya lain per transaksi. Nama dan nominal disimpan sebagai snapshot
-- agar perubahan master tidak mengubah riwayat transaksi.
DROP TABLE IF EXISTS `bayar_biaya_lain`;
CREATE TABLE `bayar_biaya_lain` (
  `id`                         INT AUTO_INCREMENT PRIMARY KEY,
  `bayar_id`                   INT NOT NULL,
  `master_biaya_lain_id`       INT DEFAULT NULL,
  `nama_biaya_snapshot`        VARCHAR(100) NOT NULL,
  `nominal_snapshot`           DECIMAL(15,2) NOT NULL,
  `keterangan`                 VARCHAR(255) DEFAULT NULL,
  `urutan`                     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `legacy_key`                 VARCHAR(10) DEFAULT NULL,
  `created_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_bayar_biaya_lain_legacy` (`bayar_id`, `legacy_key`),
  KEY `idx_bayar_biaya_lain_master` (`master_biaya_lain_id`),
  CONSTRAINT `fk_bayar_biaya_lain_bayar`
    FOREIGN KEY (`bayar_id`) REFERENCES `bayar` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_biaya_lain_master`
    FOREIGN KEY (`master_biaya_lain_id`) REFERENCES `master_biaya_lain` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Tabel Daftar Ulang
DROP TABLE IF EXISTS `Daftar_ulang`;
CREATE TABLE `Daftar_ulang` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `th_ajaran` CHAR(9) DEFAULT NULL,
  `kelas`     CHAR(1) DEFAULT NULL,
  `Jumlah`    DECIMAL(18,2) DEFAULT 0
) ENGINE=InnoDB;

-- Tabel Bayar DU
DROP TABLE IF EXISTS `bayar_du`;
CREATE TABLE `bayar_du` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `bayar_id`  INT DEFAULT NULL,
  `no_induk`  VARCHAR(50) DEFAULT NULL,
  `kelas`     VARCHAR(5) DEFAULT NULL,
  `th_ajaran` CHAR(9) DEFAULT NULL,
  `jumlah`    DECIMAL(18,2) DEFAULT 0,
  UNIQUE KEY `uk_bayar_du_bayar_id` (`bayar_id`),
  FOREIGN KEY (`no_induk`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bayar_du_bayar`
    FOREIGN KEY (`bayar_id`) REFERENCES `bayar`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Tabel Tabungan
DROP TABLE IF EXISTS `tabungan`;
CREATE TABLE `tabungan` (
  `id`       INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK` VARCHAR(10) NOT NULL UNIQUE,
  `SALDO`    DOUBLE DEFAULT 0,
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Tabel Transaksi Masuk
DROP TABLE IF EXISTS `transaksi_m`;
CREATE TABLE `transaksi_m` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `bayar_id`  INT DEFAULT NULL,
  `NO_INDUK`  VARCHAR(10) DEFAULT NULL,
  `TANGGAL`   DATETIME DEFAULT NULL,
  `MASUK`     DOUBLE DEFAULT 0,
  `KELUAR`    DOUBLE DEFAULT 0,
  `user_id`   CHAR(10) DEFAULT NULL,
  UNIQUE KEY `uk_transaksi_m_bayar_id` (`bayar_id`),
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transaksi_m_bayar`
    FOREIGN KEY (`bayar_id`) REFERENCES `bayar`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Tabel Transaksi Keluar
DROP TABLE IF EXISTS `transaksi_k`;
CREATE TABLE `transaksi_k` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `NO_INDUK`  VARCHAR(10) DEFAULT NULL,
  `TANGGAL`   DATETIME DEFAULT NULL,
  `MASUK`     DOUBLE DEFAULT 0,
  `KELUAR`    DOUBLE DEFAULT 0,
  `user_id`   CHAR(10) DEFAULT NULL,
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Hapus tabel lama jika ada
DROP TABLE IF EXISTS `pembayaran`;

SET FOREIGN_KEY_CHECKS = 1;
