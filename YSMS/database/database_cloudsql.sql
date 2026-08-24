USE `ysms_db`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `uploads`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `reports`;
DROP TABLE IF EXISTS `surveys`;
DROP TABLE IF EXISTS `vessels`;
DROP TABLE IF EXISTS `surveyors`;
DROP TABLE IF EXISTS `ports`;
DROP TABLE IF EXISTS `survey_types`;
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `settings`;

CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `roles` (`id`, `name`) VALUES (1, 'Admin'), (2, 'Surveyor'), (3, 'Client');

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `avatar` varchar(255) DEFAULT 'default_avatar.png',
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Password is 'password' hashed
INSERT INTO `users` (`id`, `role_id`, `username`, `password`, `full_name`, `avatar`, `status`) VALUES
(1, 1, 'admin', '$2y$10$7rK7S7Y86kptG1Wp4wVWeO8v9QZ7vIumI0C2M5vC12zKz8qf/2L2W', 'Admin', 'avatar_admin.png', 'Active'),
(2, 2, 'surveyor', '$2y$10$7rK7S7Y86kptG1Wp4wVWeO8v9QZ7vIumI0C2M5vC12zKz8qf/2L2W', 'John Doe', 'avatar_surveyor.png', 'Active'),
(3, 2, 'ramesh', '$2y$10$7rK7S7Y86kptG1Wp4wVWeO8v9QZ7vIumI0C2M5vC12zKz8qf/2L2W', 'Ramesh Kumar', 'avatar_surveyor2.png', 'Active'),
(4, 3, 'client', '$2y$10$7rK7S7Y86kptG1Wp4wVWeO8v9QZ7vIumI0C2M5vC12zKz8qf/2L2W', 'Northern Shipping', 'default_avatar.png', 'Active');

CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `clients` (`id`, `user_id`, `company_name`, `contact_person`) VALUES
(1, 4, 'Northern Shipping', 'Mr. Northern'),
(2, NULL, 'Bainbridge', 'Mr. Bainbridge'),
(3, NULL, 'XO Shipping', 'Mr. XO'),
(4, NULL, 'Oceanus Maritime', 'Mr. Oceanus');

CREATE TABLE `ports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `port_name` varchar(150) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `ports` (`id`, `port_name`) VALUES (1, 'Visakhapatnam Port'), (2, 'Kakinada Port'), (3, 'Gangavaram Port');

CREATE TABLE `survey_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(150) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `survey_types` (`id`, `type_name`) VALUES 
(1, 'Bunker Survey'), (2, 'Draft Survey'), (3, 'Cargo Survey'), (4, 'Condition Survey');

CREATE TABLE `surveys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vessel_name` varchar(255) NOT NULL,
  `client_id` int(11) NOT NULL,
  `agent_name` varchar(255) NOT NULL,
  `surveyor_id` int(11) NOT NULL,
  `survey_type_id` int(11) NOT NULL,
  `survey_type_ids` varchar(255) DEFAULT NULL,
  `port_id` int(11) NOT NULL,
  `assign_date` date NOT NULL,
  `survey_completed_date` date DEFAULT NULL,
  `report_uploaded_date` date DEFAULT NULL,
  `status` enum('Pending Vessel', 'Pending Report', 'Completed') DEFAULT 'Pending Vessel',
  `remarks` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `due_days` int(11) DEFAULT 0,
  `overdue_days` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  FOREIGN KEY (`surveyor_id`) REFERENCES `users` (`id`),
  FOREIGN KEY (`survey_type_id`) REFERENCES `survey_types` (`id`),
  FOREIGN KEY (`port_id`) REFERENCES `ports` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `surveys` (`id`, `vessel_name`, `client_id`, `agent_name`, `surveyor_id`, `survey_type_id`, `port_id`, `assign_date`, `survey_completed_date`, `report_uploaded_date`, `status`, `remarks`, `notes`, `due_days`, `overdue_days`) VALUES
(1, 'MV Northern Star', 1, 'Oceanus Agencies', 3, 1, 1, '2025-06-01', '2025-06-01', NULL, 'Pending Report', 'Vessel expected to complete bunkering before departure.', 'Pending final sign-off.', 1, 0),
(2, 'MV Ocean Explorer', 4, 'Oceanus Maritime', 2, 2, 1, '2025-05-31', NULL, NULL, 'Pending Vessel', 'Draft verification requested.', NULL, 3, 0),
(3, 'MV Blue Horizon', 2, 'Bainbridge Logistics', 2, 3, 2, '2025-05-30', NULL, NULL, 'Pending Vessel', 'Cargo hold inspection.', NULL, 0, 2),
(4, 'MV Pacific Dawn', 2, 'Bainbridge Logistics', 2, 1, 1, '2025-06-01', '2025-06-04', '2025-06-05', 'Completed', 'Routine bunker.', 'All surveys and reports are verified and uploaded successfully.', 0, 0),
(5, 'MV Emerald', 3, 'XO Agency', 3, 2, 3, '2025-05-28', '2025-05-31', '2025-06-01', 'Completed', 'Draft survey.', 'Completed successfully.', 0, 0),
(6, 'MV Atlantic', 4, 'Oceanus Agencies', 2, 4, 1, '2025-05-25', '2025-05-28', '2025-05-29', 'Completed', 'Condition survey.', 'Verified.', 0, 0),
(7, 'MV Ocean Queen', 1, 'Oceanus Agencies', 3, 1, 1, '2025-05-22', '2025-05-25', '2025-05-26', 'Completed', 'Bunker inspection.', 'Done.', 0, 0),
(8, 'MV Golden Wave', 2, 'Bainbridge Logistics', 2, 3, 2, '2025-05-19', '2025-05-21', '2025-05-22', 'Completed', 'Cargo survey.', 'Completed without remarks.', 0, 0);

CREATE TABLE `uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `survey_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_type` enum('Field Report', 'Formal Report PDF', 'Formal Report Excel', 'Formal Report Word') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` varchar(50) DEFAULT NULL,
  `uploaded_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `uploads` (`id`, `survey_id`, `file_name`, `file_type`, `file_path`, `file_size`) VALUES
(1, 4, 'Pacific Dawn_Report.xlsx', 'Formal Report Excel', 'uploads/Pacific Dawn_Report.xlsx', '245 KB'),
(2, 4, 'Pacific Dawn_Report.docx', 'Formal Report Word', 'uploads/Pacific Dawn_Report.docx', '512 KB'),
(3, 4, 'Pacific Dawn_Report.pdf', 'Formal Report PDF', 'uploads/Pacific Dawn_Report.pdf', '1.2 MB');

CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `meta_key` varchar(100) NOT NULL UNIQUE,
  `meta_value` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`meta_key`, `meta_value`) VALUES 
('site_name', 'YMR Survey Management System'),
('total_recovery', '135.768 MT'),
('month_recovery', '135.768 MT');