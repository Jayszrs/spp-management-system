-- =========================================================
-- Putus relasi tabungan dari transaksi pembayaran.
-- Tabungan baru hanya dicatat lewat menu Tabungan Masuk/Keluar.
-- Aman dijalankan ulang pada database db_spp.
-- =========================================================

USE `db_spp`;

DROP PROCEDURE IF EXISTS `remove_payment_linked_savings`;
DELIMITER $$
CREATE PROCEDURE `remove_payment_linked_savings`()
BEGIN
  DECLARE insufficient_count INT DEFAULT 0;

  START TRANSACTION;

  DROP TEMPORARY TABLE IF EXISTS `tmp_payment_linked_savings`;
  CREATE TEMPORARY TABLE `tmp_payment_linked_savings` AS
  SELECT `NO_INDUK`, COALESCE(SUM(`MASUK`), 0) AS `amount`
  FROM `transaksi_m`
  WHERE `bayar_id` IS NOT NULL
  GROUP BY `NO_INDUK`
  HAVING `amount` > 0;

  SELECT COUNT(*) INTO insufficient_count
  FROM `tmp_payment_linked_savings` tmp
  LEFT JOIN `tabungan` t ON t.`NO_INDUK` = tmp.`NO_INDUK`
  WHERE COALESCE(t.`SALDO`, 0) + 0.001 < tmp.`amount`;

  IF insufficient_count > 0 THEN
    ROLLBACK;
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Cleanup dibatalkan: saldo tabungan tidak cukup untuk menghapus setoran dari pembayaran.';
  END IF;

  UPDATE `tabungan` t
  JOIN `tmp_payment_linked_savings` tmp ON tmp.`NO_INDUK` = t.`NO_INDUK`
  SET t.`SALDO` = GREATEST(t.`SALDO` - tmp.`amount`, 0);

  DELETE FROM `transaksi_m`
  WHERE `bayar_id` IS NOT NULL;

  COMMIT;
END$$
DELIMITER ;

CALL `remove_payment_linked_savings`();
DROP PROCEDURE `remove_payment_linked_savings`;

SELECT COUNT(*) AS remaining_payment_linked_savings
FROM `transaksi_m`
WHERE `bayar_id` IS NOT NULL;
