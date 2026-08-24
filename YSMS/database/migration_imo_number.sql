-- Adds an optional IMO Number field to surveys so vessels.php / reports.php /
-- completed.php can offer "Search by IMO Number" in the advanced filters.
-- Safe to run on an existing live database: nullable column, no data loss,
-- no change to existing foreign keys, indexes, or enum values.

ALTER TABLE `surveys`
    ADD COLUMN `imo_number` VARCHAR(20) NULL DEFAULT NULL AFTER `vessel_name`;

ALTER TABLE `surveys`
    ADD INDEX `idx_surveys_imo_number` (`imo_number`);
