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

// 🔐 మీ లైవ్ సర్వర్ / cPanel డేటాబేస్ కనెక్షన్ వివరాలు
// (env variable సెట్ చేసి ఉంటే దాన్ని వాడుతుంది, లేకపోతే కింద ఉన్న డిఫాల్ట్ విలువలనే వాడుతుంది —
//  కాబట్టి ఇప్పుడు ఏమీ మారదు, కానీ future లో సర్వర్ env vars లో పెట్టి ఇక్కడి నుండి ప్లెయిన్‌టెక్స్ట్ తీసేయొచ్చు)
define('DB_HOST', getenv('YSMS_DB_HOST') ?: '/cloudsql/ymr-sms:asia-south1:ymrmarine');
define('DB_USER', getenv('YSMS_DB_USER') ?: 'ysms_user');
define('DB_PASS', getenv('YSMS_DB_PASS') ?: '');
define('DB_NAME', getenv('YSMS_DB_NAME') ?: 'ysms_db');
define('SITE_NAME', 'YMR Survey Management System');
define('WHATSAPP_NUMBER', getenv('YSMS_WHATSAPP_NUMBER') ?: '');

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

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
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
function buildAnchorageName(string $portName): ?string {
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
function getCombinedSurveyTypeNames($db, $ids_csv, $fallback_name = '') {
    $ids_csv = trim((string)$ids_csv);
    if ($ids_csv === '') {
        return $fallback_name;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $ids_csv)))));
    if (empty($ids)) {
        return $fallback_name;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, type_name FROM survey_types WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $name_by_id = [];
    foreach ($stmt->fetchAll() as $row) {
        $name_by_id[(int)$row['id']] = $row['type_name'];
    }
    $names = [];
    foreach ($ids as $tid) {
        if (isset($name_by_id[$tid])) $names[] = $name_by_id[$tid];
    }
    return !empty($names) ? implode(' + ', $names) : $fallback_name;
}

// 🌟 ఒక సర్వేకి బహుళ Surveyors అసైన్ చేసినప్పుడు (survey_surveyors జంక్షన్ టేబుల్), వాటన్నింటినీ
// "+" తో కలిపి చూపించడానికి హెల్పర్ — ఏ surveyor రికార్డు లేకపోతే (పాత single-surveyor
// అసైన్‌మెంట్లు) $fallback_name (సాధారణంగా surveys.surveyor_id నుండి వచ్చిన పేరు) ఇస్తుంది.
// Per-request memoization: అదే survey_id కి ఒకే పేజీలో రెండుసార్లు (mobile + desktop view వంటివి)
// పిలిస్తే మళ్ళీ query చేయదు.
function getAssignedSurveyorNamesArray($db, $surveyId): array {
    static $cache = [];
    $surveyId = (int)$surveyId;
    if ($surveyId <= 0) {
        return [];
    }
    if (array_key_exists($surveyId, $cache)) {
        return $cache[$surveyId];
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
?>