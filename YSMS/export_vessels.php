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
checkAuth();

$db = getDB();
$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

// Safety net: ensure custom_live_status exists before SELECT
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'custom_live_status'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN custom_live_status TEXT NULL DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('export_vessels.php live-status column check: ' . $e->getMessage());
}

try {
    if ($role === 'Admin') {
        $stmt = $db->prepare("
            SELECT s.vessel_name, s.agent_name, c.company_name, st.type_name,
                   u.full_name AS surveyor_name, s.custom_live_status
            FROM surveys s
            LEFT JOIN clients c ON s.client_id = c.id
            LEFT JOIN survey_types st ON s.survey_type_id = st.id
            LEFT JOIN users u ON s.surveyor_id = u.id
            WHERE s.status = 'Pending Vessel'
            ORDER BY s.id DESC
        ");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("
            SELECT s.vessel_name, s.agent_name, c.company_name, st.type_name,
                   u.full_name AS surveyor_name, s.custom_live_status
            FROM surveys s
            LEFT JOIN clients c ON s.client_id = c.id
            LEFT JOIN survey_types st ON s.survey_type_id = st.id
            LEFT JOIN users u ON s.surveyor_id = u.id
            WHERE s.status = 'Pending Vessel' AND s.surveyor_id = ?
            ORDER BY s.id DESC
        ");
        $stmt->execute([$user_id]);
    }
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    error_log('Vessel export error: ' . $e->getMessage());
    echo 'Something went wrong while generating this export. Please try again, or contact support if it keeps happening.';
    exit;
}

$headers = ['Vessel Name', 'Client', 'Agent', 'Survey Type', 'Surveyor', 'Latest Update'];
$rows = [];
foreach ($surveys as $row) {
    $rows[] = [
        $row['vessel_name'] ?? '',
        $row['company_name'] ?? '',
        $row['agent_name'] ?? 'N/A',
        $row['type_name'] ?? 'N/A',
        $row['surveyor_name'] ?? 'N/A',
        $row['custom_live_status'] ?? '',
    ];
}

$baseName = 'Pending_Vessels_' . date('d_m_Y');

// Try PhpSpreadsheet xlsx
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    try {
        require_once $autoload;
        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Pending Vessels');
            $sheet->fromArray([$headers], null, 'A1');
            $r = 2;
            foreach ($rows as $row) {
                $sheet->fromArray([$row], null, 'A' . $r);
                $r++;
            }
            foreach (range('A', 'F') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            $lastRow = max(1, $sheet->getHighestRow());
            $sheet->getStyle('A1:F1')->getFont()->setBold(true)->getColor()->setRGB('FF0000');
            $sheet->getStyle('A1:F1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FFFF00');
            $sheet->getStyle('A1:F' . $lastRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

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

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);
exit;
