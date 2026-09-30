# Audit alur operasional SistemSPP — 30 September 2026

## Kesimpulan

Alur inti pendaftaran, penerbitan tagihan, pembayaran, dan kenaikan kelas lolos pengujian terpisah pada salinan database. **Alur satu siswa dari pendaftaran sampai kelulusan belum diuji sebagai satu rangkaian penuh.** Sistem belum dapat dinyatakan lancar untuk seluruh siklus tahun ajaran: setelah siswa dinaikkan, beberapa rekap tahun asal salah mengelompokkan status siswa atau memakai kelas terbaru untuk tagihan lama.

Audit memakai dump lokal `db_spp` dan dua database uji terpisah. Database sumber dibaca saja; saat audit dimulai dan berakhir, jumlahnya tetap 222 siswa dan 1.036 pembayaran. Data uji dan hasil eksperimen tidak dimasukkan ke database sumber. Hasil di bawah berlaku untuk kode dan data lokal saat audit, bukan bukti semua kombinasi data sekolah telah teruji.

## Alur yang diperiksa

| Tahap | Hasil | Bukti dan batasnya |
| --- | --- | --- |
| Data Siswa dan PSB | Lulus untuk validasi dan skenario HTTP PSB | Tes integrasi memeriksa input, tarif/penanda PSB, cicilan, dan penolakan field lama. Pendaftaran siswa reguler diperiksa dari alur kode, belum diuji sebagai satu rangkaian HTTP sampai laporan. |
| Penerbitan SPP dan tagihan tahun ajaran | Lulus pada tes transaksi yang dibatalkan | Penempatan tahun terbit, sejarah kelas, penerbitan, dan isolasi unit diuji. SPP perlu diterbitkan terpisah setelah siswa masuk; membuat siswa saja tidak otomatis menerbitkan 12 tagihan SPP. |
| Pembayaran SPP, Komite, dan Daftar Ulang | Sebagian lulus | Tes transaksi untuk komponen terpisah lulus. Tes HTTP Daftar Ulang lulus untuk tagihan lama, gabungan dengan SPP/Komite, validasi pilihan, edit, dan struk. Tes HTTP SPP yang lebih luas berhenti pada pemeriksaan pesan penolakan bulan yang melompati tunggakan; transaksi memang ditolak, tetapi pesannya terlalu umum. Kasus sesudah titik itu belum diverifikasi oleh skrip tersebut. |
| Dropdown Daftar Ulang | Lulus uji tampilan statis | Dropdown tampil pada input dan edit tanpa tunggakan; tanda `!` mengikuti tunggakan tahun lampau. Uji browser menggunakan fixture, bukan seluruh interaksi dengan data produksi. |
| Kenaikan kelas dan kelulusan | Lulus pada tes transaksi yang dibatalkan | Urutan kelas 6 lulus lalu dua siswa kelas 5 dinaikkan lewat proses terpisah, pengiriman ulang, histori, penerbitan tahun tujuan, dan isolasi unit lolos. |
| Rekap, tagihan, surat, dan kas | Sebagian lulus | Tes modul laporan, riwayat tagihan, dan surat tunggakan kepala sekolah lulus; pada snapshot audit, surat menemukan 51 surat aktif dengan total tunggakan Rp83.870.000. Simulasi kenaikan mengungkap kesalahan rekap historis di bawah. |

Dua pengujian lintas unit berbasis fixture berhenti karena asumsi data awal tidak cocok dengan database hasil impor: contoh tabungan SD yang diharapkan tidak lengkap dan jumlah pembayaran awal SMP/SMA sudah lebih besar dari angka yang diasumsikan skrip. Kegagalan ini belum membuktikan bug isolasi unit. Tes khusus isolasi unit dan kenaikan kelas lulus. Enam smoke test browser untuk riwayat kelas, pembayaran, edit, penguncian komponen, dropdown Daftar Ulang, dan rekap kas lulus.

Tes yang lulus mencakup `student_psb_integration_test.php`, `du_payment_integration_test.php`, `class_promotion_sequence_test.php`, `class_promotion_multiunit_test.php`, `class_graduation_history_test.php`, `academic_year_history_publish_test.php`, `academic_year_billing_test.php`, `modular_reports_test.php`, `billing_history_report_integration_test.php`, dan `report_letters_test.php`. `payment_process_integration_test.php` berhenti pada pesan penolakan SPP; `demo_multiunit_reports_test.php` dan `multiunit_isolation_test.php` berhenti pada asumsi fixture di atas.

## Temuan yang perlu ditangani

### 1. Rekap historis keliru setelah kenaikan kelas — prioritas tinggi

Dalam transaksi uji yang kemudian dibatalkan, seorang siswa kelas 5A pada tahun asal dinaikkan ke 6A pada tahun tujuan; siswa tetap aktif. Sebelum kenaikan, rekap **Status Pembayaran SPP** bulan September menampilkan tagihan Rp305.000 dan rekap **SPP Tahun Ajaran** menampilkan Rp3.660.000 pada kelas 5A. Sesudah kenaikan, kedua rekap itu tidak lagi menampilkan siswa pada filter **Aktif**, tetapi memasukkannya ke **Arsip/Lulus**. Filter **Semua** masih menyimpan kelas historis 5A. Rekap **Per Item SPP** untuk September tahun asal masih memuat Rp305.000, namun menandainya sebagai kelas 6A.

Penyebabnya berbeda tetapi berhubungan: proses kenaikan memberi status `pindah` pada penempatan tahun asal (`includes/kelas.php`), sementara `report_student_status_where()` di `includes/reports.php` mengartikan penempatan `pindah` sebagai siswa **Arsip/Lulus**. Rekap Per Item bulanan mulai dari kelas aktif di tabel `siswa`, bukan penempatan/tagihan untuk periode yang ditampilkan. Filter **Aktif** pada laporan tahunan Daftar Ulang dan Komite memakai fungsi status yang sama, sehingga berisiko serupa; itu kesimpulan dari kode, belum reproduksi terpisah. Ekspor dan PDF yang memakai hasil rekap tersebut juga perlu diperiksa saat perbaikan.

Untuk pemeriksaan sementara, pilih **Semua** pada rekap tahun asal dan cocokkan kelas serta nominal dengan **Riwayat Tagihan Siswa**. Jangan jadikan pengelompokan kelas pada Per Item periode lampau sebagai dasar keputusan sampai diperbaiki.

### 2. Jalur kompatibilitas SPP melupakan penempatan lama — prioritas menengah

Simulasi yang dibatalkan menunjukkan `spp_payment_status()` menghitung 12 periode tahun asal sebelum kenaikan, tetapi nol setelah status penempatan asal menjadi `pindah`. Tarif lama Rp250.000 juga jatuh ke tarif siswa sekarang Rp300.000. Penyebabnya `spp_active_placements()` hanya membaca penempatan berstatus `aktif` (`includes/spp_payment_status.php` dan `includes/spp_sequence.php`).

**Batas temuan:** instalasi saat ini memakai tagihan SPP terbit. Endpoint status dan alokasi pembayaran aktif membaca tagihan terbit lintas tahun di `includes/spp_billing.php`, tanpa menyaring penempatan lama berstatus `aktif`. Karena itu eksperimen ini **belum membuktikan** tunggakan dapat dilewati pada jalur pembayaran yang sedang dipakai. Jalur kompatibilitas tetap perlu diperbaiki atau dipensiunkan dengan jelas.

### 3. Pesan penolakan SPP terlalu umum — prioritas menengah

Pada tes HTTP, kiriman pembayaran Agustus ketika Juli belum lunas ditolak oleh `spp_allocate_payment()` sesuai aturan. `pembayaran/proses.php` kemudian mengganti alasan spesifik (“Lunasi dahulu SPP Juli ...”) dengan pesan umum “Nominal atau status tagihan berubah ...”. Kasir tidak mendapat petunjuk periode yang harus dilunasi. Skrip uji HTTP yang memeriksa pesan spesifik berhenti di sini, sehingga rangkaian edit, titipan, dan struk di bagian lanjutannya belum berjalan dalam tes tersebut.

## Urutan pemeriksaan manual yang disarankan

Gunakan **database latihan/salinan** dan satu siswa uji dengan NIS unik. Catat jumlah siswa, tagihan, dan pembayaran sebelum serta sesudah setiap tahap.

1. Pilih unit yang tepat. Siapkan **Master Kelas/Rombel** dan tahun ajaran aktif; tentukan tarif di **Master Penerbitan SPP** serta **Master Daftar Ulang** sebelum menagih. Periksa tahun dan kelas sasaran pada pratinjau penerbitan.
2. Tambah siswa reguler di **Data Siswa**. Periksa NIS, kelas aktif, dan riwayat penempatan pada tahun berjalan. Untuk jalur PSB, isi kewajiban PSB, selesaikan perpindahan ke kelas reguler sesuai prosedur, lalu periksa penempatannya. Bila tahun ajaran aktif belum tersedia, penempatan/tagihan tidak terbentuk; bila ada histori tahun lalu tanpa penempatan tahun ini, jalankan kenaikan dahulu.
3. Terbitkan SPP untuk siswa itu di **Master Penerbitan SPP**. Cocokkan 12 bulan Juli–Juni dan tarif dari penempatan yang benar. Periksa tagihan Komite dan Daftar Ulang sesuai master; jangan menganggap input siswa otomatis menerbitkan SPP.
4. Di **Input Pembayaran**, pilih siswa dan periode tagihan. Bayar Juli SPP bersama Komite bulan itu; coba Agustus sebelum Juli pada siswa uji lain untuk memastikan ditolak. Uji Daftar Ulang lunas/cicilan, dropdown tanpa tunggakan, dan tanda `!` bila ada tunggakan tahun sebelumnya. Cocokkan nominal di struk, Riwayat Pembayaran, Riwayat Daftar Ulang, dan Riwayat Tagihan Siswa.
5. Cocokkan total transaksi dengan **Penerimaan Harian**, **Rekap Kas**, **Status Pembayaran**, **SPP Tahun Ajaran**, dan surat tunggakan. Bedakan tanggal uang diterima dari bulan/tahun tagihan yang dilunasi.
6. Saat berganti tahun, buka **Master Kelas/Rombel → Proses Tahun Ajaran**. Luluskan kelas tertinggi dahulu, lalu naikkan tingkat di bawahnya secara berurutan. Naikkan dua siswa satu per satu dan pastikan tahap tetap pada kelas asal sampai seluruh siswa asal diproses. Periksa satu penempatan tujuan per siswa serta kelas aktif yang langsung berubah.
7. Terbitkan tagihan tahun tujuan hanya setelah penempatan tujuan lengkap. Ulangi rekap tahun asal dan tahun tujuan. Saat ini, terapkan catatan temuan nomor 1 untuk laporan tahun asal setelah kenaikan.

## Batas pengujian

Tes transaksi database dibatalkan dengan `ROLLBACK`; tes HTTP berjalan pada database disposable yang dihapus setelah audit. Audit tidak mencakup pemakaian bersama oleh beberapa kasir secara serentak, seluruh kombinasi potongan/tarif, pencetakan fisik, atau satu perjalanan siswa penuh dari pendaftaran hingga kelulusan. Hasil tes yang gagal karena fixture atau pesan harus dibedakan dari penolakan transaksi bisnis yang benar.
