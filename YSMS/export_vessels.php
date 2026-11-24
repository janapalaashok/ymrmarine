<?php
/**
 * Pending Vessels Excel/CSV export
 * - Tries PhpSpreadsheet (.xlsx) first
 * - Falls back to pure CSV if vendor/autoload or memory fails
 * - Mobile-safe download headers
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
@set_time_limit(120);
@ini_set('memory_limit', '256M');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Export Fatal Error: " . $error['message'];
    }
});

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/excel_export_helpers.php';
require_once __DIR__ . '/includes/list_filters.php';
require_once __DIR__ . '/includes/vessel_dates.php';
checkAuth();

$db = getDB();
vesselDatesEnsure($db); // ETA / ETB / ETC columns (selected below)
$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
// Admin and Super Admin see every vessel; Client sees only their own company's;
// Surveyor sees only their own assigned ones (was previously: everyone who
// isn't Admin got filtered by surveyor_id, which silently emptied the export
// for both Super Admin and Client).
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
$client_row_id = 0;
if ($role === 'Client') {
    try {
        $ccheck = $db->prepare("SELECT id FROM clients WHERE user_id = ? LIMIT 1");
        $ccheck->execute([$user_id]);
        $client_row_id = (int)($ccheck->fetchColumn() ?: 0);
    } catch (Throwable $e) { error_log('export_vessels.php client lookup: ' . $e->getMessage()); }
}

// Safety net: ensure custom_live_status exists before SELECT
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'custom_live_status'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN custom_live_status TEXT NULL DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('export_vessels.php live-status column check: ' . $e->getMessage());
}

// 🌟 SAFETY NET: appointment_date column (database/migration_appointment_date.sql).
// assign_vessel.php adds this column on its own first load, but this export's
// SELECT names the column explicitly — if nobody has opened assign_vessel.php
// on this environment yet, the column is still missing and the query below
// fails outright ("Unknown column") instead of just showing blank dates like
// vessels.php's SELECT s.* does.
try {
    $ad_exists = $db->query("SHOW COLUMNS FROM surveys LIKE 'appointment_date'")->fetchAll();
    if (empty($ad_exists)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN appointment_date DATE NULL DEFAULT NULL AFTER assign_date");
    }
} catch (Exception $e) {
    error_log('export_vessels.php appointment_date column check: ' . $e->getMessage());
}

try {
    // 🌟 Mirror vessels.php's own filter UI (search/place/client/surveyor) so
    // the export matches whatever's currently on screen: no filter applied →
    // every param below is empty → identical to the old always-full export.
    $q = trim((string)($_GET['q'] ?? ''));
    // Each filter can hold several values (multi-select on the list pages): any of them matches.
    $filter_types = listFilterValues($_GET, 'type');
    $filter_places = listFilterValues($_GET, 'place');
    $filter_clients = listFilterValues($_GET, 'client');
    $filter_surveyors = listFilterValues($_GET, 'surveyor');
    $filter_place = implode(', ', $filter_places);
    $filter_client = implode(', ', $filter_clients);
    $filter_surveyor = implode(', ', $filter_surveyors);
    $filter_appointment = trim((string)($_GET['appointment'] ?? ''));

    $where = ["s.status = 'Pending Vessel'"];
    $params = [];
    if ($role === 'Client') {
        $where[] = 's.client_id = ?';
        $params[] = $client_row_id;
    } elseif (!$is_full_access) {
        $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        $params[] = $user_id;
        $params[] = $user_id;
    }
    if ($q !== '') {
        $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if ($filter_types) { $where[] = listFilterTypeSql($filter_types, $params); }
    if ($filter_places) { $where[] = listFilterInSql('p.port_name', $filter_places, $params); }
    if ($filter_clients) { $where[] = listFilterInSql('c.company_name', $filter_clients, $params); }
    if ($is_full_access && $filter_surveyors) {
        $where[] = listFilterSurveyorNameSql($filter_surveyors, $params);
    }
    if ($filter_appointment !== '') { $where[] = 's.appointment_date = ?'; $params[] = $filter_appointment; }
    $whereSql = implode(' AND ', $where);

    $baseSql = "
        SELECT s.id, s.vessel_name, s.agent_name, c.company_name, st.type_name, s.survey_type_ids,
               u.full_name AS surveyor_name, s.custom_live_status, s.appointment_date, s.eta_date, s.etb_date, s.etc_date,
               p.port_name, p.country
        FROM surveys s
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE $whereSql
        ORDER BY s.id DESC
    ";
    $stmt = $db->prepare($baseSql);
    $stmt->execute($params);
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    error_log('Vessel export error: ' . $e->getMessage());
    echo 'Something went wrong while generating this export. Please try again, or contact support if it keeps happening.';
    exit;
}

$headers = ['Vessel Name', 'Port', 'Survey Type', 'Client', 'Agent', 'Country', 'Surveyor', 'Appointment Date', 'ETA', 'ETB', 'ETC', 'Latest Update'];
$xlDate = fn($v) => (!empty($v) && strpos((string)$v, '0000') !== 0) ? date('d M Y', strtotime((string)$v)) : '';
$rows = [];
foreach ($surveys as $row) {
    $country = $row['country'] ?? 'India';
    $rows[] = [
        'country' => $country,
        'cells' => [
            $row['vessel_name'] ?? '',
            $row['port_name'] ?? 'N/A',
            getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? 'N/A'),
            $row['company_name'] ?? '',
            $row['agent_name'] ?? 'N/A',
            $country,
            getCombinedSurveyorNames($db, $row['id'] ?? 0, $row['surveyor_name'] ?? 'N/A'),
            $xlDate($row['appointment_date'] ?? ''),
            $xlDate($row['eta_date'] ?? ''),
            $xlDate($row['etb_date'] ?? ''),
            $xlDate($row['etc_date'] ?? ''),
            $row['custom_live_status'] ?? '',
        ],
    ];
}

$apptFilterDisp = $filter_appointment !== '' ? date('d-m-Y', strtotime($filter_appointment)) : '';
$baseName = buildExportFileBaseName('Pending Vessels', [
    ['label' => 'at',          'value' => $filter_place],
    ['label' => 'for',         'value' => $filter_client],
    ['label' => 'by',          'value' => $filter_surveyor],
    ['label' => 'Appointment', 'value' => $apptFilterDisp],
]);

// Try PhpSpreadsheet xlsx
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    try {
        require_once $autoload;
        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            require_once __DIR__ . '/includes/excel_export_helpers.php';
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Pending Vessels');
            writeCountryGroupedSheet($sheet, $headers, $rows);

            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $baseName . '.xlsx"');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            header('Expires: 0');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            exit;
        }
    } catch (Throwable $e) {
        error_log('export_vessels xlsx failed: ' . $e->getMessage());
    }
}

// CSV fallback (always works, no vendor needed)
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

require_once __DIR__ . '/includes/excel_export_helpers.php';
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
foreach (groupRowsByCountry($rows) as $countryName => $countryRows) {
    fputcsv($out, [$countryName]);
    fputcsv($out, $headers);
    foreach ($countryRows as $cells) {
        fputcsv($out, $cells);
    }
    fputcsv($out, []);
}
fclose($out);
exit;
