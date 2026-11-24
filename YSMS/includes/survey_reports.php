<?php
/**
 * Survey Reports (survey_reports.php / survey_report_public.php / ajax/survey_reports.php): the bunker
 * survey report of a vessel entered on the website and filled into the company's Excel report
 * template (includes/report_templates.php).
 *
 * Who: Admin — every vessel, can edit any report. Surveyor — the vessels assigned to them, can
 * edit while the vessel is Pending Vessel / Pending Report. Super Admin — view and download.
 * Making a report never changes the vessel's status or its files.
 */
require_once __DIR__ . '/report_templates.php';
require_once __DIR__ . '/gemini_json.php';
require_once __DIR__ . '/survey_certificates.php';
require_once __DIR__ . '/vessel_lookup.php'; // Vessel lookup by IMO (also used by the Report Generator)

function srRole(): string { return (string)($_SESSION['role'] ?? ''); }
function srCanUse(): bool { return in_array(srRole(), ['Admin', 'Super Admin', 'Surveyor'], true); }

/** Report + its vessel, or null when missing / not visible to this user. */
function srReport(PDO $db, int $id): ?array {
    rtEnsureTables($db);
    $q = $db->prepare('SELECT r.*, u.full_name AS updated_by_name FROM survey_reports r LEFT JOIN users u ON u.id = r.updated_by WHERE r.id = ?');
    $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if ($r['survey_id'] === null || (int)$r['survey_id'] === 0) {
        // a report made without a vessel: Admin / Super Admin, and the surveyor who made it
        if (!in_array(srRole(), ['Admin', 'Super Admin'], true) && (int)$r['created_by'] !== (int)($_SESSION['user_id'] ?? 0)) return null;
        $r['survey'] = null;
    } else {
        $s = sfSurvey($db, (int)$r['survey_id']);
        if (!$s || !sfCanAccess($db, $s)) return null;
        $r['survey'] = $s;
    }
    $r['data'] = json_decode((string)$r['data'], true) ?: ['fields' => [], 'tanks' => []];
    $r['id'] = (int)$r['id']; $r['version'] = (int)$r['version']; $r['template_id'] = (int)$r['template_id'];
    return $r;
}

function srCanEdit(?array $survey, ?array $report = null): bool {
    if (srRole() === 'Admin') return true;
    if (srRole() !== 'Surveyor') return false;
    if ($survey === null) return $report !== null && (int)$report['created_by'] === (int)($_SESSION['user_id'] ?? 0);
    return in_array((string)$survey['status'], SF_EDIT_STATUSES, true);
}

/** Title of a report: its vessel name, else the report type. */
function srTitle(array $r): string {
    $v = trim((string)($r['data']['fields']['vessel_name'] ?? ''));
    if ($v === '' && !empty($r['survey'])) $v = (string)$r['survey']['vessel_name'];
    return $v !== '' ? $v : 'New ' . (RT_KINDS[$r['kind']] ?? $r['kind']) . ' report';
}

/** Starting values of a new report: the template's standard texts and what the vessel record knows. */
function srDefaults(array $eff, ?array $survey): array {
    $survey = $survey ?? [];
    $d = ['fields' => [], 'tanks' => array_fill_keys(array_keys($eff['sections']), [])];
    foreach ($eff['fields'] as $k => $f) {
        $d['fields'][$k] = in_array($f['group'], ['offhire', 'remarks'], true) ? (string)($eff['sample']['fields'][$k] ?? '') : '';
    }
    if ($survey) foreach (sfPrefill($survey) as $k => $v) if (isset($d['fields'][$k])) $d['fields'][$k] = (string)$v;
    if (isset($d['fields']['surveyor_name']) && $d['fields']['surveyor_name'] === '' && srRole() === 'Surveyor') $d['fields']['surveyor_name'] = strtoupper(trim((string)($_SESSION['full_name'] ?? '')));
    if (isset($d['fields']['place']) && !empty($survey['port_name'])) $d['fields']['place'] = strtoupper((string)$survey['port_name']);
    $d['grades'] = array_values(array_intersect(['vlsfo', 'lsmgo'], array_keys(srGradeList($eff)))); // the usual two, preselected
    $d['step'] = 'vessel';
    $d['extra'] = array_fill_keys(array_keys(srExtraFieldDefs()), '');
    $d['extra']['port_name'] = strtoupper(trim((string)($survey['port_name'] ?? '')));
    foreach (array_keys(srGradeList($eff)) as $g) $d['tanks'][$g] = $d['tanks'][$g] ?? [];
    return $d;
}

/** What the editor needs to draw the form for a template version. */
function srEditorConfig(array $tpl): array {
    $eff = rtEffective($tpl);
    $fields = [];
    foreach ($eff['fields'] as $k => $f) $fields[] = ['key' => $k, 'label' => $f['label'], 'type' => $f['type'], 'group' => $f['group'], 'cell' => $f['sheet'] . '!' . $f['cell']];
    $sections = [];
    foreach ($eff['sections'] as $k => $s) $sections[] = ['key' => $k, 'label' => trim($s['label']), 'short' => $eff['fuel_names'][$k] ?? strtoupper($k), 'rows' => $s['last'] - $s['first'] + 1];
    // what the offline Excel builder needs: where things go in the workbook
    $offline = ['tables_sheet' => $eff['tables_sheet'], 'pattern' => $eff['pattern'],
        'sections' => array_map(fn($s) => ['first' => $s['first'], 'last' => $s['last'], 'gsv_total' => $s['gsv_total'], 'mt_total' => $s['mt_total'], 'cols' => $s['cols'], 'calc_cols' => $s['calc_cols']], $eff['sections']),
        'fields' => array_map(fn($f) => ['sheet' => $f['sheet'], 'cell' => $f['cell'], 'type' => $f['type']], $eff['fields'])];
    $tanksCols = [];
    foreach (RT_TANK_COLS as $k => $d) $tanksCols[] = ['key' => $k, 'label' => $d[0], 'type' => $d[3]];
    return ['template' => ['id' => $tpl['id'], 'kind' => $tpl['kind'], 'version' => $tpl['version'], 'name' => $tpl['original_name']],
        'fields' => $fields, 'groups' => RT_GROUPS, 'sections' => $sections, 'tank_cols' => $tanksCols, 'calc' => rtCalcConfig($eff), 'offline' => $offline,
        'flags' => array_values(array_filter($eff['flags'], fn($f) => $f['level'] === 'error')),
        'grades' => array_map(fn($g, $d) => ['key' => $g] + $d, array_keys(srGradeList($eff)), srGradeList($eff)),
        'extra_fields' => array_map(fn($k, $d) => ['key' => $k, 'label' => $d[0], 'type' => $d[1]], array_keys(srExtraFieldDefs()), srExtraFieldDefs()),
        'vessel_keys' => array_values(array_filter(SR_VESSEL_KEYS, fn($k) => isset($eff['fields'][$k]))),
        'survey_required' => SR_SURVEY_REQUIRED,
        // certificate wording of each survey type (the page draws the certificate offline from it)
        'cert' => ['types' => srCertTypes(), 'choices' => srCertChoices(), 'by_kind' => SR_CERT_BY_KIND, 'asper' => SR_CERT_ASPER]];
}

/** File name in the company's style: "MV. PROTECTOR - OFFHIRE - FIELD REPORT". */
function srFileBase(array $report, array $tpl): string {
    $v = trim((string)($report['data']['fields']['vessel_name'] ?? '')) ?: (string)($report['survey']['vessel_name'] ?? 'SURVEY');
    $base = strtoupper($v) . ' - ' . strtoupper(RT_KINDS[$tpl['kind']] ?? $tpl['kind']) . ' - FIELD REPORT';
    return trim(preg_replace('/[^A-Za-z0-9 .,()_-]+/', '_', $base)) ?: 'SURVEY REPORT';
}

/**
 * Make the Excel (and PDF) from the saved data with the report's template version. Each run
 * creates new files; earlier ones are kept. → ['ok', 'files' => [...], 'results'] or errors.
 */
function srGenerate(PDO $db, array $report, bool $pdf, int $uid): array {
    $tpl = rtTemplate($db, $report['template_id']);
    if (!$tpl || !rtAbs($tpl['file_path'])) return ['ok' => false, 'error' => 'The report template is missing.'];
    $eff = rtEffective($tpl);
    $issues = srValidate($eff, $report['data']);
    if ($issues) return ['ok' => false, 'error' => 'Please complete the marked values first.', 'issues' => $issues];
    [$book, $eff, $print, $where] = srBuildBook($tpl, $report['data']);
    $results = srResults($book, $eff, $report['data'], $where);
    $dir = rtReportDir($report['id']);
    rtMkdir($dir);
    $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $base = srFileBase($report, $tpl);
    $rel = 'uploads/survey_reports/' . $report['id'] . '/' . $stamp . '.xlsx';
    rtSave($book, dirname(__DIR__) . '/' . $rel, rtAbs($tpl['file_path']));
    $files = [];
    $ins = $db->prepare('INSERT INTO survey_report_files (report_id, template_id, type, file_name, file_path, file_size, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $now = date('Y-m-d H:i:s');
    $ins->execute([$report['id'], $tpl['id'], 'xlsx', $base . '.xlsx', $rel, filesize(dirname(__DIR__) . '/' . $rel), $uid ?: null, $now]);
    $files['xlsx'] = (int)$db->lastInsertId();
    $pdfError = '';
    if ($pdf) {
        $prel = 'uploads/survey_reports/' . $report['id'] . '/' . $stamp . '.pdf';
        require_once __DIR__ . '/survey_report_pdf.php';
        if (srOwnPdfAvailable()) $p = srOwnPdf($tpl, $report, dirname(__DIR__) . '/' . $prel); // YMR's own format on the letterhead
        else { // no dompdf: the template printed by LibreOffice, as before
            [$pbook, , $pprint] = srBuildBook($tpl, $report['data'], true);
            $ptmp = sys_get_temp_dir() . '/ysms_srpdf_' . bin2hex(random_bytes(5)) . '.xlsx';
            rtSave($pbook, $ptmp, rtAbs($tpl['file_path']));
            $pbook->disconnectWorksheets();
            $p = rtPdf($ptmp, $pprint, dirname(__DIR__) . '/' . $prel);
            @unlink($ptmp);
        }
        if ($p['ok']) { $ins->execute([$report['id'], $tpl['id'], 'pdf', $base . '.pdf', $prel, filesize(dirname(__DIR__) . '/' . $prel), $uid ?: null, $now]); $files['pdf'] = (int)$db->lastInsertId(); }
        else $pdfError = $p['error'];
    }
    return ['ok' => true, 'file_ids' => $files, 'results' => $results, 'pdf_error' => $pdfError];
}

/** Server check of the summary: the same workbook, calculated, nothing stored. */
function srVerify(array $tpl, array $data): array {
    $eff = rtEffective($tpl);
    $issues = srValidate($eff, $data);
    $grades = srChosenGrades($eff, $data);
    $ok = $grades && !array_filter($issues, fn($i) => strpos($i['step'], 'g:') === 0);
    if (!$ok) return ['issues' => $issues, 'results' => null];
    [$book, $eff, , $where] = srBuildBook($tpl, $data);
    return ['issues' => $issues, 'results' => srResults($book, $eff, $data, $where)];
}

function srFiles(PDO $db, int $reportId): array {
    $q = $db->prepare('SELECT f.id, f.type, f.file_name, f.file_size, f.created_at, f.template_id, t.version AS template_version, u.full_name AS created_by_name
        FROM survey_report_files f LEFT JOIN report_templates t ON t.id = f.template_id LEFT JOIN users u ON u.id = f.created_by WHERE f.report_id = ? ORDER BY f.id DESC');
    $q->execute([$reportId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

// ================================================================ wizard: grades, checks, workbook

/** Fuel grades the surveyor can choose (order of the wizard steps). */
const SR_GRADES = [
    'vlsfo' => ['VLSFO', 'Very Low Sulphur Fuel Oil'],
    'ulsfo' => ['ULSFO', 'Ultra Low Sulphur Fuel Oil'],
    'hsfo'  => ['HSFO', 'High Sulphur Fuel Oil'],
    'lsmgo' => ['LSMGO', 'Low Sulphur Marine Gas Oil'],
    'hsmgo' => ['HSMGO', 'High Sulphur Marine Gas Oil'],
];
/**
 * Grades the template has no section for go on a copy of TABLES 54B added to the generated
 * workbook, in the section of this template grade (same layout and 54B formulas).
 */
const SR_EXTRA_SLOT = ['ulsfo' => 'vlsfo', 'hsmgo' => 'lsmgo'];
const SR_EXTRA_SHEET = 'TABLES 54B (ULSFO-HSMGO)';
const SR_SUMMARY_SHEET = 'ROB SUMMARY';
/** The vessel particulars of step 1 (with the IMO): what Vessel lookup fills. Owners are entered in step 2 with the
    charterers (the lookup still fills them when it finds them). */
const SR_VESSEL_KEYS = ['vessel_name', 'flag', 'port_of_registry', 'grt', 'nrt', 'holds', 'builder', 'keel_month', 'keel_year',
    'operator', 'callsign', 'loa', 'breadth', 'depth', 'summer_draft', 'summer_dwt', 'class'];
/** Survey particulars that must be filled (ROB of each chosen grade is added). */
const SR_SURVEY_REQUIRED = ['owners', 'charterers', 'fwe_date', 'fwe_time', 'started_date', 'started_time', 'completed_date', 'completed_time', 'surveyor_name',
    'chief_engineer', 'master', 'port_name', 'place', 'draft_fwd', 'draft_aft'];
/** Values the template has no cell for (kept in data.extra; printed on the ROB SUMMARY sheet). */
function srExtraFieldDefs(): array {
    return [
        'port_name' => ['Port Name', 'text'],
        'survey_type' => ['Survey Type', 'text'], // certificate wording: one of srCertChoices() (includes/survey_certificates.php)
        'brob_fwe_ulsfo' => ['FWE ROB — ULSFO (MT)', 'num:3'], 'brob_fwe_hsmgo' => ['FWE ROB — HSMGO (MT)', 'num:3'],
        'brob_started_ulsfo' => ['Survey started ROB — ULSFO (MT)', 'num:3'], 'brob_started_hsmgo' => ['Survey started ROB — HSMGO (MT)', 'num:3'],
    ];
}
const SR_ROW_META = ['mode', 'ullage', 'su', 'done', 'id', 'copy']; // su = s / u: printed next to the reading // copy = 1: a copied tank not saved yet

/** Grades offered for a template: its own sections plus the ones a copied section can take. */
function srGradeList(array $eff): array {
    $out = [];
    foreach (SR_GRADES as $g => [$short, $name]) {
        if (isset($eff['sections'][$g])) $out[$g] = ['short' => $short, 'name' => $name, 'template' => true];
        elseif (isset(SR_EXTRA_SLOT[$g], $eff['sections'][SR_EXTRA_SLOT[$g]])) $out[$g] = ['short' => $short, 'name' => $name, 'template' => false];
    }
    return $out;
}
function srChosenGrades(array $eff, array $data): array {
    $list = srGradeList($eff);
    return array_values(array_filter(array_keys($list), fn($g) => in_array($g, (array)($data['grades'] ?? []), true)));
}
/** A value of the form: template field or extra value. */
function srVal(array $data, string $k): string {
    return trim((string)($data['fields'][$k] ?? $data['extra'][$k] ?? ''));
}

/** Clean what the page sends besides the template fields: grades, step, extra values, tank rows of every grade. */
function srCleanExtra(array $eff, array $in, array &$store, array &$errors): void {
    $list = srGradeList($eff);
    $store['grades'] = array_values(array_filter(array_keys($list), fn($g) => in_array($g, (array)($in['grades'] ?? []), true)));
    $store['step'] = mb_substr(preg_replace('/[^a-z0-9:_]/', '', (string)($in['step'] ?? '')), 0, 20);
    $store['extra'] = [];
    foreach (srExtraFieldDefs() as $k => [$label, $type]) {
        $raw = (string)($in['extra'][$k] ?? '');
        [$v, $err] = sfValidate($type, $raw);
        if ($err === '' && $v !== '' && strpos($k, 'brob_') === 0) $err = sfRangeError('brob', $v);
        if ($k === 'survey_type' && !isset(srCertChoices()[$v])) { $v = ''; $err = ''; } // only a known type is kept
        if ($err !== '') { $errors['f:' . $k] = $err; $v = mb_substr(trim($raw), 0, 200); }
        $store['extra'][$k] = $v;
    }
    foreach (array_keys($list) as $g) { // rows as typed, with their own keys (row positions stay as the user sees them)
        $store['tanks'][$g] = array_values(array_map(function ($row) {
            $o = [];
            foreach (array_merge(array_keys(RT_TANK_COLS), SR_ROW_META) as $c) $o[$c] = is_scalar($row[$c] ?? '') ? mb_substr(trim((string)($row[$c] ?? '')), 0, 60) : '';
            $o['mode'] = $o['mode'] === 'ullage' ? 'ullage' : 'sounding';
            $o['su'] = $o['mode'] === 'ullage' ? 'u' : ($o['su'] === 's' ? 's' : '');
            $o['done'] = $o['done'] ? '1' : '';
            $o['copy'] = $o['copy'] && !$o['done'] ? '1' : '';
            $o['id'] = preg_replace('/[^A-Za-z0-9_-]/', '', $o['id']);
            return $o;
        }, array_slice(array_values((array)($in['tanks'][$g] ?? [])), 0, RT_MAX_TANKS)));
    }
}

/** Sounding of a tank row: typed, or Reference height − Ullage. */
function srSounding(array $row): string {
    if (($row['mode'] ?? '') !== 'ullage') return trim((string)($row['sounding'] ?? ''));
    $ref = str_replace(',', '', trim((string)($row['ref_height'] ?? ''))); $ull = str_replace(',', '', trim((string)($row['ullage'] ?? '')));
    if (!is_numeric($ref) || !is_numeric($ull)) return '';
    return number_format(round((float)$ref - (float)$ull, 3), 3, '.', '');
}

/**
 * Everything that stops the export, step by step:
 * [['step' => 'vessel'|'survey'|'g:<grade>'|'grades', 'key' => 'f:<field>'|'t:<grade>:<i>:<col>'|'', 'msg']].
 */
function srValidate(array $eff, array $data): array {
    $issues = [];
    $label = fn($k) => $eff['fields'][$k]['label'] ?? (srExtraFieldDefs()[$k][0] ?? $k);
    [, $typeErrors] = rtCleanData($eff, ['fields' => $data['fields'] ?? [], 'tanks' => []]);
    foreach (array_merge(['imo'], SR_VESSEL_KEYS) as $k) {
        if (!isset($eff['fields'][$k])) continue;
        if (srVal($data, $k) === '') $issues[] = ['step' => 'vessel', 'key' => 'f:' . $k, 'msg' => $label($k) . ' is missing.'];
        elseif (isset($typeErrors['f:' . $k])) $issues[] = ['step' => 'vessel', 'key' => 'f:' . $k, 'msg' => $label($k) . ': ' . $typeErrors['f:' . $k]];
    }
    $grades = srChosenGrades($eff, $data);
    if (!$grades) $issues[] = ['step' => 'survey', 'key' => 'grades', 'msg' => 'Choose the fuel grades on board.'];
    $req = SR_SURVEY_REQUIRED;
    foreach ($grades as $g) { $req[] = 'brob_fwe_' . $g; $req[] = 'brob_started_' . $g; }
    foreach ($req as $k) {
        if (!isset($eff['fields'][$k]) && !isset(srExtraFieldDefs()[$k])) continue;
        if (srVal($data, $k) === '') $issues[] = ['step' => 'survey', 'key' => 'f:' . $k, 'msg' => $label($k) . ' is missing.'];
    }
    foreach (['voyage_no', 'charterers', 'alongside', 'list', ...$req] as $k) {
        if (isset($typeErrors['f:' . $k]) && srVal($data, $k) !== '') $issues[] = ['step' => 'survey', 'key' => 'f:' . $k, 'msg' => $label($k) . ': ' . $typeErrors['f:' . $k]];
    }
    $s = srVal($data, 'started_date') . ' ' . srVal($data, 'started_time'); $c = srVal($data, 'completed_date') . ' ' . srVal($data, 'completed_time');
    if (strlen($s) === 16 && strlen($c) === 16 && $c < $s) $issues[] = ['step' => 'survey', 'key' => 'f:completed_time', 'msg' => 'Survey Completed is before Survey Started.'];
    $names = srGradeList($eff);
    foreach ($grades as $g) {
        $rows = array_values((array)($data['tanks'][$g] ?? []));
        $short = $names[$g]['short'];
        if (!$rows) { $issues[] = ['step' => 'g:' . $g, 'key' => '', 'msg' => $short . ': add at least one tank.']; continue; }
        foreach ($rows as $i => $row) {
            $tn = $short . ' tank ' . ($i + 1) . (trim((string)($row['name'] ?? '')) !== '' ? ' (' . trim((string)$row['name']) . ')' : '');
            foreach (srTankErrors($row) as $col => $msg) $issues[] = ['step' => 'g:' . $g, 'key' => "t:$g:$i:$col", 'msg' => $tn . ': ' . $msg];
        }
    }
    return $issues;
}

/** Missing / wrong values of one tank → [column => message]. */
function srTankErrors(array $row): array {
    $err = [];
    $ull = ($row['mode'] ?? '') === 'ullage';
    $need = ['name' => 'Tank name', 'ref_height' => 'Reference height', $ull ? 'ullage' : 'sounding' => $ull ? 'Ullage' : 'Sounding', 'volume' => 'Volume', 'density' => 'Density @15°C', 'temp' => 'Temperature'];
    foreach ($need as $c => $lab) {
        $v = trim((string)($row[$c] ?? ''));
        if ($v === '') { $err[$c] = $lab . ' is missing.'; continue; }
        $type = $c === 'ullage' ? 'num:3' : RT_TANK_COLS[$c][3];
        [$clean, $e] = sfValidate($type, $v);
        if ($e === '') $e = sfRangeError($c, $clean);
        if ($e !== '') $err[$c] = $lab . ': ' . $e;
    }
    if ($ull && !isset($err['ref_height']) && !isset($err['ullage'])) {
        if (!is_numeric(str_replace(',', '', (string)$row['ref_height']))) $err['ref_height'] = 'Reference height must be a number to work out the sounding from the ullage.';
        elseif ((float)str_replace(',', '', (string)$row['ullage']) > (float)str_replace(',', '', (string)$row['ref_height'])) $err['ullage'] = 'Ullage is more than the reference height.';
    }
    return $err;
}

/** Tank rows ready for the workbook (sounding worked out from ullage). */
function srTankRows(array $data, string $g): array {
    $out = [];
    foreach ((array)($data['tanks'][$g] ?? []) as $row) {
        $r = [];
        foreach (array_keys(RT_TANK_COLS) as $c) $r[$c] = trim((string)($row[$c] ?? ''));
        $r['sounding'] = srSounding($row);
        if (implode('', $r) === '') continue;
        $r['su'] = ($row['mode'] ?? '') === 'ullage' ? 'u' : (($row['su'] ?? '') === 's' ? 's' : '');
        if ($r['su'] === 's' && !is_numeric(str_replace(',', '', $r['sounding']))) $r['su'] = ''; // GAUGE / empty: no S
        $r['ullage'] = trim((string)($row['ullage'] ?? ''));
        $out[] = $r;
    }
    return $out;
}

/** [sheet => [col idx max, [coord => formula]]]: formula cells inside each sheet's print-area columns. */
function srFormulaCells(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $sheets): array {
    $out = [];
    foreach ($sheets as $title) {
        $ws = $book->getSheetByName($title);
        if (!$ws) continue;
        $maxCol = 'Z';
        $pa = $ws->getPageSetup()->getPrintArea();
        if ($pa && preg_match('/:\$?([A-Z]{1,3})\$?\d+$/', explode(',', $pa)[0], $m)) $maxCol = $m[1];
        $maxIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($maxCol);
        $cells = [];
        foreach ($ws->getCoordinates() as $c) {
            [$cc] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($c);
            if (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cc) > $maxIdx) continue;
            $v = $ws->getCell($c)->getValue();
            if (is_string($v) && $v !== '' && $v[0] === '=') $cells[$c] = $v;
        }
        $out[$title] = $cells;
    }
    return $out;
}

/**
 * Hide a grade that is not on board: its section on the tables sheet, every printed row that
 * shows its heading or total (certificate, totals list), and rows that repeat such a row's
 * cells (e.g. the certificate's consumption lines "=D18").
 */
function srHideGrade(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $tablesTitle, array $sec, string $mtC, array $sheets): void {
    $t = $book->getSheetByName($tablesTitle);
    srHideRows($t, $sec['heading'], $sec['mt_total']);
    $hidden = [$tablesTitle => range($sec['heading'], $sec['mt_total'])];
    $targets = ['B' . $sec['heading'], $mtC . $sec['mt_total']];
    $formulas = srFormulaCells($book, $sheets);
    foreach ($formulas as $title => $cells) {
        foreach ($cells as $coord => $f) {
            foreach ($targets as $cell) {
                [$col, $row] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($cell);
                $local = '/(?<![A-Z$!\'])\$?' . $col . '\$?' . $row . '(?!\d)/';
                $remote = '/\'?' . preg_quote($tablesTitle, '/') . '\'?!\$?' . $col . '\$?' . $row . '(?!\d)/';
                if (($title === $tablesTitle && preg_match($local, $f)) || preg_match($remote, $f)) { $hidden[$title][] = (int)preg_replace('/\D/', '', $coord); break; }
            }
        }
    }
    for ($pass = 0; $pass < 3; $pass++) { // rows that repeat a hidden row's cells on the same sheet
        $more = false;
        foreach ($formulas as $title => $cells) {
            $h = array_flip($hidden[$title] ?? []);
            if (!$h) continue;
            foreach ($cells as $coord => $f) {
                $r = (int)preg_replace('/\D/', '', $coord);
                if (isset($h[$r]) || !preg_match_all('/(?<![A-Z$!\'])\$?[A-Z]{1,3}\$?(\d+)(?!\d)/', preg_replace('/"[^"]*"|\'[^\']*\'![$A-Z0-9:]+/', '', $f), $m)) continue;
                foreach ($m[1] as $ref) if (isset($h[(int)$ref])) { $hidden[$title][] = $r; $more = true; break; }
            }
        }
        if (!$more) break;
    }
    foreach ($hidden as $title => $rows) {
        $ws = $book->getSheetByName($title);
        foreach (array_unique($rows) as $r) srHideRows($ws, $r, $r);
    }
}

/**
 * The BROB block in the report header (labels over the log-book ROB at FWE and at survey start,
 * one column per template grade): the grades in $grades fill the columns from the left, in order,
 * so there is no empty column between them; the columns left over are emptied. A template
 * grade keeps its formula to SHIP DETAILS; ULSFO / HSMGO get their typed values.
 */
function srHeaderCompact(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, array $eff, array $grades, array $data): void {
    $top = min(array_column($eff['sections'], 'heading')) - 1;
    $DT = \PhpOffice\PhpSpreadsheet\Cell\DataType::class;
    $norm = fn($v) => str_replace(['$', "'"], '', (string)$v);
    $cols = [];
    foreach (array_keys($eff['sections']) as $slot) {
        $col = ['label' => null, 'fwe' => null, 'started' => null];
        foreach (['fwe', 'started'] as $k) {
            $f = $eff['fields']['brob_' . $k . '_' . $slot] ?? null;
            if (!$f) continue;
            $want = $norm('=' . $f['sheet'] . '!' . $f['cell']);
            foreach ($ws->getRowIterator(1, $top) as $row) foreach ($row->getCellIterator('A', 'L') as $cell) {
                if ($norm($cell->getValue()) === $want) $col[$k] = $cell->getCoordinate();
            }
        }
        $any = $col['fwe'] ?? $col['started'];
        if (!$any) continue;
        [$c] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($any);
        $short = strtoupper(trim($eff['fuel_names'][$slot] ?? $slot));
        for ($r = $top; $r >= 1; $r--) {
            $v = $ws->getCell($c . $r)->getValue();
            if (is_string($v) && strtoupper(trim($v)) === $short) { $col['label'] = $c . $r; break; }
        }
        $cols[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($c)] = $col;
    }
    ksort($cols);
    $grades = array_values($grades);
    foreach (array_values($cols) as $i => $col) {
        $g = $grades[$i] ?? null;
        if ($col['label']) { $g ? $ws->getCell($col['label'])->setValueExplicit(SR_GRADES[$g][0], $DT::TYPE_STRING) : $ws->getCell($col['label'])->setValueExplicit(null, $DT::TYPE_NULL); }
        foreach (['fwe', 'started'] as $k) {
            if (!$col[$k]) continue;
            $cell = $ws->getCell($col[$k]);
            if (!$g) { $cell->setValueExplicit(null, $DT::TYPE_NULL); continue; }
            $f = $eff['fields']['brob_' . $k . '_' . $g] ?? null;
            if ($f) { $cell->setValue("='" . $f['sheet'] . "'!" . $f['cell']); continue; }
            $v = srVal($data, 'brob_' . $k . '_' . $g);
            if ($v !== '' && is_numeric($v)) $cell->setValue((float)$v); else $cell->setValueExplicit(null, $DT::TYPE_NULL);
        }
    }
    // the columns left over disappear from the header: no borders, and the unit ("M.Tons") moves
    // next to the last grade, so the block has exactly as many columns as grades on board
    $list = array_values($cols);
    $used = min(count($grades), count($list));
    if ($used >= count($list) || $used < 1) return;
    $none = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE]]];
    $rowsOf = function (array $col) { $r = []; foreach (['label', 'fwe', 'started'] as $k) if ($col[$k]) $r[$k] = (int)preg_replace('/\D/', '', $col[$k]); return $r; };
    for ($i = $used; $i < count($list); $i++) foreach (['label', 'fwe', 'started'] as $k) if ($list[$i][$k]) $ws->getStyle($list[$i][$k])->applyFromArray($none); // the last grade keeps its own right edge
    $lastIdx = max(array_keys($cols));
    $after = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_keys($cols)[$used - 1] + 1);
    foreach ($rowsOf($list[$used - 1]) as $r) {
        for ($c = $lastIdx + 1; $c <= $lastIdx + 3; $c++) { // the unit text right of the block
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
            $v = $ws->getCell($coord)->getValue();
            if (!is_string($v) || trim($v) === '' || $v[0] === '=') continue;
            $ws->getCell($after . $r)->setValueExplicit($v, $DT::TYPE_STRING);
            $ws->getStyle($after . $r)->getFont()->applyFromArray(['name' => $ws->getStyle($coord)->getFont()->getName(), 'size' => $ws->getStyle($coord)->getFont()->getSize(), 'bold' => $ws->getStyle($coord)->getFont()->getBold()]);
            $ws->getStyle($after . $r)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
            $ws->getCell($coord)->setValueExplicit(null, $DT::TYPE_NULL);
            break;
        }
    }
}

/** A section shows only the tanks entered: its spare rows are hidden (totals and formulas are unchanged). */
function srHideEmptyTankRows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, array $sec, int $count): void {
    if ($count < 1) return;
    srHideRows($ws, $sec['first'] + $count, $sec['gsv_total'] - 1);
}

/** The tank report on one A4 page: print area up to the last filled row, scaled to fit one page. */
function srFitOnePage(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): void {
    $ps = $ws->getPageSetup();
    $area = $ps->getPrintArea();
    $lastCol = 'J'; $firstCell = 'A1';
    if ($area && preg_match('/^\'?[^!]*\'?!?\$?([A-Z]+)\$?(\d+):\$?([A-Z]+)\$?\d+$/', explode(',', $area)[0], $m)) { $firstCell = $m[1] . $m[2]; $lastCol = $m[3]; }
    $maxIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastCol);
    $lastRow = 1;
    foreach ($ws->getCoordinates() as $c) {
        [$cc, $rr] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($c);
        if (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cc) > $maxIdx) continue;
        $v = $ws->getCell($c)->getValue();
        if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) continue;
        if ((int)$rr > $lastRow) $lastRow = (int)$rr;
    }
    $ps->setPrintArea($firstCell . ':' . $lastCol . ($lastRow + 1));
    $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $ps->setFitToPage(true);
    $ps->setFitToWidth(1);
    $ps->setFitToHeight(1);
}

/**
 * A printed page that fills the A4 sheet: print area = the cells used (visible columns), narrow
 * margins, centred, scaled to the page width; the height left over is shared out over the rows
 * (at most 1.6×) so the page is filled top to bottom and still prints on one page.
 */
function srFitA4(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, ?string $lastCol = null): void {
    $C = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::class;
    $ps = $ws->getPageSetup();
    $firstRow = 1; $maxIdx = $lastCol ? $C::columnIndexFromString($lastCol) : 0; $lastRow = 1;
    $area = $ps->isPrintAreaSet() ? $ps->getPrintArea() : '';
    if ($area && preg_match('/\$?([A-Z]+)\$?(\d+):\$?([A-Z]+)\$?(\d+)/', explode(',', $area)[0], $m)) { $firstRow = (int)$m[2]; if (!$lastCol) $maxIdx = $C::columnIndexFromString($m[3]); }
    $limit = $maxIdx ?: 30;
    $usedMax = 1;
    foreach ($ws->getCoordinates() as $c) {
        [$cc, $rr] = $C::coordinateFromString($c);
        $ci = $C::columnIndexFromString($cc);
        if ($ci > $limit || !$ws->getColumnDimension($cc)->getVisible() || !$ws->getRowDimension((int)$rr)->getVisible()) continue;
        $v = $ws->getCell($c)->getValue();
        if ($v === null || (is_string($v) && trim($v) === '')) continue;
        $lastRow = max($lastRow, (int)$rr); $usedMax = max($usedMax, $ci);
    }
    foreach ($ws->getDrawingCollection() as $d) { // the letterhead / logo pictures belong to the page too
        [$dc, $dr] = $C::coordinateFromString($d->getCoordinates());
        $lastRow = max($lastRow, (int)$dr);
    }
    if (!$maxIdx) $maxIdx = $usedMax;
    foreach ($ws->getMergeCells() as $rng) { // a merged block that starts inside reaches its end column
        [$a, $b] = explode(':', $rng);
        [$ac, $ar] = $C::coordinateFromString($a); [$bc] = $C::coordinateFromString($b);
        if ((int)$ar >= $firstRow && (int)$ar <= $lastRow && $C::columnIndexFromString($ac) <= $maxIdx) $maxIdx = max($maxIdx, min($C::columnIndexFromString($bc), $limit));
    }
    // centred text wider than the first column would run off the page's left edge: start it at the edge
    $aw = $ws->getColumnDimension('A')->getWidth();
    $merged = [];
    foreach ($ws->getMergeCells() as $rng) { [$m0] = explode(':', $rng); $merged[$m0] = true; } // a title merged across the page stays centred
    for ($r = $firstRow; $r <= $lastRow; $r++) {
        if (isset($merged['A' . $r])) continue;
        $v = $ws->getCell('A' . $r)->getValue();
        if (!is_string($v) || $v === '' || $v[0] === '=' || mb_strlen($v) <= ($aw > 0 ? $aw : 8.43)) continue;
        $al = $ws->getStyle('A' . $r)->getAlignment();
        if (in_array($al->getHorizontal(), ['center', 'centerContinuous', 'right'], true) && !$al->getWrapText()) $al->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
    }
    $lastRow++;
    $endCol = $C::stringFromColumnIndex($maxIdx);
    $ps->setPrintArea('A' . $firstRow . ':' . $endCol . $lastRow);
    $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $ps->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
    $ps->setHorizontalCentered(true);
    $mg = $ws->getPageMargins();
    $mg->setLeft(0.3); $mg->setRight(0.3); $mg->setTop(0.3); $mg->setBottom(0.3); $mg->setHeader(0); $mg->setFooter(0);
    // size of the area in points (column width in characters → about 7 px + 5 px padding; 1 px = 0.75 pt)
    $defW = $ws->getDefaultColumnDimension()->getWidth(); $defW = $defW > 0 ? $defW : 8.43;
    $w = 0.0;
    for ($i = 1; $i <= $maxIdx; $i++) {
        $cd = $ws->getColumnDimension($C::stringFromColumnIndex($i));
        if (!$cd->getVisible()) continue;
        $cw = $cd->getWidth(); $w += (($cw > 0 ? $cw : $defW) * 7 + 5) * 0.75;
    }
    $defH = $ws->getDefaultRowDimension()->getRowHeight(); $defH = $defH > 0 ? $defH : 15.0;
    $h = 0.0; $rows = [];
    for ($r = $firstRow; $r <= $lastRow; $r++) {
        $rd = $ws->getRowDimension($r);
        if (!$rd->getVisible()) continue;
        $rh = $rd->getRowHeight(); $rh = $rh > 0 ? $rh : $defH;
        $rows[$r] = $rh; $h += $rh;
    }
    $pageW = (8.27 - 0.6) * 72; $pageH = (11.69 - 0.6) * 72;
    if ($w > 0 && $h > 0) {
        $scale = min($pageW / $w, $pageH / $h);
        $stretch = min(1.6, 0.96 * ($pageH / $scale) / $h); // fill the height left over at this scale
        if ($stretch > 1.02) foreach ($rows as $r => $rh) $ws->getRowDimension($r)->setRowHeight(round($rh * $stretch, 2));
    }
    $ps->setFitToPage(true);
    $ps->setFitToWidth(1);
    $ps->setFitToHeight(1);
}

/** The company letterhead used on the survey reports (TABLES 54B, certificate, ROB summary, draft PDF). */
const SR_LETTERHEAD = __DIR__ . '/../assets/images/survey-letterhead.png';

/**
 * The template's logo at the top of a printed sheet is replaced by the letterhead: same place
 * (from A1), as wide as the printed columns, the rows it covered resized to its height.
 */
function srLetterhead(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, string $lastCol = 'J'): void {
    $C = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::class;
    if (!is_file(SR_LETTERHEAD)) return;
    $coll = $ws->getDrawingCollection(); $bottom = 0; $remove = [];
    foreach ($coll as $k => $d) {
        [, $r1] = $C::coordinateFromString($d->getCoordinates());
        if ((int)$r1 > 3) continue; // only the header picture
        $r2 = $d->getCoordinates2() ? (int)$C::coordinateFromString($d->getCoordinates2())[1] : (int)$r1 + 4;
        $bottom = max($bottom, $r2 - 1, (int)$r1);
        $remove[] = $k;
    }
    if (!$remove) return; // a template without a header logo: nothing to replace
    foreach (array_reverse($remove) as $k) $coll->offsetUnset($k);
    $coll->exchangeArray(array_values($coll->getArrayCopy()));
    $defW = $ws->getDefaultColumnDimension()->getWidth(); $defW = $defW > 0 ? $defW : 8.43;
    $px = 0;
    for ($i = 1; $i <= $C::columnIndexFromString($lastCol); $i++) {
        $cd = $ws->getColumnDimension($C::stringFromColumnIndex($i));
        if ($cd->getVisible()) $px += (($cd->getWidth() > 0 ? $cd->getWidth() : $defW) * 7 + 5);
    }
    [$bw, $bh] = getimagesize(SR_LETTERHEAD) ?: [961, 248];
    $w = (int)max(200, $px - 6); $h = (int)round($w * $bh / $bw);
    $d = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $d->setName('Letterhead'); $d->setPath(SR_LETTERHEAD); $d->setResizeProportional(false); $d->setWidth($w); $d->setHeight($h);
    $d->setCoordinates('A1'); $d->setOffsetX(2); $d->setOffsetY(2); $d->setWorksheet($ws);
    $rows = max(1, $bottom);
    for ($r = 1; $r <= $rows; $r++) $ws->getRowDimension($r)->setRowHeight(round(($h + 8) * 0.75 / $rows, 2)); // px → pt
}

/**
 * PDF: the BROB header block with every grade on board, in order. The template's columns are used
 * first (as srHeaderCompact); more grades get columns of their own to the right, styled like the
 * last one, and the unit moves after them. → the last column used (for the print area).
 */
function srHeaderAll(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, array $eff, array $grades, array $data): string {
    $C = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::class; $DT = \PhpOffice\PhpSpreadsheet\Cell\DataType::class;
    $n = srHeaderCols($ws, $eff);
    $first = array_slice($grades, 0, count($n));
    srHeaderCompact($ws, $eff, $first, $data);
    $more = array_slice($grades, count($n));
    if (!$more || !$n) return 'J';
    $last = end($n); $lastIdx = max(array_keys($n));
    // the unit ("M.Tons") right of the block moves after the new columns
    $rows = []; foreach (['label', 'fwe', 'started'] as $k) if ($last[$k]) $rows[$k] = (int)preg_replace('/\D/', '', $last[$k]);
    $unit = [];
    foreach ($rows as $k => $r) for ($c = $lastIdx + 1; $c <= $lastIdx + 3; $c++) {
        $v = $ws->getCell($C::stringFromColumnIndex($c) . $r)->getValue();
        if (is_string($v) && trim($v) !== '' && $v[0] !== '=') { $unit[$r] = [$v, $ws->getCell($C::stringFromColumnIndex($c) . $r)->getXfIndex()]; $ws->getCell($C::stringFromColumnIndex($c) . $r)->setValueExplicit(null, $DT::TYPE_NULL); break; }
    }
    foreach ($more as $i => $g) {
        $col = $C::stringFromColumnIndex($lastIdx + 1 + $i);
        if (!$ws->getColumnDimension($col)->getWidth() || $ws->getColumnDimension($col)->getWidth() < 9) $ws->getColumnDimension($col)->setWidth($ws->getColumnDimension($C::stringFromColumnIndex($lastIdx))->getWidth() ?: 12);
        foreach ($rows as $k => $r) {
            $xf = $ws->getCell($C::stringFromColumnIndex($lastIdx) . $r)->getXfIndex(); // read before the next getCell (cell objects are reused)
            $cell = $ws->getCell($col . $r);
            $cell->setXfIndex($xf);
            if ($k === 'label') { $cell->setValueExplicit(SR_GRADES[$g][0], $DT::TYPE_STRING); continue; }
            $f = $eff['fields']['brob_' . $k . '_' . $g] ?? null;
            if ($f) { $cell->setValue("='" . $f['sheet'] . "'!" . $f['cell']); continue; }
            $v = srVal($data, 'brob_' . $k . '_' . $g);
            if ($v !== '' && is_numeric($v)) $cell->setValue((float)$v); else $cell->setValueExplicit(null, $DT::TYPE_NULL);
        }
    }
    $after = $C::stringFromColumnIndex($lastIdx + 1 + count($more));
    foreach ($unit as $r => [$v, $xf]) { $ws->getCell($after . $r)->setValueExplicit($v, $DT::TYPE_STRING); $ws->getCell($after . $r)->setXfIndex($xf); }
    if ($unit && $ws->getColumnDimension($after)->getWidth() < 9) $ws->getColumnDimension($after)->setWidth(9); // room for "M.Tons"
    return $unit ? $after : $C::stringFromColumnIndex($lastIdx + count($more));
}

/** The BROB header's grade columns of the template: [column index => ['label', 'fwe', 'started' => coordinate]]. */
function srHeaderCols(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, array $eff): array {
    $top = min(array_column($eff['sections'], 'heading')) - 1;
    $norm = fn($v) => str_replace(['$', "'"], '', (string)$v);
    $cols = [];
    foreach (array_keys($eff['sections']) as $slot) {
        $col = ['label' => null, 'fwe' => null, 'started' => null];
        foreach (['fwe', 'started'] as $k) {
            $f = $eff['fields']['brob_' . $k . '_' . $slot] ?? null;
            if (!$f) continue;
            $want = $norm('=' . $f['sheet'] . '!' . $f['cell']);
            foreach ($ws->getRowIterator(1, $top) as $row) foreach ($row->getCellIterator('A', 'L') as $cell) if ($norm($cell->getValue()) === $want) $col[$k] = $cell->getCoordinate();
        }
        $any = $col['fwe'] ?? $col['started'];
        if (!$any) continue;
        [$c] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($any);
        $short = strtoupper(trim($eff['fuel_names'][$slot] ?? $slot));
        for ($r = $top; $r >= 1; $r--) { $v = $ws->getCell($c . $r)->getValue(); if (is_string($v) && strtoupper(trim($v)) === $short) { $col['label'] = $c . $r; break; } }
        $cols[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($c)] = $col;
    }
    ksort($cols);
    return $cols;
}

/**
 * PDF: the ULSFO / HSMGO sections (filled on the copy sheet) placed on TABLES 54B right after the
 * section they use (VLSFO / LSMGO), with their calculated values and the same look; their lines in
 * the totals list under the tables follow the others. Only the shown rows are copied.
 */
function srMergeExtras(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $t, \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $x, array $eff, array $pos, array $cpos, array $extraGrades, string $mtC): void {
    $C = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::class; $DT = \PhpOffice\PhpSpreadsheet\Cell\DataType::class;
    $lastIdx = 12; // A..L
    // the shown value of a cell (formulas calculated) and its style; then written as a plain value
    $val = function (\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, string $coord): array {
        $sc = $ws->getCell($coord); $xf = $sc->getXfIndex(); $v = $sc->getValue();
        if (is_string($v) && $v !== '' && $v[0] === '=') { try { $v = $ws->getCell($coord)->getCalculatedValue(); } catch (Throwable $e) { $v = null; } }
        return [$v, $xf];
    };
    $put = function (string $coord, $v, int $xf) use ($t, $DT) {
        $tc = $t->getCell($coord);
        $tc->setXfIndex($xf);
        if ($v === null || $v === '') $tc->setValueExplicit(null, $DT::TYPE_NULL);
        elseif (is_numeric($v) && !is_string($v)) $tc->setValueExplicit($v, $DT::TYPE_NUMERIC);
        else $tc->setValueExplicit((string)$v, $DT::TYPE_STRING);
    };
    $copyRows = function (array $src, int $at) use ($t, $x, $book, $C, $lastIdx, $val, $put) { // source rows of $x → new rows of $t from $at
        $n = count($src) + 1; // + a blank row before
        $t->insertNewRowBefore($at, $n);
        sfShiftSheetRefs($book, $t->getTitle(), $at, $n);
        for ($r = $at; $r < $at + $n; $r++) $t->getRowDimension($r)->setVisible(true);
        $map = [];
        foreach ($src as $i => $r) $map[$r] = $at + 1 + $i;
        foreach ($map as $r => $to) {
            $h = $x->getRowDimension($r)->getRowHeight(); if ($h > 0) $t->getRowDimension($to)->setRowHeight($h);
            $t->getRowDimension($to)->setVisible(true);
            for ($c = 1; $c <= $lastIdx; $c++) {
                $col = $C::stringFromColumnIndex($c);
                [$v, $xf] = $val($x, $col . $r);
                $put($col . $to, $v, $xf); // (a cell object is reused by the next getCell: read first, then write)
            }
        }
        foreach ($x->getMergeCells() as $rng) {
            [$a, $b] = explode(':', $rng);
            [$ac, $ar] = $C::coordinateFromString($a); [$bc, $br] = $C::coordinateFromString($b);
            if (isset($map[(int)$ar], $map[(int)$br])) $t->mergeCells($ac . $map[(int)$ar] . ':' . $bc . $map[(int)$br]);
        }
    };
    // the totals list lines of the extra grades (labelled with their short name on the copy)
    $shorts = array_map(fn($g) => SR_GRADES[$g][0], $extraGrades);
    $listSrc = [];
    $maxSec = max(array_map(fn($s) => $s['mt_total'], $cpos));
    for ($r = $maxSec + 1; $r <= $x->getHighestRow(); $r++) {
        $v = $x->getCell('B' . $r)->getValue();
        if (is_string($v) && in_array(strtoupper(trim($v)), $shorts, true) && $x->getRowDimension($r)->getVisible()) $listSrc[] = $r;
    }
    // where the totals list is on TABLES 54B: its last shown line (a template grade's short name in column B)
    $tShorts = array_map(fn($s) => strtoupper(trim($eff['fuel_names'][$s] ?? $s)), array_keys($eff['sections']));
    $maxT = max(array_map(fn($s) => $s['mt_total'], $pos));
    $listAt = 0; $listAny = 0;
    for ($r = $maxT + 1; $r <= $t->getHighestRow(); $r++) {
        $v = $t->getCell('B' . $r)->getValue();
        if (!is_string($v) || !in_array(strtoupper(trim($v)), array_merge($tShorts, ['VLSFO', 'HSFO', 'LSMGO']), true)) continue;
        $listAny = $r;
        if ($t->getRowDimension($r)->getVisible()) $listAt = $r;
    }
    if (!$listAt) $listAt = $listAny; // no template grade on board: the extra lines go where the list is
    if ($listSrc && $listAt) { // bottom first, so the section positions above stay right
        $n = count($listSrc);
        $t->insertNewRowBefore($listAt + 1, $n);
        sfShiftSheetRefs($book, $t->getTitle(), $listAt + 1, $n);
        foreach ($listSrc as $i => $r) {
            $to = $listAt + 1 + $i;
            $t->getRowDimension($to)->setVisible(true);
            $h = $x->getRowDimension($r)->getRowHeight(); if ($h > 0) $t->getRowDimension($to)->setRowHeight($h);
            for ($c = 1; $c <= $lastIdx; $c++) {
                $col = $C::stringFromColumnIndex($c);
                $xf = $t->getCell($col . $listAt)->getXfIndex(); // looks like the line above
                [$v] = $val($x, $col . $r);
                $put($col . $to, $v, $xf);
            }
        }
    }
    // the sections, each after the section it uses (lowest first from the bottom)
    $todo = [];
    foreach ($extraGrades as $g) {
        $slot = SR_EXTRA_SLOT[$g]; $sec = $cpos[$slot];
        $rows = [];
        for ($r = $sec['heading']; $r <= $sec['mt_total']; $r++) if ($x->getRowDimension($r)->getVisible()) $rows[] = $r;
        $todo[] = [$pos[$slot]['mt_total'] + 1, $rows];
    }
    usort($todo, fn($a, $b) => $b[0] <=> $a[0]);
    foreach ($todo as [$at, $rows]) if ($rows) $copyRows($rows, $at);
}

function srHideRows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $from, int $to): void {
    for ($r = $from; $r <= $to; $r++) $ws->getRowDimension($r)->setVisible(false);
}

/**
 * The report workbook: the template filled with the chosen grades (the others hidden), a copy of
 * TABLES 54B for ULSFO / HSMGO when chosen, and the ROB SUMMARY sheet.
 * → [book, eff, print sheets, where each grade's section is (sheet, rows)].
 * $forPdf: the book the PDF is printed from — every chosen grade on TABLES 54B (one A4 page) and
 * the certificate; no ROB summary page. The Excel kept in the system is built without it (unchanged).
 */
function srBuildBook(array $tpl, array $data, bool $forPdf = false): array {
    $eff = rtEffective($tpl);
    $grades = srChosenGrades($eff, $data);
    $list = srGradeList($eff);
    $tplGrades = array_values(array_filter($grades, fn($g) => $list[$g]['template']));
    $extraGrades = array_values(array_filter($grades, fn($g) => !$list[$g]['template']));
    [$clean] = rtCleanData($eff, ['fields' => $data['fields'] ?? [], 'tanks' => []]);
    $fill = ['fields' => $clean['fields'], 'tanks' => []];
    foreach (array_keys($eff['sections']) as $s) $fill['tanks'][$s] = in_array($s, $tplGrades, true) ? srTankRows($data, $s) : [];
    $book = rtBuild($tpl, $fill);
    $tn = $eff['tables_sheet'];
    $t = $book->getSheetByName($tn);
    $pos = rtSectionRows($eff, array_map('count', $fill['tanks']));
    $mtC = $eff['sections']['vlsfo']['calc_cols']['mt'];
    $printBase = $eff['print_sheets'];
    $where = [];
    foreach ($tplGrades as $g) $where[$g] = ['sheet' => $tn, 'sec' => $pos[$g]];

    // ULSFO / HSMGO: a copy of the filled TABLES 54B, its sections re-used
    $extraTitle = null;
    if ($extraGrades) {
        $copy = $t->copy();
        $copy->setTitle(SR_EXTRA_SHEET);
        $book->addSheet($copy, $book->getIndex($t) + 1);
        $rowsFor = [];
        foreach (array_keys($eff['sections']) as $s) $rowsFor[$s] = [];
        foreach ($extraGrades as $g) $rowsFor[SR_EXTRA_SLOT[$g]] = srTankRows($data, $g);
        $order = $pos; uasort($order, fn($a, $b) => $b['first'] <=> $a['first']);
        foreach ($order as $s => $sec) rtFillSection($book, $copy, $eff, $sec, $rowsFor[$s]);
        $cpos = rtSectionRows(['sections' => $pos], array_map('count', $rowsFor));
        $used = [];
        foreach ($extraGrades as $g) {
            $slot = SR_EXTRA_SLOT[$g]; $used[] = $slot;
            $where[$g] = ['sheet' => SR_EXTRA_SHEET, 'sec' => $cpos[$slot]];
            $copy->getCell('B' . $cpos[$slot]['heading'])->setValue(SR_GRADES[$g][1] . ' ');
            $mtCell = $mtC . $cpos[$slot]['mt_total'];
            foreach (srFormulaCells($book, [SR_EXTRA_SHEET])[SR_EXTRA_SHEET] as $coord => $f) { // the totals list under the tables ("VLSFO  =J36  MT")
                [$mc, $mr] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($mtCell);
                if (!preg_match('/(?<![A-Z$!\'])\$?' . $mc . '\$?' . $mr . '(?!\d)/', $f)) continue;
                $b = $copy->getCell('B' . preg_replace('/\D/', '', $coord));
                if (is_string($b->getValue()) && stripos($b->getValue(), $list[$slot]['short']) !== false) $b->setValue(SR_GRADES[$g][0]);
            }
        }
        foreach ($cpos as $s => $sec) if (!in_array($s, $used, true)) srHideGrade($book, SR_EXTRA_SHEET, $sec, $mtC, [SR_EXTRA_SHEET]);
        srHeaderCompact($copy, $eff, $extraGrades, $data);
        foreach ($extraGrades as $g) srHideEmptyTankRows($copy, $cpos[SR_EXTRA_SLOT[$g]], count($rowsFor[SR_EXTRA_SLOT[$g]]));
        if (!$forPdf) {
            srFitOnePage($copy);
            srLetterhead($copy);
            srFitA4($copy); // fills the A4 page
        }
        $copy->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $extraTitle = SR_EXTRA_SHEET;
    }
    // grades not on board: their rows are hidden on the report and the certificate
    foreach ($pos as $s => $sec) {
        if (in_array($s, $tplGrades, true)) continue;
        srHideGrade($book, $tn, $sec, $mtC, $printBase);
    }
    if ($forPdf && $extraGrades) $hdr = srHeaderAll($t, $eff, $grades, $data); // the PDF: every grade in the BROB header
    else srHeaderCompact($t, $eff, $tplGrades, $data); // BROB header: the grades on board side by side, no empty column between
    foreach ($tplGrades as $g) srHideEmptyTankRows($t, $pos[$g], count($fill['tanks'][$g])); // only the tanks entered
    if ($forPdf && $extraGrades) srMergeExtras($book, $t, $copy, $eff, $pos, $cpos, $extraGrades, $mtC);
    srFitOnePage($t); // the tank report prints on one A4 page
    $lastCol = $forPdf && !empty($hdr) ? $hdr : 'J';
    if ($lastCol !== 'J') $t->getPageSetup()->setPrintArea(preg_replace('/\$?J\$?(\d+)$/', $lastCol . '$1', preg_replace('/^[^!]*!/', '', str_replace('$', '', $t->getPageSetup()->getPrintArea()))));
    srLetterhead($t, $lastCol);
    srFitA4($t, $lastCol);
    if ($forPdf) { // printed: TABLES 54B (all grades) and the certificate
        if ($extraGrades && ($cx = $book->getSheetByName(SR_EXTRA_SHEET))) $book->removeSheetByIndex($book->getIndex($cx)); // its rows are on TABLES 54B now (LibreOffice would print a hidden sheet that has a print area)
        $order = [];
        foreach ($book->getWorksheetIterator() as $ws) if (in_array($ws->getTitle(), $printBase, true) && ($tplGrades || $extraGrades || $ws->getTitle() !== $tn)) {
            $order[] = $ws->getTitle();
            if ($ws->getTitle() !== $tn) { srLetterhead($ws); srFitA4($ws); }
        }
        return [$book, $eff, $order, $where];
    }
    $summary = srSummarySheet($book, $eff, $data, $grades, $where);
    // printed: the report sheets in workbook order
    $print = $printBase;
    if (!$tplGrades) $print = array_values(array_diff($print, [$tn]));
    if ($extraTitle) $print[] = $extraTitle;
    $print[] = $summary;
    $order = [];
    foreach ($book->getWorksheetIterator() as $ws) if (in_array($ws->getTitle(), $print, true)) {
        $order[] = $ws->getTitle();
        if (!in_array($ws->getTitle(), [$tn, SR_EXTRA_SHEET, $summary], true)) { srLetterhead($ws); srFitA4($ws); } // the certificate: the letterhead, and it fills its A4 page too
        if ($ws->getTitle() === $summary) srFitA4($ws, 'D'); // the ROB summary on one A4 page too (it already has the letterhead)
    }
    return [$book, $eff, $order, $where];
}

/** ROB SUMMARY sheet (after the tank tables): particulars, completed vs log book ROB per grade, total difference, signatures. */
function srSummarySheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $eff, array $data, array $grades, array $where): string {
    $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($book, SR_SUMMARY_SHEET);
    $after = $book->getSheetByName(SR_EXTRA_SHEET) ?: $book->getSheetByName($eff['tables_sheet']);
    $book->addSheet($ws, $book->getIndex($after) + 1);
    $blue = '0F2B5B'; $brand = '00A3DF';
    $ws->getDefaultRowDimension()->setRowHeight(18);
    foreach (['A' => 26, 'B' => 22, 'C' => 22, 'D' => 24] as $c => $w) $ws->getColumnDimension($c)->setWidth($w);
    $ws->getStyle('A1:D80')->getFont()->setName('Arial')->setSize(10);
    $ws->getStyle('A1:D80')->getAlignment()->setVertical('center');
    $banner = SR_LETTERHEAD; // the company letterhead of the survey reports
    if (is_file($banner)) {
        [$bw, $bh] = getimagesize($banner) ?: [961, 248];
        $d = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $d->setPath($banner); $d->setResizeProportional(false); $d->setWidth(668); $d->setHeight((int)round(668 * $bh / $bw)); $d->setCoordinates('A1'); $d->setOffsetX(2); $d->setOffsetY(2); $d->setWorksheet($ws);
        foreach ([1, 2, 3, 4] as $i) $ws->getRowDimension($i)->setRowHeight(round((668 * $bh / $bw) * 0.75 / 4 + 1, 2));
    }
    $ws->mergeCells('A5:D5');
    $ws->setCellValue('A5', 'BUNKER SURVEY — ROB SUMMARY');
    $ws->getStyle('A5')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($blue);
    $ws->getStyle('A5')->getAlignment()->setHorizontal('center');
    $ws->getRowDimension(5)->setRowHeight(26);
    $ws->getStyle('A5:D5')->getBorders()->getBottom()->setBorderStyle('medium')->getColor()->setRGB($brand);

    $num = fn($k) => is_numeric(srVal($data, $k)) ? (float)srVal($data, $k) : null;
    $dt = fn($p) => trim((srVal($data, $p . '_date') !== '' ? sfDateText(srVal($data, $p . '_date')) : '') . '   ' . srVal($data, $p . '_time') . (srVal($data, $p . '_time') !== '' ? ' Hrs LT' : ''));
    $fa = $num('draft_aft'); $ff = $num('draft_fwd');
    $r = 7;
    $text = function (string $label, string $v) use ($ws, &$r) {
        $ws->setCellValue('A' . $r, $label); $ws->getStyle('A' . $r)->getFont()->setBold(true)->getColor()->setRGB('475569');
        $ws->mergeCells("B$r:D$r");
        $ws->setCellValueExplicit('B' . $r, $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->getStyle('B' . $r)->getAlignment()->setWrapText(true);
        if (mb_strlen($v) > 60) $ws->getRowDimension($r)->setRowHeight(30);
        $r++;
    };
    $text('Vessel', srVal($data, 'vessel_name') . (srVal($data, 'imo') !== '' ? '   (IMO No. ' . srVal($data, 'imo') . ')' : ''));
    $text('Flag', srVal($data, 'flag'));
    $text('Port Name', srVal($data, 'port_name'));
    $text('Place of Survey', srVal($data, 'place'));
    $text('Voyage No.', srVal($data, 'voyage_no'));
    $text('FWE', $dt('fwe'));
    $text('Survey Started', $dt('started'));
    $text('Survey Completed', $dt('completed'));
    // drafts: two per row, Mean draft and Trim (Aft − Fwd, as the template's Trim) as formulas
    $pair = function (string $l1, $v1, string $l2, $v2) use ($ws, &$r) {
        foreach ([['A', $l1], ['C', $l2]] as [$c, $l]) { $ws->setCellValue($c . $r, $l); $ws->getStyle($c . $r)->getFont()->setBold(true)->getColor()->setRGB('475569'); }
        foreach ([['B', $v1], ['D', $v2]] as [$c, $v]) {
            if ($v === null || $v === '') continue;
            $ws->setCellValue($c . $r, $v);
            $ws->getStyle($c . $r)->getNumberFormat()->setFormatCode('0.000');
            $ws->getStyle($c . $r)->getAlignment()->setHorizontal('left');
        }
        $r++;
    };
    $dr = $r;
    $pair('Draft Fwd (m)', $ff, 'Draft Aft (m)', $fa);
    $both = $fa !== null && $ff !== null;
    $pair('Mean Draft (m)', $both ? "=ROUND((B$dr+D$dr)/2,3)" : null, 'Trim (m)', $both ? "=ROUND(D$dr-B$dr,3)" : null);
    $pair('List (degrees)', $num('list'), '', null);
    if ($num('list') !== null) $ws->getStyle('B' . ($r - 1))->getNumberFormat()->setFormatCode('0.00');
    // ROB comparison
    $r++;
    $head = $r;
    foreach (['A' => 'Fuel Grade', 'B' => 'Survey Completed ROB (MT)', 'C' => 'Log Book ROB (MT)', 'D' => 'Difference (MT)'] as $c => $h) $ws->setCellValue($c . $r, $h);
    $ws->getStyle("A$r:D$r")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle("A$r:D$r")->getFill()->setFillType('solid')->getStartColor()->setRGB($blue);
    $ws->getStyle("A$r:D$r")->getAlignment()->setWrapText(true)->setHorizontal('center')->setVertical('center');
    $ws->getRowDimension($r)->setRowHeight(30);
    $mtC = $eff['sections']['vlsfo']['calc_cols']['mt'];
    $first = $r + 1;
    foreach ($grades as $g) {
        if (!isset($where[$g])) continue;
        $r++;
        $ws->setCellValue('A' . $r, SR_GRADES[$g][0]);
        $ws->setCellValue('B' . $r, "='" . $where[$g]['sheet'] . "'!" . $mtC . $where[$g]['sec']['mt_total']);
        $lk = 'brob_started_' . $g;
        if (isset($eff['fields'][$lk])) $ws->setCellValue('C' . $r, "='" . $eff['fields'][$lk]['sheet'] . "'!" . $eff['fields'][$lk]['cell']);
        elseif (is_numeric(srVal($data, $lk))) $ws->setCellValue('C' . $r, (float)srVal($data, $lk));
        $ws->setCellValue('D' . $r, "=ROUND(B$r-C$r,3)");
    }
    $last = $r;
    $r++;
    $ws->setCellValue('A' . $r, 'TOTAL DIFFERENCE');
    $ws->mergeCells("A$r:C$r");
    $ws->setCellValue('D' . $r, $last >= $first ? "=ROUND(SUM(D$first:D$last),3)" : 0);
    $ws->getStyle("A$r:D$r")->getFont()->setBold(true)->setSize(11);
    $ws->getStyle("A$r:D$r")->getFill()->setFillType('solid')->getStartColor()->setRGB('E6F6FC');
    $ws->getStyle("A$r")->getAlignment()->setHorizontal('right');
    $ws->getStyle("B$first:D$r")->getNumberFormat()->setFormatCode('0.000');
    $ws->getStyle("D$first:D$r")->getNumberFormat()->setFormatCode('+0.000;-0.000;0.000');
    $ws->getStyle("B$first:D$r")->getAlignment()->setHorizontal('right');
    $ws->getStyle("A$head:D$r")->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('94A3B8');
    $r += 2;
    $ws->mergeCells("A$r:D$r");
    $ws->setCellValue('A' . $r, 'Survey Completed ROB = sum of the metric tons (in air) of the tanks of each grade (TABLES 54B). Log Book ROB = ROB at the survey start as per the engine-room log book. Difference = Survey Completed − Log Book.');
    $ws->getStyle('A' . $r)->getAlignment()->setWrapText(true)->setVertical('top');
    $ws->getStyle('A' . $r)->getFont()->setSize(8.5)->setItalic(true)->getColor()->setRGB('64748B');
    $ws->getRowDimension($r)->setRowHeight(36);
    // signatures
    $r += 4;
    $sig = [['A', 'B', 'The Master', 'Capt. ' . srVal($data, 'master'), ''], ['C', 'D', 'For YMR MARINE SOLUTIONS LLP', 'Mr. ' . srVal($data, 'surveyor_name'), 'Independent Marine Surveyor']];
    foreach ([[$sig[0], $sig[1]], [['A', 'B', 'The Chief Engineer', 'Mr. ' . srVal($data, 'chief_engineer'), '']]] as $line) {
        foreach ($line as [$c1, $c2, $role, $name, $sub]) {
            $ws->getStyle("$c1$r:$c2$r")->getBorders()->getTop()->setBorderStyle('thin');
            foreach ([[$r, $role], [$r + 1, $name], [$r + 2, $sub]] as [$rr, $txt]) {
                if ($txt === '') continue;
                $ws->mergeCells("$c1$rr:$c2$rr");
                $ws->setCellValueExplicit($c1 . $rr, $txt, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $ws->getStyle($c1 . $r)->getFont()->setBold(true);
            if ($sub !== '') $ws->getStyle($c1 . ($r + 2))->getFont()->setSize(9)->getColor()->setRGB('64748B');
        }
        $r += 6;
    }
    $r -= 4;
    $ps = $ws->getPageSetup();
    $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setOrientation('portrait')->setFitToWidth(1)->setFitToHeight(0);
    $ps->setPrintArea('A1:D' . ($r + 2));
    $ws->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.6)->setRight(0.6);
    $ws->setShowGridlines(false);
    return SR_SUMMARY_SHEET;
}

/** Results calculated on the server from the workbook's own formulas: per tank and per grade, ROB comparison. */
function srResults(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $eff, array $data, array $where): array {
    $cc = $eff['sections']['vlsfo']['calc_cols']; $wcfCol = $eff['calc']['wcf_col'] ?? null;
    $out = ['tanks' => [], 'grades' => [], 'total_difference' => 0.0];
    foreach ($where as $g => $w) {
        $ws = $book->getSheetByName($w['sheet']); $sec = $w['sec'];
        $n = count(srTankRows($data, $g));
        $out['tanks'][$g] = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $sec['first'] + $i;
            $v = fn($c) => $c ? sfNum($ws->getCell($c . $r)->getCalculatedValue()) : null;
            $out['tanks'][$g][] = ['vcf' => $v($cc['vcf']), 'wcf' => $v($wcfCol), 'gsv' => $v($cc['gsv']), 'mt' => $v($cc['mt'])];
        }
        $done = sfNum($ws->getCell($cc['mt'] . $sec['mt_total'])->getCalculatedValue()) ?? 0.0;
        $gsv = sfNum($ws->getCell($cc['gsv'] . $sec['gsv_total'])->getCalculatedValue()) ?? 0.0;
        $log = is_numeric(srVal($data, 'brob_started_' . $g)) ? (float)srVal($data, 'brob_started_' . $g) : null;
        $diff = $log === null ? null : round($done - $log, 3);
        $out['grades'][$g] = ['gsv' => round($gsv, 3), 'completed' => round($done, 3), 'logbook' => $log, 'difference' => $diff];
        if ($diff !== null) $out['total_difference'] += $diff;
    }
    $out['total_difference'] = round($out['total_difference'], 3);
    return $out;
}


// ================================================================ tank version history (Admin only)

/**
 * Every saved tank keeps an immutable version history of its four readings — Sounding, Volume,
 * Density, Temperature (survey_tank_entry_history). Version 1 is written when the tank is first
 * saved; a new version only when at least one of the four changes (values compared after
 * normalising numbers); remarks or any other field never make a version. Written in the same
 * transaction as the report's current values. Rows are never updated or deleted by the app.
 */
const SR_TRACKED = ['sounding' => 'Sounding', 'volume' => 'Volume', 'density' => 'Density', 'temperature' => 'Temperature'];

function srEnsureHistory(PDO $db): void {
    static $done = false;
    if ($done) return;
    rtEnsureTables($db);
    $cols = "`id` int(11) NOT NULL AUTO_INCREMENT,
        `report_id` int(11) NOT NULL, `survey_id` int(11) DEFAULT NULL, `grade` varchar(10) NOT NULL, `tank_id` varchar(40) NOT NULL,
        `tank_name` varchar(100) NOT NULL, `version_number` int(11) NOT NULL,
        `sounding` varchar(30) DEFAULT NULL, `volume` decimal(14,3) DEFAULT NULL, `density` decimal(8,4) DEFAULT NULL, `temperature` decimal(6,1) DEFAULT NULL,
        `changed_by` int(11) DEFAULT NULL, `changed_at` datetime NOT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_steh_tank_version` (`report_id`, `tank_id`, `version_number`),
        KEY `idx_steh_survey` (`survey_id`), KEY `idx_steh_tank` (`tank_id`)";
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `survey_tank_entry_history` ($cols,
            CONSTRAINT `fk_steh_report` FOREIGN KEY (`report_id`) REFERENCES `survey_reports` (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { // foreign key not possible on this server: the table without it
        $db->exec("CREATE TABLE IF NOT EXISTS `survey_tank_entry_history` ($cols) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $done = true;
}

/** A tracked value as compared and stored: numbers rounded to their decimals, text in capitals, blank → null. */
function srTrackNorm($v, int $dec): ?string {
    $v = trim(str_replace(',', '', (string)$v));
    if ($v === '') return null;
    if (is_numeric($v)) return number_format(round((float)$v, $dec), $dec, '.', '');
    return mb_strtoupper(preg_replace('/\s+/u', ' ', $v));
}
/** The four tracked readings of a tank row (sounding = typed, or reference height − ullage). */
function srTracked(array $row): array {
    return ['sounding' => srTrackNorm(srSounding($row), 3), 'volume' => srTrackNorm($row['volume'] ?? '', 3),
        'density' => srTrackNorm($row['density'] ?? '', 4), 'temperature' => srTrackNorm($row['temp'] ?? '', 1)];
}

/**
 * Write the versions for the saved tanks of $data. Must run inside the transaction that stores
 * the report's current values. A tank counts as saved when "Save tank" was used (done = 1), or
 * for every tank when the report is made ($final). → number of versions written.
 */
function srTankVersionsApply(PDO $db, array $report, array $data, bool $final, int $uid): int {
    srEnsureHistory($db);
    $last = $db->prepare('SELECT version_number, sounding, volume, density, temperature FROM survey_tank_entry_history
        WHERE report_id = ? AND tank_id = ? ORDER BY version_number DESC LIMIT 1 FOR UPDATE');
    $ins = $db->prepare('INSERT INTO survey_tank_entry_history (report_id, survey_id, grade, tank_id, tank_name, version_number, sounding, volume, density, temperature, changed_by, changed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $now = date('Y-m-d H:i:s');
    $n = 0;
    foreach ((array)($data['tanks'] ?? []) as $g => $rows) {
        foreach ((array)$rows as $row) {
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['id'] ?? ''));
            if ($id === '' || (($row['done'] ?? '') !== '1' && !$final)) continue; // a copied tank: its version 1 when it is saved
            $t = srTracked($row);
            if ($t === array_fill_keys(array_keys(SR_TRACKED), null)) continue;
            $last->execute([$report['id'], $id]);
            $prev = $last->fetch(PDO::FETCH_ASSOC);
            if ($prev) {
                $p = ['sounding' => srTrackNorm($prev['sounding'], 3), 'volume' => srTrackNorm($prev['volume'], 3), 'density' => srTrackNorm($prev['density'], 4), 'temperature' => srTrackNorm($prev['temperature'], 1)];
                if ($p === $t) continue; // same readings: no new version
            }
            $ins->execute([$report['id'], $report['survey_id'] ?: null, $g, $id, mb_substr(trim((string)($row['name'] ?? '')), 0, 100), $prev ? (int)$prev['version_number'] + 1 : 1,
                $t['sounding'], $t['volume'], $t['density'], $t['temperature'], $uid ?: null, $now]);
            $n++;
        }
    }
    return $n;
}

/**
 * The history of a report's tanks, for the Admin: every tank with its versions (oldest first,
 * the values that changed against the version before marked), its current values in the report,
 * and whether it is still in the report.
 */
function srTankHistory(PDO $db, array $report): array {
    srEnsureHistory($db);
    $q = $db->prepare('SELECT h.*, u.full_name AS changed_by_name FROM survey_tank_entry_history h LEFT JOIN users u ON u.id = h.changed_by
        WHERE h.report_id = ? ORDER BY h.tank_id, h.version_number');
    $q->execute([$report['id']]);
    $cur = [];
    foreach ((array)($report['data']['tanks'] ?? []) as $g => $rows) foreach ((array)$rows as $i => $row) {
        if (!empty($row['id'])) $cur[$row['id']] = ['grade' => $g, 'pos' => $i + 1, 'name' => (string)($row['name'] ?? ''), 'values' => srTracked($row)];
    }
    $tanks = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $id = $h['tank_id'];
        if (!isset($tanks[$id])) $tanks[$id] = ['tank_id' => $id, 'grade' => $h['grade'], 'grade_label' => SR_GRADES[$h['grade']][0] ?? strtoupper($h['grade']), 'versions' => []];
        $vals = ['sounding' => srTrackNorm($h['sounding'], 3), 'volume' => srTrackNorm($h['volume'], 3), 'density' => srTrackNorm($h['density'], 4), 'temperature' => srTrackNorm($h['temperature'], 1)];
        $prev = end($tanks[$id]['versions']) ?: null;
        $tanks[$id]['versions'][] = ['version' => (int)$h['version_number'], 'name' => $h['tank_name'], 'values' => $vals,
            'changed' => $prev ? array_keys(array_diff_assoc($vals, $prev['values'])) : [],
            'by' => (string)$h['changed_by_name'], 'at' => $h['changed_at']];
        $tanks[$id]['name'] = $h['tank_name'];
    }
    foreach ($tanks as $id => &$t) {
        $t['current_version'] = end($t['versions'])['version'];
        $t['in_report'] = isset($cur[$id]);
        if (isset($cur[$id])) { $t['name'] = $cur[$id]['name'] ?: $t['name']; $t['pos'] = $cur[$id]['pos']; $t['current'] = $cur[$id]['values']; }
    }
    unset($t);
    $order = array_flip(array_keys(SR_GRADES));
    usort($tanks, fn($a, $b) => [$order[$a['grade']] ?? 9, $a['pos'] ?? 999, $a['name']] <=> [$order[$b['grade']] ?? 9, $b['pos'] ?? 999, $b['name']]);
    return $tanks;
}

/**
 * "Start Survey" on a vessel: its report is opened — the existing draft when there is one,
 * otherwise a new one on the chosen type's active template. The vessel row is locked meanwhile,
 * so repeated clicks never make two reports. → ['ok', 'id', 'created'] | ['ok' => false, 'error'].
 */
function srStart(PDO $db, array $survey, string $kind, int $uid): array {
    rtEnsureTables($db);
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM surveys WHERE id = ? FOR UPDATE')->execute([$survey['id']]);
        $q = $db->prepare('SELECT id FROM survey_reports WHERE survey_id = ? ORDER BY id LIMIT 1');
        $q->execute([$survey['id']]);
        if ($id = (int)$q->fetchColumn()) { $db->commit(); return ['ok' => true, 'id' => $id, 'created' => false]; }
        if (!isset(RT_KINDS[$kind])) { $db->rollBack(); return ['ok' => false, 'error' => 'Choose the report type.']; }
        $t = rtActive($db, $kind);
        if (!$t) { $db->rollBack(); return ['ok' => false, 'error' => 'No ' . RT_KINDS[$kind] . ' template is uploaded yet. An Admin uploads it under Report Templates.']; }
        $now = date('Y-m-d H:i:s');
        $db->prepare('INSERT INTO survey_reports (survey_id, template_id, kind, data, version, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)')
           ->execute([$survey['id'], $t['id'], $kind, json_encode(srDefaults(rtEffective($t), $survey)), $uid ?: null, $now, $uid ?: null, $now]);
        $id = (int)$db->lastInsertId();
        $db->commit();
        return ['ok' => true, 'id' => $id, 'created' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** A report not tied to a vessel (the report page opened without one): the active template of the kind, starting values only. */
function srNew(PDO $db, string $kind, int $uid): array {
    rtEnsureTables($db);
    if (!isset(RT_KINDS[$kind])) return ['ok' => false, 'error' => 'Choose the report type.'];
    $t = rtActive($db, $kind);
    if (!$t) return ['ok' => false, 'error' => 'No ' . RT_KINDS[$kind] . ' template is uploaded yet. An Admin uploads it under Report Templates.'];
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO survey_reports (survey_id, template_id, kind, data, version, created_by, created_at, updated_by, updated_at) VALUES (NULL, ?, ?, ?, 1, ?, ?, ?, ?)')
       ->execute([$t['id'], $kind, json_encode(srDefaults(rtEffective($t), null)), $uid ?: null, $now, $uid ?: null, $now]);
    return ['ok' => true, 'id' => (int)$db->lastInsertId(), 'created' => true];
}

/** The report of a vessel (null when no survey was started). */
function srReportForSurvey(PDO $db, int $surveyId): ?array {
    rtEnsureTables($db);
    $q = $db->prepare('SELECT id FROM survey_reports WHERE survey_id = ? ORDER BY id LIMIT 1');
    $q->execute([$surveyId]);
    $id = (int)$q->fetchColumn();
    return $id ? srReport($db, $id) : null;
}

/**
 * Live preview: the report as it would print now, made from the saved values into a temporary
 * PDF (kept only in the server's temp folder, reused while nothing changes). Nothing is stored
 * with the report, no number is given, the vessel's status is not touched. → PDF path | error.
 */
function srPreviewPdf(array $tpl, array $report): array {
    $dir = sys_get_temp_dir() . '/ysms_sr_preview';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    foreach (glob($dir . '/*') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);
    require_once __DIR__ . '/survey_report_pdf.php';
    $own = srOwnPdfAvailable();
    $key = ($own ? 'y' : '') . $report['id'] . '-' . $report['version'] . '-' . $tpl['id'] . '-' . substr(sha1(json_encode($report['data'])), 0, 12);
    $pdf = $dir . '/' . $key . '.pdf';
    if (is_file($pdf)) return ['ok' => true, 'pdf' => $pdf];
    if ($own) { // the same PDF as the download: YMR's own format on the letterhead
        $tmp = $dir . '/' . $key . '.' . bin2hex(random_bytes(4)) . '.pdf';
        try {
            $p = srOwnPdf($tpl, $report, $tmp);
            if (!$p['ok']) return ['ok' => false, 'error' => $p['error']];
            if (!@rename($tmp, $pdf) && !is_file($pdf)) return ['ok' => false, 'error' => 'The preview could not be stored.'];
            return ['ok' => true, 'pdf' => $pdf];
        } finally { @unlink($tmp); }
    }
    [$book, , $print] = srBuildBook($tpl, $report['data'], true); // as the PDF prints
    // own temp names, then an atomic rename: two people previewing the same version at once never clash
    $tmp = $dir . '/' . $key . '.' . bin2hex(random_bytes(4));
    try {
        rtSave($book, $tmp . '.xlsx', rtAbs($tpl['file_path']));
        $p = rtPdf($tmp . '.xlsx', $print, $tmp . '.pdf');
        if (!$p['ok']) return ['ok' => false, 'error' => $p['error']];
        if (!@rename($tmp . '.pdf', $pdf) && !is_file($pdf)) return ['ok' => false, 'error' => 'The preview could not be stored.'];
        return ['ok' => true, 'pdf' => $pdf];
    } finally {
        @unlink($tmp . '.xlsx'); @unlink($tmp . '.pdf');
    }
}

// ================================================================ fill from a previous attendance

/** Vessel name compared loosely: "MV. X", "M.V X" and "x" are the same vessel. */
function srVesselKey(string $name): string {
    $n = mb_strtoupper(trim($name));
    $n = preg_replace('/^M\.?\s*V\.?\s*/u', '', $n);
    return preg_replace('/[^A-Z0-9]+/u', '', $n);
}

/**
 * What an earlier attendance of the same vessel knows, for "Fill from previous attendance": vessel
 * particulars (step 1), the grades on board (step 2) and the tank names with reference heights
 * (step 3) — never readings, dates or ROB. Matched by IMO, else by vessel name.
 *   1. an earlier Survey Report made on the website (newest first);
 *   2. else the Formal Report Excel uploaded for an earlier survey of the vessel, read with the
 *      template analysis (the company format) — values that do not pass the field checks are left out.
 * → null, or ['source', 'label', 'fields', 'grades', 'tanks' => [grade => [[name, ref_height]]]].
 */
function srPrevious(PDO $db, array $report): ?array {
    require_once __DIR__ . '/vessel_history.php';
    $tpl = rtTemplate($db, $report['template_id']);
    if (!$tpl) return null;
    $eff = rtEffective($tpl);
    $imo = vesselHistoryImo(($report['data']['fields']['imo'] ?? '') ?: ($report['survey']['imo_number'] ?? ''));
    $imo2 = vesselHistoryImo($report['survey']['imo_number'] ?? '');
    $keys = array_values(array_unique(array_filter([
        vesselHistoryKey((string)($report['data']['fields']['vessel_name'] ?? '')),
        vesselHistoryKey((string)($report['survey']['vessel_name'] ?? '')),
    ])));
    $imos = array_values(array_unique(array_filter([$imo, $imo2])));
    if (!$keys && !$imos) return null;
    // the same vessel: the same IMO, or the same name (MV./M.V. prefix, case and punctuation ignored) — as "Previous surveys of this vessel"
    $same = function ($i, $n) use ($keys, $imos): bool {
        $i = vesselHistoryImo($i);
        if ($i !== '' && in_array($i, $imos, true)) return true;
        $k = vesselHistoryKey((string)$n);
        return $k !== '' && in_array($k, $keys, true);
    };
    $vesselKeys = array_merge(['imo'], SR_VESSEL_KEYS, ['owners']);
    $grades = array_keys(srGradeList($eff));

    // 1. an earlier report on the website
    $q = $db->prepare('SELECT r.id, r.kind, r.data, r.updated_at, s.vessel_name, s.imo_number FROM survey_reports r LEFT JOIN surveys s ON s.id = r.survey_id
        WHERE r.id <> ? ORDER BY r.updated_at DESC LIMIT 1000');
    $q->execute([$report['id']]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        if (!$same($d['fields']['imo'] ?? '', $d['fields']['vessel_name'] ?? '') && !$same($r['imo_number'], $r['vessel_name'])) continue;
        $tanks = [];
        foreach ($grades as $g) foreach ((array)($d['tanks'][$g] ?? []) as $row) {
            $n = trim((string)($row['name'] ?? ''));
            if ($n !== '') $tanks[$g][] = ['name' => $n, 'ref_height' => trim((string)($row['ref_height'] ?? ''))];
        }
        $fields = [];
        foreach ($vesselKeys as $k) { $v = trim((string)($d['fields'][$k] ?? '')); if ($v !== '') $fields[$k] = $v; }
        if (!$tanks && count($fields) < 3) continue;
        $gs = array_values(array_filter((array)($d['grades'] ?? []), fn($g) => in_array($g, $grades, true)));
        return ['source' => 'report', 'label' => (RT_KINDS[$r['kind']] ?? $r['kind']) . ' report, ' . date('d M Y', strtotime((string)$r['updated_at'])),
            'fields' => $fields, 'grades' => $gs ?: array_keys($tanks), 'tanks' => $tanks];
    }

    // 2. the Excel uploaded for an earlier survey of the vessel (surveys found as in "Previous surveys of this vessel")
    $ids = [];
    foreach (array_merge($keys ?: [''], []) as $k) {
        $name = $k !== '' ? $k : '';
        foreach ($imos ?: [''] as $i) $ids = array_merge($ids, vesselHistoryIds($db, $name, $i, (int)($report['survey_id'] ?? 0)));
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return null;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $db->prepare("SELECT u.id, u.file_path, u.file_name, s.assign_date, s.survey_completed_date FROM uploads u JOIN surveys s ON s.id = u.survey_id
        WHERE u.survey_id IN ($in) AND (u.file_type = 'Formal Report Excel' OR LOWER(u.file_name) LIKE '%.xlsx' OR LOWER(u.file_name) LIKE '%.xlsm' OR LOWER(u.file_name) LIKE '%.xls')
        ORDER BY COALESCE(s.survey_completed_date, s.assign_date) DESC, u.id DESC LIMIT 20");
    $q->execute($ids);
    $tried = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (!preg_match('/\.(xlsx|xlsm|xls)$/i', (string)$u['file_path'])) continue;
        if (++$tried > 6) break;
        $abs = realpath(dirname(__DIR__) . '/' . ltrim((string)$u['file_path'], '/'));
        $root = realpath(dirname(__DIR__) . '/uploads');
        if (!$abs || !$root || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs) || filesize($abs) > 25 * 1024 * 1024) continue;
        try {
            $book = sfLoadSpreadsheet($abs);
            $a = rtAnalyze($book);
            $map = !empty($a['ok']) ? $a : $eff; // a workbook the analysis does not recognise: read it with the template's own cells
            $got = srReadPrevious($book, $map, $eff, $vesselKeys, $grades);
            if ((!$got['tanks'] && count($got['fields']) < 3) && $map !== $eff) $got = srReadPrevious($book, $eff, $eff, $vesselKeys, $grades);
            $book->disconnectWorksheets();
            if (!$got['tanks'] && count($got['fields']) < 3) continue;
            $when = $u['survey_completed_date'] ?: $u['assign_date'];
            return ['source' => 'excel', 'label' => 'Excel of the survey on ' . date('d M Y', strtotime((string)$when)) . ' (' . $u['file_name'] . ')',
                'fields' => $got['fields'], 'grades' => array_keys($got['tanks']), 'tanks' => $got['tanks']];
        } catch (Throwable $e) {
            error_log('srPrevious excel ' . (int)$u['id'] . ': ' . $e->getMessage());
        }
    }
    return null;
}

/** Particulars (checked with the field rules) and tank names + heights of an earlier workbook, read with the layout $map. */
function srReadPrevious(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $map, array $eff, array $vesselKeys, array $grades): array {
    $fields = [];
    foreach ($vesselKeys as $k) {
        $f = $map['fields'][$k] ?? null; $ws = $f ? $book->getSheetByName($f['sheet']) : null;
        if (!$ws) continue;
        try { $v = $ws->getCell($f['cell'])->getCalculatedValue(); } catch (Throwable $e) { continue; }
        if ($v === null || is_array($v) || trim((string)$v) === '') continue;
        [$clean, $err] = sfValidate($eff['fields'][$k]['type'] ?? $f['type'], (string)$v);
        if ($err === '' && $clean !== '') $fields[$k] = (string)$clean;
    }
    $tanks = [];
    $tws = $book->getSheetByName($map['tables_sheet'] ?? '');
    foreach ((array)($map['sections'] ?? []) as $g => $sec) {
        if (!$tws || !in_array($g, $grades, true)) continue;
        for ($r = $sec['first']; $r <= $sec['last']; $r++) {
            try { $n = trim((string)$tws->getCell($sec['cols']['name'] . $r)->getCalculatedValue()); $h = $tws->getCell($sec['cols']['ref_height'] . $r)->getCalculatedValue(); } catch (Throwable $e) { continue; }
            if ($n === '' || preg_match('/^(tank|total|name)\b/i', $n) || mb_strlen($n) > 40) continue;
            $tanks[$g][] = ['name' => mb_strtoupper($n), 'ref_height' => is_numeric($h) ? rtrim(rtrim(number_format((float)$h, 3, '.', ''), '0'), '.') : mb_strtoupper(trim((string)$h))];
        }
    }
    return ['fields' => $fields, 'tanks' => $tanks];
}

// ================================================================ Excel view, tank import

/** The report's printed sheets as HTML (the "Excel View" tab): made from the saved values, nothing is stored. */
function srExcelViewHtml(array $tpl, array $report): string {
    [$book, , $print] = srBuildBook($tpl, $report['data']);
    $html = '';
    foreach ($print as $title) {
        $ws = $book->getSheetByName($title);
        if (!$ws) continue;
        $w = new \PhpOffice\PhpSpreadsheet\Writer\Html($book);
        $w->setSheetIndex($book->getIndex($ws));
        $w->setEmbedImages(true);
        if ($html === '') $html .= '<style>' . $w->generateStyles(false) . '</style>';
        $html .= '<h4 class="xv-sheet">' . htmlspecialchars($title) . '</h4>' . $w->generateSheetData();
    }
    $book->disconnectWorksheets();
    return $html;
}

/**
 * "Import from Excel": tank rows from an uploaded workbook — the company report format (read with
 * the template analysis) or a plain table with a header row (Tank, Ref. height, Sounding, Ullage,
 * Volume, Density, Temp, Grade/Type). → ['ok', 'rows' => [[grade, name, ref_height, sounding, ullage, volume, density, temp]]].
 */
function srImportTanks(string $path, array $eff, array $chosen): array {
    $book = sfLoadSpreadsheet($path);
    $grades = array_keys(srGradeList($eff));
    $rows = [];
    $a = rtAnalyze($book);
    if (!empty($a['ok']) && ($ws = $book->getSheetByName($a['tables_sheet']))) {
        foreach ((array)$a['sections'] as $g => $sec) {
            if (!in_array($g, $grades, true)) continue;
            for ($r = $sec['first']; $r <= $sec['last']; $r++) {
                $v = fn($c) => trim((string)$ws->getCell($sec['cols'][$c] . $r)->getCalculatedValue());
                $n = $v('name');
                if ($n === '' || preg_match('/^(tank|total)\b/i', $n)) continue;
                $rows[] = ['grade' => $g, 'name' => mb_strtoupper($n), 'ref_height' => $v('ref_height'), 'sounding' => $v('sounding'), 'ullage' => '', 'volume' => $v('volume'), 'density' => $v('density'), 'temp' => $v('temp')];
            }
        }
    }
    if (!$rows) { // a plain table
        $ws = $book->getActiveSheet();
        $data = $ws->toArray(null, true, false, false);
        $map = []; $hdr = -1;
        foreach (array_slice($data, 0, 15, true) as $ri => $line) {
            $m = [];
            foreach ($line as $ci => $cell) {
                $t = strtolower(preg_replace('/[^a-z]/i', '', (string)$cell));
                if ($t === '') continue;
                if (!isset($m['name']) && preg_match('/^(tank|tankname|name)/', $t)) $m['name'] = $ci;
                elseif (!isset($m['ref_height']) && preg_match('/^(ref|refheight|referenceheight|totalheight|height)/', $t)) $m['ref_height'] = $ci;
                elseif (!isset($m['ullage']) && strpos($t, 'ullage') === 0) $m['ullage'] = $ci;
                elseif (!isset($m['sounding']) && preg_match('/^(sounding|sdg|sound)/', $t)) $m['sounding'] = $ci;
                elseif (!isset($m['volume']) && preg_match('/^(volume|vol)/', $t)) $m['volume'] = $ci;
                elseif (!isset($m['density']) && preg_match('/^(density|dens)/', $t)) $m['density'] = $ci;
                elseif (!isset($m['temp']) && preg_match('/^(temp|temperature)/', $t)) $m['temp'] = $ci;
                elseif (!isset($m['grade']) && preg_match('/^(grade|type|fuel|product)/', $t)) $m['grade'] = $ci;
            }
            if (isset($m['name']) && count($m) >= 3) { $map = $m; $hdr = $ri; break; }
        }
        if ($hdr < 0) return ['ok' => false, 'error' => 'No tank table found. Use the company report format, or a sheet with a header row: Tank, Ref. Height, Sounding (or Ullage), Volume, Density, Temp, Grade.'];
        $default = $chosen[0] ?? ($grades[0] ?? 'vlsfo');
        foreach (array_slice($data, $hdr + 1) as $line) {
            $get = fn($k) => isset($map[$k]) ? trim((string)($line[$map[$k]] ?? '')) : '';
            $n = $get('name');
            if ($n === '' || preg_match('/^total\b/i', $n)) continue;
            $gt = strtoupper(preg_replace('/[^a-z]/i', '', $get('grade'))); $g = $default;
            foreach (SR_GRADES as $k => [$short]) if ($gt !== '' && $gt === $short && in_array($k, $grades, true)) $g = $k;
            $rows[] = ['grade' => $g, 'name' => mb_strtoupper($n), 'ref_height' => $get('ref_height'), 'sounding' => $get('sounding'), 'ullage' => $get('ullage'), 'volume' => $get('volume'), 'density' => $get('density'), 'temp' => $get('temp')];
            if (count($rows) >= 200) break;
        }
    }
    $book->disconnectWorksheets();
    foreach ($rows as &$r) foreach ($r as $k => $v) $r[$k] = mb_substr((string)$v, 0, 60);
    unset($r);
    return $rows ? ['ok' => true, 'rows' => $rows] : ['ok' => false, 'error' => 'No tanks found in this workbook.'];
}
