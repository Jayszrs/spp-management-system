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
  `NAMA`            VARCHAR(30) DEFAULT NULL,
  `KELAS`           VARCHAR(5) DEFAULT NULL,
  `SPP_PERBULAN`    DOUBLE DEFAULT 0,
  `PANGKAL`         DOUBLE DEFAULT 0,
  `BANGUNAN`        DOUBLE DEFAULT 0,
  `SERAGAM`         DOUBLE DEFAULT 0,
  `KEGIATAN`        DOUBLE DEFAULT 0,
  `PANGKAL_BAYAR`   DOUBLE DEFAULT 0,
  `BANGUNAN_BAYAR`  DOUBLE DEFAULT 0,
  `SERAGAM_BAYAR`   DOUBLE DEFAULT 0,
  `KEGIATAN_BAYAR`  DOUBLE DEFAULT 0,
  `POMG`            DOUBLE DEFAULT 0,
  `DAFTAR_ULANG`    DOUBLE DEFAULT 0,
  `NO_induk_diknas` CHAR(10) DEFAULT NULL,
  `potong_pangkal`  DOUBLE DEFAULT 0,
  `tot_pangkal`     DOUBLE DEFAULT 0,
  `tot_du`          DOUBLE DEFAULT 0,
  `potong_du`       DOUBLE DEFAULT 0,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
  `U_LAIN`      DOUBLE DEFAULT 0,
  `KETERANGAN`  VARCHAR(20) DEFAULT NULL,
  `TGL_BYR`     DATETIME DEFAULT NULL,
  `BULAN`       VARCHAR(20) DEFAULT NULL,
  `user_id`     VARCHAR(10) DEFAULT NULL,
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
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
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
  `no_induk`  VARCHAR(50) DEFAULT NULL,
  `kelas`     VARCHAR(5) DEFAULT NULL,
  `th_ajaran` CHAR(9) DEFAULT NULL,
  `jumlah`    DECIMAL(18,2) DEFAULT 0,
  FOREIGN KEY (`no_induk`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
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
  `NO_INDUK`  VARCHAR(10) DEFAULT NULL,
  `TANGGAL`   DATETIME DEFAULT NULL,
  `MASUK`     DOUBLE DEFAULT 0,
  `KELUAR`    DOUBLE DEFAULT 0,
  `user_id`   CHAR(10) DEFAULT NULL,
  FOREIGN KEY (`NO_INDUK`) REFERENCES `siswa`(`NO_INDUK`) ON DELETE CASCADE ON UPDATE CASCADE
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
