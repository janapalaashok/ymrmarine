-- Client self-service login: mobile + email on clients, linked to a Client-role user.
-- Safe / additive. `clients.user_id` and the 'Client' role already exist in the base schema.

ALTER TABLE `clients`
  ADD COLUMN `mobile` VARCHAR(20) DEFAULT NULL AFTER `contact_person`;

ALTER TABLE `clients`
  ADD COLUMN `email` VARCHAR(150) DEFAULT NULL AFTER `mobile`;
