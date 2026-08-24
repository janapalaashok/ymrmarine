-- ==========================================================================
-- Migration: Multi-select Survey Types
-- Run this once against your EXISTING production database.
-- (Fresh installs using database.sql already include this column.)
-- ==========================================================================

ALTER TABLE `surveys`
  ADD COLUMN `survey_type_ids` VARCHAR(255) DEFAULT NULL AFTER `survey_type_id`;

-- Backfill existing rows so old records keep working with the new "+" display
-- (copies each row's single survey_type_id into the new CSV column).
UPDATE `surveys` SET `survey_type_ids` = `survey_type_id` WHERE `survey_type_ids` IS NULL;
