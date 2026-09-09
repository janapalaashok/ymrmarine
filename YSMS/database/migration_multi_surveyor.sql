-- Multi-surveyor assignment for assign_vessel.php (purely additive).
-- The existing single-value column `surveys.surveyor_id` is left untouched
-- and continues to hold the PRIMARY (first selected) surveyor, so every
-- existing page/report/query that filters by surveyor_id keeps working
-- exactly as before. This junction table records EVERY surveyor selected
-- on the Assign Vessel form (including the primary one).

CREATE TABLE IF NOT EXISTS `survey_surveyors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `survey_id` int(11) NOT NULL,
  `surveyor_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_survey_surveyor` (`survey_id`, `surveyor_id`),
  KEY `idx_survey_surveyors_surveyor` (`surveyor_id`),
  CONSTRAINT `fk_survey_surveyors_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_survey_surveyors_user` FOREIGN KEY (`surveyor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
