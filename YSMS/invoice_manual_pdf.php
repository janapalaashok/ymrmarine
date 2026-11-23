<?php

require_once 'config/config.php';
require_once 'includes/invoice_helpers.php';
require_once 'includes/invoice_pdf_template.php';

checkAuth();

if (!invoiceCanManage()) {
    http_response_code(403);
    exit('Not authorised.');
}

$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit('Composer dependencies are missing.');
}

require_once $autoload;

if (!class_exists(\Dompdf\Dompdf::class)) {
    http_response_code(500);
    exit('Dompdf is not installed.');
}

$db = getDB();

function impText(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function impNum(string $key): float
{
    $v = trim((string)($_POST[$key] ?? ''));

    if ($v === '' || !is_numeric($v)) {
        return 0.0;
    }

    return (float)$v;
}


/* ---------------------------------------------------------
 * Client
 * ------------------------------------------------------ */

$clientId = (int)($_POST['client_id'] ?? 0);

$clientName = '';
$clientAddr1 = '';
$clientAddr2 = '';

if ($clientId > 0) {

    $stmt = $db->prepare("
        SELECT
            company_name,
            address_line1,
            address_line2
        FROM clients
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$clientId]);

    if ($client = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $clientName  = (string)$client['company_name'];
        $clientAddr1 = (string)($client['address_line1'] ?? '');
        $clientAddr2 = (string)($client['address_line2'] ?? '');
    }
}


/* ---------------------------------------------------------
 * Main service
 * ------------------------------------------------------ */

$items = [];

$serviceDesc = impText('service_desc');
$serviceRate = impNum('service_rate');
$serviceQty  = impNum('service_qty');

if ($serviceDesc !== '' && $serviceQty > 0) {

    $items[] = [
        'desc' => $serviceDesc,
        'rate' => $serviceRate,
        'qty'  => $serviceQty,
    ];
}


/* ---------------------------------------------------------
 * Optional travel cost
 * ------------------------------------------------------ */

$travel = impNum('travel_cost');

if ($travel > 0) {

    $items[] = [
        'desc' => 'TRAVEL COST',
        'rate' => $travel,
        'qty'  => 1,
    ];
}


/* ---------------------------------------------------------
 * Optional launch cost
 * ------------------------------------------------------ */

$launch = impNum('launch_cost');

if ($launch > 0) {

    $items[] = [
        'desc' => 'LAUNCH COST',
        'rate' => $launch,
        'qty'  => 1,
    ];
}


if (!$items) {
    http_response_code(422);
    exit('Please add at least one invoice service.');
}


/* ---------------------------------------------------------
 * PDF data
 * ------------------------------------------------------ */

$d = [
    'title'            => 'TAX INVOICE',

    'invoice_no'       => impText('invoice_no'),
    'invoice_date'     => impText('invoice_date'),

    'client'           => $clientName,
    'client_addr1'     => $clientAddr1,
    'client_addr2'     => $clientAddr2,

    'supplier_ref'     => impText('supplier_ref'),
    'other_ref'        => impText('other_ref'),

    'vessel'           => impText('vessel_name'),
    'survey_type'      => impText('survey_type_text'),
    'port'             => impText('port_name'),
    'survey_completed' => impText('survey_completed_display'),

    'note'             => impText('invoice_notes'),

    'items'            => $items,
];


/* ---------------------------------------------------------
 * Render
 * ------------------------------------------------------ */

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);

$pdf = new \Dompdf\Dompdf($options);

$pdf->loadHtml(invoicePdfHtml($d));
$pdf->setPaper('A4', 'portrait');
$pdf->render();


/* ---------------------------------------------------------
 * Preview / Download
 * ------------------------------------------------------ */

$mode = impText('mode');

$download = $mode === 'download';

$fileName = preg_replace(
    '/[^A-Za-z0-9._-]+/',
    '_',
    $d['invoice_no'] !== ''
        ? $d['invoice_no']
        : 'YMR-Invoice'
);

$fileName .= '.pdf';

$pdf->stream(
    $fileName,
    [
        'Attachment' => $download
    ]
);

exit;