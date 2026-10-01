-- Direct import disabled. Use the reviewed CLI runner and an explicit target.
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Direct import disabled; use php sql/run_legacy_sql.php --script=reset_demo_students_and_finance.sql';
