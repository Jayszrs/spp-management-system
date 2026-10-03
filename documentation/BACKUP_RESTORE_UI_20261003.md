# Backup, Restore, dan Import Legacy — tahap antarmuka

## Status 3 Oktober 2026

Antarmuka tersedia melalui **Pengaturan → Backup & Restore**, hanya untuk Super Admin. Implementasi di atas kode `de10aef` pada `main`. Halaman menggunakan stylesheet/JavaScript khusus dengan versi `filemtime`; stylesheet bersama tidak berubah.

**Ini belum layanan backup, restore, konversi, atau importer.** Tidak ada endpoint operasi, upload, pembacaan isi SQL, eksekusi SQL, tabel, migrasi, maupun proses SQL Server baru. Pemilihan file hanya menyimpan objek File di browser. Tombol pembuatan backup, pemulihan, pemetaan, dan penerapan tetap nonaktif.

## Perilaku

- Dua mode: Backup & Restore serta Import Legacy, dengan navigasi tab keyboard.
- Backup/restore selalu mencakup seluruh database SD/SMP/SMA. Pilihan sidebar tidak membatasi cakupan tersebut.
- Status koneksi dan estimasi ukuran tabel/indeks berasal dari query baca MySQL. Ukuran merupakan estimasi, bukan ukuran berkas backup. Informasi backup terakhir, jumlah backup, dan riwayat tetap **Belum tersedia** sampai layanan/manifest ada.
- Satu berkas `.sql`, tidak kosong, maksimal 100 MiB (100 × 1.024 × 1.024 byte). Pemeriksaan awal nama/jumlah/ukuran hanya menghasilkan **Belum divalidasi**; bukan bukti SQL aman atau kompatibel.
- Modal menjelaskan penggantian seluruh siswa, pembayaran, Tabungan, akun, serta pengaturan; checkbox dan frasa konfirmasi tidak mengaktifkan operasi. Escape/Batal menutup modal dan mengembalikan fokus.
- Legacy: kategori Siswa, Kelas & Tarif, Tagihan & Pembayaran, Tabungan; unit sumber SD/SMP/SMA wajib dipilih. Unit sumber tidak mengubah sesi sidebar. Tiga tahap setelah pemilihan terkunci.
- `.dat` perlu konversi; `.sql` tidak otomatis kompatibel MySQL. Benturan NIS/status dan keterbatasan histori tidak diselesaikan otomatis. [Audit kelayakan](LEGACY_IMPORT_FEASIBILITY_20261003.md) tetap menjadi bukti kelayakan sumber.
- GET tersedia setelah validasi sesi/role terbaru. POST/PUT/PATCH/DELETE ditolak; guard mode baca Semua Unit tetap berlaku. Belum ada pengecualian administratif untuk pemulihan.

## Pengujian aktual

Target HTTP: loopback port 8133, database khusus `db_spp_audit_backup_ui_20261003`, dengan flag `SPP_TEST_ALLOW_MUTATION=1` dan pemeriksaan identitas endpoint sebelum tes. Clone baru berasal dari backup baca utama. Fixture hanya mengubah password akun clone; tes role mengembalikan keadaan akun lewat finally.

| Pemeriksaan | Hasil |
|---|---|
| PHP halaman, sidebar dan tes akses; sintaks JS | Lulus |
| CSS khusus melalui PostCSS/css-tree serta CSSOM Chromium sampai media query terakhir | Lulus |
| Acuan CSS bersama `ui_css_regression_test.js` | Lulus; stylesheet bersama tidak berubah |
| SQL berformat awal, ekstensi kapital, kosong, ekstensi salah, .dat, >100 MiB, tepat 100 MiB | Lulus |
| Pilihan ganda melalui drop, drop tunggal, ganti melalui file chooser, hapus | Lulus |
| Nama berisi markup HTML pada ringkasan/modal | Ditampilkan sebagai teks; tidak membuat elemen HTML |
| Modal: checkbox/frasa, operasi tetap nonaktif, fokus dalam modal, Escape/Batal dan reset saat dibuka ulang | Lulus |
| Tab keyboard, kategori, sumber SD/SMP/SMA tanpa Semua Unit, tahap terkunci | Lulus |
| Empat palette × dua tema × viewport 1440/2560/390 × dua mode | 48 screenshot dan pemeriksaan layout lulus; tidak ada overflow dokumen |
| Tablet 900 px | Dua kolom ringkasan lulus |
| Menu/URL Super Admin; sesi lama setelah role berubah/nonaktif; permintaan tulis pada empat cakupan | Lulus |
| Guard Semua Unit/CSRF/gate transaksi existing | `all_units_http_test.php` lulus |
| Navigasi Dashboard, Role Management, Laporan Global, Cetak Tabungan | Menu tersedia; CSS khusus tidak dimuat pada halaman lama |
| Kontrol browser SD/SMP/SMA existing | `ui_controls_browser_test.js` lulus: unit/tema/sidebar, dropdown Daftar Ulang input/edit, filter, tabel, Excel/PDF/preview serta buku Tabungan |
| Pengiriman berkas/JavaScript error | Tidak ada upload atau error pada alur UI |
| Fingerprint utama dan clone sebelum/sesudah | 32 tabel masing-masing identik |

Kendala alat tes: Playwright membatasi buffer unggah tes di atas 50 MB. Tes batas 100 MiB menggunakan File/DataTransfer di browser, tanpa unggah. Regresi awal ponsel memperlihatkan lebar intrinsik tabel membesarkan halaman; lebar wrapper diperbaiki di CSS khusus lalu seluruh matriks diulang. Keadaan kosong tabel diperjelas pada ponsel dan kontras tab aktif menyesuaikan tema gelap.

## Baseline dan bukti

Database utama tetap **222 siswa, 1.018 pembayaran, Rp577.145.000 penerimaan, 12 rekening Tabungan, saldo Rp1.050.000**. Fingerprint seluruh tabel, termasuk rekening dan jurnal Tabungan, identik. Tidak ada data uji di utama.

Bukti lokal di luar Git: `C:\laragon\backups\spp-management-system\backup_restore_ui_20261003`: baseline SQL, fingerprint sebelum/sesudah, 48 screenshot, dan `visual-results.json`. Backup dan bukti dipertahankan. Server tes dan clone milik pekerjaan ini dihentikan/dihapus setelah verifikasi; layanan Laragon dan backup asli tidak disentuh.

GitHub terbaru diambil dan diperiksa; `origin/main` masih `de10aef`, tanpa konflik. Audit kelayakan legacy yang sebelumnya belum tercatat di Git disertakan agar tautan halaman mempunyai dokumen sumber.

## Pekerjaan backend berikutnya

Layanan backup/manifest, penyimpanan privat dan unduh, validasi backup, pengamanan maintenance/restore dan salinan wajib sebelum pemulihan, serta staging/pemetaan/importer legacy masih belum dibangun. Membutuhkan rencana dan pengujian tersendiri sebelum tombol operasional diaktifkan. Kelulusan antarmuka ini tidak menyatakan pemulihan atau migrasi legacy siap digunakan.
