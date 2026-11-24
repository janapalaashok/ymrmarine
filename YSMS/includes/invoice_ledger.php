<?php
/**
 * Invoice Airtable (invoices_airtable.php): payment tracking over the invoices that already
 * exist in the Invoices module. No second invoice record is made:
 *
 *   one grid row = one invoice document of an `invoices` row
 *     kind 'invoice'  — the vessel invoice  (invoices.invoice_no / invoice_date / invoice_amount / file_path)
 *     kind 'recovery' — its recovery invoice (invoice_no + "B" / rec_invoice_date / rec_invoice_amount / rec_file_path)
 *   A document is listed once its PDF is stored (uploaded or generated) or once it has payments.
 *
 * Extra data lives in three small tables (created here when missing; same as
 * database/migration_invoice_ledger.sql):
 *   invoice_accounts  — per document: due date, remarks, cancelled flag
 *   invoice_payments  — every payment transaction (never overwritten; a wrong one is voided)
 *   invoice_activity  — who changed what
 *   invoice_grid_prefs — each user's column layout / sort
 *
 * Nothing here reads or writes survey workflow status.
 */
require_once __DIR__ . '/invoice_helpers.php';

const LEDGER_KINDS = ['invoice', 'recovery'];
const LEDGER_STATUSES = ['Paid', 'Overdue', 'Due Soon', 'Partially Paid', 'Unpaid', 'Cancelled'];
const LEDGER_DUE_SOON_DAYS = 7;
const LEDGER_PAY_MODES = ['Bank Transfer', 'NEFT', 'RTGS', 'IMPS', 'UPI', 'Cheque', 'Cash', 'Card', 'Other'];
const LEDGER_RECEIPT_MAX = 10 * 1024 * 1024;

/** Only Admin works with invoices and payments (same rule as the Invoices module). */
function ledgerCanManage(): bool {
    return invoiceCanManage();
}

function ledgerEnsureTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    invoiceEnsureTable($db);
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_accounts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL COMMENT 'invoices.id',
        `kind` enum('invoice','recovery') NOT NULL DEFAULT 'invoice',
        `due_date` date DEFAULT NULL,
        `remarks` varchar(1000) DEFAULT NULL,
        `cancelled` tinyint(1) NOT NULL DEFAULT 0,
        `cancelled_reason` varchar(500) DEFAULT NULL,
        `updated_by` int(11) DEFAULT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_invoice_accounts_doc` (`invoice_id`, `kind`),
        KEY `idx_invoice_accounts_due` (`due_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_payments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL COMMENT 'invoices.id',
        `kind` enum('invoice','recovery') NOT NULL DEFAULT 'invoice',
        `payment_date` date NOT NULL,
        `amount` decimal(14,2) NOT NULL,
        `payment_mode` varchar(30) NOT NULL,
        `reference` varchar(120) DEFAULT NULL,
        `transaction_id` varchar(120) DEFAULT NULL,
        `remarks` varchar(500) DEFAULT NULL,
        `receipt_name` varchar(255) DEFAULT NULL,
        `receipt_path` varchar(255) DEFAULT NULL,
        `status` enum('success','void') NOT NULL DEFAULT 'success',
        `void_reason` varchar(500) DEFAULT NULL,
        `voided_by` int(11) DEFAULT NULL,
        `voided_at` datetime DEFAULT NULL,
        `request_id` char(32) DEFAULT NULL COMMENT 'one per Add Payment form; stops double saves',
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_invoice_payments_request` (`request_id`),
        UNIQUE KEY `uq_invoice_payments_txn` (`invoice_id`, `kind`, `transaction_id`),
        KEY `idx_invoice_payments_doc` (`invoice_id`, `kind`, `status`),
        KEY `idx_invoice_payments_date` (`payment_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_activity` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `kind` enum('invoice','recovery') NOT NULL DEFAULT 'invoice',
        `user_id` int(11) DEFAULT NULL,
        `action` varchar(40) NOT NULL,
        `details` varchar(1000) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_invoice_activity_doc` (`invoice_id`, `kind`, `id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_grid_prefs` (
        `user_id` int(11) NOT NULL,
        `prefs` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    ledgerBackfillRecoveryAmounts($db);
    $done = true;
}

/**
 * Recovery invoices downloaded before their amount was stored: the amount is worked out from
 * the saved form (unit x qty per fuel, exactly as the PDF printed it).
 */
function ledgerBackfillRecoveryAmounts(PDO $db): void {
    $rows = $db->query("SELECT id, rec_gen_data, rec_generated_at FROM invoices
        WHERE rec_file_path IS NOT NULL AND rec_invoice_amount IS NULL AND rec_gen_data IS NOT NULL LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $up = $db->prepare('UPDATE invoices SET rec_invoice_amount = ?, rec_invoice_date = COALESCE(rec_invoice_date, ?) WHERE id = ? AND rec_invoice_amount IS NULL');
    foreach ($rows as $r) {
        $f = json_decode((string)$r['rec_gen_data'], true) ?: [];
        $amt = ledgerRecoveryAmount($f);
        $date = isset($f['invoice_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['invoice_date']) ? $f['invoice_date'] : (substr((string)$r['rec_generated_at'], 0, 10) ?: null);
        $up->execute([$amt, $date, (int)$r['id']]);
    }
}

/** Total of a recovery invoice form: Σ unit x qty over VLSFO / HSFO / LSMGO. */
function ledgerRecoveryAmount(array $f): float {
    $t = 0.0;
    foreach (['vlsfo', 'hsfo', 'lsmgo'] as $k) {
        $r = str_replace([',', ' '], '', (string)($f[$k . '_rate'] ?? ''));
        $q = str_replace([',', ' '], '', (string)($f[$k . '_qty'] ?? ''));
        if (is_numeric($r) && is_numeric($q) && (float)$r >= 0 && (float)$q >= 0) $t += round((float)$r * (float)$q, 2);
    }
    return round($t, 2);
}

/** "12-invoice" → [12, 'invoice'] (null when not valid). */
function ledgerParseKey(string $key): ?array {
    if (!preg_match('/^(\d{1,10})-(invoice|recovery)$/', $key, $m)) return null;
    return [(int)$m[1], $m[2]];
}

/**
 * The grid as a derived table (alias x) with every column the page filters / sorts on.
 * $today is Y-m-d (PHP clock, so status and summaries agree with what the page shows).
 */
function ledgerBaseSql(PDO $db, string $today): string {
    $t = $db->quote($today);
    $soon = $db->quote(date('Y-m-d', strtotime($today . ' +' . LEDGER_DUE_SOON_DAYS . ' days')));
    $docs = "SELECT i.id AS invoice_id, 'invoice' AS kind, i.invoice_no AS doc_no, i.invoice_date AS doc_date, i.invoice_amount AS amount,
                    i.file_path AS doc_path, i.file_name AS doc_name, i.survey_id, i.vessel_name AS i_vessel, i.report_number AS i_report,
                    i.own_details AS i_own, i.client_id AS i_client, i.port_id AS i_port
             FROM invoices i
             WHERE i.deleted_at IS NULL AND (i.file_path IS NOT NULL OR EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.invoice_id = i.id AND ip.kind = 'invoice'))
             UNION ALL
             SELECT i.id, 'recovery', COALESCE(NULLIF(i.rec_invoice_no, ''), CONCAT(COALESCE(i.invoice_no, ''), 'B')), i.rec_invoice_date, i.rec_invoice_amount,
                    i.rec_file_path, i.rec_file_name, i.survey_id, i.vessel_name, i.report_number,
                    i.own_details, i.client_id, i.port_id
             FROM invoices i
             WHERE i.deleted_at IS NULL AND (i.rec_file_path IS NOT NULL OR EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.invoice_id = i.id AND ip.kind = 'recovery'))";
    $recv = 'COALESCE(p.received, 0)';
    $out = "(CASE WHEN d.amount IS NULL THEN NULL ELSE d.amount - $recv END)";
    $status = "(CASE WHEN COALESCE(a.cancelled, 0) = 1 THEN 'Cancelled'
                     WHEN d.amount IS NOT NULL AND d.amount > 0 AND $out <= 0 THEN 'Paid'
                     WHEN a.due_date IS NOT NULL AND a.due_date < $t AND ($out IS NULL OR $out > 0) THEN 'Overdue'
                     WHEN a.due_date IS NOT NULL AND a.due_date >= $t AND a.due_date <= $soon AND ($out IS NULL OR $out > 0) THEN 'Due Soon'
                     WHEN $recv > 0 THEN 'Partially Paid'
                     ELSE 'Unpaid' END)";
    return "(SELECT CONCAT(d.invoice_id, '-', d.kind) AS doc_key, d.invoice_id, d.kind, d.doc_no, d.doc_date, d.amount,
                    d.doc_path, d.doc_name, d.survey_id,
                    IF(d.i_own = 1, d.i_vessel, COALESCE(s.vessel_name, d.i_vessel)) AS vessel_name,
                    IF(d.i_own = 1, d.i_report, COALESCE(s.report_number, d.i_report)) AS survey_ref,
                    c.company_name AS client_name, IF(d.i_own = 1, d.i_client, s.client_id) AS client_id, pt.port_name, IF(d.i_own = 1, d.i_port, s.port_id) AS port_id,
                    a.due_date, a.remarks, COALESCE(a.cancelled, 0) AS cancelled,
                    $recv AS received, $out AS outstanding, p.last_paid, COALESCE(p.n, 0) AS payment_count,
                    $status AS pay_status
             FROM ($docs) d
             LEFT JOIN invoice_accounts a ON a.invoice_id = d.invoice_id AND a.kind = d.kind
             LEFT JOIN (SELECT invoice_id, kind, SUM(amount) AS received, MAX(payment_date) AS last_paid, COUNT(*) AS n
                        FROM invoice_payments WHERE status = 'success' GROUP BY invoice_id, kind) p
                    ON p.invoice_id = d.invoice_id AND p.kind = d.kind
             LEFT JOIN surveys s ON s.id = d.survey_id
             LEFT JOIN clients c ON c.id = IF(d.i_own = 1, d.i_client, s.client_id)
             LEFT JOIN ports pt ON pt.id = IF(d.i_own = 1, d.i_port, s.port_id)) x";
}

/** Filters from the request → [state, whereSql, params] on alias x. */
function ledgerFilters(array $g): array {
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) && strtotime((string)$v) ? (string)$v : '';
    $num = function ($v) { $v = str_replace([',', ' ', '$', '$'], '', trim((string)$v)); return $v !== '' && is_numeric($v) ? (float)$v : null; };
    $st = [
        'q' => mb_substr(trim((string)($g['q'] ?? '')), 0, 100),
        'status' => in_array($g['status'] ?? '', LEDGER_STATUSES, true) ? (string)$g['status'] : '',
        'client' => (int)($g['client'] ?? 0),
        'port' => (int)($g['port'] ?? 0),
        'vessel' => mb_substr(trim((string)($g['vessel'] ?? '')), 0, 100),
        'inv_from' => $date($g['inv_from'] ?? ''), 'inv_to' => $date($g['inv_to'] ?? ''),
        'due_from' => $date($g['due_from'] ?? ''), 'due_to' => $date($g['due_to'] ?? ''),
        'rec_min' => $num($g['rec_min'] ?? ''), 'rec_max' => $num($g['rec_max'] ?? ''),
        'out_min' => $num($g['out_min'] ?? ''), 'out_max' => $num($g['out_max'] ?? ''),
    ];
    $w = ['1=1']; $p = [];
    if ($st['q'] !== '') {
        $like = '%' . addcslashes($st['q'], '%_\\') . '%';
        $w[] = "(x.doc_no LIKE ? OR x.client_name LIKE ? OR x.vessel_name LIKE ? OR x.port_name LIKE ? OR x.survey_ref LIKE ?
                 OR EXISTS (SELECT 1 FROM invoice_payments q WHERE q.invoice_id = x.invoice_id AND q.kind = x.kind AND (q.reference LIKE ? OR q.transaction_id LIKE ?)))";
        array_push($p, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($st['status'] !== '') { $w[] = 'x.pay_status = ?'; $p[] = $st['status']; }
    if ($st['client'] > 0) { $w[] = 'x.client_id = ?'; $p[] = $st['client']; }
    if ($st['port'] > 0) { $w[] = 'x.port_id = ?'; $p[] = $st['port']; }
    if ($st['vessel'] !== '') { $w[] = 'x.vessel_name LIKE ?'; $p[] = '%' . addcslashes($st['vessel'], '%_\\') . '%'; }
    foreach ([['inv_from', 'x.doc_date >= ?'], ['inv_to', 'x.doc_date <= ?'], ['due_from', 'x.due_date >= ?'], ['due_to', 'x.due_date <= ?'],
              ['rec_min', 'x.received >= ?'], ['rec_max', 'x.received <= ?'], ['out_min', 'x.outstanding >= ?'], ['out_max', 'x.outstanding <= ?']] as [$k, $sql]) {
        if ($st[$k] !== '' && $st[$k] !== null) { $w[] = $sql; $p[] = $st[$k]; }
    }
    return [$st, implode(' AND ', $w), $p];
}

/** Sortable fields → SQL expression on alias x. */
const LEDGER_SORTS = [
    'invoice_no' => "CAST(SUBSTRING_INDEX(REPLACE(x.doc_no, 'B', ''), '-', -1) AS UNSIGNED)",
    'invoice_date' => 'x.doc_date',
    'client' => 'x.client_name',
    'vessel' => 'x.vessel_name',
    'port' => 'x.port_name',
    'amount' => 'x.amount',
    'received' => 'x.received',
    'outstanding' => 'x.outstanding',
    'due_date' => 'x.due_date',
    'last_paid' => 'x.last_paid',
    'status' => "FIELD(x.pay_status, 'Overdue', 'Due Soon', 'Partially Paid', 'Unpaid', 'Paid', 'Cancelled')",
];

/** ORDER BY from [{f, d}] (up to 3 levels); empty values sort last. Default: newest invoice first. */
function ledgerOrderSql($sorts): string {
    $parts = [];
    foreach (array_slice(is_array($sorts) ? $sorts : [], 0, 3) as $s) {
        $f = is_array($s) ? (string)($s['f'] ?? '') : '';
        if (!isset(LEDGER_SORTS[$f])) continue;
        $e = LEDGER_SORTS[$f];
        $dir = strtolower((string)($s['d'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $parts[] = "($e IS NULL) ASC, $e $dir";
        if ($f === 'invoice_no') $parts[] = "x.doc_no $dir";
    }
    if (!$parts) $parts[] = '(x.doc_date IS NULL) ASC, x.doc_date DESC';
    $parts[] = 'x.invoice_id DESC, x.kind ASC';
    return implode(', ', $parts);
}

/** Summary over the filtered rows (cancelled invoices are kept out of the money totals). */
function ledgerSummary(PDO $db, string $base, string $where, array $params): array {
    $st = $db->prepare("SELECT
            SUM(x.pay_status <> 'Cancelled') AS invoices,
            COALESCE(SUM(CASE WHEN x.pay_status <> 'Cancelled' THEN x.amount END), 0) AS amount,
            COALESCE(SUM(CASE WHEN x.pay_status <> 'Cancelled' THEN x.received END), 0) AS received,
            COALESCE(SUM(CASE WHEN x.pay_status = 'Overdue' THEN x.outstanding END), 0) AS overdue,
            SUM(x.pay_status = 'Overdue') AS overdue_count,
            SUM(x.pay_status = 'Due Soon') AS due_soon,
            COALESCE(SUM(CASE WHEN x.pay_status = 'Due Soon' THEN x.outstanding END), 0) AS due_soon_amount,
            SUM(x.pay_status = 'Paid') AS paid,
            SUM(x.pay_status = 'Cancelled') AS cancelled
        FROM $base WHERE $where");
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $amount = round((float)($r['amount'] ?? 0), 2);
    $received = round((float)($r['received'] ?? 0), 2);
    return [
        'invoices' => (int)($r['invoices'] ?? 0),
        'amount' => $amount,
        'received' => $received,
        'outstanding' => round($amount - $received, 2),
        'overdue' => round((float)($r['overdue'] ?? 0), 2),
        'overdue_count' => (int)($r['overdue_count'] ?? 0),
        'due_soon' => (int)($r['due_soon'] ?? 0),
        'due_soon_amount' => round((float)($r['due_soon_amount'] ?? 0), 2),
        'paid' => (int)($r['paid'] ?? 0),
        'cancelled' => (int)($r['cancelled'] ?? 0),
    ];
}

/** One grid row for the page. */
function ledgerRowJson(array $r): array {
    $money = fn($v) => $v === null ? null : round((float)$v, 2);
    return [
        'key' => (string)$r['doc_key'],
        'invoice_id' => (int)$r['invoice_id'],
        'kind' => (string)$r['kind'],
        'invoice_no' => (string)($r['doc_no'] ?? ''),
        'invoice_date' => (string)($r['doc_date'] ?? ''),
        'client' => (string)($r['client_name'] ?? ''),
        'vessel' => (string)($r['vessel_name'] ?? ''),
        'port' => (string)($r['port_name'] ?? ''),
        'survey_ref' => (string)($r['survey_ref'] ?? ''),
        'amount' => $money($r['amount']),
        'received' => $money($r['received']),
        'outstanding' => $money($r['outstanding']),
        'due_date' => (string)($r['due_date'] ?? ''),
        'last_paid' => (string)($r['last_paid'] ?? ''),
        'status' => (string)$r['pay_status'],
        'has_doc' => invoiceFileAbs($r['doc_path'] ?? null) !== null,
        'doc_name' => (string)($r['doc_name'] ?? ''),
        'remarks' => (string)($r['remarks'] ?? ''),
        'payments' => (int)$r['payment_count'],
    ];
}

/** Makes sure the document's account row exists (due date / remarks / cancelled live there). */
function ledgerEnsureAccount(PDO $db, int $invoiceId, string $kind): void {
    $db->prepare('INSERT IGNORE INTO invoice_accounts (invoice_id, kind) VALUES (?, ?)')->execute([$invoiceId, $kind]);
}

function ledgerLog(PDO $db, int $invoiceId, string $kind, string $action, string $details = ''): void {
    $db->prepare('INSERT INTO invoice_activity (invoice_id, kind, user_id, action, details) VALUES (?, ?, ?, ?, ?)')
       ->execute([$invoiceId, $kind, (int)($_SESSION['user_id'] ?? 0) ?: null, $action, mb_substr($details, 0, 1000)]);
}

/* ---------- payment receipts: uploads/invoices/rcpt<id>_<random>.<pdf|jpg|png> ---------- */
function ledgerReceiptAbs(?string $rel): ?string {
    if (!$rel || !preg_match('#^uploads/invoices/rcpt[A-Za-z0-9_\-]+\.(pdf|jpg|jpeg|png)$#', $rel)) return null;
    $base = realpath(dirname(__DIR__) . '/' . INVOICE_DIR);
    $abs = realpath(dirname(__DIR__) . '/' . $rel);
    return ($base && $abs && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}

/** Checks an uploaded receipt (PDF / JPG / PNG, sniffed on the server). Returns its extension or null. */
function ledgerValidateReceipt(array $f, ?string &$err): ?string {
    $err = null;
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $err = 'The receipt could not be received.'; return null; }
    if ((int)$f['size'] <= 0 || (int)$f['size'] > LEDGER_RECEIPT_MAX) { $err = 'The receipt must be smaller than 10 MB.'; return null; }
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) : '';
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    $nameExt = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!$ext || !in_array($nameExt, ['pdf', 'jpg', 'jpeg', 'png'], true)) { $err = 'Receipts must be a PDF, JPG or PNG file.'; return null; }
    return $ext;
}
