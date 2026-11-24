<?php
/**
 * Invoices module (invoices.php, ajax/invoices.php, invoice_pdf.php).
 *
 * One `invoices` row per survey (surveys.id). In this schema one `surveys` row is both the
 * vessel's assignment and its report, so the "vessel_id / report_id" reference is stored once
 * as `survey_id`. The row is created when the survey's documents are submitted and it moves to
 * Pending Reports (ajax/upload_handler.php), and is never removed or changed by the survey
 * workflow afterwards: invoice status, file and dates live only here.
 *
 * Files are stored under uploads/invoices/ — on Cloud Run that folder is the
 * ymrmarine-ysms-uploads Cloud Storage bucket (cloudbuild.yaml volume mount), the same storage
 * every other YSMS upload uses. They are served only through ajax/invoices.php?a=file (Admin).
 */

const INVOICE_STATUSES = ['Pending for CP Prices', 'Pending for Cost', 'Pending for Other Reason', 'Completed'];
const INVOICE_DEFAULT_STATUS = 'Pending for CP Prices';
const INVOICE_DIR = 'uploads/invoices';
const INVOICE_MAX_MB_DEFAULT = 20;

/** Only Admin works with invoices (same rule as the existing invoice_generator.php). */
function invoiceCanManage(): bool {
    return ($_SESSION['role'] ?? '') === 'Admin';
}

/** Largest accepted invoice PDF in bytes (env YSMS_INVOICE_MAX_MB, default 20 MB). */
function invoiceMaxBytes(): int {
    $mb = (int)(getenv('YSMS_INVOICE_MAX_MB') ?: INVOICE_MAX_MB_DEFAULT);
    return max(1, min(100, $mb)) * 1024 * 1024;
}

/** Creates the table when missing (same statement as database/migration_invoices.sql). */
function invoiceEnsureTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `invoices` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `survey_id` int(11) NOT NULL COMMENT 'surveys.id (vessel + report record)',
        `vessel_name` varchar(255) DEFAULT NULL COMMENT 'copy at registration, shown if the survey is ever deleted',
        `report_number` varchar(50) DEFAULT NULL COMMENT 'copy at registration',
        `invoice_status` enum('Pending for CP Prices','Pending for Cost','Pending for Other Reason','Completed') NOT NULL DEFAULT 'Pending for CP Prices',
        `status_updated_by` int(11) DEFAULT NULL,
        `status_updated_at` datetime DEFAULT NULL,
        `invoice_no` varchar(100) DEFAULT NULL,
        `invoice_date` date DEFAULT NULL,
        `invoice_amount` decimal(14,2) DEFAULT NULL,
        `invoice_notes` varchar(500) DEFAULT NULL,
        `file_name` varchar(255) DEFAULT NULL COMMENT 'original file name',
        `file_path` varchar(255) DEFAULT NULL COMMENT 'uploads/invoices/… (Cloud Storage bucket)',
        `file_size` int(11) DEFAULT NULL,
        `uploaded_by` int(11) DEFAULT NULL,
        `uploaded_at` datetime DEFAULT NULL,
        `registered_via` varchar(20) NOT NULL DEFAULT 'upload' COMMENT 'upload | backfill',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_invoices_survey` (`survey_id`),
        KEY `idx_invoices_status` (`invoice_status`),
        KEY `idx_invoices_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    invoiceEnsureGenColumns($db);
    $done = true;
}

/**
 * Columns for generated invoices (added to an existing table once; same as
 * database/migration_invoices.sql):
 *   gen_data / rec_gen_data   — the Generate Invoice / Recovery Invoice form as last downloaded
 *                               (JSON), so Modify re-opens it filled in
 *   rec_file_*                — the stored Recovery Invoice PDF (the vessel invoice uses file_*)
 *   rec_invoice_date / _amount — the recovery invoice's date and total (payment tracking)
 *   recovery_pending          — 1 when the Admin chose "Recovery invoice too": the invoice is
 *                               Completed only once the recovery invoice is downloaded as well
 */
function invoiceEnsureGenColumns(PDO $db): void {
    $have = array_flip(array_map('strtolower', $db->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN)));
    $add = [
        'gen_data' => 'MEDIUMTEXT NULL',
        'rec_gen_data' => 'MEDIUMTEXT NULL',
        'rec_file_name' => 'VARCHAR(255) NULL',
        'rec_file_path' => 'VARCHAR(255) NULL',
        'rec_generated_at' => 'DATETIME NULL',
        'recovery_pending' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'rec_invoice_date' => 'DATE NULL',          // recovery invoice's own date and total
        'rec_invoice_amount' => 'DECIMAL(14,2) NULL', // (Invoice Airtable payment tracking)
        'rec_invoice_no' => 'VARCHAR(64) NULL',     // recovery invoice number when it is not the usual
                                                    // invoice number + "B" (NULL = invoice_no + B)
        'deleted_at' => 'DATETIME NULL',            // entry deleted from Invoices (kept, can be restored;
        'deleted_by' => 'INT NULL',                 //  stays so the vessel is not registered again)
        'delete_reason' => 'VARCHAR(255) NULL',
        // Add Invoice / Edit (manual entry): the invoice's own vessel & survey details. Used instead
        // of the linked survey's only when own_details = 1 (manual invoices, and entries whose
        // details were changed with Edit); rows from Pending Reports keep own_details = 0 and
        // show their survey exactly as before.
        'own_details' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'client_id' => 'INT NULL',
        'port_id' => 'INT NULL',
        'survey_type_ids' => 'VARCHAR(255) NULL',
        'survey_completed_date' => 'DATE NULL',
        'created_by' => 'INT NULL',
        'request_id' => 'CHAR(32) NULL',           // one per Add Invoice form; stops double saves
        'manual_service' => 'TEXT NULL',            // Add / Edit Invoice "Invoice Services" (JSON: desc, rate, qty,
                                                    //  travel, launch) — kept for the PDF preview / download
    ];
    foreach ($add as $col => $def) {
        if (!isset($have[$col])) $db->exec("ALTER TABLE invoices ADD COLUMN `$col` $def");
    }
    if (!isset($have['request_id'])) {
        try { $db->exec('ALTER TABLE invoices ADD UNIQUE KEY `uq_invoices_request` (`request_id`)'); } catch (Throwable $e) { error_log('invoices request key: ' . $e->getMessage()); }
    }
    // A manual invoice need not belong to a survey: survey_id may be empty (still UNIQUE, so a
    // survey has at most one invoice entry; MySQL allows any number of NULLs in a unique key).
    $sc = $db->query("SHOW COLUMNS FROM invoices LIKE 'survey_id'")->fetch(PDO::FETCH_ASSOC);
    if ($sc && strtoupper((string)$sc['Null']) === 'NO') {
        $db->exec("ALTER TABLE invoices MODIFY `survey_id` int(11) NULL COMMENT 'surveys.id (vessel + report record); empty for a manual invoice'");
    }
}

/*
 * What a row shows for its vessel & survey details: the invoice's own copy when own_details = 1,
 * otherwise the linked survey (as before). Used by every list / filter / sort / export.
 */
const INV_E_VESSEL = 'IF(i.own_details = 1, i.vessel_name, COALESCE(s.vessel_name, i.vessel_name))';
const INV_E_REPORT = 'IF(i.own_details = 1, i.report_number, COALESCE(s.report_number, i.report_number))';
const INV_E_CLIENT = 'IF(i.own_details = 1, i.client_id, s.client_id)';
const INV_E_PORT = 'IF(i.own_details = 1, i.port_id, s.port_id)';
const INV_E_SCD = 'IF(i.own_details = 1, i.survey_completed_date, s.survey_completed_date)';
const INV_E_TYPE_IDS = 'IF(i.own_details = 1, i.survey_type_ids, s.survey_type_ids)';
const INV_E_TYPE_ID = "IF(i.own_details = 1, CAST(NULLIF(SUBSTRING_INDEX(i.survey_type_ids, ',', 1), '') AS UNSIGNED), s.survey_type_id)";

/** FROM + joins of the Invoices list (aliases i, s, c, st, p). */
function invoiceFromSql(): string {
    return 'FROM invoices i
        LEFT JOIN surveys s ON s.id = i.survey_id
        LEFT JOIN clients c ON c.id = ' . INV_E_CLIENT . '
        LEFT JOIN survey_types st ON st.id = ' . INV_E_TYPE_ID . '
        LEFT JOIN ports p ON p.id = ' . INV_E_PORT;
}

/** Survey type names shown for a row of invoiceRowSql(). */
function invoiceTypeNames(PDO $db, array $r): string {
    if (empty($r['sid']) && empty($r['own_details'])) return '';
    return (string)getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? '');
}

/** Stores a generated PDF under uploads/invoices/ and returns its relative path (null on failure). */
function invoiceStoreGenerated(int $invoiceId, string $pdf, string $tag): ?string {
    $dir = invoiceStorageDir();
    $rel = INVOICE_DIR . '/inv' . $invoiceId . '_' . $tag . '_' . bin2hex(random_bytes(10)) . '.pdf';
    if (!is_dir($dir) || @file_put_contents(dirname(__DIR__) . '/' . $rel, $pdf) !== strlen($pdf)) {
        @unlink(dirname(__DIR__) . '/' . $rel);
        return null;
    }
    return $rel;
}

/** Removes a stored invoice file when no invoice row (vessel or recovery) still points to it. */
function invoiceRemoveFileIfUnused(PDO $db, ?string $rel): void {
    if (!$rel || !($abs = invoiceFileAbs($rel))) return;
    $c = $db->prepare('SELECT COUNT(*) FROM invoices WHERE file_path = ? OR rec_file_path = ?');
    $c->execute([$rel, $rel]);
    if ((int)$c->fetchColumn() === 0) @unlink($abs);
}

/**
 * Registers the survey in Invoices once (INSERT IGNORE on the unique survey_id): a repeated
 * upload or a second call changes nothing. Returns true when a new row was added.
 */
function invoiceRegister(PDO $db, int $surveyId, string $via = 'upload'): bool {
    if ($surveyId <= 0) return false;
    invoiceEnsureTable($db);
    $st = $db->prepare("INSERT IGNORE INTO invoices (survey_id, vessel_name, report_number, invoice_status, registered_via)
        SELECT s.id, s.vessel_name, s.report_number, ?, ? FROM surveys s WHERE s.id = ?");
    $st->execute([INVOICE_DEFAULT_STATUS, $via === 'backfill' ? 'backfill' : 'upload', $surveyId]);
    return $st->rowCount() > 0;
}

/**
 * Repeatable backfill: every survey now in Pending Reports that has no invoice row yet (a
 * vessel that reached Pending Reports some other way than the document upload). Vessels
 * already Completed are not added. Survey rows are only read. Returns the number added.
 */
function invoiceBackfill(PDO $db): int {
    invoiceEnsureTable($db);
    invoiceCleanupCompletedBackfill($db);
    $st = $db->prepare("INSERT IGNORE INTO invoices (survey_id, vessel_name, report_number, invoice_status, registered_via)
        SELECT s.id, s.vessel_name, s.report_number, ?, 'backfill' FROM surveys s
        WHERE s.status = 'Pending Report'");
    $st->execute([INVOICE_DEFAULT_STATUS]);
    return $st->rowCount();
}

/**
 * Runs once: the first version of the backfill also added vessels whose survey was already
 * Completed. Those rows are taken out again, but only when nobody has worked on them — added
 * by the backfill, survey Completed on or before the row was added, still the default status,
 * no invoice number, file, recovery invoice or payment. Survey rows are only read.
 */
function invoiceCleanupCompletedBackfill(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_meta` (`k` varchar(64) NOT NULL, `v` varchar(255) DEFAULT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`k`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($db->query("SELECT COUNT(*) FROM invoice_meta WHERE k = 'cleanup_completed_backfill'")->fetchColumn()) return;
    $hasPay = (bool)$db->query("SHOW TABLES LIKE 'invoice_payments'")->fetchColumn();
    $sql = "DELETE i FROM invoices i JOIN surveys s ON s.id = i.survey_id
        WHERE i.registered_via = 'backfill' AND s.status = 'Completed'
          AND (s.report_uploaded_date IS NULL OR YEAR(s.report_uploaded_date) = 0 OR DATE(s.report_uploaded_date) <= DATE(i.created_at))
          AND i.invoice_status = " . $db->quote(INVOICE_DEFAULT_STATUS) . "
          AND (i.invoice_no IS NULL OR i.invoice_no = '') AND i.file_path IS NULL AND i.rec_file_path IS NULL"
        . ($hasPay ? " AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id)" : '');
    $n = $db->exec($sql);
    $db->prepare("INSERT IGNORE INTO invoice_meta (k, v) VALUES ('cleanup_completed_backfill', ?)")->execute([date('Y-m-d H:i:s') . ' removed ' . (int)$n]);
}

/** Absolute path of a stored invoice file, only when it is inside uploads/invoices/. */
function invoiceFileAbs(?string $rel): ?string {
    if (!$rel || !preg_match('#^uploads/invoices/[A-Za-z0-9_\-]+\.pdf$#', $rel)) return null;
    $base = realpath(dirname(__DIR__) . '/' . INVOICE_DIR);
    $abs = realpath(dirname(__DIR__) . '/' . $rel);
    return ($base && $abs && strpos($abs, $base . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}

/** uploads/invoices/ with a deny-all .htaccess (files are only served by ajax/invoices.php). */
function invoiceStorageDir(): string {
    $dir = dirname(__DIR__) . '/' . INVOICE_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nOptions -Indexes\n");
    }
    return $dir;
}

/** True when the upload is a real PDF (extension, size, MIME sniffed on the server, %PDF header). */
function invoiceValidatePdf(array $f, ?string &$err): bool {
    $err = null;
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'The file is too large.' : 'No file was received.';
        return false;
    }
    if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'pdf') { $err = 'Only PDF files can be uploaded.'; return false; }
    if ((int)$f['size'] <= 0 || (int)$f['size'] > invoiceMaxBytes()) { $err = 'The PDF must be smaller than ' . (invoiceMaxBytes() / 1048576) . ' MB.'; return false; }
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) : '';
    $head = (string)@file_get_contents($f['tmp_name'], false, null, 0, 5);
    if ($mime !== 'application/pdf' || $head !== '%PDF-') { $err = 'This file is not a valid PDF.'; return false; }
    return true;
}

/** One invoice row joined with what the list shows (vessel, client, type, port, dates). */
function invoiceRowSql(): string {
    return 'SELECT i.*, s.id AS sid, ' . INV_E_VESSEL . ' AS s_vessel_name, ' . INV_E_REPORT . ' AS s_report_number, s.agent_name,
               ' . INV_E_TYPE_IDS . ' AS survey_type_ids, ' . INV_E_SCD . ' AS survey_completed_date, s.appointment_date, s.report_uploaded_date, s.status AS survey_status,
               c.company_name, st.type_name, p.port_name, ' . INV_E_CLIENT . ' AS eff_client_id, ' . INV_E_PORT . ' AS eff_port_id, ' . INV_E_TYPE_ID . ' AS survey_type_id
        ' . invoiceFromSql();
}

/** The row as JSON for the page (what the row's cells and buttons need). */
function invoiceRowJson(PDO $db, array $r): array {
    $done = !empty($r['survey_completed_date']) && strpos((string)$r['survey_completed_date'], '0000') !== 0;
    return [
        'id' => (int)$r['id'],
        'status' => (string)$r['invoice_status'],
        'has_file' => invoiceFileAbs($r['file_path'] ?? null) !== null,
        'has_rec' => invoiceFileAbs($r['rec_file_path'] ?? null) !== null,
        'rec_no' => invoiceRecNo($r),
        'rec_no_custom' => trim((string)($r['rec_invoice_no'] ?? '')) !== '',
        'rec_date' => (string)($r['rec_invoice_date'] ?? ''),
        'rec_amount' => isset($r['rec_invoice_amount']) && $r['rec_invoice_amount'] !== null ? (string)$r['rec_invoice_amount'] : '',
        // a recovery invoice was added (Add / Edit Invoice) or made (Generate Invoice)
        'has_rec_entry' => invoiceFileAbs($r['rec_file_path'] ?? null) !== null || !empty($r['rec_invoice_date']) || trim((string)($r['rec_invoice_no'] ?? '')) !== '',
        'rec_file_name' => (string)($r['rec_file_name'] ?? ''),
        'recovery_pending' => !empty($r['recovery_pending']),
        'generated' => !empty($r['gen_data']),
        'file_name' => (string)($r['file_name'] ?? ''),
        'uploaded_at' => !empty($r['uploaded_at']) ? date('d M Y H:i', strtotime($r['uploaded_at'])) : '',
        'invoice_no' => (string)($r['invoice_no'] ?? ''),
        'invoice_date' => (string)($r['invoice_date'] ?? ''),
        'invoice_amount' => $r['invoice_amount'] !== null ? (string)$r['invoice_amount'] : '',
        'invoice_notes' => (string)($r['invoice_notes'] ?? ''),
        'manual_service' => invoiceManualService($r['manual_service'] ?? null),
        'survey_completed' => $done ? date('d M Y', strtotime($r['survey_completed_date'])) : '',
        'deleted' => !empty($r['deleted_at']),
        'deleted_at' => !empty($r['deleted_at']) ? date('d M Y H:i', strtotime($r['deleted_at'])) : '',
        'deleted_by' => !empty($r['deleted_at']) ? invoiceUserName($db, (int)($r['deleted_by'] ?? 0)) : '',
        'delete_reason' => (string)($r['delete_reason'] ?? ''),
        'can_remove' => invoiceRemoveBlocker($r) === '',
    ];
}

/** Invoice Services of a manual entry (stored JSON → [desc, rate, qty, travel, launch] as strings). */
function invoiceManualService($json): array {
    $d = is_string($json) && $json !== '' ? json_decode($json, true) : null;
    $out = [];
    foreach (['desc', 'rate', 'qty', 'travel', 'launch'] as $k) $out[$k] = is_array($d) && isset($d[$k]) && is_scalar($d[$k]) ? (string)$d[$k] : '';
    return $out;
}

/** Invoice Services from the Add / Edit form ($_POST service_*) → JSON, or null when all are empty. */
function invoiceManualServiceFromPost(): ?string {
    $num = function (string $k): string {
        $v = str_replace([',', ' ', '$'], '', trim((string)($_POST[$k] ?? '')));
        return $v !== '' && is_numeric($v) && (float)$v >= 0 ? (string)$v : '';
    };
    $d = [
        'desc' => mb_substr(trim((string)($_POST['service_desc'] ?? '')), 0, 1000),
        'rate' => $num('service_rate'), 'qty' => $num('service_qty'),
        'travel' => $num('travel_cost'), 'launch' => $num('launch_cost'),
    ];
    if ($d['desc'] === '' && $d['rate'] === '' && $d['travel'] === '' && $d['launch'] === '') return null; // quantity alone (default 1) is not a service
    return json_encode($d, JSON_UNESCAPED_UNICODE);
}

function invoiceUserName(PDO $db, int $uid): string {
    if ($uid <= 0) return '';
    $q = $db->prepare('SELECT full_name FROM users WHERE id = ?');
    $q->execute([$uid]);
    return (string)$q->fetchColumn();
}

/**
 * Why this entry cannot be deleted from Invoices ('' = it can): any entry in a pending status
 * can be deleted, unless an invoice PDF is stored for it (vessel or recovery — delete the PDF
 * first). Payments are checked by the delete action itself. An invoice number already given
 * stays with the entry (it comes back with Restore; numbers are not given out again).
 */
function invoiceRemoveBlocker(array $r): string {
    if (!empty($r['deleted_at'])) return 'This entry is already deleted.';
    if (($r['invoice_status'] ?? '') === 'Completed') return 'A completed invoice cannot be deleted.';
    if (!empty($r['file_path']) || !empty($r['rec_file_path'])) return 'An invoice PDF is stored for this entry — delete the PDF first, then the entry.';
    return '';
}

/** One Invoices grid row: invoiceRowJson() plus the display columns. */
function invoiceGridRow(PDO $db, array $r): array {
    $j = invoiceRowJson($db, $r);
    $j['vessel'] = (string)($r['s_vessel_name'] ?? $r['vessel_name'] ?? '');
    $j['report_no'] = (string)($r['s_report_number'] ?? $r['report_number'] ?? '');
    $j['client'] = (string)($r['company_name'] ?? '');
    $j['type'] = invoiceTypeNames($db, $r);
    $j['port'] = (string)($r['port_name'] ?? '');
    $j['survey_deleted'] = !empty($r['survey_id']) && empty($r['sid']);
    $j['manual'] = ($r['registered_via'] ?? '') === 'manual';
    $j['linked'] = !empty($r['sid']);
    $j['client_id'] = (int)($r['eff_client_id'] ?? 0) ?: null;
    $j['port_id'] = (int)($r['eff_port_id'] ?? 0) ?: null;
    $j['type_ids'] = array_values(array_filter(array_map('intval', explode(',', str_replace(' ', '', (string)($r['survey_type_ids'] ?? ''))))))
        ?: (!empty($r['survey_type_id']) ? [(int)$r['survey_type_id']] : []);
    $j['survey_completed_ymd'] = !empty($r['survey_completed_date']) && strpos((string)$r['survey_completed_date'], '0000') !== 0 ? substr((string)$r['survey_completed_date'], 0, 10) : '';
    $j['amount'] = $r['invoice_amount'] !== null ? round((float)$r['invoice_amount'], 2) : null;
    return $j;
}

/** Bootstrap badge colour for a status. */
function invoiceStatusClass(string $s): string {
    return ['Pending for CP Prices' => 'cp', 'Pending for Cost' => 'cost', 'Pending for Other Reason' => 'other', 'Completed' => 'done'][$s] ?? 'other';
}

/**
 * Invoices list filters from the query string (invoices.php and export_invoices.php):
 *   view   — 'pending' (default: every status except Completed), 'completed', 'all' or 'deleted'
 *            (entries deleted from Invoices; every other view leaves them out)
 *   status — one pending status (pending view only), q, type, month (survey completed, YYYY-MM)
 * Returns [state, whereSql, params]; the SQL uses the aliases of invoiceRowSql().
 */
function invoiceListFilters(array $g): array {
    $st = [
        'view' => in_array($g['view'] ?? '', ['completed', 'all', 'deleted'], true) ? (string)$g['view'] : 'pending',
        'q' => mb_substr(trim((string)($g['q'] ?? '')), 0, 100),
        'status' => '',
        'type' => mb_substr(trim((string)($g['type'] ?? '')), 0, 100),
        'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($g['month'] ?? '')) ? (string)$g['month'] : '',
        'client' => max(0, (int)($g['client'] ?? 0)),
        'port' => max(0, (int)($g['port'] ?? 0)),
        'vessel' => mb_substr(trim((string)($g['vessel'] ?? '')), 0, 100),
        'scd_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['scd_from'] ?? '')) ? (string)$g['scd_from'] : '',
        'scd_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['scd_to'] ?? '')) ? (string)$g['scd_to'] : '',
        'ids' => [],
    ];
    foreach (explode(',', (string)($g['ids'] ?? '')) as $x) if (ctype_digit(trim($x))) $st['ids'][] = (int)trim($x);
    $st['ids'] = array_slice(array_values(array_unique($st['ids'])), 0, 5000);
    if ($st['view'] === 'pending' && in_array($g['status'] ?? '', INVOICE_STATUSES, true) && $g['status'] !== 'Completed') $st['status'] = (string)$g['status'];
    $where = [$st['view'] === 'completed' ? "i.invoice_status = 'Completed'" : (in_array($st['view'], ['all', 'deleted'], true) ? '1=1' : "i.invoice_status <> 'Completed'"),
        $st['view'] === 'deleted' ? 'i.deleted_at IS NOT NULL' : 'i.deleted_at IS NULL'];
    $params = [];
    if ($st['q'] !== '') {
        $like = '%' . addcslashes($st['q'], '%_\\') . '%';
        $where[] = '(' . INV_E_VESSEL . ' LIKE ? OR c.company_name LIKE ? OR p.port_name LIKE ? OR ' . INV_E_REPORT . ' LIKE ? OR i.invoice_no LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($st['status'] !== '') { $where[] = 'i.invoice_status = ?'; $params[] = $st['status']; }
    if ($st['type'] !== '') {
        $params[] = mb_strtolower($st['type']);
        $where[] = "EXISTS (SELECT 1 FROM survey_types ltf WHERE LOWER(ltf.type_name) = ?
            AND FIND_IN_SET(ltf.id, REPLACE(COALESCE(NULLIF(" . INV_E_TYPE_IDS . ", ''), " . INV_E_TYPE_ID . "), ' ', '')))";
    }
    if ($st['month'] !== '') {
        $where[] = INV_E_SCD . ' >= ? AND ' . INV_E_SCD . ' < ?';
        $params[] = $st['month'] . '-01';
        $params[] = date('Y-m-d', strtotime($st['month'] . '-01 +1 month'));
    }
    if ($st['client'] > 0) { $where[] = INV_E_CLIENT . ' = ?'; $params[] = $st['client']; }
    if ($st['port'] > 0) { $where[] = INV_E_PORT . ' = ?'; $params[] = $st['port']; }
    if ($st['vessel'] !== '') { $where[] = INV_E_VESSEL . ' LIKE ?'; $params[] = '%' . addcslashes($st['vessel'], '%_\\') . '%'; }
    if ($st['scd_from'] !== '') { $where[] = INV_E_SCD . ' >= ?'; $params[] = $st['scd_from']; }
    if ($st['scd_to'] !== '') { $where[] = INV_E_SCD . ' < ?'; $params[] = date('Y-m-d', strtotime($st['scd_to'] . ' +1 day')); }
    if ($st['ids']) { $where[] = 'i.id IN (' . implode(',', $st['ids']) . ')'; }
    return [$st, implode(' AND ', $where), $params];
}

/** The filters without view / status (for the per-status counters). */
function invoiceListFiltersNoStatus(array $g): array {
    $g['view'] = 'pending'; $g['status'] = '';
    [$st, $where, $params] = invoiceListFilters($g);
    // drop the leading view condition ("i.invoice_status <> 'Completed'")
    $where = preg_replace("/^i\.invoice_status <> 'Completed'( AND )?/", '', $where);
    return [$st, $where !== '' ? $where : '1=1', $params];
}

/** Sortable columns of the Invoices grid → SQL (aliases of invoiceRowSql()). */
const INVOICE_LIST_SORTS = [
    'invoice_no' => "CAST(SUBSTRING_INDEX(i.invoice_no, '-', -1) AS UNSIGNED)",
    'vessel' => INV_E_VESSEL,
    'report_no' => INV_E_REPORT,
    'client' => 'c.company_name',
    'type' => 'st.type_name',
    'port' => 'p.port_name',
    'survey_completed' => '(CASE WHEN YEAR(' . INV_E_SCD . ') > 0 THEN ' . INV_E_SCD . ' END)',
    'status' => "FIELD(i.invoice_status, 'Pending for CP Prices', 'Pending for Cost', 'Pending for Other Reason', 'Completed')",
    'invoice_date' => 'i.invoice_date',
    'amount' => 'i.invoice_amount',
];

/** ORDER BY from [{f, d}] (up to 3 levels, empty values last); falls back to the view's default order. */
function invoiceGridOrderSql($sorts, string $view): string {
    $parts = [];
    foreach (array_slice(is_array($sorts) ? $sorts : [], 0, 3) as $x) {
        $f = is_array($x) ? (string)($x['f'] ?? '') : '';
        if (!isset(INVOICE_LIST_SORTS[$f])) continue;
        $e = INVOICE_LIST_SORTS[$f];
        $dir = strtolower((string)($x['d'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $parts[] = "($e IS NULL) ASC, $e $dir";
        if ($f === 'invoice_no') $parts[] = "i.invoice_no $dir";
    }
    return $parts ? implode(', ', $parts) . ', i.id DESC' : invoiceListOrderSql($view);
}

/**
 * ORDER BY for the list. Pending: newest Survey Completed Date first, vessels without one last.
 * Completed: by invoice number (the running number at the end of YMR-<code>-<n>), invoices
 * without a number last.
 */
function invoiceListOrderSql(string $view = 'pending'): string {
    if ($view === 'completed') {
        return "(i.invoice_no IS NULL OR i.invoice_no = '') ASC, CAST(SUBSTRING_INDEX(i.invoice_no, '-', -1) AS UNSIGNED) ASC, i.invoice_no ASC, i.id ASC";
    }
    $scd = '(CASE WHEN YEAR(' . INV_E_SCD . ') > 0 THEN ' . INV_E_SCD . ' END)';
    return "($scd IS NULL) ASC, $scd DESC, i.id DESC";
}

/* ---------- Invoice numbers: YMR-<client short code>-<n>, n from 963, +1 per invoice ----------
   A vessel's number is fixed the first time its invoice is downloaded (preview never uses a
   number up) and kept on its invoices row; generating again reuses it. Its Recovery Invoice
   is the same number with "B" at the end. Client short codes are the ones the report
   numbers already use (clients.short_code, includes/report_number.php).
   The series was moved from 1000 to 963: the counter is set back to 963 once (row id 2 of
   invoice_number_seq records that this was done) and a number another invoice already
   carries (e.g. 1000 given before the change) is skipped, so no number is issued twice. */
const INVOICE_FIRST_NUMBER = 963;

function invoiceEnsureSequence(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_number_seq` (
        `id` tinyint NOT NULL, `next_no` int NOT NULL, PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec('INSERT IGNORE INTO invoice_number_seq (id, next_no) VALUES (1, ' . INVOICE_FIRST_NUMBER . ')');
    // one-time restart of the series at 963 (only the first time this code runs)
    if ($db->exec('INSERT IGNORE INTO invoice_number_seq (id, next_no) VALUES (2, ' . INVOICE_FIRST_NUMBER . ')') === 1) {
        $db->exec('UPDATE invoice_number_seq SET next_no = ' . INVOICE_FIRST_NUMBER . ' WHERE id = 1');
    }
    $done = true;
}

/** $n, or the first number after it that no invoice carries yet. */
function invoiceFirstFreeNumber(PDO $db, int $n): int {
    $q = $db->prepare("SELECT 1 FROM invoices WHERE invoice_no LIKE ? OR invoice_no LIKE ? LIMIT 1");
    for ($i = 0; $i < 100000; $i++, $n++) {
        $q->execute(['%-' . $n, '%-' . $n . 'B']);
        if (!$q->fetchColumn()) return $n;
    }
    return $n;
}

/** The client's short code for invoice numbers (e.g. BB for Bainbridge). */
function invoiceClientCode(PDO $db, int $surveyId): string {
    $q = $db->prepare('SELECT client_id FROM surveys WHERE id = ?');
    $q->execute([$surveyId]);
    return invoiceCodeForClient($db, (int)$q->fetchColumn());
}

/** Short code of a client (XX when there is none). */
function invoiceCodeForClient(PDO $db, int $clientId): string {
    require_once __DIR__ . '/report_number.php';
    return $clientId > 0 ? preg_replace('/[^A-Z0-9]/', '', strtoupper(ensureClientHasShortCode($db, $clientId))) ?: 'XX' : 'XX';
}

/** The client an invoice row is for (its own client for manual / edited entries, else its survey's). */
function invoiceClientIdOf(PDO $db, int $invoiceId): int {
    $q = $db->prepare('SELECT ' . INV_E_CLIENT . ' FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id WHERE i.id = ?');
    $q->execute([$invoiceId]);
    return (int)$q->fetchColumn();
}

/** The next number the series would give now (not reserved): the counter, skipping numbers in use. */
function invoiceNextNumber(PDO $db): int {
    invoiceEnsureSequence($db);
    $n = (int)$db->query('SELECT next_no FROM invoice_number_seq WHERE id = 1')->fetchColumn();
    return invoiceFirstFreeNumber($db, max(INVOICE_FIRST_NUMBER, $n));
}

/** The number the vessel's invoice has, or the one it would get now (not reserved). */
function invoicePeekNumber(PDO $db, array $inv): string {
    if (trim((string)($inv['invoice_no'] ?? '')) !== '') return (string)$inv['invoice_no'];
    $cid = (int)($inv['eff_client_id'] ?? 0);
    $code = $cid > 0 ? invoiceCodeForClient($db, $cid) : invoiceClientCode($db, (int)($inv['survey_id'] ?? 0));
    return 'YMR-' . $code . '-' . invoiceNextNumber($db);
}

/**
 * A typed invoice number, cleaned: upper case, no spaces; digits only → YMR-<code>-<digits>.
 * Returns [number, null] or [null, error]. Same rule as the Generate Invoice number fix.
 */
function invoiceNormalizeNumber(string $typed, string $code): array {
    $want = strtoupper(preg_replace('/\s+/', '', $typed));
    if ($want === '') return [null, 'Enter the invoice number.'];
    if (ctype_digit($want)) $want = 'YMR-' . $code . '-' . ltrim($want, '0');
    if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/]{0,58}[0-9]$/', $want)) {
        return [null, 'Invoice number: letters, digits and - only, ending in its number (e.g. YMR-' . $code . '-968).'];
    }
    return [$want, null];
}

/** The running number at the end of an invoice number ("YMR-BB-968" → 968), or null. */
function invoiceNumberPart(string $no): ?int {
    return preg_match('/-([0-9]{1,9})$/', $no, $m) ? (int)$m[1] : null;
}

/**
 * The invoice number another entry (deleted ones too — numbers are never reused) already has
 * that clashes with $no: the same number, or the same running number under any client code
 * (also its recovery "B" invoice). null when $no is free.
 */
function invoiceNumberTakenBy(PDO $db, string $no, int $exceptId = 0): ?string {
    $n = invoiceNumberPart($no);
    $sql = 'SELECT invoice_no FROM invoices WHERE id <> ? AND (invoice_no = ?' . ($n !== null ? ' OR invoice_no LIKE ? OR invoice_no LIKE ?' : '') . ') LIMIT 1';
    $q = $db->prepare($sql);
    $q->execute($n !== null ? [$exceptId, $no, '%-' . $n, '%-' . $n . 'B'] : [$exceptId, $no]);
    $o = $q->fetchColumn();
    if ($o !== false) return (string)$o;
    // another entry's recovery invoice typed with its own number
    $q = $db->prepare('SELECT rec_invoice_no FROM invoices WHERE id <> ? AND rec_invoice_no = ? LIMIT 1');
    $q->execute([$exceptId, $no]);
    $o = $q->fetchColumn();
    return $o === false ? null : (string)$o;
}

/** The recovery invoice's number: the one typed for it, else the invoice number + "B" ('' when neither). */
function invoiceRecNo(array $r): string {
    $own = trim((string)($r['rec_invoice_no'] ?? ''));
    if ($own !== '') return $own;
    $no = trim((string)($r['invoice_no'] ?? ''));
    return $no !== '' ? $no . 'B' : '';
}

/**
 * A typed recovery invoice number, cleaned (upper case, no spaces): [number, null] or [null, error].
 * Letters, digits, - and / only (e.g. YMR-BB-968B).
 */
function invoiceNormalizeRecNumber(string $typed): array {
    $want = strtoupper(preg_replace('/\s+/', '', $typed));
    if ($want === '') return [null, 'Enter the recovery invoice number.'];
    if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/]{0,62}[A-Z0-9]$/', $want)) {
        return [null, 'Recovery invoice number: letters, digits and - only (e.g. YMR-BB-968B).'];
    }
    return [$want, null];
}

/**
 * The entry whose invoice or recovery invoice already carries $recNo (deleted entries too), or null.
 * The entry's own vessel invoice number ($ownNo) is never allowed as its recovery number.
 */
function invoiceRecNumberTakenBy(PDO $db, string $recNo, int $exceptId, string $ownNo): ?string {
    if ($ownNo !== '' && strcasecmp($recNo, $ownNo) === 0) return $ownNo;
    $q = $db->prepare("SELECT COALESCE(NULLIF(invoice_no, ''), rec_invoice_no) FROM invoices WHERE id <> ?
        AND (invoice_no = ? OR rec_invoice_no = ? OR (rec_invoice_no IS NULL AND CONCAT(invoice_no, 'B') = ?)) LIMIT 1");
    $q->execute([$exceptId, $recNo, $recNo, $recNo]);
    $o = $q->fetchColumn();
    return $o === false ? null : (string)$o;
}

/** Locks the number series until the transaction ends (every number written goes through it). */
function invoiceLockSequence(PDO $db): int {
    return (int)$db->query('SELECT next_no FROM invoice_number_seq WHERE id = 1 FOR UPDATE')->fetchColumn();
}

/** After a number is saved: the series continues after it when it is higher (call inside the lock). */
function invoiceAdvanceSequence(PDO $db, string $no): void {
    $n = invoiceNumberPart($no);
    if ($n !== null && $n >= INVOICE_FIRST_NUMBER) {
        $db->prepare('UPDATE invoice_number_seq SET next_no = GREATEST(next_no, ?) WHERE id = 1')->execute([$n + 1]);
    }
}

/**
 * UNIQUE key on invoice_no (extra protection against a number used twice). Added only when the
 * stored numbers are already unique — existing rows are never changed; when two rows share a
 * number the key is left out (logged) and the checks above still apply.
 */
function invoiceEnsureNumberKey(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if ($db->query("SHOW INDEX FROM invoices WHERE Key_name = 'uq_invoices_no'")->fetch()) return;
    $dup = $db->query('SELECT invoice_no FROM invoices WHERE invoice_no IS NOT NULL GROUP BY invoice_no HAVING COUNT(*) > 1 LIMIT 1')->fetchColumn();
    if ($dup !== false) { error_log('invoices: invoice_no unique key not added — a number is stored twice'); return; }
    try { $db->exec('ALTER TABLE invoices ADD UNIQUE KEY `uq_invoices_no` (`invoice_no`)'); }
    catch (Throwable $e) { error_log('invoices: invoice_no unique key: ' . $e->getMessage()); }
}

/** Gives the invoice its number if it has none yet (one transaction) and returns it. */
function invoiceAllocateNumber(PDO $db, int $invoiceId): string {
    invoiceEnsureSequence($db);
    $code = invoiceCodeForClient($db, invoiceClientIdOf($db, $invoiceId)); // before the transaction (may save a missing client code)
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT invoice_no FROM invoices WHERE id = ? FOR UPDATE');
        $q->execute([$invoiceId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Invoice not found.');
        if (trim((string)$row['invoice_no']) !== '') { $db->commit(); return (string)$row['invoice_no']; }
        $n = invoiceFirstFreeNumber($db, max(INVOICE_FIRST_NUMBER, (int)$db->query('SELECT next_no FROM invoice_number_seq WHERE id = 1 FOR UPDATE')->fetchColumn()));
        $no = 'YMR-' . $code . '-' . $n;
        $db->prepare('UPDATE invoices SET invoice_no = ? WHERE id = ?')->execute([$no, $invoiceId]);
        $db->prepare('UPDATE invoice_number_seq SET next_no = ? WHERE id = 1')->execute([$n + 1]);
        $db->commit();
        return $no;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
