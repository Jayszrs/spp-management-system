# Operasi SQL legacy

Seluruh 29 berkas SQL legacy di `sql/*.sql` sekarang menolak impor langsung. Definisi aslinya disimpan sebagai data base64 di `sql/definitions/`; file ini tidak berisi statement SQL mentah sehingga `mysql --force` tidak dapat melanjutkan ke DDL/DML setelah sebuah error guard. Runner mendekode definisi hanya sesudah pemeriksaan target. Direktori definisi bukan antarmuka operasi. Skema instalasi baru juga disimpan sebagai payload non-SQL di `sql/schema.payload`; `schema.sql` hanya berisi penolakan dan `verify_schema.sql` hanya membaca. Jalur instalasinya adalah `php sql/bootstrap_production.php --execute` pada database kosong dengan target dan kredensial bootstrap eksplisit.

Lihat inventaris terkini dengan `php sql/run_legacy_sql.php --list`. Runner meminta `SPP_DB_NAME` eksplisit, `--apply`, dan `--confirm-script=<nama.sql>`; tanpa `--apply` ia hanya menampilkan target dan kategori. Skrip dengan `USE db_spp` lama dieksekusi tanpa perintah tersebut sehingga koneksi tidak berpindah dari target yang dikonfirmasi.

| Kategori | Skrip | Target yang diizinkan |
| --- | --- | --- |
| `migration` | `add_academic_year_billing`, `add_annual_payment_receipts`, `add_annual_student_fees`, `add_master_biaya_lain`, `add_master_daftar_ulang`, `add_modular_global_reports`, `add_payment_method`, `add_payment_references`, `add_payment_updated_at`, `add_psb_and_spp_full_rules`, `add_role_management`, `add_spp_billing_and_deposit`, `add_student_advanced`, `add_student_optional_fees`, `add_transaction_authorization`, `fix_spp_allocation_edit` (`.sql`) | Clone; `db_spp` hanya sesudah persetujuan pemilik, backup baru, dan gate utama |
| `historical` | `activate_legacy_fields`, `migrate_komite_bulanan`, `remove_payment_linked_savings`, `repair_negative_savings_balances`, `repair_one_time_fees`, `restore_mysql84_checks`, `simplify_payment_components_and_add_psb`, `sync_student_initial_fee_paid_totals` (`.sql`) | Clone saja; tinjau asumsi data lama sebelum pemakaian apa pun |
| `demo` | `reset_demo_students_and_finance`, `seed_students_psb`, `seed_demo_payments` (`.sql`) | Clone saja, sesuai urutan di [panduan demo](DEMO_DATA_RESET.md) |
| `dedicated` | `add_financial_request_guard.sql` | Gunakan `php sql/add_financial_request_guard.php`, bukan runner umum |
| `retired` | `allow_spp_installments.sql` | Tidak dapat dijalankan |

Contoh pemeriksaan pada clone:

```powershell
$env:SPP_DB_NAME = 'db_spp_audit_contoh'
$env:SPP_TEST_ALLOW_MUTATION = '1'
php sql/run_legacy_sql.php --script=add_transaction_authorization.sql
php sql/run_legacy_sql.php --script=add_transaction_authorization.sql --apply --confirm-script=add_transaction_authorization.sql
```

Untuk `migration` pada `db_spp`, jalankan hanya setelah persetujuan pemilik dan uji pada clone, dengan `SPP_ALLOW_MAIN_MIGRATION=1`, `--confirm-main=db_spp`, serta `--backup-file=<dump baru di luar repository>` yang dibuat dalam satu jam terakhir. Perintah ini **belum merupakan persetujuan** penerapan utama. Gate menolak skrip `historical` dan `demo` pada utama meskipun flag utama ada. Validasi hasil setiap migrasi dengan `sql/verify_schema.sql` dan pemeriksaan data yang relevan. DDL MySQL dapat commit sebagian sebelum kegagalan; catat statement terakhir dan tentukan pemulihan dari backup bersama operator.

Beberapa definisi lama memakai `ALTER TABLE ... ADD ... IF NOT EXISTS` atau `DROP CONSTRAINT IF EXISTS` khusus MariaDB. Runner menolak definisi tersebut pada server MySQL sebelum statement pertama. Migrasi yang masih diperlukan di MySQL harus ditinjau dan dikonversi secara terpisah; jangan memaksa impor definisi internal.

## Pembaruan 2 Oktober 2026: pembayaran langsung

Nama lama `add_spp_billing_and_deposit.sql` tetap menjadi alias kompatibilitas runner; definisinya sekarang hanya membuat penerbitan/alokasi SPP langsung. Tidak ada definisi legacy yang membuat kembali tabel atau kolom Titipan SPP. Seeder pembayaran menghasilkan 988 header, tanpa transaksi titipan. Backfill SPP menolak pembayaran yang tidak cocok dengan sisa satu tagihan, lalu rollback; dana tidak dikonversi menjadi saldo. Pembersihan database lama memakai `sql/remove_spp_deposit.php`, bukan menjalankan ulang schema instalasi.
