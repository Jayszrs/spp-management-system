# Semua Unit untuk data dan rekap — 3 Oktober 2026

## Perilaku yang diterapkan

Basis kode: `c8f5ac9` pada `main`, sesudah pemulihan UI. Paket ini tidak mengubah stylesheet, skema, atau data database utama.

- Super Admin dapat memilih **Semua Unit** di sidebar untuk Dashboard, siswa/master, riwayat, laporan, surat, dan pengaturan dalam mode baca. Pilihan disimpan dalam sesi. Pemilih cakupan laporan dan Dashboard memperbarui sesi melalui POST dengan CSRF; URL laporan lama tetap didukung sebagai cakupan baca.
- Input/edit pembayaran, Tabungan Masuk/Keluar, dan halaman otorisasi hanya menawarkan **SD, SMP, SMA**. Dari sesi gabungan, halaman menampilkan **Pilih unit untuk transaksi**; formulir keuangan belum dimuat dan tidak ada unit yang dipilih otomatis.
- POST perubahan dari sesi gabungan ditolak dengan 409. Permintaan transaksi yang secara eksplisit mengirim cakupan gabungan melalui query/POST, atau memilih `unit_id=0` untuk tujuan transaksi di endpoint pergantian unit, ditolak dengan 422. Role/unit/keaktifan akun dibaca ulang dari database; CSRF dan pemeriksaan kepemilikan yang sudah ada tetap berlaku.
- Formulir siswa/master, penerbitan, kenaikan, dan pengaturan serta tombol mutasinya disembunyikan dalam mode baca. Pengaman server tetap bekerja tanpa JavaScript. Pilih satu unit dahulu untuk mengubah data.
- Pergantian unit membersihkan ID, siswa, rombel, pencarian, operator, kategori, halaman/detail, dan pilihan terkait unit; tanggal dipertahankan. Berpindah dari edit pembayaran kembali ke riwayat agar ID lama tidak terbawa.
- Baris, hasil pencarian, pilihan rombel, dan ekspor gabungan menyertakan identitas unit. Tahun ajaran tidak diduplikasi; status tahun yang berbeda antarunit dinyatakan beragam. Master SPP/Daftar Ulang gabungan berupa tarif per unit/tahun, tanpa memilih satu tahun arbitrer.
- Riwayat kelas, tarif snapshot, urutan SPP/Komite, pembayaran langsung, dan penghapusan Titipan SPP tetap berlaku. Struk, buku Tabungan, dan surat individual memakai sekolah pemilik data.
- Tema SD hijau, SMP biru, SMA merah dan palette Super Admin yang sudah tersedia tetap dipakai. Tidak ada redesign.

## Temuan yang diperbaiki dalam paket ini

Rekap mutasi Tabungan sebelumnya mengaitkan tahun ajaran hanya melalui tanggal. Pada cakupan gabungan, satu jurnal dapat bergabung dengan tiga tahun ajaran dari tiga unit sehingga jumlahnya berlipat. Join sekarang juga mensyaratkan `tahun_ajaran.unit_id = siswa.unit_id`.

PSB/rombel dikelompokkan memakai unit dan ID penempatan/master, termasuk fallback tanpa master. Label kelas gabungan dipisahkan dari label aslinya sehingga detail Surat Kepala Sekolah tidak kehilangan siswa akibat awalan unit. Detail tiga unit diuji lewat tautan rombel yang sebenarnya; jumlah siswa detail cocok dengan rekap.

## Hasil verifikasi

Seluruh tes mutasi menggunakan database disposable, flag tes, dan pemeriksaan identitas server HTTP. Utama diperiksa baca saja.

| Cakupan | Bukti lulus pada paket ini |
| --- | --- |
| Pengaman Semua Unit | `all_units_http_test.php`: lima gate transaksi, sepuluh endpoint tulis, query/POST cakupan terlarang, CSRF, pergantian unit/filter, refresh role dan akun nonaktif. Fingerprint seluruh tabel bisnis selain akun fixture sama sebelum/sesudah. |
| Jumlah dan nominal gabungan | `all_units_reports_test.php`: sepuluh template; nominal gabungan sama dengan penjumlahan SD/SMP/SMA, jumlah baris sama untuk laporan per siswa/rombel, PSB terpisah dan pilihan tahun tidak ganda. Laporan kas memang merangkum komponen, sehingga jumlah barisnya tidak dijumlahkan antarunit. |
| Layar, Excel, PDF dan cetak | `all_units_exports_http_test.php`: sepuluh template cocok dengan sumber; PDF biner diekstrak memakai `pdftotext`. Struk HTML serta PDF buku/surat orang tua memakai sekolah pemilik untuk ketiga unit dari sesi gabungan. Rekap matriks riwayat tagihan mempertahankan ringkasan total tagihan yang tersedia; tidak diklaim memiliki grand total bayar/sisa yang memang tidak ditampilkan desainnya. |
| Interaksi gabungan | `all_units_browser_test.js`: sesi/sidebar/cakupan laporan dan Dashboard, daftar baca, gate tanpa opsi Semua Unit, pilihan unit eksplisit, detail rombel tiga unit, struk/buku dan dropdown Daftar Ulang. |
| Perjalanan siswa | `full_student_lifecycle_http_test.php` dan `psb_to_regular_http_test.php`, masing-masing SD/SMP/SMA: enam perjalanan HTTP dari pendaftaran/penerbitan/pembayaran, kenaikan per tahun, rekap historis sampai kelulusan, dengan pemeriksaan keadaan database. |
| Pembayaran dan akses | `payment_process_integration_test.php`, `payment_role_access_test.php`, `endpoint_access_matrix_http_test.php` (307 request dan fingerprint tetap sama), `invalid_unit_account_http_test.php`, `unit_switch_csrf_http_test.php`: nominal tepat, urutan, Komite wajib, edit/otorisasi, akses ID asing dan sesi. |
| Kenaikan, penerbitan, tarif dan histori | `class_promotion_multiunit_test.php`, `class_promotion_sequence_test.php`, `class_rombel_transfer_promotion_test.php`, `historical_reports_after_promotion_test.php`, `report_receipt_class_snapshot_test.php`, `report_spp_unbilled_status_test.php`, `student_tariff_consistency_test.php`, `report_finance_http_regression_test.php`: peserta asal, tujuan tunggal, replay, rombel, snapshot/tarif dan laporan lintas tahun tetap benar. |
| Tabungan | `savings_note_http_test.php`: setoran/penarikan, saldo/jurnal/catatan, batas panjang dan akses role/unit lulus. Regresi kontrol buku/ekspor tiga unit juga lulus. |
| Browser operasional | Alur dan verifier `payment`, `enrollment`, `promotion`, `authorization` lulus: input/edit/DU/struk, reguler/PSB/duplikat, kelulusan lalu kenaikan bertahap dan halaman lama, approve/reject/cancel. `ui_controls_browser_test.js` lulus untuk SD/SMP/SMA: unit/tema/sidebar, dropdown DU input/edit, filter, tabel, Excel/PDF/preview/buku. |
| CSS dan tampilan | Parser CSS/selector/AST serta CSSOM Chromium cocok dengan acuan yang dipertahankan. Matriks final 32 halaman/varian × empat cakupan × dua tema × tiga viewport = **768 kasus**, nol piksel melewati threshold warna 0,1, nol kesalahan JavaScript dan overflow dokumen. Viewport 1440×900, 2560×1440 dan 390×844. Hasil per kasus ada pada [JSON matriks](all-units-20261003-matrix.json). |
| Sintaks | 28 file PHP yang berubah/baru, dua skrip JavaScript browser, dan `git diff --check` lulus. |

Matriks final menggabungkan 576 kasus unit konkret, pengujian ulang 192 kasus Super Admin, lalu menggantikan kasus halaman yang berubah pada peninjauan akhir dengan hasil uji terarah. Bukan klaim satu pemanggilan tes tunggal. Screenshot membandingkan **DOM/data/viewport yang sama** terhadap CSS asli `7647608` untuk tiga unit; palette gabungan memakai acuan `7647608` yang selector titipannya dibersihkan pada pemulihan UI. CSS aplikasi tidak berubah. Detail surat sekarang membuka rombel nyata, bukan `view=detail` tanpa kunci yang kembali ke ringkasan.

Kegagalan fixture/alat awal disimpan terpisah: server akun fixture yang didemotasi bersamaan dengan tes visual mengubah sesi; PDF extractor membutuhkan path dan normalisasi teks; nama screenshot URL detail melampaui batas path Windows; cache border tabel Chromium berbeda saat mengganti stylesheet. Pengujian diisolasi, filename diperpendek dengan hash, dan kedua screenshot dihitung ulang tata letaknya. Percobaan toleransi raster ditinggalkan; hasil final memakai pemeriksaan piksel semula dan seluruh kasus memiliki `mismatched=0`. Tidak memperbaiki aplikasi untuk menutupi kegagalan fixture.

## Database utama dan backup

| Data `db_spp` | Sebelum | Sesudah |
| --- | ---: | ---: |
| Siswa / penempatan | 222 / 210 | 222 / 210 |
| Pembayaran / penerimaan | 1.018 / Rp577.145.000 | 1.018 / Rp577.145.000 |
| SPP / Komite / Daftar Ulang / Biaya Lain terbit | 2.520 / 2.520 / 210 / 16 | sama |
| Rekening / saldo Tabungan | 12 / Rp1.050.000 | sama |
| Jurnal masuk / keluar Tabungan | 12 / 6 | sama |

Health CLI `ok`, HTTP 200 `ok`, seluruh 14 pemeriksaan integritas nol. **Fingerprint seluruh 32 tabel dasar identik** sebelum/sesudah, termasuk pembayaran, Tabungan dan akun. Fingerprint Tabungan:

- Rekening: `cc5c905b4c708bcd97eb9a9fe3957dbd8a197d80e358d461d74cd58eb8a603e1`
- Masuk: `e32cfab90145e5153c304f990f10019f4842382fb8f6ef2d0f23ae356c045f9c`
- Keluar: `98fa3fe71d0158a544329d9ce870626f22128333b8bb313a5741408518053053`

Backup di luar Git: `C:\laragon\backups\spp-management-system\all_units_20261003\baseline.sql`, SHA-256 `FE2C5D2D9E0B48A374FC9600810A1E6D46AF2802FFC5012A241DE4A74A4A85AD`. Artefak sumber matrix dan fingerprint `main-before.json`/`main-final.json` berada di folder yang sama. Screenshot data latihan dapat berisi fixture tambahan; jumlah itu bukan baseline database utama.

Clone milik paket ini: `db_spp_audit_all_units_20261003` (visual/rekap) dan `db_spp_audit_all_actions_20261003` (mutasi). Server port 18106/18107 dihentikan dan kedua clone dihapus setelah identitas serta hasil diverifikasi. Backup/bukti tetap disimpan. Layanan lain dan artefak lama yang penghapusannya pernah ditolak tidak disentuh.

## Bukti tampilan dan pengiriman

- [Data gabungan](all-units-20261003-data.png)
- [Gate transaksi dengan pilihan unit eksplisit](all-units-20261003-transaction.png)
- [Rekap gabungan pada ponsel](all-units-20261003-mobile.png)

Perubahan GitHub terbaru diambil dan ditinjau; `main`/`origin/main` tidak divergen dan tidak ada konflik pada saat pengiriman paket ini. Implementasi, tes dan catatan dikirim bersama ke `main`; hash pengiriman dapat ditelusuri dari commit yang menambahkan dokumen ini.

Penilaian umum tetap **siap terbatas dengan syarat** untuk Laragon pada cakupan yang diuji, sebagaimana [audit kesiapan](READINESS_AUDIT_20261001.md). Paket ini membuktikan fitur Semua Unit dan regresi terdampaknya, bukan jaminan lingkungan deployment atau seluruh kondisi data sekolah nyata.
