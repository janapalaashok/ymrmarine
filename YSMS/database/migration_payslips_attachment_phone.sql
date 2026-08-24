-- Pay slips, assignment attachment, phone column
-- Safe additive migration

CREATE TABLE IF NOT EXISTS `payslips` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `uploaded_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `surveys` ADD COLUMN IF NOT EXISTS `attachment_path` VARCHAR(255) DEFAULT NULL;

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(30) NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NULL;

-- Optional: countries hierarchy for ports (Country → Ports)
CREATE TABLE IF NOT EXISTS `countries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `country_name` VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `countries` (`country_name`) VALUES ('India');

ALTER TABLE `ports` ADD COLUMN IF NOT EXISTS `country_id` INT DEFAULT NULL;
