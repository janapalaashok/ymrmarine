<?php
/**
 * Bulk upload of ports from Excel (Admin only).
 * Step 1 upload → Step 2 preview (nothing saved) → Step 3 add → result.
 * Every new port also gets its "<name> Anchorage" entry, the same as a
 * port added one at a time from Admin Controls → Ports.
 */
require_once 'config/config.php';
require_once __DIR__ . '/../includes/upload_validation.php';
require_once 'includes/completed_import_helpers.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();
ensurePortsCountryColumn($db);
ensurePortAnchorages($db);

const PORTS_IMPORT_MAX_ROWS = 1000;

function piLoadLibrary(): bool {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    return class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');
}

// Template download: Port Name | Country, with two example rows.
if (isset($_GET['template'])) {
    if (!piLoadLibrary()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Ports_Bulk_Upload_Template.csv"');
        echo "Port Name,Country\nPort of Example,India\nExample Anchorage,India\n";
        exit;
    }
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Ports');
    $sh->fromArray([['Port Name', 'Country'], ['Port of Example', 'India'], ['Example Anchorage', 'India']]);
    $sh->getStyle('A1:B1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sh->getStyle('A1:B1')->getFill()->setFillType('solid')->getStartColor()->setRGB('1E1B6B');
    $sh->getColumnDimension('A')->setWidth(36);
    $sh->getColumnDimension('B')->setWidth(24);
    $sh->freezePane('A2');
    $help = $ss->createSheet();
    $help->setTitle('How to fill');
    $help->fromArray([
        ['How to fill the Ports sheet'],
        ['• One port per row. Delete the two example rows before uploading.'],
        ['• Port Name: the name as it should appear in the app (e.g. Port of Durban, Kandla Port, Dibba).'],
        ['• Country: full country name. Blank is saved as India.'],
        ['• Each new port automatically gets its anchorage too (Dibba → Dibba Anchorage). You do not need a separate row for it.'],
        ['• A name that already contains "Anchorage" is added as that anchorage only.'],
        ['• Ports that already exist are skipped, so the same file can be uploaded again safely.'],
    ], null, 'A1');
    $help->getStyle('A1')->getFont()->setBold(true);
    $help->getColumnDimension('A')->setWidth(110);
    $ss->setActiveSheetIndex(0);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Ports_Bulk_Upload_Template.xlsx"');
    header('Cache-Control: no-store');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

/** Rows under the first header row that has a "Port Name" (or "Port") cell, first sheet only. */
function piReadRows($spreadsheet): array {
    $rows = $spreadsheet->getSheet(0)->toArray(null, true, true, false);
    $nameCol = $countryCol = null;
    $out = [];
    foreach ($rows as $i => $cells) {
        if ($nameCol === null) {
            foreach ($cells as $c => $v) {
                $h = strtolower(trim(preg_replace('/\s+/', ' ', (string)$v)));
                if (in_array($h, ['port name', 'port', 'ports', 'port / anchorage', 'port/anchorage'], true)) $nameCol = $c;
                elseif ($h === 'country') $countryCol = $c;
            }
            continue;
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string)($cells[$nameCol] ?? '')));
        $country = $countryCol !== null ? trim(preg_replace('/\s+/u', ' ', (string)($cells[$countryCol] ?? ''))) : '';
        if ($name === '' && $country === '') continue;
        $out[] = ['row' => $i + 1, 'name' => $name, 'country' => $country];
    }
    return ['header_found' => $nameCol !== null, 'rows' => $out];
}

/**
 * Status per row: new / exists / error. "new" rows carry the anchorage
 * that will be added with them (null when it already exists or the row
 * is itself an anchorage).
 */
function piValidate(PDO $db, array $rows): array {
    $existing = [];
    foreach ($db->query('SELECT port_name, country FROM ports')->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $existing[mb_strtolower($p['port_name'])] = $p;
    }
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $v = $r + ['status' => 'new', 'anchorage' => null, 'messages' => []];
        $key = mb_strtolower($v['name']);
        if ($v['name'] === '') {
            $v['status'] = 'error'; $v['messages'][] = 'Port Name is empty.';
        } elseif (mb_strlen($v['name']) > 150) {
            $v['status'] = 'error'; $v['messages'][] = 'Port Name is longer than 150 characters.';
        } elseif (mb_strlen($v['country']) > 100) {
            $v['status'] = 'error'; $v['messages'][] = 'Country is longer than 100 characters.';
        } elseif (isset($seen[$key])) {
            $v['status'] = 'exists'; $v['messages'][] = 'Same port as row ' . $seen[$key] . ' in this file.';
        } elseif (isset($existing[$key])) {
            $v['status'] = 'exists'; $v['messages'][] = 'Already in Ports as "' . $existing[$key]['port_name'] . '" (' . ($existing[$key]['country'] ?: 'India') . ').';
        }
        if ($v['status'] === 'new') {
            $seen[$key] = $v['row'];
            if ($v['country'] === '') $v['country'] = 'India';
            $anch = buildAnchorageName($v['name']);
            if ($anch !== null && !isset($existing[mb_strtolower($anch)]) && !isset($seen[mb_strtolower($anch)])) {
                $v['anchorage'] = $anch;
                $seen[mb_strtolower($anch)] = $v['row'];
            }
            // Same place under another spelling (e.g. "Durban" vs "Port of Durban") — added anyway, but flagged.
            $base = completedImportPortBase($v['name']);
            foreach ($existing as $p) {
                $pb = completedImportPortBase($p['port_name']);
                if ($base !== '' && $pb !== '' && ($pb === $base || completedImportCloseSpelling($pb, $base)) && completedImportIsAnchorage($p['port_name']) === completedImportIsAnchorage($v['name'])) {
                    $v['messages'][] = 'Looks like existing "' . $p['port_name'] . '" — remove this row if it is the same port.';
                    $v['similar'] = true;
                    break;
                }
            }
        } elseif ($v['status'] !== 'error') {
            $seen[$key] = $seen[$key] ?? $v['row'];
        }
        $out[] = $v;
    }
    return $out;
}

$step = 'upload';
$error = '';
$preview = null;
$result = null;
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
    } elseif (($verr = upload_validate($file, [], 5 * 1024 * 1024)) !== '') {
        $error = $verr;
    } elseif (!piLoadLibrary()) {
        $error = 'Excel library is not available on the server.';
    } else {
        try {
            $read = piReadRows(bulkAssignLoadSpreadsheet($file['tmp_name'], $ext));
            if (!$read['header_found']) {
                $error = 'No "Port Name" column found. Please use the template.';
            } elseif (!$read['rows']) {
                $error = 'No ports found in this file.';
            } elseif (count($read['rows']) > PORTS_IMPORT_MAX_ROWS) {
                $error = 'This file has ' . count($read['rows']) . ' rows — please split it into files of ' . PORTS_IMPORT_MAX_ROWS . ' rows or fewer.';
            } else {
                $token = bin2hex(random_bytes(12));
                $_SESSION['ports_import'] = ['token' => $token, 'file' => $name, 'rows' => $read['rows']];
                $preview = ['token' => $token, 'file' => $name, 'rows' => piValidate($db, $read['rows'])];
                $step = 'preview';
            }
        } catch (Throwable $e) {
            error_log('ports_import preview: ' . $e->getMessage());
            $error = 'Could not read this file. Please check that it is a valid Excel file.';
        }
    }
} elseif ($action === 'import') {
    $sess = $_SESSION['ports_import'] ?? null;
    if (!$sess || !hash_equals((string)$sess['token'], (string)($_POST['token'] ?? ''))) {
        $error = 'This preview has expired or was already used. Please upload the file again.';
    } else {
        unset($_SESSION['ports_import']); // one-shot
        $validated = piValidate($db, $sess['rows']); // re-check against the live list
        $result = ['file' => $sess['file'], 'ports' => [], 'anchorages' => 0, 'skipped' => 0, 'failed' => []];
        $ins = $db->prepare('INSERT IGNORE INTO ports (port_name, country) VALUES (?, ?)');
        foreach ($validated as $v) {
            if ($v['status'] === 'exists') { $result['skipped']++; continue; }
            if ($v['status'] !== 'new') { $result['failed'][] = ['row' => $v['row'], 'name' => $v['name'], 'error' => implode(' ', $v['messages'])]; continue; }
            try {
                $db->beginTransaction();
                $ins->execute([portCaps($v['name']), portCaps($v['country'])]);
                $added = $ins->rowCount() > 0;
                if ($v['anchorage'] !== null) {
                    $ins->execute([portCaps($v['anchorage']), portCaps($v['country'])]);
                    if ($ins->rowCount() > 0) $result['anchorages']++;
                }
                $db->commit();
                if ($added) $result['ports'][] = ['name' => $v['name'], 'country' => $v['country'], 'anchorage' => $v['anchorage']];
                else $result['skipped']++;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('ports_import insert: ' . $e->getMessage());
                $result['failed'][] = ['row' => $v['row'], 'name' => $v['name'], 'error' => 'Could not save this port.'];
            }
        }
        error_log(sprintf('ports_import: user %d added %d ports (+%d anchorages), skipped %d, failed %d from "%s"',
            (int)$_SESSION['user_id'], count($result['ports']), $result['anchorages'], $result['skipped'], count($result['failed']), $sess['file']));
        $_SESSION['ports_import_result'] = $result;
        header('Location: ports_import.php?done=1');
        exit;
    }
} elseif (isset($_GET['done']) && !empty($_SESSION['ports_import_result'])) {
    $result = $_SESSION['ports_import_result'];
    $step = 'result';
}

include 'includes/header.php';
?>
<style>
    .pi-hero { position: relative; overflow: hidden; border-radius: 22px; background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%); padding: 22px 24px; margin: 12px 12px 16px; display: flex; align-items: center; gap: 16px; box-shadow: 0 14px 34px rgba(30,27,107,.22); }
    .pi-hero-ico { width: 50px; height: 50px; border-radius: 15px; flex: none; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 21px; }
    .pi-hero-title { font-size: 20px; font-weight: 800; color: #fff; }
    .pi-hero-sub { font-size: 12px; color: rgba(255,255,255,.7); margin-top: 3px; font-weight: 500; }
    .pi-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 20px; margin: 0 12px 16px; box-shadow: 0 4px 20px rgba(15,23,42,.05); }
    .pi-card-title { font-size: 15px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
    .pi-card-sub { font-size: 12px; color: #64748b; margin-bottom: 14px; }
    .pi-drop { display: block; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 28px 16px; text-align: center; cursor: pointer; background: #F8FAFC; }
    .pi-drop:hover { border-color: #A78BFA; background: #F5F3FF; }
    .pi-drop i { font-size: 30px; color: #A78BFA; display: block; margin-bottom: 8px; }
    .pi-drop-title { font-size: 13.5px; font-weight: 700; color: #334155; word-break: break-word; }
    .pi-drop-sub { font-size: 11.5px; color: #94a3b8; margin-top: 4px; }
    .pi-btn { display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 12px; padding: 11px 20px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; }
    .pi-btn-primary { background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; }
    .pi-btn-success { background: linear-gradient(120deg, #14532D, #16A34A); color: #fff; }
    .pi-btn-ghost { background: #F1F5F9; color: #334155; }
    .pi-btn-outline { background: #fff; color: #4B2FD6; border: 1.5px solid #C4B5FD; }
    .pi-btn:disabled { opacity: .5; cursor: not-allowed; }
    .pi-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
    .pi-error { background: #FEF2F2; border: 1.5px solid #FECACA; border-radius: 12px; padding: 12px 14px; font-size: 12.5px; color: #991B1B; font-weight: 600; margin: 0 12px 16px; }
    .pi-help { font-size: 12px; color: #475569; margin: 14px 0 0; padding-left: 18px; }
    .pi-help li { margin-bottom: 4px; }
    .pi-stats { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
    .pi-stat { flex: 1 1 100px; border-radius: 14px; padding: 10px 12px; text-align: center; }
    .pi-stat .n { font-size: 20px; font-weight: 800; }
    .pi-stat .l { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .pi-stat.total { background: #F1F5F9; color: #334155; }
    .pi-stat.ok { background: #F0FDF4; color: #16A34A; }
    .pi-stat.dup { background: #FFFBEB; color: #B45309; }
    .pi-stat.err { background: #FEF2F2; color: #DC2626; }
    .pi-table-wrap { overflow: auto; border: 1px solid #e2e8f0; border-radius: 14px; max-height: 520px; }
    table.pi-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.pi-table th { position: sticky; top: 0; background: #F8FAFC; text-align: left; padding: 9px 10px; font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.pi-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: top; }
    .pi-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
    .pi-badge.new { background: #F0FDF4; color: #166534; }
    .pi-badge.exists { background: #FFFBEB; color: #B45309; }
    .pi-badge.error { background: #FEF2F2; color: #B91C1C; }
    .pi-sub { font-size: 11px; color: #94a3b8; margin-top: 2px; }
    .pi-msg { font-size: 11.5px; min-width: 160px; color: #92400E; }
    .pi-msg.e { color: #B91C1C; }
    @media (min-width: 992px) {
        .top-app-bar { display: none !important; }
        .pi-hero, .pi-card, .pi-error { margin-left: 0; margin-right: 0; }
    }
</style>

<?php
$page_title = 'Bulk Upload Ports';
$back_url = 'admin_controls.php';
$page_testid = 'ports-import';
include 'includes/top_app_bar.php';
?>

<div class="scroll-content" data-testid="ports-import-page">
    <div class="pi-hero">
        <div class="pi-hero-ico"><i class="fa-solid fa-anchor"></i></div>
        <div>
            <div class="pi-hero-title">Bulk Upload Ports</div>
            <div class="pi-hero-sub">Add many ports at once from Excel — each new port gets its anchorage too</div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="pi-error" data-testid="pi-error"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?= sanitize($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'upload'): ?>
    <div class="pi-card">
        <div class="pi-card-title">1. Upload Excel</div>
        <div class="pi-card-sub">Nothing is saved yet — you'll see a preview first.</div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <label class="pi-drop" for="piFile">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <div class="pi-drop-title" id="piFileName">Tap to choose an Excel file</div>
                <div class="pi-drop-sub">.xlsx, .xls or .csv — columns: Port Name, Country</div>
            </label>
            <input type="file" name="excel_file" id="piFile" accept=".xlsx,.xls,.csv" class="d-none" data-testid="pi-file">
            <div class="pi-actions">
                <button type="submit" class="pi-btn pi-btn-primary" id="piPreviewBtn" disabled data-testid="pi-preview-btn"><i class="fa-solid fa-magnifying-glass"></i> Preview</button>
                <a href="ports_import.php?template=1" class="pi-btn pi-btn-outline" data-testid="pi-template"><i class="fa-solid fa-download"></i> Download Template</a>
                <a href="admin_controls.php" class="pi-btn pi-btn-ghost">Cancel</a>
            </div>
        </form>
        <ul class="pi-help">
            <li>Columns: <b>Port Name</b> and <b>Country</b>. A blank country is saved as India.</li>
            <li>Every new port also gets its anchorage (e.g. <i>Dibba</i> → <i>Dibba Anchorage</i>). A name that already contains "Anchorage" is added as that anchorage only.</li>
            <li>Ports that already exist (same name, any case) are skipped — uploading the same file twice is safe.</li>
        </ul>
    </div>
    <script>
    (function () {
        var f = document.getElementById('piFile'), b = document.getElementById('piPreviewBtn');
        f.addEventListener('change', function () {
            document.getElementById('piFileName').textContent = f.files.length ? f.files[0].name : 'Tap to choose an Excel file';
            b.disabled = !f.files.length;
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($step === 'preview'):
        $rows = $preview['rows'];
        $cnt = ['new' => 0, 'exists' => 0, 'error' => 0];
        foreach ($rows as $r) $cnt[$r['status']]++;
        $anchCount = count(array_filter($rows, fn($r) => $r['status'] === 'new' && $r['anchorage'] !== null));
    ?>
    <div class="pi-card">
        <div class="pi-card-title">2. Preview — <?= sanitize($preview['file']) ?></div>
        <div class="pi-card-sub">Check the list, then press Add. Nothing has been saved yet.</div>
        <div class="pi-stats">
            <div class="pi-stat total"><div class="n"><?= count($rows) ?></div><div class="l">Rows</div></div>
            <div class="pi-stat ok"><div class="n" data-testid="pi-count-new"><?= $cnt['new'] ?></div><div class="l">New ports</div></div>
            <div class="pi-stat ok"><div class="n" data-testid="pi-count-anch"><?= $anchCount ?></div><div class="l">+ Anchorages</div></div>
            <div class="pi-stat dup"><div class="n" data-testid="pi-count-exists"><?= $cnt['exists'] ?></div><div class="l">Already exist</div></div>
            <div class="pi-stat err"><div class="n" data-testid="pi-count-err"><?= $cnt['error'] ?></div><div class="l">Errors</div></div>
        </div>
        <div class="pi-table-wrap">
            <table class="pi-table" data-testid="pi-preview-table">
                <thead><tr><th>Row</th><th>Status</th><th>Port</th><th>Country</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr data-status="<?= sanitize($r['status']) ?>">
                        <td><?= (int)$r['row'] ?></td>
                        <td><span class="pi-badge <?= sanitize($r['status']) ?>"><?= $r['status'] === 'new' ? '<i class="fa-solid fa-plus"></i> New' : ($r['status'] === 'exists' ? '<i class="fa-solid fa-clone"></i> Skip' : '<i class="fa-solid fa-xmark"></i> Error') ?></span></td>
                        <td><b><?= sanitize($r['name']) ?></b><?php if ($r['status'] === 'new' && $r['anchorage'] !== null): ?><div class="pi-sub">+ <?= sanitize($r['anchorage']) ?></div><?php endif; ?></td>
                        <td><?= sanitize($r['country']) ?></td>
                        <td class="pi-msg<?= $r['status'] === 'error' ? ' e' : '' ?>"><?= sanitize(implode(' ', $r['messages'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="pi-actions" id="piImportForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="token" value="<?= sanitize($preview['token']) ?>">
            <button type="submit" class="pi-btn pi-btn-success" <?= $cnt['new'] ? '' : 'disabled' ?> data-testid="pi-import-btn"><i class="fa-solid fa-plus"></i> Add <?= $cnt['new'] ?> port<?= $cnt['new'] === 1 ? '' : 's' ?></button>
            <a href="ports_import.php" class="pi-btn pi-btn-ghost">Upload a different file</a>
        </form>
    </div>
    <script>
    document.getElementById('piImportForm').addEventListener('submit', function (e) {
        if (this.dataset.sent) { e.preventDefault(); return; }
        this.dataset.sent = '1';
    });
    </script>
    <?php endif; ?>

    <?php if ($step === 'result'): ?>
    <div class="pi-card" data-testid="pi-result">
        <div class="pi-card-title"><i class="fa-solid fa-circle-check" style="color:#16A34A"></i> Done — <?= sanitize($result['file']) ?></div>
        <div class="pi-stats" style="margin-top:12px">
            <div class="pi-stat ok"><div class="n" data-testid="pi-res-added"><?= count($result['ports']) ?></div><div class="l">Ports added</div></div>
            <div class="pi-stat ok"><div class="n" data-testid="pi-res-anch"><?= (int)$result['anchorages'] ?></div><div class="l">Anchorages added</div></div>
            <div class="pi-stat dup"><div class="n" data-testid="pi-res-skipped"><?= (int)$result['skipped'] ?></div><div class="l">Skipped</div></div>
            <div class="pi-stat err"><div class="n" data-testid="pi-res-failed"><?= count($result['failed']) ?></div><div class="l">Not added</div></div>
        </div>
        <?php if ($result['ports']): ?>
        <div class="pi-table-wrap" style="max-height:320px">
            <table class="pi-table"><thead><tr><th>Port</th><th>Country</th></tr></thead><tbody>
            <?php foreach ($result['ports'] as $p): ?>
                <tr><td><b><?= sanitize($p['name']) ?></b><?php if ($p['anchorage']): ?><div class="pi-sub">+ <?= sanitize($p['anchorage']) ?></div><?php endif; ?></td><td><?= sanitize($p['country']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php endif; ?>
        <?php if ($result['failed']): ?>
        <div class="pi-table-wrap" style="max-height:320px;margin-top:12px">
            <table class="pi-table"><thead><tr><th>Row</th><th>Port</th><th>Reason</th></tr></thead><tbody>
            <?php foreach ($result['failed'] as $f): ?>
                <tr><td><?= (int)$f['row'] ?></td><td><?= sanitize($f['name']) ?></td><td class="pi-msg e"><?= sanitize($f['error']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php endif; ?>
        <div class="pi-actions">
            <a href="admin_controls.php" class="pi-btn pi-btn-primary" data-testid="pi-go-admin"><i class="fa-solid fa-sliders"></i> Back to Admin Controls</a>
            <a href="ports_import.php" class="pi-btn pi-btn-ghost">Upload another file</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
