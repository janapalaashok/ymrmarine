<?php
/**
 * Completed Vessel Entry (Admin): one already-completed survey typed in by hand on the Assign
 * Vessel form (assign_vessel.php?completed=1), saved straight into Completed Vessels.
 *
 * Same record as every other completed survey: one surveys row with status 'Completed' +
 * survey_surveyors rows + optional uploads. Report number, duplicate checks and the
 * "NOT APPLICABLE" recovery rule are the ones the Excel import of completed vessels uses
 * (includes/completed_import_helpers.php): Not Applicable = no recovery stored (all NULL),
 * which Completed Vessels shows as NOT APPLICABLE and counts as 0.
 * No emails / notifications are sent.
 */
require_once __DIR__ . '/bulk_assign_helpers.php';
require_once __DIR__ . '/completed_import_helpers.php';
require_once __DIR__ . '/pdf_compress.php';
require_once dirname(__DIR__, 2) . '/includes/upload_validation.php';

/** Survey type ids whose recovery is always NOT APPLICABLE (for the form). */
function completedEntryNaTypeIds(array $surveyTypes): array {
    $na = array_map(fn($n) => strtolower(trim((string)$n)), RECOVERY_NOT_APPLICABLE_TYPES);
    $ids = [];
    foreach ($surveyTypes as $t) if (in_array(strtolower(trim((string)$t['type_name'])), $na, true)) $ids[] = (int)$t['id'];
    return $ids;
}

/** 'YYYY-MM-DD' when it is a real date, else null. */
function completedEntryDate($raw): ?string {
    $raw = trim((string)$raw);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $raw : null;
}

/** Non-negative quantity: [ok, ?float]; blank → [true, null]. */
function completedEntryQty($raw): array {
    $s = str_replace([',', ' '], '', trim((string)$raw));
    if ($s === '') return [true, null];
    if (!is_numeric($s) || (float)$s < 0) return [false, null];
    return [true, round((float)$s, 3)];
}

/**
 * Checks the posted form. Never writes.
 * @return array{errors: string[], dupes: string[], v: array}
 */
function completedEntryValidate(PDO $db, array $p): array {
    $errors = []; $dupes = [];
    $v = [
        'vessel_name' => normalizeVesselName(trim((string)($p['vessel_name'] ?? ''))),
        'client_id' => (int)($p['client_id'] ?? 0),
        'agent_name' => mb_strtoupper(trim((string)($p['agent_name'] ?? '')), 'UTF-8'),
        'port_id' => (int)($p['port_id'] ?? 0),
        'remarks' => mb_strtoupper(trim((string)($p['remarks'] ?? '')), 'UTF-8'),
        'report_number' => trim((string)($p['report_number'] ?? '')),
        'type_ids' => array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($p['survey_type_ids'] ?? ''))))))
    ];
    if (trim((string)($p['vessel_name'] ?? '')) === '') $errors[] = 'Vessel Name is required.';
    if ($v['agent_name'] === '') $errors[] = 'Agent Name is required.';

    $q = $db->prepare('SELECT id FROM clients WHERE id = ?'); $q->execute([$v['client_id']]);
    if (!$q->fetchColumn()) $errors[] = 'Please select a Client.';
    $q = $db->prepare('SELECT id FROM ports WHERE id = ?'); $q->execute([$v['port_id']]);
    if (!$q->fetchColumn()) $errors[] = 'Please select a Port.';
    if (!$v['type_ids']) {
        $errors[] = 'Please select at least one Survey Type.';
    } else {
        $in = implode(',', $v['type_ids']);
        $found = array_map('intval', $db->query("SELECT id FROM survey_types WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
        if (count($found) !== count($v['type_ids'])) $errors[] = 'A selected Survey Type no longer exists — please choose again.';
        sort($v['type_ids']); // same order Assign Vessel / the import store them in
    }

    // surveyors: real ids, or Outsourcing (no surveyor login → surveyor_id 1, like Assign Vessel)
    $raw = array_values(array_filter(array_map('trim', explode(',', (string)($p['surveyor_ids'] ?? ''))), fn($x) => $x !== ''));
    $v['outsourcing'] = in_array('outsourcing', $raw, true);
    $v['surveyor_ids'] = [];
    if (!$v['outsourcing']) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
        if ($ids) {
            $in = implode(',', $ids);
            $ok = array_map('intval', $db->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Surveyor' AND u.id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            $v['surveyor_ids'] = array_values(array_filter($ids, fn($i) => in_array($i, $ok, true)));
        }
    }
    if (!$v['outsourcing'] && !$v['surveyor_ids']) $errors[] = 'Please select a Surveyor (or Outsourcing).';

    // dates
    $today = date('Y-m-d');
    $labels = ['assign_date' => 'Survey Assigned Date', 'completed_date' => 'Survey Completed Date', 'report_date' => 'Report Completed Date'];
    foreach ($labels as $k => $label) {
        $raw = trim((string)($p[$k] ?? ''));
        $v[$k] = completedEntryDate($raw);
        if ($raw === '') $errors[] = $label . ' is required.';
        elseif ($v[$k] === null) $errors[] = $label . ' is not a valid date.';
        elseif ($v[$k] > $today) $errors[] = $label . ' cannot be in the future.';
    }
    if ($v['assign_date'] && $v['completed_date'] && $v['completed_date'] < $v['assign_date']) $errors[] = 'Survey Completed Date cannot be before the Survey Assigned Date.';
    if ($v['completed_date'] && $v['report_date'] && $v['report_date'] < $v['completed_date']) $errors[] = 'Report Completed Date cannot be before the Survey Completed Date.';

    // recovery
    $typesNa = $v['type_ids'] && isRecoveryNotApplicable($db, implode(',', $v['type_ids']));
    $v['recovery_na'] = $typesNa || ($p['recovery_mode'] ?? '') === 'na';
    $v['vlsfo'] = $v['hsfo'] = $v['lsmgo'] = $v['recovery_amount'] = null;
    if (!$v['recovery_na']) {
        foreach (['vlsfo' => 'VLSFO', 'hsfo' => 'HSFO', 'lsmgo' => 'LSMGO'] as $k => $label) {
            [$ok, $val] = completedEntryQty($p[$k] ?? '');
            if (!$ok) { $errors[] = $label . ' recovery must be a number (0 or more).'; continue; }
            $v[$k] = $val;
        }
        if ($v['vlsfo'] === null && $v['hsfo'] === null && $v['lsmgo'] === null) {
            $errors[] = 'Enter the recovery (VLSFO / HSFO / LSMGO), or choose Not Applicable.';
        } else {
            $v['recovery_amount'] = round((float)$v['vlsfo'] + (float)$v['hsfo'] + (float)$v['lsmgo'], 3);
        }
    }

    // report number typed by hand: must be free
    if ($v['report_number'] !== '') {
        if (mb_strlen($v['report_number']) > 60) $errors[] = 'Report Number is too long.';
        $q = $db->prepare('SELECT vessel_name FROM surveys WHERE report_number = ?'); $q->execute([$v['report_number']]);
        if (($other = $q->fetchColumn()) !== false) $errors[] = 'Report Number "' . $v['report_number'] . '" is already used (' . $other . ').';
    }

    // same survey already in YSMS? (warn; the Admin can still save after confirming)
    if (!$errors) {
        $ex = completedImportFindExisting($db, $v['vessel_name'], $v['client_id'], $v['type_ids'], $v['report_date'], '');
        if ($ex) $dupes[] = $ex['vessel_name'] . ' with the same client, survey type and report date is already in Completed' . (!empty($ex['report_number']) ? ' (' . $ex['report_number'] . ')' : '') . '.';
        $near = completedImportLiveExisting($db, $v['vessel_name'], $v['client_id'], $v['completed_date'], null, false, 15, $ex ? [(int)$ex['id']] : []);
        if ($near) $dupes[] = $near['vessel_name'] . ' for this client is already in ' . ($near['status'] === 'Completed' ? 'Completed' : 'Pending Reports')
            . (!empty($near['report_number']) ? ' (' . $near['report_number'] . ')' : '') . ', Survey Completed ' . date('d-m-Y', strtotime($near['d'])) . '.';
    }
    return ['errors' => $errors, 'dupes' => $dupes, 'v' => $v];
}

/** Saves a validated entry. @return array{ok:bool, id:int, report_number:string, error:string} */
function completedEntryInsert(PDO $db, array $v, int $userId, string $actorName): array {
    $cols = completedImportSurveyColumns($db);
    ensureReportNumberColumnWide($db); // may ALTER (implicit commit) — before the transaction
    $short = $v['report_number'] === '' ? ensureClientHasShortCode($db, (int)$v['client_id']) : '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $db->beginTransaction();
            $rn = $v['report_number'] !== '' ? $v['report_number'] : completedImportNextReportNumber($db, $short, $v['report_date']);
            $primary = $v['outsourcing'] ? 1 : (int)$v['surveyor_ids'][0];
            $data = [
                'vessel_name' => $v['vessel_name'],
                'report_number' => $rn,
                'client_id' => $v['client_id'],
                'agent_name' => $v['agent_name'],
                'surveyor_id' => $primary,
                'survey_type_id' => $v['type_ids'][0],
                'survey_type_ids' => implode(',', $v['type_ids']),
                'port_id' => $v['port_id'],
                'assign_date' => $v['assign_date'] . ' ' . date('H:i:s'),
                'survey_completed_date' => $v['completed_date'],
                'report_uploaded_date' => $v['report_date'] . ' ' . date('H:i:s'),
                'status' => 'Completed',
                'remarks' => $v['remarks'] !== '' ? $v['remarks'] : ($v['outsourcing'] ? 'OUTSOURCED SURVEY.' : null),
                'notes' => 'Entered in Completed Vessels by ' . $actorName . ' on ' . date('d-m-Y') . '.',
                'recovery_amount' => $v['recovery_amount'],
                'vlsfo_recovery' => $v['vlsfo'],
                'hsfo_recovery' => $v['hsfo'],
                'lsmgo_recovery' => $v['lsmgo'],
                'status_updated_by' => $userId,
                'status_updated_at' => date('Y-m-d H:i:s'),
                'client_submitted' => 0,
            ];
            $data = array_filter($data, fn($k) => isset($cols[$k]), ARRAY_FILTER_USE_KEY);
            $names = array_keys($data);
            $db->prepare('INSERT INTO surveys (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')
               ->execute(array_values($data));
            $id = (int)$db->lastInsertId();
            $ins = $db->prepare('INSERT IGNORE INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)');
            foreach ($v['surveyor_ids'] as $sid) $ins->execute([$id, $sid]);
            $db->commit();
            return ['ok' => true, 'id' => $id, 'report_number' => $rn, 'error' => ''];
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $dup = $e->getCode() == 23000;
            if ($dup && $v['report_number'] === '') { usleep(30000); continue; } // generated number raced — try the next one
            error_log('completedEntryInsert: ' . $e->getMessage());
            return ['ok' => false, 'id' => 0, 'report_number' => '', 'error' => $dup ? 'Report Number is already used.' : 'Could not save. Please try again.'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('completedEntryInsert: ' . $e->getMessage());
            return ['ok' => false, 'id' => 0, 'report_number' => '', 'error' => 'Could not save. Please try again.'];
        }
    }
    return ['ok' => false, 'id' => 0, 'report_number' => '', 'error' => 'Could not create a report number. Please try again.'];
}

/**
 * Optional files: final_report_pdf (PDF → "Formal Report PDF") and completed_files[] (→ "Field
 * Report"), same storage, names and checks as the vessel upload form.
 * @return array{saved: string[], rejected: string[]}
 */
function completedEntrySaveFiles(PDO $db, int $surveyId, array $files): array {
    $dir = dirname(__DIR__) . '/uploads/';
    $saved = []; $rejected = [];
    $ext = fn($n) => strtolower(pathinfo((string)$n, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'csv', 'txt', 'rtf', 'ppt', 'pptx', 'odt', 'ods',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp', 'zip', 'rar', '7z', 'msg', 'eml'];
    $one = function (array $f, string $type, array $exts) use ($db, $surveyId, $dir, $ext, &$saved, &$rejected) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
        $orig = basename((string)$f['name']);
        if ($f['error'] !== UPLOAD_ERR_OK || !in_array($ext($orig), $exts, true) || upload_validate($f, [], 30 * 1024 * 1024) !== '') { $rejected[] = $orig; return; }
        $name = time() . '_' . bin2hex(random_bytes(3)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
        if (!move_uploaded_file($f['tmp_name'], $dir . $name)) { $rejected[] = $orig; return; }
        $size = $ext($orig) === 'pdf' ? pdfcSizeLabel(pdfCompress($dir . $name)['after']) : pdfcSizeLabel((int)@filesize($dir . $name));
        $db->prepare('INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, ?, ?, ?)')
           ->execute([$surveyId, $orig, $type, 'uploads/' . $name, $size]);
        $saved[] = $orig;
    };
    if (!empty($files['final_report_pdf'])) $one($files['final_report_pdf'], 'Formal Report PDF', ['pdf']);
    $m = $files['completed_files'] ?? null;
    if ($m && is_array($m['name'])) {
        foreach ($m['name'] as $i => $n) {
            $one(['name' => $n, 'type' => $m['type'][$i] ?? '', 'tmp_name' => $m['tmp_name'][$i], 'error' => $m['error'][$i], 'size' => $m['size'][$i]], 'Field Report', $allowed);
        }
    }
    return ['saved' => $saved, 'rejected' => $rejected];
}
