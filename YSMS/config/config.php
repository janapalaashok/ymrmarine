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
 * whatever the admin/surveyor typed (with or without "MV", "M.V.", any
 * case, extra spaces). Never doubles an existing prefix.
 */
function normalizeVesselName(string $name): string {
    $name = trim($name);
    if ($name === '') return $name;
    if (preg_match('/^m\.?\s*v\.?\s*/i', $name)) {
        $rest = preg_replace('/^m\.?\s*v\.?\s*/i', '', $name);
        return 'MV. ' . ltrim($rest);
    }
    return 'MV. ' . $name;
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
?>