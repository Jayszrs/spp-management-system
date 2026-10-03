-- Import langsung dinonaktifkan; jalankan sql/bootstrap_production.php pada database kosong.
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Gunakan bootstrap_production.php; schema.sql tidak dapat diimpor langsung';
