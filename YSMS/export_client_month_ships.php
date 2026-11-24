<?php
/**
 * Excel download of the dashboard's Client-wise Ships per Month: one year, the chosen clients
 * (?client[]=…; none = all), ships per month with row and month totals. Admin / Super Admin.
 */
ob_start();
ini_set('display_errors', '0');
@set_time_limit(120);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/excel_export_helpers.php';
require_once __DIR__ . '/includes/client_month_ships.php';
checkAuth();

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin'], true)) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not authorised.');
}

$db = getDB();
try {
    $all = clientMonthShips($db);
} catch (Throwable $e) {
    error_log('export_client_month_ships: ' . $e->getMessage());
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Something went wrong while generating this export. Please try again.');
}

$year = (int)($_GET['year'] ?? date('Y'));
$data = $all[$year] ?? [];
$want = $_GET['client'] ?? [];
$want = array_values(array_filter(array_map(fn($v) => trim((string)$v), is_array($want) ? $want : [$want]), fn($v) => $v !== ''));
if ($want) {
    $keys = array_map('mb_strtoupper', $want);
    $data = array_filter($data, fn($name) => in_array(mb_strtoupper((string)$name), $keys, true), ARRAY_FILTER_USE_KEY);
}

$headers = array_merge(['Client Name'], CLIENT_MONTH_LABELS, ['Total']);
$rows = [];
$colTot = array_fill(1, 12, 0);
foreach ($data as $name => $months) {
    $row = [mb_strtoupper((string)$name)];
    $rt = 0;
    for ($m = 1; $m <= 12; $m++) { $n = (int)($months[$m] ?? 0); $row[] = $n; $rt += $n; $colTot[$m] += $n; }
    $row[] = $rt;
    $rows[] = $row;
}
$totalRow = array_merge(['TOTAL'], array_values($colTot), [array_sum($colTot)]);

$clientLabel = $want ? (count($want) <= 3 ? implode(', ', $want) : count($want) . ' clients') : '';
$baseName = buildExportFileBaseName('Client-wise Ships per Month ' . $year, [['label' => 'for', 'value' => $clientLabel]]);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    try {
        require_once $autoload;
        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Ships ' . $year);
            $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
            $sheet->setCellValue('A1', 'CLIENT-WISE SHIPS PER MONTH — ' . $year . ($clientLabel !== '' ? ' — ' . mb_strtoupper($clientLabel) : ''));
            $sheet->mergeCells('A1:' . $last . '1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
            $sheet->fromArray($headers, null, 'A2');
            $sheet->fromArray($rows ?: [['No ships attended']], null, 'A3', true);
            $tr = 3 + max(1, count($rows));
            $sheet->fromArray($totalRow, null, 'A' . $tr, true);
            $thin = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;
            $head = $sheet->getStyle('A2:' . $last . '2');
            $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $head->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
            $foot = $sheet->getStyle('A' . $tr . ':' . $last . $tr);
            $foot->getFont()->setBold(true);
            $foot->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E0E7FF');
            $sheet->getStyle('A2:' . $last . $tr)->getBorders()->getAllBorders()->setBorderStyle($thin);
            $sheet->getStyle('B2:' . $last . $tr)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getColumnDimension('A')->setWidth(36);
            for ($i = 2; $i <= count($headers); $i++) $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setWidth(8);
            $sheet->freezePane('B3');

            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $baseName . '.xlsx"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
            exit;
        }
    } catch (Throwable $e) {
        error_log('export_client_month_ships xlsx: ' . $e->getMessage());
    }
}

// CSV fallback
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
header('Cache-Control: private, max-age=0, must-revalidate');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $headers);
foreach ($rows as $r) fputcsv($out, $r);
fputcsv($out, $totalRow);
fclose($out);
exit;
