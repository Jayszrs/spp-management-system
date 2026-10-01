# Rencana penerapan skema kesiapan operasional

**Status: untuk review pemilik, belum diizinkan pada `db_spp`.** Instruksi audit pemilik menyatakan database utama hanya boleh dibaca sampai ada persetujuan terpisah. Perubahan di bawah menggunakan DDL MySQL yang melakukan commit implisit; seluruh langkah harus dijalankan saat penulisan aplikasi dihentikan. Jangan menyalin perintah ini ke terminal utama sebelum persetujuan, jendela henti layanan, dan target deployment disepakati.

## Bukti simulasi dan tujuan

Pada clone `db_spp_audit_migration_20261001` yang diimpor dari backup pra-audit, urutan tabel `keuangan_request` → kolom/view `keterangan` tabungan → 19 `CHECK` dan dua trigger → tiga penggantian aturan FK dan 51 FK baru berhasil. Audit akhir mencatat 64/64 FK cocok, `CHECK`/trigger lengkap, 14 invariant keuangan nol, dan baseline 222 siswa serta 1.037 pembayaran sebesar Rp579.620.000 tetap sama. Rerun skrip CHECK/FK tidak menambah DDL. Clone ini bukan pengganti backup baru tepat sebelum penerapan nyata.

## Sebelum perubahan utama

1. Pastikan branch/commit yang benar-benar dipakai service Railway atau server lain. Branch review `audit/readiness-20261001` berisi kode baru; jangan aktifkan sebelum skema tujuan lengkap. `health.php` sengaja mengembalikan `unavailable` bila tabel pengaman atau kolom jurnal belum ada.
2. Hentikan input pembayaran, penerbitan, perubahan siswa, dan tabungan. Catat waktu henti serta transaksi terakhir. Pastikan `SPP_DB_NAME` menunjuk database yang dimaksud, server MySQL dan pengguna database benar, dan tidak ada sesi penulisan yang masih berjalan.
3. Jalankan `tests/readiness_integrity_audit.php`, `sql/audit_foreign_keys.php`, `sql/restore_multiunit_checks.php`, `sql/add_savings_notes.php`, dan `sql/add_financial_request_guard.php` tanpa `--apply`. Hentikan bila angka/invariant berbeda dari baseline yang baru dicatat, ada data yatim, pelanggaran CHECK, atau definisi constraint tak dikenal.
4. Buat dump **baru di luar repository** dengan `mysqldump --single-transaction --routines --triggers --result-file=<path-backup> db_spp`. Verifikasi ukuran, hash SHA-256, waktu pembuatan, dan bahwa dump tidak memuat `USE`/`CREATE DATABASE` yang mengarah ke target lain. Impor dump itu ke clone bernama `db_spp_audit_*`, lalu ulangi urutan migrasi dan audit integritas di clone. Jangan gunakan backup 30 September sebagai pengganti dump baru.

## Urutan penerapan setelah persetujuan eksplisit

Set `SPP_DB_NAME=db_spp` dan `SPP_ALLOW_MAIN_MIGRATION=1` setelah verifikasi koneksi dan persetujuan pemilik. Jalankan keempat perintah PHP berikut dengan **path backup baru yang sama**. File `.sql` adalah definisi internal untuk perintah pertama; jangan menjalankannya langsung melalui klien MySQL karena jalur itu tidak memakai pengaman target PHP.

```text
php sql/add_financial_request_guard.php --apply --confirm-main=db_spp --backup-file=<path-backup>
php sql/add_savings_notes.php --apply --confirm-main=db_spp --backup-file=<path-backup>
php sql/restore_multiunit_checks.php --apply --confirm-main=db_spp --backup-file=<path-backup>
php sql/restore_multiunit_foreign_keys.php --apply --confirm-main=db_spp --backup-file=<path-backup>
```

Pengaman PHP mensyaratkan nama database tepat `db_spp`, flag lingkungan, konfirmasi di argumen, dan file backup di luar workspace yang berumur paling lama satu jam. FK diperiksa sebelum DDL; tiga aturan `ON UPDATE` yang berbeda diganti dengan satu `ALTER TABLE` per relasi, kemudian FK yang hilang ditambah. Bila preflight atau satu DDL gagal, hentikan rangkaian, simpan output dan inventaris skema aktual, lalu nilai keadaan sebelum melanjutkan. Jangan menganggap rangkaian DDL otomatis rollback.

## Verifikasi sebelum membuka penulisan kembali

- `health.php` harus `ok`; `includes/financial_request.php` harus menerima bentuk tabel InnoDB, kunci unik, dan `referensi_id BIGINT`.
- Audit FK harus melaporkan 64/64, tanpa missing/mismatch/orphan/preflight issue. Audit CHECK harus melaporkan 0 missing dan dua trigger penjaga tingkat tarif sesuai definisi/urutan.
- `tests/readiness_integrity_audit.php` harus menunjukkan hitungan siswa, penempatan, tagihan, pembayaran, total kas, tabungan, dan seluruh 14 invariant sama dengan pra-migrasi. Kolom `keterangan` harus tersedia di kedua tabel jurnal dan view.
- Gunakan akun role yang sah untuk memeriksa login dan halaman baca-saja pada SD/SMP/SMA. Uji transaksi mutatif lanjutan hanya dengan transaksi operasional nyata yang disetujui, sambil mencocokkan struk, riwayat, kas, dan laporan. Jangan membuat data uji di `db_spp`.
- Baru setelah seluruh pemeriksaan lulus, arahkan deployment ke commit yang disetujui dan buka kembali penulisan. Pantau transaksi pertama dan catat commit, waktu, operator, backup, serta hasil audit.

## Jika penerapan gagal

Jangan melakukan `DROP` constraint/tabel secara spontan. Jika belum ada transaksi baru sejak dump, pemulihan dari backup dapat diputuskan oleh pemilik setelah membandingkan keadaan aktual; uji restore pada clone lebih dulu. Jika ada transaksi baru, restore langsung dapat menghilangkannya: bekukan penulisan, ekspor keadaan saat gagal, rekonsiliasi transaksi baru, dan tetapkan prosedur pemulihan khusus. Rollback kode saja tidak membuat skema lama dan kode baru otomatis kompatibel.
