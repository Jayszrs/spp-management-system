# Audit kelayakan impor legacy SD, SMP, dan SMA

## Status akhir, 3 Oktober 2026

**Audit isi backup dan simulasi yang memungkinkan selesai. Impor penuh ke database utama belum layak.** Ketiga backup berhasil dipulihkan dan diperiksa integritasnya. Rekomendasi: **impor identitas siswa secara bertahap setelah penyelesaian benturan identitas dan status**, serta **tunda histori tagihan/pembayaran/penempatan**. Sampel Tabungan terbukti dapat dikonversi, tetapi keseluruhan rekening belum layak dipindahkan sekaligus.

Kode acuan: `de10aefab23ce1ae93a15b5d910992de14b0f6b1` pada `main`. Tidak ada perubahan API, skema aplikasi, UI, atau data utama. Audit ini bukan importer produksi. Database latihan dan instance audit telah dihapus; uninstall runtime LocalDB masih membutuhkan administrator Windows (bagian 9).

| Hasil utama | SD | SMP | SMA | Total |
|---|---:|---:|---:|---:|
| Baris kandidat identitas `dbo.siswa` | 369 | 787 | 131 | 1.287 |
| Lolos validasi struktur awal | 139 | 250 | 22 | 411 |
| Memiliki satu atau lebih masalah validasi | 230 | 537 | 109 | 876 |
| Status aktif/lulus belum eksplisit | 369 | 787 | 131 | 1.287 |
| Baris pembayaran `dbo.bayar` | 8.876 | 22.175 | 1.972 | 33.023 |
| Rekening Tabungan | 427 | 537 | 256 | 1.220 |
| Rekening dengan selisih saldo terhadap jurnal | 3 | 13 | 2 | 18 |

**411 bukan jumlah siswa siap operasional.** Angka ini hanya lolos format identitas, kelas yang dikenali, benturan NIS, dan pemeriksaan numerik terpilih atas nilai nonkosong. Tarif/potongan NULL belum dibuktikan bernilai nol. Status siswa, tahun penempatan pertama, arti komponen tarif, serta keputusan pemilik data masih diperlukan. `dbo.siswa` juga memuat jenis akun selain siswa.

## 1. Sumber, metadata, dan restore

Backup asli: `C:\Users\lakch\Downloads`. Salinan kerja dan bukti rinci: `C:\laragon\backups\spp-management-system\legacy_import_audit_20261003`, di luar repository. Hash sumber/salinan cocok sebelum dan sesudah; file asli tidak diubah.

| Unit | Backup / database sumber | Ukuran byte | Selesai backup menurut header | SHA-256 |
|---|---|---:|---|---|
| SD | `SD-5.dat` / `sdit_mh` | 24.794.624 | 2019-02-15 11:36:42 | `1ED886D4834BCD80FC9696E61E487632A1B53E2E746C79330E22540577C086BD` |
| SMP | `SMP-5.dat` / `smpit_mh` | 28.966.400 | 2019-02-15 11:36:50 | `8818AF19481E8EF10488D43CAE616040708018517D1E45E06DE56D408C30880B` |
| SMA | `SMA-5.dat` / `smait_mh` | 15.946.240 | 2019-02-15 11:36:45 | `A115145F41989873D2701BD29623271F3D4F1AFFA63D17989064AEB606B3DFA9` |

Waktu header tidak memuat zona waktu yang dapat dipastikan. Backup adalah snapshot **Februari 2019**, bukan data operasional 2026. Tanggal unduhan bukan tanggal backup.

- `HEADERONLY`: satu full database backup per file, posisi 1; SQL Server 2008 R2 SP2 `10.50.4000`, database version 661, recovery `SIMPLE`, `IsDamaged=false`.
- `HasBackupChecksums=false`: backup tidak memiliki checksum bawaan; tidak disamakan dengan kegagalan integritas.
- `FILELISTONLY`: dua file per unit. SD/SMA: `sdit_tbz_Data` / `sdit_tbz_Log`; SMP: `smp` / `smp_log`. Restore berhasil tanpa password/kunci tambahan atau backup pendamping.
- Engine: LocalDB 2019 `15.0.2000.5`, instance khusus `SppLegacyAudit_20261003`, terpisah dari default. Tidak diperlukan engine perantara.
- `VERIFYONLY` dan restore ketiganya berhasil. Tujuan: `legacy_audit_sd_20261003`, `legacy_audit_smp_20261003`, `legacy_audit_sma_20261003`; `MOVE` memakai MDF/LDF baru dalam folder audit, bukan lokasi legacy.
- Compatibility level sumber SD/SMA 80, SMP 100; hasil restore 100. `DBCC CHECKDB` awal dan akhir: nol error pada ketiganya.
- Flag `READ_ONLY` diperiksa sebelum ekstraksi. Tidak menjalankan prosedur/job/kode legacy. Seluruh 56 tabel SD, 52 SMP, dan 50 SMA diinventarisasi beserta kolom, tipe, indeks, dan jumlah. Tidak ditemukan foreign key, procedure, view, trigger, atau function pengguna pada katalog sumber.
- Dua puluh tabel kandidat per unit diprofil dari ekstraksi. Ekstraksi ulang 60 berkas: **nol perbedaan jumlah maupun SHA-256**. Tabel arsip/pendukung lain diperiksa melalui katalog/struktur, belum diakui sebagai sumber transaksi tambahan yang sah.

Rujukan: [SQL Server 2019 supported upgrades](https://learn.microsoft.com/en-us/sql/database-engine/install-windows/supported-version-and-edition-upgrades-2019), [RESTORE HEADERONLY](https://learn.microsoft.com/en-us/sql/t-sql/statements/restore-statements-headeronly-transact-sql), [RESTORE VERIFYONLY](https://learn.microsoft.com/en-us/sql/t-sql/statements/restore-statements-verifyonly-transact-sql), dan [LocalDB](https://learn.microsoft.com/en-us/sql/database-engine/configure-windows/sql-server-express-localdb). Kompatibilitas pada audit ini dibuktikan oleh restore aktual.

## 2. Arti keputusan kelayakan

- **Dapat dipetakan:** data/relasi yang diperiksa cukup untuk tujuan tertentu; tidak berarti semua baris kategori diterima.
- **Perlu konversi:** struktur/representasi berbeda; ada subset yang terbukti dapat dipindahkan setelah validasi.
- **Belum dapat diputuskan:** arti bisnis, relasi, atau bukti belum cukup; prasyaratnya disebutkan.
- **Tidak dapat diimpor pada skema sekarang:** benturan atau tipe entitas tidak didukung target; memerlukan keputusan desain/pemisahan.

Semua 1.287 baris kandidat disimpan pada staging, termasuk baris ditolak, raw data, hash, dan daftar alasannya. Hitungan alasan dapat tumpang tindih; tidak ada perbaikan nomor atau penghilangan baris diam-diam.

## 3. Tabel kelayakan per unit

### SD

| Data/tabel sumber | Jumlah baris | Tujuan SistemSPP | Kelayakan | Kekurangan | Penyesuaian dan rekomendasi |
|---|---:|---|---|---|---|
| `siswa`, identitas/kelas saat backup | 369; 139 lolos struktur | `siswa_data`, master rombel SD | Perlu konversi | 161 terkena NIS lintas unit; 124 kode kelas/akun belum dipetakan; status tidak eksplisit | Trim padding, pertahankan nol NIS, selesaikan konflik/status |
| `siswa`, tarif/potongan | 369 | Tarif siswa terpilih | Perlu konversi | Keadaan saat backup saja; 1 potongan Pangkal melampaui tarif; arti POMG/PSB belum disahkan | Angka terpilih berhasil pada sampel tanpa tagihan; sahkan komponen dan masa berlaku |
| `kelas` / `kls` | 10 / 312 | Master rombel SD | Belum dapat diputuskan | Ada master jenjang SMP; bukan daftar rombel bersih | Validasi kode siswa/batas SD; jangan menyalin seluruh master |
| Penempatan historis | Tidak ada tabel eksplisit ditemukan | Penempatan per tahun | Belum dapat diputuskan | Kelas pembayaran berbeda dalam satu siswa/tahun; tahun masuk tidak tersedia | Minta penempatan asli; jangan menebak kelas sebelumnya |
| `Daftar_ulang` | 21 | Tarif DU per tahun/kelas | Belum dapat diputuskan | 8 tarif di luar jenjang SD | Sahkan unit/jenjang dan penempatan sebelum penerbitan |
| `bayar` | 8.876 | Header/alokasi pembayaran | Belum dapat diputuskan | 111 tanpa kandidat siswa; snapshot kewajiban/tarif belum lengkap; komponen berbeda | Rekonsiliasi periode, komponen, histori, koreksi, dan kas |
| `bayar_du` | 1.067 | Pembayaran DU | Belum dapat diputuskan | 538 tanpa kandidat siswa; 402 kelas luar SD; 3 nilai negatif; tidak ada header terbukti | Jangan menyalin ID sebagai `bayar_id` atau membuat tanggal/header fiktif |
| `lain` / `u_lain` | 50 / 32 | Biaya Lain | Belum dapat diputuskan | Kode master tidak unik; nama bukan bukti tarif/kewajiban | Tetapkan komponen dan relasi; jangan gandakan komponen header |
| `tabungan` / `transaksi_m` / `transaksi_k` | 427 / 21.247 / 1.280 | Rekening/jurnal Tabungan | Perlu konversi | 142 rekening tanpa kandidat siswa; 3 selisih saldo; jenis akun belum semua sah | Satu rekening/jurnal lengkap terbukti; tunda rekening bermasalah |
| `U_TABUNGAN` / `komitment` / `komitment_manual` | 68 / 3 / 2 | Belum ditetapkan | Belum dapat diputuskan | Belum jelas tambahan, komitmen, atau duplikasi pencatatan | Bukan penerimaan/saldo/tagihan otomatis |
| `temp_belum_spp` / `rekap_belum_spp` / `SPP_BELUM` | 600 / 49 / 0 | Bukti pembantu tunggakan | Belum dapat diputuskan | Tidak membuktikan kewajiban/snapshot lengkap | Rekonsiliasi cache; jangan terbitkan tunggakan dari cache saja |

### SMP

| Data/tabel sumber | Jumlah baris | Tujuan SistemSPP | Kelayakan | Kekurangan | Penyesuaian dan rekomendasi |
|---|---:|---|---|---|---|
| `siswa`, identitas/kelas saat backup | 787; 250 lolos struktur | `siswa_data`, master rombel SMP | Perlu konversi | 136 terkena NIS lintas unit; 451 kode belum dipetakan; 113 NIS Diknas invalid; status tidak eksplisit | Karantina per alasan; jangan koreksi nomor otomatis |
| `siswa`, tarif/potongan | 787 | Tarif siswa terpilih | Perlu konversi | 4 potongan Pangkal dan 2 potongan DU melebihi tarif; masa berlaku belum ada | Sahkan arti komponen; nominal sekarang bukan tarif semua tahun |
| `kelas` / `kls` | 10 / 312 | Master rombel SMP | Belum dapat diputuskan | Memuat akun/kode selain rombel pendidikan | Sampel kelas 7–9/PSB berhasil; master keseluruhan belum disahkan |
| Penempatan historis | Tidak ada tabel eksplisit ditemukan | Penempatan per tahun | Belum dapat diputuskan | 432 kelompok siswa/tahun mempunyai beberapa kelas pembayaran | Minta kelas/tahun asal yang sah |
| `Daftar_ulang` | 17 | Tarif DU per tahun/kelas | Belum dapat diputuskan | 2 tarif di luar SMP | Cocokkan jenjang, periode, dan penempatan |
| `bayar` | 22.175 | Header/alokasi pembayaran | Belum dapat diputuskan | 34 tanpa kandidat siswa; **tidak ada kolom ID transaksi**; tagihan/snapshot belum cukup | Staging/manifest sumber stabil diperlukan; jangan buat nomor struk legacy palsu |
| `bayar_du` | 1.317 | Pembayaran DU | Belum dapat diputuskan | 81 tanpa siswa; 67 kelas luar SMP; 4 nilai negatif; ID/header/tanggal tidak cukup | Minta relasi dan koreksi; jangan gabung berdasarkan nominal |
| `lain` / `u_lain` | 48 / 48 | Biaya Lain | Belum dapat diputuskan | Kode berulang; alokasi ke header belum terbukti | Petakan arti, rate, dan relasi |
| `tabungan` / `transaksi_m` / `transaksi_k` | 537 / 13.534 / 2.606 | Rekening/jurnal Tabungan | Perlu konversi | 24 rekening tanpa siswa; 13 selisih; presisi saldo melebihi dua desimal | Satu rekening lengkap berhasil; sahkan presisi/selisih per rekening |
| `U_TABUNGAN` / `komitment` / `komitment_manual` | 36 / 15 / 3 | Belum ditetapkan | Belum dapat diputuskan | Komitmen bukan bukti kas diterima | Tidak diimpor sebagai pembayaran/Titipan SPP |
| `temp_belum_spp` / `rekap_belum_spp` / `SPP_BELUM` | 1.039 / 89 / 685 | Bukti pembantu tunggakan | Belum dapat diputuskan | Tidak membuktikan kewajiban/kelas historis | Minta tarif/kewajiban asli per periode |

### SMA

| Data/tabel sumber | Jumlah baris | Tujuan SistemSPP | Kelayakan | Kekurangan | Penyesuaian dan rekomendasi |
|---|---:|---|---|---|---|
| `siswa`, identitas/kelas saat backup | 131; 22 lolos struktur | `siswa_data`, master rombel SMA | Perlu konversi | 75 terkena NIS lintas unit; 55 kode belum dipetakan; status tidak eksplisit | Pisahkan akun non-siswa, validasi kelas 10–12/PSB/status |
| `siswa`, tarif/potongan | 131 | Tarif siswa terpilih | Perlu konversi | 8 potongan Pangkal melampaui tarif; masa berlaku tidak ada | Sampel numerik terbukti; bukan bukti kewajiban historis |
| `kelas` / `kls` | 10 / 312 | Master rombel SMA | Belum dapat diputuskan | Master tersalin memuat jenjang SMP | Pakai kode siswa yang terverifikasi, jangan copy lintas jenjang |
| Penempatan historis | Tidak ada tabel eksplisit ditemukan | Penempatan per tahun | Belum dapat diputuskan | 41 kelompok siswa/tahun mempunyai kelas pembayaran berbeda | Minta penempatan asli; jangan tebak tahun pertama |
| `Daftar_ulang` | 17 | Tarif DU per tahun/kelas | Belum dapat diputuskan | 10 tarif di luar SMA | Sahkan pemilik tarif, tahun, dan kelas |
| `bayar` | 1.972 | Header/alokasi pembayaran | Belum dapat diputuskan | 7 tanpa siswa; tagihan/snapshot belum lengkap | Pisahkan periode dan tanggal penerimaan; rekonsiliasi komponen |
| `bayar_du` | 731 | Pembayaran DU | Belum dapat diputuskan | 612 tanpa siswa; 470 kelas luar SMA; 3 negatif; tidak ada ID header cocok | Jangan membuat penerimaan/header atau menggabungkan ID tanpa relasi |
| `lain` / `u_lain` | 43 / 16 | Biaya Lain | Belum dapat diputuskan | Kode berulang; arti/rate/alokasi belum disahkan | Konversi setelah kamus dan rekonsiliasi relasi |
| `tabungan` / `transaksi_m` / `transaksi_k` | 256 / 6.046 / 959 | Rekening/jurnal Tabungan | Perlu konversi | 198 rekening tanpa siswa; 2 selisih; 1 NIS mempunyai 2 rekening | Satu rekening lengkap terbukti; jangan gabung rekening ganda otomatis |
| `U_TABUNGAN` / `komitment` / `komitment_manual` | 1 / 1 / 1 | Belum ditetapkan | Belum dapat diputuskan | Belum terbukti sebagai kas/tagihan independen | Cek arti bisnis dan duplikasi dahulu |
| `temp_belum_spp` / `rekap_belum_spp` / `SPP_BELUM` | 318 / 25 / 25 | Bukti pembantu tunggakan | Belum dapat diputuskan | Penempatan/kewajiban historis belum lengkap | Tidak membuat tagihan dari cache saja |

### Benturan yang tidak dapat dimasukkan langsung

| Data berbenturan | Hasil | Keputusan dan syarat |
|---|---|---|
| NIS sama di beberapa unit | 167 NIS, 372 baris | **Tidak dapat diimpor pada skema sekarang** sebagai siswa berbeda dengan NIS tetap: target unik global. Memerlukan keputusan pemilik/desain; tidak menambah prefix, menghapus nol, atau menggabungkan otomatis |
| Akun guru/karyawan dan non-siswa | `GK` pada master berarti Guru & Karyawan; ada Admin/Guru/Tab dan kode lain | **Tidak dapat diimpor pada skema sekarang** sebagai rekening siswa bila entitasnya bukan siswa. Pisahkan; kode ambigu tetap belum dapat diputuskan |
| Akun/login, kontak orang tua, template/cetakan | Tabel pendukung pada lampiran | Tidak disalin ke struktur siswa/transaksi. Tidak copy kredensial/role legacy; kontak memerlukan pemetaan/desain terpisah |

## 4. Identitas dan histori: batas yang terbukti

- Duplicate NIS dalam masing-masing `dbo.siswa`: nol. Lintas unit: 167 nomor / 372 baris; 38 nomor mempunyai nama sama setelah normalisasi, **129 nama berbeda**. Nama sama bukan bukti boleh digabung.
- NIS bukan 1–10 digit: SD 2, SMP 2, SMA 0. NIS Diknas nonkosong bukan 10 digit: 6, 113, 1. SMP mempunyai satu nilai Diknas berulang pada tiga baris. Nomor tetap teks dan nol awal dipertahankan.
- Seluruh 1.287 baris memerlukan normalisasi whitespace pada setidaknya satu kolom, termasuk padding `CHAR`; tidak mengubah angka identitas.
- ID siswa sumber SMP: satu ID berulang pada dua baris; SD/SMA nol. ID tidak digunakan sendirian sebagai kunci importer. Sampel memakai unit+tabel+ordinal ekstraksi tetap dan hash; produksi harus mempertahankan staging/manifest stabil.
- Tidak ada NIS sumber yang berbenturan dengan 222 siswa database utama saat audit. Ini perbandingan baseline, bukan izin menggabungkan ke utama.
- Kelas reguler: SD 207, SMP 248, SMA 62 baris; literal PSB: 38, 88, 14. Kode belum dipetakan: 124, 451, 55. Tidak ditemukan kelas 6 SD pada kandidat `dbo.siswa`; tidak membuat sampel kelas 6 fiktif.
- `Out`, `X...`, kelas kosong, dan variasi lain tidak otomatis diberi status lulus/arsip. Tidak ada kolom lifecycle eksplisit. Kelas saat backup tidak membuktikan kelas tahun pembayaran atau kondisi 2026.
- Tidak ditemukan histori penempatan siswa/tahun yang memenuhi relasi target. `bayar.KELAS`, `th_ajaran`, `kelas_du` hanya bukti pembantu, bukan riwayat lengkap.
- Tabel arsip banyak mempunyai jumlah sama antarunit. `siswa072015` justru berskema pembayaran. Nama bukan jaminan isi/keaktifan. Tidak union arsip dengan kandidat utama atau menambah penerimaan darinya.

## 5. Keuangan: mengapa migrasi penuh ditunda

| Ukuran sumber | SD | SMP | SMA |
|---|---:|---:|---:|
| Rentang tanggal `bayar` | 2012-01-26–2019-02-15 | 2011-01-31–2019-02-15 | 2015-01-31–2019-02-15 |
| Baris `U_SPP > 0` | 5.744 | 14.601 | 1.375 |
| Jumlah `U_SPP`, belum penerimaan target | Rp1.840.310.500 | Rp5.107.911.000 | Rp503.500.000 |
| `bayar.th_ajaran` kosong | 7.299 | 19.583 | 1.694 |
| Kelompok siswa/bulan mempunyai beberapa baris SPP | 4 / 8 baris | 5 / 11 | 1 / 2 |
| Kelompok siswa/tahun mempunyai beberapa kelas pembayaran | 157 dari 966 | 432 dari 2.117 | 41 dari 266 |
| SPP dibayar berbeda dari tarif siswa saat backup | 3.040 | 7.732 | 526 |
| DU tahun kosong | 156 | 166 | 142 |
| DU negatif | 3 | 4 | 3 |

Bulan/tahun kalender pada SPP positif memenuhi format yang diperiksa; itu tidak membuktikan kewajiban, kelas, atau tarif snapshot. Pengelompokan siswa/tahun dalam tabel ini dihitung dari bulan/tahun kalender dengan batas Juli–Juni untuk mendeteksi ambiguitas, bukan bukti penempatan atau tahun ajaran sumber yang kosong. Perbedaan nominal mungkin tarif lama, potongan, cicilan, atau koreksi; tidak dipilih salah satunya tanpa bukti. Beberapa baris periode sama bukan otomatis duplikat.

`bayar` memuat `U_BANGUNAN`, `U_SERAGAM`, `U_KEGIATAN`, `U_MAKAN`, `U_SORGA`, `U_INFAQ`, `JUMLAH1..4`, `LAIN_LAIN1..4`, `tabungan_wajib` yang tidak bisa disalin langsung ke header target. **`U_SORGA` tidak otomatis Komite dan `U_KEGIATAN` tidak otomatis DU.** Angka POMG sampel juga bukan pengesahan semantik Komite. Diperlukan kamus komponen, aturan potongan/koreksi, dan relasi alokasi. Transaksi beberapa bulan sekaligus belum dibuktikan cocok tanpa pemecahan header atau penggandaan penerimaan.

**`siswa.DAFTAR_ULANG` NULL pada seluruh 1.287 baris**: SD 369, SMP 787, SMA 131. Tarif DU tidak dapat dianggap nol hanya karena kolom ini kosong; master tahunan `Daftar_ulang`, `tot_du`, dan potongan harus dijelaskan serta direkonsiliasi. SMP juga memiliki satu baris NULL pada masing-masing `PANGKAL`, `potong_pangkal`, dan `potong_du`. Field `PANGKAL_BAYAR`, `tot_pangkal`, `tot_du` bukan otomatis header penerimaan atau tarif bruto; tidak disalin sebagai pembayaran. Daftar NULL terpilih dan alasan konversinya dicatat pada bukti follow-up finansial luar repository.

`bayar_du` tidak mempunyai tanggal/header yang cukup. Tidak ada ID DU SD/SMA ditemukan sebagai ID `bayar`; SMP tidak memiliki ID pada kedua tabel. Tidak dibuat tanggal, header, atau struk fiktif. Master DU mencakup 2012/2013–2018/2019 tetapi bercampur kelas luar jenjang; tidak diterbitkan otomatis.

Relasi di bawah dihitung terhadap kandidat `dbo.siswa`, bukan seluruh arsip. Kemungkinan identitas ada di arsip belum menjadi pemetaan sah:

| NIS tidak ditemukan pada kandidat siswa | SD | SMP | SMA |
|---|---:|---:|---:|
| `bayar` | 111 | 34 | 7 |
| `bayar_du` | 538 | 81 | 612 |
| `tabungan` | 142 | 24 | 198 |
| `transaksi_m` | 2.970 | 812 | 4.240 |
| `transaksi_k` | 490 | 37 | 719 |

Tidak ditemukan tabel pembatalan eksplisit yang menghubungkan tindakan ke header sumber. Nilai negatif/catatan koreksi belum cukup untuk memilih hapus/refund/reversal. Cache tunggakan, komitmen, dan arsip bertanggal dipertahankan sebagai bukti, bukan tagihan/penerimaan baru.

Aturan target tetap: SPP langsung satu bulan tepat sisa tagihan, urutan tunggakan, pasangan SPP–Komite, penempatan resmi sebelum tagihan tahun tujuan. **Titipan SPP tidak dikembalikan.** Tidak ada sampel migrasi tagihan/pembayaran SPP/DU/Komite yang dinyatakan lulus: histori/relasinya belum memenuhi prasyarat.

## 6. Tabungan dan pembuktian sampel

| Ukuran sumber | SD | SMP | SMA |
|---|---:|---:|---:|
| Jumlah saldo rekening | Rp249.638.300,50 | Sekitar Rp178.883.800,13 | Rp85.720.300 |
| Saldo cocok jurnal, toleransi Rp0,01 | 424 | 524 | 254 |
| Saldo tidak cocok | 3 | 13 | 2 |
| Saldo negatif | 0 | 0 | 0 |
| NIS rekening ganda | 0 | 0 | 1 nomor / 2 baris |

Rekonsiliasi awal menghitung jurnal bersih per NIS. Saldo cocok belum otomatis layak bila identitas/unit/status/jenis akun bermasalah. Selisih bertanda agregat SD −Rp318.000, SMP +Rp1.336.000, SMA −Rp344.000; bukan jumlah absolut kesalahan. SMP mempunyai pecahan lebih dari dua desimal; aturan pembulatan belum disahkan. Rekening ganda tidak dijumlahkan otomatis.

Dipilih satu rekening unik per unit, terkait identitas layak struktur, lengkap jurnal masuk/keluar, arah kolom normal, saldo akhir cocok, tidak negatif pada urutan tanggal yang diuji. Tanggal sama diurutkan masuk sebelum keluar untuk sampel; urutan waktu intrahari lebih rinci tidak terbukti dari sumber.

| Sampel disamarkan | Jurnal | Masuk | Keluar | Saldo akhir | Hasil |
|---|---:|---:|---:|---:|---|
| Siswa A / SD | 65 | Rp880.000 | Rp160.000 | Rp720.000 | Sumber, target, rekap, ekspor cocok |
| Siswa B / SMP | 45 | Rp1.145.000 | Rp1.140.000 | Rp5.000 | Sumber, target, rekap, ekspor cocok |
| Siswa C / SMA | 22 | Rp2.037.500 | Rp787.500 | Rp1.250.000 | Sumber, target, rekap, ekspor cocok |
| Gabungan | 132 | Rp4.062.500 | Rp2.087.500 | Rp1.975.000 | Sama dengan jumlah ketiga unit |

Saldo/jurnal sampel **dapat dipetakan** ke Tabungan melalui konversi terukur; tidak dibuat saldo pembuka fiktif atau header pembayaran sekolah. Hubungan `tabungan_wajib`/`U_TABUNGAN` dengan jurnal belum sah; jangan masukkan dua kali.

## 7. Simulasi dan batas kelulusannya

Target kosong `db_spp_audit_legacy_import_20261003`: skema terbaru, 32 tabel fisik, tiga unit, nol data lainnya. Guard CLI/nama persis/ownership/`SPP_TEST_ALLOW_MUTATION=1` sebelum penulisan; view/routine tidak menunjuk utama.

1. Staging semua 1.287 baris dengan raw JSON/hash/unit/alasan, lifecycle `unknown_no_status_column`. Dua tabel staging/map hanya pada disposable, bukan skema aplikasi.
2. **16 identitas unik**: setiap tingkat dengan data layak tercakup, SD 1–5/PSB, SMP 7–9/PSB, SMA 10–12/PSB, ditambah identitas rekening yang belum terpilih. Identitas/rombel dan nilai nonkosong enam kolom tarif/potongan terpilih cocok. Pada **16 sel sampel yang NULL**, fixture memakai nol untuk menyimpan sampel numerik tanpa tagihan; raw NULL tetap tersedia di staging. Ini keputusan fixture, **bukan** konversi finansial yang disahkan atau bukti NULL berarti tidak ada kewajiban/potongan. Tidak ada nilai nonkosong sampel yang berubah karena pembulatan dua desimal. Kelulusan ini tidak membuktikan semua tarif/histori.
3. `is_active=0` adalah karantina latihan, **bukan** kesimpulan status arsip/lulus sumber. Tidak membuat tahun/penempatan/tagihan; form tambah siswa tidak digunakan.
4. Tiga rekening, 132 jurnal: 123 masuk, 9 keluar, asal tabel/ordinal tercatat. Operator sumber tetap teks, tidak membuat login legacy.
5. Replay: nol siswa baru, 16 dilewati; fingerprint seluruh tabel latihan identik. Bukti berlaku untuk ekstraksi tetap, bukan perubahan urutan/backup baru.
6. Uji negatif rollback: NIS sama pada unit lain ditolak unique global; tingkat SD pada unit SMP ditolak guard kelas; jumlah data tetap.
7. `report_build` transaksi dan saldo Tabungan per unit/gabungan cocok. Endpoint laporan/Excel/PDF diuji melalui runner CLI dan sesi fixture Super Admin pada clone.
8. HTML, Excel HTML `.xls`, PDF biner: total masuk/keluar/bersih, identitas baris, dan isolasi unit cocok. SD 65, SMP 45, SMA 22; gabungan Excel/PDF 132, halaman pertama HTML 100 sesuai pagination. PDF 3/2/1/5 halaman; teks dibaca dari **PDF biner**. Halaman awal/akhir sampel terpilih dirender dan diperiksa. Tidak mengklaim tes klik browser/HTTP penuh.
9. Kelas laporan sampel adalah kelas saat backup/fallback tanpa penempatan dimigrasi. **Nominal cocok tidak membuktikan kelas historis tahun jurnal.** Rekap SPP/DU/Komite/struk legacy/kenaikan belum dapat diuji tanpa prasyarat histori.
10. Akhir latihan: 16 siswa, 3 rekening saldo Rp1.975.000, 132 jurnal; **nol pembayaran, penempatan, dan tagihan**. Seluruh 14 pemeriksaan integritas bersih. Data ini tidak masuk utama.

Kegagalan fixture/alat dipisahkan dari bug aplikasi: instalasi pertama perlu administrator; nesting array PowerShell sempat merusak argumen restore (diperbaiki sebelum restore sah); fixture kelas awal tidak mengatur konteks unit dan transaksi seluruhnya rollback. Pemeriksaan judul PDF awal peka huruf besar dan renderer tidak mendukung indeks negatif; diperbaiki dan diulang. Pada sebagian restart LocalDB, flag read-only perlu ditegaskan ulang; ekstraksi akhir mempertahankannya dalam proses yang sama dan memeriksa sebelum membaca; 60 hash tetap identik. Tidak ada bypass guard atau perubahan aplikasi untuk meluluskan tes.

## 8. Rekomendasi pekerjaan berikutnya

**Pilihan: impor siswa saja secara bertahap setelah validasi, dengan penundaan keuangan/histori. Belum persetujuan impor utama.**

1. Tentukan dataset latihan historis 2019 atau operasional. Operasional 2026 membutuhkan sumber mutakhir/cutoff sah; jangan memakai kelas/status 2019 sebagai keadaan sekarang.
2. Sahkan kandidat `dbo.siswa`; pisahkan guru/karyawan/akun lain, verifikasi `Out`, `X...`, kosong, PSB, dan status setiap identitas. Arsip hanya ditambah setelah asal/duplikasi terbukti.
3. Putuskan 167 NIS lintas unit. Skema sekarang mengarantina semuanya; jangan koreksi otomatis. Bila NIS menjadi unik per unit, itu desain lanjutan seluruh relasi berbasis NIS, bukan hanya melepas index unik.
4. Selesaikan identitas/discount bermasalah, pilih subset sah. Importer siswa memakai dry-run/staging stabil/map/audit penolakan/replay; tidak memakai form penerbitan otomatis. Aktivasi/penempatan pertama harus pada tahun/kelas yang diketahui.
5. Tarif dipakai setelah arti komponen/diskon/masa berlaku/pemisahan PSB–Pangkal–POMG sah. **Siswa+tarif+penempatan** belum direkomendasikan sebagai paket lengkap tanpa penempatan asli per tahun.
6. Tabungan tahap terpisah: setiap rekening harus sah/cocok jurnal; selesaikan presisi/duplikat/non-siswa, cek tabungan pada header agar kas tidak dihitung dua kali. Tiga sampel bukan izin copy 1.220 rekening.
7. Pembayaran/tagihan/DU/Komite ditunda sampai kamus komponen, penempatan/tarif/kewajiban, relasi DU/header, identitas transaksi SMP, aturan refund/koreksi tersedia. Evaluasi multi-periode tanpa memecah header atau menggandakan kas otomatis.
8. Importer produksi/penerapan utama pekerjaan baru setelah keputusan tersebut, dengan backup, disposable test, rekonsiliasi penuh, dan cakupan kategori yang disahkan.

## 9. Baseline, bukti, pembersihan

| Database utama `db_spp` | Sebelum | Sesudah simulasi dan cleanup | Hasil |
|---|---:|---:|---|
| Siswa | 222 | 222 | Sama |
| Pembayaran | 1.018 | 1.018 | Sama |
| Total penerimaan header | Rp577.145.000 | Rp577.145.000 | Sama |
| Rekening Tabungan | 12 | 12 | Sama |
| Saldo Tabungan | Rp1.050.000 | Rp1.050.000 | Sama |
| Fingerprint seluruh tabel fisik | 32 tabel | 32 tabel | Identik, termasuk rekening dan kedua jurnal |
| Pemeriksaan integritas | 14 bersih | 14 bersih | Nol temuan |

Bukti luar repository: `source_manifest.json`, header/filelist/CHECKDB/katalog per unit, `export_manifest*.json`, `source_recheck.json`, `student_staging.json`, `profile_summary.json`, `cross_unit_nis_private.json`, `detail_checks.json`, `sample_selection_private.json`, `simulation_results.json`, `rejection_checks.json`, `export_checks.json`, sampel pribadi HTML/XLS/PDF/PNG, fingerprint awal/akhir, `final_verification.json`. Data pribadi, backup, ekstraksi, sesi fixture, dan log rinci tidak di-commit.

- MySQL latihan dihapus setelah verifikasi nama/ownership/34 tabel (32 aplikasi + 2 staging)/jumlah sampel. Schema latihan tidak tersisa.
- Tiga database SQL audit dihapus, MDF/LDF miliknya tidak tersisa, instance `SppLegacyAudit_20261003` dihentikan/dihapus. `MSSQLLocalDB` hanya nama otomatis yang dilaporkan **belum dibuat**. Tidak menghapus milik pihak lain.
- Tidak membuat server HTTP; MySQL/Laragon yang sudah ada tidak dihentikan. Dependency rendering Python `pypdfium2`/`pillow` yang dipasang khusus audit sudah di-uninstall; rendering bukti disimpan.
- Backup asli, salinan audit, media resmi Microsoft, log, skrip, dan bukti sengaja dipertahankan untuk reproduksi. Cache installer/package tidak dibersihkan secara spekulatif.
- **Runtime LocalDB 2019 masih terpasang.** Uninstall resmi setelah inventaris bersih: exit **1603**, error **1730**, meminta administrator Windows. Tidak memaksa melalui jalur lain. Pemilik komputer dapat uninstall **Microsoft SQL Server 2019 LocalDB** dari Installed Apps/installer dengan administrator bila tidak diperlukan. Ini cleanup administratif tersisa, bukan instance audit berjalan atau penghalang keputusan kelayakan.

Tidak ada penghapusan ditolak peninjauan otomatis; penolakan berasal dari Windows Installer. Dokumen ini menggantikan status persiapan yang menunggu instalasi. Dokumen lama dan log persiapan tetap disimpan luar repository.

## Lampiran: inventaris seluruh tabel

Katalog aktual ketiga database. `—` berarti tabel tidak ada, bukan nol baris. Penilaian kandidat merujuk bagian 3–7. Arsip tidak dijumlahkan menjadi tambahan siswa/penerimaan; tabel lainnya belum diakui sebagai sumber operasional tambahan.

<!-- LEGACY_CATALOG_APPENDIX -->

| Tabel `dbo` | SD | SMP | SMA | Kelompok | Tujuan / kelayakan | Kekurangan dan rekomendasi |
|---|---:|---:|---:|---|---|---|
| `bayar` | 8.876 | 22.175 | 1.972 | Pembayaran | Header/alokasi; **Belum dapat diputuskan** | Histori, komponen, identitas sumber; bagian 5 |
| `BAYAR0716` | 2.432 | 11.730 | 264 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `BAYAR072014` | 4.970 | 4.970 | 4.970 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `bayar072015` | 8.438 | 8.438 | 8.438 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `bayar10082018` | 7.308 | — | — | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `bayar27072018` | 7.096 | — | — | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `bayar_back0810` | 7.494 | 7.494 | 7.494 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `bayar_du` | 1.067 | 1.317 | 731 | Pembayaran DU | Header/tagihan DU; **Belum dapat diputuskan** | Relasi/tanggal/koreksi; bagian 5 |
| `BAYAR_salah1` | 7.096 | — | — | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `bayarasli` | 8.257 | 8.257 | 8.257 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `byr022011` | 11.565 | 11.565 | 11.565 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `BYR0716` | 2.432 | 11.730 | — | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `cetakan` | 6 | 0 | 4 | Konfigurasi/cetakan/teknis | Tidak disalin otomatis; **Belum dapat diputuskan** | Validasi pemilik/config; bukan sumber tagihan |
| `Daftar_ulang` | 21 | 17 | 17 | Master tarif DU | Tarif DU per unit/tahun; **Belum dapat diputuskan** | Jenjang/penempatan; bagian 3 |
| `dtproperties` | 0 | — | 0 | Konfigurasi/cetakan/teknis | Tidak disalin otomatis; **Belum dapat diputuskan** | Validasi pemilik/config; bukan sumber tagihan |
| `jemputan` | 0 | 0 | 0 | Komponen legacy | Biaya Lain bila sah; **Belum dapat diputuskan** | Tidak ada baris sumber untuk diuji |
| `JK` | 49 | 49 | 49 | Referensi identitas | Staging pembantu; **Belum dapat diputuskan** | Asal/duplikasi/otoritas daftar belum disahkan |
| `kelas` | 10 | 10 | 10 | Master kode | Master rombel; **Belum dapat diputuskan** | Kode campuran; bukan copy seluruh master |
| `kepsek` | 1 | — | — | Konfigurasi/cetakan/teknis | Tidak disalin otomatis; **Belum dapat diputuskan** | Validasi pemilik/config; bukan sumber tagihan |
| `kls` | 312 | 312 | 312 | Referensi kelas/identitas | Staging pembantu; **Belum dapat diputuskan** | Bukan master rombel bersih yang disahkan |
| `komitment` | 3 | 15 | 1 | Komitmen | Belum ditetapkan; **Belum dapat diputuskan** | Komitmen bukan bukti penerimaan |
| `komitment_manual` | 2 | 3 | 1 | Komitmen | Belum ditetapkan; **Belum dapat diputuskan** | Arti/relasi belum sah; bukan Titipan SPP |
| `ktl` | 1 | 1 | 1 | Konfigurasi/cetakan/teknis | Tidak disalin otomatis; **Belum dapat diputuskan** | Validasi pemilik/config; bukan sumber tagihan |
| `lain` | 50 | 48 | 43 | Master biaya | Biaya Lain; **Belum dapat diputuskan** | Kode tidak unik; rate/arti belum sah |
| `lp1_back` | 8 | 7 | 4 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `makan` | 243 | 243 | 243 | Komponen legacy | Biaya Lain bila sah; **Belum dapat diputuskan** | Kamus komponen/tarif/relasi belum sah |
| `orang` | 14 | 14 | 13 | Akun/operator | Mapping operator terpisah; **Belum dapat diputuskan** | Jangan copy kredensial/role; izin target berbeda |
| `ortu` | 2 | 3 | 1 | Kontak orang tua | Belum ditetapkan; **Belum dapat diputuskan** | Perlu mapping/desain kontak, bukan penerimaan |
| `rekap_belum_spp` | 49 | 89 | 25 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `rekap_belum_spp2` | — | 103 | — | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `rekap_belum_spp4` | 50 | 50 | — | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `rekap_belum_spp_back` | 0 | 43 | 126 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `rekap_belum_spp_back1` | 0 | 10 | 15 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `rekap_belum_spp_back2` | 0 | 0 | 2 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `rekap_belum_spp_back3` | 1 | 6 | 3 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `sd` | 49 | 49 | 49 | Referensi identitas | Staging pembantu; **Belum dapat diputuskan** | Asal/duplikasi/otoritas daftar belum disahkan |
| `semua$` | 522 | 522 | 522 | Referensi identitas | Staging pembantu; **Belum dapat diputuskan** | Asal/duplikasi/otoritas daftar belum disahkan |
| `siswa` | 369 | 787 | 131 | Identitas/tarif | siswa_data / tarif terpilih; **Perlu konversi** | Validasi dan status; bagian 3–4 |
| `siswa05` | 700 | 700 | 700 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `SISWA072014` | 248 | 248 | 248 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `siswa072015` | 8.438 | 8.438 | 8.438 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `siswa2011` | 708 | 708 | 708 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `siswaasli` | 420 | 420 | 420 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `siswabaru2011` | 126 | 126 | 126 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `smp` | 81 | 81 | 81 | Referensi identitas | Staging pembantu; **Belum dapat diputuskan** | Asal/duplikasi/otoritas daftar belum disahkan |
| `sp1_back` | 16 | 18 | 7 | Arsip/salinan | Staging pembantu bila disahkan; **Belum dapat diputuskan** | Struktur dicatat; asal/cutoff/duplikasi perlu bukti; tidak di-union |
| `SPP_BELUM` | 0 | 685 | 25 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `surat` | 1 | 1 | 1 | Konfigurasi/cetakan/teknis | Tidak disalin otomatis; **Belum dapat diputuskan** | Validasi pemilik/config; bukan sumber tagihan |
| `tabungan` | 427 | 537 | 256 | Saldo | tabungan_data; **Perlu konversi** | Kelayakan per rekening; bagian 6 |
| `temp_bayar_du` | 20 | 98 | 26 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `temp_belum_spp` | 600 | 1.039 | 318 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `temp_jemputan` | 0 | 0 | 0 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `TEMP_MAKAN` | 0 | 0 | 0 | Cache/rekap | Rekonsiliasi pembantu; **Belum dapat diputuskan** | Tidak membuktikan kewajiban/header; jangan gandakan |
| `transaksi_k` | 1.280 | 2.606 | 959 | Jurnal keluar | transaksi_k_data; **Perlu konversi** | Identitas, arah dan saldo; bagian 6 |
| `transaksi_m` | 21.247 | 13.534 | 6.046 | Jurnal masuk | transaksi_m_data; **Perlu konversi** | Identitas, arah dan saldo; bagian 6 |
| `u_lain` | 32 | 48 | 16 | Detail biaya | Alokasi Biaya Lain; **Belum dapat diputuskan** | Hubungan header dan tarif belum sah |
| `U_TABUNGAN` | 68 | 36 | 1 | Jurnal tambahan | Belum ditetapkan; **Belum dapat diputuskan** | Cek duplikasi/arti, jangan menambah kas otomatis |

Jumlah tabel hadir: SD 56; SMP 52; SMA 50. Pemeriksaan isi rinci 20 tabel kandidat per unit tidak dianggap pemeriksaan seluruh baris arsip.
