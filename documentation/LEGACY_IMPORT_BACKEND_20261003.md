# Backend impor identitas Legacy — 3 Oktober 2026

## Status pemeriksaan

**Siap untuk trial impor siswa Legacy pada Laragon lokal, untuk profil backup yang diuji.** Implementasi `62dd273` di atas `926753b` sudah dipindahkan ke `main` dengan fast-forward tanpa konflik, migrasi trial diterapkan dan ketiga backup diimpor. Tidak ada aktivasi massal. Bukti pribadi, dump, staging, log, dan tangkapan layar berada di `C:\laragon\backups\spp-management-system\legacy_backend_20261003`, bukan Git.

Importer ini hanya memindahkan **identitas**, bukan histori keuangan. Backup/Restore penuh tetap nonaktif. LocalDB 2019 diperlukan untuk membuka tiga profil backup yang sudah diaudit; worker berjalan sebagai pengguna Windows pemilik instance, bukan sebagai proses restore dari HTTP.

## Hasil tiga backup nyata

| Unit | Baris sumber | Identitas diterima | Ditahan | GK bukan siswa |
|---|---:|---:|---:|---:|
| SD | 369 | 308 | 3 | 58 |
| SMP | 787 | 708 | 5 | 74 |
| SMA | 131 | 121 | 0 | 10 |
| Total | 1.287 | 1.137 | 8 | 142 |

Setiap baris memiliki ordinal sumber, raw data, hash backup, hasil, dan alasan. GK memiliki bukti Guru/Karyawan dari audit. Kode Admin/Tab/Guru yang belum terbukti ditahan; bukan otomatis alumni. Identitas tidak memenuhi target atau NIS ganda dalam satu sumber juga ditahan. Nomor asli dan nol di depan dipertahankan. NIS Diknas yang tidak valid/unik disimpan di metadata asli, tanpa dipaksakan ke kolom operasional. Nama/kode kelas sumber tidak digunakan untuk menebak riwayat atau status.

## Kontrak aplikasi

- Primary key siswa `id` tetap unik global. NIS unik pada `(unit_id, NO_INDUK)`; FK dan indeks unik anak memakai pasangan unit/NIS. Join dan agregasi gabungan memakai unit serta ID; URL NIS ambigu menghasilkan penolakan, bukan pemilihan otomatis.
- Siswa impor: `legacy_pending=1`, `is_active=0`, `KELAS=LEGACY`, rombel NULL, tanpa penempatan. Filter Legacy terpisah dari Aktif dan Arsip/Lulus. Data Siswa/Semua tetap menampilkannya.
- Pengaman database menolak penempatan dan relasi transaksi/tagihan siswa pending. Pulihkan/Edit biasa tidak dapat mengaktifkan Legacy.
- **Aktifkan & Tempatkan** tersedia sesuai hak admin/kasir/Super Admin pada satu unit. Tahun ajaran, rombel, dan tarif dikonfirmasi; SPP cocok dengan master. Transaksi mengunci akun/siswa, membuat satu penempatan awal, memperbarui status, dan audit. Replay ditolak. Tidak membuat tagihan, pembayaran, kelulusan, atau rekening Tabungan.
- Penerbitan SPP melalui master menyiapkan pasangan Komite dari snapshot penempatan dengan `INSERT IGNORE`; penerbitan ulang tidak menimpa pembayaran. Temuan ini muncul pada pengujian siswa Legacy yang tidak menjalani pendaftaran otomatis.
- Status alumni, tahun masuk/lulus, kelas tahun lampau, tarif/tunggakan/saldo legacy tidak direkonstruksi.

## Alur dan pengaman importer

Super Admin memilih satu unit sidebar yang cocok dengan unit sumber, lalu satu `.dat` 1 byte–100 MiB. Semua Unit hanya baca. Upload bernama server acak di luar document root; SHA-256 dihitung server. Queue/staging SQLite privat dengan checkpoint; satu worker berat melalui lock. LocalDB instance khusus dan database acak per job, lokasi MDF/LDF baru, header/profile/version/encryption/full-set, VERIFYONLY, CHECKDB, READ_ONLY, dan whitelist tabel/kolom diperiksa sebelum ekstraksi. Tidak menjalankan kode legacy.

Progress: byte upload, baris ekstraksi/validasi aktual, indikator tanpa persentase untuk tahap yang tidak terukur. Modal dapat ditutup/dibuka kembali dan tidak membatalkan pekerjaan. Pratinjau maksimal 100 baris; CSV memuat semua hasil. `IMPOR LEGACY` diperlukan sebelum penerapan. Role/keaktifan diperiksa lagi sebelum commit. Semua perubahan API memakai CSRF; job acak bukan pengganti otorisasi. Nama file ditampilkan sebagai teks.

Penerapan seluruh kandidat dalam satu transaksi. Manifest `(unit, hash, ordinal)` membuat reimport/recovery idempotent; backup berbeda yang bertabrakan ditahan/ditolak, tidak menimpa siswa. Restart worker menahan ekstraksi terputus dan membersihkan hanya proses/database miliknya setelah pemeriksaan PID, waktu pembuatan, command line, dan lokasi file. Penerapan terputus dapat diputar ulang berdasarkan manifest. Maintenance menahan antrean; worker harus dihentikan sebelum DDL trial.

## Bukti regresi

| Pemeriksaan | Hasil yang dibuktikan |
|---|---|
| Tiga backup, ulang impor | HEADER/FILELIST/VERIFY/restore/CHECKDB/read-only/profil; 1.287 baris terpetakan; 1.137 Legacy; ulang tidak menggandakan |
| Clone bersih | 222→1.359 siswa; semua 31 tabel lama selain siswa identik; siswa existing dibandingkan per ID; pembayaran 1.018/Rp577.145.000; Tabungan 12/Rp1.050.000 |
| Migrasi | Restore dump terverifikasi, FK hilang ditolak sebelum DDL, skema parsial diselesaikan, replay idempotent, CHECK diperbaiki, nilai original tetap |
| Instalasi baru | Bootstrap multiunit, migrasi Legacy, tarif SMP/SMA dan seed langsung; ulang tanpa duplikasi/Titipan |
| Identitas sama tiga unit | NIS bernol awal, pembayaran/saldo berbeda, pencarian ID, URL ambigu, rekap/Excel/PDF/struk/buku tidak tertukar; kop massal sesuai pemilik |
| Aktivasi | Admin/kasir tiga unit, CSRF, lintas unit, tarif salah, halaman lama/replay; tepat satu penempatan, tanpa anak otomatis |
| Siklus siswa | Reguler dan PSB tiga unit sampai lulus; tambahan tiga siswa dari backup nyata: pending diblokir, aktivasi, master, pembayaran, tiap kenaikan, laporan historis, lulus |
| Gagal/recovery | File palsu/rusak/unit profil salah, kosong/ekstensi/batas ukuran, cancel, otorisasi berubah, parent worker dihentikan/restart, rollback baris kedua, replay commit, maintenance |
| Akses | API anonim/non-Super/role palsu/nonaktif/diturunkan/CSRF/unit/ID traversal; 307 endpoint existing dan perubahan sesi/role/unit |
| Finansial | Input/edit/hapus/otorisasi, zero-payment, Daftar Ulang, Komite, Tabungan/jurnal; dua kasir pada kewajiban SPP/Komite/DU; totals dan cleanup |
| Laporan | Sepuluh template tiga unit pada sumber/layar/Excel/PDF biner; periode asal/tujuan, status, pembatalan; PDF struk dirender dan diperiksa |
| UI | CSS shared cocok acuan; 48 keadaan dua mode Backup/Legacy + 24 backend + 18 aktivasi, empat cakupan/dua tema/1440/2560/390 sesuai akses; modal keyboard/file/filter; nol JS error/overflow setelah transisi responsif selesai |
| Integritas | 26 pemeriksaan bersih pada clone migrasi/impor; FK unit, Legacy pending, metadata, seluruh kewajiban/receipts/saldo |

### Kegagalan alat/fixture yang dipisahkan

DDL CHECK dengan rombel FK cascading MySQL ditangani oleh trigger; DROP/ADD nama FK yang sama diganti nama constraint baru. Restore dump untuk tes wajib memakai klien MySQL karena DELIMITER bukan perintah SQL mysqli. Fixture lama mengasumsikan NIS global dan diperbarui untuk unit. Vendor PDF runtime pada worktree harus tersedia. Satu fixture aktivasi gagal sebelum manifest dibuat dibersihkan hanya pada clone. Tes fingerprint atomik sempat bertabrakan dengan tes lifecycle yang sedang menulis: dijalankan ulang berurutan dan lulus. Pembandingan final prepared-result vs text-result sempat berbeda pada tipe numerik; fixture diganti dengan query text pada kedua sisi. Perubahan tarif operasional sesudah impor dicatat terpisah, bukan dianggap kegagalan importer.

Upload HTTP sintetis 100 MiB melalui Playwright maupun curl ke server PHP latihan habis waktu sebelum body lengkap (Unexpected EOF), tanpa job/data. Batas 100 MiB diuji melalui objek file UI serta fungsi validasi yang **sama** dipakai API/worker enqueue, termasuk berkas 100 MiB+1 dan respons 413. Ini bukan bukti throughput upload maksimum. Tiga backup nyata 15–29 MB berhasil melalui worker dan upload browser SD. Timeout transport tidak dinyatakan bug SQL/import atau keberhasilan upload.

## Batas kesiapan

Kesimpulan hanya **trial impor identitas Legacy pada Laragon** untuk tiga profil backup yang dibuktikan. Tidak menyatakan migrasi histori keuangan, restore web penuh, throughput 100 MiB, atau deployment pada akun/server lain siap. Startup worker manual tersedia; jalankan di bawah pengguna Windows yang memiliki instance. Operasional sekolah, klasifikasi delapan baris tertahan, aktivasi satu per satu, serta histori keuangan tetap pekerjaan lanjutan.

Lihat [runbook worker/migrasi/pemulihan](LEGACY_IMPORT_RUNBOOK_20261003.md) dan [audit sumber historis](LEGACY_IMPORT_FEASIBILITY_20261003.md).

## Penerapan trial dan verifikasi akhir

- Backup eksternal terverifikasi/restore clone: `C:\laragon\backups\spp-management-system\legacy_backend_20261003\pre-legacy-trial.sql`; hash disimpan di sidecar `.sha256`. Dump mencakup routines/triggers; nilai main sebelum DDL masih identik dengan baseline yang dibackup. Backup original legacy dan salinan tidak diubah.
- Maintenance khusus SistemSPP dipasang untuk DDL dan dilepas setelah skema, semua nilai original, serta integritas bersih. Aplikasi lain tidak dihentikan. Worker dihidupkan setelah DDL, menggunakan kode/queue/profile/apply yang sama dengan clone; konfirmasi tiga sumber dilakukan lewat harness CLI lokal atas otorisasi pemilik.
- Tepat setelah impor: **1.359 siswa = 222 existing + 1.137 Legacy**. Legacy SD 308, SMP 708, SMA 121; seluruhnya pending/inactive, rombel NULL, nol penempatan/tagihan/transaksi/rekening. Delapan ditahan dan 142 GK tetap pada staging privat, tidak hilang atau diaktifkan otomatis.
- **Pada snapshot tepat setelah impor, seluruh 222 ID/nilai siswa original identik**, dengan tambahan marker default 0. **31 tabel original lain memiliki SHA-256 baris yang identik**, termasuk admin, master, penempatan, pembayaran/detail/alokasi, rekening dan kedua jurnal Tabungan. Pembayaran **1.018/Rp577.145.000**, rekening **12/saldo Rp1.050.000**; penempatan original **210**, SPP/Komite **2.520 masing-masing**, DU **210**.
- Setelah aplikasi dibuka kembali, tercatat dua pembayaran operasional SD (+Rp645.000) dan aksi `ubah_tarif` SD dari sesi Super Admin pada 12:30:28. Ini terjadi sesudah snapshot verifikasi impor, bukan penulisan fixture audit. Angka terbaru pada pemeriksaan akhir: **1.359 siswa, 1.020 pembayaran/Rp577.790.000**, Tabungan **12 rekening/Rp1.050.000**. Seluruh pembayaran/detail/alokasi original tetap identik per primary key dan fingerprint Tabungan tetap sama. Perubahan tarif menyentuh satu master, SPP aktif 24 siswa existing, serta 119 tagihan yang tidak memiliki alokasi pembayaran; snapshot tagihan berbayar tetap terlindungi. Data tersebut dipertahankan. Bukti pembandingan original dipulihkan dari backup ke clone terpisah; clone dihapus dalam `finally`. Tidak memakai klaim seluruh fingerprint akhir identik ketika aplikasi telah menerima aktivitas operasional baru.
- HTTP Laragon `health.php` 200 `ok`; readiness importer/status ketiga pekerjaan/list Legacy/halaman Backup & Restore lulus baca saja. Main diperiksa pada tambahan **24 keadaan visual** empat cakupan/dua tema/tiga viewport, tanpa mutasi aplikasi. 26 integritas bersih; 61 FK kontrak existing lengkap/cocok, ditambah FK manifest unit/student.
- GitHub terbaru ditinjau: origin/main masih `926753b`, tidak ada perubahan pihak lain yang divergen atau konflik. Sintaks PHP/JS/PowerShell, CSS acuan dan tes terdampak publikasi/kelas/laporan diulang sebelum pengiriman.
- Dua HTTP latihan dan worker clone dihentikan setelah PID/command/waktu pembuatan diperiksa. Dua database latihan dan dua instance LocalDB khusus clone dihapus setelah kepemilikan serta nol database source dibuktikan. Default LocalDB/runtime shared tetap; worker/storage/instance importer trial dipertahankan. Worktree, dump dan bukti privat tetap di luar repo. Tidak ada penolakan cleanup otomatis pada pekerjaan ini.
- Worker trial berjalan manual sebagai pengguna pemilik Windows. Setelah restart/logoff perlu dijalankan kembali lewat runbook; task/service startup otomatis belum dipasang. Import kategori finansial dan Backup/Restore penuh tetap nonaktif.
