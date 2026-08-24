<?php
/**
 * Server-side invoice fill — preserves Excel layout (ZIP/XML replace).
 */
require_once 'config/config.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die('Server error: ZipArchive extension is not enabled. Please contact hosting support.');
}

$db = getDB();

$template_id  = (int)($_POST['template_id'] ?? 0);
$invoice_no   = trim($_POST['invoice_no'] ?? '');
$invoice_date = trim($_POST['invoice_date'] ?? '');
$client       = trim($_POST['client'] ?? '');
$client_addr1 = trim($_POST['client_addr1'] ?? '');
$client_addr2 = trim($_POST['client_addr2'] ?? '');
$description  = trim($_POST['description'] ?? '');
$unit         = trim($_POST['unit'] ?? '');
$quantity     = trim($_POST['quantity'] ?? '');
$supplier_ref = trim($_POST['supplier_ref'] ?? '');
$other_ref    = trim($_POST['other_ref'] ?? '');
$vessel       = trim($_POST['vessel'] ?? 'Vessel');

if ($template_id <= 0 || $invoice_no === '' || $invoice_date === '' || $client_addr1 === '' || $description === '' || $unit === '' || $quantity === '') {
    http_response_code(400);
    die('Missing required fields. Go back and fill all required fields.');
}

// Format date like "07 JULY 2026"
$dateFmt = $invoice_date;
$ts = strtotime($invoice_date);
if ($ts) {
    $dateFmt = strtoupper(date('d F Y', $ts));
}

try {
    $row = $db->prepare("SELECT * FROM invoice_templates WHERE id = ?");
    $row->execute([$template_id]);
    $tpl = $row->fetch();
} catch (Exception $e) {
    error_log('generate_invoice template query: ' . $e->getMessage());
    http_response_code(500);
    die('Database error loading template. Ensure invoice_templates table exists.');
}

if (!$tpl) {
    http_response_code(404);
    die('Template not found.');
}

$src = __DIR__ . '/' . ltrim(str_replace(['..', '\\'], '', $tpl['file_path']), '/');
$srcReal = realpath($src);
$baseReal = realpath(__DIR__ . '/invoice_templates');
if ($srcReal === false || $baseReal === false || strpos($srcReal, $baseReal) !== 0 || !is_file($srcReal)) {
    http_response_code(404);
    die('Template file missing on server. Re-upload under Admin → Invoice Templates.');
}

$map = [
    '{{INVOICE_NO}}'    => $invoice_no,
    '{{INVOICE_DATE}}'  => $dateFmt,
    '{{CLIENT}}'        => $client,
    '{{CLIENT_ADDR1}}'  => $client_addr1,
    '{{CLIENT_ADDR2}}'  => $client_addr2,
    '{{DESCRIPTION}}'   => $description,
    '{{UNIT}}'          => $unit,
    '{{QUANTITY}}'      => $quantity,
    '{{SUPPLIER_REF}}'  => $supplier_ref,
    '{{OTHER_REF}}'     => $other_ref,
];
uksort($map, function ($a, $b) {
    return strlen($b) - strlen($a);
});

function inv_xml_escape($val) {
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function inv_apply_map($content, $map) {
    foreach ($map as $ph => $val) {
        $content = str_replace($ph, inv_xml_escape($val), $content);
    }
    return $content;
}

/**
 * Convert inlineStr cells that are pure numbers into numeric cells
 * so Excel formulas still work for UNIT / QUANTITY.
 */
function inv_inline_str_to_number($xml) {
    $offset = 0;
    $out = '';
    $needle = 't="inlineStr"';
    while (($pos = strpos($xml, $needle, $offset)) !== false) {
        $cStart = strrpos(substr($xml, 0, $pos), '<c ');
        if ($cStart === false) {
            $cStart = strrpos(substr($xml, 0, $pos), '<c>');
        }
        if ($cStart === false) {
            $out .= substr($xml, $offset, $pos - $offset + strlen($needle));
            $offset = $pos + strlen($needle);
            continue;
        }
        $cEnd = strpos($xml, '</c>', $pos);
        if ($cEnd === false) {
            break;
        }
        $cEnd += 4;
        $cell = substr($xml, $cStart, $cEnd - $cStart);
        $out .= substr($xml, $offset, $cStart - $offset);

        if (preg_match('/<t[^>]*>([^<]*)<\/t>/', $cell, $tm) && is_numeric(trim(html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8')))) {
            $num = trim(html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8'));
            $attrs = '';
            if (preg_match('/\br="([^"]+)"/', $cell, $rm)) {
                $attrs .= ' r="' . $rm[1] . '"';
            }
            if (preg_match('/\bs="([^"]+)"/', $cell, $sm)) {
                $attrs .= ' s="' . $sm[1] . '"';
            }
            $out .= '<c' . $attrs . '><v>' . $num . '</v></c>';
        } else {
            $out .= $cell;
        }
        $offset = $cEnd;
    }
    $out .= substr($xml, $offset);
    return $out;
}

/**
 * Write a calculated number into a cell (e.g. J25 Amount, J38 Total).
 * Keeps style (s=) if present. Replaces formula cells with a plain number
 * so the value is visible immediately without Excel recalculation.
 */
function inv_set_cell_number($xml, $cellRef, $number) {
    $num = rtrim(rtrim(number_format((float)$number, 4, '.', ''), '0'), '.');
    if ($num === '' || $num === '-') {
        $num = '0';
    }
    // Match self-closing or full cell for this ref
    $pattern = '/<c\b([^>]*\br="' . preg_quote($cellRef, '/') . '"[^>]*)(?:\/>|>(?:.*?)<\/c>)/s';
    if (!preg_match($pattern, $xml, $m)) {
        return $xml;
    }
    $attrs = $m[1];
    // Drop type attribute (t="str" / t="e" / t="inlineStr") so it is a number cell
    $attrs = preg_replace('/\s*t="[^"]*"/', '', $attrs);
    $newCell = '<c' . $attrs . '><v>' . $num . '</v></c>';
    return preg_replace($pattern, $newCell, $xml, 1);
}

/**
 * Force Excel to recalculate formulas when the file is opened.
 */
function inv_force_full_calc($xml) {
    if (strpos($xml, 'fullCalcOnLoad') !== false) {
        $xml = preg_replace('/fullCalcOnLoad="[^"]*"/', 'fullCalcOnLoad="1"', $xml);
    } elseif (strpos($xml, '<calcPr') !== false) {
        $xml = preg_replace('/<calcPr\b/', '<calcPr fullCalcOnLoad="1"', $xml, 1);
    } else {
        $xml = str_replace('</workbook>', '<calcPr fullCalcOnLoad="1" calcId="191028"/></workbook>', $xml);
    }
    return $xml;
}

$tmpDir = __DIR__ . '/invoice_templates';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0755, true);
}
$tmpIn = $tmpDir . '/_tmp_src_' . uniqid('', true) . '.xlsx';
$tmpOut = $tmpDir . '/_tmp_out_' . uniqid('', true) . '.xlsx';
if (!@copy($srcReal, $tmpIn)) {
    http_response_code(500);
    die('Could not prepare template file. Check invoice_templates folder permissions (755).');
}

$zin = new ZipArchive();
if ($zin->open($tmpIn) !== true) {
    @unlink($tmpIn);
    @unlink($tmpOut);
    http_response_code(500);
    die('Invalid Excel template (could not open).');
}

$zout = new ZipArchive();
if ($zout->open($tmpOut, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
    $zin->close();
    @unlink($tmpIn);
    @unlink($tmpOut);
    http_response_code(500);
    die('Could not create output file.');
}

for ($i = 0; $i < $zin->numFiles; $i++) {
    $name = $zin->getNameIndex($i);
    if ($name === false) {
        continue;
    }
    // Skip directories
    if (substr($name, -1) === '/') {
        $zout->addEmptyDir(rtrim($name, '/'));
        continue;
    }
    $content = $zin->getFromIndex($i);
    if ($content === false) {
        continue;
    }

    if (preg_match('/\.xml$/i', $name) && strpos($name, 'xl/') === 0) {
        $content = inv_apply_map($content, $map);
        if (strpos($name, 'worksheets/') !== false) {
            $content = inv_inline_str_to_number($content);
            // Pre-compute Amount = UNIT * QUANTITY and Total so values show
            // immediately even if Excel does not recalculate formulas.
            $unitNum = is_numeric($unit) ? (float)$unit : 0.0;
            $qtyNum  = is_numeric($quantity) ? (float)$quantity : 0.0;
            $amount  = $unitNum * $qtyNum;
            $content = inv_set_cell_number($content, 'J25', $amount);
            $content = inv_set_cell_number($content, 'J38', $amount);
        }
        if ($name === 'xl/workbook.xml' || substr($name, -13) === 'workbook.xml') {
            $content = inv_force_full_calc($content);
        }
    }

    $zout->addFromString($name, $content);
}

$zin->close();
$zout->close();
@unlink($tmpIn);

$safeVessel = preg_replace('/[\\\\\\/:*?"<>|]+/', '_', $vessel);
$safeInv = preg_replace('/[\\\\\\/:*?"<>|]+/', '_', $invoice_no);
$downloadName = trim($safeInv . ' ' . $safeVessel) . ' - Invoice.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . filesize($tmpOut));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
readfile($tmpOut);
@unlink($tmpOut);
exit;
