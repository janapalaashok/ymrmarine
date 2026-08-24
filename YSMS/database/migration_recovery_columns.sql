-- YSMS Migration: Recovery in VLSFO / LSMGO cards (Dashboard enhancement)
-- Run this once on your live database (phpMyAdmin > SQL tab, or via CLI).
--
-- Note: the `recovery_amount` column (used for the existing Total/This Month
-- Recovery cards, reading TABLES 54B!O21) is assumed to already exist on your
-- live `surveys` table. If it does not exist yet, uncomment the first
-- statement below as well.

-- ALTER TABLE `surveys` ADD COLUMN `recovery_amount` DECIMAL(12,3) DEFAULT NULL AFTER `overdue_days`;

ALTER TABLE `surveys`
    ADD COLUMN `vlsfo_recovery` DECIMAL(12,3) DEFAULT NULL,
    ADD COLUMN `lsmgo_recovery` DECIMAL(12,3) DEFAULT NULL;
