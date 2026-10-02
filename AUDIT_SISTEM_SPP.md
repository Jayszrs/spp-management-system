> **Pembaruan 2 Oktober 2026:** Titipan SPP telah dihapus dari aplikasi dan database dummy lokal. Penyebutan/pengujian titipan di dokumen ini adalah bukti historis. Aturan pembayaran dan baseline terkini mengikuti [audit kesiapan](documentation/READINESS_AUDIT_20261001.md). Tabungan tetap dipertahankan.

# Audit dan Baseline SistemSPP

> **Pembaruan 2 Oktober 2026:** migrasi lokal sudah terpasang dengan izin pemilik. Temuan lanjutan dan regresi lintas SD/SMP/SMA telah dituntaskan pada clone; status lokal **siap terbatas dengan syarat**. [Audit kesiapan terkini](documentation/READINESS_AUDIT_20261001.md) adalah rujukan status; seluruh pernyataan/baseline bertanggal sebelumnya di bawah adalah bukti historis, termasuk migrasi yang ketika itu belum diterapkan dan aturan Komite yang disederhanakan. Komite mandiri kini dijelaskan dengan prasyarat SPP pada audit/konteks terbaru.

> **Pembaruan 2026-10-01:** Dokumen ini menyimpan baseline dan temuan **historis 9 September**, termasuk daftar risiko sebagaimana ditemukan saat itu. Status kesiapan, baseline `db_spp` terkini, bukti tes baru, temuan yang sudah diperbaiki pada working tree, serta prasyarat migrasi ada pada [audit kesiapan 1 Oktober](documentation/READINESS_AUDIT_20261001.md). Jangan memakai daftar risiko di bawah sebagai daftar masalah yang semuanya masih terbuka hari ini, atau menganggap perbaikan working tree sudah terpasang pada database utama.

> **Perbaikan 2026-09-30:** Tiga temuan rekap historis dan urutan SPP dari [audit alur operasional](documentation/OPERATIONAL_FLOW_AUDIT_20260930.md) telah diperbaiki dan diuji pada database disposable. Rincian hasil, termasuk satu siklus HTTP dari kelas 1 sampai lulus, ada di dokumen tersebut.

> **Pembaruan 2026-09-19:** Bagian audit di bawah adalah baseline historis 2026-09-09, bukan kontrak fitur terbaru. SPP sekarang dibayar tepat satu tagihan terbit yang dipilih melalui bulan/tahun; tunggakan lebih tua tetap menghalangi. Dana lebih atau belum cukup dicatat lewat tindakan terpisah **Catat Titipan SPP**. Komite berasal dari `siswa.POMG` per bulan, mengikuti penempatan siswa, wajib lunas pada bulan yang sama ketika SPP dibayar, dan dapat dibayar sendiri. Rincian implementasi dan migrasi ada di [PROJECT_CONTEXT.md](documentation/PROJECT_CONTEXT.md) serta [AI_CHANGELOG.md](documentation/AI_CHANGELOG.md).

**Tanggal audit:** 2026-09-09 (Asia/Jakarta)  
**Ruang lingkup:** pembacaan struktur repository, konfigurasi, alur bisnis, role, schema SQL, database lokal baca-saja, pemeriksaan invariant finansial, dokumentasi, dan test.  
**Batas keamanan dokumen:** tidak memuat password, hash, token, cookie, URL privat, maupun data identitas siswa.

## Ringkasan

SistemSPP adalah aplikasi monolit PHP/MySQL tanpa framework. Halaman dirender dari PHP, menggunakan `mysqli`, session PHP, CSS/JavaScript biasa, serta Dompdf melalui Composer untuk PDF. Database utama bernama `db_spp` dan modul bisnis utamanya adalah siswa, pembayaran multi-komponen, daftar ulang, biaya lain, tabungan, laporan, dan manajemen akun.

Audit dilakukan pada branch `main` yang bersih. Tidak ada migrasi atau perubahan data yang dijalankan selama audit.

## Arsitektur dan domain

| Area | Tanggung jawab utama |
| --- | --- |
| Autentikasi | Login session, role `admin`, `bendahara`, dan `kasir`. |
| Master | Siswa, kelas/rombel, biaya lain, daftar ulang, dan akun operator. |
| Pembayaran | Header `bayar`, klaim periode SPP, detail biaya lain, daftar ulang, serta komponen tahunan. |
| Tahun ajaran | `tahun_ajaran` dan `siswa_tahun_ajaran` menyimpan konteks kelas/tarif per periode sekolah. |
| Tabungan | Saldo `tabungan` serta jurnal masuk `transaksi_m` dan keluar `transaksi_k`. |
| Laporan | Laporan umum, template global, struk, PDF, dan ekspor spreadsheet. |

Pembayaran SPP yang baru menggunakan aturan penuh per bulan, satu transaksi per periode, dan urutan kalender Juli--Juni. Daftar ulang, komponen tahunan, serta biaya lain memiliki tagihan dan batas sisa terpisah. Siswa diarsipkan melalui `is_active`, bukan dihapus dari aplikasi.

## Role aktual

| Role | Akses utama |
| --- | --- |
| Admin | Seluruh master, transaksi, tabungan, laporan, dan akun. |
| Bendahara | Dashboard, laporan, ekspor, dan riwayat tabungan. |
| Kasir | Input/edit pembayaran, tabungan, riwayat terkait, serta laporan/struk yang diizinkan. |

Role aktual harus selalu divalidasi dari kode, karena beberapa tabel lama di dokumentasi belum sepenuhnya mengikuti implementasi terbaru.

## Kontrak finansial yang sudah diperiksa

Pemeriksaan baca-saja pada database lokal menemukan kondisi berikut tanpa selisih:

- Total header pembayaran cocok dengan komponen dan detail terkait.
- Klaim SPP baru tidak memiliki duplikasi atau pemetaan yatim.
- Pembayaran komponen tahunan, daftar ulang, dan biaya lain tidak melampaui tagihan.
- Saldo tabungan tidak negatif dan cocok dengan jurnal masuk dikurangi jurnal keluar.
- Tidak ditemukan relasi pembayaran siswa atau operator yang yatim pada snapshot audit.

Kontrak implementasi yang harus dipertahankan pada perubahan berikutnya:

- Nominal transaksi dihitung ulang oleh backend.
- Mutasi finansial memakai transaction dan penguncian baris yang relevan.
- Pembayaran legacy tidak diedit atau dihapus otomatis.
- Riwayat kelas/tarif menggunakan snapshot tahun ajaran, bukan hanya data siswa terkini.
- Perubahan tarif siswa tidak boleh mengubah snapshot tahun berjalan untuk komponen yang sudah dibayar. Komponen tanpa pembayaran boleh diselaraskan secara atomik, termasuk ketika kelas master berbeda dari kelas histori.
- Status berhasil pada master siswa harus mencerminkan perubahan yang benar-benar tersimpan; submit tanpa perubahan atau nilai Advance yang diabaikan tidak boleh dilaporkan sebagai keberhasilan umum.
- Perbaikan data finansial selalu membutuhkan backup dan database disposable untuk regression test.

## Aturan SPP terkini

1. SPP wajib dibayar penuh satu kali per bulan.
2. Jalur kompatibilitas menghitung kewajiban historis dari penempatan `siswa_tahun_ajaran` berstatus `aktif`, `pindah`, atau `lulus`.
3. Setiap penempatan yang tercatat mencakup Juli sampai Juni pada tahun ajaran tersebut.
4. Sebelum membayar periode pilihan, seluruh periode terdahulu yang tercatat harus lunas berdasarkan `spp_perbulan_snapshot` tahun asalnya. Instalasi saat ini memakai tagihan SPP terbit sebagai sumber alokasi pembayaran.
5. Periode sebelum penempatan pertama yang diketahui dan tahun tanpa penempatan tidak direkonstruksi menjadi tunggakan.
6. Edit atau hapus periode prasyarat ditolak apabila sudah ada pembayaran pada periode sesudahnya, termasuk lintas tahun ajaran.
7. Form Input dan Edit memeriksa status SPP terbaru ke server ketika siswa atau periode berubah. Popup hanya membantu kasir; proses simpan tetap memvalidasi ulang di dalam transaksi database.

## Perilaku laporan riwayat tagihan

- Filter satu rombel atau tingkat menyajikan satu kelompok per NIS, dengan total tagihan, terbayar, sisa, dan seluruh rincian yang lolos filter.
- Pemilihan satu siswa melalui NIS atau NIS Diknas mempertahankan format detail satu tagihan per baris.
- SPP diurutkan memakai periode numerik `YYYY-MM` dalam urutan kalender tahun ajaran; pengelompokan hanya mengubah presentasi dan tidak menjadi sumber perhitungan baru.
- Pagination web menghitung siswa pada mode kelompok. Cetak, PDF, dan Excel menggunakan pengelompokan yang sama tanpa membatasi hasil ke halaman web aktif.

## Risiko dan utang teknis yang tercatat pada 9 September

Daftar ini dipertahankan untuk jejak audit. Per 1 Oktober, beberapa butir telah direproduksi dan diperbaiki pada working tree: agregasi Master Biaya Lain, pembayaran total nol, GET Master Daftar Ulang, role API saldo, perlindungan CSRF/idempotensi pada jalur keuangan yang diuji, serta pengeluaran dump/sesi dari indeks Git. Catatan tabungan dan pemulihan constraint lulus pada clone tetapi memerlukan migrasi utama. Status serta batas bukti setiap butir ada di [audit kesiapan terbaru](documentation/READINESS_AUDIT_20261001.md).

Prioritas tinggi:

- Backup database dan artefak session pernah terlacak di repository. File seperti ini harus dikeluarkan dari Git secara aman sebelum repository dibagikan lebih luas.
- Mutasi pembayaran/tabungan belum seluruhnya dilindungi CSRF dan idempotency; penghapusan pembayaran masih menggunakan GET.
- Konfigurasi database masih ditulis langsung dalam `koneksi.php` dan error koneksi dapat membocorkan detail internal.
- Sebagian akun/data seed masih memakai pola autentikasi legacy. Data demo tidak boleh diperlakukan sebagai basis produksi.
- Database lokal terdeteksi kehilangan beberapa CHECK constraint walaupun schema referensi mendefinisikannya. Verifikasi schema perlu dibuat gagal bila ada requirement `MISSING`.

Prioritas menengah:

- Ringkasan master biaya lain memakai agregasi `SUM(DISTINCT ...)`, yang dapat meremehkan total bila nominal beberapa siswa sama.
- Keterangan tabungan dari form belum disimpan ke jurnal.
- Backend pembayaran belum secara eksplisit menolak transaksi total nol.
- Membuka Master Daftar Ulang dengan parameter tahun dapat membuat tahun ajaran draf melalui GET.
- Saldo API hanya memeriksa login, belum membatasi role secara khusus.
- Beberapa nilai uang legacy masih menggunakan `DOUBLE`; histori juga dapat terhapus bila database diubah langsung lewat foreign key cascade.

## Verifikasi rutin yang disarankan

```powershell
php -l pembayaran/proses.php
php tests/spp_sequence_test.php
php tests/spp_payment_status_test.php
node --check assets/js/app.js
```

Test HTTP `tests/payment_process_integration_test.php` sengaja membutuhkan `SPP_TEST_ALLOW_MUTATION=1` dan `SPP_TEST_ADMIN_PASSWORD`. Jalankan hanya pada database disposable yang terpisah dari data operasional.

## Batas audit ini

Audit ini bukan pengganti penetration test, review kepatuhan, backup produksi, atau regression test penuh pada database produksi. Temuan prioritas tinggi harus ditangani bertahap dengan backup, branch terpisah, dan verifikasi sebelum deployment.
