# Brief Perombakan Laporan Global SistemSPP

> Status 18 Agustus 2026: brief ini telah disetujui dan diimplementasikan melalui migrasi `sql/add_modular_global_reports.sql`, registry `includes/reports.php`, katalog `laporan/global.php`, dan template/export modular. Instruksi “jangan implementasi” di bawah merupakan batas tahap analisis saat brief pertama kali dibuat dan tidak lagi menjadi status pekerjaan aktif.

## Tujuan Dokumen

Dokumen ini merupakan brief analisis dan perencanaan untuk pengembangan modul **Laporan Global** SistemSPP. Pada tahap awal, jangan langsung mengubah kode atau database. Pelajari implementasi yang sudah ada, kritisi kebutuhan di bawah, lalu susun rencana implementasi bertahap yang aman dan tetap kompatibel dengan data lama.

Konteks teknis proyek harus dibaca terlebih dahulu dari:

- `documentation/PROJECT_CONTEXT.md`
- `documentation/AI_CHANGELOG.md`
- `laporan/global.php`
- `laporan/index.php`
- `laporan/rekap_kelas.php`
- modul pembayaran, tabungan, siswa, serta schema dan migrasi terkait

## Perubahan Konsep Utama

Saat ini menu **Laporan Global** langsung membuka satu halaman laporan. Konsep tersebut ingin diubah menjadi **pusat pilihan template laporan**.

Ketika pengguna membuka Laporan Global, tampilkan halaman katalog berupa card-card menu. Setiap card menjelaskan nama laporan, tujuan singkat, dan tombol untuk membuka template laporan tersebut. Pengguna kemudian memilih filter yang sesuai dan sistem menghasilkan laporan yang dapat dilihat serta dicetak.

Halaman katalog tidak melakukan query laporan besar. Data laporan baru dimuat setelah pengguna memilih salah satu template dan mengisi filter wajib.

## Daftar Template Laporan

### 1. Status Pembayaran per Kategori

Nama yang disarankan: **Laporan Status Pembayaran**.

Tujuan laporan ini adalah melihat siswa yang sudah membayar, mencicil, lunas, atau belum membayar untuk satu kategori tertentu.

Filter minimal:

- kategori pembayaran;
- kelas/rombel;
- periode yang relevan dengan kategori;
- status pembayaran;
- pencarian nama atau nomor induk siswa.

Kategori mencakup komponen pembayaran yang memang tersedia pada sistem, seperti SPP, komite, uang pangkal, bangunan, seragam, kegiatan, makan, Sorga, infaq, daftar ulang, dan setiap item pada Master Biaya Lain.

Status jangan hanya dibagi menjadi “sudah” dan “belum”, karena pembayaran dapat dicicil. Gunakan definisi yang jelas:

- **Belum Bayar**: belum ada nominal pembayaran;
- **Cicilan/Belum Lunas**: sudah ada pembayaran tetapi masih terdapat sisa;
- **Lunas**: akumulasi pembayaran telah memenuhi tagihan;
- bila dibutuhkan, sediakan filter **Ada Pembayaran** sebagai gabungan Cicilan dan Lunas.

Periode harus mengikuti sifat tagihannya:

- SPP dan komite menggunakan bulan dan tahun pembayaran;
- daftar ulang menggunakan tahun ajaran Juli–Juni;
- komponen satu kali menggunakan total kewajiban siswa;
- Biaya Lain menggunakan item master yang dipilih dan aturan tagihannya.

Contoh kebutuhan yang harus dapat dijawab:

- siapa saja siswa yang belum melunasi SPP Agustus 2026;
- siapa saja yang sudah melunasi Uang Bangunan;
- siapa saja yang masih mencicil Daftar Ulang tahun ajaran 2026/2027;
- siapa saja yang membayar item Biaya Buku pada periode terpilih.

### 2. Rekap Penerimaan Berdasarkan Tanggal Transaksi

Nama yang disarankan: **Rekap Penerimaan Harian**.

Tujuan laporan ini adalah membantu kasir melihat rincian seluruh pembayaran yang benar-benar diterima pada satu hari atau rentang tanggal tertentu.

Filter minimal:

- satu tanggal atau rentang tanggal transaksi;
- operator/kasir;
- kelas/rombel;
- kategori pembayaran;
- metode pembayaran jika tersedia.

Laporan harus mengacu pada **tanggal transaksi aktual**, bukan hanya bulan tagihan. Hasil perlu dikelompokkan per kategori dan tetap menampilkan detail siswa, waktu transaksi, nomor transaksi/struk, nominal, operator, dan metode pembayaran.

Contoh hasil yang diharapkan:

- daftar siswa yang membayar SPP pada tanggal terpilih;
- daftar siswa yang membayar Biaya Buku pada tanggal terpilih;
- subtotal setiap kategori;
- total penerimaan seluruh kategori pada periode tersebut.

Template ini dapat mengembangkan fungsi rekap harian yang saat ini berada di `laporan/global.php`, tetapi perlu dipindahkan ke struktur yang lebih modular agar `global.php` dapat menjadi halaman katalog.

### 3. Rekap SPP Tahunan per Kelas/Rombel

Nama yang disarankan: **Rekap SPP Tahun Ajaran per Kelas**.

Tujuan utama laporan ini adalah menemukan tunggakan SPP siswa selama satu tahun ajaran.

Filter minimal:

- tahun ajaran;
- kelas/rombel;
- status siswa;
- pencarian siswa;
- opsi tampilan semua siswa, hanya yang memiliki tunggakan, atau hanya yang sudah lunas.

Struktur tabel:

- satu baris untuk satu siswa;
- kolom identitas siswa dibuat tetap/sticky saat tabel digeser horizontal;
- dua belas kolom bulan mengikuti urutan tahun ajaran Juli–Juni;
- kolom terakhir memuat total dibayar dan/atau total tunggakan;
- setiap sel bulan menampilkan status yang mudah dibaca, misalnya Lunas, Cicilan, Belum Bayar, atau nominal singkat.

Contoh urutan tahun ajaran `2026/2027` adalah Juli 2026 sampai Juni 2027, bukan Januari sampai Desember.

Untuk data besar, gunakan query agregat dan pagination server-side pada baris siswa. Jangan menjalankan satu query baru untuk setiap siswa atau setiap sel bulan.

### 4. Rekap Pembayaran per Item dan Kelas

Nama yang disarankan: **Rekap Pembayaran per Item**.

Tujuannya adalah melihat penerimaan satu atau beberapa kategori pembayaran untuk satu kelas/rombel dalam satu bulan maupun beberapa bulan.

Filter minimal:

- satu atau beberapa kategori/item pembayaran;
- kelas/rombel;
- satu bulan atau rentang beberapa bulan;
- tahun kalender atau tahun ajaran sesuai kebutuhan kategori;
- status pembayaran dan pencarian siswa.

Hasil harus memperlihatkan siswa, rincian nominal per item/periode, subtotal per item, total per siswa, dan total seluruh laporan.

Gunakan `laporan/rekap_kelas.php` sebagai referensi tampilan awal, terutama pola header, filter, tabel horizontal, dan tombol cetak. Namun, jangan menyalin seluruh halaman menjadi banyak file yang duplikatif. Pecah bagian yang sama menjadi komponen/helper laporan yang dapat digunakan oleh template lain.

### 5. Rekap Tabungan per Kelas

Nama yang disarankan: **Rekap Mutasi Tabungan per Kelas**.

Tujuannya adalah melihat pergerakan tabungan seluruh siswa dalam satu kelas/rombel.

Sediakan dua mode tampilan:

- **Mode Harian**: kolom horizontal berisi tanggal-tanggal dalam bulan yang dipilih;
- **Mode Bulanan**: kolom horizontal berisi bulan-bulan dalam tahun yang dipilih.

Setiap baris mewakili siswa. Laporan perlu membedakan tabungan masuk dan tabungan keluar, serta menampilkan total masuk, total keluar, dan saldo akhir. Jangan hanya menjumlahkan uang masuk tanpa memperhitungkan penarikan.

Untuk mode harian, tentukan tampilan yang tetap terbaca ketika satu tanggal memiliki transaksi masuk dan keluar. Salah satu opsi adalah nilai bersih pada sel dengan detail saat dibuka, atau subkolom Masuk/Keluar. Codex perlu mengkritisi pilihan ini berdasarkan keterbacaan dan lebar cetak.

### 6. Rekap Tabungan Siswa Keseluruhan

Nama yang disarankan: **Laporan Buku Tabungan Siswa**.

Tujuannya adalah menampilkan histori tabungan lengkap untuk satu siswa atau sejumlah siswa berdasarkan filter yang luas.

Filter minimal:

- siswa;
- kelas/rombel;
- rentang tanggal;
- jenis mutasi: masuk, keluar, atau semua;
- operator;
- rentang nominal bila memang berguna.

Hasil minimal:

- identitas siswa;
- tanggal dan waktu transaksi;
- jenis mutasi;
- nominal masuk;
- nominal keluar;
- saldo berjalan atau saldo akhir;
- operator dan keterangan transaksi.

Saldo awal dan saldo berjalan harus dihitung dengan benar terhadap transaksi sebelum tanggal awal filter. Jangan menganggap saldo pada awal rentang selalu nol.

### 7. Laporan Tabungan Masuk dan Keluar per Siswa

Nama yang disarankan: **Laporan Transaksi Tabungan**.

Template ini berfokus pada daftar operasional tabungan masuk atau tabungan keluar. Pengguna dapat memilih salah satu jenis transaksi atau keduanya, kemudian memfilter siswa, kelas/rombel, tanggal, dan operator.

Bedakan tujuan template ini dari Buku Tabungan Siswa:

- **Buku Tabungan Siswa** menekankan kronologi dan saldo siswa;
- **Laporan Transaksi Tabungan** menekankan daftar transaksi operasional, pencocokan kas, subtotal masuk, dan subtotal keluar.

Jika setelah audit kedua template menghasilkan informasi yang terlalu mirip, Codex boleh mengusulkan penggabungan keduanya dalam satu halaman dengan dua mode, selama fungsi dan hasil cetaknya tetap jelas.

### 8. Rekap Setoran Kas Harian

Label yang disarankan: **Rekap Setoran Kas Harian**.

Tujuannya adalah menjadi dasar kasir menyerahkan penerimaan harian kepada bagian keuangan.

Filter minimal:

- tanggal tunggal atau rentang tanggal;
- kasir/operator;
- metode pembayaran;
- kategori penerimaan.

Hasil minimal:

- identitas periode dan kasir;
- total penerimaan pembayaran siswa per kategori;
- subtotal berdasarkan metode pembayaran;
- jumlah transaksi;
- rincian transaksi sebagai lampiran atau bagian yang dapat dibuka;
- ruang tanda tangan kasir dan petugas keuangan pada hasil cetak bila diperlukan.

Catatan akuntansi penting:

- hanya transaksi **Tunai** yang menjadi uang fisik untuk disetorkan;
- transaksi VA/QRIS tetap ditampilkan sebagai penerimaan non-tunai, tetapi tidak dijumlahkan sebagai setoran uang fisik;
- Tabungan Masuk merupakan kewajiban/saldo siswa, bukan pendapatan sekolah, sehingga harus dipisahkan dari pendapatan pembayaran;
- Tabungan Keluar bukan pengurang pendapatan sekolah dan harus ditempatkan pada bagian mutasi tabungan terpisah;
- pembatalan, koreksi, atau penghapusan transaksi harus tercermin secara konsisten sesuai histori yang tersedia.

Codex perlu memeriksa model data yang ada sebelum menentukan apakah laporan ini dapat menghasilkan angka setoran historis yang dapat diaudit dengan benar.

## Master Kelas dan Rombel

Sistem saat ini hanya menggunakan tingkat kelas `1` sampai `6`, sedangkan sekolah membutuhkan rombel seperti `1A`, `1B`, `1C`, `2A`, dan seterusnya.

Kebutuhan baru:

- tersedia Master Kelas/Rombel;
- kelas memiliki tingkat 1–6 dan kode/nama rombel;
- data siswa dapat ditempatkan pada kelas/rombel yang valid;
- seluruh filter laporan menggunakan master tersebut, bukan daftar teks yang ditulis manual;
- rombel dapat bertambah atau berkurang sesuai kebutuhan sekolah.

Codex harus mengkritisi model data sebelum implementasi. Jangan sekadar mengganti `siswa.KELAS` dari angka menjadi teks bebas karena hal itu berisiko merusak filter, tarif berbasis tingkat, histori, dan laporan tahun sebelumnya.

Model minimum yang perlu dipertimbangkan:

- master rombel menyimpan `tingkat` dan `nama/kode rombel` secara terpisah;
- tarif yang saat ini berbasis kelas 1–6 tetap mengacu pada `tingkat`, bukan huruf rombel;
- siswa mengacu ke ID rombel aktif;
- transaksi atau relasi tahun ajaran menyimpan snapshot/konteks kelas agar histori tidak berubah ketika siswa naik kelas.

Ada konflik yang harus diselesaikan: laporan SPP tahunan harus tetap benar setelah siswa naik kelas, sementara `siswa.KELAS` hanya menggambarkan kelas aktif terkini. Karena itu, Codex harus mengusulkan cara paling sederhana untuk mempertahankan histori kelas per tahun ajaran. Manfaatkan struktur tahun ajaran/penempatan internal yang sudah ada bila aman, dan hindari workflow penempatan massal yang rumit di UI.

## Prinsip Arsitektur dan Tampilan

- `laporan/global.php` menjadi katalog template atau memakai controller/routing tipis yang tetap menjaga kompatibilitas URL lama.
- Pisahkan query, normalisasi filter, perhitungan status, tampilan web, dan tampilan cetak.
- Buat komponen bersama untuk kop laporan, filter periode, filter kelas, kartu ringkasan, tabel, empty state, tombol cetak, pagination, dan format rupiah/tanggal.
- Jangan membuat satu file besar yang menangani seluruh jenis laporan dengan percabangan panjang.
- Hindari duplikasi query dan markup antar-template.
- Gunakan prepared statement dan escaping output.
- Gunakan agregasi SQL dan bulk query; hindari pola N+1.
- Gunakan pagination server-side untuk tabel vertikal berisi ratusan siswa/transaksi.
- Tabel yang melebar harus responsif, dapat digeser horizontal, dan mempertahankan kolom identitas bila memungkinkan.
- Tampilan cetak harus memiliki judul, identitas sekolah, periode, waktu cetak, pembuat laporan, dan nomor halaman bila didukung.
- Library tambahan, jika benar-benar diperlukan, harus disimpan lokal dan tidak bergantung pada CDN/internet.
- Export PDF/Excel yang sudah ada harus diaudit. Jangan menganggap export otomatis kompatibel dengan template baru.
- Hak akses setiap template harus mengikuti role backend, bukan hanya penyembunyian menu.

## Definisi Periode yang Tidak Boleh Tertukar

- **Tanggal transaksi**: waktu uang benar-benar diterima atau dikeluarkan.
- **Bulan tagihan SPP**: bulan kewajiban SPP yang dibayar.
- **Tahun ajaran**: Juli sampai Juni, misalnya `2026/2027` berarti Juli 2026–Juni 2027.
- **Rentang laporan**: batas tanggal transaksi yang dipilih pengguna.

Satu transaksi pada Agustus 2026 dapat saja digunakan untuk melunasi periode tagihan yang berbeda. Karena itu, setiap template harus menyatakan dengan jelas apakah filter bekerja berdasarkan tanggal transaksi atau periode tagihan.

## Tahapan yang Diminta dari Codex 5.5 High

Pada respons pertama, jangan langsung mengimplementasikan. Lakukan pekerjaan berikut:

1. Audit schema dan alur data pembayaran, daftar ulang, Biaya Lain, tabungan, kelas, serta export yang sudah ada.
2. Petakan setiap template ke tabel dan kolom sumber datanya.
3. Identifikasi data yang belum tersedia, definisi status yang ambigu, risiko histori kelas, dan potensi perhitungan ganda.
4. Kritisi apakah delapan template perlu menjadi delapan halaman terpisah atau beberapa dapat digabung dengan mode tampilan.
5. Susun arsitektur modular yang sesuai dengan PHP tanpa framework pada proyek ini.
6. Susun rancangan perubahan database, migrasi idempoten, kompatibilitas data lama, dan strategi rollback jika memang diperlukan.
7. Susun urutan implementasi bertahap beserta acceptance criteria dan pengujian setiap tahap.
8. Ajukan hanya pertanyaan yang benar-benar memerlukan keputusan bisnis dari pihak sekolah.

Jangan melakukan migrasi, perubahan kode, commit, push, atau perubahan database sebelum rancangan tersebut disetujui.

## Acceptance Criteria Tingkat Produk

- Laporan Global membuka katalog template laporan yang jelas.
- Pengguna dapat mengetahui fungsi setiap template sebelum membukanya.
- Status pembayaran tidak menyamakan cicilan dengan lunas.
- Laporan SPP mengikuti tahun ajaran Juli–Juni dan dapat menunjukkan tunggakan per rombel.
- Rombel seperti 1A, 1B, dan 1C dapat digunakan tanpa merusak tarif tingkat kelas 1–6.
- Laporan berbasis tanggal transaksi tidak tertukar dengan bulan tagihan.
- Rekap tabungan selalu memperhitungkan masuk, keluar, dan saldo.
- Rekap Setoran Kas Harian memisahkan tunai, non-tunai, dan tabungan.
- Tampilan web, cetak, PDF, dan Excel konsisten terhadap filter yang dipilih.
- Solusi tetap responsif dan efisien untuk ratusan hingga ribuan siswa.
- Implementasi mempertahankan histori dan kompatibilitas data lama.

## Hal yang Belum Boleh Diasumsikan

Codex harus meminta keputusan atau memberikan opsi jika informasi berikut belum dapat dibuktikan dari sistem:

- apakah Komite benar-benar ditagih setiap bulan dengan aturan yang sama seperti SPP;
- apakah Uang Pangkal, Bangunan, Seragam, Kegiatan, Makan, Sorga, dan Infaq berlaku satu kali selama siswa terdaftar atau per tahun ajaran;
- apakah Biaya Lain mempunyai periode kewajiban formal atau hanya tercatat ketika dibayar;
- apakah pembayaran non-tunai ikut dicatat dalam dokumen setoran atau hanya dalam rekap penerimaan;
- apakah Tabungan Masuk ikut diserahkan secara fisik oleh kasir pada hari yang sama;
- format kode rombel resmi sekolah dan apakah rombel berubah setiap tahun ajaran;
- kebutuhan tanda tangan, nomor dokumen, serta format kop pada hasil cetak;
- siapa saja role yang boleh melihat, mencetak, dan mengekspor masing-masing laporan.
