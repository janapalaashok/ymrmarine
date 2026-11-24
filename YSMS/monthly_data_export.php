<?php
// Monthly Data → Excel (.xlsx) of one tab and month: the rows shown on the page (filtered, in the
// shown order; ids=all → every row of the month). Admin / Super Admin. POST (CSRF checked in config).
ob_start();
ini_set('display_errors', '0');
require 'vendor/autoload.php';
require_once 'config/config.php';
require_once __DIR__ . '/includes/excel_export_helpers.php';
require_once __DIR__ . '/includes/monthly_data.php';
checkAuth();
if (!mdCanView()) { while (ob_get_level() > 0) { ob_end_clean(); } http_response_code(403); exit('Not allowed.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: monthly_data.php'); exit; }

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

try {
    @set_time_limit(120);
    $db = getDB();
    $tab = mdTab($_POST);
    $day = $tab === 'appointments' ? mdDay($_POST) : ''; // one day (Today's Appointments)
    [$y, $m, $start] = mdMonth($day !== '' ? ['month' => substr($day, 0, 7)] : $_POST);
    [$all, $tot] = mdData($db, $tab, $y, $m, $day);
    $cols = mdColumns($tab);

    // Only rows of this month (ids the page sent that are not in the month are ignored), in the page's order.
    $idsRaw = trim((string)($_POST['ids'] ?? 'all'));
    if ($idsRaw === 'all') {
        $rows = $all;
    } else {
        $byId = [];
        foreach ($all as $r) $byId[$r['id']] = $r;
        $rows = [];
        foreach (array_unique(array_filter(array_map('intval', explode(',', $idsRaw)))) as $id) {
            if (isset($byId[$id])) $rows[] = $byId[$id];
        }
    }
    $filtered = count($rows) !== count($all);

    $label = $day !== '' ? date('d F Y', strtotime($day)) : date('F Y', strtotime($start));
    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle(substr(MD_TABS[$tab] . ' ' . date('M Y', strtotime($start)), 0, 31));
    $headers = array_merge(['#'], array_map(fn($c) => $c[1], $cols));
    $last = Coordinate::stringFromColumnIndex(count($headers));

    $sheet->setCellValue('A1', MD_TABS[$tab] . ' — ' . $label . ($filtered ? ' (filtered: ' . count($rows) . ' of ' . count($all) . ')' : ''));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sheet->fromArray([$headers], null, 'A3');
    $hs = $sheet->getStyle('A3:' . $last . '3');
    $hs->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $hs->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4B2FD6');

    $rowNum = 4;
    $sum = ['vlsfo' => 0.0, 'lsmgo' => 0.0, 'hsfo' => 0.0, 'total' => 0.0];
    foreach ($rows as $n => $r) {
        $cells = [$n + 1];
        foreach ($cols as [$key, , $kind]) $cells[] = mdExcelCell($r, $key, $kind);
        $sheet->fromArray([$cells], null, 'A' . $rowNum, true);
        if ($tab === 'recovery' && !$r['na']) foreach ($sum as $k => $v) $sum[$k] += abs((float)($r[$k] ?? 0));
        $rowNum++;
    }
    if (!$rows) {
        $sheet->setCellValue('A4', 'No rows.');
        $rowNum = 5;
    }
    $sheet->setCellValue('A' . $rowNum, ($tab === 'appointments' ? 'Appointments: ' : 'Surveys completed: ') . count($rows));
    if ($tab === 'recovery') {
        $first = count($headers) - 3; // VLSFO, LSMGO, HSFO, Total are the last four columns
        foreach (['vlsfo', 'lsmgo', 'hsfo', 'total'] as $i => $k) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($first + $i) . $rowNum, round($sum[$k], 3));
        }
        $sheet->getStyle(Coordinate::stringFromColumnIndex($first) . '4:' . $last . $rowNum)->getNumberFormat()->setFormatCode('0.000');
    }
    $sheet->getStyle('A' . $rowNum . ':' . $last . $rowNum)->getFont()->setBold(true);
    $sheet->getStyle('A3:' . $last . $rowNum)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
    for ($i = 1; $i <= count($headers); $i++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    $sheet->freezePane('A4');

    while (ob_get_level() > 0) { ob_end_clean(); }
    $fileName = exportFileName('Monthly Data ' . MD_TABS[$tab] . ' ' . $label . ($filtered ? ' Filtered' : '')) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    (new Xlsx($ss))->save('php://output');
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
    error_log('monthly_data_export: ' . $e->getMessage());
    echo 'Something went wrong while generating this export. Please try again.';
    exit;
}
