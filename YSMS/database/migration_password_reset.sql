-- 🌟 Migration: adds email + Forgot Password support to the existing `users` table.
-- Safe to run once on the live database. Existing data/columns are untouched.

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NULL AFTER `full_name`,
    ADD COLUMN IF NOT EXISTS `reset_token` VARCHAR(255) NULL AFTER `email`,
    ADD COLUMN IF NOT EXISTS `reset_token_expires` DATETIME NULL AFTER `reset_token`;
