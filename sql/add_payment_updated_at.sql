-- =========================================================
-- Migrasi timestamp update pembayaran
-- Database: db_spp
-- =========================================================

USE `db_spp`;

DELIMITER $$
DROP PROCEDURE IF EXISTS `migrate_payment_updated_at`$$
CREATE PROCEDURE `migrate_payment_updated_at`()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bayar'
      AND COLUMN_NAME = 'updated_at'
  ) THEN
    ALTER TABLE `bayar`
      ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
      AFTER `created_at`;

    UPDATE `bayar`
    SET `updated_at` = `created_at`
    WHERE `updated_at` IS NULL;
  END IF;
END$$
DELIMITER ;

CALL `migrate_payment_updated_at`();
DROP PROCEDURE IF EXISTS `migrate_payment_updated_at`;
