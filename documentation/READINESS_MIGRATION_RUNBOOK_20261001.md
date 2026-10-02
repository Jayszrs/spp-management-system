# Runbook terkini ? penghapusan Titipan SPP, 2 Oktober 2026

## Hasil lokal

Status kode 2 Oktober 2026: seluruh 11 commit audit sampai `6a95cc0` sudah digabung ke `main` dengan fast-forward, tanpa konflik. Penggabungan tidak menerapkan ulang migrasi database. Health CLI/HTTP dan 14 pemeriksaan integritas diperiksa ulang dan lulus; baseline di bawah tetap sama.

Pembersihan dummy `db_spp` telah diterapkan atas otorisasi pemilik. Aplikasi kembali terbuka, HTTP health 200 `ok`, URL Titipan SPP lama 404. Utama: **222 siswa, 1.018 pembayaran, Rp577.145.000 penerimaan**, Tabungan **12 rekening/Rp1.050.000**, 12 jurnal masuk/6 keluar. Tabel lain dan pembayaran/alokasi biasa memiliki fingerprint sama. Skema: 61 FK, 18 CHECK/dua trigger, 14 invariant bersih. Rincian bukti ada di [audit terkini](READINESS_AUDIT_20261001.md).

Backup pra-penerapan yang sudah dipulihkan dan diuji:
`C:\laragon\backups\spp-management-system\db_spp_before_remove_spp_deposit_final_20261002_182220.sql`
SHA-256 `254E17AD132E380576BF5D30E1F97B6AB07B08D486B1F7F53D58A23447CF95DA`.
Backup pertama 17:48 tetap disimpan; hash `813954DDFD4E19463AAB29A6EADA24F8C41D8E912020F378DA6B85E164698226`.

## Kontrak migrasi CLI

`php sql/remove_spp_deposit.php` adalah pemeriksaan baca saja. `--apply` memerlukan gate clone/flag atau gate utama/backup baru. Skrip mengharapkan **data dummy yang tepat telah diaudit**, yaitu 19 header/batch/mutasi senilai Rp2.475.000 dengan fingerprint terpasang. Jangan menggunakannya untuk menghapus saldo sekolah lain secara otomatis. Jika baseline/fingerprint/relasi berbeda, hentikan dan audit target baru.

Skrip menolak titipan terpakai, header campuran, antrean pending, relasi belum dipetakan, perubahan data audit atau skema parsial. Data dibersihkan dalam transaksi; DDL MySQL dilakukan sesudah commit dengan tahap tercatat. Semua tabel lain, pembayaran/alokasi langsung serta Tabungan diverifikasi melalui fingerprint. Instalasi baru tidak membuat fitur titipan; nama legacy `add_spp_billing_and_deposit.sql` kini hanya definisi billing langsung.

## Penerapan terkontrol pada target yang sesuai

1. Audit database dan identitas service. Buat dump baru di luar repo dengan `--single-transaction --routines --triggers --result-file`, verifikasi hash/ukuran; backup gate utama maksimal satu jam.
2. Pulihkan backup ke clone bernama `db_spp_audit_*`, dengan `SPP_TEST_ALLOW_MUTATION=1`. Jalankan pemeriksaan, `--apply`, rerun, integritas/skema dan regresi. `tests/remove_spp_deposit_migration_test.php` menerima path backup terverifikasi melalui `SPP_TEST_RETIREMENT_BACKUP`, `SPP_TEST_RETIREMENT_BACKUP_SHA256`, serta `SPP_TEST_MYSQL_BIN`.
3. Khusus aplikasi Laragon ini, tulis marker `tmp/financial_migration.lock` dengan isi `remove_spp_deposit_20261002` setelah memastikan tidak ada transaksi HTTP yang masih berjalan. `koneksi.php` memblokir HTTP `db_spp` dengan 503; clone/CLI dan proyek lain tetap tersedia. Di target lain, siapkan blokir penulisan yang setara dan otorisasi tersendiri.
4. Dengan otorisasi pemilik dan `SPP_DB_NAME=db_spp`, jalankan:

   ```powershell
   $env:SPP_ALLOW_MAIN_MIGRATION = '1'
   php sql/remove_spp_deposit.php --apply --confirm-main=db_spp --backup-file=C:\path\backup-baru.sql
   ```

5. Simpan output setiap tahap di luar Git. Setelah sukses, jalankan `tests/readiness_integrity_audit.php`, `sql/audit_foreign_keys.php`, `sql/restore_multiunit_checks.php`, `health.php`, dan audit penghapusan tanpa `--apply`. Bandingkan count/penerimaan serta fingerprint Tabungan dengan sebelum migrasi.
6. Hapus hanya marker milik migrasi ini setelah semua pemeriksaan lulus. Periksa health HTTP, URL lama 404 dan aplikasi dapat dibuka. Hentikan server/clone latihan yang identitasnya telah diperiksa; pertahankan backup.

## Pemulihan bila gagal

- Kegagalan preflight terjadi sebelum commit: rollback mempertahankan data, jangan memaksa menghapus.
- Jika muncul `DATA_COMMITTED` lalu error DDL/verifikasi: **pertahankan maintenance**. DDL tidak di-rollback bersama data. Jangan meneruskan memakai schema setengah berubah.
- Pulihkan dump penuh yang sudah diuji pada identitas database yang benar; terapkan versi aplikasi sebelum penghapusan (`ec3db42`) bersama skema lama. Pulihkan melalui prosedur DBA untuk target yang disetujui, bukan reset demo. Verifikasi baseline lama 1.037/Rp579.620.000, Tabungan dan health sebelum membuka penulisan.
- Tes clone membuktikan restore setelah kegagalan yang disuntikkan sesudah commit data. Rerun pada skema bersih hanya melaporkan sudah bersih; skema parsial memerlukan restore terlebih dahulu.

Deployment server lain belum dianggap siap hanya karena Laragon lulus. Pada instalasi kosong gunakan bootstrap schema baru; jangan impor schema fresh ke database lama.

## Arsip sebelum penghapusan Titipan SPP

> Seluruh bagian di bawah merupakan bukti historis sebelum pembersihan. Baseline, jumlah constraint, fitur titipan dan keputusan saat itu tidak menggantikan status terbaru di atas.

# Runbook dan catatan penerapan skema kesiapan operasional

**Status 2 Oktober 2026: migrasi skema lokal `db_spp` telah dijalankan setelah pemilik mengizinkan perubahan pada database dummy ini.** Dokumen ini mencatat urutan dan hasil penerapan; perintah `--apply` tidak perlu diulang untuk keadaan yang sudah cocok. DDL MySQL melakukan commit implisit. Pengujian target deployment, pemulihan layanan, dan keputusan siap operasional masih memerlukan pemeriksaan tersendiri.

## Catatan penerapan 2 Oktober 2026

- Sebelum DDL, dibuat backup baru di luar repository: `C:\laragon\backups\spp-management-system\db_spp_before_authorized_readiness_migration_20261002_0514.sql`, **1.398.718 byte**, SHA-256 `C885992603B5B3E55FDF450DD5FC8B97CF1914EC4A13ADA53815B98DBBEB5759`, waktu berkas **05:14:33 +07:00**. Backup ini dipertahankan.
- Backup dipulihkan ke clone `db_spp_audit_mainmigration_20261002`. Empat wrapper migrasi dijalankan pada clone dan verifikasi pra/pasca lulus. Setelah otorisasi pemilik, empat wrapper yang sama diterapkan pada `db_spp` dengan `SPP_ALLOW_MAIN_MIGRATION=1`, `--confirm-main=db_spp`, dan `--backup-file` di atas.
- Pada `db_spp`, tabel `keuangan_request` siap; kolom `keterangan` tersedia pada tabel dasar dan view untuk kedua jurnal tabungan; **19 `CHECK`** dan **dua trigger** cocok; audit foreign key melaporkan **64 terpasang, 0 hilang, 0 berbeda, 0 unresolved, 0 orphan, dan 0 preflight issue**. `health.php` mengembalikan `ok`.
- Data utama sebelum/sesudah DDL tetap **222 siswa, 210 penempatan, 2.520 SPP, 2.520 Komite, 210 DU, 16 biaya opsional, 1.037 pembayaran, Rp579.620.000 total kas header, 982 alokasi SPP, 982 detail Komite, 107 detail DU, 12 rekening tabungan, Rp1.050.000 saldo, serta 12 jurnal masuk dan 6 keluar**. Keempat belas pemeriksaan `tests/readiness_integrity_audit.php` tetap bernilai nol. Tidak ada siswa atau pembayaran uji yang dibuat pada `db_spp`.
- Enam tes HTTP terfokus pembayaran, DU, role, pengaman keuangan, catatan tabungan, dan pembayaran nol dijalankan ulang pada clone skema lengkap dan lulus. Ini bukti regresi lokal terpilih, bukan bukti seluruh alur dan target deployment.

## Penutupan penerimaan lokal 2 Oktober

Pengujian lanjutan pada clone skema lengkap telah selesai: enam perjalanan reguler/PSB sampai kelulusan pada SD/SMP/SMA, regresi pembayaran dan saldo, dua kasir pada SPP/Komite/DU, 333 request matriks akses, refresh sesi, rekonsiliasi laporan layar/Excel/PDF, 15 PDF surat, dan browser pendaftaran/pembayaran/kenaikan/keputusan otorisasi. Bug Komite ganda yang ditemukan pada konkurensi diperbaiki melalui current read setelah lock; tidak memerlukan migrasi baru. Hasil dan batas terperinci ada pada [audit kesiapan terkini](READINESS_AUDIT_20261001.md).

Backup pasca-migrasi sebelum penerimaan dipertahankan: `C:\laragon\backups\spp-management-system\db_spp_after_readiness_migration_acceptance_20261002.sql`, 1.412.033 byte, SHA-256 `767FD31948399DABA9E0906A8BB8341B2D546AEB1643220BDC2F2661E98AF3AE`. Verifikasi akhir utama tetap 222 siswa/1.037 pembayaran/Rp579.620.000 dan 14 invariant nol. Keputusan lokal **siap terbatas dengan syarat**; migrasi lokal sudah terpasang. Kesiapan server deployment, cetak fisik dan pemulihan layanan tetap diperiksa pada target sebenarnya.

## Bukti simulasi dan tujuan

Pada clone `db_spp_audit_migration_20261001` yang diimpor dari backup pra-audit, urutan tabel `keuangan_request` → kolom/view `keterangan` tabungan → 19 `CHECK` dan dua trigger → tiga penggantian aturan FK dan 51 FK baru berhasil. Audit akhir mencatat 64/64 FK cocok, `CHECK`/trigger lengkap, 14 invariant keuangan nol, dan baseline 222 siswa serta 1.037 pembayaran sebesar Rp579.620.000 tetap sama. Rerun skrip CHECK/FK tidak menambah DDL. Clone ini bukan pengganti backup baru tepat sebelum penerapan nyata.

## Prosedur pra-migrasi untuk target atau backup baru

1. Pastikan branch/commit yang benar-benar dipakai service Railway atau server lain. Branch review `audit/readiness-20261001` berisi kode baru; skema lokal `db_spp` sudah cocok, tetapi skema target deployment harus diperiksa sendiri. `health.php` sengaja mengembalikan `unavailable` bila tabel pengaman atau kolom jurnal belum ada.
2. Hentikan input pembayaran, penerbitan, perubahan siswa, dan tabungan. Catat waktu henti serta transaksi terakhir. Pastikan `SPP_DB_NAME` menunjuk database yang dimaksud, server MySQL dan pengguna database benar, dan tidak ada sesi penulisan yang masih berjalan.
3. Jalankan `tests/readiness_integrity_audit.php`, `sql/audit_foreign_keys.php`, `sql/restore_multiunit_checks.php`, `sql/add_savings_notes.php`, dan `sql/add_financial_request_guard.php` tanpa `--apply`. Hentikan bila angka/invariant berbeda dari baseline yang baru dicatat, ada data yatim, pelanggaran CHECK, atau definisi constraint tak dikenal.
4. Untuk target baru atau pengulangan terencana, buat dump **baru di luar repository** dengan `mysqldump --single-transaction --routines --triggers --result-file=<path-backup> <database-target>`. Verifikasi ukuran, hash SHA-256, waktu pembuatan, dan bahwa dump tidak memuat `USE`/`CREATE DATABASE` yang mengarah ke target lain. Impor dump itu ke clone bernama `db_spp_audit_*`, lalu ulangi pratinjau migrasi dan audit integritas di clone. Jangan gunakan backup 30 September atau backup 2 Oktober sebagai pengganti dump tepat sebelum perubahan berikutnya.

## Urutan wrapper yang dijalankan pada clone dan `db_spp`

Pada penerapan 2 Oktober, target dan backup diverifikasi sebelum menyetel `SPP_DB_NAME=db_spp` dan `SPP_ALLOW_MAIN_MIGRATION=1`; empat wrapper berikut memakai **path backup baru yang sama**. Untuk target berikutnya, persetujuan, backup, dan verifikasi target harus diulang. Definisi untuk perintah pertama berada sebagai payload base64 non-SQL di `sql/definitions/`; jalur `sql/add_financial_request_guard.sql` menolak impor langsung. Jangan menjalankan file definisi melalui klien MySQL; hanya wrapper PHP yang memeriksa target dan mendekodenya.

```text
php sql/add_financial_request_guard.php --apply --confirm-main=db_spp --backup-file=<path-backup>
php sql/add_savings_notes.php --apply --confirm-main=db_spp --backup-file=<path-backup>
php sql/restore_multiunit_checks.php --apply --confirm-main=db_spp --backup-file=<path-backup>
php sql/restore_multiunit_foreign_keys.php --apply --confirm-main=db_spp --backup-file=<path-backup>
```

Pengaman PHP mensyaratkan nama database tepat `db_spp`, flag lingkungan, konfirmasi di argumen, dan file backup di luar workspace yang berumur paling lama satu jam. FK diperiksa sebelum DDL; tiga aturan `ON UPDATE` yang berbeda diganti dengan satu `ALTER TABLE` per relasi, kemudian FK yang hilang ditambah. Pada penerapan ini pemeriksaan akhir bersih. Bila prasyarat atau satu DDL pada target lain gagal, hentikan rangkaian, simpan output dan inventaris skema aktual, lalu nilai keadaan sebelum melanjutkan. Jangan menganggap rangkaian DDL otomatis rollback.

## Verifikasi yang berlaku sebelum rilis operasional

- `health.php` harus `ok`; `includes/financial_request.php` harus menerima bentuk tabel InnoDB, kunci unik, dan `referensi_id BIGINT`.
- Audit FK harus melaporkan 64/64, tanpa missing/mismatch/orphan/preflight issue. Audit CHECK harus melaporkan 0 missing dan dua trigger penjaga tingkat tarif sesuai definisi/urutan. Kondisi tersebut telah diperiksa pada `db_spp` lokal tanggal 2 Oktober; ulangi pada target deployment yang sebenarnya.
- `tests/readiness_integrity_audit.php` harus menunjukkan hitungan siswa, penempatan, tagihan, pembayaran, total kas, tabungan, dan seluruh 14 invariant sama dengan pra-migrasi. Kolom `keterangan` harus tersedia di kedua tabel jurnal dan view.
- Gunakan akun role yang sah untuk memeriksa login dan halaman baca-saja pada SD/SMP/SMA. Uji transaksi mutatif lanjutan hanya dengan transaksi operasional nyata yang disetujui, sambil mencocokkan struk, riwayat, kas, dan laporan. Jangan membuat data uji di `db_spp`.
- Baru setelah seluruh pemeriksaan pada **target deployment** lulus, arahkan deployment ke commit yang disetujui dan buka kembali penulisan. Pantau transaksi pertama dan catat commit, waktu, operator, backup, serta hasil audit. Migrasi lokal ini sendiri belum menjadi keputusan rilis.

## Jika penerapan gagal

Jangan melakukan `DROP` constraint/tabel secara spontan. Jika belum ada transaksi baru sejak dump, pemulihan dari backup dapat diputuskan oleh pemilik setelah membandingkan keadaan aktual; uji restore pada clone lebih dulu. Jika ada transaksi baru, restore langsung dapat menghilangkannya: bekukan penulisan, ekspor keadaan saat gagal, rekonsiliasi transaksi baru, dan tetapkan prosedur pemulihan khusus. Rollback kode saja tidak membuat skema lama dan kode baru otomatis kompatibel.
