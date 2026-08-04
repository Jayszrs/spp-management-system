# Minutes of Meeting (MoM) Project SistemSPP

## Informasi Rapat

| Keterangan | Detail |
| --- | --- |
| Nama Rapat | Pembahasan Progres, Revisi, dan Finalisasi Project SistemSPP |
| Tanggal | 31 Juli 2026 |
| Waktu | [Isi waktu rapat] |
| Tempat/Media | [Isi lokasi atau media rapat] |
| Pemimpin Rapat | [Isi nama] |
| Notulis | [Isi nama] |
| Peserta | [Isi nama peserta] |
| Repository | `https://github.com/alhafiztaufikur/spp-management-system` |
| Commit terakhir yang sudah dipush | `876bb40 - Polish SistemSPP reports and mobile UI` |

## Tujuan Rapat

Rapat ini dilakukan untuk membahas progres terbaru SistemSPP, meninjau revisi tampilan dan laporan, memastikan alur utama aplikasi sudah sesuai kebutuhan, serta menyusun catatan akhir yang dapat digunakan sebagai bahan laporan project.

## Agenda Rapat

1. Review gambaran umum SistemSPP.
2. Review fitur data siswa, pembayaran, tabungan, role, dan laporan.
3. Pembahasan revisi UI desktop dan mobile.
4. Pembahasan export PDF dan preview Excel.
5. Pembahasan validasi, keamanan, dan struktur database.
6. Penentuan keputusan, tindak lanjut, dan risiko pengembangan.

## Ringkasan Pembahasan

### 1. Gambaran Umum Project

SistemSPP adalah aplikasi administrasi sekolah berbasis web untuk membantu pengelolaan data siswa, pembayaran sekolah, tabungan siswa, role pengguna, dan laporan keuangan. Aplikasi dikembangkan menggunakan PHP, MySQL/MariaDB, HTML, CSS, dan JavaScript tanpa framework.

Project berjalan secara lokal menggunakan XAMPP dengan database utama `db_spp`. Aplikasi memakai pola PHP tradisional, yaitu halaman merender HTML dari server dan mengakses database menggunakan `mysqli`.

### 2. Fitur Login dan Tampilan Utama

Halaman login sudah menggunakan desain portal pembayaran sekolah dengan tema hijau dan oranye. Login juga memiliki animasi transisi masuk berdasarkan role pengguna agar perpindahan halaman terasa lebih halus.

Tampilan aplikasi sudah diperbaiki pada light mode dan dark mode. Palet dark mode dibuat lebih nyaman dibaca, tidak terlalu pekat, dan tetap menggunakan aksen hijau serta oranye sesuai tema SistemSPP.

### 3. Fitur Data Siswa

Fitur data siswa digunakan untuk mengelola identitas siswa kelas 1 sampai 6. Data yang dikelola meliputi nomor induk, nama, kelas, NIS Diknas, tarif pembayaran, potongan, total tagihan, dan status siswa.

Mode Advance tersedia untuk mengisi data keuangan siswa secara lebih lengkap. Siswa yang tidak aktif tidak dihapus permanen, melainkan diarsipkan agar histori transaksi tetap tersimpan dan tetap dapat ditampilkan pada laporan.

### 4. Fitur Pembayaran

Fitur pembayaran digunakan untuk mencatat transaksi pembayaran siswa. Komponen pembayaran meliputi uang pangkal, bangunan, seragam, kegiatan, SPP, makan, Sorga, infaq, uang Komite, daftar ulang, tabungan wajib, potongan SPP, dan biaya lain.

Sistem pembayaran sudah ditambahkan dengan pilihan `Tunai`, `VA`, dan `Qris`. Nilai metode pembayaran disimpan pada transaksi dan ikut tampil pada slip maupun laporan export.

Total pembayaran dihitung ulang dari backend agar data yang tersimpan tidak bergantung pada nilai dari browser. Untuk biaya tambahan, aplikasi menggunakan master biaya lain sehingga jenis biaya dapat dikelola secara dinamis. Biaya lain juga sudah mendukung cicilan: sistem menampilkan total tagihan, sudah dibayar, sisa, dan input bayar sehingga pembayaran tidak harus langsung lunas.

Untuk daftar ulang, sistem memakai master `Daftar_ulang` berdasarkan kombinasi kelas dan tahun ajaran. Jika tabel master masih kosong total, sistem memakai fallback dari nominal daftar ulang pada data siswa agar transaksi lama tetap dapat berjalan. Jika master sudah ada tetapi kelas/tahun ajaran yang dipilih belum diatur, sistem menampilkan peringatan dan pembayaran daftar ulang ditolak sampai master dilengkapi. Perhitungan sisa daftar ulang dipisahkan per siswa, kelas daftar ulang, dan tahun ajaran.

### 5. Fitur Master Biaya Lain

Master Biaya Lain digunakan untuk mengelola daftar biaya tambahan. Admin dapat menambahkan, mengubah, mengaktifkan, atau menonaktifkan jenis biaya.

Biaya yang sudah digunakan pada transaksi tetap tersimpan melalui snapshot nama dan nominal bayar. Keputusan ini dibuat agar perubahan tarif di masa depan tidak mengubah histori transaksi lama, sekaligus tetap memungkinkan pembayaran bertahap sesuai sisa tagihan siswa.

### 6. Fitur Master Daftar Ulang

Master Daftar Ulang digunakan untuk mengelola nominal daftar ulang per kelas dan tahun ajaran. Admin dapat menambahkan, mengubah, dan menghapus tarif selama tarif tersebut belum digunakan pada histori pembayaran.

Setiap kombinasi kelas dan tahun ajaran dibuat unik agar tidak ada tarif ganda. Dengan konsep ini, ketika tahun ajaran baru berubah, admin cukup membuat master nominal baru tanpa mengubah histori daftar ulang tahun sebelumnya.

### 7. Fitur Tabungan

Fitur tabungan digunakan untuk mencatat transaksi tabungan masuk dan tabungan keluar siswa. Sistem menampilkan saldo siswa, total masuk, total keluar, dan riwayat transaksi tabungan.

Penarikan tabungan tidak boleh melebihi saldo yang tersedia. Riwayat tabungan juga sudah dirapikan pada tampilan mobile, termasuk filter bulan, tahun, nomor induk, tombol aksi, dan tabel transaksi.

### 8. Role dan Hak Akses

Aplikasi menggunakan tiga role pengguna, yaitu admin, bendahara, dan kasir.

| Role | Hak Akses Utama |
| --- | --- |
| Admin | Dashboard, pembayaran, data siswa, master biaya lain, master daftar ulang, role management, tabungan, dan laporan |
| Bendahara | Dashboard, riwayat tabungan, dan laporan |
| Kasir | Tabungan masuk, tabungan keluar, dan riwayat tabungan |

Hak akses tidak hanya dibatasi dari tampilan menu, tetapi juga melalui pengecekan role pada backend. Tampilan profile pada sidebar sudah diganti menjadi avatar agar lebih rapi untuk setiap role.

### 9. Fitur Laporan dan Export

Fitur laporan digunakan untuk melihat rekap pembayaran dan tabungan berdasarkan periode bulan dan tahun. Laporan menampilkan total pembayaran, tabungan masuk, tabungan keluar, rekap komponen pembayaran, detail transaksi pembayaran, dan rekap tabungan.

Export PDF slip pembayaran sudah dirender server-side menggunakan Dompdf. Dengan perubahan ini, hasil PDF tidak lagi bergantung pada header/footer print browser. Ukuran slip disesuaikan ke format landscape `210mm x 148mm`, layout dibuat lebih rapi menggunakan tabel HTML, dan periode kosong dapat menampilkan contoh slip. Pada detail transaksi pembayaran, pengguna juga dapat mencetak slip per transaksi atau memilih beberapa transaksi tertentu untuk dicetak.

Export Excel sudah diubah menjadi alur preview terlebih dahulu. Pengguna dapat melihat preview laporan, lalu menekan tombol `Download Excel` jika sudah sesuai. Tampilan preview Excel mobile juga dirapikan dengan tombol yang proporsional dan scroll horizontal per tabel agar kolom tetap terbaca.

### 10. Responsive Mobile

Beberapa tampilan mobile sudah direvisi agar lebih nyaman digunakan, terutama:

- sidebar dan logout mobile;
- bottom navigation;
- riwayat tabungan;
- preview Excel;
- dashboard dan kartu informasi;
- tabel transaksi agar tidak memaksa halaman melebar.

Konsep mobile dibuat agar pengguna tetap dapat mengakses fitur utama tanpa tombol bertabrakan atau layout terlalu sempit.

### 11. Keamanan dan Validasi

Beberapa validasi utama sudah diterapkan, seperti validasi role, pengecekan login, validasi nominal, validasi metode pembayaran, pembatasan siswa aktif untuk transaksi baru, serta penggunaan database transaction pada proses yang melibatkan lebih dari satu tabel.

Query yang menerima input pengguna menggunakan prepared statement. Integritas relasi pembayaran juga diperkuat dengan `payment_link_version` dan referensi `bayar_id` pada child transaksi tertentu, sehingga edit atau hapus pembayaran tidak mengganggu transaksi lain.

Masih terdapat catatan pengembangan lanjutan, yaitu penerapan CSRF token secara merata pada endpoint mutasi yang belum dilindungi sepenuhnya.

## Keputusan Rapat

1. SistemSPP disepakati sebagai aplikasi administrasi pembayaran sekolah berbasis web.
2. Role pengguna ditetapkan menjadi admin, bendahara, dan kasir.
3. Data siswa yang tidak aktif tidak dihapus permanen, tetapi diarsipkan.
4. Biaya tambahan menggunakan master biaya lain agar lebih fleksibel, historinya tetap aman, dan pembayarannya dapat dicicil.
5. Daftar ulang menggunakan master resmi per kelas dan tahun ajaran agar tarif tahun ajaran baru dapat berubah tanpa mengganggu histori lama.
6. Sistem pembayaran transaksi menggunakan pilihan `Tunai`, `VA`, dan `Qris`.
7. Laporan keuangan harus dapat ditampilkan di web, dipreview sebelum export Excel, dan diexport sebagai PDF slip pembayaran.
8. Slip PDF menggunakan Dompdf server-side dengan ukuran landscape `210mm x 148mm`.
9. Tampilan mobile menjadi bagian penting dari finalisasi project karena aplikasi sering diuji melalui browser mobile atau viewport kecil.
10. Validasi nominal, role, status siswa, dan total pembayaran harus tetap dilakukan di backend.

## Action Items

| No | Tindak Lanjut | PIC | Target |
| --- | --- | --- | --- |
| 1 | Melengkapi nama peserta, waktu, tempat, PIC, dan target tanggal pada dokumen MoM | [Isi nama] | [Isi tanggal] |
| 2 | Melakukan uji coba fitur utama sebagai admin, bendahara, dan kasir | [Isi nama] | [Isi tanggal] |
| 3 | Mengecek ulang tampilan mobile pada dashboard, tabungan, dan preview Excel | [Isi nama] | [Isi tanggal] |
| 4 | Mengecek hasil slip PDF dari periode kosong dan periode yang memiliki transaksi | [Isi nama] | [Isi tanggal] |
| 5 | Menyiapkan screenshot aplikasi untuk lampiran laporan project | [Isi nama] | [Isi tanggal] |
| 6 | Menambahkan CSRF token secara merata pada endpoint mutasi yang belum terlindungi | [Isi nama] | [Isi tanggal] |
| 7 | Menyusun panduan singkat penggunaan sistem untuk pengguna admin, bendahara, dan kasir | [Isi nama] | [Isi tanggal] |

## Kendala dan Risiko

1. Belum tersedia automated test suite, sehingga pengujian masih banyak dilakukan secara manual.
2. Export Excel masih menggunakan format tabel HTML dengan ekstensi `.xls`, bukan file XLSX native.
3. Beberapa endpoint mutasi masih perlu pemerataan perlindungan CSRF.
4. Konfigurasi database masih berada langsung di file `koneksi.php`, sehingga perlu penyesuaian bila aplikasi dipindahkan ke server produksi.
5. Dependency Dompdf membutuhkan Composer. Jika folder `vendor/` belum tersedia, developer perlu menjalankan `composer install`.
6. Tampilan mobile tetap perlu diuji langsung pada beberapa ukuran layar karena browser dapat memiliki perilaku rendering yang berbeda.

## Hasil Revisi Terbaru

| Area | Hasil |
| --- | --- |
| Login | Animasi transisi masuk per role dan tampilan portal lebih modern |
| Sidebar | Avatar profile role dan logout mobile lebih mudah diakses |
| Dark mode | Palet warna dibuat lebih ramah dan tidak terlalu pekat |
| Pembayaran | Menambahkan metode pembayaran `Tunai`, `VA`, dan `Qris` |
| Daftar Ulang | Pencatatan memakai master resmi kelas/tahun ajaran, fallback hanya saat master kosong total |
| Master Daftar Ulang | CRUD admin untuk nominal daftar ulang per kelas dan tahun ajaran |
| Biaya Lain | Mendukung cicilan dengan kolom total, sudah dibayar, sisa, bayar, dan alert jika melebihi sisa |
| Navigasi Data | Baris pembayaran, transaksi dashboard, dan siswa dapat diklik langsung untuk edit |
| Slip PDF | Export server-side dengan Dompdf, ukuran `210mm x 148mm`, layout lebih rapi |
| Cetak Slip Terpilih | Detail transaksi pembayaran dapat mencetak satu atau beberapa slip yang dipilih |
| Excel | Preview sebelum download, tampilan mobile lebih rapi |
| Riwayat Tabungan | Filter dan tabel mobile diperbaiki |
| Dokumentasi | MoM, changelog, dan konteks project diperbarui |
| Git | Perubahan sudah dipush ke `origin/main` pada commit `876bb40` |

## Kesimpulan

Berdasarkan pembahasan rapat, project SistemSPP sudah memiliki fitur utama yang mendukung kebutuhan administrasi sekolah, mulai dari manajemen siswa, pembayaran, tabungan, role pengguna, hingga laporan keuangan. Revisi terbaru berfokus pada kerapian tampilan, pengalaman mobile, sistem pembayaran, preview export Excel, dan slip PDF server-side.

Sistem sudah dapat digunakan sebagai dasar laporan project, dengan catatan pengembangan lanjutan pada pemerataan CSRF, pengujian otomatis, konfigurasi produksi, dan kemungkinan peningkatan format Excel menjadi XLSX native.

## Lampiran Fitur Project

| Modul | File/Folder Utama |
| --- | --- |
| Login dan Logout | `login.php`, `logout.php` |
| Dashboard | `dashboard.php` |
| Data Siswa | `siswa/daftar.php` |
| Pembayaran | `pembayaran/` |
| Master Biaya Lain | `master_biaya_lain.php` |
| Master Daftar Ulang | `master_daftar_ulang.php` |
| Tabungan | `tabungan/` |
| Laporan Web | `laporan/index.php` |
| Export Excel | `laporan/export_excel.php` |
| Export PDF Slip | `laporan/export_pdf.php` |
| Role Management | `role_management.php` |
| Auth Guard | `includes/auth.php` |
| Sidebar dan Navigasi | `includes/sidebar.php` |
| Style Utama | `assets/css/style.css`, `assets/css/login.css` |
| Koneksi Database | `koneksi.php` |
| Schema Database | `sql/schema.sql` |
| Migrasi Tambahan | `sql/add_*.sql` |
| Dependency PHP | `composer.json`, `composer.lock` |
