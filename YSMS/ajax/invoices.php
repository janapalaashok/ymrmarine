<?php
/**
 * Invoices module actions (invoices.php). Admin only; POSTs need the CSRF token
 * (csrf_require() in config.php; the page's fetch() sends X-CSRF-Token).
 *
 *   POST a=status  id, status            → invoice_status only
 *   POST a=upload  id, invoice_pdf, meta  → first upload or replacement of the PDF
 *   POST a=meta    id, invoice_no, invoice_date, invoice_amount, invoice_notes
 *   POST a=delete  id                    → removes the invoice PDFs (vessel + recovery) and their
 *                                          details; the row stays and keeps its invoice number.
 *                                          A Completed invoice goes back to the pending list
 *                                          (Pending for CP Prices) so it can be made again.
 *   POST a=remove  id, reason            → deletes a wrong entry from Invoices: any entry in a
 *                                          pending status with no stored invoice PDF and no
 *                                          payment. The row is kept (deleted_at), with its invoice
 *                                          number if it has one, so the vessel is not registered
 *                                          again and Restore brings it back as it was.
 *   POST a=restore id                    → brings a deleted entry back to the pending list
 *   GET  a=file    id, mode=view|download, which=recovery (optional) → the stored PDF
 *   GET  a=list    view, status, q, type, month, client, port, vessel, scd_from, scd_to, sort
 *                  (JSON), page, per → grid rows, total, pages, per-status counts
 *   POST a=prefs   prefs (JSON: order, hidden, widths, sort, per) → this user's grid layout
 *   GET  a=lists                         → clients, ports, survey types for the Add / Edit form
 *   GET  a=next    client                → the invoice number Add Invoice suggests (not reserved)
 *   GET  a=surveys q                     → surveys with no Invoices entry yet (optional link)
 *   POST a=create  form fields, no_auto, request_id, survey_id (optional), confirm_dup
 *                                        → a manual invoice (registered_via 'manual'); with
 *                                          no_auto=1 the next free number is taken at save time
 *   POST a=update  id + form fields      → Edit: the same row is updated (never a new one)
 *   Add / Edit also take an optional recovery invoice: rec_on=1, rec_invoice_no (empty or the
 *   invoice number + "B" = the usual number, kept as NULL so it follows the invoice number),
 *   rec_invoice_date, rec_invoice_amount.
 *   POST a=upload_rec id, rec_pdf        → stores the recovery invoice PDF (first or replacement)
 *   POST a=recovery id, rec_on=1, rec_invoice_no, rec_invoice_date, rec_invoice_amount, rec_pdf
 *                  (optional)           → Upload / Edit PDF dialog: the recovery invoice's details
 *                                          and PDF in one go
 *   Every number written goes through the invoice_number_seq row lock and must be free (same
 *   number, or the same running number under another client code); a higher number moves the
 *   series on so the next automatic number follows it.
 *
 * Nothing here reads or writes survey status, report fields or the survey's own uploads.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/invoice_helpers.php';
checkAuth();

function invJson(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

if (!invoiceCanManage()) invJson(403, ['ok' => false, 'error' => 'Only Admin can manage invoices.']);

$db = getDB();
invoiceEnsureTable($db);
$action = (string)($_REQUEST['a'] ?? '');
$id = (int)($_REQUEST['id'] ?? 0);

// ---------- grid (no invoice id) ----------
function invPrefsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_list_prefs` (`user_id` int(11) NOT NULL, `prefs` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
if ($action === 'list') {
    require_once __DIR__ . '/../includes/list_filters.php';
    [$f, $where, $params] = invoiceListFilters($_GET);
    $from = invoiceFromSql();
    $per = (int)($_GET['per'] ?? 50);
    if (!in_array($per, [25, 50, 100, 200], true)) $per = 50;
    $cnt = $db->prepare("SELECT COUNT(*) $from WHERE $where");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $order = invoiceGridOrderSql(json_decode((string)($_GET['sort'] ?? '[]'), true), $f['view']);
    $st = $db->prepare(invoiceRowSql() . " WHERE $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = array_map(fn($r) => invoiceGridRow($db, $r), $st->fetchAll(PDO::FETCH_ASSOC));
    // counters per status for the same search / filters
    [, $w2, $p2] = invoiceListFiltersNoStatus($_GET);
    $c = $db->prepare("SELECT i.invoice_status, COUNT(*) n $from WHERE $w2 GROUP BY i.invoice_status");
    $c->execute($p2);
    $counts = array_fill_keys(INVOICE_STATUSES, 0);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['invoice_status']] = (int)$r['n'];
    $dq = $db->prepare("SELECT COUNT(*) $from WHERE " . str_replace('i.deleted_at IS NULL', 'i.deleted_at IS NOT NULL', $w2));
    $dq->execute($p2);
    invJson(200, ['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'view' => $f['view'],
        'counts' => $counts, 'pending' => $counts['Pending for CP Prices'] + $counts['Pending for Cost'] + $counts['Pending for Other Reason'], 'deleted' => (int)$dq->fetchColumn()]);
}
if ($action === 'prefs' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p = json_decode((string)($_POST['prefs'] ?? ''), true);
    if (!is_array($p)) invJson(422, ['ok' => false, 'error' => 'Bad layout.']);
    $cols = ['vessel', 'report_no', 'client', 'type', 'port', 'survey_completed', 'status', 'actions', 'document', 'invoice_date', 'amount'];
    $clean = [
        'order' => array_values(array_intersect(array_map('strval', (array)($p['order'] ?? [])), $cols)),
        'hidden' => array_values(array_intersect(array_map('strval', (array)($p['hidden'] ?? [])), $cols)),
        'widths' => [], 'sort' => [],
        'per' => in_array((int)($p['per'] ?? 50), [25, 50, 100, 200], true) ? (int)$p['per'] : 50,
    ];
    foreach ((array)($p['widths'] ?? []) as $k => $w) if (in_array($k, $cols, true) || $k === 'invoice_no') $clean['widths'][$k] = max(60, min(600, (int)$w));
    foreach (array_slice((array)($p['sort'] ?? []), 0, 3) as $x) {
        if (is_array($x) && isset(INVOICE_LIST_SORTS[$x['f'] ?? ''])) $clean['sort'][] = ['f' => $x['f'], 'd' => ($x['d'] ?? '') === 'desc' ? 'desc' : 'asc'];
    }
    invPrefsTable($db);
    $db->prepare('INSERT INTO invoice_list_prefs (user_id, prefs) VALUES (?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)')
       ->execute([(int)($_SESSION['user_id'] ?? 0), json_encode($clean)]);
    invJson(200, ['ok' => true, 'prefs' => $clean]);
}

$load = function (int $id) use ($db): ?array {
    $st = $db->prepare(invoiceRowSql() . ' WHERE i.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
};
// ---------- Add Invoice / Edit (manual entry) ----------
/** Clients, ports and survey types for the invoice form. */
if ($action === 'lists') {
    $clients = $db->query("SELECT id, company_name AS name FROM clients WHERE company_name <> '' ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
    $hasCountry = (bool)$db->query("SHOW COLUMNS FROM ports LIKE 'country'")->fetch();
    $ports = $db->query('SELECT id, port_name AS name' . ($hasCountry ? ', country' : '') . " FROM ports WHERE port_name <> '' ORDER BY port_name")->fetchAll(PDO::FETCH_ASSOC);
    $types = $db->query("SELECT id, type_name AS name FROM survey_types WHERE type_name <> '' ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
    $cast = fn($rows) => array_map(function ($r) { $r['id'] = (int)$r['id']; return $r; }, $rows);
    invJson(200, ['ok' => true, 'clients' => $cast($clients), 'ports' => $cast($ports), 'types' => $cast($types)]);
}
/** The number Add Invoice suggests (not reserved — Save takes the next free one at that moment). */
if ($action === 'next') {
    invoiceEnsureSequence($db);
    $code = invoiceCodeForClient($db, max(0, (int)($_GET['client'] ?? 0)));
    $n = invoiceNextNumber($db);
    invJson(200, ['ok' => true, 'n' => $n, 'code' => $code, 'number' => 'YMR-' . $code . '-' . $n]);
}
/** Is a typed number free? (live hint in the form; Save checks again under the lock) */
if ($action === 'checkno') {
    $code = invoiceCodeForClient($db, max(0, (int)($_GET['client'] ?? 0)));
    [$no, $ne] = invoiceNormalizeNumber((string)($_GET['no'] ?? ''), $code);
    if ($ne) invJson(200, ['ok' => false, 'error' => $ne]);
    $other = invoiceNumberTakenBy($db, $no, max(0, (int)($_GET['id'] ?? 0)));
    $n = invoiceNumberPart($no);
    invJson(200, ['ok' => true, 'number' => $no, 'n' => $n, 'taken' => $other !== null,
        'message' => $other === null ? '' : ($n !== null && strcasecmp($other, $no) !== 0 ? 'Number ' . $n . ' is already used by invoice ' . $other . '.' : 'Invoice number ' . $other . ' already exists.')]);
}
/** Surveys that have no Invoices entry yet (to link a manual invoice to its vessel record). */
if ($action === 'surveys') {
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    if (mb_strlen($q) < 2) invJson(200, ['ok' => true, 'rows' => []]);
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $st = $db->prepare("SELECT s.id, s.vessel_name, s.report_number, s.status, s.client_id, s.port_id, s.survey_type_id, s.survey_type_ids,
            s.survey_completed_date, c.company_name, p.port_name, st.type_name
        FROM surveys s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN ports p ON p.id = s.port_id LEFT JOIN survey_types st ON st.id = s.survey_type_id
        WHERE (s.vessel_name LIKE ? OR s.report_number LIKE ?) AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.survey_id = s.id)
        ORDER BY s.id DESC LIMIT 15");
    $st->execute([$like, $like]);
    $rows = array_map(function ($r) use ($db) {
        $scd = !empty($r['survey_completed_date']) && strpos((string)$r['survey_completed_date'], '0000') !== 0 ? substr((string)$r['survey_completed_date'], 0, 10) : '';
        $ids = array_values(array_filter(array_map('intval', explode(',', str_replace(' ', '', (string)$r['survey_type_ids'])))));
        if (!$ids && (int)$r['survey_type_id'] > 0) $ids = [(int)$r['survey_type_id']];
        return ['id' => (int)$r['id'], 'vessel' => (string)$r['vessel_name'], 'report_no' => (string)($r['report_number'] ?? ''), 'status' => (string)$r['status'],
            'client_id' => (int)$r['client_id'] ?: null, 'client' => (string)($r['company_name'] ?? ''), 'port_id' => (int)$r['port_id'] ?: null, 'port' => (string)($r['port_name'] ?? ''),
            'type_ids' => $ids, 'type' => (string)getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? ''), 'survey_completed_ymd' => $scd];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    invJson(200, ['ok' => true, 'rows' => $rows]);
}

/**
 * The invoice form's fields, checked: [values, errors]. Values: vessel_name, report_number,
 * client_id, port_id, survey_type_ids (csv), survey_completed_date, invoice_date, invoice_amount,
 * invoice_notes, invoice_status, invoice_no (typed text).
 */
$invForm = function () use ($db): array {
    $e = [];
    $date = function (string $k, string $label) use (&$e): ?string {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v === '') return null;
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v || (int)$d->format('Y') < 1990 || (int)$d->format('Y') > 2100) { $e[$k] = $label . ' is not a valid date.'; return null; }
        return $v;
    };
    $v = [];
    $v['vessel_name'] = mb_substr(preg_replace('/\s+/u', ' ', trim((string)($_POST['vessel_name'] ?? ''))), 0, 255);
    if ($v['vessel_name'] === '') $e['vessel_name'] = 'Enter the vessel name.';
    $v['report_number'] = trim((string)($_POST['report_number'] ?? ''));
    if (mb_strlen($v['report_number']) > 50) $e['report_number'] = 'Report number is too long (50 characters at most).';
    $v['report_number'] = $v['report_number'] !== '' ? $v['report_number'] : null;
    $v['client_id'] = (int)($_POST['client_id'] ?? 0);
    if ($v['client_id'] <= 0) $e['client_id'] = 'Choose the client.';
    else { $q = $db->prepare('SELECT 1 FROM clients WHERE id = ?'); $q->execute([$v['client_id']]); if (!$q->fetchColumn()) $e['client_id'] = 'This client no longer exists.'; }
    $v['port_id'] = (int)($_POST['port_id'] ?? 0) ?: null;
    if ($v['port_id']) { $q = $db->prepare('SELECT 1 FROM ports WHERE id = ?'); $q->execute([$v['port_id']]); if (!$q->fetchColumn()) $e['port_id'] = 'This port no longer exists.'; }
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['survey_type_ids'] ?? ''))), fn($x) => $x > 0)));
    if ($ids) {
        $q = $db->query('SELECT COUNT(*) FROM survey_types WHERE id IN (' . implode(',', $ids) . ')');
        if ((int)$q->fetchColumn() !== count($ids)) $e['survey_type_ids'] = 'A chosen survey type no longer exists.';
    }
    $v['survey_type_ids'] = $ids ? implode(',', $ids) : null;
    $v['survey_completed_date'] = $date('survey_completed_date', 'Survey completed date');
    $v['invoice_date'] = $date('invoice_date', 'Invoice date');
    $amt = str_replace([',', ' ', '$'], '', trim((string)($_POST['invoice_amount'] ?? '')));
    if ($amt !== '' && (!is_numeric($amt) || (float)$amt < 0 || (float)$amt > 999999999999)) $e['invoice_amount'] = 'Amount must be a number of 0 or more.';
    $v['invoice_amount'] = $amt !== '' && !isset($e['invoice_amount']) ? round((float)$amt, 2) : null;
    $v['invoice_notes'] = mb_substr(trim((string)($_POST['invoice_notes'] ?? '')), 0, 500) ?: null;
    $v['invoice_status'] = (string)($_POST['invoice_status'] ?? '');
    if (!in_array($v['invoice_status'], INVOICE_STATUSES, true)) $e['invoice_status'] = 'Choose the invoice status.';
    $v['invoice_no'] = (string)($_POST['invoice_no'] ?? '');
    return [$v, $e];
};
/** Another live entry for the same vessel (only warned about; the Admin may still save). */
$sameVessel = function (string $vessel, int $exceptId) use ($db): array {
    $q = $db->prepare('SELECT i.id, i.invoice_no, ' . INV_E_REPORT . ' AS report_no FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id
        WHERE i.deleted_at IS NULL AND i.id <> ? AND LOWER(TRIM(' . INV_E_VESSEL . ')) = LOWER(?) ORDER BY i.id DESC LIMIT 5');
    $q->execute([$exceptId, $vessel]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
};

/**
 * The optional recovery invoice of the Add / Edit form, checked against the entry's final
 * invoice number $no: [values|null (no recovery invoice), errors]. Values: rec_invoice_no (NULL =
 * invoice number + B), rec_invoice_date, rec_invoice_amount. The number is checked for clashes
 * again under the lock ($recClash).
 */
$recForm = function (?string $no) use ($db): array {
    if (($_POST['rec_on'] ?? '') !== '1') return [null, []];
    $e = [];
    $v = ['rec_invoice_no' => null, 'rec_invoice_date' => null, 'rec_invoice_amount' => null];
    $typed = trim((string)($_POST['rec_invoice_no'] ?? ''));
    $usual = $no !== null && $no !== '' ? strtoupper($no) . 'B' : '';
    if ($typed === '' && $usual === '') $e['rec_invoice_no'] = 'Enter the recovery invoice number (or the invoice number first).';
    elseif ($typed !== '') {
        [$rn, $re] = invoiceNormalizeRecNumber($typed);
        if ($re) $e['rec_invoice_no'] = $re;
        elseif ($rn !== $usual) $v['rec_invoice_no'] = $rn;
    }
    $d = trim((string)($_POST['rec_invoice_date'] ?? ''));
    if ($d === '') $e['rec_invoice_date'] = 'Choose the recovery invoice date.';
    else {
        $dt = DateTime::createFromFormat('!Y-m-d', $d);
        if (!$dt || $dt->format('Y-m-d') !== $d || (int)$dt->format('Y') < 1990 || (int)$dt->format('Y') > 2100) $e['rec_invoice_date'] = 'Recovery invoice date is not a valid date.';
        else $v['rec_invoice_date'] = $d;
    }
    $amt = str_replace([',', ' ', '$'], '', trim((string)($_POST['rec_invoice_amount'] ?? '')));
    if ($amt !== '' && (!is_numeric($amt) || (float)$amt < 0 || (float)$amt > 999999999999)) $e['rec_invoice_amount'] = 'Recovery amount must be a number of 0 or more.';
    elseif ($amt !== '') $v['rec_invoice_amount'] = round((float)$amt, 2);
    return [$v, $e];
};
/** Error text when the recovery number is used by another entry (or is the entry's own invoice number), else null. */
$recClash = function (?array $rv, ?string $no, int $exceptId) use ($db): ?string {
    if ($rv === null) return null;
    $rn = $rv['rec_invoice_no'] ?? (($no ?? '') !== '' ? strtoupper((string)$no) . 'B' : '');
    if ($rn === '') return null;
    if (($no ?? '') !== '' && strcasecmp($rn, (string)$no) === 0) return 'The recovery invoice number must be different from the invoice number.';
    $other = invoiceRecNumberTakenBy($db, $rn, $exceptId, '');
    return $other === null ? null : 'Recovery invoice number ' . $rn . ' is already used (' . $other . ').';
};

if ($action === 'create' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    invoiceEnsureSequence($db);
    invoiceEnsureNumberKey($db);
    [$v, $err] = $invForm();
    if ($v['invoice_date'] === null && !isset($err['invoice_date'])) $err['invoice_date'] = 'Choose the invoice date.';
    $rid = preg_match('/^[a-f0-9]{32}$/', (string)($_POST['request_id'] ?? '')) ? (string)$_POST['request_id'] : null;
    if ($rid) { // the same form sent twice (double click / retry) → the entry it already made
        $q = $db->prepare('SELECT id FROM invoices WHERE request_id = ?'); $q->execute([$rid]);
        if ($done = (int)$q->fetchColumn()) invJson(200, ['ok' => true, 'repeat' => true, 'row' => invoiceGridRow($db, $load($done))]);
    }
    $sid = (int)($_POST['survey_id'] ?? 0) ?: null;
    if ($sid) {
        $q = $db->prepare('SELECT id FROM surveys WHERE id = ?'); $q->execute([$sid]);
        if (!$q->fetchColumn()) $err['survey_id'] = 'The linked survey no longer exists.';
        $q = $db->prepare('SELECT invoice_no, deleted_at FROM invoices WHERE survey_id = ?'); $q->execute([$sid]);
        if ($o = $q->fetch(PDO::FETCH_ASSOC)) $err['survey_id'] = 'This survey already has an Invoices entry' . ($o['invoice_no'] ? ' (' . $o['invoice_no'] . ')' : '') . ($o['deleted_at'] ? ' under Deleted — restore it instead.' : ' — edit that entry instead.');
    }
    $auto = false; // the invoice number is always typed (no automatic series); no_auto is no longer honoured
    $code = isset($err['client_id']) ? 'XX' : invoiceCodeForClient($db, $v['client_id']); // before the transaction (may save a missing client code)
    if (!$auto) {
        [$no, $ne] = invoiceNormalizeNumber($v['invoice_no'], $code);
        if ($ne) $err['invoice_no'] = $ne;
    }
    [$rv, $rerr] = $recForm(isset($err['invoice_no']) ? null : ($no ?? null));
    $err += $rerr;
    if ($err) invJson(422, ['ok' => false, 'error' => reset($err), 'errors' => $err]);
    if (!$sid && ($_POST['confirm_dup'] ?? '') !== '1' && ($same = $sameVessel($v['vessel_name'], 0))) {
        invJson(409, ['ok' => false, 'confirm' => 'vessel', 'matches' => $same, 'error' => 'Invoices already has an entry for this vessel.']);
    }
    try {
        $db->beginTransaction();
        $next = invoiceLockSequence($db);
        if ($auto) {
            $no = 'YMR-' . $code . '-' . invoiceFirstFreeNumber($db, max(INVOICE_FIRST_NUMBER, $next));
        } elseif ($other = invoiceNumberTakenBy($db, $no)) {
            $db->rollBack();
            $n = invoiceNumberPart($no);
            invJson(409, ['ok' => false, 'errors' => ['invoice_no' => 1], 'error' => $n !== null && strcasecmp($other, $no) !== 0 ? 'Number ' . $n . ' is already used by invoice ' . $other . '.' : 'Invoice number ' . $other . ' already exists.']);
        }
        if ($rc = $recClash($rv, $no, 0)) {
            $db->rollBack();
            invJson(409, ['ok' => false, 'errors' => ['rec_invoice_no' => $rc], 'error' => $rc]);
        }
        $db->prepare("INSERT INTO invoices (survey_id, vessel_name, report_number, invoice_status, status_updated_by, status_updated_at, invoice_no, invoice_date,
                invoice_amount, invoice_notes, registered_via, own_details, client_id, port_id, survey_type_ids, survey_completed_date, created_by, request_id,
                rec_invoice_no, rec_invoice_date, rec_invoice_amount, manual_service)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'manual', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$sid, $v['vessel_name'], $v['report_number'], $v['invoice_status'], $uid ?: null, $no, $v['invoice_date'], $v['invoice_amount'], $v['invoice_notes'],
               $v['client_id'], $v['port_id'], $v['survey_type_ids'], $v['survey_completed_date'], $uid ?: null, $rid,
               $rv['rec_invoice_no'] ?? null, $rv['rec_invoice_date'] ?? null, $rv['rec_invoice_amount'] ?? null, invoiceManualServiceFromPost()]);
        $newId = (int)$db->lastInsertId();
        invoiceAdvanceSequence($db, $no);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            if ($rid) { $q = $db->prepare('SELECT id FROM invoices WHERE request_id = ?'); $q->execute([$rid]); if ($done = (int)$q->fetchColumn()) invJson(200, ['ok' => true, 'repeat' => true, 'row' => invoiceGridRow($db, $load($done))]); }
            invJson(409, ['ok' => false, 'errors' => ['invoice_no' => 1], 'error' => 'That invoice number or survey was just used by another entry — please check and save again.']);
        }
        error_log('invoices create: ' . $e->getMessage());
        invJson(500, ['ok' => false, 'error' => 'The invoice could not be saved. Please try again.']);
    }
    $shown = strtoupper(trim((string)($_POST['shown_no'] ?? '')));
    invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($newId)), 'number_changed' => $auto && $shown !== '' && $shown !== $no]);
}

$row = $id > 0 ? $load($id) : null;
if (!$row) invJson(404, ['ok' => false, 'error' => 'Invoice not found.']);

// ---------- file (GET) ----------
if ($action === 'file') {
    $rec = ($_GET['which'] ?? '') === 'recovery';
    $abs = invoiceFileAbs($row[$rec ? 'rec_file_path' : 'file_path'] ?? null);
    if (!$abs) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit($rec ? 'No recovery invoice for this vessel.' : 'No invoice PDF uploaded for this vessel.'); }
    $name = preg_replace('/[^A-Za-z0-9 ._()\-]/', '_', (string)($row[$rec ? 'rec_file_name' : 'file_name'] ?: ($rec ? 'recovery-invoice.pdf' : 'invoice.pdf')));
    if (strtolower(substr($name, -4)) !== '.pdf') $name .= '.pdf';
    $inline = ($_GET['mode'] ?? 'view') !== 'download';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') invJson(405, ['ok' => false, 'error' => 'POST required.']);
$uid = (int)($_SESSION['user_id'] ?? 0);

/** Optional invoice details from the form: [ok, values or error]. */
$meta = function (): array {
    $no = mb_substr(trim((string)($_POST['invoice_no'] ?? '')), 0, 100);
    $date = trim((string)($_POST['invoice_date'] ?? ''));
    if ($date !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) return [false, 'Invoice date is not valid.'];
    }
    $amt = str_replace([',', ' '], '', trim((string)($_POST['invoice_amount'] ?? '')));
    if ($amt !== '' && (!is_numeric($amt) || (float)$amt < 0 || (float)$amt > 999999999999)) return [false, 'Amount must be a number.'];
    $notes = mb_substr(trim((string)($_POST['invoice_notes'] ?? '')), 0, 500);
    return [true, [$no !== '' ? $no : null, $date !== '' ? $date : null, $amt !== '' ? round((float)$amt, 2) : null, $notes !== '' ? $notes : null]];
};

/** A number typed in the Upload / Edit PDF form must not be another invoice's (null = ok). */
$numberClash = function (?string $no) use ($db, $id, $row): ?string {
    if ($no === null || strcasecmp($no, (string)($row['invoice_no'] ?? '')) === 0) return null;
    $other = invoiceNumberTakenBy($db, $no, $id);
    return $other === null ? null : 'Invoice number ' . $no . ' is already used (' . $other . ').';
};

if (!empty($row['deleted_at']) && $action !== 'restore') invJson(409, ['ok' => false, 'error' => 'This entry was deleted from Invoices — restore it first.']);

switch ($action) {
    case 'remove': {
        $why = invoiceRemoveBlocker($row);
        if ($why === '' && $db->query("SHOW TABLES LIKE 'invoice_payments'")->fetchColumn()) {
            $q = $db->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
            $q->execute([$id]);
            if ((int)$q->fetchColumn() > 0) $why = 'Payments are recorded for this entry, so it cannot be deleted.';
        }
        if ($why !== '') invJson(409, ['ok' => false, 'error' => $why]);
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
        // only while still pending with no invoice made (checked again in the UPDATE itself)
        $u = $db->prepare("UPDATE invoices SET deleted_at = NOW(), deleted_by = ?, delete_reason = ?
            WHERE id = ? AND deleted_at IS NULL AND invoice_status <> 'Completed' AND file_path IS NULL AND rec_file_path IS NULL");
        $u->execute([$uid ?: null, $reason !== '' ? $reason : null, $id]);
        if (!$u->rowCount()) invJson(409, ['ok' => false, 'error' => 'This entry changed meanwhile — reload the page.']);
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id))]);
    }
    case 'restore':
        if (empty($row['deleted_at'])) invJson(409, ['ok' => false, 'error' => 'This entry is not deleted.']);
        $db->prepare('UPDATE invoices SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = ?')->execute([$id]);
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id))]);

    case 'update': {
        // Edit: the same invoice row is changed (id, survey link, PDFs and payments stay).
        invoiceEnsureSequence($db);
        invoiceEnsureNumberKey($db);
        [$v, $err] = $invForm();
        $oldNo = trim((string)($row['invoice_no'] ?? ''));
        $typed = trim($v['invoice_no']);
        $no = $oldNo !== '' ? $oldNo : null;
        if ($typed === '' && $oldNo !== '') $err['invoice_no'] = 'The invoice number cannot be removed.';
        elseif ($typed !== '' && strcasecmp(preg_replace('/\s+/', '', $typed), $oldNo) !== 0) {
            $code = isset($err['client_id']) ? 'XX' : invoiceCodeForClient($db, $v['client_id']);
            [$no, $ne] = invoiceNormalizeNumber($typed, $code);
            if ($ne) $err['invoice_no'] = $ne;
        }
        [$rv, $rerr] = $recForm(isset($err['invoice_no']) ? null : $no);
        $err += $rerr;
        $hadRec = !empty($row['rec_file_path']) || !empty($row['rec_invoice_date']) || trim((string)($row['rec_invoice_no'] ?? '')) !== '';
        if ($rv === null && $hadRec) {
            // taking the recovery invoice off: only while nothing depends on it
            if (!empty($row['rec_file_path'])) $err['rec_on'] = 'A recovery invoice PDF is stored for this entry, so the recovery invoice cannot be removed here.';
            elseif ($db->query("SHOW TABLES LIKE 'invoice_payments'")->fetchColumn()) {
                $q = $db->prepare("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ? AND kind = 'recovery'");
                $q->execute([$id]);
                if ((int)$q->fetchColumn() > 0) $err['rec_on'] = 'Payments are recorded for the recovery invoice, so it cannot be removed.';
            }
        }
        if ($err) invJson(422, ['ok' => false, 'error' => reset($err), 'errors' => $err]);
        if (!$row['sid'] && ($_POST['confirm_dup'] ?? '') !== '1' && strcasecmp($v['vessel_name'], (string)$row['s_vessel_name']) !== 0 && ($same = $sameVessel($v['vessel_name'], $id))) {
            invJson(409, ['ok' => false, 'confirm' => 'vessel', 'matches' => $same, 'error' => 'Invoices already has an entry for this vessel.']);
        }
        // Vessel & survey details: an entry from Pending Reports keeps following its survey
        // unless one of these was actually changed here.
        $curTypes = implode(',', array_map('intval', array_filter(explode(',', str_replace(' ', '', (string)($row['survey_type_ids'] ?? '')))))) ?: ((int)($row['survey_type_id'] ?? 0) ?: '');
        $cur = [(string)$row['s_vessel_name'], (string)($row['s_report_number'] ?? ''), (int)($row['eff_client_id'] ?? 0), (int)($row['eff_port_id'] ?? 0), (string)$curTypes,
            !empty($row['survey_completed_date']) && strpos((string)$row['survey_completed_date'], '0000') !== 0 ? substr((string)$row['survey_completed_date'], 0, 10) : ''];
        $new = [$v['vessel_name'], (string)$v['report_number'], (int)$v['client_id'], (int)$v['port_id'], (string)$v['survey_type_ids'], (string)$v['survey_completed_date']];
        $own = !empty($row['own_details']) || $cur !== $new;
        $statusChanged = $v['invoice_status'] !== $row['invoice_status'];
        try {
            $db->beginTransaction();
            $lk = $db->prepare('SELECT invoice_no, deleted_at FROM invoices WHERE id = ? FOR UPDATE'); // same lock order as invoiceAllocateNumber()
            $lk->execute([$id]);
            $now = $lk->fetch(PDO::FETCH_ASSOC);
            if (!$now || !empty($now['deleted_at']) || trim((string)$now['invoice_no']) !== $oldNo) {
                $db->rollBack();
                invJson(409, ['ok' => false, 'error' => 'This invoice changed meanwhile — reload the page and edit again.']);
            }
            invoiceLockSequence($db);
            if ($no !== null && $no !== $oldNo && ($other = invoiceNumberTakenBy($db, $no, $id))) {
                $db->rollBack();
                $n = invoiceNumberPart($no);
                invJson(409, ['ok' => false, 'errors' => ['invoice_no' => 1], 'error' => $n !== null && strcasecmp($other, $no) !== 0 ? 'Number ' . $n . ' is already used by invoice ' . $other . '.' : 'Invoice number ' . $other . ' already exists.']);
            }
            if ($rc = $recClash($rv, $no, $id)) {
                $db->rollBack();
                invJson(409, ['ok' => false, 'errors' => ['rec_invoice_no' => $rc], 'error' => $rc]);
            }
            $set = 'invoice_no = ?, invoice_date = ?, invoice_amount = ?, invoice_notes = ?, invoice_status = ?, rec_invoice_no = ?, rec_invoice_date = ?, rec_invoice_amount = ?';
            $p = [$no, $v['invoice_date'], $v['invoice_amount'], $v['invoice_notes'], $v['invoice_status'],
                $rv['rec_invoice_no'] ?? null, $rv['rec_invoice_date'] ?? null, $rv['rec_invoice_amount'] ?? null];
            if (isset($_POST['service_desc'])) { $set .= ', manual_service = ?'; $p[] = invoiceManualServiceFromPost(); } // the form's Invoice Services (older clients don't send them)
            if ($statusChanged) { $set .= ', status_updated_by = ?, status_updated_at = NOW()'; $p[] = $uid ?: null; }
            if ($own) {
                $set .= ', own_details = 1, vessel_name = ?, report_number = ?, client_id = ?, port_id = ?, survey_type_ids = ?, survey_completed_date = ?';
                array_push($p, $v['vessel_name'], $v['report_number'], $v['client_id'], $v['port_id'], $v['survey_type_ids'], $v['survey_completed_date']);
            }
            $p[] = $id;
            $db->prepare("UPDATE invoices SET $set WHERE id = ?")->execute($p);
            if ($no !== null && $no !== $oldNo) invoiceAdvanceSequence($db, $no);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') invJson(409, ['ok' => false, 'errors' => ['invoice_no' => 1], 'error' => 'That invoice number was just used by another entry — choose another.']);
            error_log('invoices update: ' . $e->getMessage());
            invJson(500, ['ok' => false, 'error' => 'The invoice could not be updated. Please try again.']);
        }
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id)), 'number_changed' => $no !== $oldNo && $oldNo !== '']);
    }

    case 'status':
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, INVOICE_STATUSES, true)) invJson(422, ['ok' => false, 'error' => 'Unknown status.']);
        $db->prepare('UPDATE invoices SET invoice_status = ?, status_updated_by = ?, status_updated_at = NOW() WHERE id = ?')
           ->execute([$status, $uid, $id]);
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id))]);

    case 'meta':
        [$ok, $m] = $meta();
        if (!$ok) invJson(422, ['ok' => false, 'error' => $m]);
        if ($c = $numberClash($m[0])) invJson(409, ['ok' => false, 'error' => $c]);
        $db->prepare('UPDATE invoices SET invoice_no = ?, invoice_date = ?, invoice_amount = ?, invoice_notes = ? WHERE id = ?')
           ->execute([$m[0], $m[1], $m[2], $m[3], $id]);
        invJson(200, ['ok' => true, 'row' => invoiceGridRow($db, $load($id))]);

    case 'upload':
        $err = null;
        if (!invoiceValidatePdf($_FILES['invoice_pdf'] ?? [], $err)) invJson(422, ['ok' => false, 'error' => $err]);
        [$ok, $m] = $meta();
        if (!$ok) invJson(422, ['ok' => false, 'error' => $m]);
        if ($c = $numberClash($m[0])) invJson(409, ['ok' => false, 'error' => $c]);
        $dir = invoiceStorageDir();
        $rel = INVOICE_DIR . '/inv' . $id . '_' . bin2hex(random_bytes(12)) . '.pdf';
        if (!is_dir($dir) || !move_uploaded_file($_FILES['invoice_pdf']['tmp_name'], dirname(__DIR__) . '/' . $rel)) {
            invJson(500, ['ok' => false, 'error' => 'The file could not be stored. Please try again.']);
        }
        $orig = mb_substr(basename((string)$_FILES['invoice_pdf']['name']), 0, 255);
        $old = $row['file_path'] ?? null;
        try {
            $db->beginTransaction();
            $db->prepare('UPDATE invoices SET file_name = ?, file_path = ?, file_size = ?, uploaded_by = ?, uploaded_at = NOW(),
                    invoice_no = COALESCE(?, invoice_no), invoice_date = COALESCE(?, invoice_date),
                    invoice_amount = COALESCE(?, invoice_amount), invoice_notes = COALESCE(?, invoice_notes) WHERE id = ?')
               ->execute([$orig, $rel, (int)$_FILES['invoice_pdf']['size'], $uid, $m[0], $m[1], $m[2], $m[3], $id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            @unlink(dirname(__DIR__) . '/' . $rel);
            error_log('invoices upload: ' . $e->getMessage());
            invJson(500, ['ok' => false, 'error' => 'The invoice could not be saved. Please try again.']);
        }
        // The previous PDF goes only after the new one is saved, and only if no other row uses it.
        if ($old && $old !== $rel) invoiceRemoveFileIfUnused($db, $old);
        invJson(200, ['ok' => true, 'replaced' => (bool)$old, 'row' => invoiceGridRow($db, $load($id))]);

    case 'upload_rec': {
        // The recovery invoice PDF (Add / Edit Invoice). The entry must have a recovery invoice.
        $err = null;
        if (!invoiceValidatePdf($_FILES['rec_pdf'] ?? [], $err)) invJson(422, ['ok' => false, 'error' => $err]);
        if (empty($row['rec_file_path']) && empty($row['rec_invoice_date']) && trim((string)($row['rec_invoice_no'] ?? '')) === '') {
            invJson(409, ['ok' => false, 'error' => 'Add the recovery invoice details first (Edit Invoice).']);
        }
        $dir = invoiceStorageDir();
        $rel = INVOICE_DIR . '/rec' . $id . '_' . bin2hex(random_bytes(12)) . '.pdf';
        if (!is_dir($dir) || !move_uploaded_file($_FILES['rec_pdf']['tmp_name'], dirname(__DIR__) . '/' . $rel)) {
            invJson(500, ['ok' => false, 'error' => 'The file could not be stored. Please try again.']);
        }
        $orig = mb_substr(basename((string)$_FILES['rec_pdf']['name']), 0, 255);
        $old = $row['rec_file_path'] ?? null;
        try {
            $db->beginTransaction();
            $cur = $db->prepare('SELECT file_path, recovery_pending FROM invoices WHERE id = ? FOR UPDATE');
            $cur->execute([$id]);
            $cur = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
            $db->prepare('UPDATE invoices SET rec_file_name = ?, rec_file_path = ?, rec_generated_at = NOW(),
                    rec_invoice_date = COALESCE(rec_invoice_date, invoice_date, CURDATE()), recovery_pending = 0 WHERE id = ?')
               ->execute([$orig, $rel, $id]);
            // Generate Invoice was waiting for this recovery invoice: the invoice is now complete.
            if (!empty($cur['recovery_pending']) && invoiceFileAbs($cur['file_path'] ?? null) !== null) {
                $db->prepare("UPDATE invoices SET status_updated_by = IF(invoice_status = 'Completed', status_updated_by, ?),
                        status_updated_at = IF(invoice_status = 'Completed', status_updated_at, NOW()), invoice_status = 'Completed' WHERE id = ?")
                   ->execute([$uid, $id]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            @unlink(dirname(__DIR__) . '/' . $rel);
            error_log('invoices upload_rec: ' . $e->getMessage());
            invJson(500, ['ok' => false, 'error' => 'The recovery invoice could not be saved. Please try again.']);
        }
        if ($old && $old !== $rel) invoiceRemoveFileIfUnused($db, $old);
        invJson(200, ['ok' => true, 'replaced' => (bool)$old, 'row' => invoiceGridRow($db, $load($id))]);
    }

    case 'recovery': {
        // Upload / Edit PDF dialog: recovery invoice number, date, amount and (optionally) its PDF.
        invoiceEnsureSequence($db);
        $no = trim((string)($row['invoice_no'] ?? ''));
        [$rv, $err] = $recForm($no !== '' ? $no : null);
        if ($rv === null) invJson(422, ['ok' => false, 'error' => 'Tick "Recovery invoice" to save it.']);
        $hasPdf = isset($_FILES['rec_pdf']) && ($_FILES['rec_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $perr = null;
        if ($hasPdf && !invoiceValidatePdf($_FILES['rec_pdf'], $perr)) $err['rec_file'] = $perr;
        if ($err) invJson(422, ['ok' => false, 'error' => reset($err), 'errors' => $err]);
        $rel = null;
        if ($hasPdf) {
            $dir = invoiceStorageDir();
            $rel = INVOICE_DIR . '/rec' . $id . '_' . bin2hex(random_bytes(12)) . '.pdf';
            if (!is_dir($dir) || !move_uploaded_file($_FILES['rec_pdf']['tmp_name'], dirname(__DIR__) . '/' . $rel)) {
                invJson(500, ['ok' => false, 'error' => 'The recovery PDF could not be stored. Please try again.']);
            }
        }
        $old = $row['rec_file_path'] ?? null;
        try {
            $db->beginTransaction();
            $cur = $db->prepare('SELECT invoice_no, file_path, recovery_pending, deleted_at FROM invoices WHERE id = ? FOR UPDATE');
            $cur->execute([$id]);
            $cur = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$cur || !empty($cur['deleted_at']) || trim((string)$cur['invoice_no']) !== $no) {
                $db->rollBack();
                if ($rel) @unlink(dirname(__DIR__) . '/' . $rel);
                invJson(409, ['ok' => false, 'error' => 'This invoice changed meanwhile — reload the page and try again.']);
            }
            invoiceLockSequence($db);
            if ($rc = $recClash($rv, $no !== '' ? $no : null, $id)) {
                $db->rollBack();
                if ($rel) @unlink(dirname(__DIR__) . '/' . $rel);
                invJson(409, ['ok' => false, 'errors' => ['rec_invoice_no' => $rc], 'error' => $rc]);
            }
            $set = 'rec_invoice_no = ?, rec_invoice_date = ?, rec_invoice_amount = ?';
            $p = [$rv['rec_invoice_no'], $rv['rec_invoice_date'], $rv['rec_invoice_amount']];
            if ($rel) {
                $set .= ', rec_file_name = ?, rec_file_path = ?, rec_generated_at = NOW(), recovery_pending = 0';
                array_push($p, mb_substr(basename((string)$_FILES['rec_pdf']['name']), 0, 255), $rel);
            }
            $p[] = $id;
            $db->prepare("UPDATE invoices SET $set WHERE id = ?")->execute($p);
            // Generate Invoice was waiting for this recovery invoice: the invoice is now complete.
            if ($rel && !empty($cur['recovery_pending']) && invoiceFileAbs($cur['file_path'] ?? null) !== null) {
                $db->prepare("UPDATE invoices SET status_updated_by = IF(invoice_status = 'Completed', status_updated_by, ?),
                        status_updated_at = IF(invoice_status = 'Completed', status_updated_at, NOW()), invoice_status = 'Completed' WHERE id = ?")
                   ->execute([$uid, $id]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($rel) @unlink(dirname(__DIR__) . '/' . $rel);
            error_log('invoices recovery: ' . $e->getMessage());
            invJson(500, ['ok' => false, 'error' => 'The recovery invoice could not be saved. Please try again.']);
        }
        if ($rel && $old && $old !== $rel) invoiceRemoveFileIfUnused($db, $old);
        invJson(200, ['ok' => true, 'replaced' => $rel && $old, 'row' => invoiceGridRow($db, $load($id))]);
    }

    case 'delete':
        $old = [$row['file_path'] ?? null, $row['rec_file_path'] ?? null];
        $wasDone = $row['invoice_status'] === 'Completed';
        // MySQL applies SET left to right, so invoice_status is changed last.
        // The invoice number is kept (numbers are never reused); generating again prints the same one.
        $db->prepare("UPDATE invoices SET file_name = NULL, file_path = NULL, file_size = NULL, uploaded_by = NULL, uploaded_at = NULL,
                invoice_date = NULL, invoice_amount = NULL, invoice_notes = NULL,
                gen_data = NULL, rec_gen_data = NULL, rec_file_name = NULL, rec_file_path = NULL, rec_generated_at = NULL, recovery_pending = 0,
                rec_invoice_date = NULL, rec_invoice_amount = NULL,
                status_updated_by = IF(invoice_status = 'Completed', ?, status_updated_by),
                status_updated_at = IF(invoice_status = 'Completed', NOW(), status_updated_at),
                invoice_status = IF(invoice_status = 'Completed', ?, invoice_status) WHERE id = ?")
           ->execute([$uid, INVOICE_DEFAULT_STATUS, $id]);
        foreach ($old as $o) invoiceRemoveFileIfUnused($db, $o);
        invJson(200, ['ok' => true, 'moved' => $wasDone, 'row' => invoiceGridRow($db, $load($id))]);
}
invJson(400, ['ok' => false, 'error' => 'Unknown action.']);
