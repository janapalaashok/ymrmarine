-- Anchorage entries for every port (safe, additive, idempotent).
--
-- Every port gets a matching "<name> Anchorage" row in the same country,
-- e.g. "Visakhapatnam Port" -> "Visakhapatnam Anchorage",
-- "Port of Singapore" -> "Singapore Anchorage". This mirrors the automatic
-- backfill already performed in code by config/config.php's
-- ensurePortAnchorages() (called from assign_vessel.php, vessel_detail.php
-- and ajax/admin_master.php) — running this file manually is optional and
-- only useful if you want the anchorages present before those pages are
-- first loaded. Safe to re-run: uses INSERT IGNORE against the existing
-- unique key on ports.port_name, so it never creates duplicates.
--
-- New ports added afterwards via Admin Controls automatically get their own
-- matching Anchorage entry created at the same time (see
-- ajax/admin_master.php's 'ports' add handler) — no need to re-run this.

INSERT IGNORE INTO `ports` (`port_name`, `country`)
SELECT
    CASE
        WHEN `port_name` LIKE 'Port of %' THEN
            CONCAT(TRIM(SUBSTRING(`port_name`, 9)), ' Anchorage')
        WHEN `port_name` REGEXP ' Port \\([^)]*\\)$' THEN
            CONCAT(
                TRIM(SUBSTRING(`port_name`, 1, LOCATE(' Port (', `port_name`) - 1)),
                ' Anchorage ',
                SUBSTRING(`port_name`, LOCATE(' Port (', `port_name`) + 6)
            )
        WHEN `port_name` LIKE '% Port' THEN
            CONCAT(TRIM(SUBSTRING(`port_name`, 1, LENGTH(`port_name`) - 5)), ' Anchorage')
        ELSE
            CONCAT(`port_name`, ' Anchorage')
    END,
    `country`
FROM `ports`
WHERE `port_name` NOT LIKE '%Anchorage%';
