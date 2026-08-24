-- YSMS Migration: Business Card / ID Card downloads (Profile page enhancement)
-- Run this once on your live database (phpMyAdmin > SQL tab, or via CLI).
--
-- Adds two nullable columns on `users` that store the path of the Business
-- Card / ID Card file uploaded by Admin (from Admin Controls > Surveyors >
-- ID / Business Cards). Surveyors only get a Download button on Profile;
-- only Admin can upload/replace these files.

ALTER TABLE `users`
    ADD COLUMN `business_card_path` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN `id_card_path` VARCHAR(255) DEFAULT NULL;
