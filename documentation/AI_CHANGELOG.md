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

## 2026-08-02 - Sinkronisasi Database Lama dan Kompatibilitas Kelas

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memperbaiki fatal error dashboard dan menyelaraskan database lokal lama dengan kebutuhan schema aplikasi saat ini tanpa menghapus data siswa.

**Perubahan fitur dan perilaku:**

- Master siswa tetap membatasi data baru ke kelas 1 sampai 6.
- Label kelas legacy pada siswa yang sudah ada dapat dipertahankan saat diedit dan tersedia pada filter daftar siswa.
- Pengurutan daftar siswa menempatkan kelas SD sebelum label kelas legacy.

**Database dan migrasi:**

- Memperbarui `sql/add_student_advanced.sql` agar migrasi tetap menambahkan kolom, tipe nominal, audit, dan Uang Komite saat database memuat label kelas legacy maksimal 5 karakter.
- Constraint kelas SD hanya ditambahkan bila seluruh kelas existing sudah bernilai 1 sampai 6.
- Memperluas `sql/verify_schema.sql` agar memeriksa seluruh tabel, kolom, dan index utama dari paket migrasi saat ini.
- Menjalankan seluruh migrasi upgrade pada `db_spp` setelah backup.

**Kompatibilitas dan data lama:**

- Data siswa, akun, dan transaksi lama dipertahankan. Label kelas legacy tidak dipetakan atau diubah otomatis.

**Verifikasi:**

- Migrasi dijalankan dua kali untuk memeriksa idempotensi.
- Verifikasi schema, lint seluruh file PHP, query dashboard, dan smoke test HTTP lokal dijalankan.

**Catatan tindak lanjut:**

- Label kelas legacy dapat dikonversi manual ke kelas 1 sampai 6 bila sekolah tidak lagi membutuhkannya.

## 2026-07-31 - Perapihan Mobile Preview Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan tampilan preview export Excel pada layar mobile agar tombol, ringkasan, dan tabel lebih nyaman dibaca.

**Perubahan fitur dan perilaku:**

- Mengubah area preview Excel mobile menjadi full-width tanpa shadow/card besar yang membuat ruang terasa sempit.
- Merapikan tombol `Download Excel` dan `Kembali` pada mobile agar tinggi dan jaraknya konsisten.
- Membungkus setiap tabel laporan dalam container scroll tersendiri, sehingga tabel tidak dipaksa mengecil dan kolom tetap terbaca.
- Mempertahankan tabel komponen pembayaran sebagai tabel compact agar tetap pas di layar mobile.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data. Download Excel tetap memakai URL dan format `.xls` yang sama.

**Verifikasi:**

- `php -l laporan/export_excel.php` berhasil.
- Test HTTP lokal preview Excel berhasil status `200` dan memuat wrapper tabel mobile.
- Test HTTP lokal download Excel berhasil status `200` dengan `Content-Type: application/vnd.ms-excel; charset=UTF-8` dan attachment `Laporan_SPP_Juli_2026.xls`.

**Catatan tindak lanjut:**

- Uji visual langsung di viewport mobile browser untuk memastikan scroll horizontal per tabel terasa nyaman.

## 2026-07-31 - Perapihan Layout Slip PDF

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan konsep slip PDF server-side agar tetap memakai format baru tetapi tidak berantakan atau terpecah halaman.

**Perubahan fitur dan perilaku:**

- Mengubah template slip PDF dari layout berbasis `div/grid` menjadi layout tabel HTML yang lebih stabil untuk Dompdf.
- Menyesuaikan ukuran konten slip agar total halaman pas pada kertas landscape `210mm x 148mm`.
- Menghapus sisa toolbar/aksi cetak HTML dari output slip PDF.
- Mempertahankan contoh slip otomatis ketika periode belum memiliki transaksi.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun URL export. Endpoint `laporan/export_pdf.php` tetap mengembalikan PDF server-side.

**Verifikasi:**

- `php -l laporan/export_pdf.php` berhasil.
- Test HTTP lokal export PDF berhasil mengembalikan `Content-Type: application/pdf`, file berawalan `%PDF`, satu page object, dan MediaBox `595.276 x 419.528` pt.
- `composer validate --strict` berhasil; `composer audit` tidak menemukan security advisory, dengan peringatan sebagian metadata Packagist memakai cache lokal karena timeout koneksi.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual langsung di Chrome PDF viewer setelah refresh tab export.

## 2026-07-31 - Perbaikan Mobile Riwayat dan Preview Export

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan tampilan mobile riwayat tabungan dan preview Excel, serta mengembalikan konsep slip saat periode belum memiliki transaksi.

**Perubahan fitur dan perilaku:**

- Merapikan form filter riwayat tabungan mobile agar field dan tombol tidak saling menekan.
- Merapikan tombol `Tabungan Masuk` dan `Tabungan Keluar` pada card riwayat tabungan mobile.
- Mengubah tabel riwayat tabungan dan rekap saldo menjadi responsive card pada mobile.
- Merapikan toolbar dan tabel preview Excel mobile agar tidak membuat halaman melebar.
- Export PDF kini otomatis menampilkan slip contoh saat periode belum memiliki transaksi, sehingga konsep slip tidak berubah menjadi halaman kosong.
- Membump versi `style.css` ke `v=3.8` agar browser mengambil styling mobile terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. Slip contoh hanya tampil sebagai preview PDF saat periode kosong dan tidak menyimpan data.

**Verifikasi:**

- Lint PHP berhasil untuk `tabungan/riwayat.php`, `laporan/export_excel.php`, `laporan/export_pdf.php`, dan halaman yang memakai `style.css`.
- Pencarian versi lama `style.css?v=3.0` sampai `v=3.7` tidak menemukan sisa pada file PHP.
- Test HTTP lokal export PDF periode kosong berhasil mengembalikan file `%PDF` dengan MediaBox `595.276 x 419.528` pt atau `210mm x 148mm`.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual mobile pada Riwayat Tabungan dan Preview Excel di browser.

## 2026-07-31 - Ukuran Cetak Slip Landscape

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan hasil cetak slip pembayaran agar tidak memakai A4 portrait penuh, menghapus logo dari slip, dan menghilangkan header/footer bawaan Chrome.

**Perubahan fitur dan perilaku:**

- Menghapus logo dari header slip pembayaran.
- Mengubah ukuran print slip menjadi landscape `210mm x 148mm`.
- Mengubah export slip dari HTML print browser menjadi PDF asli yang dirender server-side dengan Dompdf.
- Menghapus toolbar print HTML dari template PDF agar file hanya berisi isi slip.
- Menyederhanakan judul browser menjadi `Slip Pembayaran`.
- Menambahkan `vendor/` ke `.gitignore`; dependency dipulihkan lewat `composer install`.

**Database dan migrasi:**

- Tidak ada perubahan database.
- Menambahkan dependency Composer `dompdf/dompdf` versi `3.1.6`.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. URL export PDF lama tetap sama, tetapi response kini berupa `application/pdf` dari server.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- `composer audit` berhasil tanpa security advisory setelah Dompdf diperbarui ke `3.1.6`.
- Test HTTP lokal setelah login berhasil mengembalikan `Content-Type: application/pdf` dan file berawalan `%PDF`.
- Pencarian di `laporan/export_pdf.php` memastikan ukuran `210mm x 148mm` sudah dipakai, referensi logo slip sudah tidak ada, dan toolbar print HTML sudah dihapus.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual file PDF di browser untuk memastikan posisi slip sesuai contoh cetak.

## 2026-07-31 - Logo pada Slip Pembayaran

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan logo sekolah pada slip pembayaran agar header slip tidak hanya berisi teks.

**Perubahan fitur dan perilaku:**

- Menambahkan favicon pada halaman preview/cetak slip PDF.
- Menambahkan logo `assets/img/school-logo.png` pada header slip pembayaran.
- Menyesuaikan layout header slip agar logo berada di kiri dan judul sekolah tetap rata tengah.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun isi transaksi. Perubahan hanya pada tampilan slip.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- Asset `assets/img/school-logo.png` dan `assets/img/favicon.png` tersedia.
- Pencarian di `laporan/export_pdf.php` memastikan path logo dan favicon sudah dipasang.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji cetak/save PDF di browser untuk memastikan logo tampil pada hasil cetak.

## 2026-07-31 - Palet Dark Mode Lebih Ramah

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah palet dark mode agar tampilan tidak terlalu hijau pekat dan lebih nyaman dibaca.

**Perubahan fitur dan perilaku:**

- Mengubah variable dark mode dari palet hijau pekat menjadi dasar neutral charcoal/slate dengan aksen emerald.
- Mengurangi intensitas glow dan orb background agar area dashboard terasa lebih bersih.
- Menyesuaikan sidebar, topbar, active menu, kartu statistik, tabel, badge, search box, tab, dan bottom navigation agar kontras lebih ramah mata.
- Menyesuaikan dark mode halaman login agar selaras dengan palet baru.
- Membump versi `style.css` ke `v=3.7` dan `login.css` ke `v=3.4` agar browser mengambil styling terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data, role, maupun alur fitur. Perubahan hanya pada tampilan dark mode.

**Verifikasi:**

- Lint PHP berhasil untuk halaman yang memuat stylesheet utama: dashboard, login, master biaya lain, role management, sidebar, laporan, pembayaran, siswa, dan tabungan.
- Pencarian versi lama `style.css?v=3.0` sampai `v=3.6` dan `login.css?v=3.0` sampai `v=3.3` tidak menemukan sisa pada file PHP.
- Pencarian warna dark mode lama hanya menyisakan override light mode yang memang terpisah.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual dark mode di dashboard, form pembayaran, tabel laporan, dan halaman login.

## 2026-07-31 - Logout Mobile Sidebar

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Memastikan tombol logout bisa diakses dengan nyaman pada tampilan mobile.

**Perubahan fitur dan perilaku:**

- Menambahkan label `Logout` pada tombol logout sidebar agar lebih jelas di mobile.
- Menyembunyikan bottom navigation saat sidebar mobile terbuka supaya tidak menutupi footer sidebar.
- Menaikkan prioritas tampilan sidebar mobile dan menambahkan safe-area padding pada footer.
- Membump versi `style.css` ke `v=3.6` pada halaman utama agar browser mengambil styling mobile terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data maupun hak akses. Logout tetap memakai `logout.php`.

**Verifikasi:**

- `C:\xampp\php\php.exe -l includes\sidebar.php` berhasil.
- `C:\xampp\php\php.exe -l login.php` berhasil.
- Pencarian `style.css?v=3.0` sampai `v=3.5` tidak menemukan sisa pada file PHP.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual di mobile: buka sidebar, pastikan bottom nav hilang dan tombol logout terlihat di footer.

## 2026-07-31 - Animasi Transisi Login Per Role

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan animasi masuk setelah login berhasil agar perpindahan ke halaman sesuai role terasa lebih halus.

**Perubahan fitur dan perilaku:**

- Mengubah redirect login valid dari redirect server instan menjadi render transisi singkat lalu redirect via JavaScript.
- Menambahkan overlay transisi kiri-kanan dengan panel hijau dan oranye sebelum pengguna diarahkan ke halaman role.
- Menonaktifkan tombol login saat transisi berjalan dan menampilkan status `Masuk...`.
- Membump versi `login.css` ke `v=3.3` agar browser mengambil animasi terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan data atau hak akses role. Tujuan redirect tetap sama: kasir ke tabungan masuk, bendahara ke laporan, role lain ke dashboard.

**Verifikasi:**

- `C:\xampp\php\php.exe -l login.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual login untuk role admin, bendahara, dan kasir di browser.

## 2026-07-31 - Avatar Sidebar Per Role

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyesuaikan tampilan profile role di sidebar agar lebih mirip avatar profile pada contoh.

**Perubahan fitur dan perilaku:**

- Menambahkan asset `assets/img/profile-avatar.png` untuk avatar profile sidebar.
- Mengubah avatar footer sidebar menjadi gambar profile dengan badge inisial role: `AD` untuk admin, `BD` untuk bendahara, dan `KS` untuk kasir.
- Menambahkan warna badge berbeda per role serta styling border dan shadow agar tampil seperti profile badge.
- Menyesuaikan styling light mode agar avatar tetap terlihat rapi.
- Mengunci ukuran avatar lewat atribut gambar dan CSS agar gambar tidak tampil pada ukuran asli saat cache CSS lama masih tersisa.
- Membump versi `style.css` ke `v=3.5` pada halaman utama agar browser mengambil styling avatar terbaru.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data maupun hak akses.

**Verifikasi:**

- Lint seluruh file PHP berhasil.
- Pencarian versi lama `style.css?v=3.2`, `v=3.3`, dan `v=3.4` tidak menemukan sisa pada file PHP.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji visual sebagai admin, bendahara, dan kasir untuk memastikan avatar role sesuai.

## 2026-07-31 - Sistem Pembayaran Tunai VA Qris

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menambahkan pilihan sistem pembayaran pada transaksi pembayaran sekolah.

**Perubahan fitur dan perilaku:**

- Menambahkan pilihan `Tunai`, `VA`, dan `Qris` pada form input dan edit pembayaran.
- Menambahkan validasi backend agar hanya tiga metode pembayaran tersebut yang dapat disimpan.
- Menampilkan sistem pembayaran pada daftar pembayaran, laporan web, export Excel, dan slip PDF.
- Slip PDF tidak lagi hardcoded `VA`, tetapi memakai metode dari transaksi.

**Database dan migrasi:**

- Menambahkan kolom `bayar.sistem_pembayaran` melalui `sql/add_payment_method.sql`.
- Memperbarui `sql/schema.sql` agar instalasi baru langsung memiliki kolom sistem pembayaran.

**Kompatibilitas dan data lama:**

- Data transaksi lama memakai default `VA`.
- Tidak ada perubahan struktur tabel selain penambahan kolom baru pada `bayar`.

**Verifikasi:**

- Migrasi `sql/add_payment_method.sql` berhasil dijalankan pada database lokal dan dijalankan ulang tanpa error.
- Verifikasi schema lokal menunjukkan `bayar.sistem_pembayaran` bertipe `enum('Tunai','VA','Qris')`, `NOT NULL`, default `VA`.
- Lint seluruh file PHP berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji input dan edit transaksi dengan metode `Tunai`, `VA`, dan `Qris`.

## 2026-07-31 - Penyempurnaan Tampilan Preview Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Merapikan halaman preview Excel agar tidak banyak area kosong dan paletnya sesuai tema hijau-oranye.

**Perubahan fitur dan perilaku:**

- Mengubah palet preview Excel dari ungu menjadi hijau dengan aksen oranye.
- Membuat tabel preview melebar penuh di dalam lembar preview agar area kosong berkurang.
- Menambahkan header laporan yang lebih ringkas dan kartu ringkasan total pembayaran, tabungan masuk, dan tabungan keluar.
- Mengurangi jarak kosong antar section tabel dan memperbaiki `colspan` header tabel.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Perubahan hanya memengaruhi tampilan preview dan gaya HTML export.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_excel.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.
- Pencarian warna ungu lama dan `colspan` tabel yang tidak sesuai tidak menemukan sisa di `laporan/export_excel.php`.

**Catatan tindak lanjut:**

- Uji visual di browser pada periode kosong dan periode berisi data.

## 2026-07-31 - Preview Sebelum Download Excel

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengubah alur Export Excel agar pengguna dapat melihat preview sebelum mengunduh file.

**Perubahan fitur dan perilaku:**

- Menambahkan mode preview default pada `laporan/export_excel.php`.
- Download file `.xls` hanya dilakukan saat URL memakai parameter `download=1`.
- Menambahkan tombol `Download Excel` dan `Kembali` pada halaman preview.
- Menambahkan empty state untuk komponen pembayaran, transaksi pembayaran, dan transaksi tabungan saat periode belum memiliki data.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. URL export lama kini menampilkan preview terlebih dahulu, sedangkan format download tetap `.xls`.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_excel.php` berhasil.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji manual download dari preview untuk memastikan browser menerima file `.xls` sesuai periode yang dipilih.

## 2026-07-31 - Export Laporan Tetap di Tab yang Sama

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mencegah tab browser menumpuk saat pengguna membuka export laporan.

**Perubahan fitur dan perilaku:**

- Menghapus `target="_blank"` pada tombol Export Excel dan Export PDF di `laporan/index.php`.
- Export laporan kini dibuka dari tab yang sama agar alur navigasi lebih rapi.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Perubahan hanya memengaruhi perilaku navigasi link export.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\index.php` berhasil.
- Pencarian `target="_blank"` tidak menemukan penggunaan tersisa di kode aplikasi.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji manual dari halaman Laporan di browser untuk memastikan tombol Export Excel dan Export PDF terasa sesuai alur kerja pengguna.

## 2026-07-31 - Template Slip Pembayaran Sekolah

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Mengganti tampilan cetak/PDF laporan pembayaran agar mengikuti contoh slip pembayaran sekolah yang diberikan pemilik proyek.

**Perubahan fitur dan perilaku:**

- Mengubah `laporan/export_pdf.php` dari kwitansi rekap bulanan menjadi slip pembayaran per transaksi.
- Menyesuaikan layout slip dengan header SD Al-Qur'an Mutiara Hikmah, data siswa dua kolom, rincian pembayaran, sisa pembayaran, pembayaran lain-lain, jumlah total, terbilang, sistem pembayaran, dan tanda tangan bagian keuangan.
- Menambahkan fungsi terbilang rupiah untuk menampilkan total pembayaran dalam bentuk teks.
- Menampilkan biaya lain, tabungan wajib, uang daftar ulang, dan sisa PSB/DU berdasarkan data transaksi yang tersedia.
- Menambahkan mode preview `contoh=1` saat periode belum memiliki transaksi agar template slip tetap dapat dilihat tanpa membuat data pembayaran palsu di database.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan struktur data. Output PDF kini berisi slip per transaksi pada periode yang dipilih, bukan rekap tabel bulanan.

**Verifikasi:**

- `C:\xampp\php\php.exe -l laporan\export_pdf.php` berhasil.
- Query lokal menunjukkan tabel `bayar` masih kosong sehingga mode preview diperlukan untuk melihat template.
- `git diff --check` berhasil, dengan warning line ending CRLF dari Git.

**Catatan tindak lanjut:**

- Uji cetak dari browser dengan data transaksi nyata untuk memastikan hasil visual sesuai format laporan yang diinginkan.

## 2026-07-30 - Dokumen Minutes of Meeting Project

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menyusun dokumen Minutes of Meeting untuk kebutuhan laporan project SistemSPP.

**Perubahan fitur dan perilaku:**

- Menambahkan dokumen `documentation/MOM_SistemSPP.md` berisi informasi rapat, agenda, ringkasan pembahasan fitur, keputusan, action items, risiko, kesimpulan, dan lampiran modul project.

**Database dan migrasi:**

- Tidak ada.

**Kompatibilitas dan data lama:**

- Tidak ada perubahan perilaku aplikasi maupun struktur data.

**Verifikasi:**

- Review manual isi dokumen berdasarkan `documentation/PROJECT_CONTEXT.md`, `documentation/AI_CHANGELOG.md`, schema database, dan file modul utama.

**Catatan tindak lanjut:**

- Lengkapi placeholder peserta, waktu, tempat, PIC, dan target tanggal sesuai kebutuhan laporan.

## 2026-07-30 - Perbaikan SQL Injection Riwayat Tabungan

**AI/Aktor:** Codex berbasis GPT-5, bersama pemilik proyek

**Tujuan:** Menutup SQL injection pada filter NIS di riwayat tabungan tanpa mengubah perilaku laporan.

**Perubahan fitur dan perilaku:**

- Menghapus interpolasi langsung `$_GET['nis']` dari query masuk dan keluar pada `tabungan/riwayat.php`.
- Filter NIS kosong tetap menampilkan seluruh transaksi pada periode yang dipilih.
- Filter NIS terisi tetap menggunakan exact match, tetapi nilainya sekarang dikirim sebagai parameter prepared statement.

**Database dan migrasi:**

- Tidak ada perubahan schema atau migrasi database.

**Kompatibilitas dan data lama:**

- Format URL, filter bulan/tahun, hasil laporan normal, saldo, jurnal, pembayaran, dan transaksi legacy tetap tidak berubah.

**Verifikasi:**

- Lint seluruh file PHP, `node --check assets/js/app.js`, dan `git diff --check` dijalankan.
- Filter kosong, filter NIS valid, dan payload SQL `' OR 1=1 --` diuji melalui HTTP; payload diperlakukan sebagai nilai literal tanpa SQL error atau perluasan hasil.
- Tidak ada data transaksi yang tersisa setelah pengujian.

**Catatan tindak lanjut:**

- CSRF pada endpoint mutasi pembayaran, tabungan, dan Master Biaya Lain tetap berada di luar scope dan dicatat sebagai technical debt.

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
