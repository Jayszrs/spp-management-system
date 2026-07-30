# Riwayat Perubahan AI SistemSPP

File ini mencatat perubahan proyek secara reverse chronological. Baca [PROJECT_CONTEXT.md](./PROJECT_CONTEXT.md) terlebih dahulu untuk memahami arsitektur, aturan bisnis, dan kewajiban dokumentasi.

## Aturan Pencatatan

- Tambahkan entri terbaru tepat di bawah bagian ini.
- Gunakan tanggal lokal proyek (`Asia/Jakarta`) dengan format `YYYY-MM-DD`.
- Satu entri boleh mencakup satu paket perubahan yang dikerjakan dan diverifikasi bersama.
- Sebutkan AI/aktor, tujuan, perubahan perilaku, database/migrasi, kompatibilitas, dan verifikasi.
- Tulis `Tidak ada` bila suatu bagian memang tidak memiliki perubahan.
- Jangan mencantumkan data siswa nyata, password, token, cookie, atau secret.
- Jangan menghapus atau menulis ulang entri lama. Tambahkan entri koreksi bila diperlukan.
- Perubahan implementasi dan entri changelog wajib masuk commit yang sama.

## 2026-07-30 - Integritas Child Pembayaran dan Kesiapan Schema

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menghilangkan pencocokan child pembayaran yang ambigu dan menyiapkan migrasi aman untuk database berhistori.

**Perubahan fitur dan perilaku:**

- Pembayaran baru menandai header dengan `payment_link_version=1` dan menyimpan `bayar_id` pada Daftar Ulang serta setoran Tabungan Wajib miliknya.
- Edit/hapus pembayaran sekarang hanya membaca atau mengubah child dengan `bayar_id` yang sama; transaksi tabungan manual dan penarikan tidak disentuh.
- Pembalikan setoran Tabungan Wajib mengunci saldo. Jika pembalikan akan membuat saldo negatif, operasi ditolak dan seluruh transaction di-rollback.
- Pembayaran legacy ditandai di daftar/dashboard serta tidak dapat diedit atau dihapus, termasuk dengan akses langsung ke endpoint.
- Menambahkan `documentation/PROGRESS.md` sebagai register temuan, status, bukti, dan aturan pembaruan progres.

**Database dan migrasi:**

- Menambahkan `sql/add_payment_references.sql` yang idempoten untuk `bayar.payment_link_version`, `bayar_du.bayar_id`, `transaksi_m.bayar_id`, index unik, dan foreign key cascade.
- Menyelaraskan `sql/schema.sql` untuk instalasi baru dan menambahkan `sql/verify_schema.sql` berbasis `information_schema`.

**Kompatibilitas dan data lama:**

- Tidak ada backfill atau pencocokan otomatis berdasarkan NIS, tanggal, maupun tahun ajaran.
- Histori yang telah ada tetap legacy (`payment_link_version=0` dan child `bayar_id=NULL`) dan membutuhkan rekonsiliasi manual sebelum dapat diubah.

**Verifikasi:**

- Sebelum migrasi, jumlah transaksi pada database lokal diperiksa dan bernilai nol.
- `add_payment_references.sql` dijalankan dua kali pada database lokal tanpa error; `verify_schema.sql` mengembalikan `OK` untuk tabel, kolom, index unik, dan foreign key cascade wajib.
- Lint seluruh 22 file PHP dan `git diff --check` berhasil. Peringatan normalisasi akhir baris Git tidak mengubah hasil pemeriksaan.
- Uji HTTP/database terisolasi membuat dua pembayaran pada tanggal sama serta satu setoran manual. Edit dan hapus salah satunya hanya mengubah child ber-`bayar_id` miliknya; jurnal manual tetap ada. Pembalikan yang akan membuat saldo negatif dan edit/hapus pembayaran legacy sama-sama ditolak. Semua data uji dibersihkan hingga jumlah transaksi kembali nol.

**Catatan tindak lanjut:**

- Perbaikan SQL injection, CSRF, XSS, dan temuan keamanan lain tidak termasuk ruang lingkup perubahan ini.

## 2026-07-28 - Master Biaya Lain, Master Siswa Advance, dan Uang Komite

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan SistemSPP untuk kebutuhan SDIT, membuat biaya tambahan berbasis master, memperkuat pengelolaan siswa, dan menjaga konsistensi laporan.

**Perubahan fitur dan perilaku:**

- Menambahkan CRUD admin `Master Biaya Lain` dengan nama unik, nominal positif, status aktif/nonaktif, jumlah penggunaan, dan proteksi penghapusan master terpakai.
- Mengganti baris `Uang Lain` lama dengan baris biaya dinamis pada input/edit pembayaran.
- Menyimpan nama dan nominal biaya lain sebagai snapshot agar histori tidak berubah ketika tarif master diperbarui.
- Mempertahankan detail legacy yang tidak memiliki master dan menampilkan master nonaktif hanya pada transaksi lama yang memakainya.
- Mengubah Master Siswa menjadi CRUD dasar/Advance dengan kelas 1 sampai 6, pencarian, filter kelas/status, badge status, serta aksi Edit dan Arsipkan/Pulihkan.
- Menambahkan field Advance untuk NIS Diknas, tarif siswa, POMG/Komite, daftar ulang, potongan, total turunan, dan migrasi saldo awal.
- Menjaga field Advance saat panel ditutup dan menolak perubahan saldo awal setelah siswa memiliki histori.
- Mengizinkan perubahan nomor induk dalam transaction dengan cascade foreign key.
- Menambahkan audit JSON sebelum/sesudah untuk tambah, edit, arsip, dan pemulihan siswa.
- Mengintegrasikan `siswa.POMG` sebagai Uang Komite opsional yang dilacak per siswa, bulan, dan tahun.
- Menghitung sisa Komite di frontend dan memvalidasi ulang tarif, status siswa, periode, serta batas sisa di backend.
- Membatasi siswa arsip dari pembayaran dan tabungan baru tanpa menghapus histori lama.
- Menambahkan Uang Komite dan agregasi biaya lain ke laporan web, halaman kwitansi/PDF, dan Excel.
- Menghitung ulang `total_jumlah` di backend serta mengabaikan total dan nominal biaya master dari browser.
- Menambahkan responsive styling untuk Master Siswa dan memperbaiki constraint lebar konten mobile.
- Menambahkan dokumentasi konteks proyek dan aturan changelog AI pada folder `documentation`.

**Database dan migrasi:**

- Menambahkan `master_biaya_lain` dan `bayar_biaya_lain` melalui `sql/add_master_biaya_lain.sql`.
- Memigrasikan `U_LAIN` serta empat slot biaya lama secara idempotent melalui `legacy_key` tanpa mengubah `bayar.total_jumlah`.
- Mengubah data finansial siswa menjadi `DECIMAL(15,2)`, membatasi kelas 1 sampai 6, serta menambahkan `siswa.is_active` dan index pencarian.
- Menambahkan `siswa_audit_log` dan `bayar.U_KOMITE` melalui `sql/add_student_advanced.sql`.
- Menyinkronkan `tot_pangkal` dan `tot_du` dari tarif dan potongan.
- Memperbarui `sql/schema.sql` agar instalasi baru langsung memakai struktur terkini.

**Kompatibilitas dan data lama:**

- Kolom pembayaran lama tetap ada, tetapi transaksi baru tidak lagi menulis nominal biaya lain ke kolom legacy.
- Snapshot menjaga tarif dan nama biaya transaksi lama.
- Migrasi siswa berhenti bila masih menemukan kelas di luar 1 sampai 6 dan tidak memetakan kelas secara otomatis.
- Siswa arsip tetap ikut histori, laporan, dan edit transaksi lama.

**Verifikasi:**

- Lint seluruh 22 file PHP berhasil.
- `node --check assets/js/app.js` dan `git diff --check` berhasil.
- Kedua migrasi diuji pada database salinan; migrasi siswa juga dijalankan ulang dua kali pada database lokal tanpa error.
- Pengujian HTTP/database mencakup CRUD siswa, total turunan, audit, cascade nomor induk, perlindungan saldo awal, CSRF, role non-admin, arsip/pulihkan, dan penolakan siswa arsip.
- Pembayaran Komite diuji sebagian, berlebih, edit transaksi siswa arsip, serta periode bulan berbeda.
- Laporan web, PDF, dan Excel diverifikasi memuat Uang Komite.
- Tampilan Master Siswa diperiksa melalui screenshot desktop dan mobile.
- Seluruh data pengujian dibersihkan setelah verifikasi.

**Catatan tindak lanjut:**

- Terapkan CSRF secara konsisten pada pembayaran, tabungan, dan Master Biaya Lain.
- Pertimbangkan automated integration test dan migrasi kolom uang legacy dari `DOUBLE` ke `DECIMAL` pada pekerjaan terpisah.

## 2026-07-28 - Penyempurnaan Alur Pembayaran dan Kwitansi PDF

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `232bbc1`

**Ringkasan:** Menyempurnakan alur pembayaran, representasi bulan, kelas SD, pengisian rincian biaya, serta halaman laporan cetak menjadi format yang lebih menyerupai kwitansi.

## 2026-07-28 - Light Mode Lebih Terang

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `5f35c16`

**Ringkasan:** Meningkatkan kontras dan kecerahan light mode tanpa mengganti konsep visual utama SistemSPP.

## 2026-07-28 - Refactor Struktur dan Maintainability

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `95bb7b0`, `379dd3e`

**Ringkasan:** Merapikan struktur kode dan meningkatkan keterbacaan serta maintainability tanpa perubahan domain utama yang tercatat pada pesan commit.

## 2026-07-28 - Role Management dan Perbaikan Pencarian

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `9a8e99d`, `daacb41`

**Ringkasan:** Menambahkan pengelolaan akun berbasis role, memperbarui akses UI/backend, dan mengganti pencarian menjadi search box dengan ikon yang konsisten.

## 2026-07-28 - Multi-role, Tabungan, dan Export Laporan

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `35e1dd9`

**Ringkasan:** Menambahkan role admin/bendahara/kasir, alur tabungan masuk/keluar, riwayat tabungan, laporan keuangan, dan export.

## 2026-07-27 - Implementasi Awal SistemSPP

**AI/Aktor:** Riwayat repository sebelum aturan changelog

**Referensi Git:** `2943f44`

**Ringkasan:** Membuat fondasi aplikasi SistemSPP dan desain Material 3 sebagai baseline repository.

## Template Entri Berikutnya

Salin template ini ke bagian paling atas setelah `Aturan Pencatatan`:

```markdown
## YYYY-MM-DD - Judul Perubahan

**AI/Aktor:** Nama AI/model atau developer
**Tujuan:** Ringkasan permintaan dan hasil yang diinginkan.

**Perubahan fitur dan perilaku:**

- Perubahan yang benar-benar diterapkan.

**Database dan migrasi:**

- Nama migrasi, perubahan schema, atau `Tidak ada`.

**Kompatibilitas dan data lama:**

- Dampak terhadap data/API/perilaku lama atau `Tidak ada`.

**Verifikasi:**

- Command dan skenario yang benar-benar dijalankan.

**Catatan tindak lanjut:**

- Risiko tersisa, technical debt, atau `Tidak ada`.
```
