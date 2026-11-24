<?php
// 🌟 1. సర్వర్ ఎక్కడ ఉన్నా సరే గ్లోబల్ గా ఇండియన్ టైమ్ జోన్ సెట్ చేయడం
date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookie flags — conditional 'secure' so this doesn't
    // break a plain-HTTP local dev environment if one is ever used.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
    ]);
    session_start();
}
require_once __DIR__ . '/../../includes/csrf.php';

// 🌟 Sales role: deny by default — only the Sales pages / endpoints listed in
// includes/sales.php (salesAllowedPaths) open for a signed-in Sales user.
if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'Sales') {
    require_once dirname(__DIR__) . '/includes/sales.php';
    salesGuard(dirname(__DIR__));
}

// 🌟 Uploads with a progress bar: assets/js/submit-feedback.js sends every file-upload form
// with XMLHttpRequest (header X-YSMS-Upload) so it can show the % sent. The browser then has
// to land on the same page a normal submit would have shown:
//  - a redirect is answered as {"redirect": "<url>"} for the page to follow;
//  - a page rendered straight from the POST is kept once in the session and answered as a
//    redirect to the same URL + ?ysms_page=<token>, which shows exactly that page (once);
//  - file downloads and error responses (4xx/5xx) pass through untouched.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_SERVER['HTTP_X_YSMS_UPLOAD'] ?? '') === '1') {
    $ysmsUploadObLevel = ob_get_level();
    ob_start();
    register_shutdown_function(function () use ($ysmsUploadObLevel) {
        while (ob_get_level() > $ysmsUploadObLevel + 1) ob_end_flush();
        if (ob_get_level() <= $ysmsUploadObLevel) return;
        $body = (string)ob_get_clean();
        $code = http_response_code() ?: 200;
        $location = '';
        $attachment = false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
            if (stripos($h, 'Content-Disposition:') === 0 && stripos($h, 'attachment') !== false) $attachment = true;
        }
        if (headers_sent() || $attachment || ($code >= 400 && $location === '')) { echo $body; return; }
        if ($location === '') {
            $token = bin2hex(random_bytes(8));
            if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
            $type = 'text/html; charset=UTF-8';
            foreach (headers_list() as $h) if (stripos($h, 'Content-Type:') === 0) $type = trim(substr($h, 13));
            $_SESSION['ysms_upload_page'] = ['token' => $token, 'body' => $body, 'type' => $type];
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            $location = preg_replace('/([?&])ysms_page=[0-9a-f]+&?/', '$1', $uri);
            $location = rtrim($location, '?&') . (strpos(rtrim($location, '?&'), '?') === false ? '?' : '&') . 'ysms_page=' . $token;
        }
        header_remove('Location');
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['redirect' => $location]);
    });
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['ysms_page'])
    && is_array($_SESSION['ysms_upload_page'] ?? null)
    && hash_equals((string)$_SESSION['ysms_upload_page']['token'], (string)$_GET['ysms_page'])) {
    $ysmsPage = $_SESSION['ysms_upload_page'];
    unset($_SESSION['ysms_upload_page']);
    header('Content-Type: ' . $ysmsPage['type']);
    header('Cache-Control: no-store');
    echo $ysmsPage['body'];
    exit;
}

// 🔐 మీ లైవ్ సర్వర్ / cPanel డేటాబేస్ కనెక్షన్ వివరాలు
// (env variable సెట్ చేసి ఉంటే దాన్ని వాడుతుంది, లేకపోతే కింద ఉన్న డిఫాల్ట్ విలువలనే వాడుతుంది —
//  కాబట్టి ఇప్పుడు ఏమీ మారదు, కానీ future లో సర్వర్ env vars లో పెట్టి ఇక్కడి నుండి ప్లెయిన్‌టెక్స్ట్ తీసేయొచ్చు)
define('DB_HOST', getenv('YSMS_DB_HOST') ?: '/cloudsql/ymr-sms:asia-south1:ymrmarine');
define('DB_USER', getenv('YSMS_DB_USER') ?: 'ysms_user');
define('DB_PASS', getenv('YSMS_DB_PASS') ?: '');
define('DB_NAME', getenv('YSMS_DB_NAME') ?: 'ysms_db');
define('SITE_NAME', 'YMR Survey Management System');
define('WHATSAPP_NUMBER', getenv('YSMS_WHATSAPP_NUMBER') ?: '');

// 🌟 AI Photo Description (photo_report_generator.php) — feature flags only;
// the Gemini API key itself is never defined here or exposed to the browser,
// it stays purely in the environment and is read directly by
// ajax/gemini_describe.php. AI_PHOTO_DESCRIPTION_ENABLED is the master
// rollback switch: false hides the AI UI entirely and the rest of the photo
// report tool (upload, sort, generate) keeps working exactly as before.
define('AI_PHOTO_DESCRIPTION_ENABLED', filter_var(getenv('AI_PHOTO_DESCRIPTION_ENABLED') !== false ? getenv('AI_PHOTO_DESCRIPTION_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN));
define('AI_AUTO_DESCRIPTION', filter_var(getenv('AI_AUTO_DESCRIPTION') !== false ? getenv('AI_AUTO_DESCRIPTION') : 'true', FILTER_VALIDATE_BOOLEAN));
define('AI_MAX_CONCURRENT', max(1, (int)(getenv('AI_MAX_CONCURRENT') ?: 3)));

// 🌟 AI Email -> Vessel Assignment extraction (assign_vessel.php "Paste Email"
// tab, ajax/email_vessel_extract.php) — same Gemini credentials/env vars as
// AI Photo Description above (YSMS_GEMINI_API_KEY / GEMINI_API_KEY,
// YSMS_GEMINI_MODEL), but its own independent on/off switch so one feature
// can be disabled without touching the other.
define('AI_EMAIL_EXTRACTION_ENABLED', filter_var(getenv('AI_EMAIL_EXTRACTION_ENABLED') !== false ? getenv('AI_EMAIL_EXTRACTION_ENABLED') : 'true', FILTER_VALIDATE_BOOLEAN));

function getDB() {
    try {
        // Cloud Run + Cloud SQL connects via Unix socket (path starts with /cloudsql/);
        // local/dev environments connect via TCP host.
        if (str_starts_with(DB_HOST, '/cloudsql/')) {
            $dsn = "mysql:unix_socket=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8";
        } else {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8";
        }
        $db = new PDO($dsn, DB_USER, DB_PASS);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // డేటాబేస్ సెషన్‌ను కూడా +05:30 కి సింక్ చేయడం
        $db->exec("SET time_zone = '+05:30';");
        
        return $db;
    } catch(PDOException $e) {
        error_log("DB connection failed: " . $e->getMessage());
        die("Database connection failed. Please try again later.");
    }
}

// 🌟 NOW() కి బదులుగా పక్కా ఇండియన్ టైమ్ స్ట్రింగ్ ఇచ్చే హెల్పర్ ఫంక్షన్
function getIST() {
    date_default_timezone_set('Asia/Kolkata');
    return date('Y-m-d H:i:s');
}

// 🌟 సర్వర్ లోకల్ టైమ్ ఆధారంగా డైనమిక్ గ్రీటింగ్ (Good Morning / Afternoon / Evening) ఇచ్చే హెల్పర్ ఫంక్షన్
// 05:00–11:59 -> Good Morning | 12:00–16:59 -> Good Afternoon | 17:00–04:59 -> Good Evening
function getGreeting() {
    $hour = (int)date('G');
    if ($hour >= 5 && $hour < 12) {
        return 'Good Morning';
    } elseif ($hour >= 12 && $hour < 17) {
        return 'Good Afternoon';
    } else {
        return 'Good Evening';
    }
}

/**
 * The page asked for, to open again after signing in (login.php?next=…): a page of YSMS (or of
 * report-generator/) opened with GET, with its query string. Never login / logout, never ajax/.
 */
function ysmsLoginNextFromRequest(): string {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return '';
    $file = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = realpath(dirname(__DIR__));
    if (!$file || !$root || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) return '';
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    $next = $rel . ($qs !== '' ? '?' . $qs : '');
    return ysmsSafeLoginNext($next);
}

/** $next when it is a YSMS page to go to after signing in, else '' (so it can never send anyone to another site). */
function ysmsSafeLoginNext(string $next): string {
    if ($next === '' || strlen($next) > 1500) return '';
    if (!preg_match('~^(report-generator/)?[A-Za-z0-9_-]+\.php(\?[^\s"\'<>\\#]*)?$~', $next)) return '';
    $path = strtok($next, '?');
    if (in_array(basename($path), ['login.php', 'logout.php'], true) || in_array($path, ['index.php', 'app.php'], true)) return '';
    return is_file(dirname(__DIR__) . '/' . $path) ? $next : '';
}

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        // back to this page after signing in (pages only; ajax/ answers stay as they were)
        $next = ysmsLoginNextFromRequest();
        header('Location: login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
        exit();
    }
    csrf_require();
}

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Ensures a vessel name always carries a single, consistent "MV. " prefix —
 * whatever the admin/client typed (with or without "MV", "M.V.", any case,
 * extra spaces) — and that the rest of the name is upper case, e.g.
 * "vessel name", "Vessel Name" and "mv vessel name" all normalize to
 * "MV. VESSEL NAME". Never doubles an existing prefix.
 */
function normalizeVesselName(string $name): string {
    $name = trim($name);
    if ($name === '') return $name;
    if (preg_match('/^m\.?\s*v\.?\s*/i', $name)) {
        $rest = preg_replace('/^m\.?\s*v\.?\s*/i', '', $name);
        return 'MV. ' . mb_strtoupper(ltrim($rest), 'UTF-8');
    }
    return 'MV. ' . mb_strtoupper($name, 'UTF-8');
}

/**
 * Shared "Updated on 10th Sep - 04:02 PM" phrase for the Latest Update
 * feature (vessels.php, vessel_detail.php, photo_report.php) — kept in one
 * place so the date/time formatting stays identical everywhere it's shown.
 * Caller is responsible for the "Latest Update : {status}" prefix and the
 * "By {name}" suffix (sanitize()d separately, since this returns a value
 * meant to be echoed directly, not escaped again).
 */
function formatLatestUpdateWhen(string $updatedAt): string {
    $ts = strtotime($updatedAt);
    if (!$ts) return '';
    return 'Updated on ' . date('jS M', $ts) . ' - ' . date('h:i A', $ts);
}

/** For a Client-role user, returns their linked clients.id (0 if not linked/not a client). */
function getClientIdForUser(PDO $db, int $userId): int {
    $stmt = $db->prepare('SELECT id FROM clients WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/** Safety net: ensure ports.country exists (used by the country revenue dashboard cards). */
function ensurePortsCountryColumn(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $db->query("SHOW COLUMNS FROM ports LIKE 'country'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE ports ADD COLUMN country VARCHAR(100) DEFAULT NULL");
        }
        $done = true;
    } catch (Exception $e) {
        error_log('ensurePortsCountryColumn: ' . $e->getMessage());
    }
}

/**
 * "Visakhapatnam Port" -> "Visakhapatnam Anchorage"; "Port of Singapore" ->
 * "Singapore Anchorage"; "King Abdulaziz Port (Dammam)" -> "King Abdulaziz
 * Anchorage (Dammam)". Falls back to appending " Anchorage" to the full name
 * for anything that doesn't match the "X Port" / "Port of X" patterns.
 * Returns null for a name that's already an anchorage (avoids "X Anchorage
 * Anchorage" if this ever runs against its own output).
 */
/** Port names and countries are kept in capitals, single-spaced ("VISAKHAPATNAM PORT", "INDIA"). */
function portCaps(?string $s): string {
    return mb_strtoupper(trim((string)preg_replace('/\s+/u', ' ', (string)$s)), 'UTF-8');
}

function buildAnchorageName(string $portName): ?string {
    $caps = trim($portName) !== '' && mb_strtoupper(trim($portName), 'UTF-8') === trim($portName);
    $a = buildAnchorageNameRaw($portName);
    return $a !== null && $caps ? mb_strtoupper($a, 'UTF-8') : $a; // "CHENNAI PORT" -> "CHENNAI ANCHORAGE"
}
function buildAnchorageNameRaw(string $portName): ?string {
    $name = trim($portName);
    if ($name === '' || stripos($name, 'anchorage') !== false) return null;
    if (preg_match('/^Port of\s+(.+)$/i', $name, $m)) {
        return trim($m[1]) . ' Anchorage';
    }
    if (preg_match('/^(.+?)\s+Port(\s*\(.+\))?$/i', $name, $m)) {
        return trim($m[1]) . ' Anchorage' . (!empty($m[2]) ? ' ' . trim($m[2]) : '');
    }
    return $name . ' Anchorage';
}

/**
 * One-time backfill: every port that existed before the Anchorage feature
 * gets a matching "<name> Anchorage" row (same country), e.g. Visakhapatnam
 * Port -> Visakhapatnam Anchorage. Skips entirely once any "* Anchorage"
 * port already exists, so it only ever runs once. New ports added after
 * that get their anchorage created inline instead (see
 * ajax/admin_master.php's 'ports' add handler).
 */
function ensurePortAnchorages(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $already = (int)$db->query("SELECT COUNT(*) FROM ports WHERE port_name LIKE '%Anchorage%'")->fetchColumn();
        if ($already > 0) return;
        $ports = $db->query("SELECT port_name, country FROM ports")->fetchAll(PDO::FETCH_ASSOC);
        $ins = $db->prepare("INSERT IGNORE INTO ports (port_name, country) VALUES (?, ?)");
        foreach ($ports as $p) {
            $anchorageName = buildAnchorageName((string)$p['port_name']);
            if ($anchorageName === null) continue;
            $ins->execute([$anchorageName, $p['country'] ?: 'India']);
        }
    } catch (Throwable $e) {
        error_log('ensurePortAnchorages: ' . $e->getMessage());
    }
}

// 🌟 ఒక సర్వేకి బహుళ Survey Types ఎంచుకున్నప్పుడు, వాటన్నింటినీ "+" తో కలిపి చూపించడానికి హెల్పర్
// (survey_type_ids కాలమ్ ఖాళీగా ఉంటే, పాత రికార్డుల కోసం $fallback_name ఇస్తుంది)
/**
 * id => type_name for the given survey type ids. The survey_types table is small, so it is
 * read once per request and reused (list pages call the name helpers for every row — one
 * query per row made Completed slow with hundreds of vessels). Re-read once if an id is
 * missing (a type added during this request).
 */
function surveyTypeNameMap($db, array $ids): array {
    static $all = null;
    if ($all === null || array_diff($ids, array_keys($all))) {
        $all = [];
        foreach ($db->query("SELECT id, type_name FROM survey_types")->fetchAll() as $row) {
            $all[(int)$row['id']] = $row['type_name'];
        }
    }
    return array_intersect_key($all, array_flip($ids));
}

function getCombinedSurveyTypeNames($db, $ids_csv, $fallback_name = '') {
    $ids_csv = trim((string)$ids_csv);
    if ($ids_csv === '') {
        return $fallback_name;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $ids_csv)))));
    if (empty($ids)) {
        return $fallback_name;
    }
    $name_by_id = surveyTypeNameMap($db, $ids);
    $names = [];
    foreach ($ids as $tid) {
        if (isset($name_by_id[$tid])) $names[] = $name_by_id[$tid];
    }
    return !empty($names) ? implode(' + ', $names) : $fallback_name;
}

// 🌟 Pending Vessels / Pending Reports / Completed lists are ordered by the
// vessel's Appointment Date (newest first). Records without one (older or
// imported entries) fall back to their assign date so they still sort sensibly.
const SURVEY_APPOINTMENT_ORDER_SQL = 'COALESCE(s.appointment_date, DATE(s.assign_date))';

/** Pending Reports are dated and ordered by the Survey Completed date. */
const SURVEY_COMPLETED_ORDER_SQL = 'COALESCE(DATE(s.survey_completed_date), s.appointment_date, DATE(s.assign_date))';

/** Survey Completed date used for month figures (dashboard cards, Month Summary,
 *  Surveyor Performance, Recovery page, exports): the vessel's own Survey Completed
 *  Date only — no fallback to the appointment / assign date, so a vessel without one
 *  is in no month rather than in the month it was assigned. NULL when missing. */
function surveyCompletedDateSql(string $a = 's'): string {
    // No zero-date literal here: MySQL 8 strict mode rejects comparing with '0000-00-00 …'
    // (500 on staging); YEAR() > 0 skips a stored zero date without one.
    return "IF(YEAR($a.survey_completed_date) > 0, DATE($a.survey_completed_date), NULL)";
}

/** d-m-Y of the vessel's own Survey Completed Date ('' when missing) — what the month figures use. */
function surveyCompletedDateText(array $r): string {
    $v = (string)($r['survey_completed_date'] ?? '');
    return ($v !== '' && strpos($v, '0000') !== 0 && ($t = strtotime(substr($v, 0, 10)))) ? date('d-m-Y', $t) : '';
}

function surveyCompletedTs(array $survey): int {
    $v = (string)($survey['survey_completed_date'] ?? '');
    if ($v !== '' && strpos($v, '0000-00-00') !== 0 && ($ts = strtotime(substr($v, 0, 10))) !== false) return $ts;
    return surveyAppointmentTs($survey);
}

/** Completed list is ordered by the Report Completed date (report_uploaded_date). */
const SURVEY_REPORT_ORDER_SQL = 'COALESCE(DATE(s.report_uploaded_date), DATE(s.survey_completed_date), s.appointment_date, DATE(s.assign_date))';

function surveyReportTs(array $survey): int {
    $v = (string)($survey['report_uploaded_date'] ?? '');
    if ($v !== '' && strpos($v, '0000-00-00') !== 0 && ($ts = strtotime(substr($v, 0, 10))) !== false) return $ts;
    return surveyCompletedTs($survey);
}

/** Unix timestamp used by the lists' client-side "Newest/Oldest first" sort. */
function surveyAppointmentTs(array $survey): int {
    foreach (['appointment_date', 'assign_date'] as $k) {
        $v = (string)($survey[$k] ?? '');
        if ($v !== '' && strpos($v, '0000-00-00') !== 0) {
            $ts = strtotime($v);
            if ($ts !== false) return $ts;
        }
    }
    return 0;
}

// 🌟 Survey types for which Recovery does not apply at all (no fuel/cargo
// quantity to recover) — Recovery shows as RECOVERY_NA_LABEL ("NOT APPLICABLE")
// instead of a number whenever EVERY type selected on a survey (single or
// combination) is in this list. If even one selected type is outside this
// list, Recovery behaves exactly as before (a normal numeric value).
// Matched by exact type_name (case-insensitive, trimmed) — add/remove
// names here only, nothing else needs to change.
const RECOVERY_NOT_APPLICABLE_TYPES = [
    'Draft Survey', 'Conversion Survey', 'Condition Survey', 'Reversion Survey',
    'P&I Survey', 'Hold Cleanliness Survey', 'BQS', 'Ultrasonic Thickness Survey',
    'Tally Survey', 'Discharge Supervision', 'Loading Supervision', 'Damage Survey',
    'Pre-Purchase Inspection', 'Unsealing of Hatches', 'Grab Inspection', 'Crane Inspection',
    'Hold Inspection', 'Hold Inspection Survey',
];

// Same id-resolution as getCombinedSurveyTypeNames() above, but returns the
// raw name list instead of a joined display string.
function getSurveyTypeNamesArray($db, $ids_csv, $fallback_name = '') {
    $ids_csv = trim((string)$ids_csv);
    if ($ids_csv === '') {
        return $fallback_name !== '' ? [$fallback_name] : [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $ids_csv)))));
    if (empty($ids)) {
        return $fallback_name !== '' ? [$fallback_name] : [];
    }
    $name_by_id = surveyTypeNameMap($db, $ids);
    $names = [];
    foreach ($ids as $tid) {
        if (isset($name_by_id[$tid])) $names[] = $name_by_id[$tid];
    }
    return !empty($names) ? $names : ($fallback_name !== '' ? [$fallback_name] : []);
}

// The one label shown wherever Recovery would appear for those survey types
// (lists, detail pages, exports, emails).
const RECOVERY_NA_LABEL = 'NOT APPLICABLE';

// True only when every selected survey type (single or combination) is in
// RECOVERY_NOT_APPLICABLE_TYPES. An unresolved/empty type list is treated
// as applicable (false) — i.e. the old numeric behavior, never silently N/A.
function isRecoveryNotApplicable($db, $ids_csv, $fallback_name = '') {
    $names = getSurveyTypeNamesArray($db, $ids_csv, $fallback_name);
    if (empty($names)) {
        return false;
    }
    $naList = array_map('strtolower', array_map('trim', RECOVERY_NOT_APPLICABLE_TYPES));
    foreach ($names as $n) {
        if (!in_array(strtolower(trim((string)$n)), $naList, true)) {
            return false;
        }
    }
    return true;
}

/**
 * Onhire surveys (any spelling: Onhire / ONHIRE / On hire / On-Hire, alone or combined, e.g.
 * "Onhire + BQS"): their recovery is SHOWN with a minus sign. Display only — the stored value
 * stays positive and every total / sum / average keeps adding it as a positive number.
 */
function isOnhireSurvey($db, $ids_csv, $fallback_name = ''): bool {
    foreach (getSurveyTypeNamesArray($db, (string)$ids_csv, (string)$fallback_name) as $n) {
        if (preg_match('/(?<![a-z])on[\s\-]*hire/i', (string)$n)) return true;
    }
    return false;
}

/** Recovery figure for display: "12.500", or "-12.500" for an Onhire survey (0 stays "0.000"). */
function recoveryDisplay($value, bool $onhire, int $decimals = 3): string {
    $v = abs((float)$value);
    return ($onhire && round($v, $decimals) != 0 ? '-' : '') . number_format($v, $decimals);
}

/** Numeric twin of recoveryDisplay() for Excel cells (negative for Onhire), null stays null. */
function recoverySigned($value, bool $onhire): ?float {
    if ($value === null || $value === '') return null;
    $v = round(abs((float)$value), 3);
    return $onhire && $v != 0.0 ? -$v : $v;
}

// SQL twin of isRecoveryNotApplicable(): true when recovery counts for the
// survey row aliased $a (at least one selected type is outside
// RECOVERY_NOT_APPLICABLE_TYPES; unresolved types count as applicable).
// Every recovery SUM/AVG uses it, so a NOT APPLICABLE vessel with a stale
// stored value can't make on-screen totals differ from the exports.
function recoveryAppliesSql(string $a = 's'): string {
    $list = implode(',', array_map(fn($n) => "'" . str_replace("'", "''", strtolower(trim($n))) . "'", RECOVERY_NOT_APPLICABLE_TYPES));
    $inIds = "FIND_IN_SET(x.id, REPLACE(COALESCE($a.survey_type_ids, ''), ' ', ''))";
    return "(CASE WHEN EXISTS (SELECT 1 FROM survey_types x WHERE $inIds)
        THEN EXISTS (SELECT 1 FROM survey_types x WHERE $inIds AND LOWER(TRIM(x.type_name)) NOT IN ($list))
        ELSE NOT EXISTS (SELECT 1 FROM survey_types x WHERE x.id = $a.survey_type_id AND LOWER(TRIM(x.type_name)) IN ($list)) END)";
}

/** recovery_amount (or $col) counted in totals: NULL for NOT APPLICABLE survey types. */
function recoveryCountedSql(string $a = 's', string $col = 'recovery_amount'): string {
    // ABS: recovery is always counted as a positive quantity (an Onhire value typed or imported with a minus sign must never reduce totals)
    return "(CASE WHEN " . recoveryAppliesSql($a) . " THEN ABS($a.$col) END)";
}

// 🌟 ఒక సర్వేకి బహుళ Surveyors అసైన్ చేసినప్పుడు (survey_surveyors జంక్షన్ టేబుల్), వాటన్నింటినీ
// "+" తో కలిపి చూపించడానికి హెల్పర్ — ఏ surveyor రికార్డు లేకపోతే (పాత single-surveyor
// అసైన్‌మెంట్లు) $fallback_name (సాధారణంగా surveys.surveyor_id నుండి వచ్చిన పేరు) ఇస్తుంది.
// Per-request memoization: అదే survey_id కి ఒకే పేజీలో రెండుసార్లు (mobile + desktop view వంటివి)
// పిలిస్తే మళ్ళీ query చేయదు.
function getAssignedSurveyorNamesArray($db, $surveyId): array {
    static $cache = [];
    static $misses = 0;
    $surveyId = (int)$surveyId;
    if ($surveyId <= 0) {
        return [];
    }
    if (array_key_exists($surveyId, $cache)) {
        return $cache[$surveyId];
    }
    // A list page asks for many vessels: after a few single lookups, read every survey's
    // surveyors in one query instead of one query per row. Every existing survey gets an
    // entry (empty when it has none), so only a survey created later in this request falls
    // through to the single lookup below.
    if (++$misses === 4) {
        try {
            $rows = $db->query("
                SELECT s.id, u.full_name
                FROM surveys s
                LEFT JOIN survey_surveyors ss ON ss.survey_id = s.id
                LEFT JOIN users u ON u.id = ss.surveyor_id
                ORDER BY s.id, ss.id
            ")->fetchAll(PDO::FETCH_NUM);
            $known = $cache; // surveys already looked up singly keep their list (don't append twice)
            foreach ($rows as [$sid, $name]) {
                $sid = (int)$sid;
                if (array_key_exists($sid, $known)) continue;
                if (!isset($cache[$sid])) $cache[$sid] = [];
                if ($name !== null) $cache[$sid][] = $name;
            }
            if (array_key_exists($surveyId, $cache)) {
                return $cache[$surveyId];
            }
        } catch (Throwable $e) {
            error_log('getAssignedSurveyorNamesArray bulk: ' . $e->getMessage());
        }
    }
    try {
        $stmt = $db->prepare("
            SELECT u.full_name
            FROM survey_surveyors ss
            JOIN users u ON ss.surveyor_id = u.id
            WHERE ss.survey_id = ?
            ORDER BY ss.id ASC
        ");
        $stmt->execute([$surveyId]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $names = [];
    }
    $cache[$surveyId] = $names;
    return $names;
}

function getCombinedSurveyorNames($db, $surveyId, $fallback_name = '') {
    $names = getAssignedSurveyorNamesArray($db, $surveyId);
    return !empty($names) ? implode(' + ', $names) : $fallback_name;
}

// 🌟 ఒక Surveyor ఒక సర్వేకి అసైన్ అయ్యాడో లేదో చెక్ చేయడానికి — legacy single
// surveys.surveyor_id కాలమ్ (primary) మరియు survey_surveyors జంక్షన్ టేబుల్ (బహుళ
// surveyors) రెండింటినీ చూస్తుంది. వెసెల్/రిపోర్ట్/కంప్లీటెడ్ డీటెయిల్ పేజీలు, ఎక్స్‌పెన్స్
// జనరేటర్ లాంటివి — "ఈ సర్వేయర్ ఈ సర్వేని చూడగలడా/యాక్సెస్ చేయగలడా" అని చెక్ చేసేచోట
// వాడాలి, కేవలం $survey['surveyor_id'] === $user_id పోల్చడం వల్ల seconday (multi-assign)
// surveyor లు తమ సొంత అసైన్‌మెంట్‌నే చూడలేకపోయే బగ్ రాకుండా.
function isSurveyorAssignedToSurvey($db, $surveyId, $userId): bool {
    $surveyId = (int)$surveyId;
    $userId = (int)$userId;
    if ($surveyId <= 0 || $userId <= 0) {
        return false;
    }
    try {
        $stmt = $db->prepare("
            SELECT 1 FROM surveys WHERE id = ? AND surveyor_id = ?
            UNION
            SELECT 1 FROM survey_surveyors WHERE survey_id = ? AND surveyor_id = ?
            LIMIT 1
        ");
        $stmt->execute([$surveyId, $userId, $surveyId, $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

// 🌟 SAFETY NET: survey_attachments — one row per assignment-time document
// (Assign Vessel form supports multiple files at once; each gets its own row
// here instead of overwriting a single surveys.attachment_path column).
// Mirrors the survey_surveyors lazy-create pattern already used in this app.
function ensureSurveyAttachmentsTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `survey_attachments` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `survey_id` int(11) NOT NULL,
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(255) NOT NULL,
            `file_size` int(11) DEFAULT NULL,
            `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_survey_attachments_survey` (`survey_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureSurveyAttachmentsTable: ' . $e->getMessage());
    }
}

/**
 * All assignment-time attachments for one survey, newest first. Falls back
 * to the legacy single surveys.attachment_path column (as a single-item
 * list) for vessels assigned before multi-file upload existed, so old
 * assignments keep showing their one attachment exactly as before.
 */
function getSurveyAttachments($db, $surveyId, $legacyAttachmentPath = ''): array {
    $surveyId = (int)$surveyId;
    if ($surveyId <= 0) return [];
    try {
        ensureSurveyAttachmentsTable($db);
        $stmt = $db->prepare("SELECT file_name, file_path, file_size, uploaded_at FROM survey_attachments WHERE survey_id = ? ORDER BY id ASC");
        $stmt->execute([$surveyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }
    if (empty($rows) && !empty($legacyAttachmentPath)) {
        $rows = [[
            'file_name' => basename($legacyAttachmentPath),
            'file_path' => $legacyAttachmentPath,
            'file_size' => null,
            'uploaded_at' => null,
        ]];
    }
    return $rows;
}

// 🌟 SAFETY NET: floating_notes — one row per user (their single sticky
// note), so it reads the same regardless of which device/browser they open
// it from — a plain localStorage-only note can't do that since it never
// leaves the device it was typed on. See ajax/floating_notes.php.
function ensureFloatingNotesTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `floating_notes` (
            `user_id` int(11) NOT NULL,
            `note_text` mediumtext,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureFloatingNotesTable: ' . $e->getMessage());
    }
}

// 🌟 Android app: signed in until Log out. The PHP session can end (expiry, a new server instance);
// the app's remember cookie then signs the user back in. See includes/app_login_tokens.php.
if (empty($_SESSION['user_id']) && !empty($_COOKIE['ysms_rm'])) {
    require_once __DIR__ . '/../includes/app_login_tokens.php';
    try { appRememberRestore(getDB()); } catch (Throwable $e) { error_log('app remember restore: ' . $e->getMessage()); }
}
?>