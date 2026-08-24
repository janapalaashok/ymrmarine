-- YSMS Migration: Word Templates (Report Generator enhancement)
-- Run this once on your live database (phpMyAdmin > SQL tab, or via CLI).
--
-- Stores the predefined Word (.docx) templates that Admin uploads for use
-- in report_generator.php's "Select Word Template" searchable dropdown.
-- This does NOT touch any existing table.

CREATE TABLE IF NOT EXISTS `word_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `template_name` VARCHAR(150) NOT NULL,
    `survey_type` VARCHAR(100) DEFAULT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `uploaded_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
