<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

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

require 'vendor/autoload.php';
require_once 'config/config.php';
checkAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$db = getDB();
$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

$period = isset($_GET['period']) && $_GET['period'] === 'month' ? 'month' : 'total';

try {
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    $sql = "
        SELECT s.vessel_name, s.survey_type_ids, t.type_name,
               s.vlsfo_recovery, s.lsmgo_recovery, s.recovery_amount, s.report_uploaded_date
        FROM surveys s
        LEFT JOIN survey_types t ON s.survey_type_id = t.id
        WHERE s.recovery_amount IS NOT NULL
    ";
    $params = [];

    if ($period === 'month') {
        $sql .= " AND MONTH(s.report_uploaded_date) = MONTH(CURDATE()) AND YEAR(s.report_uploaded_date) = YEAR(CURDATE())";
    }

    if ($role !== 'Admin') {
        $sql .= " AND s.surveyor_id = ?";
        $params[] = $user_id;
    }

    $sql .= " ORDER BY s.report_uploaded_date DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($period === 'month' ? 'This Month Recovery' : 'Total Recovery');

    $headers = ['Vessel Name', 'Survey Type', 'VLSFO Recovery', 'LSMGO Recovery', 'Total Recovery'];
    $sheet->fromArray([$headers], null, 'A1');

    $rowNum = 2;
    foreach ($surveys as $row) {
        $typeLabel = $row['type_name'] ?? 'N/A';
        if (function_exists('getCombinedSurveyTypeNames')) {
            $typeLabel = getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? 'N/A');
        }
        $sheet->setCellValue('A' . $rowNum, $row['vessel_name'] ?? '');
        $sheet->setCellValue('B' . $rowNum, $typeLabel);
        $sheet->setCellValue('C' . $rowNum, !empty($row['vlsfo_recovery']) ? (float)$row['vlsfo_recovery'] : 0);
        $sheet->setCellValue('D' . $rowNum, !empty($row['lsmgo_recovery']) ? (float)$row['lsmgo_recovery'] : 0);
        $sheet->setCellValue('E' . $rowNum, !empty($row['recovery_amount']) ? (float)$row['recovery_amount'] : 0);
        $rowNum++;
    }

    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $lastCol = $sheet->getHighestColumn();
    $lastRow = max(1, $sheet->getHighestRow());
    $headerRange = 'A1:' . $lastCol . '1';
    $dataRange = 'A1:' . $lastCol . $lastRow;
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FF0000');
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF00');
    if ($lastRow >= 2) {
        $sheet->getStyle('A2:' . $lastCol . $lastRow)->getFont()->getColor()->setRGB('000000');
        $sheet->getStyle('A2:' . $lastCol . $lastRow)->getFill()->setFillType(Fill::FILL_NONE);
    }
    $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('000000');

    while (ob_get_level() > 0) { ob_end_clean(); }

    $fileNamePrefix = $period === 'month' ? 'This_Month_Recovery_' : 'Total_Recovery_';
    $fileName = $fileNamePrefix . date('d_m_Y') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Export Error: ' . $e->getMessage();
    exit;
}
