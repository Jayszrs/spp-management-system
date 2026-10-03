-- Direct import disabled. Use the reviewed CLI runner and an explicit target.
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Direct import disabled; use php sql/run_legacy_sql.php --script=add_payment_method.sql';
