# Pemulihan UI SistemSPP - 2 Oktober 2026

## Penyebab dan perubahan

Kode dasar perbaikan: `e479606` pada `main`. Acuan desain: `7647608`, **Fix historical reports and SPP payment order feedback**.

Commit `6a95cc0` menghapus Titipan SPP tetapi merusak selector bersama pada stylesheet: 10 tanda kurung `:is(...)` tidak tertutup, target turunan terhapus, serta dua blok responsif bersama hilang. Parser browser kehilangan aturan berikutnya, termasuk tema unit, sidebar, Otorisasi, Cetak Tabungan dan penyempurnaan laporan. Health dan integritas database tidak mendeteksi kesalahan tampilan ini. Kelulusan browser pada audit sebelumnya membuktikan interaksi dan hasil database; klaim tersebut tidak cukup membuktikan seluruh tampilan setelah penghapusan titipan.

Pemulihan mempertahankan seluruh aturan desain `7647608` untuk komponen aktif. Selector khusus Titipan SPP dihapus melalui AST selector, sehingga cabang bersama, pseudo class dan media query tetap utuh. Tombol logout POST dengan CSRF mempertahankan penyesuaian tampilan tombolnya. Semua 23 pemanggil stylesheet PHP memakai tambahan versi `filemtime`. Tidak ada perubahan JavaScript aplikasi, API bisnis, skema atau data utama; perbaikan audit dan pembayaran langsung tetap berlaku.

## Bukti tampilan

- [Lima halaman SMA sebelum dan sesudah](ui-recovery-20261002-before-after.png): Dashboard, Otorisasi, Cetak Tabungan, Laporan Umum dan Laporan Global.
- [SD/SMP/SMA pada mode terang dan gelap](ui-recovery-20261002-palettes.png).
- Screenshot asli, screenshot acuan, dan hasil per kasus disimpan di luar repository: `C:\laragon\backups\spp-management-system\ui_recovery_tools\matrix\matrix.json` dan folder PNG yang sama. Screenshot kondisi rusak berada di subfolder `screenshots`.

Perbandingan menggunakan **DOM dan data sekarang yang sama**, kemudian mengganti stylesheet dengan sumber asli `7647608` ditambah empat properti tombol logout. Tidak menjalankan backend lama pada skema baru. Animasi dinonaktifkan dan jam dibekukan hanya dalam pengujian; aplikasi tetap memakai animasinya. Snapshot diambil setelah font dan permintaan status pembayaran selesai. Lembar sebelum/sesudah merender ulang CSS rusak yang disimpan sebelum perbaikan dengan animasi dinonaktifkan, agar animasi masuk kartu tidak membuat bukti terlihat pudar; capture awal tetap disimpan di luar Git.

## Matriks verifikasi

32 halaman/varian dikalikan tiga unit, dua tema dan tiga viewport menghasilkan **576 perbandingan screenshot lulus, nol selisih piksel, nol kegagalan, nol kesalahan JavaScript dan nol overflow dokumen**. Viewport: 1440 x 900, 2560 x 1440 dan 390 x 844. Setiap kombinasi unit/tema/viewport memiliki 32 hasil lulus. Perbandingan piksel memakai threshold warna 0,1; tidak ada piksel yang melewati threshold tersebut.

| Cakupan | Bukti |
| --- | --- |
| Sintaks dan desain bersama | `ui_css_regression_test.js`: PostCSS, selector parser dan CSS Tree; seluruh aturan aktif sama dengan acuan setelah pembersihan selector khusus titipan; seluruh pemanggil stylesheet memiliki versi waktu perubahan. |
| Parser browser | `ui_visual_matrix_browser_test.js`: CSSOM Chromium sama dengan referensi yang dibersihkan; 3.065 style rules terbaca, termasuk aturan akhir surat kepala sekolah. |
| Halaman | Seluruh 18 tujuan menu, edit pembayaran, sepuluh template laporan, detail/pratinjau surat kepala sekolah, dan surat orang tua. |
| Tampilan | Warna unit dan tema, panel unit, ukuran logo buku 36px, susunan header/filter/kartu/tabel, responsif dan lebar dokumen diperiksa. Screenshot tiap kasus dibandingkan dengan acuan. |
| Kontrol tiga unit | `ui_controls_browser_test.js`: pergantian unit/tema, sidebar desktop/ponsel, dropdown DU input/edit dan pusat panah, filter, gulir tabel, ekspor Excel/PDF/preview, pemilihan siswa dan pratinjau/PDF buku Tabungan lulus; tidak ada kesalahan JavaScript. |
| Pembayaran browser | `payment_browser_flow.js` dan verifier: tanpa siswa/tanpa tagihan/tahun berjalan/tunggakan DU, urutan SPP, input/edit pembayaran langsung dan struk; nominal/alokasi akhir cocok dengan database. |
| Pendaftaran browser | `enrollment_browser_flow.js` dan verifier: reguler/PSB, NIS duplikat dan PSB tanpa nominal; identitas/penempatan/tagihan/audit akhir cocok. |
| Otorisasi browser | `authorization_browser_flow.js` dan verifier: pengajuan kasir, approve/reject/cancel; tepat satu edit disetujui diterapkan dan keputusan lain mempertahankan nominal. |
| Kenaikan browser | `promotion_browser_flow.js` dan verifier: kelas 6 lulus, dua siswa kelas 5 diproses terpisah, dua halaman lama ditolak; satu kelulusan dan dua tujuan tercatat tepat satu kali. |

Kegagalan alat dipisahkan dan diperbaiki: import ESM Windows memerlukan `pathToFileURL`; ukuran logo awal diukur dari bounding box yang sudah diputar, lalu diganti ukuran CSS; screenshot edit awal mendahului hasil status asynchronous, lalu menunggu network idle; pemilihan buku harus menekan hasil pencarian, dan PDF buku memerlukan `output=pdf`. Pemeriksaan terkait dijalankan ulang. Tidak mengubah perilaku aplikasi untuk menyesuaikan fixture.

## Database dan lingkungan

Backup baca saja database sekarang: `C:\laragon\backups\spp-management-system\db_spp_ui_recovery_20261002.sql`, SHA-256 `58F36A843592D77795522CDFAC532D2BAC7805DFC7631463B5C10FB8A92A2008`. Backup ini sudah memakai skema tanpa Titipan SPP; bukan backup untuk mengembalikan fitur tersebut.

Clone visual: `db_spp_audit_ui_recovery_20261002`, server loopback 18104. Clone interaksi mutatif: `db_spp_audit_ui_actions_20261002`, server 18105. Keduanya dibuat khusus audit ini dari dump utama dan diberi flag tes. Endpoint identitas membuktikan server visual memakai clone yang diminta; fixture mutatif memeriksa database aktual dan verifikasi akhirnya.

Utama tetap **222 siswa, 210 penempatan, 1.018 pembayaran/Rp577.145.000**. SPP/Komite/DU/Biaya Lain: 2.520/2.520/210/16. Tabungan tetap **12 rekening/Rp1.050.000**, 12 jurnal masuk/6 keluar. Perbandingan 32 tabel dasar utama dengan clone visual menemukan perbedaan hanya pada `admin`, akibat penggantian hash password akun tes di clone. Seluruh tabel bisnis sama. Fingerprint tiga tabel Tabungan tetap:

```text
tabungan_data   cc5c905b4c708bcd97eb9a9fe3957dbd8a197d80e358d461d74cd58eb8a603e1
transaksi_m_data e32cfab90145e5153c304f990f10019f4842382fb8f6ef2d0f23ae356c045f9c
transaksi_k_data 98fa3fe71d0158a544329d9ce870626f22128333b8bb313a5741408518053053
```

Health utama CLI/HTTP OK (200); 14 pemeriksaan integritas nol. PHP lint 23 file pemanggil stylesheet lulus; sintaks empat skrip JavaScript tes lulus. Pengujian mutatif hanya berlangsung di clone interaksi. Tidak ada penerapan ulang migrasi.

## Menjalankan kembali pemeriksaan UI

Tooling Node dipasang di luar repository. `SPP_CSS_TOOLS` menunjuk folder `node_modules` berisi `postcss`, `postcss-selector-parser`, `css-tree`, `pngjs` dan `pixelmatch`; `SPP_PLAYWRIGHT_CORE` menunjuk modul Playwright Core. Chrome lokal digunakan secara headless.

```powershell
node tests/ui_css_regression_test.js
node tests/ui_visual_matrix_browser_test.js
node tests/ui_controls_browser_test.js
```

Dua runner browser mensyaratkan `SPP_TEST_ALLOW_MUTATION=1`, nama `SPP_DB_NAME` dengan prefix `db_spp_audit_`, URL loopback pada `SPP_TEST_BASE_URL`, akun superadmin clone dan `SPP_TEST_ADMIN_PASSWORD_FILE`. `SPP_UI_IDS_FILE` adalah JSON dengan kunci unit `1`, `2`, `3`, masing-masing berisi `id` pembayaran biasa serta `NO_INDUK` milik unit itu. Runner matriks juga memerlukan `SPP_UI_OUTPUT` di luar Git. Jangan mengarahkan server tes ke database utama. Jangan menyimpan sandi atau dump ke repository.

Seluruh runner selesai dan lulus. Server milik audit PID 12092/43292 (port 18104/18105) dihentikan setelah executable dan command line diverifikasi; kedua clone milik audit dihapus setelah identitas diperiksa. Inventaris `db_spp%` kembali hanya `db_spp`; health utama tetap OK. Backup, tooling dan screenshot/log di luar Git dipertahankan, termasuk artefak lama yang sebelumnya ditolak penghapusannya; layanan lain tidak dihentikan.

Perubahan origin terbaru diambil dan diperiksa: `main` lokal dan origin berada pada `e479606`, tanpa divergensi atau konflik. Paket pemulihan dikirim melalui commit pada main yang memuat dokumen ini; tidak memakai force push. Ringkasan matriks yang dapat diperiksa mesin ada pada [hasil ringkas](ui-recovery-20261002-summary.json).
