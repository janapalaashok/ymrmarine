-- Migration: Multi-Survey-Type support for assign_vessel.php
-- This migration is purely ADDITIVE. It does not alter or remove any existing
-- column, table, or data. The existing single-value column
-- (surveys.survey_type_id) is left untouched and continues to hold the
-- PRIMARY (first selected) survey type so that every existing page, report,
-- and query keeps working exactly as before.
--
-- The new junction table below simply records ALL survey types selected on
-- the Assign Vessel form, in addition to the primary one.
-- (Note: Surveyor assignment remains single-select, exactly as in the
-- original project, so no surveyor junction table is needed.)

CREATE TABLE IF NOT EXISTS `survey_survey_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `survey_id` int(11) NOT NULL,
  `survey_type_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_survey_type` (`survey_id`, `survey_type_id`),
  KEY `idx_survey_survey_types_type` (`survey_type_id`),
  CONSTRAINT `fk_survey_survey_types_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_survey_survey_types_type` FOREIGN KEY (`survey_type_id`) REFERENCES `survey_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
