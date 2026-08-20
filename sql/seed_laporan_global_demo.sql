-- =========================================================
-- Seeder demo Laporan Global SistemSPP
-- Aman dijalankan ulang pada database db_spp.
--
-- Isi data:
-- - Master kelas/rombel 1A sampai 6C.
-- - 36 siswa demo, 2 siswa per rombel.
-- - Penempatan tahun ajaran 2026/2027 dan tagihan Daftar Ulang.
-- - Tagihan Biaya Lain untuk Biaya Buku.
-- - Transaksi SPP, Komite, Daftar Ulang, Biaya Lain, dan tabungan.
-- =========================================================

USE `db_spp`;

START TRANSACTION;

INSERT INTO `master_kelas` (`tingkat`, `kode_rombel`, `is_placeholder`, `is_active`) VALUES
(1, 'A', 0, 1), (1, 'B', 0, 1), (1, 'C', 0, 1),
(2, 'A', 0, 1), (2, 'B', 0, 1), (2, 'C', 0, 1),
(3, 'A', 0, 1), (3, 'B', 0, 1), (3, 'C', 0, 1),
(4, 'A', 0, 1), (4, 'B', 0, 1), (4, 'C', 0, 1),
(5, 'A', 0, 1), (5, 'B', 0, 1), (5, 'C', 0, 1),
(6, 'A', 0, 1), (6, 'B', 0, 1), (6, 'C', 0, 1)
ON DUPLICATE KEY UPDATE
  `is_placeholder` = 0,
  `is_active` = 1;

DROP TEMPORARY TABLE IF EXISTS `tmp_seed_students`;
CREATE TEMPORARY TABLE `tmp_seed_students` (
  `seq` INT NOT NULL PRIMARY KEY,
  `no_induk` VARCHAR(10) NOT NULL UNIQUE,
  `nisn` CHAR(10) DEFAULT NULL,
  `nama` VARCHAR(100) NOT NULL,
  `tingkat` TINYINT UNSIGNED NOT NULL,
  `rombel` VARCHAR(10) NOT NULL,
  `spp` DECIMAL(15,2) NOT NULL,
  `komite` DECIMAL(15,2) NOT NULL,
  `du` DECIMAL(18,2) NOT NULL,
  `pangkal` DECIMAL(15,2) NOT NULL,
  `bangunan` DECIMAL(15,2) NOT NULL,
  `seragam` DECIMAL(15,2) NOT NULL,
  `kegiatan` DECIMAL(15,2) NOT NULL,
  `makan` DECIMAL(15,2) NOT NULL,
  `sorga` DECIMAL(15,2) NOT NULL,
  `infaq` DECIMAL(15,2) NOT NULL
) ENGINE=MEMORY;

INSERT INTO `tmp_seed_students`
(`seq`, `no_induk`, `nisn`, `nama`, `tingkat`, `rombel`, `spp`, `komite`, `du`, `pangkal`, `bangunan`, `seragam`, `kegiatan`, `makan`, `sorga`, `infaq`) VALUES
(1,  '262701001', '2627010001', 'Abdullah Rasyid Azzam',       1, 'A', 250000, 100000, 1000000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000),
(2,  '262701002', '2627010002', 'Abhimanyu Alfarendra',        1, 'A', 250000, 100000, 1000000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000),
(3,  '262701003', '2627010003', 'Adistia Nadhera Kamania',     1, 'B', 250000, 100000, 1000000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000),
(4,  '262701004', '2627010004', 'Aisha Fateha Madina',         1, 'B', 250000, 100000, 1000000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000),
(5,  '262701005', '2627010005', 'Aisyah Qaireen Inara',        1, 'C', 250000, 100000, 1000000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000),
(6,  '262701006', '2627010006', 'Alby Anugrah Ramadhan',       1, 'C', 250000, 100000, 1000000, 1000000, 1500000, 500000, 300000, 180000, 50000, 25000),
(7,  '262702001', '2627020001', 'Alvaro Surya Devano',         2, 'A', 260000, 110000, 1100000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000),
(8,  '262702002', '2627020002', 'Alzena Zea Sadiya',           2, 'A', 260000, 110000, 1100000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000),
(9,  '262702003', '2627020003', 'Ammar Fadhil Prakasa',        2, 'B', 260000, 110000, 1100000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000),
(10, '262702004', '2627020004', 'Anindya Putri Maharani',      2, 'B', 260000, 110000, 1100000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000),
(11, '262702005', '2627020005', 'Arkan Maulana Ibrahim',       2, 'C', 260000, 110000, 1100000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000),
(12, '262702006', '2627020006', 'Aulia Zahra Nasywa',          2, 'C', 260000, 110000, 1100000, 1100000, 1550000, 525000, 325000, 185000, 50000, 30000),
(13, '262703001', '2627030001', 'Bagas Saputra Wijaya',        3, 'A', 275000, 120000, 1200000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000),
(14, '262703002', '2627030002', 'Bilqis Nayla Humaira',        3, 'A', 275000, 120000, 1200000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000),
(15, '262703003', '2627030003', 'Cahya Ramadhan Putra',        3, 'B', 275000, 120000, 1200000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000),
(16, '262703004', '2627030004', 'Citra Maharani Syifa',        3, 'B', 275000, 120000, 1200000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000),
(17, '262703005', '2627030005', 'Daffa Rayyan Hakim',          3, 'C', 275000, 120000, 1200000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000),
(18, '262703006', '2627030006', 'Dania Kirana Putri',          3, 'C', 275000, 120000, 1200000, 1200000, 1600000, 550000, 350000, 190000, 60000, 30000),
(19, '262704001', '2627040001', 'Dewangga Farel Pratama',      4, 'A', 290000, 130000, 1300000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000),
(20, '262704002', '2627040002', 'Eleanor Syakira Putri',       4, 'A', 290000, 130000, 1300000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000),
(21, '262704003', '2627040003', 'Fadli Maulana Yusuf',         4, 'B', 290000, 130000, 1300000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000),
(22, '262704004', '2627040004', 'Farah Azzahra Lestari',       4, 'B', 290000, 130000, 1300000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000),
(23, '262704005', '2627040005', 'Ghani Athallah Zikri',        4, 'C', 290000, 130000, 1300000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000),
(24, '262704006', '2627040006', 'Hana Qotrunnada Safitri',     4, 'C', 290000, 130000, 1300000, 1300000, 1650000, 575000, 375000, 195000, 60000, 35000),
(25, '262705001', '2627050001', 'Hafiz Alfarizi Rahman',       5, 'A', 305000, 140000, 1400000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000),
(26, '262705002', '2627050002', 'Intan Nuraini Hasanah',       5, 'A', 305000, 140000, 1400000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000),
(27, '262705003', '2627050003', 'Jibran Arya Mahendra',        5, 'B', 305000, 140000, 1400000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000),
(28, '262705004', '2627050004', 'Kaila Putri Amanda',          5, 'B', 305000, 140000, 1400000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000),
(29, '262705005', '2627050005', 'Laras Puspita Ningrum',       5, 'C', 305000, 140000, 1400000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000),
(30, '262705006', '2627050006', 'Muhammad Fikri Akbar',        5, 'C', 305000, 140000, 1400000, 1400000, 1700000, 600000, 400000, 200000, 70000, 40000),
(31, '262706001', '2627060001', 'Nabila Khairunnisa Zahra',    6, 'A', 320000, 150000, 1500000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000),
(32, '262706002', '2627060002', 'Naufal Akbar Firdaus',        6, 'A', 320000, 150000, 1500000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000),
(33, '262706003', '2627060003', 'Raka Firmansyah Ilham',       6, 'B', 320000, 150000, 1500000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000),
(34, '262706004', '2627060004', 'Sabrina Fitri Aulia',         6, 'B', 320000, 150000, 1500000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000),
(35, '262706005', '2627060005', 'Tiara Safitri Azzahra',       6, 'C', 320000, 150000, 1500000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000),
(36, '262706006', '2627060006', 'Zidan Hafizh Muttaqin',       6, 'C', 320000, 150000, 1500000, 1500000, 1750000, 625000, 425000, 210000, 70000, 40000);

INSERT INTO `siswa` (
  `NO_INDUK`, `NO_induk_diknas`, `NAMA`, `KELAS`, `master_kelas_id`, `SPP_PERBULAN`,
  `PANGKAL`, `BANGUNAN`, `SERAGAM`, `KEGIATAN`, `MAKAN`, `SORGA`, `INFAQ`,
  `POMG`, `DAFTAR_ULANG`, `potong_pangkal`, `tot_pangkal`, `potong_du`, `tot_du`, `is_active`
)
SELECT
  ts.`no_induk`, ts.`nisn`, ts.`nama`, CAST(ts.`tingkat` AS CHAR), mk.`id`, ts.`spp`,
  ts.`pangkal`, ts.`bangunan`, ts.`seragam`, ts.`kegiatan`, ts.`makan`, ts.`sorga`, ts.`infaq`,
  ts.`komite`, ts.`du`, 0, ts.`pangkal`, 0, ts.`du`, 1
FROM `tmp_seed_students` ts
JOIN `master_kelas` mk ON mk.`tingkat` = ts.`tingkat` AND mk.`kode_rombel` = ts.`rombel`
ON DUPLICATE KEY UPDATE
  `NAMA` = VALUES(`NAMA`),
  `KELAS` = VALUES(`KELAS`),
  `master_kelas_id` = VALUES(`master_kelas_id`),
  `SPP_PERBULAN` = VALUES(`SPP_PERBULAN`),
  `PANGKAL` = VALUES(`PANGKAL`),
  `BANGUNAN` = VALUES(`BANGUNAN`),
  `SERAGAM` = VALUES(`SERAGAM`),
  `KEGIATAN` = VALUES(`KEGIATAN`),
  `MAKAN` = VALUES(`MAKAN`),
  `SORGA` = VALUES(`SORGA`),
  `INFAQ` = VALUES(`INFAQ`),
  `POMG` = VALUES(`POMG`),
  `DAFTAR_ULANG` = VALUES(`DAFTAR_ULANG`),
  `tot_pangkal` = VALUES(`tot_pangkal`),
  `tot_du` = VALUES(`tot_du`),
  `is_active` = 1;

DROP TEMPORARY TABLE IF EXISTS `tmp_existing_student_class`;
CREATE TEMPORARY TABLE `tmp_existing_student_class` (
  `no_induk` VARCHAR(10) NOT NULL PRIMARY KEY,
  `tingkat` TINYINT UNSIGNED NOT NULL,
  `rombel` VARCHAR(10) NOT NULL,
  `spp` DECIMAL(15,2) NOT NULL,
  `komite` DECIMAL(15,2) NOT NULL,
  `du` DECIMAL(18,2) NOT NULL
) ENGINE=MEMORY;

INSERT INTO `tmp_existing_student_class` (`no_induk`, `tingkat`, `rombel`, `spp`, `komite`, `du`) VALUES
('2024001', 1, 'A', 250000, 100000, 1000000),
('2024002', 2, 'A', 260000, 110000, 1100000),
('2024003', 3, 'A', 275000, 120000, 1200000),
('2024004', 4, 'A', 290000, 130000, 1300000),
('2024005', 5, 'A', 305000, 140000, 1400000),
('2024006', 6, 'A', 320000, 150000, 1500000),
('12345678', 6, 'B', 320000, 150000, 1500000);

UPDATE `siswa` s
JOIN `tmp_existing_student_class` esc ON esc.`no_induk` = s.`NO_INDUK`
JOIN `master_kelas` mk ON mk.`tingkat` = esc.`tingkat` AND mk.`kode_rombel` = esc.`rombel`
SET s.`KELAS` = CAST(esc.`tingkat` AS CHAR),
    s.`master_kelas_id` = mk.`id`,
    s.`SPP_PERBULAN` = CASE WHEN s.`SPP_PERBULAN` <= 0 THEN esc.`spp` ELSE s.`SPP_PERBULAN` END,
    s.`POMG` = CASE WHEN s.`POMG` <= 0 THEN esc.`komite` ELSE s.`POMG` END,
    s.`DAFTAR_ULANG` = CASE WHEN s.`DAFTAR_ULANG` <= 0 THEN esc.`du` ELSE s.`DAFTAR_ULANG` END,
    s.`tot_du` = CASE WHEN s.`tot_du` <= 0 THEN esc.`du` ELSE s.`tot_du` END,
    s.`is_active` = 1;

INSERT INTO `tahun_ajaran` (`label`, `tanggal_mulai`, `tanggal_selesai`, `status`, `published_at`) VALUES
('2026/2027', '2026-07-01', '2027-06-30', 'published', COALESCE(NOW(), CURRENT_TIMESTAMP))
ON DUPLICATE KEY UPDATE
  `tanggal_mulai` = VALUES(`tanggal_mulai`),
  `tanggal_selesai` = VALUES(`tanggal_selesai`),
  `status` = 'published',
  `published_at` = COALESCE(`published_at`, NOW());

INSERT INTO `Daftar_ulang` (`tahun_ajaran_id`, `th_ajaran`, `kelas`, `Jumlah`)
SELECT ta.`id`, ta.`label`, data_kelas.`kelas`, data_kelas.`jumlah`
FROM `tahun_ajaran` ta
JOIN (
  SELECT '1' AS `kelas`, 1000000 AS `jumlah` UNION ALL
  SELECT '2', 1100000 UNION ALL
  SELECT '3', 1200000 UNION ALL
  SELECT '4', 1300000 UNION ALL
  SELECT '5', 1400000 UNION ALL
  SELECT '6', 1500000
) data_kelas
WHERE ta.`label` = '2026/2027'
ON DUPLICATE KEY UPDATE
  `tahun_ajaran_id` = VALUES(`tahun_ajaran_id`),
  `Jumlah` = VALUES(`Jumlah`);

INSERT INTO `siswa_tahun_ajaran`
(`tahun_ajaran_id`, `no_induk`, `kelas`, `master_kelas_id`, `kelas_rombel_snapshot`, `spp_perbulan_snapshot`, `komite_snapshot`, `status`)
SELECT
  ta.`id`, s.`NO_INDUK`, s.`KELAS`, s.`master_kelas_id`,
  CASE
    WHEN mk.`is_placeholder` = 1 THEN CONCAT('Kelas ', s.`KELAS`, ' (Belum Ditentukan)')
    ELSE CONCAT(mk.`tingkat`, UPPER(mk.`kode_rombel`))
  END,
  s.`SPP_PERBULAN`, s.`POMG`, 'aktif'
FROM `siswa` s
JOIN `tahun_ajaran` ta ON ta.`label` = '2026/2027'
LEFT JOIN `master_kelas` mk ON mk.`id` = s.`master_kelas_id`
WHERE s.`is_active` = 1
ON DUPLICATE KEY UPDATE
  `kelas` = VALUES(`kelas`),
  `master_kelas_id` = VALUES(`master_kelas_id`),
  `kelas_rombel_snapshot` = VALUES(`kelas_rombel_snapshot`),
  `spp_perbulan_snapshot` = VALUES(`spp_perbulan_snapshot`),
  `komite_snapshot` = VALUES(`komite_snapshot`),
  `status` = 'aktif';

INSERT INTO `tagihan_daftar_ulang`
(`tahun_ajaran_id`, `penempatan_id`, `master_daftar_ulang_id`, `no_induk`, `kelas_snapshot`, `tahun_ajaran_snapshot`, `nominal_awal`, `nominal_tagihan`, `status`)
SELECT
  ta.`id`, sta.`id`, du.`id`, s.`NO_INDUK`, s.`KELAS`, ta.`label`,
  du.`Jumlah`, du.`Jumlah`, 'open'
FROM `siswa` s
JOIN `tahun_ajaran` ta ON ta.`label` = '2026/2027'
JOIN `siswa_tahun_ajaran` sta ON sta.`tahun_ajaran_id` = ta.`id` AND sta.`no_induk` = s.`NO_INDUK`
JOIN `Daftar_ulang` du ON du.`tahun_ajaran_id` = ta.`id` AND du.`kelas` = s.`KELAS`
WHERE s.`is_active` = 1
ON DUPLICATE KEY UPDATE
  `penempatan_id` = VALUES(`penempatan_id`),
  `master_daftar_ulang_id` = VALUES(`master_daftar_ulang_id`),
  `kelas_snapshot` = VALUES(`kelas_snapshot`),
  `tahun_ajaran_snapshot` = VALUES(`tahun_ajaran_snapshot`),
  `nominal_awal` = GREATEST(`nominal_awal`, VALUES(`nominal_awal`)),
  `nominal_tagihan` = GREATEST(`nominal_tagihan`, VALUES(`nominal_tagihan`)),
  `status` = 'open';

INSERT INTO `master_biaya_lain` (`nama`, `nominal`, `is_active`) VALUES
('Biaya Buku', 100000, 1),
('Biaya Outing Class', 150000, 1)
ON DUPLICATE KEY UPDATE
  `nominal` = VALUES(`nominal`),
  `is_active` = 1;

INSERT INTO `tagihan_biaya_lain`
(`master_biaya_lain_id`, `no_induk`, `master_kelas_id`, `nama_snapshot`, `nominal_tagihan`, `kelas_rombel_snapshot`, `status`, `created_by`)
SELECT
  m.`id`, s.`NO_INDUK`, s.`master_kelas_id`, m.`nama`, m.`nominal`,
  COALESCE(sta.`kelas_rombel_snapshot`, CASE WHEN mk.`is_placeholder` = 1 THEN CONCAT('Kelas ', s.`KELAS`, ' (Belum Ditentukan)') ELSE CONCAT(mk.`tingkat`, UPPER(mk.`kode_rombel`)) END),
  'open', NULL
FROM `master_biaya_lain` m
JOIN `siswa` s ON s.`is_active` = 1
LEFT JOIN `master_kelas` mk ON mk.`id` = s.`master_kelas_id`
LEFT JOIN `tahun_ajaran` ta ON ta.`label` = '2026/2027'
LEFT JOIN `siswa_tahun_ajaran` sta ON sta.`tahun_ajaran_id` = ta.`id` AND sta.`no_induk` = s.`NO_INDUK`
WHERE m.`nama` = 'Biaya Buku'
ON DUPLICATE KEY UPDATE
  `master_kelas_id` = VALUES(`master_kelas_id`),
  `nama_snapshot` = VALUES(`nama_snapshot`),
  `nominal_tagihan` = GREATEST(`nominal_tagihan`, VALUES(`nominal_tagihan`)),
  `kelas_rombel_snapshot` = VALUES(`kelas_rombel_snapshot`),
  `status` = 'open';

DELETE FROM `transaksi_m`
WHERE `bayar_id` IS NULL AND `user_id` = 'seed_laporan';

DELETE FROM `transaksi_k`
WHERE `user_id` = 'seed_laporan';

DELETE FROM `bayar`
WHERE `KETERANGAN` LIKE '[SEED LAPORAN]%';

DROP TEMPORARY TABLE IF EXISTS `tmp_seed_payments`;
CREATE TEMPORARY TABLE `tmp_seed_payments` (
  `marker` VARCHAR(100) NOT NULL PRIMARY KEY,
  `no_induk` VARCHAR(10) NOT NULL,
  `tanggal` DATETIME NOT NULL,
  `bulan` CHAR(2) NOT NULL,
  `tahun` CHAR(4) NOT NULL,
  `metode` ENUM('Tunai','VA','Qris') NOT NULL,
  `u_pangkal` DOUBLE NOT NULL DEFAULT 0,
  `u_bangunan` DOUBLE NOT NULL DEFAULT 0,
  `u_seragam` DOUBLE NOT NULL DEFAULT 0,
  `u_kegiatan` DOUBLE NOT NULL DEFAULT 0,
  `u_spp` DOUBLE NOT NULL DEFAULT 0,
  `u_komite` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `u_makan` DOUBLE NOT NULL DEFAULT 0,
  `u_sorga` DOUBLE NOT NULL DEFAULT 0,
  `u_infaq` DOUBLE NOT NULL DEFAULT 0,
  `potong_spp` DOUBLE NOT NULL DEFAULT 0,
  `du_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `biaya_buku_amount` DECIMAL(15,2) NOT NULL DEFAULT 0
) ENGINE=MEMORY;

INSERT INTO `tmp_seed_payments`
(`marker`, `no_induk`, `tanggal`, `bulan`, `tahun`, `metode`, `u_spp`, `u_komite`, `potong_spp`)
SELECT
  CONCAT('[SEED LAPORAN] SPP Juli ', ts.`no_induk`), ts.`no_induk`,
  TIMESTAMP('2026-07-10', MAKETIME(8 + (ts.`seq` MOD 7), 5, 0)),
  '07', '2026',
  CASE ts.`seq` MOD 3 WHEN 0 THEN 'Tunai' WHEN 1 THEN 'VA' ELSE 'Qris' END,
  CASE WHEN ts.`seq` MOD 2 = 1 THEN ts.`spp` ELSE ROUND(ts.`spp` * 0.5, 0) END,
  CASE WHEN ts.`seq` MOD 2 = 1 THEN ts.`komite` ELSE 0 END,
  CASE WHEN ts.`seq` MOD 12 = 0 THEN 25000 ELSE 0 END
FROM `tmp_seed_students` ts;

INSERT INTO `tmp_seed_payments`
(`marker`, `no_induk`, `tanggal`, `bulan`, `tahun`, `metode`, `u_spp`, `u_komite`)
SELECT
  CONCAT('[SEED LAPORAN] SPP Agustus ', ts.`no_induk`), ts.`no_induk`,
  TIMESTAMP('2026-08-05', MAKETIME(9 + (ts.`seq` MOD 6), 15, 0)),
  '08', '2026',
  CASE ts.`seq` MOD 3 WHEN 0 THEN 'Tunai' WHEN 1 THEN 'VA' ELSE 'Qris' END,
  CASE WHEN ts.`seq` MOD 4 = 1 THEN ts.`spp` WHEN ts.`seq` MOD 4 = 2 THEN ROUND(ts.`spp` * 0.4, 0) ELSE 0 END,
  CASE WHEN ts.`seq` MOD 4 = 1 THEN ts.`komite` ELSE 0 END
FROM `tmp_seed_students` ts
WHERE ts.`seq` MOD 4 IN (1, 2);

INSERT INTO `tmp_seed_payments`
(`marker`, `no_induk`, `tanggal`, `bulan`, `tahun`, `metode`, `du_amount`)
SELECT
  CONCAT('[SEED LAPORAN] DU Cicilan ', ts.`no_induk`), ts.`no_induk`,
  TIMESTAMP('2026-08-06', MAKETIME(8 + (ts.`seq` MOD 8), 30, 0)),
  '08', '2026', 'Tunai',
  CASE WHEN ts.`seq` MOD 6 = 1 THEN 400000 WHEN ts.`seq` MOD 6 = 3 THEN ts.`du` ELSE 0 END
FROM `tmp_seed_students` ts
WHERE ts.`seq` MOD 6 IN (1, 3);

INSERT INTO `tmp_seed_payments`
(`marker`, `no_induk`, `tanggal`, `bulan`, `tahun`, `metode`, `biaya_buku_amount`)
SELECT
  CONCAT('[SEED LAPORAN] Buku ', ts.`no_induk`), ts.`no_induk`,
  TIMESTAMP('2026-08-07', MAKETIME(9 + (ts.`seq` MOD 7), 45, 0)),
  '08', '2026', CASE WHEN ts.`seq` MOD 2 = 0 THEN 'Tunai' ELSE 'Qris' END,
  CASE WHEN ts.`seq` MOD 3 = 0 THEN 100000 ELSE 50000 END
FROM `tmp_seed_students` ts
WHERE ts.`seq` MOD 3 IN (0, 1);

INSERT INTO `tmp_seed_payments`
(`marker`, `no_induk`, `tanggal`, `bulan`, `tahun`, `metode`, `u_pangkal`, `u_bangunan`, `u_seragam`, `u_kegiatan`, `u_makan`, `u_sorga`, `u_infaq`)
SELECT
  CONCAT('[SEED LAPORAN] Komponen Satu Kali ', ts.`no_induk`), ts.`no_induk`,
  TIMESTAMP('2026-08-08', MAKETIME(10 + (ts.`seq` MOD 5), 20, 0)),
  '08', '2026', 'Tunai',
  CASE WHEN ts.`seq` MOD 10 = 1 THEN 500000 ELSE 0 END,
  CASE WHEN ts.`seq` MOD 10 = 2 THEN 750000 ELSE 0 END,
  CASE WHEN ts.`seq` MOD 10 = 3 THEN 250000 ELSE 0 END,
  CASE WHEN ts.`seq` MOD 10 = 4 THEN 150000 ELSE 0 END,
  CASE WHEN ts.`seq` MOD 5 = 0 THEN 90000 ELSE 0 END,
  CASE WHEN ts.`seq` MOD 7 = 0 THEN 50000 ELSE 0 END,
  CASE WHEN ts.`seq` MOD 8 = 0 THEN 25000 ELSE 0 END
FROM `tmp_seed_students` ts
WHERE ts.`seq` MOD 10 IN (1, 2, 3, 4)
   OR ts.`seq` MOD 5 = 0
   OR ts.`seq` MOD 7 = 0
   OR ts.`seq` MOD 8 = 0;

DELETE FROM `tmp_seed_payments`
WHERE `u_pangkal` + `u_bangunan` + `u_seragam` + `u_kegiatan` + `u_spp` + `u_komite`
    + `u_makan` + `u_sorga` + `u_infaq` + `du_amount` + `biaya_buku_amount` <= 0;

INSERT INTO `bayar`
(`NO_INDUK`, `KELAS`, `master_kelas_id`, `kelas_rombel_snapshot`,
 `U_PANGKAL`, `U_BANGUNAN`, `U_SERAGAM`, `U_KEGIATAN`, `U_SPP`,
 `U_MAKAN`, `U_SORGA`, `U_INFAQ`, `U_KOMITE`, `U_LAIN`,
 `KETERANGAN`, `TGL_BYR`, `BULAN`, `user_id`, `sistem_pembayaran`, `TAHUN`,
 `th_ajaran`, `kelas_du`, `potong_spp`, `total_jumlah`, `payment_link_version`)
SELECT
  p.`no_induk`, s.`KELAS`, s.`master_kelas_id`,
  COALESCE(sta.`kelas_rombel_snapshot`, CASE WHEN mk.`is_placeholder` = 1 THEN CONCAT('Kelas ', s.`KELAS`, ' (Belum Ditentukan)') ELSE CONCAT(mk.`tingkat`, UPPER(mk.`kode_rombel`)) END),
  p.`u_pangkal`, p.`u_bangunan`, p.`u_seragam`, p.`u_kegiatan`, p.`u_spp`,
  p.`u_makan`, p.`u_sorga`, p.`u_infaq`, p.`u_komite`, 0,
  p.`marker`, p.`tanggal`, p.`bulan`, 'seed_laporan', p.`metode`, p.`tahun`,
  '2026/2027', s.`KELAS`, p.`potong_spp`,
  p.`u_pangkal` + p.`u_bangunan` + p.`u_seragam` + p.`u_kegiatan` + p.`u_spp`
    + p.`u_makan` + p.`u_sorga` + p.`u_infaq` + p.`u_komite` + p.`du_amount` + p.`biaya_buku_amount`
    - p.`potong_spp`,
  0
FROM `tmp_seed_payments` p
JOIN `siswa` s ON s.`NO_INDUK` = p.`no_induk`
LEFT JOIN `master_kelas` mk ON mk.`id` = s.`master_kelas_id`
LEFT JOIN `tahun_ajaran` ta ON ta.`label` = '2026/2027'
LEFT JOIN `siswa_tahun_ajaran` sta ON sta.`tahun_ajaran_id` = ta.`id` AND sta.`no_induk` = s.`NO_INDUK`;

INSERT INTO `bayar_spp_periode` (`bayar_id`, `no_induk`, `bulan`, `tahun`)
SELECT b.`id`, b.`NO_INDUK`, b.`BULAN`, b.`TAHUN`
FROM `bayar` b
WHERE b.`KETERANGAN` LIKE '[SEED LAPORAN] SPP%'
  AND b.`U_SPP` > 0
ON DUPLICATE KEY UPDATE
  `no_induk` = VALUES(`no_induk`),
  `bulan` = VALUES(`bulan`),
  `tahun` = VALUES(`tahun`);

INSERT INTO `bayar_du` (`bayar_id`, `tagihan_daftar_ulang_id`, `no_induk`, `kelas`, `th_ajaran`, `jumlah`)
SELECT b.`id`, t.`id`, b.`NO_INDUK`, b.`KELAS`, '2026/2027', p.`du_amount`
FROM `tmp_seed_payments` p
JOIN `bayar` b ON b.`KETERANGAN` = p.`marker`
JOIN `tagihan_daftar_ulang` t ON t.`no_induk` = p.`no_induk` AND t.`tahun_ajaran_snapshot` = '2026/2027' AND t.`status` = 'open'
WHERE p.`du_amount` > 0
ON DUPLICATE KEY UPDATE
  `tagihan_daftar_ulang_id` = VALUES(`tagihan_daftar_ulang_id`),
  `jumlah` = VALUES(`jumlah`);

INSERT INTO `bayar_biaya_lain`
(`bayar_id`, `master_biaya_lain_id`, `tagihan_biaya_lain_id`, `nama_biaya_snapshot`, `nominal_snapshot`, `keterangan`, `urutan`, `legacy_key`)
SELECT b.`id`, m.`id`, t.`id`, t.`nama_snapshot`, p.`biaya_buku_amount`, 'Seeder laporan global', 1, 'SEED'
FROM `tmp_seed_payments` p
JOIN `bayar` b ON b.`KETERANGAN` = p.`marker`
JOIN `master_biaya_lain` m ON m.`nama` = 'Biaya Buku'
JOIN `tagihan_biaya_lain` t ON t.`master_biaya_lain_id` = m.`id` AND t.`no_induk` = p.`no_induk` AND t.`status` = 'open'
WHERE p.`biaya_buku_amount` > 0
ON DUPLICATE KEY UPDATE
  `tagihan_biaya_lain_id` = VALUES(`tagihan_biaya_lain_id`),
  `nominal_snapshot` = VALUES(`nominal_snapshot`);

INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT NULL, ts.`no_induk`, TIMESTAMP('2026-08-04', MAKETIME(8 + (ts.`seq` MOD 5), 10, 0)),
       CASE WHEN ts.`seq` MOD 2 = 1 THEN 100000 ELSE 50000 END, 0, 'seed_laporan'
FROM `tmp_seed_students` ts
WHERE ts.`seq` <= 18;

INSERT INTO `transaksi_m` (`bayar_id`, `NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT NULL, ts.`no_induk`, TIMESTAMP('2026-08-11', MAKETIME(9 + (ts.`seq` MOD 4), 0, 0)),
       75000, 0, 'seed_laporan'
FROM `tmp_seed_students` ts
WHERE ts.`seq` BETWEEN 19 AND 36 AND ts.`seq` MOD 2 = 0;

INSERT INTO `transaksi_k` (`NO_INDUK`, `TANGGAL`, `MASUK`, `KELUAR`, `user_id`)
SELECT ts.`no_induk`, TIMESTAMP('2026-08-12', MAKETIME(10 + (ts.`seq` MOD 4), 25, 0)),
       0, 25000, 'seed_laporan'
FROM `tmp_seed_students` ts
WHERE ts.`seq` MOD 6 = 0;

INSERT INTO `tabungan` (`NO_INDUK`, `SALDO`)
SELECT x.`NO_INDUK`, SUM(x.`delta`) AS `saldo`
FROM (
  SELECT `NO_INDUK`, `MASUK` AS `delta` FROM `transaksi_m` WHERE `NO_INDUK` IN (SELECT `no_induk` FROM `tmp_seed_students`)
  UNION ALL
  SELECT `NO_INDUK`, -`KELUAR` AS `delta` FROM `transaksi_k` WHERE `NO_INDUK` IN (SELECT `no_induk` FROM `tmp_seed_students`)
) x
GROUP BY x.`NO_INDUK`
ON DUPLICATE KEY UPDATE
  `SALDO` = VALUES(`SALDO`);

COMMIT;

SELECT 'rombel_aktif' AS info, COUNT(*) AS jumlah
FROM `master_kelas`
WHERE `is_active` = 1 AND `is_placeholder` = 0 AND `tingkat` BETWEEN 1 AND 6;

SELECT 'siswa_seed' AS info, COUNT(*) AS jumlah
FROM `siswa`
WHERE `NO_INDUK` LIKE '2627%';

SELECT 'transaksi_pembayaran_seed' AS info, COUNT(*) AS jumlah
FROM `bayar`
WHERE `KETERANGAN` LIKE '[SEED LAPORAN]%';

SELECT 'transaksi_tabungan_seed' AS info, COUNT(*) AS jumlah
FROM (
  SELECT `id` FROM `transaksi_m` WHERE `bayar_id` IS NULL AND `user_id` = 'seed_laporan'
  UNION ALL
  SELECT `id` FROM `transaksi_k` WHERE `user_id` = 'seed_laporan'
) x;
