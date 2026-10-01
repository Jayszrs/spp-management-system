# Konteks Proyek SistemSPP

SistemSPP adalah aplikasi administrasi pembayaran sekolah berbasis PHP, JavaScript, dan MySQL (`mysqli`). Dokumen ini merangkum alur aktif. Untuk rincian teknis, kode dan schema adalah sumber kebenaran; [AI_CHANGELOG.md](./AI_CHANGELOG.md) adalah arsip perubahan, bukan panduan operasional.

> **Status pengembangan 1 Oktober 2026:** [Audit kesiapan terbaru](./READINESS_AUDIT_20261001.md) mencatat perbaikan pada branch review yang diuji pada database latihan. Kode transaksi/tabungan baru bergantung pada tabel `keuangan_request`; catatan tabungan bergantung pada kolom `keterangan` dan view jurnal yang diperbarui. Database utama belum dimigrasi dan hanya dibaca selama audit. Periksa urutan deployment dan persetujuan migrasi sebelum mengaktifkan kode tersebut untuk operasional.

## Lingkungan

- Pengembangan lokal saat ini memakai Laragon di `C:\laragon\www\spp-management-system` dan database `db_spp`.
- Konfigurasi koneksi berada di `koneksi.php`. Di Railway, koneksi memakai variabel `SPP_DB_*`; lihat [panduan deployment](./RAILWAY_DEPLOYMENT.md).
- `sql/schema.sql` hanya untuk database baru/kosong dan tidak boleh diimpor ke database berisi data.
- Untuk reset dan pengisian data **demo**, ikuti [DEMO_DATA_RESET.md](./DEMO_DATA_RESET.md). Jangan terapkan reset pada data sekolah sungguhan.

## Alur pembayaran aktif

- Master Siswa menyimpan Pangkal dan PSB sebagai kewajiban sekali bayar. Pembayaran keduanya dapat dicicil sesuai sisa tagihan.
- Master Penerbitan SPP membuat tagihan bulanan Juli–Juni berdasarkan penempatan siswa yang tersimpan. Kasir memilih **Bulan Tagihan SPP & Komite** dan **Tahun Tagihan**. Satu transaksi SPP hanya melunasi satu bulan; tunggakan SPP lebih tua diperiksa dahulu.
- Komite adalah tagihan bulanan dari tarif `siswa.POMG`. Ketika SPP suatu bulan dibayar, Komite bulan yang sama harus sudah lunas atau ikut dilunasi. Komite dapat dibayar sendiri.
- **Tanggal Bayar** mencatat hari uang diterima, bukan periode tagihan. Nominal SPP yang belum cukup untuk satu bulan atau melebihi sisa tagihan dicatat melalui tindakan terpisah **Catat Titipan SPP**. Penggunaan titipan memerlukan konfirmasi dan tidak menambah penerimaan kas baru.
- Daftar Ulang memakai tagihan tahunan. Dropdown tahun selalu tersedia di form input dan edit; tanda `!` muncul bila siswa memiliki tunggakan tahun ajaran sebelumnya. Tagihan tahun berjalan yang masih bersisa menjadi pilihan awal; jika sudah lunas, tunggakan lama tertua yang belum lunas dipilih. Pembayaran dapat dicicil sampai sisa tagihan, sedangkan tahun dan kelas pada transaksi berasal dari snapshot tagihan yang dipilih (bukan bulan SPP pada form). Baseline demo 2026/2027 tidak membuat tagihan Daftar Ulang tahun sebelumnya.
- Biaya Lain memakai tagihan yang diterbitkan dari master. Tabungan masuk/keluar adalah jurnal terpisah, bukan komponen penerimaan pembayaran sekolah.
- Riwayat kelas memakai `siswa_tahun_ajaran`; laporan dan struk membaca snapshot/tagihan terkait agar perubahan tarif atau kelas berikutnya tidak menulis ulang histori.
- Surat Laporan ke Kepala Sekolah menampilkan total tunggakan per rombel dan total pilihan, tanpa nama atau NIS siswa. Cakupan dapat dipilih untuk satu rombel, seluruh rombel dalam satu tingkat kelas, atau seluruh kelas; status siswa Aktif/Arsip/Semua tetap dapat dipilih. PDF dan Excel memakai rekap yang sama.

## Hak akses

| Aksi | Admin | Kasir | Bendahara |
| --- | :---: | :---: | :---: |
| Input, lihat, cetak pembayaran | Ya | Ya | Tidak |
| Edit pembayaran | Langsung | Ajukan perubahan; berlaku setelah disetujui Admin | Tidak |
| Hapus pembayaran | Langsung | Ajukan penghapusan; berlaku setelah disetujui Admin | Tidak |
| Setujui/tolak pengajuan kasir | Ya | Tidak | Tidak |
| Periksa antrean/riwayat otorisasi | Ya | Pengajuan sendiri | Ya, baca saja |
| Kelola Data Siswa, Kelas/Rombel, SPP, Biaya Lain, Daftar Ulang | Ya | Ya | Tidak |
| Kelola akun/role | Ya | Tidak | Tidak |
| Laporan Global | Ya | Ya | Ya |

Guard backend berada di `includes/auth.php`. Hak akses harus diperiksa pada endpoint mutasi, bukan hanya dengan menyembunyikan tombol.

## Lokasi kode utama

- `pembayaran/`: input, histori, edit/hapus, dan struk transaksi.
- `siswa/`: Data Siswa dan riwayat kelas.
- `master_spp.php`, `master_kelas.php`, `master_biaya_lain.php`, `master_daftar_ulang.php`: pengelolaan master.
- `includes/reports.php`, `laporan/`: query, tampilan, cetak/PDF, dan ekspor laporan.
- `sql/schema.sql`, `sql/verify_schema.sql`: schema referensi dan pemeriksaannya.
- `tests/`: pengujian regresi dan integrasi.

Sebelum mengubah data atau menjalankan SQL destruktif, periksa database target, buat backup, dan baca hasil verifikasi. Jangan memasukkan dump data siswa, kredensial, atau secret ke Git.
