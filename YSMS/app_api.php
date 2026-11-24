<?php
/**
 * JSON data for the Android app screens (app.php + assets/app/). Read-only: every change
 * (upload, status update, edits) still goes through the existing pages and their checks.
 *
 * Visibility is the same as the web lists and detail pages:
 *   Admin / Super Admin → every survey; Surveyor → surveys assigned to them (primary or
 *   survey_surveyors); Client → their own company's surveys.
 *
 *   GET app_api.php?a=me
 *   GET app_api.php?a=dashboard
 *   GET app_api.php?a=list&status=pending_vessel|pending_report|completed|cancelled[&q=text][&overdue=1][&page=1]
 *   GET app_api.php?a=survey&id=<id>
 *   GET app_api.php?a=flash                      the message the last form post left (read once)
 *   GET app_api.php?a=inbox[&unread=1][&page=1]  Admin / Surveyor
 *   GET app_api.php?a=recovery[&q=][&month=YYYY-MM][&status=][&page=1]
 *   GET app_api.php?a=formats
 *   GET app_api.php?a=profile
 *   GET app_api.php?a=history&id=<id>             previous surveys of the same vessel
 *   GET app_api.php?a=assign_form[&edit_id=<id>]   choices for Assign Vessel / Request Survey (assign_vessel.php)
 *   GET app_api.php?a=admin_modules                 Admin Controls modules (config/admin_modules.php) for this role
 *   GET app_api.php?a=surveyor&id=<id>              Admin: a surveyor's details for the edit form (add_surveyor.php)
 *   GET app_api.php?a=templates                     Admin: Word templates + Photo Report template (manage_templates.php)
 *   GET app_api.php?a=duplicates                    Admin: possible duplicate vessels (possible_duplicates.php)
 *   GET app_api.php?a=insights                      dashboard extras as on index.php: recent recovery, averages,
 *                                                   surveyor report (Admin/Super Admin), analytics (Super Admin)
 *   GET app_api.php?a=month&y=<yyyy>&m=<1-12>       month summary (ships surveyed + recovery), as the dashboard card
 *   GET app_api.php?a=sa_controls                   Super Admin: record counts + duplicate client groups (super_admin_controls.php)
 *   GET app_api.php?a=surveyor_cards                Admin: each surveyor's ID / business card (admin_surveyor_cards.php)
 *   GET app_api.php?a=daily_emails                  Admin: daily email settings, today's runs, recent runs (admin_daily_emails.php)
 *   GET app_api.php?a=permission_copy               the Permission Copy form's choices and template (generate_permission_copy.php)
 */
require_once 'config/config.php';
require_once 'includes/notifications.php';
require_once 'includes/document_replace_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function appOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') appOut(['error' => 'GET only'], 405);
if (empty($_SESSION['user_id'])) appOut(['error' => 'login'], 401);

$db = getDB();
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? '');
$fullAccess = in_array($role, ['Admin', 'Super Admin'], true);
$clientId = $role === 'Client' ? getClientIdForUser($db, $userId) : 0;
$csrf = csrf_token(); // the app sends it with forms it posts to the existing pages
if (($_GET['a'] ?? '') === 'flash') {
    // Upload / form pages leave their result in the session for the next page; the app shows it.
    $flash = ['message' => (string)($_SESSION['flash_msg'] ?? ''), 'type' => (string)($_SESSION['flash_type'] ?? 'success'),
        // An upload queued the admin email (includes/upload_email_outbox.php); the app asks
        // ajax/upload_email_send.php to send it, as the website's footer does.
        'email_queued' => !empty($_SESSION['upload_email_outbox'])];
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
    session_write_close();
    appOut($flash);
}
session_write_close();

const APP_STATUSES = [
    'pending_vessel' => 'Pending Vessel',
    'pending_report' => 'Pending Report',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

/** [sql, params] limiting `surveys s` to what this user may see. */
function appScope(string $role, bool $fullAccess, int $userId, int $clientId): array
{
    if ($fullAccess) return ['1=1', []];
    if ($role === 'Client') return ['s.client_id = ?', [$clientId]];
    return ['(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))', [$userId, $userId]];
}

function appDate($v, string $fmt = 'd M Y'): string
{
    $v = (string)$v;
    if ($v === '' || strpos($v, '0000-00-00') === 0) return '';
    $t = strtotime($v);
    return $t ? date($fmt, $t) : '';
}

function appInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) $s .= mb_strtoupper(mb_substr($p, 0, 1));
    return $s !== '' ? $s : '?';
}

/** Same rule as includes/overdue_alert.php, loaded without its markup. */
function appOverdue(array $survey, string $role): ?array
{
    static $loaded = false;
    if (!$loaded) {
        (function () { ob_start(); include_once __DIR__ . '/includes/overdue_alert.php'; ob_end_clean(); })();
        $loaded = true;
    }
    return overdueAlertFor($survey, $role);
}

function appAvatar(PDO $db, int $userId): string
{
    try {
        $st = $db->prepare('SELECT avatar, profile_pic FROM users WHERE id = ?');
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['avatar', 'profile_pic'] as $k) {
            $v = (string)($r[$k] ?? '');
            if ($v !== '' && strpos($v, 'uploads/') === 0 && is_file(__DIR__ . '/' . $v)) return $v;
        }
    } catch (Throwable $e) { error_log('app_api avatar: ' . $e->getMessage()); }
    return '';
}

/**
 * Vessel pictures (includes/vessel_image.php — found by the AI from the vessel name, the same as the
 * website's cards): img = the picture to show (null = none yet), img_k / img_st = its key and state,
 * so the app can ask for the ones not looked up yet (ajax/vessel_image.php a=lookup).
 */
function appPics(PDO $db, array $items): array
{
    try {
        require_once __DIR__ . '/includes/vessel_image.php';
        $states = viStates($db, array_map(fn($i) => (string)$i['vessel'], $items));
        foreach ($items as &$it) {
            $k = viKey((string)$it['vessel']);
            $st = $states[$k] ?? ['status' => 'unknown'];
            $it['img_k'] = $k;
            $it['img_st'] = $st['status'];
            $it['img'] = $st['status'] === 'found' ? viImgUrl($k) : null;
        }
        unset($it);
    } catch (Throwable $e) { error_log('app_api pictures: ' . $e->getMessage()); }
    return $items;
}

function appCard(PDO $db, array $s, bool $fullAccess, string $role): array
{
    $over = appOverdue($s, $role);
    return [
        'id' => (int)$s['id'],
        'vessel' => (string)($s['vessel_name'] ?: 'Vessel'),
        'client' => (string)($s['company_name'] ?? ''),
        'agent' => (string)($s['agent_name'] ?? ''),
        'port' => (string)($s['port_name'] ?? ''),
        'type' => getCombinedSurveyTypeNames($db, $s['survey_type_ids'] ?? '', (string)($s['type_name'] ?? '')),
        'surveyors' => $fullAccess ? getCombinedSurveyorNames($db, $s['id'], (string)($s['surveyor_name'] ?? '')) : '',
        'appointment' => appDate($s['appointment_date'] ?? ''),
        'completed_on' => appDate($s['survey_completed_date'] ?? ''),
        'report_on' => appDate($s['report_uploaded_date'] ?? ''),
        'update' => (string)($s['custom_live_status'] ?? ''),
        'update_when' => !empty($s['status_updated_at']) ? formatLatestUpdateWhen((string)$s['status_updated_at']) : '',
        'update_by' => (string)($s['modifier_name'] ?? ''),
        'overdue' => $over ? ['kind' => $over['kind'], 'days' => $over['days']] : null,
        'status' => (string)$s['status'],
    ];
}

/** The WhatsApp / Email text vessel_detail.php builds for a new appointment. */
function appShareText(PDO $db, array $s): array
{
    $client = (string)($s['company_name'] ?? 'N/A');
    $lines = [
        'Vessel Name: ' . ($s['vessel_name'] ?? 'N/A'),
        'Report No: ' . (!empty($s['report_number']) ? $s['report_number'] : 'N/A'),
        'Client: ' . $client,
        'Survey Type: ' . getCombinedSurveyTypeNames($db, $s['survey_type_ids'] ?? '', $s['type_name'] ?? 'N/A'),
        'Assigned Date: ' . (!empty($s['assign_date']) ? date('d M Y', strtotime((string)$s['assign_date'])) : '--/--/----'),
        'Agent: ' . ($s['agent_name'] ?? 'N/A'),
        'Surveyor: ' . getCombinedSurveyorNames($db, $s['id'] ?? 0, $s['surveyor_name'] ?? 'N/A'),
        'Port: ' . ($s['port_name'] ?? 'N/A'),
        'Status: ' . ($s['status'] ?? 'N/A'),
    ];
    if (!empty($s['custom_live_status'])) $lines[] = 'Latest Update: ' . $s['custom_live_status'];
    if (!empty($s['remarks'])) $lines[] = 'Admin Remarks: ' . $s['remarks'];
    $block = implode("\n", $lines);
    $wa = "*New Survey Appointment*\n\n" . $block;
    $num = preg_replace('/\D+/', '', WHATSAPP_NUMBER);
    return [
        'whatsapp' => $num !== '' ? 'https://wa.me/' . $num . '?text=' . rawurlencode($wa) : 'https://api.whatsapp.com/send?text=' . rawurlencode($wa),
        'subject' => 'New Survey Appointment - ' . ($s['vessel_name'] ?? 'Vessel') . ' (' . $client . ')',
        'body' => "Dear Sir,\n\nGood day to you.\n\nPlease find the below appointment from client (" . $client . ").\n\n" . $block . "\n\nThank you.\nYMR Marine Solutions",
    ];
}

/** Recovery figures and whether this user may edit them (Admin, report / completed detail pages). */
function appRecovery(PDO $db, array $s, string $role): ?array
{
    if (!in_array($s['status'], ['Pending Report', 'Completed'], true)) return null;
    $na = isRecoveryNotApplicable($db, (string)($s['survey_type_ids'] ?? ''), (string)($s['type_name'] ?? ''));
    $v = function ($k) use ($s) { return $s[$k] !== null && $s[$k] !== '' ? number_format(abs((float)$s[$k]), 3, '.', '') : ''; };
    return ['na' => $na, 'total' => $v('recovery_amount'), 'vlsfo' => $v('vlsfo_recovery'), 'lsmgo' => $v('lsmgo_recovery'), 'hsfo' => $v('hsfo_recovery'),
        'can_edit' => $role === 'Admin' && !$na];
}

function appRemarks(PDO $db, array $s, string $role, int $userId): array
{
    require_once __DIR__ . '/includes/completed_remarks_helpers.php';
    $items = [];
    foreach (getCompletedRemarks($db, (int)$s['id']) as $r) {
        $items[] = ['id' => (int)$r['id'], 'text' => (string)$r['remarks'], 'by' => (string)($r['author_name'] ?? ''), 'when' => appDate($r['created_at'] ?? '', 'd M Y, h:i A'),
            'files' => array_map(function ($f) { return ['id' => (int)$f['id'], 'name' => (string)($f['original_name'] ?? $f['file_name'] ?? 'File')]; }, $r['attachments'] ?? [])];
    }
    return ['items' => $items, 'can_add' => canAddCompletedRemark($db, $s, $role, $userId), 'can_delete' => canDeleteCompletedRemark($role)];
}

$a = (string)($_GET['a'] ?? '');
[$scopeSql, $scopeParams] = appScope($role, $fullAccess, $userId, $clientId);

if ($a === 'me') {
    $name = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
    $unread = 0;
    if (in_array($role, ['Admin', 'Surveyor'], true)) {
        try { $unread = (int)getUnreadNotificationCount($db, $userId); } catch (Throwable $e) { $unread = 0; }
    }
    appOut([
        'id' => (int)$userId,
        'name' => $name,
        'first' => explode(' ', trim($name))[0] ?? '',
        'initials' => appInitials($name),
        'role' => $role,
        'avatar' => appAvatar($db, $userId),
        'greeting' => getGreeting(),
        'unread' => $unread,
        'csrf' => $csrf,
        'can' => [
            'assign' => in_array($role, ['Admin', 'Client'], true),
            'inbox' => in_array($role, ['Admin', 'Surveyor'], true),
            'chat' => in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true),
            'recovery' => $role !== 'Client',
            'report_generator' => in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true),
            // pages this server has (the same app opens staging and live)
            'survey_reports' => in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true) && is_file(__DIR__ . '/survey_reports.php'),
            'live_survey' => $role === 'Admin' && is_file(__DIR__ . '/live_survey_reports.php'),
            'report_templates' => $role === 'Admin' && is_file(__DIR__ . '/admin_report_templates.php'),
        ],
    ]);
}

if ($a === 'dashboard') {
    require_once 'includes/recovery_list.php';
    $counts = [];
    foreach (APP_STATUSES as $key => $status) {
        $st = $db->prepare("SELECT COUNT(*) FROM surveys s WHERE s.status = ? AND $scopeSql");
        $st->execute(array_merge([$status], $scopeParams));
        $counts[$key] = (int)$st->fetchColumn();
    }
    $cmStart = date('Y-m-01');
    $cmEnd = date('Y-m-01', strtotime('first day of next month'));
    $recBase = "s.status IN ('Pending Report','Completed') AND s.recovery_amount IS NOT NULL AND " . recoveryAppliesSql('s') . " AND $scopeSql";
    $st = $db->prepare("SELECT SUM(ABS(s.recovery_amount)) FROM surveys s WHERE $recBase");
    $st->execute($scopeParams);
    $recTotal = (float)$st->fetchColumn();
    $cd = surveyCompletedDateSql('s');
    $st = $db->prepare("SELECT SUM(ABS(s.recovery_amount)) FROM surveys s WHERE $recBase AND $cd >= ? AND $cd < ?");
    $st->execute(array_merge($scopeParams, [$cmStart, $cmEnd]));
    $recMonth = (float)$st->fetchColumn();
    $st = $db->prepare("SELECT COUNT(*) FROM surveys s WHERE s.status IN ('Pending Report','Completed') AND $scopeSql AND $cd >= ? AND $cd < ?");
    $st->execute(array_merge($scopeParams, [$cmStart, $cmEnd]));
    $shipsMonth = (int)$st->fetchColumn();

    // Needs attention: the same overdue rule as the detail pages, open surveys only.
    $attention = [];
    if (in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)) {
        $st = $db->prepare("
            SELECT s.*, c.company_name, p.port_name, st.type_name
            FROM surveys s
            JOIN clients c ON s.client_id = c.id
            LEFT JOIN ports p ON s.port_id = p.id
            LEFT JOIN survey_types st ON s.survey_type_id = st.id
            WHERE s.status IN ('Pending Vessel','Pending Report') AND $scopeSql
              AND ((s.status = 'Pending Vessel' AND s.appointment_date IS NOT NULL AND s.appointment_date <= ?)
                OR (s.status = 'Pending Report' AND s.survey_completed_date IS NOT NULL AND DATE(s.survey_completed_date) <= ?))
            ORDER BY s.id DESC LIMIT 60");
        $st->execute(array_merge($scopeParams, [date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('-4 days'))]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $over = appOverdue($row, $role);
            if (!$over) continue;
            $attention[] = ['id' => (int)$row['id'], 'vessel' => (string)$row['vessel_name'], 'status' => (string)$row['status'],
                'kind' => $over['kind'], 'days' => $over['days'], 'port' => (string)($row['port_name'] ?? '')];
        }
        usort($attention, function ($x, $y) { return $y['days'] <=> $x['days']; });
        $attention = array_slice($attention, 0, 5);
    }
    appOut([
        'counts' => $counts,
        'recovery_total' => round($recTotal, 3),
        'recovery_month' => round($recMonth, 3),
        'ships_month' => $shipsMonth,
        'month' => date('F Y'),
        'attention' => $attention,
        'today' => date('d M Y'),
    ]);
}

if ($a === 'month') {
    require_once 'includes/recovery_list.php';
    require_once 'includes/dashboard_month.php';
    $y = (int)($_GET['y'] ?? date('Y'));
    $m = (int)($_GET['m'] ?? date('n'));
    if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) appOut(['error' => 'month'], 400);
    $dm = dashboardMonthStats($db, $role, $userId, $clientId, $y, $m);
    appOut(['y' => $y, 'm' => $m, 'label' => $dm['label'], 'ships' => $dm['ships'], 'recovery' => round($dm['recovery'], 3),
        'years' => dashboardMonthYears($db)]);
}

if ($a === 'insights') {
    // The website dashboard's (index.php) extra cards, same queries and the same role scope.
    require_once 'includes/recovery_list.php';
    $applies = recoveryAppliesSql('s');
    $out = [];
    // Recent survey recovery: the latest vessel with recovery.
    $st = $db->prepare("SELECT s.id, s.vessel_name, s.survey_type_ids, s.vlsfo_recovery, s.lsmgo_recovery, s.hsfo_recovery, s.recovery_amount
        FROM surveys s WHERE s.status IN ('Pending Report','Completed') AND s.recovery_amount IS NOT NULL AND $applies AND $scopeSql
        ORDER BY s.survey_completed_date DESC, s.id DESC LIMIT 1");
    $st->execute($scopeParams);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $on = isOnhireSurvey($db, (string)($r['survey_type_ids'] ?? ''));
        $fmt = function ($v) use ($on) { return !empty($v) ? recoveryDisplay($v, $on) : '0.000'; };
        $out['recent'] = ['id' => (int)$r['id'], 'vessel' => (string)$r['vessel_name'], 'vlsfo' => $fmt($r['vlsfo_recovery']),
            'lsmgo' => $fmt($r['lsmgo_recovery']), 'hsfo' => $fmt($r['hsfo_recovery'])];
    } else {
        $out['recent'] = null;
    }
    // Average ships per month: completed ships over the months since the first one.
    $st = $db->prepare("SELECT COUNT(*) FROM surveys s WHERE s.status = 'Completed' AND $scopeSql");
    $st->execute($scopeParams);
    $ships = (float)$st->fetchColumn();
    $st = $db->prepare("SELECT GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(s.survey_completed_date, s.report_uploaded_date, s.assign_date)), CURDATE()) + 1)
        FROM surveys s WHERE s.status IN ('Completed','Pending Report') AND $scopeSql");
    $st->execute($scopeParams);
    $span = (float)$st->fetchColumn();
    $out['avg_ships_month'] = $span > 0 ? round($ships / $span, 1) : 0;
    if ($fullAccess) {
        $out['avg_rec_ship'] = round((float)$db->query("SELECT AVG(ABS(s.recovery_amount)) FROM surveys s WHERE s.status IN ('Pending Report','Completed') AND s.recovery_amount IS NOT NULL AND $applies AND ABS(s.recovery_amount) > 0")->fetchColumn(), 3);
        $out['avg_rec_surveyor'] = round((float)$db->query("SELECT AVG(t.v) FROM (SELECT SUM(ABS(s.recovery_amount)) AS v FROM surveys s WHERE s.status IN ('Pending Report','Completed') AND s.recovery_amount IS NOT NULL AND $applies GROUP BY s.surveyor_id) t")->fetchColumn(), 3);

        // Surveyor full report: ships attended + recovery, as of now and this month.
        $cmStart = date('Y-m-01');
        $cmEnd = date('Y-m-01', strtotime('first day of next month'));
        $rec = recoveryCountedSql('s');
        $done = "s.status IN ('Pending Report','Completed')";
        $cd = surveyCompletedDateSql('s');
        $inMonth = "$cd >= '$cmStart' AND $cd < '$cmEnd'";
        $rows = $db->query("SELECT u.id, u.full_name, u.status AS user_status,
                COUNT(DISTINCT CASE WHEN $done THEN s.id END) AS ships_total,
                COUNT(DISTINCT CASE WHEN $done AND $inMonth THEN s.id END) AS ships_month,
                COALESCE(SUM(CASE WHEN $done THEN $rec END), 0) AS recovery_total,
                COALESCE(SUM(CASE WHEN $done AND $inMonth THEN $rec END), 0) AS recovery_month
            FROM users u
            LEFT JOIN surveys s ON (s.surveyor_id = u.id OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = u.id))
            WHERE u.role_id = 2 GROUP BY u.id, u.full_name, u.status ORDER BY u.full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $noSv = RECOVERY_NO_SURVEYOR_SQL;
        $un = $db->query("SELECT COUNT(*) AS ships_total, COUNT(CASE WHEN $inMonth THEN 1 END) AS ships_month,
                COALESCE(SUM($rec), 0) AS recovery_total, COALESCE(SUM(CASE WHEN $inMonth THEN $rec END), 0) AS recovery_month
            FROM surveys s WHERE $done AND $noSv")->fetch(PDO::FETCH_ASSOC);
        [$creditId, $creditName] = outsourcedCreditSurveyor($db);
        if ($creditId === -1) {
            $rows[] = ['id' => -1, 'full_name' => $creditName, 'user_status' => 'Active', 'ships_total' => 0, 'ships_month' => 0, 'recovery_total' => 0, 'recovery_month' => 0];
            usort($rows, function ($x, $y) { return strcasecmp($x['full_name'], $y['full_name']); });
        }
        $k4 = ['ships_total', 'ships_month', 'recovery_total', 'recovery_month'];
        $out['surveyors'] = array_map(function ($row) use ($creditId, $un, $k4) {
            if ((int)$row['id'] === $creditId) foreach ($k4 as $k) $row[$k] += $un[$k];
            return ['id' => (int)$row['id'], 'name' => (string)$row['full_name'], 'inactive' => ($row['user_status'] ?? '') === 'Inactive',
                'ships_total' => (int)$row['ships_total'], 'ships_month' => (int)$row['ships_month'],
                'recovery_total' => round((float)$row['recovery_total'], 3), 'recovery_month' => round((float)$row['recovery_month'], 3)];
        }, $rows);
        $t = $db->query("SELECT COUNT(*) AS ships_total, COUNT(CASE WHEN $inMonth THEN 1 END) AS ships_month,
                COALESCE(SUM($rec), 0) AS recovery_total, COALESCE(SUM(CASE WHEN $inMonth THEN $rec END), 0) AS recovery_month
            FROM surveys s WHERE $done")->fetch(PDO::FETCH_ASSOC);
        $out['surveyors_total'] = ['ships_total' => (int)$t['ships_total'], 'ships_month' => (int)$t['ships_month'],
            'recovery_total' => round((float)$t['recovery_total'], 3), 'recovery_month' => round((float)$t['recovery_month'], 3)];
    }
    if ($role === 'Super Admin') {
        // Super Admin analytics: top lists and average ships per year / month / day / surveyor.
        ensurePortsCountryColumn($db);
        $base = "s.recovery_amount IS NOT NULL AND s.status IN ('Pending Report','Completed') AND $applies";
        $top = function (string $sql) use ($db) {
            return array_map(function ($r) { return ['name' => (string)$r['name'], 'value' => round((float)$r['value'], 3)]; }, $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        };
        $country = "COALESCE(NULLIF(p.country, ''), 'India')";
        $sa = [
            'rec_surveyor' => $top("SELECT u.full_name AS name, SUM(ABS(s.recovery_amount)) AS value FROM surveys s JOIN users u ON s.surveyor_id = u.id WHERE $base GROUP BY s.surveyor_id, u.full_name ORDER BY value DESC LIMIT 10"),
            'rec_port' => $top("SELECT p.port_name AS name, SUM(ABS(s.recovery_amount)) AS value FROM surveys s JOIN ports p ON s.port_id = p.id WHERE $base GROUP BY s.port_id, p.port_name ORDER BY value DESC LIMIT 5"),
            'rec_country' => $top("SELECT $country AS name, SUM(ABS(s.recovery_amount)) AS value FROM surveys s JOIN ports p ON s.port_id = p.id WHERE $base GROUP BY $country ORDER BY value DESC LIMIT 5"),
            'rec_client' => $top("SELECT c.company_name AS name, SUM(ABS(s.recovery_amount)) AS value FROM surveys s JOIN clients c ON s.client_id = c.id WHERE $base GROUP BY s.client_id, c.company_name ORDER BY value DESC LIMIT 5"),
            'ships_country' => $top("SELECT $country AS name, COUNT(*) AS value FROM surveys s JOIN ports p ON s.port_id = p.id WHERE s.status = 'Completed' GROUP BY $country ORDER BY value DESC LIMIT 5"),
            'ships_port' => $top("SELECT p.port_name AS name, COUNT(*) AS value FROM surveys s JOIN ports p ON s.port_id = p.id WHERE s.status = 'Completed' GROUP BY s.port_id, p.port_name ORDER BY value DESC LIMIT 5"),
        ];
        $span = $db->query("SELECT COUNT(*) AS cnt, MIN(COALESCE(survey_completed_date, report_uploaded_date)) AS min_d,
                MAX(COALESCE(survey_completed_date, report_uploaded_date)) AS max_d FROM surveys WHERE status = 'Completed'")->fetch(PDO::FETCH_ASSOC);
        $n = (int)($span['cnt'] ?? 0);
        $sa['avg_year'] = $sa['avg_month'] = $sa['avg_day'] = 0;
        if ($n > 0 && !empty($span['min_d']) && !empty($span['max_d'])) {
            $lo = strtotime($span['min_d']); $hi = strtotime($span['max_d']);
            $sa['avg_day'] = round($n / max(1, (int)round(($hi - $lo) / 86400) + 1), 1);
            $sa['avg_month'] = round($n / max(1, ((int)date('Y', $hi) - (int)date('Y', $lo)) * 12 + ((int)date('n', $hi) - (int)date('n', $lo)) + 1), 1);
            $sa['avg_year'] = round($n / max(1, (int)date('Y', $hi) - (int)date('Y', $lo) + 1), 1);
        }
        $sa['avg_surveyor'] = round((float)$db->query("SELECT AVG(t.cnt) FROM (SELECT COUNT(*) AS cnt FROM surveys WHERE status = 'Completed' GROUP BY surveyor_id) t")->fetchColumn(), 1);
        $out['sa'] = $sa;
    }
    appOut($out);
}

if ($a === 'list') {
    $key = (string)($_GET['status'] ?? '');
    if (!isset(APP_STATUSES[$key])) appOut(['error' => 'status'], 400);
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 30;
    $where = ['s.status = ?', $scopeSql];
    $params = array_merge([APP_STATUSES[$key]], $scopeParams);
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($_GET['overdue'])) {
        // Same thresholds as includes/overdue_alert.php (7 days / 4 days); open surveys only.
        if ($key === 'pending_vessel') { $where[] = 's.appointment_date IS NOT NULL AND s.appointment_date <= ?'; $params[] = date('Y-m-d', strtotime('-7 days')); }
        elseif ($key === 'pending_report') { $where[] = 'YEAR(s.survey_completed_date) > 0 AND DATE(s.survey_completed_date) <= ?'; $params[] = date('Y-m-d', strtotime('-4 days')); }
        else $where[] = '1=0';
    }
    if ($key === 'cancelled' && $role === 'Super Admin') {
        // cancelled.php shows every cancelled vessel to Admin only; others see their own.
        $where[1] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        $params = array_merge([APP_STATUSES[$key]], [$userId, $userId], array_slice($params, 1 + count($scopeParams)));
    }
    $whereSql = implode(' AND ', $where);
    $from = "FROM surveys s
        JOIN clients c ON s.client_id = c.id
        " . ($key === 'cancelled' ? 'LEFT JOIN' : 'JOIN') . " users u ON s.surveyor_id = u.id
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id";
    $st = $db->prepare("SELECT COUNT(*) $from WHERE $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $order = $key === 'completed' ? SURVEY_REPORT_ORDER_SQL : SURVEY_APPOINTMENT_ORDER_SQL;
    $offset = ($page - 1) * $per;
    $st = $db->prepare("SELECT s.*, c.company_name, u.full_name AS surveyor_name, uu.full_name AS modifier_name, st.type_name, p.port_name
        $from WHERE $whereSql ORDER BY $order DESC, s.id DESC LIMIT $per OFFSET $offset");
    $st->execute($params);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $items[] = appCard($db, $row, $fullAccess, $role);
    $items = appPics($db, $items);
    appOut(['total' => $total, 'page' => $page, 'more' => $offset + count($items) < $total, 'items' => $items]);
}

if ($a === 'survey') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT s.*, c.company_name, u.full_name AS surveyor_name, uu.full_name AS modifier_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.id = ?");
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    $allowed = $s && ($fullAccess || isSurveyorAssignedToSurvey($db, $id, $userId)
        || ($role === 'Client' && (int)$s['client_id'] === $clientId && $clientId > 0));
    if (!$allowed) appOut(['error' => 'not_found'], 404);

    $card = appPics($db, [appCard($db, $s, true, $role)])[0];
    $docs = [];
    try {
        $ds = $db->prepare('SELECT id, file_name, file_type, file_size, uploaded_at FROM uploads WHERE survey_id = ? ORDER BY id ASC');
        $ds->execute([$id]);
        foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $docs[] = ['id' => (int)$d['id'], 'name' => (string)$d['file_name'], 'type' => (string)$d['file_type'],
                'size' => (string)($d['file_size'] ?? ''), 'when' => appDate($d['uploaded_at'] ?? '', 'd M Y')];
        }
    } catch (Throwable $e) { error_log('app_api docs: ' . $e->getMessage()); }

    $page = ['Pending Vessel' => 'vessel_detail.php', 'Pending Report' => 'report_detail.php', 'Completed' => 'completed_detail.php'][$s['status']] ?? 'vessel_detail.php';
    $assigned = $role === 'Surveyor' && isSurveyorAssignedToSurvey($db, $id, $userId);
    // What the upload screen needs (rules of ajax/upload_handler.php and the detail pages' popups).
    $types = array_column($docs, 'type');
    $uploadKind = null;
    if ($role === 'Admin' || $assigned) {
        if ($s['status'] === 'Pending Vessel') $uploadKind = 'vessel';
        elseif ($s['status'] === 'Pending Report') $uploadKind = 'report';
    }
    $card += [
        'report_no' => (string)($s['report_number'] ?? ''),
        'imo' => (string)($s['imo_number'] ?? ''),
        'assigned_on' => appDate($s['assign_date'] ?? ''),
        'remarks' => (string)($s['remarks'] ?? ''),
        'docs' => $docs,
        'web' => $page . '?id=' . $id,
        'can_upload' => $uploadKind !== null,
        'upload' => $uploadKind === null ? null : [
            'kind' => $uploadKind,
            'has_pdf' => in_array('Formal Report PDF', $types, true),
            'has_excel' => in_array('Formal Report Excel', $types, true),
            'recovery_na' => isRecoveryNotApplicable($db, (string)($s['survey_type_ids'] ?? ''), (string)($s['type_name'] ?? '')),
            'today' => date('Y-m-d'),
            'min_date' => ($uploadKind === 'report' && ($d = appDate($s['survey_completed_date'] ?? '', 'Y-m-d')) !== '') ? $d : '',
        ],
        // Same as the Latest Update form on vessel_detail.php (shown to everyone who can open it).
        'can_update' => $s['status'] === 'Pending Vessel',
        // vessel_detail.php shows previous surveys (Pending Vessel) to Admin / Super Admin / Surveyor.
        'can_history' => $s['status'] === 'Pending Vessel' && ($fullAccess || $assigned),
        // Extra tools of the detail pages:
        'share' => $s['status'] === 'Pending Vessel' ? appShareText($db, $s) : null,                  // vessel_detail.php WhatsApp / Email
        'agent_email' => $s['status'] === 'Pending Vessel' && $role === 'Admin' && trim((string)$s['agent_name']) !== ''
            ? ['sent' => !empty($s['agent_email_sent_at']) ? date('d M Y, h:i A', strtotime((string)$s['agent_email_sent_at'])) : ''] : null,
        'recovery' => appRecovery($db, $s, $role),                                                 // report / completed detail
        'can_replace' => canReplaceDocument($db, $role, $userId, $id),                             // ajax/document_replace.php
        'remarks_list' => $s['status'] === 'Completed' ? appRemarks($db, $s, $role, $userId) : null, // completed_detail.php
    ];
    appOut($card);
}

if ($a === 'inbox') {
    if (!in_array($role, ['Admin', 'Surveyor'], true)) appOut(['error' => 'not_allowed'], 403);
    $unreadOnly = !empty($_GET['unread']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 30;
    $rows = getInboxNotifications($db, $userId, $per, ($page - 1) * $per, $unreadOnly);
    $total = $unreadOnly ? getUnreadNotificationCount($db, $userId) : getNotificationCount($db, $userId);
    $items = [];
    foreach ($rows as $n) {
        $link = safeNotificationLink($n['link'] ?? null);
        $sid = ($link && preg_match('~^(vessel_detail|report_detail|completed_detail)\.php\?id=(\d+)$~', $link, $m)) ? (int)$m[2] : 0;
        $items[] = ['id' => (int)$n['id'], 'type' => (string)$n['type'], 'title' => (string)$n['title'], 'message' => (string)$n['message'],
            'read' => (bool)$n['is_read'], 'ago' => notificationTimeAgo($n['created_at'] ?? null), 'from' => (string)($n['creator_name'] ?? ''),
            'link' => $link, 'survey_id' => $sid];
    }
    appOut(['total' => $total, 'unread' => getUnreadNotificationCount($db, $userId), 'more' => ($page * $per) < $total, 'items' => $items]);
}

if ($a === 'recovery') {
    require_once 'includes/recovery_list.php';
    $f = recoveryListFilters($_GET, $fullAccess);
    $f['sort'] = 'newest';
    [$whereSql, $params, $orderSql] = recoveryListWhere($role, $userId, $clientId, $f, outsourcedCreditSurveyor($db)[0]);
    $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(" . recoveryCountedSql('s', 'vlsfo_recovery') . "),0) AS vlsfo,
        COALESCE(SUM(" . recoveryCountedSql('s', 'lsmgo_recovery') . "),0) AS lsmgo, COALESCE(SUM(" . recoveryCountedSql('s', 'hsfo_recovery') . "),0) AS hsfo,
        COALESCE(SUM(" . recoveryCountedSql('s') . "),0) AS total " . RECOVERY_LIST_FROM . " WHERE $whereSql");
    $st->execute($params);
    $tot = $st->fetch(PDO::FETCH_ASSOC);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 30;
    $offset = ($page - 1) * $per;
    $st = $db->prepare("SELECT " . RECOVERY_LIST_COLUMNS . " " . RECOVERY_LIST_FROM . " WHERE $whereSql ORDER BY $orderSql LIMIT $per OFFSET $offset");
    $st->execute($params);
    $rows = array_map(function ($r) use ($db) {
        $x = recoveryListRow($db, $r);
        $x['total_text'] = $x['na'] ? 'N/A' : ($x['total'] !== null ? recoveryDisplay($x['total'], $x['onhire']) : '—');
        foreach (['vlsfo', 'lsmgo', 'hsfo'] as $k) $x[$k . '_text'] = $x['na'] ? 'N/A' : ($x[$k] !== null ? recoveryDisplay($x[$k], $x['onhire']) : '—');
        return $x;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    [$scopeWhere, $scopeP] = recoveryListScope($role, $userId, $clientId);
    $cd = surveyCompletedDateSql('s');
    $st = $db->prepare("SELECT DATE_FORMAT($cd, '%Y-%m') AS m " . RECOVERY_LIST_FROM . " WHERE " . implode(' AND ', $scopeWhere) . " AND $cd IS NOT NULL GROUP BY m ORDER BY m DESC");
    $st->execute($scopeP);
    $months = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) {
        if (preg_match('/^\d{4}-\d{2}$/', (string)$m) && strpos((string)$m, '0000') !== 0) $months[] = ['value' => $m, 'label' => date('M Y', strtotime($m . '-01'))];
    }
    appOut([
        'count' => (int)$tot['n'], 'vlsfo' => round((float)$tot['vlsfo'], 3), 'lsmgo' => round((float)$tot['lsmgo'], 3),
        'hsfo' => round((float)$tot['hsfo'], 3), 'total' => round((float)$tot['total'], 3),
        'months' => $months, 'more' => $offset + count($rows) < (int)$tot['n'], 'items' => $rows,
    ]);
}

if ($a === 'formats') {
    // Same files and order as formats_download.php (formats/ folder, newest of each name, recent first).
    $files = [];
    $dir = __DIR__ . '/formats';
    foreach (is_dir($dir) ? scandir($dir) : [] as $file) {
        if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['xls', 'xlsx', 'xlsm'], true)) continue;
        $mtime = (int)@filemtime($dir . '/' . $file);
        $name = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', preg_replace('/^\d+_/', '', pathinfo($file, PATHINFO_FILENAME)))));
        $key = strtolower($name);
        if (!isset($files[$key]) || $mtime >= $files[$key]['mtime']) {
            $files[$key] = ['name' => $name, 'path' => 'formats/' . $file, 'mtime' => $mtime];
        }
    }
    $week = time() - 7 * 86400;
    $out = [];
    foreach ($files as $f) {
        $out[] = ['name' => $f['name'], 'path' => $f['path'], 'updated' => $f['mtime'] ? date('d M Y, h:i A', $f['mtime']) : '', 'recent' => $f['mtime'] >= $week];
    }
    usort($out, function ($x, $y) { return ($y['recent'] <=> $x['recent']) ?: strcasecmp($x['name'], $y['name']); });
    appOut(['items' => $out]);
}

if ($a === 'profile') {
    $st = $db->prepare('SELECT username, full_name, first_name, last_name, dob, email, phone FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $full = (string)($u['full_name'] ?? '');
    $parts = preg_split('/\s+/', trim($full), 2) ?: [''];
    appOut([
        'username' => (string)($u['username'] ?? ''),
        'full_name' => $full,
        'first_name' => (string)($u['first_name'] ?? '') !== '' ? (string)$u['first_name'] : ($parts[0] ?? ''),
        'last_name' => (string)($u['last_name'] ?? '') !== '' ? (string)$u['last_name'] : ($parts[1] ?? ''),
        'dob' => appDate($u['dob'] ?? '', 'Y-m-d'),
        'email' => (string)($u['email'] ?? ''),
        'phone' => (string)($u['phone'] ?? ''),
        'avatar' => appAvatar($db, $userId),
        // profile.php: Surveyors edit first / last name and date of birth; everyone else full name, email, phone.
        'surveyor_form' => $role === 'Surveyor',
    ]);
}

if ($a === 'history') {
    require_once 'includes/vessel_history.php';
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare('SELECT * FROM surveys WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    // Same as vessel_detail.php: Pending Vessel, Admin / Super Admin or the assigned Surveyor.
    if (!$s || $s['status'] !== 'Pending Vessel' || !($fullAccess || ($role === 'Surveyor' && isSurveyorAssignedToSurvey($db, $id, $userId)))) {
        appOut(['error' => 'not_found'], 404);
    }
    $out = [];
    foreach (vesselHistory($db, $s) as $h) {
        $rec = function ($v) use ($h) { return $h['recovery_na'] ? 'N/A' : ($v !== null ? recoveryDisplay(abs((float)$v), (bool)$h['recovery_onhire']) : '—'); };
        $out[] = [
            'id' => (int)$h['id'], 'vessel' => (string)$h['vessel_name'], 'status' => (string)$h['status'],
            'report_no' => (string)($h['report_number'] ?? ''), 'type' => (string)$h['types'], 'client' => (string)($h['company_name'] ?? ''),
            'port' => (string)($h['port_name'] ?? ''), 'surveyors' => (string)$h['surveyors'],
            'completed_on' => appDate($h['survey_completed_date'] ?? ''), 'report_on' => appDate($h['report_uploaded_date'] ?? ''),
            'recovery' => ['total' => $rec($h['recovery_amount']), 'vlsfo' => $rec($h['vlsfo_recovery']), 'lsmgo' => $rec($h['lsmgo_recovery']), 'hsfo' => $rec($h['hsfo_recovery'])],
            'docs' => array_map(function ($f) { return ['id' => (int)$f['id'], 'name' => (string)$f['file_name'], 'type' => (string)$f['file_type'], 'when' => appDate($f['uploaded_at'] ?? '')]; }, $h['files']),
        ];
    }
    appOut(['vessel' => (string)$s['vessel_name'], 'items' => $out]);
}

if ($a === 'assign_form') {
    // Same choices assign_vessel.php offers; saving still posts to assign_vessel.php.
    if (!in_array($role, ['Admin', 'Client'], true)) appOut(['error' => 'not_allowed'], 403);
    $editId = (int)($_GET['edit_id'] ?? 0);
    if ($editId > 0 && $role !== 'Admin') appOut(['error' => 'not_allowed'], 403);
    $out = ['role' => $role, 'edit' => null];
    if ($role === 'Client') {
        $st = $db->prepare('SELECT id, company_name FROM clients WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        $out['clients'] = $c ? [['id' => (int)$c['id'], 'name' => (string)$c['company_name']]] : [];
        $out['surveyors'] = [];
    } else {
        $out['clients'] = array_map(function ($c) { return ['id' => (int)$c['id'], 'name' => (string)$c['company_name']]; },
            $db->query('SELECT id, company_name FROM clients ORDER BY company_name ASC')->fetchAll(PDO::FETCH_ASSOC));
        $out['surveyors'] = array_map(function ($u) { return ['id' => (int)$u['id'], 'name' => (string)$u['full_name']]; },
            $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC));
    }
    $hasCountry = (bool)$db->query("SHOW COLUMNS FROM ports LIKE 'country'")->fetch();
    $out['ports'] = array_map(function ($p) { return ['id' => (int)$p['id'], 'name' => (string)$p['port_name'], 'country' => (string)($p['country'] ?? '') ?: 'India']; },
        $db->query('SELECT id, port_name' . ($hasCountry ? ', country' : '') . ' FROM ports ORDER BY ' . ($hasCountry ? 'country ASC, ' : '') . 'port_name ASC')->fetchAll(PDO::FETCH_ASSOC));
    $out['types'] = array_map(function ($t) { return ['id' => (int)$t['id'], 'name' => (string)$t['type_name']]; },
        $db->query('SELECT id, type_name FROM survey_types ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC));
    if ($editId > 0) {
        $st = $db->prepare('SELECT * FROM surveys WHERE id = ?');
        $st->execute([$editId]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) appOut(['error' => 'not_found'], 404);
        $ids = [];
        try {
            $ss = $db->prepare('SELECT surveyor_id FROM survey_surveyors WHERE survey_id = ? ORDER BY id ASC');
            $ss->execute([$editId]);
            $ids = array_map('intval', $ss->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $x) { $ids = []; }
        if (!$ids && (int)$e['surveyor_id'] > 1) $ids = [(int)$e['surveyor_id']];
        $out['edit'] = [
            'id' => $editId, 'vessel' => (string)$e['vessel_name'], 'report_no' => (string)($e['report_number'] ?? ''),
            'client_id' => (int)$e['client_id'], 'agent' => (string)$e['agent_name'], 'port_id' => (int)$e['port_id'],
            'type_ids' => array_values(array_filter(array_map('intval', explode(',', (string)($e['survey_type_ids'] ?: $e['survey_type_id']))))),
            'surveyor_ids' => $ids, 'appointment' => appDate($e['appointment_date'] ?? '', 'Y-m-d'), 'remarks' => (string)($e['remarks'] ?? ''),
        ];
    }
    appOut($out);
}

if ($a === 'admin_modules') {
    // ajax/admin_master.php rules: Admin every module, Super Admin only Clients.
    if (!in_array($role, ['Admin', 'Super Admin'], true)) appOut(['error' => 'not_allowed'], 403);
    $mods = [];
    foreach (require __DIR__ . '/config/admin_modules.php' as $key => $m) {
        if ($role !== 'Admin' && $key !== 'clients') continue;
        $mods[] = ['key' => $key, 'label' => $m['label'], 'singular' => $m['singular'], 'name_field' => $m['name_field'],
            'fields' => array_map(function ($f) { return array_intersect_key($f, array_flip(['name', 'label', 'type', 'required', 'options', 'hint', 'readonly'])); }, $m['fields'])];
    }
    appOut(['modules' => $mods]);
}

if ($a === 'surveyor') {
    if ($role !== 'Admin') appOut(['error' => 'not_allowed'], 403);
    $st = $db->prepare('SELECT * FROM users WHERE id = ? AND role_id = 2');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) appOut(['error' => 'not_found'], 404);
    $parts = preg_split('/\s+/', trim((string)$u['full_name']), 2) ?: [''];
    appOut([
        'id' => (int)$u['id'], 'username' => (string)$u['username'],
        'first_name' => (string)($u['first_name'] ?? '') !== '' ? (string)$u['first_name'] : ($parts[0] ?? ''),
        'last_name' => (string)($u['last_name'] ?? '') !== '' ? (string)$u['last_name'] : ($parts[1] ?? ''),
        'dob' => appDate($u['dob'] ?? '', 'Y-m-d'), 'date_of_joining' => appDate($u['date_of_joining'] ?? '', 'Y-m-d'),
        'phone' => (string)($u['phone'] ?? ''), 'email' => (string)($u['email'] ?? ''), 'status' => (string)($u['status'] ?? 'Active'),
        'avatar' => appAvatar($db, (int)$u['id']),
    ]);
}

if ($a === 'templates') {
    if ($role !== 'Admin') appOut(['error' => 'not_allowed'], 403);
    $rows = [];
    try {
        foreach ($db->query('SELECT id, template_name, survey_type, file_name, created_at FROM word_templates ORDER BY template_name ASC')->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $rows[] = ['id' => (int)$t['id'], 'name' => (string)$t['template_name'], 'type' => (string)($t['survey_type'] ?? ''),
                'file' => (string)$t['file_name'], 'when' => appDate($t['created_at'] ?? '')];
        }
    } catch (Throwable $e) { $rows = []; }
    $photo = null;
    $pf = __DIR__ . '/uploads/photo_report_templates/YMR_Photo_Report_Template.docx';
    if (is_file($pf)) {
        $meta = json_decode((string)@file_get_contents(dirname($pf) . '/meta.json'), true);
        $photo = ['name' => is_array($meta) && !empty($meta['name']) ? $meta['name'] : basename($pf),
            'when' => appDate(is_array($meta) && !empty($meta['updated_at']) ? $meta['updated_at'] : date('Y-m-d H:i:s', filemtime($pf)), 'd M Y, h:i A')];
    }
    appOut(['items' => $rows, 'photo' => $photo]);
}

if ($a === 'sa_controls') {
    // Read-only view for the app's Super Admin controls screen; the merge / delete actions post to
    // super_admin_controls.php itself, so its confirmations and checks apply unchanged.
    if ($role !== 'Super Admin') appOut(['error' => 'not_allowed'], 403);
    $counts = [];
    foreach (['pending_vessel' => 'Pending Vessel', 'pending_report' => 'Pending Report', 'cancelled' => 'Cancelled', 'completed' => 'Completed'] as $k => $status) {
        $st = $db->prepare('SELECT COUNT(*) FROM surveys WHERE status = ?');
        $st->execute([$status]);
        $counts[$k] = (int)$st->fetchColumn();
    }
    $groups = [];
    try {
        $dup = $db->query('SELECT LOWER(TRIM(company_name)) AS norm_name FROM clients GROUP BY LOWER(TRIM(company_name)) HAVING COUNT(*) > 1');
        $rowsSt = $db->prepare('SELECT c.id, c.company_name, c.contact_person, u.username,
                (SELECT COUNT(*) FROM surveys s WHERE s.client_id = c.id) AS survey_count
            FROM clients c LEFT JOIN users u ON u.id = c.user_id WHERE LOWER(TRIM(c.company_name)) = ? ORDER BY c.id');
        foreach ($dup->fetchAll(PDO::FETCH_COLUMN) as $norm) {
            $rowsSt->execute([$norm]);
            $groups[] = ['norm' => (string)$norm, 'rows' => array_map(function ($r) {
                return ['id' => (int)$r['id'], 'company' => (string)$r['company_name'], 'contact' => (string)($r['contact_person'] ?? ''),
                    'login' => (string)($r['username'] ?? ''), 'surveys' => (int)$r['survey_count']];
            }, $rowsSt->fetchAll(PDO::FETCH_ASSOC))];
        }
    } catch (Throwable $e) {
        error_log('app_api sa_controls dup scan: ' . $e->getMessage());
    }
    appOut(['counts' => $counts, 'groups' => $groups]);
}

if ($a === 'duplicates') {
    if ($role !== 'Admin') appOut(['error' => 'not_allowed'], 403);
    require_once 'includes/duplicate_finder.php';
    $out = [];
    foreach (findPossibleDuplicates($db) as $c) {
        $out[] = ['kind' => $c['kind'], 'month' => $c['month'], 'extra_mt' => $c['extra_mt'], 'extra_ships' => $c['extra_ships'],
            'records' => array_map(function ($r) use ($db) {
                return ['id' => (int)$r['id'], 'vessel' => (string)$r['vessel_name'], 'report_no' => (string)($r['report_number'] ?? ''),
                    'status' => (string)$r['status'], 'type' => getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', (string)($r['type_name'] ?? '')),
                    'surveyor' => (string)($r['surveyor_name'] ?? ''), 'completed_on' => appDate($r['survey_completed_date'] ?? ''),
                    'recovery' => $r['recovery_amount'] !== null ? number_format(abs((float)$r['recovery_amount']), 3) : '—',
                    'files' => (int)($r['files'] ?? 0), 'source' => duplicateRecordSource($r),
                    'list' => $r['status'] === 'Completed' ? 'completed_surveys' : 'pending_reports'];
            }, $c['records'])];
    }
    appOut(['clusters' => $out]);
}

if ($a === 'surveyor_cards') {
    // Read-only, as admin_surveyor_cards.php lists them; upload / remove post to that page itself.
    if ($role !== 'Admin') appOut(['error' => 'not_allowed'], 403);
    $rows = [];
    try {
        $q = $db->query("SELECT id, full_name, username, business_card_path, id_card_path FROM users WHERE role_id = 2 ORDER BY full_name ASC");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $card = function ($p) { $p = (string)($p ?? ''); return $p !== '' && is_file(__DIR__ . '/' . $p) ? $p : ''; };
            $rows[] = ['id' => (int)$u['id'], 'name' => (string)$u['full_name'], 'username' => (string)$u['username'],
                'business_card' => $card($u['business_card_path']), 'id_card' => $card($u['id_card_path'])];
        }
    } catch (Throwable $e) { $rows = []; } // older databases without the card columns: the page adds them
    appOut(['items' => $rows]);
}

if ($a === 'daily_emails') {
    // Read-only view of admin_daily_emails.php; saving, test, send now and retry post to that page.
    if ($role !== 'Admin') appOut(['error' => 'not_allowed'], 403);
    require_once __DIR__ . '/includes/daily_emails.php';
    dailyEmailEnsureTables($db);
    $s = dailyEmailSettings($db);
    $fmt = function ($v) { return $v ? date('d M Y H:i', strtotime($v)) : ''; };
    $jobs = [];
    foreach (DAILY_EMAIL_JOBS as $job => $label) {
        $run = dailyEmailTodayRun($db, $job);
        $jobs[] = [
            'job' => $job, 'label' => $label,
            'enabled' => ($s[$job . '_enabled'] ?? '0') === '1', 'time' => (string)($s[$job . '_time'] ?? ''),
            'send_empty' => ($s[$job . '_send_empty'] ?? '0') === '1',
            'from' => $job === 'surveyor_pending' ? (string)($s['surveyor_pending_from'] ?? '') : '',
            'weekly' => isset(DAILY_EMAIL_WEEKDAY[$job]), 'due_today' => dailyEmailRunsToday($job),
            'last_scheduler_call' => $fmt(dailyEmailGetMeta($db, 'last_scheduler_call_' . $job)),
            'today' => $run ? [
                'status' => (string)$run['status'], 'detail' => (string)$run['detail'], 'attempts' => (int)$run['attempts'], 'via' => (string)$run['via'],
                'deliveries' => array_map(function ($d) {
                    return ['name' => (string)$d['recipient_name'], 'to' => (string)$d['recipient'], 'vessels' => (int)$d['vessels'], 'status' => (string)$d['status'],
                        'attempts' => (int)$d['attempts'], 'sent_at' => $d['sent_at'] ? date('H:i', strtotime($d['sent_at'])) : '', 'error' => (string)$d['error']];
                }, dailyEmailDeliveries($db, (int)$run['id'])),
            ] : null,
        ];
    }
    $routes = [
        'surveyor_pending' => "To each surveyor's own profile email only — their own list, no CC (fixed).",
        'all_pending' => 'From ' . DAILY_EMAIL_APPT_FROM . ' to ' . DAILY_EMAIL_ALL_PENDING_TO . ' only — fixed, no CC.',
        'appointments' => 'From ' . DAILY_EMAIL_APPT_FROM . ' to ' . DAILY_EMAIL_APPT_TO . ' only — fixed, no CC.',
        'surveyor_reports' => 'From ' . DAILY_EMAIL_APPT_FROM . " to each surveyor's own profile email only — their own list, no CC (fixed).",
        'all_reports' => 'From ' . DAILY_EMAIL_APPT_FROM . ' to ' . implode(' and ', DAILY_EMAIL_ALL_REPORTS_TO) . ' only — fixed, no CC.',
    ];
    foreach ($jobs as &$j) $j['route'] = $routes[$j['job']] ?? '';
    unset($j);
    $runs = array_map(function ($r) {
        return ['when' => date('d M Y H:i', strtotime($r['started_at'])), 'label' => DAILY_EMAIL_JOBS[$r['job']] ?? (string)$r['job'],
            'how' => ($r['kind'] === 'auto' ? 'Scheduled' . ($r['via'] ? ' (' . $r['via'] . ')' : '') : ($r['kind'] === 'test' ? 'Test' : 'Send now')) . ($r['full_name'] ? ' · ' . $r['full_name'] : ''),
            'status' => (string)$r['status'], 'attempts' => (int)($r['attempts'] ?? 1), 'detail' => (string)$r['detail']];
    }, dailyEmailRecentRuns($db, 25));
    appOut([
        'jobs' => $jobs, 'runs' => $runs, 'today' => date('d M Y'),
        'mail_on' => !defined('EMAIL_NOTIFICATIONS_ENABLED') || EMAIL_NOTIFICATIONS_ENABLED,
        'scheduler' => (string)(getenv('YSMS_CRON_TOKEN') ?: '') !== '', // only whether it is set, never the token
        'surveyors_with_email' => count(dailyEmailSurveyors($db)), 'surveyors' => dailyEmailActiveSurveyorCount($db),
        'counts' => ['appointments' => count(dailyEmailAppointmentRows($db, date('Y-m-d'))), 'all_pending' => count(dailyEmailAllPendingRows($db)),
            'all_reports' => count(dailyEmailReportRows($db))],
    ]);
}

if ($a === 'permission_copy') {
    // The choices generate_permission_copy.php offers; generating and the template upload post to that page.
    $tpl = glob(__DIR__ . '/uploads/permission_templates/*.docx') ?: [];
    rsort($tpl);
    appOut([
        'template' => $tpl ? basename($tpl[0]) : '',
        'can_upload' => $role === 'Admin',
        'max_surveyors' => 4,
        'survey_types' => array_map('strval', $db->query('SELECT type_name FROM survey_types ORDER BY type_name ASC')->fetchAll(PDO::FETCH_COLUMN)),
        'surveyors' => array_map('strval', $db->query("SELECT full_name FROM users WHERE role_id = 2 AND status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_COLUMN)),
        'today' => date('Y-m-d'),
    ]);
}

appOut(['error' => 'unknown'], 400);
