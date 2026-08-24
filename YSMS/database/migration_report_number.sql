-- Adds a unique report_number to surveys.
-- Format: YMR-YYYY-NNNNN (e.g. YMR-2026-00001)
-- Safe on live DB: nullable for old rows; UNIQUE allows multiple NULLs in MySQL.
-- New assigns always set a non-null unique value via assign_vessel.php.

ALTER TABLE `surveys`
    ADD COLUMN `report_number` VARCHAR(30) NULL DEFAULT NULL AFTER `vessel_name`;

ALTER TABLE `surveys`
    ADD UNIQUE INDEX `uq_surveys_report_number` (`report_number`);
