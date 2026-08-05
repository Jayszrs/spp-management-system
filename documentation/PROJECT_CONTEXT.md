# Konteks Proyek SistemSPP

Dokumen ini adalah sumber konteks utama untuk developer dan AI yang bekerja pada repository SistemSPP. Baca dokumen ini terlebih dahulu, lalu baca [AI_CHANGELOG.md](./AI_CHANGELOG.md) sebelum menganalisis atau mengubah kode.

## 1. Ringkasan Proyek

SistemSPP adalah aplikasi administrasi sekolah berbasis web untuk SDIT. Aplikasi mengelola:

- data dan tarif siswa kelas 1 sampai 6;
- transaksi pembayaran sekolah;
- Uang Komite bulanan;
- master biaya lain dan rincian biaya tambahan;
- daftar ulang dan potongan;
- tabungan masuk, tabungan keluar, dan saldo siswa;
- pengguna dengan role berbeda;
- rekap laporan web, dokumen cetak/PDF, dan export Excel.

Aplikasi ini merupakan aplikasi PHP tradisional tanpa framework. Halaman merender HTML di server, memakai JavaScript biasa untuk interaksi browser, dan mengakses MySQL melalui `mysqli`.

## 2. Stack dan Lingkungan Lokal

| Bagian | Teknologi |
| --- | --- |
| Backend | PHP 8.x, procedural/object-oriented `mysqli` |
| Database | MySQL/MariaDB, database `db_spp` |
| Frontend | HTML, CSS, JavaScript tanpa framework |
| Web server lokal | Apache dari XAMPP |
| Export PDF | Dompdf server-side dari template HTML slip |
| Export Excel | HTML table dengan response `.xls` |
| Dependency manager | Composer untuk dependency PHP (`dompdf/dompdf`) |

Lokasi kerja standar pada mesin pengembangan saat ini:

```text
C:\xampp\htdocs\spp-management-system
```

URL lokal:

```text
http://localhost/spp-management-system/
```

Konfigurasi koneksi berada di `koneksi.php`. Default lokal menggunakan host `localhost`, user MySQL `root`, password kosong, dan database `db_spp`. Jangan menyalin konfigurasi lokal ini ke produksi tanpa secret management dan kredensial baru.

Aplikasi menetapkan timezone PHP ke `Asia/Jakarta` dan session MySQL ke `+07:00` dari `koneksi.php`, sehingga timestamp transaksi tampil konsisten dengan WIB.

## 3. Menjalankan Proyek

1. Aktifkan Apache dan MySQL dari XAMPP.
2. Pastikan repository berada di document root Apache.
3. Jalankan `composer install` bila folder `vendor/` belum tersedia.
4. Untuk instalasi database baru, jalankan `sql/schema.sql`.
5. Buka URL lokal dan login menggunakan akun seed pengembangan.
6. Segera ganti password seed pada lingkungan selain pengembangan lokal.

Contoh impor schema baru melalui PowerShell:

```powershell
composer install
Get-Content sql\schema.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
```

`sql/schema.sql` menyediakan akun seed lokal `admin`, `bendahara`, dan `kasir`. Password seed terlihat di schema dan hanya ditujukan untuk pengembangan. Login lama yang masih memakai MD5 akan otomatis dinaikkan ke `PASSWORD_DEFAULT` setelah autentikasi berhasil.

## 4. Struktur Repository

| Path | Tanggung jawab |
| --- | --- |
| `dashboard.php` | Ringkasan jumlah siswa aktif, transaksi, nominal, dan transaksi terbaru |
| `login.php`, `logout.php` | Autentikasi session dan keluar aplikasi |
| `role_management.php` | CRUD akun dan role, hanya untuk admin |
| `master_biaya_lain.php` | CRUD master jenis biaya tambahan |
| `master_daftar_ulang.php` | CRUD master nominal daftar ulang per kelas dan tahun ajaran |
| `pembayaran/` | Input, daftar, edit, hapus, dan proses transaksi pembayaran |
| `siswa/` | Master siswa, mode Advance, arsip, filter, dan audit perubahan |
| `tabungan/` | Transaksi masuk/keluar, saldo, dan riwayat tabungan |
| `laporan/` | Rekap web, halaman cetak/PDF, dan export Excel |
| `includes/auth.php` | Guard autentikasi dan role |
| `includes/sidebar.php` | Navigasi desktop/mobile sesuai role |
| `assets/css/` | Tema, layout, dan responsive design |
| `assets/js/app.js` | Interaksi UI dan kalkulasi tampilan pembayaran |
| `sql/schema.sql` | Schema lengkap untuk instalasi baru |
| `sql/add_*.sql` | Migrasi bertahap untuk database lama, termasuk sistem pembayaran |
| `documentation/` | Konteks stabil proyek dan riwayat perubahan AI |

## 5. Role dan Hak Akses

Guard backend memakai `requireRole()` dari `includes/auth.php`. Menyembunyikan menu saja tidak dianggap sebagai kontrol akses.

| Fitur | Admin | Bendahara | Kasir |
| --- | :---: | :---: | :---: |
| Dashboard | Ya | Ya | Tidak |
| Input/lihat/edit pembayaran | Ya | Tidak | Tidak |
| Master Siswa | Ya | Tidak | Tidak |
| Master Biaya Lain | Ya | Tidak | Tidak |
| Master Daftar Ulang | Ya | Tidak | Tidak |
| Role Management | Ya | Tidak | Tidak |
| Tabungan masuk/keluar | Ya | Tidak | Ya |
| Riwayat tabungan | Ya | Ya | Ya |
| Laporan, PDF, dan Excel | Ya | Ya | Tidak |

Role yang valid hanya `admin`, `bendahara`, dan `kasir`. Pengguna tanpa role valid harus dikeluarkan dari session dan diarahkan kembali ke login.

## 6. Model Data Utama

### `admin`

Menyimpan akun, hash password, nama, dan role. Password baru wajib dibuat dengan `password_hash()`.

### `siswa`

Menyimpan identitas, kelas, tarif per siswa, potongan, saldo awal pembayaran, dan status aktif. Aturan penting:

- `NO_INDUK` unik dan menjadi foreign key pada transaksi;
- `KELAS` hanya `1` sampai `6`;
- nominal master siswa menggunakan `DECIMAL(15,2)`;
- `is_active=0` berarti siswa diarsipkan, bukan dihapus;
- `tot_pangkal = MAX(0, PANGKAL - potong_pangkal)`;
- `tot_du = MAX(0, DAFTAR_ULANG - potong_du)`;
- `POMG` adalah tarif dasar Uang Komite bulanan.

### `siswa_audit_log`

Menyimpan aksi, admin, waktu, nomor induk snapshot, serta JSON sebelum dan sesudah perubahan siswa. Relasi siswa dan admin memakai `ON DELETE SET NULL` agar catatan audit tidak hilang ketika parent tidak tersedia.

### `bayar`

Header dan komponen utama transaksi pembayaran. `NO_INDUK` berelasi ke siswa dengan `ON UPDATE CASCADE` dan `ON DELETE CASCADE`. Kolom `U_KOMITE` menyimpan pembayaran Komite untuk kombinasi siswa, bulan, dan tahun.

`payment_link_version` adalah kontrak integritas child pembayaran: `0` berarti transaksi legacy yang relasinya tidak dapat diverifikasi dan tidak boleh diubah/dihapus dari aplikasi; `1` berarti seluruh child yang dibuat oleh alur baru menggunakan relasi `bayar_id` aman.

Kolom `sistem_pembayaran` menyimpan metode pembayaran transaksi. Nilai yang valid hanya `Tunai`, `VA`, dan `Qris`. Data lama menggunakan default `VA`.

Kolom `created_at` menyimpan waktu data pembayaran dibuat, sedangkan `updated_at` menyimpan waktu terakhir transaksi diubah. Halaman lihat pembayaran menampilkan waktu bayar dari `TGL_BYR` dan keterangan `Diubah` bila `updated_at` lebih baru dari `created_at`.

Kolom lama `U_LAIN`, `LAIN_LAIN1-4`, dan `JUMLAH1-4` masih dipertahankan untuk kompatibilitas data. Transaksi baru memakai tabel detail biaya lain dan mengisi kolom lama dengan nilai netral.

### `master_biaya_lain`

Master nama, nominal, dan status biaya tambahan. Nama unik, nominal harus lebih dari nol, dan master nonaktif tidak boleh dipilih untuk transaksi baru.

### `bayar_biaya_lain`

Detail biaya tambahan per transaksi. Nama dan nominal disimpan sebagai snapshot agar perubahan tarif master tidak mengubah histori. Relasi ke transaksi memakai `ON DELETE CASCADE`; master yang sudah digunakan dilindungi dengan `ON DELETE RESTRICT`.

### `bayar_du` dan `Daftar_ulang`

`bayar_du` menyimpan pembayaran daftar ulang per siswa, kelas daftar ulang, dan tahun ajaran. Child yang dibuat oleh pembayaran versi aman menyimpan `bayar_id` unik dengan foreign key `ON DELETE CASCADE` ke `bayar`.

Tabel `Daftar_ulang` dipakai sebagai template tarif per kombinasi `kelas + th_ajaran`, sedangkan kewajiban nyata siswa disimpan pada `tagihan_daftar_ulang`. Tahun ajaran mengikuti kalender pendidikan Juli-Juni: Juli-Desember memakai `YYYY/YYYY+1`, sedangkan Januari-Juni memakai `YYYY-1/YYYY`. Pembayaran baru tidak memakai fallback nominal dari kolom Daftar Ulang pada `siswa`; total dan saldo selalu dibaca dari tagihan siswa yang sudah diterbitkan. `bayar_du.tagihan_daftar_ulang_id` menjadi referensi saldo utama, sementara kelas dan tahun ajaran tetap disimpan sebagai snapshot kompatibilitas.

### `tabungan`, `transaksi_m`, dan `transaksi_k`

`tabungan` menyimpan saldo berjalan per siswa. `transaksi_m` dan `transaksi_k` menyimpan jurnal masuk dan keluar. Setoran Tabungan Wajib dari pembayaran aman menyimpan `transaksi_m.bayar_id` unik dengan foreign key `ON DELETE CASCADE`; setoran manual bernilai `NULL`, sedangkan seluruh `transaksi_k` tetap tidak berelasi ke pembayaran. Semua foreign key nomor induk mengikuti perubahan nomor induk melalui `ON UPDATE CASCADE`.

## 7. Alur dan Aturan Bisnis

### Master Siswa

- Form dasar memuat nomor induk, nama, dan kelas.
- Switch `Advance` membuka NIS Diknas, tarif, Komite, potongan, total turunan, dan saldo awal.
- Menutup Advance saat edit tidak boleh menimpa field lanjutan dengan nol.
- NIS Diknas harus tepat 10 digit bila diisi.
- Nominal tidak boleh negatif dan potongan tidak boleh melebihi tagihan.
- Saldo awal hanya dapat diedit sebelum ada histori pada pembayaran, daftar ulang, tabungan masuk, atau tabungan keluar.
- Perubahan nomor induk dilakukan dalam database transaction dan mengandalkan foreign key `ON UPDATE CASCADE`.
- Aksi hapus pada UI diganti menjadi Arsipkan/Pulihkan.
- Siswa arsip tetap tersedia untuk histori dan laporan, tetapi tidak boleh dipakai pada transaksi baru.
- Tambah, edit, perubahan tarif, perubahan nomor induk, arsip, dan pemulihan dicatat pada audit log.

### Pembayaran

- Dropdown bulan menampilkan nama bulan saat dibuka dan menampilkan kode `01` sampai `12` setelah dipilih.
- Hanya siswa aktif yang muncul pada transaksi baru.
- Edit transaksi lama tetap mengizinkan siswa arsip yang memang menjadi pemilik transaksi; siswa arsip lain tidak dapat dipilih.
- Backend mengambil kelas, tarif Komite, dan total tagihan master biaya lain langsung dari database.
- `total_jumlah` dihitung ulang di backend dari komponen pembayaran, daftar ulang, biaya lain, dan potongan SPP. Hidden total dari browser bukan sumber kebenaran.
- Pada rincian pembayaran, `Total Tagihan`, `Sudah Terbayar`, dan `Sisa` adalah nilai sistem otomatis dari database/histori dan ditampilkan sebagai readonly visual-only; hanya `Input Bayar` yang dapat diedit pengguna.
- Halaman edit pembayaran harus auto-bind siswa yang sedang diedit saat load sehingga rincian tagihan langsung memakai konteks siswa aktif, master/tarif terbaru, dan histori lain yang mengecualikan `bayar.id` transaksi tersebut.
- Sistem pembayaran dipilih dari opsi `Tunai`, `VA`, atau `Qris` dan divalidasi ulang di backend.
- Nominal negatif, periode tidak valid, pembayaran Komite melebihi sisa periode, dan input komponen yang melebihi sisa tagihan harus ditolak.
- Form pembayaran menampilkan alert bila `Sudah Terbayar` lebih besar dari `Total Tagihan`; sisa ditampilkan sebagai nol dan input bayar pada komponen tersebut dikunci.
- Form pembayaran juga menampilkan alert inline bila `Input Bayar` lebih besar dari sisa tagihan sebelum submit; input tersebut diberi invalid state dan browser menahan submit melalui custom validity.
- Simpan, edit, dan hapus transaksi utama, daftar ulang, tabungan terkait, serta detail biaya lain dijalankan dalam transaction.
- Pembayaran baru menyimpan relasi eksplisit ke Daftar Ulang dan setoran Tabungan Wajib melalui `bayar_id`. Edit atau hapus hanya boleh mengubah child dengan `bayar_id` yang sama, bukan child dengan NIS, tanggal, atau tahun ajaran yang kebetulan sama.
- Pembayaran `payment_link_version=0` adalah legacy. Sistem menandainya di daftar dan menolak edit/hapus, termasuk akses endpoint langsung; rekonsiliasi harus dilakukan manual tanpa pencocokan otomatis.
- Sebelum edit/hapus membalikkan setoran Tabungan Wajib, baris saldo dikunci. Bila pembalikan membuat saldo negatif, seluruh transaction ditolak dan di-rollback.

### Uang Komite

- Tarif dasar berasal dari `siswa.POMG`.
- Komite bersifat opsional; input default nol.
- Sudah dibayar dan sisa dihitung per `NO_INDUK + BULAN + TAHUN`.
- Periode berbeda memiliki saldo Komite yang terpisah.
- Edit transaksi mengecualikan transaksi yang sedang diedit saat menghitung pembayaran periode sebelumnya.
- Pada edit pembayaran, basis total tagihan mengikuti master/tarif terbaru sesuai keputusan project; nominal input transaksi aktif tetap dipertahankan dan ikut diuji terhadap sisa terbaru.

### Master Biaya Lain

- Admin dapat menambah, mengubah, mengaktifkan, atau menonaktifkan master.
- Penghapusan permanen hanya boleh jika master belum pernah digunakan.
- Form pembayaran mendukung beberapa baris biaya lain dan master yang sama dapat dipakai lebih dari sekali dengan keterangan berbeda.
- Nominal master biaya lain menjadi total tagihan, sedangkan `bayar_biaya_lain.nominal_snapshot` menyimpan nominal cicilan yang benar-benar dibayar pada transaksi.
- Form pembayaran menampilkan total tagihan, sudah dibayar, sisa, dan input bayar untuk biaya lain. Input bayar boleh lebih kecil dari sisa, tetapi tidak boleh melebihi sisa akumulasi siswa untuk master tersebut.
- Bila master pada detail lama tidak berubah, nama snapshot lama dipertahankan; nominal snapshot dapat diedit sebagai nominal cicilan selama tidak melebihi sisa tagihan.
- Master nonaktif tetap dapat ditampilkan pada edit transaksi lama, tetapi tidak untuk pilihan baru.

### Master Daftar Ulang

- Admin mengelola enam tarif kelas per tahun ajaran pada `master_daftar_ulang.php`; kombinasi tahun ajaran dan kelas dijaga unik.
- Halaman master hanya menampilkan tarif, jumlah siswa aktif per kelas, dan ringkasan penerbitan. Daftar penempatan siswa tidak ditampilkan agar tetap ringan untuk ratusan siswa.
- `siswa.KELAS` menjadi sumber kelas aktif saat penerbitan. Admin harus memperbarui kelas dan status aktif melalui Data Siswa sebelum memakai `Simpan & Terbitkan Tagihan`.
- Untuk tahun draf, satu transaksi menyimpan enam tarif, menyelaraskan penempatan internal, membuat satu tagihan per siswa aktif, mengubah status menjadi `published`, dan menulis audit. Kegagalan salah satu tahap membatalkan seluruh perubahan.
- Tahun yang sudah terbit memakai `Simpan Perubahan Tarif`; tagihan belum lunas mengikuti aturan perubahan nominal, sedangkan tagihan lunas mempertahankan snapshot histori.
- Siswa tinggal kelas memakai kelas yang tetap tersimpan pada Data Siswa; siswa tidak aktif tidak menerima tagihan. Siswa baru setelah penerbitan otomatis memperoleh penempatan dan tagihan.
- Input/edit pembayaran menghitung tahun ajaran dari periode pilihan dan mengambil kelas serta saldo langsung dari tagihan server-side; hidden input kelas/tahun tidak dipercaya. Total, terbayar, sisa, kelas, tahun ajaran, status, dan warning tampil pada baris Daftar Ulang.
- Tagihan yang telah diterbitkan mempertahankan snapshot kelas dan nominal sehingga perubahan Data Siswa berikutnya tidak memindahkan histori.
- Migrasi tidak boleh menerbitkan tahun ajaran draf secara otomatis. Status `published` hanya diubah oleh aksi penerbitan admin.

### Tabungan

- Hanya siswa aktif yang dapat menerima transaksi tabungan baru.
- Penarikan tidak boleh melebihi saldo.
- Saldo dan jurnal harus diperbarui dalam satu transaction dengan row lock.
- Histori siswa arsip tetap tampil.

### Laporan

- Rekap web, PDF, dan Excel mengambil data transaksi berdasarkan periode tanggal bayar.
- Rekap komponen tetap mencakup Uang Komite.
- Biaya lain diagregasi berdasarkan `nama_biaya_snapshot`, bukan nama master saat ini.
- Siswa arsip tidak dikeluarkan dari histori laporan.
- Filter laporan yang menerima input pengguna, termasuk filter NIS riwayat tabungan, wajib menggunakan prepared statement; input tidak boleh dirangkai ke SQL.
- Export PDF memakai Dompdf server-side sehingga hasil PDF tidak memuat header/footer bawaan print browser.
- Tombol `Export PDF` pada laporan mencetak seluruh transaksi periode, sedangkan detail transaksi pembayaran menyediakan `Cetak Dipilih` dan cetak per baris untuk slip transaksi tertentu.

## 8. Instalasi dan Migrasi Database

### Database baru

Gunakan hanya:

```text
sql/schema.sql
```

Schema ini bersifat destruktif untuk sebagian tabel karena memakai `DROP TABLE`. Jangan jalankan pada database berisi data produksi.

### Upgrade database lama

1. Buat backup database.
2. Periksa nilai `siswa.KELAS`. Label lama maksimal 5 karakter akan dipertahankan untuk kompatibilitas; siswa baru tetap dibatasi kelas `1` sampai `6`.
3. Jalankan `sql/add_master_biaya_lain.sql`.
4. Jalankan `sql/add_master_daftar_ulang.sql`.
5. Jalankan `sql/add_academic_year_billing.sql`.
6. Jalankan `sql/add_student_advanced.sql`.
7. Jalankan `sql/add_payment_references.sql`.
8. Jalankan `sql/add_payment_method.sql`.
9. Jalankan `sql/verify_schema.sql` dan uji aplikasi.

Contoh PowerShell:

```powershell
Get-Content sql\add_master_biaya_lain.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\add_master_daftar_ulang.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\add_academic_year_billing.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\add_student_advanced.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\add_payment_references.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\add_payment_method.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\verify_schema.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
```

Seluruh migrasi bertahap dirancang idempotent. Migrasi siswa melakukan preflight dan berhenti bila menemukan kelas kosong atau lebih dari 5 karakter. Migrasi tahun ajaran menambahkan penempatan dan tagihan siswa, lalu menghubungkan histori `bayar_du` yang memiliki kelas+tahun ajaran valid tanpa mengubah nominal transaksi. Tahun ajaran memakai batas Juli–Juni dan tagihan terbit menjadi sumber saldo Daftar Ulang baru. Migrasi relasi pembayaran lain tetap mempertahankan data legacy yang belum dapat dibuktikan relasinya.

## 9. Konvensi Keamanan

- Semua halaman privat harus memulai session dan memakai `requireRole()`.
- Semua query dengan input pengguna harus memakai prepared statement.
- Mutasi lintas tabel wajib memakai database transaction dan rollback saat gagal.
- Data uang, role, status, tarif, dan total harus divalidasi ulang di backend.
- Output pengguna wajib melalui `htmlspecialchars()` sesuai konteks HTML.
- Mutation baru wajib memakai CSRF token dan `hash_equals()`.
- Nomor induk, ID, nominal, role, hidden input, dan atribut `readonly` dari browser tetap dianggap tidak tepercaya.
- Jangan menulis password plaintext, dump data siswa nyata, cookie, token session, atau secret ke repository maupun dokumentasi.

Cakupan CSRF saat ini belum merata. Master Siswa dan Role Management sudah memakai CSRF, sedangkan pembayaran, tabungan, dan Master Biaya Lain masih menjadi technical debt. Jangan menyatakan endpoint tersebut sudah terlindungi sebelum implementasinya benar-benar ditambahkan dan diuji.

## 10. Checklist Verifikasi

### Static check

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
node --check assets\js\app.js
git diff --check
```

### Database

- Uji schema atau migrasi pada salinan database lebih dahulu.
- Jalankan migrasi dua kali untuk memastikan idempotensi.
- Periksa tipe kolom, index, check constraint, foreign key, dan jumlah data sebelum/sesudah.
- Hapus semua data pengujian setelah selesai.

### HTTP dan hak akses

- Uji halaman sebagai admin, bendahara, kasir, dan tanpa session.
- Uji POST valid, POST dengan CSRF salah pada endpoint yang terlindungi, serta manipulasi nominal/status/ID.
- Pastikan redirect role mengarah ke halaman default yang benar.

### Alur bisnis

- Uji CRUD dasar dan Advance siswa, preservasi field, audit, arsip, perubahan nomor induk, dan cascade.
- Uji pembayaran Komite sebagian, penuh, berlebih, dan pada dua periode berbeda.
- Uji satu siswa dengan dua pembayaran pada tanggal sama: edit/hapus salah satunya hanya boleh memengaruhi `bayar_du` dan `transaksi_m` yang memiliki `bayar_id` miliknya; setoran manual pada tanggal sama harus tetap utuh.
- Uji penolakan update/hapus ketika pembalikan setoran Tabungan Wajib membuat saldo negatif, serta penolakan edit/hapus pembayaran legacy.
- Uji biaya lain aktif/nonaktif, snapshot, edit, penghapusan master terpakai, dan cascade detail.
- Uji tabungan masuk, keluar, saldo tidak cukup, dan siswa arsip.
- Bandingkan total web, PDF, Excel, dan data database.

### UI

- Periksa desktop dan mobile dengan screenshot browser nyata.
- Pastikan tidak ada overflow horizontal, overlap, teks terpotong, atau kontrol yang sulit digunakan.
- Periksa light mode dan dark mode bila CSS terkait tema berubah.

## 11. Keterbatasan dan Technical Debt

- Belum ada automated test suite; pengujian saat ini memakai lint, HTTP request, query database, dan screenshot manual/headless.
- Export Excel masih berupa HTML table dengan ekstensi `.xls`, bukan file XLSX native.
- Export PDF memakai Dompdf; validasi visual tetap perlu dilakukan pada viewer PDF/browser karena engine PDF berbeda dari rendering HTML browser.
- CSRF belum diterapkan pada seluruh endpoint mutasi.
- Beberapa kolom transaksi legacy masih memakai `DOUBLE` dan struktur lama tetap dipertahankan untuk kompatibilitas.
- Penamaan `user_id` pada tabel legacy belum konsisten antara ID dan nama pengguna serta belum semuanya menjadi foreign key.
- Konfigurasi database masih berada langsung di `koneksi.php` dan belum menggunakan environment variable.

## 12. Aturan Wajib untuk Developer dan AI

1. Baca file ini, lalu [AI_CHANGELOG.md](./AI_CHANGELOG.md), sebelum bekerja.
2. Periksa `git status`, kode terkait, dan schema sebelum membuat asumsi.
3. Jangan menghapus atau mengembalikan perubahan worktree yang tidak dibuat olehmu.
4. Bila dokumentasi berbeda dengan kode/schema, anggap kode dan schema sebagai fakta saat ini, lalu perbaiki dokumentasinya dalam task yang sama.
5. Perbarui file ini jika arsitektur, role, schema, setup, aturan bisnis, security contract, atau prosedur testing berubah.
6. Tambahkan satu entri pada `AI_CHANGELOG.md` untuk setiap perubahan kode, database, UI, konfigurasi, keamanan, atau dokumentasi.
7. Catatan changelog harus menyebut dampak database, kompatibilitas, dan pengujian secara jujur. Jangan mengklaim test yang tidak dijalankan.
8. Jangan menulis ulang atau menghapus riwayat lama. Koreksi dibuat sebagai entri baru dengan referensi ke entri sebelumnya.
9. Dokumentasi dan implementasi harus masuk commit yang sama.
10. Sebelum commit, jalankan checklist yang relevan dan pastikan tidak ada file preview, credential, dump, cookie, atau data uji.

## 13. Urutan Baca untuk AI Baru

1. `documentation/PROJECT_CONTEXT.md`
2. `documentation/AI_CHANGELOG.md`
3. `git status` dan `git log` terbaru
4. File implementasi dan SQL yang berhubungan langsung dengan permintaan

Dokumentasi membantu orientasi, tetapi bukan pengganti pembacaan kode untuk perubahan yang akan diimplementasikan.
