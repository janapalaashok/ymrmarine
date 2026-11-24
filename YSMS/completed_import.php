<?php
/**
 * Bulk import of completed vessels from Excel (Admin / Super Admin).
 * Step 1 upload → Step 2 preview (nothing saved) → Step 3 import → result.
 */
require_once 'config/config.php';
require_once __DIR__ . '/../includes/upload_validation.php';
require_once 'includes/completed_import_helpers.php';
checkAuth();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin'], true)) {
    header('Location: completed.php');
    exit;
}
@set_time_limit(300);
@ini_set('memory_limit', '256M');

$db = getDB();
$actorName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');
$step = 'upload';
$error = '';
$preview = null;   // ['token','file','rows'=>validated rows]
$result = null;    // ['file','inserted'=>[],'skipped'=>n,'failed'=>[]]

function ciLoadLibrary(): bool {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    return class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');
}

function ciValidateAll(PDO $db, array $rawRows): array {
    completedImportPrime($db, $rawRows);
    $seen = [];
    $out = [];
    foreach ($rawRows as $r) $out[] = completedImportValidateRow($db, $r, $seen);
    return $out;
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'The file is too large to upload.';
} elseif ($action === 'preview') {
    $file = $_FILES['excel_file'] ?? null;
    $name = basename((string)($file['name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose an Excel file to upload.';
    } elseif (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        $error = 'Only .xlsx, .xls or .csv files are allowed.';
    } elseif (($verr = upload_validate($file, [], 10 * 1024 * 1024)) !== '') {
        $error = $verr;
    } elseif (!ciLoadLibrary()) {
        $error = 'Excel library is not available on the server.';
    } else {
        try {
            $spreadsheet = bulkAssignLoadSpreadsheet($file['tmp_name'], $ext);
            $read = completedImportReadRows($spreadsheet);
            if (!$read['header_found']) {
                $error = 'No header row found. The sheet needs a "Vessel Name" column header (download the Completed list to see the expected layout).';
            } elseif ($read['missing']) {
                $error = 'Required column(s) missing: ' . implode(', ', $read['missing']) . '.';
            } elseif (!$read['rows']) {
                $error = 'No vessel rows found in this file.';
            } elseif (count($read['rows']) > 2000) {
                $error = 'This file has ' . count($read['rows']) . ' rows — please split it into files of 2000 rows or fewer.';
            } else {
                $token = bin2hex(random_bytes(12));
                // Only the latest preview is kept in the session.
                $_SESSION['completed_import'] = ['token' => $token, 'file' => $name, 'rows' => $read['rows'], 'at' => time()];
                $preview = ['token' => $token, 'file' => $name, 'rows' => ciValidateAll($db, $read['rows'])];
                $step = 'preview';
            }
        } catch (Throwable $e) {
            error_log('completed_import preview: ' . $e->getMessage());
            $error = 'Could not read this file. Please check that it is a valid Excel file.';
        }
    }
} elseif ($action === 'import') {
    $sess = $_SESSION['completed_import'] ?? null;
    if (!$sess || !hash_equals((string)$sess['token'], (string)($_POST['token'] ?? ''))) {
        $error = 'This preview has expired or was already imported. Please upload the file again.';
    } elseif (!ciLoadLibrary()) { // date-serial parsing needs PhpSpreadsheet
        $error = 'Excel library is not available on the server.';
    } else {
        unset($_SESSION['completed_import']); // one-shot: a refresh / double-click can't import twice
        $validated = ciValidateAll($db, $sess['rows']); // re-check against the live data
        $result = ['file' => $sess['file'], 'inserted' => [], 'skipped' => 0, 'failed' => []];
        foreach ($validated as $v) {
            if ($v['status'] === 'duplicate') { $result['skipped']++; continue; }
            if ($v['status'] !== 'ready') {
                $result['failed'][] = ['row' => $v['row'], 'vessel' => $v['vessel_name'], 'error' => implode(' ', $v['errors'])];
                continue;
            }
            $r = completedImportInsertRow($db, $v, $actorName);
            if ($r['ok']) {
                $result['inserted'][] = ['id' => $r['survey_id'], 'vessel' => $v['vessel_name'], 'report_number' => $r['report_number']];
            } else {
                $result['failed'][] = ['row' => $v['row'], 'vessel' => $v['vessel_name'], 'error' => $r['error']];
            }
        }
        error_log(sprintf('completed_import: user %d imported %d, skipped %d, failed %d from "%s"',
            (int)$_SESSION['user_id'], count($result['inserted']), $result['skipped'], count($result['failed']), $sess['file']));
        $_SESSION['completed_import_result'] = $result;
        header('Location: completed_import.php?done=1');
        exit;
    }
} elseif (isset($_GET['done']) && !empty($_SESSION['completed_import_result'])) {
    $result = $_SESSION['completed_import_result'];
    $step = 'result';
}

include 'includes/header.php';
?>
<style>
    .ci-hero { position: relative; overflow: hidden; border-radius: 22px; background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%); padding: 22px 24px; margin: 12px 12px 16px; display: flex; align-items: center; gap: 16px; box-shadow: 0 14px 34px rgba(30,27,107,.22); }
    .ci-hero-ico { width: 50px; height: 50px; border-radius: 15px; flex: none; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 21px; }
    .ci-hero-title { font-size: 20px; font-weight: 800; color: #fff; }
    .ci-hero-sub { font-size: 12px; color: rgba(255,255,255,.7); margin-top: 3px; font-weight: 500; }
    .ci-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 20px; margin: 0 12px 16px; box-shadow: 0 4px 20px rgba(15,23,42,.05); }
    .ci-card-title { font-size: 15px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
    .ci-card-sub { font-size: 12px; color: #64748b; margin-bottom: 14px; }
    .ci-drop { display: block; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 28px 16px; text-align: center; cursor: pointer; background: #F8FAFC; }
    .ci-drop:hover { border-color: #A78BFA; background: #F5F3FF; }
    .ci-drop i { font-size: 30px; color: #A78BFA; display: block; margin-bottom: 8px; }
    .ci-drop-title { font-size: 13.5px; font-weight: 700; color: #334155; word-break: break-word; }
    .ci-drop-sub { font-size: 11.5px; color: #94a3b8; margin-top: 4px; }
    .ci-btn { display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 12px; padding: 11px 20px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; }
    .ci-btn-primary { background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; }
    .ci-btn-success { background: linear-gradient(120deg, #14532D, #16A34A); color: #fff; }
    .ci-btn-ghost { background: #F1F5F9; color: #334155; }
    .ci-btn:disabled { opacity: .5; cursor: not-allowed; }
    .ci-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
    .ci-error { background: #FEF2F2; border: 1.5px solid #FECACA; border-radius: 12px; padding: 12px 14px; font-size: 12.5px; color: #991B1B; font-weight: 600; margin: 0 12px 16px; }
    .ci-help { font-size: 12px; color: #475569; margin: 14px 0 0; padding-left: 18px; }
    .ci-help li { margin-bottom: 4px; }
    .ci-stats { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
    .ci-stat { flex: 1 1 100px; border-radius: 14px; padding: 10px 12px; text-align: center; }
    .ci-stat .n { font-size: 20px; font-weight: 800; }
    .ci-stat .l { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .ci-stat.total { background: #F1F5F9; color: #334155; }
    .ci-stat.ok { background: #F0FDF4; color: #16A34A; }
    .ci-stat.dup { background: #FFFBEB; color: #B45309; }
    .ci-stat.err { background: #FEF2F2; color: #DC2626; }
    .ci-table-wrap { overflow: auto; border: 1px solid #e2e8f0; border-radius: 14px; max-height: 520px; }
    table.ci-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.ci-table th { position: sticky; top: 0; background: #F8FAFC; text-align: left; padding: 9px 10px; font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.ci-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: top; }
    table.ci-table td.nw { white-space: nowrap; }
    .ci-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
    .ci-badge.ready { background: #F0FDF4; color: #166534; }
    .ci-badge.duplicate { background: #FFFBEB; color: #B45309; }
    .ci-badge.error { background: #FEF2F2; color: #B91C1C; }
    .ci-msg { font-size: 11.5px; min-width: 180px; }
    .ci-saved { font-weight: 600; color: #0f172a; }
    .ci-excel { font-size: 11px; color: #94a3b8; margin-top: 2px; }
    .ci-tag { display: inline-block; font-size: 9.5px; font-weight: 800; letter-spacing: .03em; padding: 1px 6px; border-radius: 6px; margin-left: 4px; vertical-align: middle; }
    .ci-tag.new { background: #EDE9FE; color: #5B21B6; }
    .ci-new-box { background: #F5F3FF; border: 1px solid #DDD6FE; border-radius: 14px; padding: 12px 14px; font-size: 12.5px; color: #3B0764; margin-bottom: 12px; display: grid; gap: 6px; }
    .ci-new-list { color: #6D28D9; }
    .ci-filter { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
    .ci-filter button { border: 1px solid #E2E8F0; background: #fff; border-radius: 999px; padding: 5px 12px; font-size: 12px; font-weight: 700; color: #475569; }
    .ci-filter button.active { background: #1E1B6B; border-color: #1E1B6B; color: #fff; }
    .ci-msg .e { color: #B91C1C; }
    .ci-msg .w { color: #92400E; }
    @media (min-width: 992px) {
        .top-app-bar { display: none !important; }
        .ci-hero, .ci-card, .ci-error { margin-left: 0; margin-right: 0; }
    }
</style>

<?php
$page_title = 'Import Completed';
$back_url = 'completed.php';
$page_testid = 'completed-import';
include 'includes/top_app_bar.php';
?>

<div class="scroll-content" data-testid="completed-import-page">
    <div class="ci-hero">
        <div class="ci-hero-ico"><i class="fa-solid fa-file-import"></i></div>
        <div>
            <div class="ci-hero-title">Bulk Import Completed Vessels</div>
            <div class="ci-hero-sub">Upload an Excel to add vessels directly to the Completed list — no emails are sent</div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="ci-error" data-testid="ci-error"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?= sanitize($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'upload'): ?>
    <div class="ci-card">
        <div class="ci-card-title">1. Upload Excel</div>
        <div class="ci-card-sub">Nothing is saved yet — you'll see a preview of every row first.</div>
        <form method="post" enctype="multipart/form-data" id="ciUploadForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <label class="ci-drop" for="ciFile">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <div class="ci-drop-title" id="ciFileName">Tap to choose an Excel file</div>
                <div class="ci-drop-sub">.xlsx, .xls or .csv — up to 10 MB</div>
            </label>
            <input type="file" name="excel_file" id="ciFile" accept=".xlsx,.xls,.csv" class="d-none" data-testid="ci-file">
            <div class="ci-actions">
                <button type="submit" class="ci-btn ci-btn-primary" id="ciPreviewBtn" disabled data-testid="ci-preview-btn"><i class="fa-solid fa-magnifying-glass"></i> Preview</button>
                <a href="completed.php" class="ci-btn ci-btn-ghost">Cancel</a>
            </div>
        </form>
        <ul class="ci-help">
            <li>Columns (in this order): <b>Appointment Date, Vessel Name, Port Name, Client Name, Type of Survey, Date of Survey, Report Date, Total Recovery, VLSFO, HSFO, LSMGO, Surveyor</b>. Columns are found by their heading, so the Completed list download (Client, Port, Survey Type, Recovery (MT), Report Uploaded Date…) works too. Country heading rows are ignored.</li>
            <li>Required: Vessel Name, Client Name, Port Name, Type of Survey, Surveyor, Report Date. Date of Survey is the Survey Completed Date (monthly figures use it).</li>
            <li>Optional columns: Appointment Date, Agent, Country, Report No, IMO, Assign Date, Remarks. Without a Report No, one is generated for the report's month.</li>
            <li>Combined survey types / surveyors are written with “+” (e.g. <i>Offhire + Condition Survey</i>).</li>
            <li>Names are matched to the existing clients, ports, survey types and surveyors (ignoring case, spacing and words like LTD / PTE / PORT). The preview shows each Excel value next to what will be saved.</li>
            <li>A port name with "Anchorage" is saved to that port's anchorage, otherwise to the port. Ports that don't exist are added (with their anchorage). Surveyors that don't exist are added as inactive records; a blank surveyor is saved as "Not Recorded".</li>
            <li>A missing Report / Completed date is taken from the Assign Date. Clients and survey types must already exist.</li>
            <li>Vessels already in Completed (same vessel, client, port and report date) are skipped.</li>
        </ul>
    </div>
    <script>
    (function () {
        var f = document.getElementById('ciFile'), b = document.getElementById('ciPreviewBtn');
        f.addEventListener('change', function () {
            document.getElementById('ciFileName').textContent = f.files.length ? f.files[0].name : 'Tap to choose an Excel file';
            b.disabled = !f.files.length;
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($step === 'preview'):
        $rows = $preview['rows'];
        $cnt = ['ready' => 0, 'duplicate' => 0, 'error' => 0];
        foreach ($rows as $r) $cnt[$r['status']]++;
        $fmtQty = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
        // Everything this import will add to the master lists (ready rows only).
        $newPorts = []; $newSurveyors = []; $newClients = []; $changed = 0;
        foreach ($rows as $r) {
            if ($r['status'] !== 'ready') continue;
            if (!empty($r['port_plan'])) $newPorts[strtolower($r['port_plan']['create_port'])] = $r['port_plan'];
            // first spelling in the file wins — that's the name the import saves
            if (!empty($r['create_client'])) $newClients[completedImportClientKey($r['client_name'])] ??= $r['client_name'];
            foreach ($r['surveyor_plan'] as $u) if (!empty($u['create'])) $newSurveyors[strtolower($u['name'])] = $u['name'];
        }
        foreach ($rows as $r) foreach ($r['compare'] as $c) if (strcasecmp($c['excel'], $c['saved']) !== 0) { $changed++; break; }
        $possibleDup = count(array_filter($rows, fn($r) => !empty($r['possible_duplicate']) && $r['status'] === 'ready'));
        // Saved value, the Excel value under it when it differs, and how it matched.
        $cmpCell = function (array $r, string $field, string $fallback, string $tag = '') {
            $c = $r['compare'][$field] ?? null;
            if (!$c) return sanitize($fallback);
            $out = '<div class="ci-saved">' . sanitize($c['saved']) . $tag . '</div>';
            if (strcasecmp(trim($c['excel']), trim($c['saved'])) !== 0) {
                $out .= '<div class="ci-excel" title="Value in your Excel">Excel: ' . sanitize($c['excel']) . ($c['how'] !== '' ? ' · ' . sanitize($c['how']) : '') . '</div>';
            }
            return $out;
        };
    ?>
    <div class="ci-card">
        <div class="ci-card-title">2. Preview — <?= sanitize($preview['file']) ?></div>
        <div class="ci-card-sub">Check the rows below. Only rows marked <b>Ready</b> will be imported.</div>
        <div class="ci-stats">
            <div class="ci-stat total"><div class="n"><?= count($rows) ?></div><div class="l">Rows</div></div>
            <div class="ci-stat ok"><div class="n" data-testid="ci-count-ready"><?= $cnt['ready'] ?></div><div class="l">Ready</div></div>
            <div class="ci-stat dup"><div class="n" data-testid="ci-count-dup"><?= $cnt['duplicate'] ?></div><div class="l">Already exist</div></div>
            <div class="ci-stat err"><div class="n" data-testid="ci-count-err"><?= $cnt['error'] ?></div><div class="l">Errors</div></div>
        </div>
        <?php if ($newPorts || $newSurveyors || $newClients): ?>
        <div class="ci-new-box" data-testid="ci-new-masters">
            <?php if ($newClients): ?>
            <div data-testid="ci-new-clients"><b><i class="fa-solid fa-building"></i> <?= count($newClients) ?> new client<?= count($newClients) === 1 ? '' : 's' ?> will be added</b> (not in the client records):
                <span class="ci-new-list"><?= sanitize(implode(', ', $newClients)) ?></span></div>
            <?php endif; ?>
            <?php if ($newPorts): ?>
            <div><b><i class="fa-solid fa-anchor"></i> <?= count($newPorts) ?> new port<?= count($newPorts) === 1 ? '' : 's' ?> will be added</b> (each with its anchorage):
                <span class="ci-new-list"><?= sanitize(implode(', ', array_map(fn($p) => $p['create_port'] . ' (' . $p['country'] . ')', $newPorts))) ?></span></div>
            <?php endif; ?>
            <?php if ($newSurveyors): ?>
            <div><b><i class="fa-solid fa-user-plus"></i> <?= count($newSurveyors) ?> new surveyor<?= count($newSurveyors) === 1 ? '' : 's' ?> will be added</b> (inactive — they cannot log in):
                <span class="ci-new-list"><?= sanitize(implode(', ', $newSurveyors)) ?></span></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="ci-filter" role="group" aria-label="Show rows">
            <button type="button" class="active" data-ci-filter="all">All (<?= count($rows) ?>)</button>
            <button type="button" data-ci-filter="ready">Ready (<?= $cnt['ready'] ?>)</button>
            <button type="button" data-ci-filter="changed">Changed by matching (<?= $changed ?>)</button>
            <button type="button" data-ci-filter="error">Errors (<?= $cnt['error'] ?>)</button>
            <button type="button" data-ci-filter="duplicate">Already exist (<?= $cnt['duplicate'] ?>)</button>
            <?php if ($possibleDup): ?><button type="button" data-ci-filter="possible" data-testid="ci-filter-possible">Possible duplicates (<?= $possibleDup ?>)</button><?php endif; ?>
        </div>
        <div class="ci-table-wrap">
            <table class="ci-table" data-testid="ci-preview-table">
                <thead><tr>
                    <th>Row</th><th>Status</th><th>Vessel</th><th>Client</th><th>Port</th><th>Survey Type</th><th>Surveyor</th><th>Recovery (MT)</th><th>Appointment</th><th>Survey Date</th><th>Report Date</th><th>Notes</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $isChanged = false; foreach ($r['compare'] as $c) if (strcasecmp($c['excel'], $c['saved']) !== 0) $isChanged = true; ?>
                    <tr data-status="<?= sanitize($r['status']) ?>" data-changed="<?= $isChanged ? '1' : '0' ?>" data-possible="<?= !empty($r['possible_duplicate']) ? '1' : '0' ?>">
                        <td class="nw"><?= (int)$r['row'] ?></td>
                        <td class="nw"><span class="ci-badge <?= sanitize($r['status']) ?>"><?= $r['status'] === 'ready' ? '<i class="fa-solid fa-check"></i> Ready' : ($r['status'] === 'duplicate' ? '<i class="fa-solid fa-clone"></i> Skip' : '<i class="fa-solid fa-xmark"></i> Error') ?></span></td>
                        <td class="nw"><b><?= sanitize($r['vessel_name']) ?></b></td>
                        <td><?= $cmpCell($r, 'client', $r['client_raw']) ?></td>
                        <td><?= $cmpCell($r, 'port', $r['port_raw'], !empty($r['port_plan']) ? ' <span class="ci-tag new">NEW PORT</span>' : '') ?></td>
                        <td><?= $cmpCell($r, 'survey_type', $r['survey_type_raw']) ?></td>
                        <td><?= $cmpCell($r, 'surveyor', $r['surveyor_raw'], array_filter($r['surveyor_plan'], fn($u) => !empty($u['create'])) ? ' <span class="ci-tag new">NEW SURVEYOR</span>' : '') ?></td>
                        <td class="nw"><?php
                            if ($r['recovery_na']) echo sanitize(RECOVERY_NA_LABEL);
                            else {
                                echo sanitize($fmtQty($r['recovery_amount']));
                                $parts = [];
                                foreach (['vlsfo' => 'VLSFO', 'hsfo' => 'HSFO', 'lsmgo' => 'LSMGO'] as $k => $lbl) if ($r[$k] !== null) $parts[] = $lbl . ' ' . $fmtQty($r[$k]);
                                if ($parts) echo '<div style="font-size:10.5px;color:#94a3b8">' . sanitize(implode(' · ', $parts)) . '</div>';
                            }
                        ?></td>
                        <td class="nw"><?= !empty($r['appointment_date']) ? date('d-m-Y', strtotime($r['appointment_date'])) : '—' ?></td>
                        <td class="nw"><?= !empty($r['completed_date']) ? date('d-m-Y', strtotime($r['completed_date'])) : '—' ?></td>
                        <td class="nw"><?= isset($r['compare']['report_date']) ? $cmpCell($r, 'report_date', '') : ($r['report_date'] ? date('d-m-Y', strtotime($r['report_date'])) : '') ?></td>
                        <td class="ci-msg"><?php
                            foreach ($r['errors'] as $m) echo '<div class="e">' . sanitize($m) . '</div>';
                            foreach ($r['warnings'] as $m) echo '<div class="w">' . sanitize($m) . '</div>';
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="ci-actions" id="ciImportForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="token" value="<?= sanitize($preview['token']) ?>">
            <button type="submit" class="ci-btn ci-btn-success" <?= $cnt['ready'] ? '' : 'disabled' ?> data-testid="ci-import-btn"><i class="fa-solid fa-file-import"></i> Import <?= $cnt['ready'] ?> vessel<?= $cnt['ready'] === 1 ? '' : 's' ?></button>
            <a href="completed_import.php" class="ci-btn ci-btn-ghost">Upload a different file</a>
        </form>
    </div>
    <script>
    document.querySelectorAll('[data-ci-filter]').forEach(function (b) {
        b.addEventListener('click', function () {
            var f = b.getAttribute('data-ci-filter');
            document.querySelectorAll('[data-ci-filter]').forEach(function (x) { x.classList.toggle('active', x === b); });
            document.querySelectorAll('[data-testid=ci-preview-table] tbody tr').forEach(function (tr) {
                var show = f === 'all' || (f === 'changed' ? tr.getAttribute('data-changed') === '1' : f === 'possible' ? tr.getAttribute('data-possible') === '1' : tr.getAttribute('data-status') === f);
                tr.style.display = show ? '' : 'none';
            });
        });
    });
    document.getElementById('ciImportForm').addEventListener('submit', function (e) {
        if (this.dataset.sent) { e.preventDefault(); return; }
        this.dataset.sent = '1';
    });
    </script>
    <?php endif; ?>

    <?php if ($step === 'result'): ?>
    <div class="ci-card" data-testid="ci-result">
        <div class="ci-card-title"><i class="fa-solid fa-circle-check" style="color:#16A34A"></i> Import finished — <?= sanitize($result['file']) ?></div>
        <div class="ci-stats" style="margin-top:12px">
            <div class="ci-stat ok"><div class="n" data-testid="ci-res-inserted"><?= count($result['inserted']) ?></div><div class="l">Imported</div></div>
            <div class="ci-stat dup"><div class="n" data-testid="ci-res-skipped"><?= (int)$result['skipped'] ?></div><div class="l">Skipped (already exist)</div></div>
            <div class="ci-stat err"><div class="n" data-testid="ci-res-failed"><?= count($result['failed']) ?></div><div class="l">Not imported</div></div>
        </div>
        <?php if ($result['inserted']): ?>
        <div class="ci-table-wrap" style="max-height:320px">
            <table class="ci-table"><thead><tr><th>Vessel</th><th>Report No</th></tr></thead><tbody>
            <?php foreach ($result['inserted'] as $i): ?>
                <tr><td><a href="completed_detail.php?id=<?= (int)$i['id'] ?>"><?= sanitize($i['vessel']) ?></a></td><td class="nw"><?= sanitize($i['report_number']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php endif; ?>
        <?php if ($result['failed']): ?>
        <div class="ci-table-wrap" style="max-height:320px;margin-top:12px">
            <table class="ci-table"><thead><tr><th>Row</th><th>Vessel</th><th>Reason</th></tr></thead><tbody>
            <?php foreach ($result['failed'] as $f): ?>
                <tr><td class="nw"><?= (int)$f['row'] ?></td><td><?= sanitize($f['vessel']) ?></td><td class="ci-msg"><span class="e"><?= sanitize($f['error']) ?></span></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php endif; ?>
        <div class="ci-actions">
            <a href="completed.php" class="ci-btn ci-btn-primary" data-testid="ci-go-completed"><i class="fa-solid fa-list-check"></i> Go to Completed</a>
            <a href="completed_import.php" class="ci-btn ci-btn-ghost">Import another file</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
