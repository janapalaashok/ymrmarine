<?php
/**
 * Vessel dates of a survey: ETA, ETB, ETC and Expected Supply Date (each optional).
 * Set on the vessel page (vessel_detail.php, above Latest Update / Live Status) by an Admin or a
 * surveyor assigned to the vessel; shown in Pending Vessels (Latest Update column). Only the
 * dates that are set are shown, as DD-MMM-YY (e.g. 22-NOV-26).
 */

/** key => [column, label] */
const VESSEL_DATE_FIELDS = [
    'eta' => ['eta_date', 'ETA'],
    'etb' => ['etb_date', 'ETB'],
    'etc' => ['etc_date', 'ETC'],
    'esd' => ['expected_supply_date', 'Expected Supply Date'],
];

/** Adds the four DATE NULL columns to surveys once (existing rows are not changed). */
function vesselDatesEnsure(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = array_flip(array_map('strtolower', $db->query('SHOW COLUMNS FROM surveys')->fetchAll(PDO::FETCH_COLUMN)));
        foreach (VESSEL_DATE_FIELDS as [$col]) {
            if (!isset($have[$col])) $db->exec("ALTER TABLE surveys ADD COLUMN `$col` DATE NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('vessel dates columns: ' . $e->getMessage());
    }
}

/** 'Y-m-d' → '22-NOV-26' ('' when empty / not a date). */
function vesselDateFmt(?string $ymd): string {
    $ymd = substr(trim((string)$ymd), 0, 10);
    if ($ymd === '' || strpos($ymd, '0000') === 0) return '';
    $d = DateTime::createFromFormat('!Y-m-d', $ymd);
    return $d ? strtoupper($d->format('d-M-y')) : '';
}

/** The dates of a survey row that are set: [key => ['label' => 'ETA', 'ymd' => '2026-11-22', 'text' => '22-NOV-26']]. */
function vesselDatesOf(array $row): array {
    $out = [];
    foreach (VESSEL_DATE_FIELDS as $k => [$col, $label]) {
        $t = vesselDateFmt($row[$col] ?? null);
        if ($t !== '') $out[$k] = ['label' => $label, 'ymd' => substr((string)$row[$col], 0, 10), 'text' => $t];
    }
    return $out;
}
