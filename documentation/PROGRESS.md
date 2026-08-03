# Progres SistemSPP

Dokumen kerja ini melacak pekerjaan teknis yang sedang dan sudah dilakukan. Perbarui status, bukti verifikasi, dan tindak lanjut setiap kali ada perubahan yang memengaruhi data, alur bisnis, atau keamanan.

## Aturan pembaruan

- Tambahkan atau perbarui satu baris temuan pada tabel di bawah dalam pekerjaan yang sama dengan perubahan kode.
- Tulis bukti yang benar-benar dijalankan; jangan menyebut pengujian yang belum dilakukan.
- Jangan mengubah histori legacy secara otomatis hanya untuk menutup temuan. Catat prosedur rekonsiliasi manual bila diperlukan.
- Setelah pekerjaan selesai, tambahkan ringkasan yang konsisten di `AI_CHANGELOG.md` dan perbarui `PROJECT_CONTEXT.md` bila kontrak sistem berubah.

## Baseline database dan migrasi

| Item | Status | Catatan |
| --- | --- | --- |
| Database pengembangan | Tersedia | `db_spp` lokal; saat migrasi ini diterapkan, tabel transaksi pembayaran dan tabungan tidak berisi data. |
| Instalasi baru | Siap | `sql/schema.sql` sudah memuat relasi pembayaran aman. File ini destruktif dan tidak boleh dijalankan pada database berisi data. |
| Upgrade database berhistori | Siap | Jalankan `sql/add_payment_references.sql` setelah backup. Migrasi bersifat idempoten dan tidak melakukan backfill relasi. |
| Pemeriksaan schema | Siap | Jalankan `sql/verify_schema.sql`; hasil harus seluruhnya `OK`. |

## Register temuan dan progres

| ID | Temuan | Prioritas | Status | Bukti / tindak lanjut |
| --- | --- | --- | --- | --- |
| FIN-001 | Edit/hapus pembayaran dahulu dapat menyentuh Daftar Ulang atau jurnal tabungan lain dengan NIS dan tanggal/tahun yang sama. | Kritis | Selesai | `bayar_du.bayar_id` dan `transaksi_m.bayar_id` sekarang unik dan ber-FK ke header pembayaran. Handler hanya mengakses child lewat `bayar_id`. |
| FIN-002 | Pembalikan setoran Tabungan Wajib dapat membuat saldo tabungan negatif. | Kritis | Selesai | Saldo dikunci dalam transaction sebelum dibalikkan; update/hapus ditolak dan di-rollback bila saldo tidak cukup. |
| DB-001 | Database lama belum memiliki relasi pembayaran eksplisit. | Kritis | Selesai | Migrasi idempoten dan `verify_schema.sql` tersedia; migrasi dijalankan dua kali pada database lokal dan pemeriksaan mengembalikan `OK`. |
| COMP-001 | Histori pembayaran lama tidak dapat dibuktikan relasinya secara aman. | Tinggi | Diterima / dibatasi | Tetap legacy (`payment_link_version=0`, child `bayar_id=NULL`), tidak dicocokkan otomatis, dan hanya dapat direkonsiliasi manual. |
| SEC-001 | CSRF serta lokasi SQL injection/XSS lain masih belum ditangani menyeluruh. | Tinggi | Terbuka | Perbaikan SEC-002 menutup filter NIS riwayat tabungan; endpoint mutasi dan lokasi lain tetap menjadi pekerjaan terpisah. |
| SEC-002 | Filter NIS riwayat tabungan merangkai input URL langsung ke query SQL. | Kritis | Selesai | Kedua cabang query `UNION` sekarang memakai placeholder prepared statement. Uji filter kosong, NIS valid, dan payload SQL dilakukan tanpa error atau perluasan hasil. |
| PAY-001 | Input pembayaran sebelumnya belum mencegah komponen yang sudah terbayar lebih dari total tagihan. | Tinggi | Selesai | Form menampilkan alert, sisa dianggap nol, input komponen dikunci, dan backend menolak input yang melebihi sisa. |
| PAY-002 | Biaya lain sebelumnya harus lunas sesuai nominal master dan belum mendukung cicilan. | Tinggi | Selesai | Baris biaya lain menampilkan `Total`, `Sudah`, `Sisa`, dan `Bayar`; nominal snapshot menyimpan nilai cicilan transaksi. |
| PAY-003 | Reset form masih dapat meninggalkan alert overpayment. | Sedang | Selesai | Reset membersihkan alert, state row overpaid, custom validity, dan baris biaya lain. |
| PAY-004 | Pencatatan daftar ulang belum memakai master `Daftar_ulang` secara kontekstual. | Tinggi | Selesai | Nominal DU memakai master per `kelas + th_ajaran` bila tersedia, fallback ke data siswa bila master kosong, dan sisa DU dihitung per `NO_INDUK + kelas + th_ajaran`. |
| PAY-005 | Daftar pembayaran, dashboard, dan data siswa masih mengharuskan klik tombol edit. | Rendah | Selesai | Baris tabel dapat diklik langsung untuk masuk edit; tombol aksi tetap berjalan sendiri. |
| REP-001 | Export Excel langsung download tanpa preview. | Sedang | Selesai | Alur diubah menjadi preview terlebih dahulu, lalu tombol `Download Excel`. |
| REP-002 | Slip PDF pernah bergantung pada print browser dan memunculkan header/footer URL. | Tinggi | Selesai | Slip dirender server-side memakai Dompdf dengan ukuran landscape `210mm x 148mm`; header/footer browser tidak ikut tercetak. |
| UI-001 | Beberapa tampilan mobile dan dark mode kurang rapi/user friendly. | Sedang | Selesai bertahap | Sidebar mobile, logout, bottom nav, preview Excel, riwayat tabungan, avatar role, dan palet dark mode sudah direvisi. |

## Log perubahan alur aplikasi

### Login dan role

- Login memakai tampilan portal pembayaran sekolah dengan palet hijau-oranye.
- Setelah login, transisi masuk dibuat lebih halus dengan animasi dari sisi kiri/kanan sesuai konteks halaman.
- Role yang dipakai tetap `admin`, `bendahara`, dan `kasir`; akses backend divalidasi dengan `requireRole()`.
- Sidebar profile setiap role memakai avatar gambar, bukan inisial teks.
- Pada mobile, logout tetap tersedia melalui sidebar dan bottom navigation tidak menutup akses menu penting.

### Dashboard dan navigasi tabel

- Dashboard menampilkan ringkasan siswa, transaksi, total nominal, dan bayar bulan ini.
- Baris `Transaksi Terbaru` dapat diklik langsung untuk membuka halaman edit transaksi.
- Tombol `Edit` dan `Hapus` tetap dipertahankan sebagai aksi eksplisit, tetapi bukan satu-satunya cara masuk detail.

### Data siswa

- Form siswa memiliki mode dasar dan mode `Advance`.
- Mode `Advance` memuat NIS Diknas, tarif pembayaran, potongan, total turunan, dan saldo awal.
- Siswa tidak dihapus permanen; status diubah menjadi arsip/nonaktif agar histori pembayaran dan laporan tetap aman.
- Baris siswa pada daftar siswa dapat diklik langsung untuk masuk mode edit.

### Pembayaran siswa

- Sistem pembayaran transaksi berubah menjadi pilihan `Tunai`, `VA`, dan `Qris`.
- Total pembayaran dihitung ulang di backend dari komponen resmi, bukan percaya penuh pada nilai browser.
- Pembayaran Komite dan SPP dihitung per periode `NO_INDUK + BULAN + TAHUN`.
- Komponen pembayaran yang sudah terbayar lebih besar dari total menampilkan alert, sisa menjadi nol, dan input dikunci.
- Backend ikut menolak nominal negatif, periode tidak valid, metode pembayaran tidak valid, dan pembayaran yang melebihi sisa tagihan.
- Edit/hapus pembayaran hanya menyentuh child yang terhubung dengan `bayar_id`, bukan mencocokkan berdasarkan NIS/tanggal secara rapuh.

### Daftar ulang

- Pembayaran daftar ulang dicatat pada `bayar_du` dengan `bayar_id`, `no_induk`, `kelas`, `th_ajaran`, dan `jumlah`.
- Master `Daftar_ulang` menjadi sumber nominal per `kelas + th_ajaran` bila tabel master berisi data.
- Bila master `Daftar_ulang` belum diisi, sistem fallback ke nominal daftar ulang dari tabel `siswa` agar transaksi lama tetap bisa digunakan.
- Perhitungan `Sudah Terbayar` dan `Sisa` daftar ulang dipisah per siswa, kelas daftar ulang, dan tahun ajaran.
- Pemeriksaan lokal terakhir menunjukkan tabel `Daftar_ulang` tersedia tetapi masih kosong, sehingga data master perlu diisi/import bila ingin memakai nominal master sepenuhnya.

### Master biaya lain

- Biaya tambahan dipindahkan ke master `master_biaya_lain`.
- Form pembayaran bisa menambahkan beberapa baris biaya lain.
- Setiap baris biaya lain menampilkan `Jenis`, `Total`, `Sudah`, `Sisa`, `Bayar`, dan `Keterangan`.
- Biaya lain dapat dicicil; `bayar_biaya_lain.nominal_snapshot` menyimpan nominal yang benar-benar dibayar pada transaksi tersebut.
- Pembayaran biaya lain yang melebihi sisa menampilkan alert di UI dan ditolak di backend.
- Master biaya lain yang sudah dipakai tidak mengubah histori transaksi lama karena nama dan nominal transaksi disimpan sebagai snapshot.

### Lihat pembayaran

- Halaman lihat pembayaran menampilkan tanggal dan jam bayar.
- Bila transaksi pernah diupdate, sistem menampilkan keterangan waktu update.
- Baris transaksi dapat diklik langsung untuk edit tanpa harus menekan tombol `Edit`.
- Transaksi legacy dengan `payment_link_version=0` tetap dibatasi dan tidak diedit/hapus otomatis.

### Tabungan

- Tabungan masuk dan keluar tetap berjalan dengan saldo per siswa.
- Penarikan tabungan tidak boleh melebihi saldo.
- Setoran Tabungan Wajib dari pembayaran dihubungkan dengan `transaksi_m.bayar_id`.
- Saat edit/hapus pembayaran, pembalikan tabungan wajib dilakukan dengan transaction dan row lock agar saldo tidak negatif.
- Riwayat tabungan mobile sudah dirapikan pada filter, tombol, dan tabel.

### Laporan dan export

- Laporan web menampilkan rekap pembayaran, tabungan masuk, tabungan keluar, detail pembayaran, dan rekap tabungan per periode.
- Export Excel berubah dari download otomatis menjadi preview terlebih dahulu, lalu download setelah pengguna menekan tombol.
- Preview Excel memakai palet hijau-oranye dan layout mobile yang lebih rapi.
- Export PDF slip pembayaran memakai Dompdf server-side, bukan print browser.
- Slip pembayaran memakai ukuran landscape `210mm x 148mm` dan tidak memunculkan header/footer URL browser.
- Laporan periode kosong dapat menampilkan contoh slip untuk kebutuhan contoh kwitansi.
- Detail transaksi pembayaran mendukung cetak slip per transaksi atau beberapa transaksi terpilih.

### Tampilan mobile dan tema

- Sidebar, bottom navigation, tombol aksi, tabel, dan preview laporan disesuaikan untuk viewport kecil.
- Dark mode diganti ke palet yang lebih nyaman dibaca dengan aksen hijau-oranye.
- Avatar profile role menggantikan badge inisial agar sidebar lebih rapi.

## Kontrak kompatibilitas pembayaran

- Pembayaran baru diberi `bayar.payment_link_version=1` dan setiap Daftar Ulang atau setoran Tabungan Wajib miliknya menyimpan `bayar_id`.
- Pembayaran berhistori tetap `payment_link_version=0`. Sistem tidak menebak relasi berdasarkan NIS, tanggal, atau tahun ajaran.
- Pembayaran legacy ditandai di daftar dan tidak bisa diedit atau dihapus melalui UI maupun endpoint langsung. Selesaikan hanya melalui rekonsiliasi manual yang terdokumentasi dan disetujui.
- Transaksi tabungan manual (`transaksi_m.bayar_id IS NULL`) dan semua penarikan (`transaksi_k`) bukan child pembayaran; edit/hapus pembayaran tidak boleh mengubahnya.

## Bukti verifikasi terakhir

Dilakukan pada 2026-07-30 di database lokal `db_spp` setelah memastikan tabel transaksi kosong:

```powershell
Get-Content sql\add_payment_references.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\add_payment_references.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-Content sql\verify_schema.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
git diff --check
```

Verifikasi schema mengembalikan `OK` untuk tabel, kolom, index unik, dan FK cascade relasi pembayaran. Uji HTTP/database terisolasi juga membuktikan pembuatan relasi, edit/hapus selektif pada dua pembayaran bertanggal sama, pelestarian setoran manual, penolakan saldo negatif, dan penolakan pembayaran legacy. Semua data uji dibersihkan kembali hingga jumlah transaksi kembali nol.
