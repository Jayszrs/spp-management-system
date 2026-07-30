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
| SEC-001 | CSRF, SQL injection, dan XSS lain masih belum ditangani menyeluruh. | Tinggi | Terbuka | Di luar ruang lingkup perbaikan integritas pembayaran ini; lihat `PROJECT_CONTEXT.md`. |

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
