<?php
require 'vendor/autoload.php';
require_once 'config/config.php';
checkAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

if (ob_get_length()) ob_end_clean();

$vessel_name   = trim($_POST['vessel_name'] ?? '');
$client_name   = trim($_POST['client_name'] ?? '');
$port_name     = trim($_POST['port_name'] ?? '');
$surveyor_name = trim($_POST['surveyor_name'] ?? '');
$report_number = trim($_POST['report_number'] ?? '');
$claim_date    = trim($_POST['claim_date'] ?? date('Y-m-d'));
$hotel  = (float)($_POST['hotel'] ?? 0);
$taxi   = (float)($_POST['taxi'] ?? 0);
$train  = (float)($_POST['train'] ?? 0);
$flight = (float)($_POST['flight'] ?? 0);
$food   = (float)($_POST['food'] ?? 0);
$other  = (float)($_POST['other'] ?? 0);
$remarks = trim($_POST['remarks'] ?? '');
$total = $hotel + $taxi + $train + $flight + $food + $other;

try {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Expense Claim');

    $sheet->mergeCells('A1:D1');
    $sheet->setCellValue('A1', 'YMR Marine Solutions LLP — Survey Expense Claim');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $meta = [
        ['Claim Date', date('d-m-Y', strtotime($claim_date) ?: time())],
        ['Vessel', $vessel_name],
        ['Report No', $report_number],
        ['Client', $client_name],
        ['Port', $port_name],
        ['Surveyor', $surveyor_name],
        ['Prepared By', $_SESSION['full_name'] ?? ''],
    ];
    $row = 3;
    foreach ($meta as $m) {
        $sheet->setCellValue('A' . $row, $m[0]);
        $sheet->setCellValue('B' . $row, $m[1]);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
    }

    $row += 1;
    $headerRow = $row;
    $sheet->fromArray([['#', 'Expense Type', 'Amount (INR)', 'Notes']], null, 'A' . $headerRow);

    $items = [
        [1, 'Hotel / Lodging', $hotel, ''],
        [2, 'Taxi / Local Transport', $taxi, ''],
        [3, 'Train', $train, ''],
        [4, 'Flight', $flight, ''],
        [5, 'Food / Meals', $food, ''],
        [6, 'Other', $other, $remarks],
    ];
    $r = $headerRow + 1;
    foreach ($items as $item) {
        $sheet->fromArray([$item], null, 'A' . $r);
        $r++;
    }
    $totalRow = $r;
    $sheet->setCellValue('B' . $totalRow, 'TOTAL');
    $sheet->setCellValue('C' . $totalRow, $total);
    $sheet->getStyle('B' . $totalRow . ':C' . $totalRow)->getFont()->setBold(true);

    $headerRange = 'A' . $headerRow . ':D' . $headerRow;
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FF0000');
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF00');
    $sheet->getStyle('A' . ($headerRow + 1) . ':D' . $totalRow)->getFont()->getColor()->setRGB('000000');
    $sheet->getStyle('A' . $headerRow . ':D' . $totalRow)->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('000000');

    foreach (range('A', 'D') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $safeVessel = preg_replace('/[^A-Za-z0-9_-]+/', '_', $vessel_name) ?: 'Vessel';
    $fileName = 'Expense_' . $safeVessel . '_' . date('d_m_Y') . '.xlsx';

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    echo 'Expense export error: ' . htmlspecialchars($e->getMessage());
    exit;
}
