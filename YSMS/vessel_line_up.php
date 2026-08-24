<?php
require 'vendor/autoload.php';
require_once 'config/config.php';
checkAuth();

$role = $_SESSION['role'] ?? '';

// 🌟 8. Vessel Schedule Line-up - ఏ ఇండియన్ పోర్ట్ అయినా (జనరిక్ పేజీ)
// ----------------------------------------------------------------------
// voceanship.com పబ్లిక్‌గా చూపించే ఒక్కో పోర్ట్ Vessel Line-up పేజీని
// (?port=slug ఆధారంగా) fetch చేసి, పార్స్ చేసి, మన సైట్‌లో టేబుల్‌గా చూపిస్తాం.
// పోర్ట్‌ల లిస్ట్ (slug/name/source_url) అన్నీ includes/indian_ports.php లో.
//
// 🌟 ముఖ్యమైన అప్‌డేట్ (2026): voceanship.com ఇప్పుడు WORKING/WAITING/EXPECTED
// టేబుల్‌లను ప్లెయిన్ HTML <table> గా చూపించడం లేదు - బదులుగా అదే డేటాను ఒక
// పబ్లిష్ చేసిన Google Sheet గా పెట్టి, పేజీలో ఒక దాచిన "Download Excel"
// బటన్ (JS లో `excelUrl = "...docs.google.com/.../pub?output=xlsx&gid=..."`)
// ఇస్తున్నారు. కాబట్టి ఇప్పుడు మనం:
//   1. పోర్ట్ పేజీ HTML fetch చేసి, అందులోనుండి ఆ excelUrl ని రెగ్యులర్ ఎక్స్‌ప్రెషన్‌తో పట్టుకుంటాం.
//   2. ఆ xlsx ఫైల్‌ను డౌన్‌లోడ్ చేసి, PhpSpreadsheet తో చదివి, అదే
//      సెక్షన్/కాలమ్-హెడ్డర్ ఆధారిత లాజిక్‌తో పార్స్ చేస్తాం.
//   3. ఏ కారణం చేతైనా excelUrl దొరకకపోతే (పోర్ట్ ఇంకా పాత HTML ఫార్మాట్‌లో ఉంటే),
//      పాత <table> పార్సర్‌నే ఫాల్‌బ్యాక్‌గా వాడతాం.
//   4. పార్సింగ్ ఏ దశలో ఫెయిల్ అయినా, డీబగ్ కోసం fetch చేసిన HTML మరియు (ఉంటే)
//      xlsx ఫైల్‌ను uploads/cache/ లో సేవ్ చేస్తాం.
// ----------------------------------------------------------------------

const YMR_LINEUP_CACHE_TTL_SECONDS = 1800; // 30 నిమిషాలు

$indian_ports = require __DIR__ . '/includes/indian_ports.php';

$requested_slug = trim($_GET['port'] ?? '');
$port_info = null;
foreach ($indian_ports as $p) {
    if ($p['slug'] === $requested_slug) { $port_info = $p; break; }
}

// స్లగ్ లేకపోయినా / తప్పు స్లగ్ అయినా - పోర్ట్‌ల లిస్ట్ పేజీకి పంపడం
if ($port_info === null) {
    header('Location: vessel_lineups.php');
    exit;
}

/**
 * పోర్ట్ పేజీ HTML లో దాచిన "excelUrl" (పబ్లిష్ చేసిన Google Sheet xlsx లింక్) ను పట్టుకునే హెల్పర్.
 */
function extractExcelUrlFromHtml($html) {
    if (preg_match('/excelUrl\s*=\s*"([^"]+)"/i', $html, $m)) {
        $url = str_replace('\\/', '/', $m[1]);
        $url = html_entity_decode($url, ENT_QUOTES);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
    }
    return null;
}

/**
 * ఇచ్చిన URL ను cURL తో fetch చేసే జనరిక్ హెల్పర్ (HTML పేజీకి, xlsx ఫైల్‌కి రెండింటికీ వాడొచ్చు).
 * తిరిగి ఇచ్చేది: ['body' => string|false, 'http_code' => int, 'error' => string]
 */
function ymrCurlFetch($url, $timeout = 25) {
    if (!function_exists('curl_init')) {
        return ['body' => false, 'http_code' => 0, 'error' => "PHP's cURL extension is not enabled on this server. Please ask your host to enable php-curl."];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'http_code' => $http_code, 'error' => $error];
}

/**
 * ఇచ్చిన 2D రో-array లో, ఖాళీగా ఉన్న చివరి రోస్/కాలమ్‌లను తీసివేసి (షీట్ dimensions
 * A1:Z1000 లాంటివి అయినా, అసలు డేటా ఉన్నంత వరకే), విలువలు ఉన్న స్థానాలు మారకుండా
 * (ఖాళీ సెల్స్ మధ్యలో ఉన్నా అలాగే ఉంచి) ఒక "as-is" grid గా తిరిగి ఇచ్చే హెల్పర్.
 */
function trimRawRows($rows_of_cells) {
    $last_row = -1;
    $last_col = -1;
    foreach ($rows_of_cells as $r_idx => $row) {
        foreach ($row as $c_idx => $val) {
            if (trim((string)($val ?? '')) !== '') {
                $last_row = max($last_row, $r_idx);
                $last_col = max($last_col, $c_idx);
            }
        }
    }
    if ($last_row < 0) return [];

    $rows = [];
    for ($r = 0; $r <= $last_row; $r++) {
        $row = [];
        for ($c = 0; $c <= $last_col; $c++) {
            $row[] = trim((string)($rows_of_cells[$r][$c] ?? ''));
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * ఫాల్‌బ్యాక్: పేజీ HTML లోనే <table> ఉంటే (కొన్ని పోర్ట్‌లు ఇంకా పాత ఫార్మాట్‌లో ఉంటే),
 * ఏ కాలమ్ మ్యాపింగ్ చేయకుండా, ఆ టేబుల్ సెల్స్‌ను as-is గా (row/col array) తీసుకుంటుంది.
 */
function parseLineupFromHtmlTablesRaw($html) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//tr');

    $rows_of_cells = [];
    foreach ($rows as $row) {
        $cells = [];
        foreach ($xpath->query('.//td|.//th', $row) as $cell) {
            $cells[] = trim(preg_replace('/\s+/', ' ', $cell->textContent));
        }
        if (!empty($cells)) $rows_of_cells[] = $cells;
    }

    return trimRawRows($rows_of_cells);
}

/**
 * 🌟 మెయిన్ మార్గం: డౌన్‌లోడ్ చేసిన xlsx ఫైల్‌ను (అదే ఫైల్ uploads/cache/lineup_{slug}_debug.xlsx
 * లో కూడా సేవ్ అవుతుంది) PhpSpreadsheet తో చదివి, ఏ కాలమ్ మ్యాపింగ్ / సెక్షన్ లాజిక్ లేకుండా,
 * ఎక్సెల్‌లో ఉన్న రోస్/కాలమ్‌లను as-is గా (అదే ఆర్డర్‌లో) తిరిగి ఇస్తుంది - దీన్నే పేజీలో నేరుగా
 * ఒక టేబుల్‌గా చూపిస్తాం, డౌన్‌లోడ్ లింక్ ఇవ్వకుండా.
 * ఒకవేళ వర్క్‌బుక్‌లో ఒకటి కంటే ఎక్కువ షీట్‌లు ఉంటే, పోర్ట్ పేరుతో సరిపోలే షీట్‌ను ముందు వెతుకుతుంది.
 */
function readExcelRawRows($file_path, $port_name) {
    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        return ['rows' => null, 'sheet_used' => null, 'sheet_names' => [], 'error' => 'PhpSpreadsheet library not available.'];
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
    } catch (\Throwable $e) {
        return ['rows' => null, 'sheet_used' => null, 'sheet_names' => [], 'error' => 'Could not read the Excel file: ' . $e->getMessage()];
    }

    $sheet_names = $spreadsheet->getSheetNames();
    $worksheet = null;

    // పోర్ట్ పేరు (ఉదా. "Angre Port") తో సరిపోలే షీట్ ఏదైనా ఉందేమో చూడటం
    $port_words = preg_split('/\s+/', strtoupper(trim(preg_replace('/PORT$/i', '', $port_name))));
    foreach ($sheet_names as $name) {
        $upper_name = strtoupper($name);
        foreach ($port_words as $w) {
            if ($w !== '' && strpos($upper_name, $w) !== false) {
                $worksheet = $spreadsheet->getSheetByName($name);
                break 2;
            }
        }
    }
    if (!$worksheet) {
        $worksheet = $spreadsheet->getActiveSheet();
    }

    $raw_rows = $worksheet->toArray(null, true, true, false); // numeric-indexed columns, calculated values
    $rows = trimRawRows($raw_rows);

    return ['rows' => $rows, 'sheet_used' => $worksheet->getTitle(), 'sheet_names' => $sheet_names, 'error' => ''];
}

/**
 * ఇచ్చిన పోర్ట్ కోసం లైన్-అప్ డేటాను fetch+parse చేసి (లేదా cache నుండి) తిరిగి ఇచ్చే మెయిన్ ఫంక్షన్.
 */
function fetchPortLineup($port_info, $force_refresh = false) {
    $cache_dir = __DIR__ . '/uploads/cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    $slug = $port_info['slug'];
    $cache_file = $cache_dir . "/lineup_{$slug}.json";
    $debug_html_file = $cache_dir . "/lineup_{$slug}_debug.html";
    $debug_xlsx_file = $cache_dir . "/lineup_{$slug}_debug.xlsx";

    if (empty($port_info['source_url'])) {
        return [
            'success' => false,
            'error' => 'This port is not yet connected to a live source. Please add its voceanship.com URL in includes/indian_ports.php.',
            'rows' => [],
            'fetched_at' => date('Y-m-d H:i:s'),
            'from_cache' => false,
        ];
    }

    if (!$force_refresh && is_file($cache_file) && (time() - filemtime($cache_file)) < YMR_LINEUP_CACHE_TTL_SECONDS) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if (is_array($cached)) {
            $cached['from_cache'] = true;
            return $cached;
        }
    }

    $page = ymrCurlFetch($port_info['source_url']);
    $html = $page['body'];

    if ($html === false || $html === '' || $page['http_code'] >= 400) {
        if (is_file($cache_file)) {
            $cached = json_decode(file_get_contents($cache_file), true);
            if (is_array($cached)) {
                $cached['from_cache'] = true;
                $cached['fetch_error'] = "Live fetch failed (HTTP {$page['http_code']}) {$page['error']} - showing last saved data.";
                return $cached;
            }
        }
        return [
            'success' => false,
            'error' => "Could not fetch the source page (HTTP {$page['http_code']}). {$page['error']}",
            'rows' => [],
            'fetched_at' => date('Y-m-d H:i:s'),
            'from_cache' => false,
        ];
    }

    $source_updated = '';
    if (preg_match('/Updated\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4})/i', $html, $m)) {
        $source_updated = $m[1];
    }

    $rows = null;
    $source_type = '';
    $debug_notes = [];

    // --- మార్గం 1: దాచిన Google Sheet excelUrl ద్వారా (ప్రస్తుత voceanship.com ఫార్మాట్) ---
    // ఈ excel ఫైల్‌నే uploads/cache/lineup_{slug}_debug.xlsx లో సేవ్ చేసి, దాన్ని as-is గా చదివి పేజీలో చూపిస్తాం.
    $excel_url = extractExcelUrlFromHtml($html);
    if ($excel_url) {
        $excel = ymrCurlFetch($excel_url, 30);
        if ($excel['body'] !== false && $excel['body'] !== '' && $excel['http_code'] < 400) {
            $tmp_path = tempnam(sys_get_temp_dir(), 'ymr_lu_') . '.xlsx';
            file_put_contents($tmp_path, $excel['body']);
            @copy($tmp_path, $debug_xlsx_file); // ఇదే ఫైల్ - పేజీలో as-is table గా చూపించే మెయిన్ సోర్స్

            $excel_parsed = readExcelRawRows($tmp_path, $port_info['name']);
            @unlink($tmp_path);

            if ($excel_parsed['rows'] !== null && !empty($excel_parsed['rows'])) {
                $rows = $excel_parsed['rows'];
                $source_type = 'excel';
            } else {
                $debug_notes[] = 'Excel file downloaded but appeared to be empty. Sheet used: ' .
                    ($excel_parsed['sheet_used'] ?? '-') . '. ' . ($excel_parsed['error'] ?? '') .
                    ' Saved to uploads/cache/lineup_' . $port_info['slug'] . '_debug.xlsx for inspection.';
            }
        } else {
            $debug_notes[] = "Found an Excel link on the page but could not download it (HTTP {$excel['http_code']}) {$excel['error']}.";
        }
    } else {
        $debug_notes[] = 'No Excel download link (excelUrl) found in the page JavaScript.';
    }

    // --- మార్గం 2: ఫాల్‌బ్యాక్ - పేజీలోనే ప్లెయిన్ <table> ఉంటే, దాన్ని కూడా as-is గా చూపించడం ---
    if ($rows === null) {
        $html_rows = parseLineupFromHtmlTablesRaw($html);
        if (!empty($html_rows)) {
            $rows = $html_rows;
            $source_type = 'html_table';
        } else {
            $debug_notes[] = 'No HTML <table> with data found on the page either.';
        }
    }

    if ($rows === null) {
        // రెండు మార్గాలు ఫెయిల్ అయితే - డీబగ్ HTML సేవ్ చేసి, స్పష్టమైన ఎర్రర్ చూపించడం
        @file_put_contents($debug_html_file, $html);
        if (is_file($cache_file)) {
            $cached = json_decode(file_get_contents($cache_file), true);
            if (is_array($cached)) {
                $cached['from_cache'] = true;
                $cached['fetch_error'] = 'Could not read any table data on the latest page load - showing last saved data. Details: ' . implode(' ', $debug_notes);
                return $cached;
            }
        }
        return [
            'success' => false,
            'error' => 'The page loaded but no table data could be found. ' . implode(' ', $debug_notes) .
                ' Debug copy saved to uploads/cache/lineup_' . $port_info['slug'] . '_debug.html - open it in a browser to inspect.',
            'rows' => [],
            'source_updated' => $source_updated,
            'fetched_at' => date('Y-m-d H:i:s'),
            'from_cache' => false,
        ];
    }

    $result = [
        'success'        => true,
        'error'          => '',
        'rows'           => $rows,
        'source_updated' => $source_updated,
        'source_type'    => $source_type,
        'fetched_at'     => date('Y-m-d H:i:s'),
        'from_cache'     => false,
    ];

    @file_put_contents($cache_file, json_encode($result));
    return $result;
}

$force_refresh = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_lineup']));
$lineup = fetchPortLineup($port_info, $force_refresh);

include 'includes/header.php';
?>
<style>
    .lu-page { padding: 22px 18px 110px; }
    .lu-heading { font-size: 20px; font-weight: 750; color: var(--text-dark); margin: 0 0 6px; }
    .lu-subtitle { color: var(--text-muted); font-size: 12.5px; margin-bottom: 14px; }
    .lu-meta-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; font-size: 12px; color: var(--text-muted); background: #f8fafc; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 12px; margin-bottom: 16px; }
    .lu-refresh-btn { border: 0; border-radius: 9px; padding: 7px 12px; background: var(--accent-purple); color: #fff; font-size: 12px; font-weight: 650; white-space: nowrap; }
    .lu-card { background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 16px; margin-bottom: 16px; box-shadow: 0 4px 12px rgba(15,23,42,.04); }
    .lu-section-title { font-size: 14px; font-weight: 700; color: var(--text-dark); margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
    .lu-table-wrap { overflow-x: auto; }
    .lu-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 640px; }
    .lu-table th { text-align: left; background: #f1f5f9; color: var(--text-muted); font-weight: 650; padding: 8px 10px; white-space: nowrap; border-bottom: 1px solid var(--border-color); }
    .lu-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: var(--text-dark); white-space: nowrap; }
    .lu-group-row td { background: #f8fafc; font-weight: 650; color: var(--text-muted); font-size: 11.5px; text-transform: uppercase; letter-spacing: .02em; }
    .lu-empty-note { font-size: 12px; color: var(--text-muted); padding: 6px 2px; }
</style>
<div class="scroll-content">
    <?php $page_title = $port_info['name'] . ' Line-up'; $back_url = 'vessel_lineups.php'; $page_testid = 'vessel-line-up'; include 'includes/top_app_bar.php'; ?>
    <main class="lu-page" data-testid="vessel-line-up-page">
        <h2 class="lu-heading"><?= sanitize($port_info['name']) ?> - Vessel Line-up</h2>
        <p class="lu-subtitle">Live vessel schedule (Working / Waiting / Expected) sourced from voceanship.com.</p>

        <div class="lu-meta-bar" data-testid="lineup-meta-bar">
            <div>
                <?php if (!empty($lineup['source_updated'])): ?>
                    Source updated: <strong><?= sanitize($lineup['source_updated']) ?></strong> &middot;
                <?php endif; ?>
                Last fetched: <strong><?= sanitize($lineup['fetched_at'] ?? '-') ?></strong>
                <?= !empty($lineup['from_cache']) ? ' (cached)' : ' (live)' ?>
            </div>
            <form method="POST">
                <button type="submit" name="refresh_lineup" class="lu-refresh-btn" data-testid="lineup-refresh-button"><i class="fa-solid fa-rotate me-1"></i> Refresh Now</button>
            </form>
        </div>

        <?php if (!empty($lineup['fetch_error'])): ?>
            <div class="alert alert-warning py-2" style="font-size:12px;" data-testid="lineup-fetch-warning"><?= sanitize($lineup['fetch_error']) ?></div>
        <?php endif; ?>

        <?php if (empty($lineup['success']) && !empty($lineup['error'])): ?>
            <div class="alert alert-danger py-2" style="font-size:12px;" data-testid="lineup-fetch-error"><?= sanitize($lineup['error']) ?></div>
        <?php endif; ?>

        <?php
        // 🌟 ఈ పోర్ట్ కోసం cache అయిన excel (uploads/cache/lineup_{slug}_debug.xlsx) లో ఉన్న
        // డేటాను, ఏ కాలమ్ మ్యాపింగ్ చేయకుండా, as-is గా ఒకే టేబుల్‌గా చూపిస్తున్నాం.
        $raw_rows = $lineup['rows'] ?? [];
        ?>
        <section class="lu-card" data-testid="lineup-raw-table">
            <div class="lu-section-title"><i class="fa-solid fa-table text-primary"></i> <?= sanitize($port_info['name']) ?> Line-up (as in source excel)</div>
            <?php if (empty($raw_rows)): ?>
                <div class="lu-empty-note">No vessel data available right now.</div>
            <?php else: ?>
                <div class="lu-table-wrap">
                    <table class="lu-table lu-raw-table">
                        <tbody>
                            <?php foreach ($raw_rows as $row):
                                $non_empty = array_values(array_filter($row, function ($c) { return trim((string)$c) !== ''; }));
                            ?>
                                <?php if (count($non_empty) === 1): ?>
                                    <tr class="lu-group-row" data-testid="lineup-row">
                                        <td colspan="<?= count($row) ?>"><?= sanitize($non_empty[0]) ?></td>
                                    </tr>
                                <?php else: ?>
                                    <tr data-testid="lineup-row">
                                        <?php foreach ($row as $cell): ?>
                                            <td><?= sanitize($cell) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <p class="lu-empty-note">Data is scraped from a third-party public page and may lag behind the port's actual status. Not an official source.</p>
    </main>
</div>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
