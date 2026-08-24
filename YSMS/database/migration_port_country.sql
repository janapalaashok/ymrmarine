-- Country → Ports hierarchy (safe additive migration)
ALTER TABLE `ports`
  ADD COLUMN `country` VARCHAR(100) DEFAULT 'India' AFTER `port_name`;
