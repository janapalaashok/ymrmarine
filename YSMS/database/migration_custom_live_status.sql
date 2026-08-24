-- Adds live status tracking columns used by vessels.php, vessel_detail.php, export_vessels.php
-- Safe to run multiple times (IF NOT EXISTS where supported; otherwise ignore duplicate errors)

ALTER TABLE `surveys`
  ADD COLUMN IF NOT EXISTS `custom_live_status` TEXT NULL DEFAULT NULL;

ALTER TABLE `surveys`
  ADD COLUMN IF NOT EXISTS `status_updated_by` INT(11) NULL DEFAULT NULL;

ALTER TABLE `surveys`
  ADD COLUMN IF NOT EXISTS `status_updated_at` DATETIME NULL DEFAULT NULL;
