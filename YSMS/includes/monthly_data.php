<?php
/**
 * Monthly Data (monthly_data.php + its Excel download monthly_data_export.php). Admin / Super Admin.
 * Read only; uses the existing fields and rules:
 *   appointments — surveys.appointment_date in the month (every stage); a vessel with no Appointment
 *                  Date counts by its Survey Assigned date (assign_date) instead
 *   completed    — the dashboard Month Summary vessels: Pending Report + Completed with their own
 *                  Survey Completed Date in the month (dashboardMonthWhere)
 *   recovery     — the same vessels as completed, with their recovery (recovery_list.php rules:
 *                  NOT APPLICABLE survey types count 0, Onhire shown with a minus, totals positive)
 * One row per survey (no joins that repeat a survey).
 */
require_once __DIR__ . '/dashboard_month.php';
require_once __DIR__ . '/recovery_list.php';

/** The date an Appointments row counts by: Appointment Date, else the Survey Assigned date. */
const MD_APPT_DATE_SQL = 'IF(YEAR(s.appointment_date) > 0, s.appointment_date, IF(YEAR(s.assign_date) > 0, DATE(s.assign_date), NULL))';

const MD_TABS = ['appointments' => 'Appointments', 'completed' => 'Survey Completed', 'recovery' => 'Recovery'];

function mdCanView(): bool {
    return in_array((string)($_SESSION['role'] ?? ''), ['Admin', 'Super Admin'], true);
}

/** [year, month, 'YYYY-MM-01', first day of the next month] from ?month=YYYY-MM (this month when missing / wrong). */
function mdMonth(array $g): array {
    $v = (string)($g['month'] ?? '');
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $v, $m) || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
        $m = [0, date('Y'), date('m')];
    }
    $start = sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]);
    return [(int)$m[1], (int)$m[2], $start, date('Y-m-d', strtotime($start . ' +1 month'))];
}

/** ?day=YYYY-MM-DD (Appointments of one day, e.g. today from the dashboard) or ''. */
function mdDay(array $g): string {
    $d = (string)($g['day'] ?? '');
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d && (int)$dt->format('Y') >= 2000 && (int)$dt->format('Y') <= 2100) ? $d : '';
}

/** Appointments (same rule as the Appointments tab) with their date in [$from, $to): a count. */
function mdAppointmentCount(PDO $db, string $from, string $to): int {
    $q = $db->prepare('SELECT COUNT(*) FROM surveys s WHERE ' . MD_APPT_DATE_SQL . ' >= ? AND ' . MD_APPT_DATE_SQL . ' < ?');
    $q->execute([$from, $to]);
    return (int)$q->fetchColumn();
}

function mdTab(array $g): string {
    $t = (string)($g['tab'] ?? '');
    return isset(MD_TABS[$t]) ? $t : 'appointments';
}

/** Page the vessel opens on: its current stage. */
function mdStageUrl(array $r): string {
    $page = ['Pending Report' => 'report_detail.php', 'Completed' => 'completed_detail.php'][(string)$r['status']] ?? 'vessel_detail.php';
    return $page . '?id=' . (int)$r['id'];
}

/** Stage name as the menu calls it. */
function mdStageLabel(string $status): string {
    return ['Pending Vessel' => 'Pending Vessel', 'Pending Report' => 'Pending Report', 'Completed' => 'Completed',
        'Cancelled' => 'Cancelled', 'Missed' => 'Missed'][$status] ?? $status;
}

/** Column headings of a tab: [key, label, 'text'|'date'|'num']. */
function mdColumns(string $tab): array {
    $base = [['vessel', 'Vessel', 'text'], ['report_no', 'Report No', 'text'], ['client', 'Client', 'text']];
    if ($tab === 'appointments') {
        return array_merge($base, [['port', 'Port', 'text'], ['type', 'Survey Type', 'text'], ['surveyor', 'Surveyor', 'text'],
            ['appointment', 'Appointment Date', 'date'], ['date_from', 'Date From', 'text'], ['stage', 'Stage', 'text']]);
    }
    if ($tab === 'completed') {
        return array_merge($base, [['port', 'Port', 'text'], ['type', 'Survey Type', 'text'], ['surveyor', 'Surveyor', 'text'],
            ['completed', 'Survey Completed', 'date'], ['stage', 'Stage', 'text']]);
    }
    return array_merge($base, [['type', 'Survey Type', 'text'], ['completed', 'Survey Completed', 'date'],
        ['vlsfo', 'VLSFO (MT)', 'num'], ['lsmgo', 'LSMGO (MT)', 'num'], ['hsfo', 'HSFO (MT)', 'num'], ['total', 'Total Recovery (MT)', 'num']]);
}

/**
 * The month's rows of a tab and its totals: [rows, totals]. Each row: id, url, na, onhire and the
 * column values (dates as Y-m-d, recovery as positive floats or null). totals: count, and for
 * recovery vlsfo / lsmgo / hsfo / total (DB sums, NOT APPLICABLE types count 0).
 */
function mdData(PDO $db, string $tab, int $y, int $m, string $day = ''): array {
    [, , $start, $end] = mdMonth(['month' => sprintf('%04d-%02d', $y, $m)]);
    if ($tab === 'appointments' && $day !== '') { $start = $day; $end = date('Y-m-d', strtotime($day . ' +1 day')); } // one day only
    if ($tab === 'appointments') {
        $where = MD_APPT_DATE_SQL . ' >= ? AND ' . MD_APPT_DATE_SQL . ' < ?';
        $params = [$start, $end];
        $order = MD_APPT_DATE_SQL . ' ASC, s.id ASC';
    } else {
        [$where, $params] = dashboardMonthWhere('Admin', 0, 0, $y, $m);
        $order = surveyCompletedDateSql('s') . ' ASC, s.id ASC';
    }
    $st = $db->prepare('SELECT ' . RECOVERY_LIST_COLUMNS . ' ' . RECOVERY_LIST_FROM . " WHERE $where ORDER BY $order");
    $st->execute($params);
    $rows = [];
    $seen = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($seen[(int)$r['id']])) continue; // one row per survey
        $seen[(int)$r['id']] = 1;
        $x = recoveryListRow($db, $r);
        $ymd = fn($v) => (!empty($v) && strpos((string)$v, '0000') !== 0) ? substr((string)$v, 0, 10) : '';
        $rows[] = [
            'id' => $x['id'], 'url' => mdStageUrl($r), 'na' => $x['na'], 'onhire' => $x['onhire'],
            'vessel' => $x['vessel'], 'report_no' => $x['report_no'], 'client' => $x['client'], 'port' => $x['port'],
            'type' => $x['type'], 'surveyor' => $x['surveyor'], 'stage' => mdStageLabel($x['status']),
            // Appointment Date, or the Survey Assigned date when the vessel has none
            'appointment' => $ymd($r['appointment_date'] ?? '') ?: $ymd($r['assign_date'] ?? ''),
            'date_from' => $ymd($r['appointment_date'] ?? '') !== '' ? 'Appointment' : ($ymd($r['assign_date'] ?? '') !== '' ? 'Survey Assigned' : ''), 'completed' => $ymd($r['survey_completed_date'] ?? ''),
            'vlsfo' => $x['vlsfo'], 'lsmgo' => $x['lsmgo'], 'hsfo' => $x['hsfo'], 'total' => $x['total'],
        ];
    }
    $totals = ['count' => count($rows)];
    if ($tab === 'recovery') {
        $q = $db->prepare('SELECT COALESCE(SUM(' . recoveryCountedSql('s', 'vlsfo_recovery') . '),0) AS vlsfo, COALESCE(SUM(' . recoveryCountedSql('s', 'lsmgo_recovery') . '),0) AS lsmgo,
            COALESCE(SUM(' . recoveryCountedSql('s', 'hsfo_recovery') . '),0) AS hsfo, COALESCE(SUM(' . recoveryCountedSql('s') . '),0) AS total FROM surveys s WHERE ' . $where);
        $q->execute($params);
        $totals += array_map('floatval', $q->fetch(PDO::FETCH_ASSOC) ?: ['vlsfo' => 0, 'lsmgo' => 0, 'hsfo' => 0, 'total' => 0]);
    }
    return [$rows, $totals];
}

/** A cell as shown: [text, sort value]. */
function mdCell(array $r, string $key, string $kind): array {
    $v = $r[$key] ?? '';
    if ($kind === 'date') {
        return $v === '' ? ['—', ''] : [strtoupper(date('d-M-Y', strtotime($v))), $v];
    }
    if ($kind === 'num') {
        if ($r['na']) return ['N/A', '-1e18']; // survey type without recovery (RECOVERY_NA_LABEL in Excel)
        if ($v === null) return ['—', '-1e17'];
        $signed = recoverySigned($v, $r['onhire']);
        return [number_format((float)$signed, 3), (string)$signed];
    }
    $t = trim((string)$v);
    return [$t === '' ? '—' : $t, strtolower($t)];
}

/** The same cell for Excel: text, a date, or a signed number (null = empty). */
function mdExcelCell(array $r, string $key, string $kind) {
    $v = $r[$key] ?? '';
    if ($kind === 'date') return $v === '' ? '' : date('d-M-Y', strtotime($v));
    if ($kind === 'num') {
        if ($r['na']) return RECOVERY_NA_LABEL;
        return $v === null ? '' : recoverySigned($v, $r['onhire']);
    }
    return (string)$v;
}
