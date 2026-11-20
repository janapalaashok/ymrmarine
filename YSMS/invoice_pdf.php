<?php
/**
 * Invoices → Generate Invoice / Recovery Invoice: a form pre-filled from the vessel's survey
 * record, a live PDF preview and the PDF download, on the YMR letterhead. Admin only.
 * Template: includes/invoice_pdf_template.php.
 *
 *   ?id=N                 vessel invoice: survey service, Travel Cost, Launch Cost, added services
 *   ?id=N&type=recovery   recovery invoice: description + "COMMISSION ON RECOVERY @<agreed %>% OF CP
 *                         PRICE FOR <fuel>" lines for VLSFO, HSFO (when not 0) and LSMGO; the % is only
 *                         wording, each amount is unit x qty
 *
 * Invoice number: YMR-<client short code>-<n> (includes/invoice_helpers.php), given to the
 * vessel the first time an invoice is downloaded and kept; the recovery invoice is that
 * number + "B". Survey data is only read.
 *
 * Download stores the PDF (uploads/invoices/, served by ajax/invoices.php?a=file) and the form
 * (gen_data / rec_gen_data, so Modify re-opens it filled in). Before the vessel invoice
 * downloads, the page asks whether a recovery invoice is needed too:
 *   - no  (with_recovery=0) → the invoice is Completed straight away
 *   - yes (with_recovery=1) → it is Completed once the recovery invoice is downloaded as well
 * A recovery invoice download completes it when the vessel invoice is already there.
 */
require_once 'config/config.php';
require_once 'includes/invoice_helpers.php';
require_once 'includes/invoice_pdf_template.php';
checkAuth();
if (!invoiceCanManage()) { header('Location: index.php'); exit; }

$db = getDB();
invoiceEnsureTable($db);
$id = (int)($_REQUEST['id'] ?? 0);
$isRecovery = ($_REQUEST['type'] ?? '') === 'recovery';
$st = $db->prepare(invoiceRowSql() . ' WHERE i.id = ?');
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); exit('Invoice not found.'); }
if (!empty($inv['deleted_at'])) { http_response_code(409); exit('This entry was deleted from Invoices. Restore it on the Invoices page (Deleted) to make its invoice.'); }

$addr1 = $addr2 = '';
if (!empty($inv['eff_client_id'])) { // the entry's client (its survey's, or its own for a manual / edited entry)
    try { // address columns are added by invoice_generator.php / migration_client_address.sql
        $a = $db->prepare('SELECT c.address_line1, c.address_line2 FROM clients c WHERE c.id = ?');
        $a->execute([(int)$inv['eff_client_id']]);
        if ($ar = $a->fetch(PDO::FETCH_ASSOC)) { $addr1 = (string)$ar['address_line1']; $addr2 = (string)$ar['address_line2']; }
    } catch (Throwable $e) { /* no address columns yet */ }
}
if (!empty($inv['sid'])) {
    $rq = $db->prepare('SELECT vlsfo_recovery, hsfo_recovery, lsmgo_recovery FROM surveys WHERE id = ?');
    $rq->execute([(int)$inv['sid']]);
    $inv = array_merge($inv, $rq->fetch(PDO::FETCH_ASSOC) ?: []);
}
$vessel = (string)($inv['s_vessel_name'] ?? $inv['vessel_name'] ?? '');
$type = invoiceTypeNames($db, $inv);
$completed = !empty($inv['survey_completed_date']) && strpos((string)$inv['survey_completed_date'], '0000') !== 0 ? date('d M Y', strtotime($inv['survey_completed_date'])) : '';

$defaults = [
    'invoice_no' => (string)($inv['invoice_no'] ?? ''),
    'invoice_date' => (string)($inv['invoice_date'] ?? '') ?: date('Y-m-d'),
    'client' => (string)($inv['company_name'] ?? ''),
    'client_addr1' => $addr1,
    'client_addr2' => $addr2,
    'supplier_ref' => '', // typed by the Admin (not filled from the record)
    'other_ref' => '',
    'vessel' => $vessel,
    'survey_type' => $type,
    'port' => (string)($inv['port_name'] ?? ''),
    'survey_completed' => $completed,
];
$serviceDesc = trim($type . ' of ' . $vessel . ($inv['port_name'] ? ' at ' . $inv['port_name'] : '') . ($completed ? ' on ' . $completed : ''));
$serviceRate = (string)($inv['invoice_amount'] ?? '');
$noFixed = trim((string)($inv['invoice_no'] ?? '')) !== '';
$invoiceNo = $isRecovery ? invoiceRecNo($inv) : ($noFixed ? (string)$inv['invoice_no'] : ''); // typed by hand (no automatic series); recovery: its own number or + B
$hasVesselFile = invoiceFileAbs($inv['file_path'] ?? null) !== null;
$hasRecFile = invoiceFileAbs($inv['rec_file_path'] ?? null) !== null;
// The form as last downloaded (Modify); the recovery form borrows the vessel invoice's header
// fields (client, references…) until it has been downloaded itself.
$savedVessel = json_decode((string)($inv['gen_data'] ?? ''), true) ?: [];
$saved = $isRecovery ? (json_decode((string)($inv['rec_gen_data'] ?? ''), true) ?: []) : $savedVessel;
$savedHdr = $saved ?: $savedVessel;
$sv = function (string $k, $def, bool $hdr = false) use ($saved, $savedHdr) {
    $src = $hdr ? $savedHdr : $saved;
    return isset($src[$k]) && is_scalar($src[$k]) ? (string)$src[$k] : (string)$def;
};
foreach (['invoice_date', 'client', 'client_addr1', 'client_addr2', 'supplier_ref', 'other_ref', 'vessel', 'survey_type', 'port', 'survey_completed'] as $k) {
    $defaults[$k] = $sv($k, $defaults[$k], true);
}
// Recovery quantities recorded on the vessel (MT, stored positive)
$recQty = [];
foreach (['vlsfo', 'hsfo', 'lsmgo'] as $fuel) {
    $v = $inv[$fuel . '_recovery'] ?? null;
    $recQty[$fuel] = $v !== null && $v !== '' ? rtrim(rtrim(number_format(abs((float)$v), 3, '.', ''), '0'), '.') : '';
}
const IG_FUELS = ['vlsfo' => 'VLSFO', 'hsfo' => 'HSFO', 'lsmgo' => 'LSMGO', 'ulsfo' => 'ULSFO'];
/** Order of the agreed CP price fields on the form. */
const IG_CP_ORDER = ['vlsfo', 'lsmgo', 'hsfo', 'ulsfo'];
/** Fuels that may be left empty on the recovery invoice (listed only when they have an amount). */
const IG_FUELS_OPTIONAL = ['hsfo', 'ulsfo'];

/** A posted amount / quantity: float, or null when not a valid number ≥ 0. */
function igNum($v): ?float {
    $v = str_replace([',', ' '], '', trim((string)$v));
    return ($v !== '' && is_numeric($v) && (float)$v >= 0) ? (float)$v : null;
}

// ---------- PDF (POST from the form: preview in the frame, or download) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $d = [];
    foreach ($defaults as $k => $v) $d[$k] = mb_substr(trim((string)($_POST[$k] ?? $v)), 0, 255);
    if ($d['invoice_date'] !== '' && !DateTime::createFromFormat('!Y-m-d', $d['invoice_date'])) $d['invoice_date'] = '';
    $errors = [];
    $items = [];
    if ($isRecovery) {
        // Description line (no amount), then commission on each fuel's recovery.
        $d['note'] = mb_substr(trim((string)($_POST['service_desc'] ?? '')), 0, 2000);
        $priceMode = ($_POST['price_mode'] ?? 'cp') === 'plat' ? 'plat' : 'cp';

        $pct = igNum($_POST['recovery_pct'] ?? '');

        if ($pct === null || $pct <= 0 || $pct > 100) {
            $errors[] = 'Agreed recovery %';
        }

        $fuelRows = [];

        foreach (IG_FUELS as $k => $label) {

            $rate = igNum($_POST[$k . '_rate'] ?? '');
            $qty = igNum($_POST[$k . '_qty'] ?? '');

            if ($priceMode === 'cp') {

                // CP mode:
                // Unit = agreed CP price × agreed recovery %
                $cp = igNum($_POST['cp_' . $k] ?? '');

                if (
                    $rate === null &&
                    $cp !== null &&
                    $pct !== null &&
                    trim((string)($_POST[$k . '_rate'] ?? '')) === ''
                ) {
                    $rate = round($cp * $pct / 100, 2);
                }

            } else {

                // PLAT mode:
                // Unit = quoted PLAT price directly
                $plat = igNum($_POST['plat_' . $k] ?? '');

                if (
                    $rate === null &&
                    $plat !== null &&
                    trim((string)($_POST[$k . '_rate'] ?? '')) === ''
                ) {
                    if (
                        $rate === null &&
                        $plat !== null &&
                        $pct !== null &&
                        trim((string)($_POST[$k . '_rate'] ?? '')) === ''
                    ) {
                        $rate = round($plat * $pct / 100, 2);
                    }
                }
            }

            if (
                in_array($k, IG_FUELS_OPTIONAL, true) &&
                trim((string)($_POST[$k . '_rate'] ?? '')) === '' &&
                $rate === null
            ) {
                $rate = 0.0;
            }

            if (
                in_array($k, IG_FUELS_OPTIONAL, true) &&
                trim((string)($_POST[$k . '_qty'] ?? '')) === ''
            ) {
                $qty = 0.0;
            }

            if ($rate === null || $qty === null) {
                $errors[] = $label . ' unit and quantity (enter 0 if there is none)';
                continue;
            }

            $fuelRows[$k] = [
                'rate' => $rate,
                'qty'  => $qty,
                'base_price' => $priceMode === 'plat'
                    ? igNum($_POST['plat_' . $k] ?? '')
                    : igNum($_POST['cp_' . $k] ?? '')
            ];
        }

        if (!$errors) {

            $pctTxt = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');

            foreach ($fuelRows as $k => $r) {

                if (
                    in_array($k, IG_FUELS_OPTIONAL, true) &&
                    ($r['rate'] * $r['qty']) <= 0
                ) {
                    continue;
                }

                if ($priceMode === 'cp') {

                    $platTxt = rtrim(
                        rtrim(number_format((float)$r['base_price'], 2, '.', ''), '0'),
                        '.'
                    );

                    $desc =
                        'COMMISSION ON RECOVERY @' .
                        $pctTxt .
                        '% OF CP PRICE FOR ' .
                        IG_FUELS[$k] .
                        ' ' .
                        $platTxt .
                        ' PMT';

                } else {

                    $platTxt = rtrim(
                        rtrim(number_format((float)$r['base_price'], 2, '.', ''), '0'),
                        '.'
                    );

                    $desc =
                        'COMMISSION ON RECOVERY @' .
                        $pctTxt .
                        '% OF PLAT PRICE FOR ' .
                        IG_FUELS[$k] .
                        ' ' .
                        $platTxt .
                        ' PMT';
                }

                $items[] = [
                    'desc' => $desc,
                    'rate' => $r['rate'],
                    'qty'  => $r['qty']
                ];
            }
        }
    } else {
        // Services: the survey service, then Travel Cost and Launch Cost (always on the
        // invoice), then any services added on the form.
        $desc = mb_substr(trim((string)($_POST['service_desc'] ?? '')), 0, 2000);
        $items[] = ['desc' => $desc !== '' ? $desc : $serviceDesc, 'rate' => igNum($_POST['service_rate'] ?? '') ?? 0.0, 'qty' => igNum($_POST['service_qty'] ?? '') ?? 1.0];
        foreach (['travel' => 'Travel Cost', 'launch' => 'Launch Cost'] as $k => $label) {
            $rate = igNum($_POST[$k . '_rate'] ?? '');
            $qty = igNum($_POST[$k . '_qty'] ?? '');
            if ($rate === null || $qty === null) { $errors[] = $label . ' (enter 0 if there is none)'; continue; }
            $items[] = ['desc' => $label, 'rate' => $rate, 'qty' => $qty];
        }
        $ed = (array)($_POST['extra_desc'] ?? []);
        foreach (array_slice(array_keys($ed), 0, 30) as $i) {
            $xd = mb_substr(trim((string)$ed[$i]), 0, 500);
            $xr = igNum($_POST['extra_rate'][$i] ?? '');
            $xq = igNum($_POST['extra_qty'][$i] ?? '');
            if ($xd === '' && $xr === null) continue; // empty added row
            if ($xd === '' || $xr === null) { $errors[] = 'Added service (description and unit)'; continue; }
            $items[] = ['desc' => $xd, 'rate' => $xr, 'qty' => $xq ?? 1.0];
        }
    }
    // The vessel invoice's number is typed by hand (required to download). Only digits: the
    // number keeps its client prefix (YMR-BB-962), or gets the client's code when it has none.
    // It must not be another invoice's. The recovery invoice uses the vessel's number + B.
    $fixNo = null;
    $download = ($_POST['mode'] ?? '') === 'download';
    if (!$isRecovery) {
        $want = strtoupper(preg_replace('/\s+/', '', (string)($_POST['invoice_no_fix'] ?? '')));
        if (ctype_digit($want) && $want !== '') {
            $want = $noFixed && preg_match('/-[0-9]+$/', (string)$inv['invoice_no'])
                ? preg_replace('/-[0-9]+$/', '', strtoupper((string)$inv['invoice_no'])) . '-' . ltrim($want, '0')
                : 'YMR-' . invoiceCodeForClient($db, (int)($inv['eff_client_id'] ?? 0)) . '-' . ltrim($want, '0');
        }
        if ($want === '') {
            if ($download) $errors[] = 'Invoice No. (type the invoice number)';
        } elseif ($want !== strtoupper((string)($inv['invoice_no'] ?? ''))) {
            if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/]{0,58}[0-9]$/', $want)) {
                $errors[] = 'Invoice No. (letters, digits and - only, ending in its number, e.g. YMR-BB-962)';
            } else {
                $num = preg_replace('/^.*?([0-9]+)$/', '$1', $want);
                $u = $db->prepare("SELECT invoice_no FROM invoices WHERE id <> ? AND (invoice_no = ? OR invoice_no LIKE ? OR invoice_no LIKE ?) LIMIT 1");
                $u->execute([$id, $want, '%-' . $num, '%-' . $num . 'B']);
                if ($other = $u->fetchColumn()) $errors[] = 'Invoice No. — number ' . $num . ' is already used by invoice ' . $other;
                else $fixNo = $want;
            }
        }
    } elseif (!$noFixed && invoiceRecNo($inv) === '' && $download) {
        $errors[] = 'Invoice No. — make the Vessel Invoice first: its number is typed there (the Recovery Invoice uses it with B)';
    }
    if ($errors) {
        http_response_code(422);
        header('Content-Type: text/html; charset=utf-8');
        exit('<div style="font-family:sans-serif;padding:24px;color:#991b1b"><b>Please fill in:</b><ul><li>' . implode('</li><li>', array_map('sanitize', $errors)) . '</li></ul></div>');
    }
    // Number: as typed (saved with the PDF below); the recovery invoice: the vessel's + B
    $d['invoice_no'] = $fixNo ?? ($isRecovery ? invoiceRecNo($inv) : ($noFixed ? (string)$inv['invoice_no'] : ''));
    $d['items'] = $items;
    @include_once __DIR__ . '/vendor/autoload.php';
    if (!class_exists(\Dompdf\Dompdf::class)) {
        http_response_code(500);
        exit('PDF engine (dompdf) is not installed on this server.');
    }
    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->setChroot(__DIR__); // the signature font lives under assets/fonts/signature/
    $fontTmp = sys_get_temp_dir() . '/ysms-dompdf-fonts';
    if (!is_dir($fontTmp)) @mkdir($fontTmp, 0775, true);
    if (is_dir($fontTmp) && is_writable($fontTmp)) $opt->setFontCache($fontTmp);
    $pdf = new \Dompdf\Dompdf($opt);
    $pdf->loadHtml(invoicePdfHtml($d), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    $name = preg_replace('/[^A-Za-z0-9 ._\-]/', '_', trim($d['invoice_no'] . ' ' . $d['vessel'] . ($isRecovery ? ' - Recovery Invoice' : ' - Invoice'))) . '.pdf';
    $out = $pdf->output();
    $state = '';
    if ($download) {
        // Keep the PDF and the form, then work out whether the invoice is now Completed.
        $form = [];
        foreach ($_POST as $k => $v) {
            if (in_array($k, ['csrf_token', 'mode', 'with_recovery', 'invoice_no_fix'], true)) continue;
            $form[$k] = is_array($v) ? array_map(fn($x) => is_scalar($x) ? mb_substr((string)$x, 0, 2000) : '', array_slice($v, 0, 30, true)) : mb_substr((string)$v, 0, 2000);
        }
        $rel = invoiceStoreGenerated($id, $out, $isRecovery ? 'rec' : 'gen');
        if (!$rel) {
            http_response_code(500);
            exit('The invoice PDF could not be saved. Please try again.');
        }
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $amount = array_sum(array_map('invoicePdfLineAmount', $items));
        try {
            $db->beginTransaction();
            $cur = $db->prepare('SELECT file_path, rec_file_path, invoice_status FROM invoices WHERE id = ? FOR UPDATE');
            $cur->execute([$id]);
            $cur = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
            $old = $cur[$isRecovery ? 'rec_file_path' : 'file_path'] ?? null;
            if ($isRecovery) {
                $complete = invoiceFileAbs($cur['file_path'] ?? null) !== null; // vessel invoice already made
                $db->prepare('UPDATE invoices SET rec_file_name = ?, rec_file_path = ?, rec_generated_at = NOW(), rec_gen_data = ?,
                        rec_invoice_date = ?, rec_invoice_amount = ? WHERE id = ?')
                   ->execute([$name, $rel, json_encode($form), $d['invoice_date'] !== '' ? $d['invoice_date'] : null, round($amount, 2), $id]);
                $state = $complete ? 'completed' : 'vessel-next';
            } else {
                $withRec = ($_POST['with_recovery'] ?? '') === '1';
                $complete = !$withRec || invoiceFileAbs($cur['rec_file_path'] ?? null) !== null;
                $db->prepare('UPDATE invoices SET file_name = ?, file_path = ?, file_size = ?, uploaded_by = ?, uploaded_at = NOW(),
                        invoice_date = ?, invoice_amount = ?, gen_data = ?, recovery_pending = ?, invoice_no = COALESCE(?, invoice_no) WHERE id = ?')
                   ->execute([$name, $rel, strlen($out), $uid, $d['invoice_date'] !== '' ? $d['invoice_date'] : null, round($amount, 2), json_encode($form), $complete ? 0 : 1, $fixNo, $id]);
                $state = $complete ? 'completed' : 'recovery-next';
            }
            if ($complete) {
                // SET runs left to right: the status changes last.
                $db->prepare("UPDATE invoices SET recovery_pending = 0,
                        status_updated_by = IF(invoice_status = 'Completed', status_updated_by, ?),
                        status_updated_at = IF(invoice_status = 'Completed', status_updated_at, NOW()),
                        invoice_status = 'Completed' WHERE id = ?")
                   ->execute([$uid, $id]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            @unlink(__DIR__ . '/' . $rel);
            error_log('invoice download save: ' . $e->getMessage());
            http_response_code(500);
            exit('The invoice could not be saved. Please try again.');
        }
        if ($old && $old !== $rel) invoiceRemoveFileIfUnused($db, $old);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('X-Invoice-No: ' . $d['invoice_no']);
    if ($state !== '') header('X-Invoice-State: ' . $state);
    if ($download && $fixNo !== null) header('X-Invoice-Renumbered: 1');
    header('Cache-Control: private, no-store');
    echo $out;
    exit;
}

$page_title = $isRecovery ? 'Recovery Invoice' : 'Generate Invoice';
include 'includes/header.php';
?>
<style>
.ig-wrap { display: grid; grid-template-columns: minmax(300px, 420px) 1fr; gap: 16px; padding: 16px; }
.ig-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
.ig-card h2 { font-size: 17px; font-weight: 800; margin: 0 0 2px; }
.ig-sub { font-size: 12px; color: #64748b; margin-bottom: 12px; }
.ig-card label { font-size: 11.5px; font-weight: 700; color: #475569; margin: 8px 0 3px; display: block; }
.ig-card .form-control { font-size: 13px; }
.ig-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.ig-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
.ig-btn { border: 0; border-radius: 10px; padding: 9px 14px; font-weight: 700; font-size: 13px; display: inline-flex; gap: 7px; align-items: center; text-decoration: none; }
.ig-btn.pv { background: #6C4CF1; color: #fff; } .ig-btn.dl { background: #00A3DF; color: #fff; } .ig-btn.bk { background: #f1f5f9; color: #334155; }
.ig-svc { display: grid; gap: 8px; }
.ig-cp { display: grid; grid-template-columns: 124px 1fr; gap: 10px; align-items: end; }
.ig-cp > div:first-child label { white-space: nowrap; }
.ig-cp-f .form-control { padding-left: 8px; padding-right: 6px; }
.ig-cp-f { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
.ig-cp-k { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 2px; }
.ig-price-mode {
    margin-top: 10px;
}

.ig-price-toggle {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    padding: 4px;
    background: #f1f5f9;
    border-radius: 10px;
}

.ig-price-toggle label {
    margin: 0;
}

.ig-price-toggle input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.ig-price-toggle span {
    display: block;
    padding: 8px 10px;
    border-radius: 8px;
    text-align: center;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    cursor: pointer;
}

.ig-price-toggle input:checked + span {
    background: #fff;
    color: #6C4CF1;
    box-shadow: 0 1px 3px rgba(15,23,42,.12);
}

.ig-plat {
    display: none;
}
@media (max-width: 575.98px) { .ig-cp { grid-template-columns: 1fr; } .ig-cp-f { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.ig-opt { font-weight: 500; color: #94a3b8; font-size: 11px; }
.ig-svc-row { display: grid; grid-template-columns: 1fr 170px; gap: 8px; align-items: start; background: #f8fafc; border: 1px solid #eef1f6; border-radius: 10px; padding: 8px; position: relative; }
.ig-svc-nums { display: grid; grid-template-columns: 1fr 64px; gap: 6px; }
.ig-svc-fixed { font-size: 13px; font-weight: 700; color: #334155; align-self: center; }
.ig-req { color: #dc2626; }
.ig-svc-row .ig-x { border: 0; background: #fff; color: #94a3b8; width: 24px; height: 24px; border-radius: 50%; position: absolute; right: -8px; top: -9px; box-shadow: 0 1px 3px rgba(15,23,42,.15); font-size: 11px; }
.ig-svc-row .ig-x:hover { background: #fee2e2; color: #dc2626; }
.ig-add { justify-self: start; border: 1.5px dashed #c4b5fd; background: #faf8ff; color: #6C4CF1; font-weight: 700; font-size: 12.5px; border-radius: 10px; padding: 7px 12px; }
.ig-total { text-align: right; font-size: 13px; color: #475569; } .ig-total b { font-size: 15px; color: #0f172a; margin-left: 6px; }
.ig-tabs { display: flex; gap: 6px; margin-bottom: 12px; background: #f1f5f9; padding: 4px; border-radius: 12px; }
.ig-tabs a { flex: 1; text-align: center; padding: 8px 10px; border-radius: 9px; font-size: 12.5px; font-weight: 700; color: #475569; text-decoration: none; }
.ig-tabs a.on { background: #fff; color: #6C4CF1; box-shadow: 0 1px 3px rgba(15,23,42,.12); }
.ig-hint { font-size: 10.5px; color: #94a3b8; font-weight: 500; margin-top: 2px; }
.ig-frame { width: 100%; height: calc(100vh - 140px); min-height: 520px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; }
@media (max-width: 991.98px) { .ig-wrap { grid-template-columns: 1fr; padding: 12px; padding-top: 84px; } .ig-frame { height: 70vh; } }
</style>
<div class="scroll-content" data-testid="invoice-generate-page">
<?php $back_url = 'invoices.php'; $page_testid = 'invoice-generate'; include 'includes/top_app_bar.php'; ?>
<div class="ig-wrap">
    <form class="ig-card" method="post" action="invoice_pdf.php?id=<?= (int)$id ?><?= $isRecovery ? '&amp;type=recovery' : '' ?>" target="igPreview" id="igForm" data-testid="ig-form">
        <?= csrf_field() ?>
        <input type="hidden" name="mode" value="preview" id="igMode">
        <div class="ig-tabs">
            <a href="invoice_pdf.php?id=<?= (int)$id ?>" class="<?= $isRecovery ? '' : 'on' ?>" data-testid="ig-tab-vessel"><i class="fa-solid fa-file-invoice-dollar"></i> Vessel Invoice</a>
            <a href="invoice_pdf.php?id=<?= (int)$id ?>&amp;type=recovery" class="<?= $isRecovery ? 'on' : '' ?>" data-testid="ig-tab-recovery"><i class="fa-solid fa-droplet"></i> Recovery Invoice</a>
        </div>
        <h2><?= $isRecovery ? 'Recovery Invoice' : 'Generate Invoice' ?></h2>
        <div class="ig-sub"><?= sanitize($vessel) ?><?= $defaults['port'] ? ' · ' . sanitize($defaults['port']) : '' ?> — details are filled from the vessel record; change anything before downloading.</div>
        <div class="ig-row">
<?php if (!$isRecovery): ?>
            <div><label>Invoice No. <b style="color:#dc2626">*</b></label><input class="form-control" name="invoice_no_fix" value="<?= sanitize($invoiceNo) ?>" maxlength="60" placeholder="e.g. YMR-BB-962" data-testid="ig-invoice-no">
                <div class="ig-hint">Type the invoice number (only digits: the client code is added). The Recovery Invoice uses it with B.</div></div>
<?php else: ?>
            <div><label>Invoice No.</label><input class="form-control" value="<?= sanitize($invoiceNo) ?>" readonly placeholder="Typed on the Vessel Invoice" data-testid="ig-invoice-no">
                <div class="ig-hint"><?= trim((string)($inv['rec_invoice_no'] ?? '')) !== '' ? 'Recovery invoice number set with Edit Invoice' : ($noFixed ? 'The vessel invoice\'s number + B (change it with Edit Invoice)' : 'Make the Vessel Invoice first — its number is typed there') ?></div></div>
<?php endif; ?>
            <div><label>Invoice Date</label><input class="form-control" type="date" name="invoice_date" value="<?= sanitize($defaults['invoice_date']) ?>"></div>
        </div>
        <label>Client</label><input class="form-control" name="client" value="<?= sanitize($defaults['client']) ?>" data-testid="ig-client">
        <label>Client address</label>
        <input class="form-control mb-1" name="client_addr1" value="<?= sanitize($defaults['client_addr1']) ?>" placeholder="Address line 1">
        <input class="form-control" name="client_addr2" value="<?= sanitize($defaults['client_addr2']) ?>" placeholder="Address line 2">
        <div class="ig-row">
            <div><label>Supplier's Ref.</label><input class="form-control" name="supplier_ref" value="<?= sanitize($defaults['supplier_ref']) ?>"></div>
            <div><label>Other Reference(s)</label><input class="form-control" name="other_ref" value="<?= sanitize($defaults['other_ref']) ?>"></div>
        </div>
        <div class="ig-row">
            <div><label>Vessel</label><input class="form-control" name="vessel" value="<?= sanitize($defaults['vessel']) ?>" data-testid="ig-vessel"></div>
            <div><label>Survey Type</label><input class="form-control" name="survey_type" value="<?= sanitize($defaults['survey_type']) ?>"></div>
        </div>
        <div class="ig-row">
            <div><label>Port</label><input class="form-control" name="port" value="<?= sanitize($defaults['port']) ?>"></div>
            <div><label>Survey Completed</label><input class="form-control" name="survey_completed" value="<?= sanitize($defaults['survey_completed']) ?>"></div>
        </div>
<?php if ($isRecovery): ?>
        <label>Description</label>
        <textarea class="form-control" name="service_desc" rows="3" data-testid="ig-service-desc"><?= sanitize($sv('service_desc', 'Recovery of bunkers — ' . $serviceDesc)) ?></textarea>
        <div class="ig-price-mode">
            <label>CP Price Available?</label>

            <div class="ig-price-toggle">
                <label>
                    <input
                        type="radio"
                        name="price_mode"
                        value="cp"
                        <?= $sv('price_mode', 'cp') !== 'plat' ? 'checked' : '' ?>
                        data-ig-price-mode
                    >
                    <span>Yes — CP Price</span>
                </label>

                <label>
                    <input
                        type="radio"
                        name="price_mode"
                        value="plat"
                        <?= $sv('price_mode', 'cp') === 'plat' ? 'checked' : '' ?>
                        data-ig-price-mode
                    >
                    <span>No — PLAT Price</span>
                </label>
            </div>
        </div>

        <div class="ig-recovery-pct">
    <label>
        Agreed recovery %
        <span class="ig-req">*</span>
    </label>

    <input
        class="form-control"
        name="recovery_pct"
        inputmode="decimal"
        placeholder="e.g. 8"
        value="<?= sanitize($sv('recovery_pct', '')) ?>"
        required
        data-ig-pct
        data-testid="ig-recovery-pct"
    >
</div>

<div data-ig-cp-section>
    <label>
        Agreed CP price
        <span class="ig-opt">(USD / MT, optional)</span>
    </label>

    <div class="ig-cp-f">
        <?php foreach (IG_CP_ORDER as $k): $label = IG_FUELS[$k]; ?>
            <div>
                <span class="ig-cp-k"><?= $label ?></span>

                <input
                    class="form-control"
                    name="cp_<?= $k ?>"
                    inputmode="decimal"
                    placeholder="USD"
                    value="<?= sanitize($sv('cp_' . $k, '')) ?>"
                    data-ig-cp="<?= $k ?>"
                    data-testid="ig-cp-<?= $k ?>"
                >
            </div>
        <?php endforeach; ?>
    </div>
</div>

        <div class="ig-plat" data-ig-plat-section>
            <label>
                PLAT Price
                <span class="ig-opt">(USD / MT)</span>
            </label>

            <div class="ig-cp-f">
                <?php foreach (IG_CP_ORDER as $k): $label = IG_FUELS[$k]; ?>
                    <div>
                        <span class="ig-cp-k"><?= $label ?></span>

                        <input
                            class="form-control"
                            name="plat_<?= $k ?>"
                            inputmode="decimal"
                            placeholder="USD"
                            value="<?= sanitize($sv('plat_' . $k, '')) ?>"
                            data-ig-plat="<?= $k ?>"
                            data-testid="ig-plat-<?= $k ?>"
                        >
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <label>
            Recovery
            <span class="ig-hint" data-ig-recovery-hint>
                (unit = agreed CP price × agreed %, filled in from the CP price; quantity = MT recovered)
            </span>
        </label>
        <label>Recovery <span class="ig-hint">(unit = agreed CP price × agreed %, filled in from the CP price; quantity = MT recovered)</span></label>
        <div class="ig-svc" data-testid="ig-recovery">
            <?php foreach (IG_FUELS as $k => $label): ?>
            <div class="ig-svc-row">
                <?php $opt = in_array($k, IG_FUELS_OPTIONAL, true); ?>
                <div class="ig-svc-fixed">Recovery of <?= $label ?> <?= $opt ? '<span class="ig-opt">(optional)</span>' : '<span class="ig-req">*</span>' ?></div>
                <div class="ig-svc-nums"><input class="form-control" name="<?= $k ?>_rate" inputmode="decimal" placeholder="Unit" value="<?= sanitize($sv($k . '_rate', '')) ?>"<?= $opt ? '' : ' required' ?> data-ig-rate data-ig-fuel-rate="<?= $k ?>" data-testid="ig-<?= $k ?>-rate"><input class="form-control" name="<?= $k ?>_qty" inputmode="decimal" placeholder="Qty" value="<?= sanitize($sv($k . '_qty', $recQty[$k] ?? '')) ?>"<?= $opt ? '' : ' required' ?> data-ig-qty data-testid="ig-<?= $k ?>-qty"></div>
            </div>
            <?php endforeach; ?>
            <div class="ig-total">Total <b data-ig-total>$0.00</b></div>
        </div>
<?php else: ?>
        <label>Services</label>
        <div class="ig-svc" data-testid="ig-services">
            <div class="ig-svc-row main">
                <textarea class="form-control" name="service_desc" rows="3" data-testid="ig-service-desc"><?= sanitize($sv('service_desc', $serviceDesc)) ?></textarea>
                <div class="ig-svc-nums"><input class="form-control" name="service_rate" inputmode="decimal" placeholder="Unit" value="<?= sanitize($sv('service_rate', $serviceRate)) ?>" data-ig-rate data-testid="ig-rate"><input class="form-control" name="service_qty" inputmode="decimal" placeholder="Qty" value="<?= sanitize($sv('service_qty', '1')) ?>" data-ig-qty></div>
            </div>
            <?php foreach (['travel' => 'Travel Cost', 'launch' => 'Launch Cost'] as $k => $label): ?>
            <div class="ig-svc-row">
                <div class="ig-svc-fixed"><?= $label ?> <span class="ig-req" title="Required">*</span></div>
                <div class="ig-svc-nums"><input class="form-control" name="<?= $k ?>_rate" inputmode="decimal" placeholder="Unit" value="<?= sanitize($sv($k . '_rate', '')) ?>" required data-ig-rate data-testid="ig-<?= $k ?>-rate"><input class="form-control" name="<?= $k ?>_qty" inputmode="decimal" placeholder="Qty" value="<?= sanitize($sv($k . '_qty', '1')) ?>" required data-ig-qty></div>
            </div>
            <?php endforeach; ?>
            <div data-ig-extras style="display:grid;gap:8px"><?php
                $xn = 0;
                foreach ((array)($saved['extra_desc'] ?? []) as $xi => $xd):
                    $xr = $saved['extra_rate'][$xi] ?? ''; $xq = $saved['extra_qty'][$xi] ?? '1';
                    if (!is_scalar($xd) || (trim((string)$xd) === '' && trim((string)$xr) === '')) continue; ?>
                <div class="ig-svc-row extra"><input class="form-control" name="extra_desc[<?= $xn ?>]" placeholder="Service description" value="<?= sanitize((string)$xd) ?>" data-testid="ig-extra-desc"><div class="ig-svc-nums"><input class="form-control" name="extra_rate[<?= $xn ?>]" inputmode="decimal" placeholder="Unit" value="<?= sanitize((string)$xr) ?>" data-ig-rate data-testid="ig-extra-rate"><input class="form-control" name="extra_qty[<?= $xn ?>]" inputmode="decimal" placeholder="Qty" value="<?= sanitize((string)$xq) ?>" data-ig-qty></div><button type="button" class="ig-x" title="Remove" aria-label="Remove service"><i class="fa-solid fa-xmark"></i></button></div>
            <?php $xn++; endforeach; ?></div>
            <button type="button" class="ig-add" data-ig-add data-testid="ig-add-service"><i class="fa-solid fa-plus"></i> Add service</button>
            <div class="ig-total">Total <b data-ig-total>$0.00</b></div>
        </div>
<?php endif; ?>
        <div class="ig-hint" style="margin-top:12px" data-testid="ig-state-hint"><i class="fa-solid fa-circle-info"></i> <?php
            if ($isRecovery) echo $hasVesselFile ? 'Downloading the recovery invoice completes this invoice (Completed list).' : 'Download the vessel invoice too — the invoice is completed once both are downloaded.';
            elseif ($hasRecFile) echo 'Recovery invoice already downloaded — downloading this completes the invoice.';
            else echo 'On download you will be asked whether a recovery invoice is needed too; the invoice moves to Completed once everything is downloaded.';
        ?></div>
        <?php if ($saved): ?><div class="ig-hint" data-testid="ig-modify-hint"><i class="fa-solid fa-pen"></i> Showing the details last downloaded — download again to replace the saved PDF.</div><?php endif; ?>
        <div class="ig-actions">
            <button type="submit" class="ig-btn pv" data-testid="ig-preview"><i class="fa-solid fa-eye"></i> Preview</button>
            <button type="button" class="ig-btn dl" id="igDownload" data-testid="ig-download"><i class="fa-solid fa-file-arrow-down"></i> Download PDF</button>
            <a href="invoices.php" class="ig-btn bk"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
    </form>
    <div class="ig-card" style="padding:10px"><iframe name="igPreview" class="ig-frame" title="Invoice preview" data-testid="ig-frame"></iframe></div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
  var f = document.getElementById('igForm'), mode = document.getElementById('igMode');
  var IS_REC = <?= $isRecovery ? 'true' : 'false' ?>, HAS_REC = <?= $hasRecFile ? 'true' : 'false' ?>;
  var REC_URL = 'invoice_pdf.php?id=<?= (int)$id ?>&type=recovery', VESSEL_URL = 'invoice_pdf.php?id=<?= (int)$id ?>';
  var dlBtn = document.getElementById('igDownload'), dlHtml = dlBtn.innerHTML;
  // Download: the PDF is saved on the server and handed to the browser; the reply says what is next.
  function download(withRec) {
    var data = new FormData(f);
    data.set('mode', 'download');
    if (!IS_REC) data.set('with_recovery', withRec ? '1' : '0');
    dlBtn.disabled = true; dlBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Downloading';
    return fetch(f.action, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) return r.text().then(function (t) { throw new Error(t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() || ('Error ' + r.status)); });
      var m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || ''), state = r.headers.get('X-Invoice-State') || '';
      if (r.headers.get('X-Invoice-Renumbered') && HAS_REC) state = 'renumbered';
      return r.blob().then(function (b) {
        var a = document.createElement('a'), u = URL.createObjectURL(b);
        a.href = u; a.download = m ? m[1] : 'invoice.pdf'; document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(u); a.remove(); }, 4000);
        return state;
      });
    }).then(function (state) {
      if (state === 'renumbered') {
        return Swal.fire({ icon: 'success', title: 'Invoice number changed', text: 'The vessel invoice was downloaded with the new number. The saved Recovery Invoice still shows the old number — open it and click Download PDF again to get it with the new number + B.', confirmButtonText: 'Open Recovery Invoice', confirmButtonColor: '#6C4CF1', allowOutsideClick: false })
          .then(function () { location.href = REC_URL; });
      }
      if (state === 'recovery-next') {
        return Swal.fire({ icon: 'success', title: 'Vessel invoice downloaded', text: 'Now fill in the recovery invoice — the invoice moves to Completed once it is downloaded.', confirmButtonText: 'Open Recovery Invoice', confirmButtonColor: '#6C4CF1', allowOutsideClick: false })
          .then(function () { location.href = REC_URL; });
      }
      if (state === 'vessel-next') {
        return Swal.fire({ icon: 'success', title: 'Recovery invoice downloaded', text: 'The vessel invoice is still to be downloaded — the invoice moves to Completed once both are done.', confirmButtonText: 'Open Vessel Invoice', confirmButtonColor: '#6C4CF1', allowOutsideClick: false })
          .then(function () { location.href = VESSEL_URL; });
      }
      return Swal.fire({ icon: 'success', title: 'Invoice completed', text: 'Downloaded and moved to Completed invoices.', showCancelButton: true, confirmButtonText: 'Go to Completed', cancelButtonText: 'Stay here', confirmButtonColor: '#6C4CF1' })
        .then(function (r) { if (r.isConfirmed) location.href = 'invoices.php?view=completed'; else location.reload(); });
    }).catch(function (e) {
      Swal.fire({ icon: 'error', title: 'Not downloaded', text: e.message || 'Please try again.' });
    }).then(function () { dlBtn.disabled = false; dlBtn.innerHTML = dlHtml; });
  }
  dlBtn.addEventListener('click', function () {
    if (!f.reportValidity()) return;
    if (IS_REC || HAS_REC) { download(false); return; }
    Swal.fire({
      icon: 'question', title: 'Recovery invoice too?',
      html: 'Will you also make a <b>Recovery Invoice</b> for this vessel?<br><small style="color:#64748b">Yes: this invoice downloads now and the recovery invoice opens next; it moves to Completed after both are downloaded.<br>No: it downloads and moves to Completed now.</small>',
      showDenyButton: true, showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-droplet"></i> Yes, recovery invoice too', denyButtonText: 'No, continue', cancelButtonText: 'Cancel',
      confirmButtonColor: '#6C4CF1', denyButtonColor: '#00A3DF'
    }).then(function (r) {
      if (r.isConfirmed) download(true);
      else if (r.isDenied) download(false);
    });
  });
  // Services: added rows + live total
  var box = document.querySelector('[data-ig-extras]'), n = box ? box.children.length : 0;
  function num(v) { v = String(v || '').replace(/[, ]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
  function total() {
    var t = 0;
    document.querySelectorAll('.ig-svc-row').forEach(function (r) { var a = r.querySelector('[data-ig-rate]'), q = r.querySelector('[data-ig-qty]'); if (a) t += num(a.value) * (q && q.value !== '' ? num(q.value) : 1); });
    document.querySelector('[data-ig-total]').textContent = '$' + t.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  // Recovery: unit of each fuel = agreed CP price × agreed % (recalculated when either changes)
  // Recovery pricing: CP or PLAT price × agreed %
var priceModes = document.querySelectorAll('[data-ig-price-mode]');
var cpSection = document.querySelector('[data-ig-cp-section]');
var platSection = document.querySelector('[data-ig-plat-section]');
var recoveryHint = document.querySelector('[data-ig-recovery-hint]');

function getPriceMode() {
    var selected = document.querySelector('[data-ig-price-mode]:checked');
    return selected ? selected.value : 'cp';
}

function updatePriceMode() {
    var mode = getPriceMode();
    var isPlat = mode === 'plat';

    if (cpSection) {
        cpSection.style.display = isPlat ? 'none' : 'block';
    }

    if (platSection) {
        platSection.style.display = isPlat ? 'block' : 'none';
    }

    document.querySelectorAll('[data-ig-cp]').forEach(function (el) {
        el.disabled = isPlat;
    });

    document.querySelectorAll('[data-ig-plat]').forEach(function (el) {
        el.disabled = !isPlat;
    });

    if (recoveryHint) {
        recoveryHint.textContent = isPlat
            ? '(unit = PLAT price × agreed %; quantity = MT recovered)'
            : '(unit = agreed CP price × agreed %; quantity = MT recovered)';
    }

    recoveryUnits(null);
}

function recoveryUnits(only) {
    var pctEl = document.querySelector('[data-ig-pct]');
    if (!pctEl) return;

    var pct = num(pctEl.value);
    var mode = getPriceMode();

    var selector = mode === 'plat'
        ? '[data-ig-plat]'
        : '[data-ig-cp]';

    document.querySelectorAll(selector).forEach(function (priceEl) {

        var k = mode === 'plat'
            ? priceEl.getAttribute('data-ig-plat')
            : priceEl.getAttribute('data-ig-cp');

        if (only && only !== k) return;

        var rate = document.querySelector('[data-ig-fuel-rate="' + k + '"]');

        if (!rate || String(priceEl.value).trim() === '' || !(pct > 0)) {
            return;
        }

        rate.value = (
            Math.round(num(priceEl.value) * pct) / 100
        ).toFixed(2);
    });

    total();
}

document.querySelectorAll('[data-ig-cp]').forEach(function (el) {
    el.addEventListener('input', function () {
        if (getPriceMode() === 'cp') {
            recoveryUnits(el.getAttribute('data-ig-cp'));
        }
    });
});

document.querySelectorAll('[data-ig-plat]').forEach(function (el) {
    el.addEventListener('input', function () {
        if (getPriceMode() === 'plat') {
            recoveryUnits(el.getAttribute('data-ig-plat'));
        }
    });
});

var pctIn = document.querySelector('[data-ig-pct]');
if (pctIn) {
    pctIn.addEventListener('input', function () {
        recoveryUnits(null);
    });
}

priceModes.forEach(function (radio) {
    radio.addEventListener('change', updatePriceMode);
});

updatePriceMode();
  var add = document.querySelector('[data-ig-add]');
  if (add) add.addEventListener('click', function () {
    var r = document.createElement('div');
    r.className = 'ig-svc-row extra';
    r.innerHTML = '<input class="form-control" name="extra_desc[' + n + ']" placeholder="Service description" data-testid="ig-extra-desc">' +
      '<div class="ig-svc-nums"><input class="form-control" name="extra_rate[' + n + ']" inputmode="decimal" placeholder="Unit" data-ig-rate data-testid="ig-extra-rate"><input class="form-control" name="extra_qty[' + n + ']" inputmode="decimal" placeholder="Qty" value="1" data-ig-qty></div>' +
      '<button type="button" class="ig-x" title="Remove" aria-label="Remove service"><i class="fa-solid fa-xmark"></i></button>';
    n++; box.appendChild(r); r.querySelector('input').focus();
  });
  if (box) box.addEventListener('click', function (ev) { var x = ev.target.closest('.ig-x'); if (x) { x.closest('.ig-svc-row').remove(); total(); } });
  f.addEventListener('input', total);
  total();
  f.addEventListener('submit', function (ev) { if (!f.checkValidity()) { ev.preventDefault(); f.reportValidity(); } });
  f.submit(); // first preview straight away (shows what is still missing)
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
