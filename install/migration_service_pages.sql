-- ============================================================
-- Migration: Dedicated service pages (SEO + editable content)
-- Safe to run on live DB. Existing rows are kept.
-- ============================================================

ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `slug` VARCHAR(160) NULL DEFAULT NULL AFTER `title`,
  ADD COLUMN IF NOT EXISTS `meta_title` VARCHAR(200) NULL DEFAULT NULL AFTER `slug`,
  ADD COLUMN IF NOT EXISTS `meta_description` TEXT NULL AFTER `meta_title`,
  ADD COLUMN IF NOT EXISTS `meta_keywords` VARCHAR(255) NULL DEFAULT NULL AFTER `meta_description`,
  ADD COLUMN IF NOT EXISTS `hero_image` VARCHAR(255) NULL DEFAULT NULL AFTER `meta_keywords`,
  ADD COLUMN IF NOT EXISTS `hero_subtitle` TEXT NULL AFTER `hero_image`,
  ADD COLUMN IF NOT EXISTS `overview_title` VARCHAR(200) NULL DEFAULT NULL AFTER `hero_subtitle`,
  ADD COLUMN IF NOT EXISTS `overview_body` TEXT NULL AFTER `overview_title`,
  ADD COLUMN IF NOT EXISTS `overview_body2` TEXT NULL AFTER `overview_body`,
  ADD COLUMN IF NOT EXISTS `features_json` MEDIUMTEXT NULL AFTER `overview_body2`,
  ADD COLUMN IF NOT EXISTS `process_json` MEDIUMTEXT NULL AFTER `features_json`,
  ADD COLUMN IF NOT EXISTS `faq_json` MEDIUMTEXT NULL AFTER `process_json`,
  ADD COLUMN IF NOT EXISTS `who_body` TEXT NULL AFTER `faq_json`,
  ADD COLUMN IF NOT EXISTS `page_image` VARCHAR(255) NULL DEFAULT NULL AFTER `who_body`,
  ADD COLUMN IF NOT EXISTS `cta_text` VARCHAR(120) NULL DEFAULT NULL AFTER `page_image`;

-- Unique slug (allows multiple NULLs on older MySQL; fine after backfill)
ALTER TABLE `services` ADD UNIQUE INDEX IF NOT EXISTS `uq_services_slug` (`slug`);
