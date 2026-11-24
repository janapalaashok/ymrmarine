<?php
/**
 * Survey report without signing in: the API of survey_report_public.php (rules:
 * includes/survey_report_guest.php). The report is named by its secret token, sent as `id`, so
 * the editor (assets/js/survey-report.js) works unchanged. Only these actions exist here:
 *
 *   GET  a=token                     the current CSRF token
 *   POST a=new        kind           a new report → its token
 *   GET  a=starter&kind=<kind>       the form a new report of that type starts with (nothing stored) — kept on
 *                                    the device so a report can be started there without internet
 *   GET  a=get&id=<token>            data, version, form config, its PDF files
 *   GET  a=version | verify | excel_view | preview &id=<token>
 *   POST a=save | generate  id, ...  as ajax/survey_reports.php (generate: Excel kept in the system, the PDF for the guest)
 *   POST a=lookup     id, imo        vessel particulars by IMO
 *   POST a=import_tanks id, file     tank rows from an uploaded workbook (nothing stored)
 *   GET  a=file&id=<file id>&t=<token>   a PDF of this report
 *
 * Never here: other reports, vessels, earlier attendances, tank history, template files, Excel downloads.
 */
ob_start(); // a stray warning / notice must never break the JSON answer
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/survey_report_guest.php';

function srgJson(int $code, array $d): void {
    $stray = '';
    while (ob_get_level() > 0) $stray .= (string)ob_get_clean();
    if (trim($stray) !== '') error_log('ajax/survey_report_public stray output: ' . mb_substr(trim(strip_tags($stray)), 0, 300));
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($d);
    exit;
}
function srgLimited(): void { srgJson(429, ['ok' => false, 'error' => 'Too many requests from this connection — please try again in a while.']); }

csrf_require();
$a = (string)($_REQUEST['a'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
@set_time_limit(180);
@ini_set('memory_limit', '512M');

try {
    if ($a === 'token') srgJson(200, ['ok' => true, 'csrf' => csrf_token()]);
    $db = getDB();
    srgEnsure($db);
    srEnsureHistory($db);

    if ($a === 'new') {
        if (!$isPost) srgJson(405, ['ok' => false, 'error' => 'POST only.']);
        if (!srgHit($db, 'new')) srgLimited();
        $res = srgNew($db, (string)($_POST['kind'] ?? ''), (int)($_SESSION['user_id'] ?? 0)); // signed in: the report is theirs too
        srgJson($res['ok'] ? 200 : 422, $res);
    }
    if ($a === 'starter') {
        $kind = (string)($_GET['kind'] ?? '');
        $t = isset(RT_KINDS[$kind]) ? rtActive($db, $kind) : null;
        if (!$t) srgJson(404, ['ok' => false, 'error' => 'This report type is not available.']);
        $data = srDefaults(rtEffective($t), null);
        if (!empty($_SESSION['user_id']) && isset($data['fields']['surveyor_name']) && $data['fields']['surveyor_name'] === '') $data['fields']['surveyor_name'] = strtoupper(trim((string)($_SESSION['full_name'] ?? '')));
        srgJson(200, ['ok' => true, 'report' => ['id' => '', 'version' => 0, 'data' => $data, 'updated_at' => null, 'updated_by' => null, 'vessel' => 'New ' . (RT_KINDS[$kind] ?? $kind) . ' report',
            'survey_id' => 0, 'status' => '', 'kind' => $kind, 'kind_label' => RT_KINDS[$kind] ?? $kind],
            'is_admin' => false, 'guest' => true, 'can_edit' => true, 'locked' => false, 'config' => srEditorConfig($t), 'files' => [], 'newer_template' => null]);
    }
    if ($a === 'file') {
        $r = srgReport($db, srgToken($_GET['t'] ?? ''));
        $q = $db->prepare("SELECT * FROM survey_report_files WHERE id = ? AND type = 'pdf'");
        $q->execute([(int)($_GET['id'] ?? 0)]);
        $f = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$f || (int)$f['report_id'] !== $r['id'] || !($abs = rtAbs($f['file_path']))) srgJson(404, ['ok' => false, 'error' => 'File not found.']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (!empty($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $f['file_name']) . '"');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        srgLogDownload($db, $r['id'], (int)$f['id']); // the Admin sees every download
        while (ob_get_level() > 0) ob_end_clean();
        readfile($abs);
        exit;
    }

    $token = srgToken($_REQUEST['id'] ?? '');
    $r = srgReport($db, $token);
    if (!$r) srgJson(404, ['ok' => false, 'error' => 'Report not found. Check the link.']);
    $t = rtTemplate($db, $r['template_id']);
    if (!$t) srgJson(404, ['ok' => false, 'error' => 'This report type is not available any more.']);
    // only its PDFs, without who made them
    $pdfs = fn() => array_values(array_map(fn($f) => ['id' => $f['id'], 'type' => 'pdf', 'file_name' => $f['file_name'], 'file_size' => $f['file_size'],
        'created_at' => $f['created_at'], 'template_id' => $f['template_id'], 'template_version' => $f['template_version'], 'created_by_name' => null],
        array_filter(srFiles($db, $r['id']), fn($f) => $f['type'] === 'pdf')));

    if ($a === 'get') {
        srgJson(200, ['ok' => true, 'report' => ['id' => $token, 'version' => $r['version'], 'data' => $r['data'], 'updated_at' => $r['updated_at'], 'updated_by' => null,
            'vessel' => srTitle($r), 'survey_id' => 0, 'status' => '', 'kind' => $r['kind'], 'kind_label' => RT_KINDS[$r['kind']] ?? $r['kind']],
            'is_admin' => false, 'guest' => true, 'can_edit' => !$r['locked'], 'locked' => $r['locked'], 'config' => srEditorConfig($t), 'files' => $pdfs(), 'newer_template' => null]);
    }
    if ($a === 'version') srgJson(200, ['ok' => true, 'version' => $r['version']]);
    if ($a === 'verify') srgJson(200, ['ok' => true] + srVerify($t, $r['data']));
    if ($a === 'excel_view' || $a === 'preview') {
        if (!rtAbs($t['file_path'])) srgJson(404, ['ok' => false, 'error' => 'Report not found.']);
        if (!srgHit($db, 'preview')) srgLimited();
        if ($a === 'excel_view') srgJson(200, ['ok' => true, 'version' => $r['version'], 'html' => srExcelViewHtml($t, $r)]);
        $p = srPreviewPdf($t, $r);
        if (!$p['ok']) srgJson(422, ['ok' => false, 'error' => 'The preview could not be made: ' . $p['error']]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="preview.pdf"');
        header('Content-Length: ' . filesize($p['pdf']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Report-Version: ' . $r['version']);
        while (ob_get_level() > 0) ob_end_clean();
        readfile($p['pdf']);
        exit;
    }
    if ($a === 'previous') srgJson(200, ['ok' => true, 'previous' => null]); // earlier attendances are never shown here
    if (!$isPost) srgJson(400, ['ok' => false, 'error' => 'Unknown action.']);
    if ($r['locked']) srgJson(423, ['ok' => false, 'locked' => true, 'error' => 'This report is locked by the Admin (read only). Ask the Admin for edit access.']);
    $eff = rtEffective($t);

    if ($a === 'save' || $a === 'generate') {
        if (!srgHit($db, $a === 'save' ? 'save' : 'generate')) srgLimited();
        $errors = [];
        if (isset($_POST['data'])) {
            if (strlen((string)$_POST['data']) > SRG_MAX_DATA) srgJson(413, ['ok' => false, 'error' => 'The report is too large.']);
            $in = json_decode((string)$_POST['data'], true);
            if (!is_array($in)) srgJson(422, ['ok' => false, 'error' => 'Bad data.']);
            if ((int)($_POST['version'] ?? 0) !== $r['version']) {
                srgJson(409, ['ok' => false, 'conflict' => true, 'error' => 'This report was saved meanwhile (another tab or device).', 'server' => ['version' => $r['version'], 'data' => $r['data']]]);
            }
            [$clean, $errors] = rtCleanData($eff, $in);
            $store = $clean;
            foreach ($errors as $k => $msg) {
                if (strpos($k, 'f:') === 0) { $fk = substr($k, 2); $store['fields'][$fk] = (string)(is_array($in['fields'][$fk] ?? null) ? ($in['fields'][$fk]['v'] ?? '') : ($in['fields'][$fk] ?? '')); }
            }
            $errors = array_filter($errors, fn($k) => strpos($k, 't:') !== 0, ARRAY_FILTER_USE_KEY);
            srCleanExtra($eff, $in, $store, $errors);
            $ver = $r['version'] + 1;
            $db->beginTransaction();
            try {
                $lock = $db->prepare('SELECT version FROM survey_reports WHERE id = ? FOR UPDATE');
                $lock->execute([$r['id']]);
                if ((int)$lock->fetchColumn() !== $r['version']) {
                    $db->rollBack();
                    srgJson(409, ['ok' => false, 'conflict' => true, 'error' => 'This report was saved meanwhile — reload it.']);
                }
                $db->prepare('UPDATE survey_reports SET data = ?, version = ?, updated_by = NULL, updated_at = ? WHERE id = ? AND version = ?')
                   ->execute([json_encode($store), $ver, date('Y-m-d H:i:s'), $r['id'], $r['version']]);
                srTankVersionsApply($db, $r, $store, false, 0);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            $r['version'] = $ver; $r['data'] = $store;
        }
        if ($a === 'save') srgJson(200, ['ok' => true, 'version' => $r['version'], 'errors' => (object)$errors, 'tank_versions' => 0]);
        $g = srGenerate($db, $r, true, 0); // the guest always gets the PDF; the Excel stays in the system
        if (!$g['ok']) srgJson(422, ['ok' => false, 'error' => $g['error'], 'issues' => $g['issues'] ?? [], 'version' => $r['version']]);
        $db->beginTransaction();
        try {
            $db->prepare('SELECT id FROM survey_reports WHERE id = ? FOR UPDATE')->execute([$r['id']]);
            srTankVersionsApply($db, $r, $r['data'], true, 0);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $new = isset($g['file_ids']['pdf']) ? ['pdf' => $g['file_ids']['pdf']] : [];
        srgJson(200, ['ok' => true, 'version' => $r['version'], 'files' => $pdfs(), 'new_files' => $new, 'results' => $g['results'], 'pdf_error' => $g['pdf_error']]);
    }
    if ($a === 'import_tanks') {
        if (!srgHit($db, 'import')) srgLimited();
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) srgJson(422, ['ok' => false, 'error' => 'Choose an Excel file (.xlsx / .xls / .csv).']);
        if ((int)$file['size'] > 10 * 1024 * 1024 || !preg_match('/\.(xlsx|xlsm|xls|csv)$/i', (string)$file['name'])) srgJson(422, ['ok' => false, 'error' => 'Use an .xlsx, .xls or .csv file under 10 MB.']);
        $tmp = sys_get_temp_dir() . '/ysms_imp_' . bin2hex(random_bytes(6)) . '.' . strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!move_uploaded_file($file['tmp_name'], $tmp)) srgJson(422, ['ok' => false, 'error' => 'Upload failed.']);
        try { $res = srImportTanks($tmp, $eff, (array)($r['data']['grades'] ?? [])); } finally { @unlink($tmp); }
        srgJson($res['ok'] ? 200 : 422, $res);
    }
    if ($a === 'lookup') {
        $last = (int)($_SESSION['sr_lookup_at'] ?? 0);
        if (time() - $last < 5) srgJson(429, ['ok' => false, 'error' => 'Please wait a few seconds before the next lookup.']);
        if (!srgHit($db, 'lookup')) srgLimited();
        $_SESSION['sr_lookup_at'] = time();
        $types = array_map(fn($f) => $f['type'], $eff['fields']);
        $res = srLookupFast($db, (string)($_POST['imo'] ?? ''), $types, (int)$r['id']);
        srgJson($res['ok'] ? 200 : 422, $res);
    }
} catch (Throwable $e) {
    error_log('ajax/survey_report_public ' . $a . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    srgJson(500, ['ok' => false, 'error' => 'Something went wrong. Please try again.']);
}
srgJson(400, ['ok' => false, 'error' => 'Unknown action.']);
