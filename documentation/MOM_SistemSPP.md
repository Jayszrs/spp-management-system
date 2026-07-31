# Minutes of Meeting (MoM) Project SistemSPP

## Informasi Rapat

| Keterangan | Detail |
| --- | --- |
| Nama Rapat | Pembahasan Progres dan Finalisasi Project SistemSPP |
| Tanggal | 30 Juli 2026 |
| Waktu | [Isi waktu rapat] |
| Tempat/Media | [Isi lokasi atau media rapat] |
| Pemimpin Rapat | [Isi nama] |
| Notulis | [Isi nama] |
| Peserta | [Isi nama peserta] |

## Tujuan Rapat

Rapat ini dilakukan untuk membahas progres pengembangan SistemSPP, mengevaluasi fitur yang sudah dibuat, menyepakati alur utama aplikasi, serta menentukan tindak lanjut sebelum project digunakan sebagai bahan laporan.

## Agenda Rapat

1. Pembahasan gambaran umum SistemSPP.
2. Review fitur utama aplikasi.
3. Pembahasan role pengguna dan hak akses.
4. Pembahasan struktur data dan transaksi keuangan.
5. Review fitur laporan dan export.
6. Identifikasi kendala, risiko, dan tindak lanjut.

## Ringkasan Pembahasan

### 1. Gambaran Umum Project

SistemSPP adalah aplikasi administrasi sekolah berbasis web yang dibuat untuk membantu pengelolaan data siswa, pembayaran sekolah, tabungan siswa, dan laporan keuangan. Aplikasi dikembangkan menggunakan PHP, MySQL/MariaDB, HTML, CSS, dan JavaScript tanpa framework tambahan.

Project berjalan secara lokal menggunakan XAMPP dengan database utama `db_spp`. Aplikasi dirancang untuk digunakan oleh beberapa jenis pengguna sesuai tanggung jawab masing-masing.

### 2. Fitur Data Siswa

Fitur data siswa digunakan untuk mengelola identitas siswa kelas 1 sampai 6. Data yang dikelola meliputi nomor induk, nama, kelas, NIS Diknas, tarif pembayaran, potongan, total tagihan, dan status siswa.

Pada fitur ini juga tersedia mode Advance untuk pengisian data keuangan siswa secara lebih lengkap. Siswa tidak dihapus permanen dari sistem, tetapi dapat diarsipkan agar histori transaksi tetap tersimpan.

### 3. Fitur Pembayaran

Fitur pembayaran digunakan untuk mencatat transaksi pembayaran siswa. Komponen pembayaran yang tersedia meliputi uang pangkal, bangunan, seragam, kegiatan, SPP, makan, Sorga, infaq, uang Komite, daftar ulang, dan biaya lain.

Sistem menghitung ulang total pembayaran melalui backend agar nominal yang tersimpan lebih valid. Untuk biaya tambahan, aplikasi sudah menggunakan master biaya lain sehingga jenis biaya dapat dikelola secara dinamis.

### 4. Fitur Master Biaya Lain

Master Biaya Lain digunakan untuk mengelola daftar biaya tambahan. Admin dapat menambahkan, mengubah, mengaktifkan, atau menonaktifkan jenis biaya. Biaya yang sudah digunakan pada transaksi tetap tersimpan sebagai histori melalui snapshot nama dan nominal.

Keputusan ini dibuat agar perubahan tarif biaya di masa depan tidak mengubah data transaksi lama.

### 5. Fitur Tabungan

Fitur tabungan digunakan untuk mencatat transaksi tabungan masuk dan tabungan keluar siswa. Sistem menampilkan saldo siswa dan riwayat transaksi tabungan.

Penarikan tabungan tidak boleh melebihi saldo yang tersedia. Transaksi masuk dan keluar juga dicatat sebagai jurnal agar riwayat keuangan siswa dapat ditelusuri.

### 6. Role dan Hak Akses

Aplikasi menggunakan tiga role pengguna, yaitu admin, bendahara, dan kasir.

| Role | Hak Akses Utama |
| --- | --- |
| Admin | Dashboard, pembayaran, data siswa, master biaya lain, role management, tabungan, dan laporan |
| Bendahara | Dashboard, riwayat tabungan, dan laporan |
| Kasir | Tabungan masuk, tabungan keluar, dan riwayat tabungan |

Hak akses tidak hanya dibatasi dari tampilan menu, tetapi juga melalui pengecekan role pada backend.

### 7. Fitur Laporan

Fitur laporan digunakan untuk melihat rekap pembayaran dan tabungan berdasarkan periode bulan dan tahun. Laporan menampilkan total pembayaran, tabungan masuk, tabungan keluar, saldo tabungan, rekap komponen pembayaran, serta detail transaksi.

Aplikasi juga menyediakan export laporan dalam bentuk Excel dan halaman cetak/PDF melalui browser.

### 8. Keamanan dan Validasi

Beberapa validasi utama sudah diterapkan, seperti validasi role, pengecekan login, penggunaan prepared statement pada query input pengguna, validasi nominal, pembatasan siswa aktif untuk transaksi baru, serta penggunaan transaction pada proses yang melibatkan lebih dari satu tabel.

Masih terdapat catatan pengembangan lanjutan, yaitu penerapan CSRF token secara merata pada seluruh endpoint mutasi seperti pembayaran, tabungan, dan master biaya lain.

## Keputusan Rapat

1. SistemSPP disepakati sebagai aplikasi administrasi pembayaran sekolah berbasis web.
2. Role pengguna ditetapkan menjadi admin, bendahara, dan kasir.
3. Data siswa yang tidak aktif tidak dihapus permanen, tetapi diarsipkan.
4. Biaya tambahan menggunakan master biaya lain agar lebih fleksibel.
5. Histori transaksi lama harus tetap dipertahankan meskipun data master berubah.
6. Laporan keuangan harus dapat ditampilkan di web dan diexport ke Excel atau PDF.
7. Validasi nominal dan total pembayaran harus dilakukan di backend.

## Action Items

| No | Tindak Lanjut | PIC | Target |
| --- | --- | --- | --- |
| 1 | Melengkapi data peserta, waktu, dan lokasi rapat pada dokumen MoM | [Isi nama] | [Isi tanggal] |
| 2 | Melakukan uji coba seluruh fitur utama sebagai admin, bendahara, dan kasir | [Isi nama] | [Isi tanggal] |
| 3 | Mengecek kembali hasil export laporan Excel dan PDF | [Isi nama] | [Isi tanggal] |
| 4 | Menambahkan CSRF token pada endpoint pembayaran, tabungan, dan master biaya lain | [Isi nama] | [Isi tanggal] |
| 5 | Menyiapkan screenshot aplikasi untuk lampiran laporan project | [Isi nama] | [Isi tanggal] |
| 6 | Menyusun kesimpulan akhir dan dokumentasi penggunaan sistem | [Isi nama] | [Isi tanggal] |

## Kendala dan Risiko

1. Belum tersedia automated test suite, sehingga pengujian masih dilakukan secara manual.
2. Export Excel masih menggunakan format tabel HTML dengan ekstensi `.xls`, bukan file XLSX native.
3. PDF masih bergantung pada fitur print atau save as PDF dari browser.
4. Perlindungan CSRF belum diterapkan secara merata pada semua fitur mutasi.
5. Konfigurasi database masih berada langsung di file `koneksi.php`, sehingga perlu penyesuaian bila aplikasi dipindahkan ke server produksi.

## Kesimpulan

Berdasarkan pembahasan rapat, project SistemSPP sudah memiliki fitur utama yang mendukung kebutuhan administrasi sekolah, mulai dari manajemen siswa, pembayaran, tabungan, role pengguna, hingga laporan keuangan. Sistem sudah dapat digunakan sebagai dasar laporan project, dengan beberapa catatan pengembangan lanjutan pada sisi keamanan, pengujian otomatis, dan format export.

## Lampiran Fitur Project

| Modul | File/Folder Utama |
| --- | --- |
| Login dan Logout | `login.php`, `logout.php` |
| Dashboard | `dashboard.php` |
| Data Siswa | `siswa/daftar.php` |
| Pembayaran | `pembayaran/` |
| Master Biaya Lain | `master_biaya_lain.php` |
| Tabungan | `tabungan/` |
| Laporan | `laporan/` |
| Role Management | `role_management.php` |
| Koneksi Database | `koneksi.php` |
| Schema Database | `sql/schema.sql` |
