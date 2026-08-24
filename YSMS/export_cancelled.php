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

try {
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    if ($role === 'Admin') {
        $stmt = $db->prepare("
            SELECT s.vessel_name, s.agent_name, c.company_name, t.type_name, u.full_name as surveyor_name, s.assign_date, s.report_number
            FROM surveys s
            LEFT JOIN clients c ON s.client_id = c.id
            LEFT JOIN survey_types t ON s.survey_type_id = t.id
            LEFT JOIN users u ON s.surveyor_id = u.id
            WHERE s.status = 'Cancelled'
            ORDER BY s.id DESC
        ");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("
            SELECT s.vessel_name, s.agent_name, c.company_name, t.type_name, u.full_name as surveyor_name, s.assign_date, s.report_number
            FROM surveys s
            LEFT JOIN clients c ON s.client_id = c.id
            LEFT JOIN survey_types t ON s.survey_type_id = t.id
            LEFT JOIN users u ON s.surveyor_id = u.id
            WHERE s.status = 'Cancelled' AND s.surveyor_id = ?
            ORDER BY s.id DESC
        ");
        $stmt->execute([$user_id]);
    }
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Cancelled Vessels');

    $headers = ['Vessel Name', 'Report No', 'Client', 'Agent', 'Survey Type', 'Surveyor', 'Assigned Date'];
    $sheet->fromArray([$headers], null, 'A1');

    $rowNum = 2;
    foreach ($surveys as $row) {
        $sheet->setCellValue('A' . $rowNum, $row['vessel_name'] ?? '');
        $sheet->setCellValue('B' . $rowNum, $row['report_number'] ?? '');
        $sheet->setCellValue('C' . $rowNum, $row['company_name'] ?? '');
        $sheet->setCellValue('D' . $rowNum, $row['agent_name'] ?? 'N/A');
        $sheet->setCellValue('E' . $rowNum, $row['type_name'] ?? 'N/A');
        $sheet->setCellValue('F' . $rowNum, $row['surveyor_name'] ?? 'N/A');
        $sheet->setCellValue('G' . $rowNum, !empty($row['assign_date']) ? date('d-m-Y H:i', strtotime($row['assign_date'])) : '');
        $rowNum++;
    }

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $lastCol = 'G';
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

    $fileName = 'Cancelled_Vessels_' . date('d_m_Y') . '.xlsx';
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
