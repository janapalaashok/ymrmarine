-- Client address lines for invoice auto-fill
-- Safe to run multiple times if columns already exist (will error harmlessly)

ALTER TABLE `clients`
  ADD COLUMN `address_line1` VARCHAR(255) DEFAULT NULL COMMENT 'Door no, street' AFTER `contact_person`;

ALTER TABLE `clients`
  ADD COLUMN `address_line2` VARCHAR(255) DEFAULT NULL COMMENT 'City, country' AFTER `address_line1`;
