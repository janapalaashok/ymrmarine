CREATE DATABASE IF NOT EXISTS `ysms` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ysms`;
CREATE TABLE `roles` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE `users` (`id` INT AUTO_INCREMENT PRIMARY KEY, `role_id` INT, `name` VARCHAR(100), `email` VARCHAR(100) UNIQUE, `username` VARCHAR(50) UNIQUE, `password` VARCHAR(255), FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)) ENGINE=InnoDB;
INSERT INTO `roles` (`id`, `name`) VALUES (1, 'Admin'), (2, 'Surveyor');