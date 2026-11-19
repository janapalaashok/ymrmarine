<?php
/**
 * Bulk import of already-completed vessels from Excel (Admin only).
 *
 * Accepts the same layout export_completed.php produces (country banner
 * rows + repeated header rows), or any sheet with recognisable headers.
 * Rows are inserted straight into surveys with status = 'Completed'.
 * No emails / notifications are sent and no files are attached.
 *
 * Reuses the lookups and date parser from bulk_assign_helpers.php (or the
 * identical fallback copies below when that file isn't deployed).
 */

if (is_file(__DIR__ . '/bulk_assign_helpers.php')) {
    require_once __DIR__ . '/bulk_assign_helpers.php';
}
require_once __DIR__ . '/report_number.php';

// The lookups/parsers below are shared with the bulk-assign feature
// (bulk_assign_helpers.php). Defined here only when that file isn't present.
if (!function_exists('bulkAssignLoadSpreadsheet')) {
    function bulkAssignLoadSpreadsheet(string $filePath, string $ext) {
        if ($ext === 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
            $reader->setDelimiter(bulkAssignDetectCsvDelimiter($filePath));
            $reader->setEnclosure('"');
            $reader->setSheetIndex(0);
            return $reader->load($filePath);
        }
        return \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
    }
}

if (!function_exists('bulkAssignDetectCsvDelimiter')) {
    function bulkAssignDetectCsvDelimiter(string $filePath): string {
        $first = @fgets(fopen($filePath, 'r')) ?: '';
        $commas = substr_count($first, ',');
        $semis = substr_count($first, ';');
        return $semis > $commas ? ';' : ',';
    }
}

if (!function_exists('bulkAssignNormalizeImo')) {
    function bulkAssignNormalizeImo(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') return '';
        $raw = preg_replace('/imo/i', '', $raw);
        $digits = preg_replace('/[^0-9]/', '', $raw);
        return $digits;
    }
}

if (!function_exists('bulkAssignParseDate')) {
    function bulkAssignParseDate($cellOrValue): ?string {
        if ($cellOrValue instanceof \PhpOffice\PhpSpreadsheet\Cell\Cell) {
            $value = $cellOrValue->getValue();
            if (is_numeric($value) && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cellOrValue)) {
                try {
                    $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                    return $dt->format('Y-m-d H:i:s');
                } catch (Throwable $e) {
                    // fall through to string parsing below
                }
            }
            $value = (string)$cellOrValue->getCalculatedValue();
        } else {
            $value = $cellOrValue;
        }

        $value = trim((string)$value);
        if ($value === '') return null;

        // Pure numeric — likely an Excel serial read outside a Cell context (CSV rarely hits this)
        if (is_numeric($value) && (float)$value > 20000 && (float)$value < 80000) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
                return $dt->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                // fall through
            }
        }

        // DD-MM-YYYY / DD/MM/YYYY (bias toward day-first, matches this business's locale)
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $value, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $year = (int)$m[3];
            if ($day > 12 && $month <= 12) {
                // unambiguous day-first
            } elseif ($month > 12 && $day <= 12) {
                [$day, $month] = [$month, $day];
            }
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day);
            }
        }

        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
        return null;
    }
}

if (!function_exists('bulkAssignFindSurveyor')) {
    function bulkAssignFindSurveyor(PDO $db, string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '') return null;

        if (ctype_digit($raw)) {
            $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND role_id = 2 AND status = 'Active' LIMIT 1");
            $stmt->execute([(int)$raw]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        if (strpos($raw, '@') !== false) {
            $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE role_id = 2 AND status = 'Active' AND LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$raw]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
            return null; // an email was given but didn't match — don't fall through to a fuzzy name match
        }
        $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE role_id = 2 AND status = 'Active' AND LOWER(full_name) = LOWER(?) LIMIT 1");
        $stmt->execute([$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE role_id = 2 AND status = 'Active' AND full_name LIKE ? LIMIT 2");
        $stmt->execute(['%' . $raw . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null; // ambiguous partial match = no match
    }
}

if (!function_exists('bulkAssignFindClient')) {
    function bulkAssignFindClient(PDO $db, string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '') return null;

        if (ctype_digit($raw)) {
            $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$raw]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        if (strpos($raw, '@') !== false) {
            try {
                $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $stmt->execute([$raw]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) return $row;
            } catch (Throwable $e) { /* email column may not exist on very old installs */ }
            return null;
        }
        // exact short_code match (e.g. "AS")
        try {
            $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE UPPER(short_code) = UPPER(?) LIMIT 1");
            $stmt->execute([$raw]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (Throwable $e) { /* short_code may not exist yet */ }

        $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE LOWER(company_name) = LOWER(?) LIMIT 1");
        $stmt->execute([$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE company_name LIKE ? LIMIT 2");
        $stmt->execute(['%' . $raw . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }
}

if (!function_exists('bulkAssignFindSurveyType')) {
    function bulkAssignFindSurveyType(PDO $db, string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '') return null;

        if (ctype_digit($raw)) {
            $stmt = $db->prepare("SELECT id, type_name FROM survey_types WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$raw]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        $stmt = $db->prepare("SELECT id, type_name FROM survey_types WHERE LOWER(type_name) = LOWER(?) LIMIT 1");
        $stmt->execute([$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        $stmt = $db->prepare("SELECT id, type_name FROM survey_types WHERE type_name LIKE ? LIMIT 2");
        $stmt->execute(['%' . $raw . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }
}

function completedImportAliases(): array {
    return [
        'vessel_name'    => ['vessel name', 'vessel', 'ship name', 'ship'],
        'client'         => ['client', 'client name', 'company', 'company name'],
        'agent'          => ['agent', 'agent name', 'agents'],
        'port'           => ['port', 'port name', 'survey place', 'place'],
        'country'        => ['country'],
        'survey_type'    => ['survey type', 'survey types', 'type of survey'],
        'surveyor'       => ['surveyor', 'surveyors', 'surveyor name'],
        'recovery'       => ['recovery mt', 'recovery', 'total recovery', 'total recovery mt'],
        'vlsfo'          => ['vlsfo', 'vlsfo mt', 'vlsfo recovery'],
        'hsfo'           => ['hsfo', 'hsfo mt', 'hsfo recovery'],
        'lsmgo'          => ['lsmgo', 'lsmgo mt', 'lsmgo recovery'],
        'report_date'    => ['report uploaded date', 'report upload date', 'report date', 'uploaded date', 'upload date'],
        'completed_date' => ['survey completed date', 'completed date', 'completion date'],
        'assign_date'    => ['assign date', 'assigned date', 'assignment date'],
        'report_number'  => ['report no', 'report number', 'report num', 'ref no'],
        'imo'            => ['imo', 'imo number', 'imo no'],
        'remarks'        => ['remarks', 'remark', 'notes'],
    ];
}

function completedImportRequiredFields(): array {
    return ['vessel_name' => 'Vessel Name', 'client' => 'Client', 'port' => 'Port',
            'survey_type' => 'Survey Type', 'surveyor' => 'Surveyor', 'report_date' => 'Report Uploaded Date'];
}

function completedImportNormHeader($h): string {
    $h = strtolower(trim((string)$h));
    $h = preg_replace('/[^a-z0-9]+/', ' ', $h);
    return trim($h);
}

/** Field key for a header cell, or '' when not recognised. */
function completedImportFieldFor($h): string {
    $n = completedImportNormHeader($h);
    if ($n === '') return '';
    foreach (completedImportAliases() as $field => $aliases) {
        if (in_array($n, $aliases, true)) return $field;
    }
    return '';
}

/**
 * Reads every sheet and returns the data rows as field => raw value arrays.
 * Header rows are any row whose cells include "Vessel Name"; a later header
 * row overrides earlier positions only where its cell is non-blank (the
 * export leaves VLSFO/HSFO/LSMGO blank on repeated headers). Rows with a
 * single non-empty cell are country banners and are skipped.
 *
 * @return array{rows: array<int,array>, missing: array<int,string>, header_found: bool}
 */
function completedImportReadRows($spreadsheet): array {
    $out = [];
    $headerFound = false;
    $seenFields = [];
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        $sheetName = $sheet->getTitle();
        $map = [];
        // formatted = false -> numbers and date serials come through raw
        $rows = $sheet->toArray(null, true, false, false);
        foreach ($rows as $i => $cells) {
            $nonEmpty = 0;
            foreach ($cells as $c) { if (trim((string)$c) !== '') $nonEmpty++; }
            if ($nonEmpty === 0) continue;

            $isHeader = false;
            foreach ($cells as $c) {
                if (completedImportFieldFor($c) === 'vessel_name') { $isHeader = true; break; }
            }
            if ($isHeader) {
                $headerFound = true;
                foreach ($cells as $idx => $c) {
                    $f = completedImportFieldFor($c);
                    if ($f === '') continue;
                    foreach ($map as $k => $v) { if ($v === $idx) unset($map[$k]); }
                    $map[$f] = $idx;
                    $seenFields[$f] = true;
                }
                continue;
            }
            if (empty($map) || $nonEmpty < 2) continue; // before first header, or a country banner

            $row = ['_sheet' => $sheetName, '_row' => $i + 1];
            foreach ($map as $f => $idx) {
                $v = $cells[$idx] ?? '';
                $row[$f] = is_string($v) ? trim($v) : $v;
            }
            if (trim((string)($row['vessel_name'] ?? '')) === '') continue;
            $out[] = $row;
        }
    }
    $missing = [];
    if ($headerFound) {
        foreach (completedImportRequiredFields() as $f => $label) {
            if (empty($seenFields[$f])) $missing[] = $label;
        }
    }
    return ['rows' => $out, 'missing' => $missing, 'header_found' => $headerFound];
}

/** Parses a recovery cell. Returns [ok(bool), value(float|null)]. */
function completedImportParseQty($raw): array {
    if ($raw === null) return [true, null];
    if (is_int($raw) || is_float($raw)) return [true, round(abs((float)$raw), 3)]; // stored positive (Onhire minus is display only)
    $s = trim((string)$raw);
    if ($s === '' || preg_match('/^(n\/?a|na|nil|-+|—|–)\b/i', $s)) return [true, null];
    $s = preg_replace('/\s*(mt|m\.t\.?)$/i', '', $s);
    $s = str_replace(',', '', $s);
    if (!is_numeric($s)) return [false, null];
    return [true, round(abs((float)$s), 3)];
}

// ─────────────────────────────────────────────────────────────────────────
// Matching against the live master lists (clients / ports / survey types /
// surveyors). Exact name first, then the same name ignoring case,
// punctuation and filler words, then the older partial-match finders. Every
// result says HOW it matched so the preview can show Excel value → saved as.
// ─────────────────────────────────────────────────────────────────────────

const COMPLETED_IMPORT_PLACEHOLDER_SURVEYOR = 'Not Recorded';

function completedImportRefs(PDO $db, bool $refresh = false): array {
    static $refs = null;
    if ($refs !== null && !$refresh) return $refs;
    $refs = [
        'clients'   => $db->query("SELECT id, company_name FROM clients")->fetchAll(PDO::FETCH_ASSOC),
        'ports'     => $db->query("SELECT id, port_name, country FROM ports")->fetchAll(PDO::FETCH_ASSOC),
        'types'     => $db->query("SELECT id, type_name FROM survey_types")->fetchAll(PDO::FETCH_ASSOC),
        'surveyors' => $db->query("SELECT u.id, u.full_name, u.status FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Surveyor'")->fetchAll(PDO::FETCH_ASSOC),
    ];
    return $refs;
}

function completedImportWords(string $s): array {
    $s = strtoupper(str_replace("\xC2\xA0", ' ', $s));
    return preg_split('/\s+/', trim(preg_replace('/[^A-Z0-9]+/', ' ', $s)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
function completedImportClientKey(string $s): string {
    $drop = ['PTE', 'LTD', 'LIMITED', 'PVT', 'PRIVATE', 'FZCO', 'FZE', 'DMCC', 'LLC', 'INC', 'CO', 'THE'];
    return implode(' ', array_diff(completedImportWords($s), $drop));
}
function completedImportPortBase(string $s): string {
    $s = explode(',', $s)[0];
    $drop = ['PORT', 'ANCHORAGE', 'ANCORAGE', 'ANCHORGAE', 'ANCH', 'OF', 'THE'];
    return implode(' ', array_diff(completedImportWords($s), $drop));
}
function completedImportIsAnchorage(string $s): bool { return (bool)preg_match('/anch|ancor/i', $s); }
function completedImportTypeKey(string $s): string { return implode(' ', array_diff(completedImportWords($s), ['SURVEY'])); }
function completedImportPersonKey(string $s): string { return implode(' ', array_diff(completedImportWords($s), ['CAPT', 'CAPTAIN', 'SIR', 'MR', 'MRS', 'MS', 'DR'])); }
/** ALL-CAPS text → Title Case (names typed in capitals); mixed case is kept as typed. */
function completedImportNiceName(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', $s)), " \t\n\r\0\x0B,;");
    if ($s !== '' && strtoupper($s) === $s) {
        $s = ucwords(strtolower($s));
        $s = preg_replace_callback('/\(([a-z]{2,6})\)/i', fn($m) => '(' . strtoupper($m[1]) . ')', $s); // (KBSP)
    }
    return $s;
}

/**
 * Same name typed slightly differently: at most 2 letters different, ≥80 %
 * similar and the same first letter (Dhmara/Dhamra, Karchi/Karachi,
 * New Manglore/New Mangalore), or equal once spaces are removed
 * (Khor Fakkan/Khorfakkan). Tested on the historical port list so that
 * different places (Said/Side, Kandla/Kakinada, Dhamra/Dharamtar) stay apart.
 */
function completedImportCloseSpelling(string $a, string $b): bool {
    if ($a === '' || $b === '') return false;
    if (str_replace(' ', '', $a) === str_replace(' ', '', $b)) return true;
    if ($a[0] !== $b[0] || min(strlen($a), strlen($b)) < 4) return false;
    similar_text($a, $b, $pct);
    return $pct >= 80 && levenshtein($a, $b) <= 2;
}

/** @return array{id:int,name:string,how:string}|array{error:string}|null */
function completedImportFindClient(PDO $db, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;
    $refs = completedImportRefs($db);
    foreach ($refs['clients'] as $c) if (strcasecmp(trim($c['company_name']), $raw) === 0) return ['id' => (int)$c['id'], 'name' => $c['company_name'], 'how' => 'exact'];
    $k = completedImportClientKey($raw);
    $hits = array_values(array_filter($refs['clients'], fn($c) => $k !== '' && completedImportClientKey($c['company_name']) === $k));
    if (count($hits) === 1) return ['id' => (int)$hits[0]['id'], 'name' => $hits[0]['company_name'], 'how' => 'same name (case / punctuation / LTD, PTE…)'];
    if (count($hits) > 1) return ['error' => 'matches more than one client: ' . implode(' / ', array_column($hits, 'company_name'))];
    if ($c = bulkAssignFindClient($db, $raw)) return ['id' => (int)$c['id'], 'name' => $c['company_name'], 'how' => 'partial / short code match'];
    return null;
}

/** @return array{id:int,name:string,how:string}|array{error:string}|null */
function completedImportFindType(PDO $db, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;
    $refs = completedImportRefs($db);
    foreach ($refs['types'] as $t) if (strcasecmp(trim($t['type_name']), $raw) === 0) return ['id' => (int)$t['id'], 'name' => $t['type_name'], 'how' => 'exact'];
    $k = completedImportTypeKey($raw);
    $hits = array_values(array_filter($refs['types'], fn($t) => $k !== '' && completedImportTypeKey($t['type_name']) === $k));
    if (count($hits) === 1) return ['id' => (int)$hits[0]['id'], 'name' => $hits[0]['type_name'], 'how' => 'same name'];
    if (count($hits) > 1) return ['error' => 'matches more than one survey type: ' . implode(' / ', array_column($hits, 'type_name'))];
    if ($t = bulkAssignFindSurveyType($db, $raw)) return ['id' => (int)$t['id'], 'name' => $t['type_name'], 'how' => 'partial match'];
    return null;
}

/**
 * Surveyor by name (active or inactive — historical data). Unknown names are
 * planned for creation (id 0, create = true) as an INACTIVE surveyor record.
 * @return array{id:int,name:string,how:string,create:bool}|array{error:string}
 */
function completedImportFindSurveyor(PDO $db, string $raw): array {
    $raw = trim($raw);
    $refs = completedImportRefs($db);
    if ($raw === '') $raw = COMPLETED_IMPORT_PLACEHOLDER_SURVEYOR;
    foreach ($refs['surveyors'] as $u) if (strcasecmp(trim($u['full_name']), $raw) === 0) return ['id' => (int)$u['id'], 'name' => $u['full_name'], 'how' => 'exact', 'create' => false];
    $k = completedImportPersonKey($raw);
    $hits = array_values(array_filter($refs['surveyors'], fn($u) => $k !== '' && completedImportPersonKey($u['full_name']) === $k));
    if (count($hits) === 1) return ['id' => (int)$hits[0]['id'], 'name' => $hits[0]['full_name'], 'how' => 'same name', 'create' => false];
    $words = completedImportWords($k);
    if (count($words) === 1) {
        $hits = array_values(array_filter($refs['surveyors'], fn($u) => (completedImportWords(completedImportPersonKey($u['full_name']))[0] ?? '') === $words[0]));
        if (count($hits) === 1) return ['id' => (int)$hits[0]['id'], 'name' => $hits[0]['full_name'], 'how' => 'first name', 'create' => false];
        if (count($hits) > 1) return ['error' => 'Surveyor "' . $raw . '" matches more than one surveyor (' . implode(' / ', array_column($hits, 'full_name')) . ') — write the full name.'];
        $close = array_values(array_filter($refs['surveyors'], fn($u) => completedImportCloseSpelling($words[0], completedImportWords(completedImportPersonKey($u['full_name']))[0] ?? '')));
        if (count($close) === 1) return ['id' => (int)$close[0]['id'], 'name' => $close[0]['full_name'], 'how' => 'close spelling of first name', 'create' => false];
    }
    $reg = &completedImportNewSurveyorRegistry();
    foreach ($reg as $rk => $name) {
        if ($rk === $k || completedImportCloseSpelling($k, $rk)) { $reg[$k] = $name; return ['id' => 0, 'name' => $name, 'how' => 'same new surveyor as "' . $name . '"', 'create' => true]; }
    }
    $name = completedImportNiceName(implode(' ', array_map('ucfirst', array_map('strtolower', completedImportWords($k)))) ?: $raw);
    $reg[$k] = $name;
    return ['id' => 0, 'name' => $name, 'how' => 'new surveyor', 'create' => true];
}

/**
 * Port rule: a name that says anchorage → that port's "… Anchorage" entry,
 * otherwise the port itself. Unknown ports are planned for creation together
 * with their anchorage (same as Admin Controls → Ports).
 * @return array  id, name, how, create(bool), create_port, create_anchorage, country
 */
function completedImportResolvePort(PDO $db, string $raw, string $country = ''): array {
    $raw = trim(preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', $raw)));
    $refs = completedImportRefs($db);
    foreach ($refs['ports'] as $p) if (strcasecmp(trim($p['port_name']), $raw) === 0) return ['id' => (int)$p['id'], 'name' => $p['port_name'], 'how' => 'exact', 'create' => false];
    $base = completedImportPortBase($raw);
    if ($base === '') return ['error' => 'Port "' . $raw . '" is not a port name — write the real port name.'];
    $wantAnch = completedImportIsAnchorage($raw);
    $cands = array_values(array_filter($refs['ports'], fn($p) => $base !== '' && completedImportPortBase($p['port_name']) === $base));
    $anch = array_values(array_filter($cands, fn($p) => completedImportIsAnchorage($p['port_name'])));
    $port = array_values(array_filter($cands, fn($p) => !completedImportIsAnchorage($p['port_name'])));
    $pick = $wantAnch ? $anch : $port;
    if (count($pick) > 1) {
        $named = array_values(array_filter($pick, fn($p) => preg_match('/\bport\b/i', $p['port_name'])));
        if (count($named) === 1) $pick = $named;
    }
    if (count($pick) === 1) return ['id' => (int)$pick[0]['id'], 'name' => $pick[0]['port_name'], 'how' => $wantAnch ? 'anchorage of this port' : 'port (not its anchorage)', 'create' => false];
    if (count($pick) > 1) return ['error' => 'Port "' . $raw . '" matches more than one port (' . implode(' / ', array_column($pick, 'port_name')) . ') — use the exact name.'];

    // Typo of an existing port name (only when exactly one port base is that close).
    if (!$cands) {
        $close = [];
        foreach ($refs['ports'] as $p) {
            $pb = completedImportPortBase($p['port_name']);
            if ($pb !== $base && completedImportCloseSpelling($base, $pb)) $close[$pb][] = $p;
        }
        if (count($close) > 1) { // several close names → keep only the closest one, if it is unique
            $dist = [];
            foreach (array_keys($close) as $pb) $dist[$pb] = levenshtein($base, $pb);
            $min = min($dist);
            $best = array_keys($dist, $min);
            $close = count($best) === 1 ? [$best[0] => $close[$best[0]]] : [];
        }
        if (count($close) === 1) {
            $grp = reset($close);
            $cands = $grp;
            $anch = array_values(array_filter($grp, fn($p) => completedImportIsAnchorage($p['port_name'])));
            $port = array_values(array_filter($grp, fn($p) => !completedImportIsAnchorage($p['port_name'])));
            $pick = $wantAnch ? $anch : $port;
            if (count($pick) > 1) {
                $named = array_values(array_filter($pick, fn($p) => preg_match('/\bport\b/i', $p['port_name'])));
                if (count($named) === 1) $pick = $named;
            }
            if (count($pick) === 1) return ['id' => (int)$pick[0]['id'], 'name' => $pick[0]['port_name'], 'how' => 'close spelling of an existing port' . ($wantAnch ? ' (anchorage)' : ''), 'create' => false];
        }
    }

    // Not found → create the port and its anchorage.
    $parts = explode(',', $raw, 2);
    if ($country === '' && isset($parts[1]) && trim($parts[1]) !== '') $country = completedImportNiceName($parts[1]);
    $baseName = trim(preg_replace('/\b(anchorage|ancorage|anchorgae|anch)\b\.?/i', '', $parts[0]));
    $baseName = completedImportNiceName(preg_replace('/\s+/', ' ', $baseName));
    if ($port) { $baseName = $port[0]['port_name']; $country = $country ?: (string)$port[0]['country']; }
    $anchName = buildAnchorageName($baseName) ?? ($baseName . ' Anchorage');
    if ($anch && !$wantAnch) $anchName = $anch[0]['port_name'];
    $plan = ['id' => 0, 'name' => $wantAnch ? $anchName : $baseName, 'how' => $wantAnch ? 'new port — its anchorage is used' : 'new port',
            'create' => true, 'create_port' => $baseName, 'create_anchorage' => $anchName, 'country' => $country !== '' ? $country : 'Not specified'];

    // One new port per place: later spellings of a port already planned in
    // this file (same name, or a close spelling) reuse that first plan.
    $reg = &completedImportNewPortRegistry();
    foreach ($reg as $rb => $rp) {
        if ($rb === $base || completedImportCloseSpelling($base, $rb)) {
            $reg[$base] = $rp; // this spelling too, so a further typo of it lands on the same port
            $same = $rp;
            $same['name'] = $wantAnch ? $rp['create_anchorage'] : $rp['create_port'];
            $same['how'] = ($rb === $base ? 'same new port as' : 'close spelling of new port') . ' "' . $rp['create_port'] . '"' . ($wantAnch ? ' (anchorage)' : '');
            return $same;
        }
    }
    $reg[$base] = $plan;
    return $plan;
}

/** "A + B", "A / B", "A & B", or two known first names with only a space ("PRAKASH ASHOK"). */
function completedImportSplitSurveyors(PDO $db, string $raw): array {
    $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[+\/&]\s*/', trim($raw)) ?: []), fn($p) => $p !== ''));
    $out = [];
    $refs = completedImportRefs($db);
    $firsts = [];
    foreach ($refs['surveyors'] as $u) { $f = completedImportWords(completedImportPersonKey($u['full_name']))[0] ?? ''; if ($f !== '') $firsts[$f] = ($firsts[$f] ?? 0) + 1; }
    foreach ($parts as $p) {
        $w = completedImportWords(completedImportPersonKey($p));
        $exact = false;
        foreach ($refs['surveyors'] as $u) if (completedImportPersonKey($u['full_name']) === implode(' ', $w)) $exact = true;
        if (!$exact && count($w) > 1 && count(array_filter($w, fn($x) => ($firsts[$x] ?? 0) === 1)) === count($w)) { foreach ($w as $x) $out[] = $x; }
        else $out[] = $p;
    }
    return $out;
}

function &completedImportNewPortRegistry(): array { static $reg = []; return $reg; }
function &completedImportNewSurveyorRegistry(): array { static $reg = []; return $reg; }

/**
 * Resolve every distinct port / surveyor once, most-used spelling first, so
 * the most common spelling becomes the name of any port / surveyor created.
 */
function completedImportPrime(PDO $db, array $rawRows): void {
    $reg = &completedImportNewPortRegistry(); $reg = [];
    $sreg = &completedImportNewSurveyorRegistry(); $sreg = [];
    $ports = []; $countries = []; $people = [];
    foreach ($rawRows as $r) {
        $p = trim((string)($r['port'] ?? ''));
        if ($p !== '') { $ports[$p] = ($ports[$p] ?? 0) + 1; $countries[$p] = $countries[$p] ?? trim((string)($r['country'] ?? '')); }
        foreach (completedImportSplitSurveyors($db, (string)($r['surveyor'] ?? '')) as $s) $people[$s] = ($people[$s] ?? 0) + 1;
    }
    arsort($ports); arsort($people);
    foreach ($ports as $p => $n) completedImportResolvePort($db, $p, $countries[$p]);
    foreach ($people as $s => $n) completedImportFindSurveyor($db, $s);
}

/** Splits "Offhire + Draft Survey" / "A + B" combinations. */
function completedImportSplitCombo(string $raw): array {
    $parts = preg_split('/\s*\+\s*/', trim($raw)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
}

/** "MV. STAR ATLAS", "M/V Star Atlas", "MV STAR ATLAS" → "STAR ATLAS". */
function completedImportVesselKey(string $s): string {
    $s = strtoupper(str_replace("\xC2\xA0", ' ', $s));
    $s = preg_replace('/^\s*(M\s*[.\/]?\s*[VT]\s*[.\/]?\s+|M[VT]\.)+/', '', $s);
    $s = preg_replace('/^\s*M\s*\/\s*V\s*/', '', $s);
    return implode(' ', completedImportWords($s));
}

/** Same vessel + client already completed within ±7 days (different date) — a warning, not a skip. */
function completedImportNearbyExisting(PDO $db, string $vessel, int $clientId, string $reportDate): ?array {
    if ($clientId <= 0 || $reportDate === '') return null;
    $stmt = $db->prepare("SELECT id, report_number, vessel_name, DATE(report_uploaded_date) AS d FROM surveys
        WHERE status = 'Completed' AND client_id = ? AND report_uploaded_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
          AND DATE(report_uploaded_date) <> DATE(?)");
    $d = substr($reportDate, 0, 10);
    $stmt->execute([$clientId, $d, $d, $d]);
    $vk = completedImportVesselKey($vessel);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) if (completedImportVesselKey((string)$row['vessel_name']) === $vk) return $row;
    return null;
}

/**
 * The same vessel + client already in YSMS (Pending Reports or Completed — e.g. entered live
 * before the Excel was imported) with a Survey Completed Date within $days days of this row.
 * $sameRecovery: also the identical, non-zero recovery — that is the same survey, so the row
 * is skipped; otherwise it is only a 'Possible duplicate' warning (could be a repeat survey).
 * Records in $usedIds were already matched to an earlier row of this file and are ignored, so
 * one existing record never hides two Excel rows.
 */
function completedImportLiveExisting(PDO $db, string $vessel, int $clientId, ?string $date, ?float $recovery, bool $sameRecovery, int $days, array $usedIds): ?array {
    if ($clientId <= 0 || !$date || ($sameRecovery && !$recovery)) return null;
    $sql = "SELECT id, vessel_name, status, report_number, recovery_amount, DATE(survey_completed_date) AS d FROM surveys
        WHERE status IN ('Pending Report','Completed') AND client_id = ? AND YEAR(survey_completed_date) > 0
          AND survey_completed_date BETWEEN DATE_SUB(?, INTERVAL $days DAY) AND DATE_ADD(?, INTERVAL " . ($days + 1) . " DAY)";
    $params = [$clientId, substr($date, 0, 10), substr($date, 0, 10)];
    if ($sameRecovery) { $sql .= ' AND ROUND(recovery_amount, 3) = ROUND(?, 3)'; $params[] = $recovery; }
    $stmt = $db->prepare($sql . ' ORDER BY ABS(DATEDIFF(survey_completed_date, ?)), id');
    $params[] = substr($date, 0, 10);
    $stmt->execute($params);
    $vk = completedImportVesselKey($vessel);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!in_array((int)$row['id'], $usedIds, true) && completedImportVesselKey((string)$row['vessel_name']) === $vk) return $row;
    }
    return null;
}

/**
 * Already in Completed? Same report number, or the same vessel + client +
 * report date + survey types (port is NOT part of the key, so a port typed
 * differently in a later file still counts as the same survey).
 */
function completedImportFindExisting(PDO $db, string $vessel, int $clientId, array $typeIds, string $reportDate, string $reportNumber): ?array {
    if ($reportNumber !== '') {
        $stmt = $db->prepare("SELECT id, report_number FROM surveys WHERE report_number = ? LIMIT 1");
        $stmt->execute([$reportNumber]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    if ($clientId <= 0) return null;
    $stmt = $db->prepare("
        SELECT id, report_number, vessel_name, survey_type_ids, survey_type_id FROM surveys
        WHERE status = 'Completed' AND client_id = ? AND DATE(report_uploaded_date) = ?
    ");
    $stmt->execute([$clientId, substr($reportDate, 0, 10)]);
    $want = $typeIds; sort($want);
    $vk = completedImportVesselKey($vessel);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (completedImportVesselKey((string)$row['vessel_name']) !== $vk) continue;
        $have = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($row['survey_type_ids'] ?: $row['survey_type_id']))))));
        sort($have);
        if ($have === $want) return $row;
    }
    return null;
}

/**
 * Resolves one row against the database. Never writes.
 * status: ready | duplicate | error. 'warnings' are informational only.
 */
function completedImportValidateRow(PDO $db, array $r, array &$seenKeys): array {
    $str = fn($k) => trim((string)($r[$k] ?? ''));
    $res = [
        'sheet' => $r['_sheet'] ?? '', 'row' => (int)($r['_row'] ?? 0),
        'vessel_name' => $str('vessel_name'), 'client_raw' => $str('client'), 'agent_name' => $str('agent'),
        'port_raw' => $str('port'), 'country' => $str('country'), 'survey_type_raw' => $str('survey_type'),
        'surveyor_raw' => $str('surveyor'), 'remarks' => $str('remarks'),
        'imo_number' => bulkAssignNormalizeImo($str('imo')), 'report_number' => $str('report_number'),
        'client_id' => 0, 'client_name' => '', 'create_client' => false, 'port_id' => 0, 'port_name' => '', 'create_port' => false,
        'port_plan' => null, 'survey_type_ids' => [], 'survey_type_names' => [], 'surveyor_ids' => [], 'surveyor_names' => [],
        'surveyor_plan' => [], 'compare' => [],
        'recovery_amount' => null, 'vlsfo' => null, 'hsfo' => null, 'lsmgo' => null, 'recovery_na' => false,
        'report_date' => null, 'completed_date' => null, 'assign_date' => null,
        'errors' => [], 'warnings' => [], 'status' => 'ready', 'existing_id' => 0,
    ];
    $err = function (string $m) use (&$res) { $res['errors'][] = $m; };

    if ($res['vessel_name'] === '') $err('Vessel name is empty.');

    $cmp = function (string $field, string $excel, string $saved, string $how) use (&$res) {
        $res['compare'][$field] = ['excel' => $excel, 'saved' => $saved, 'how' => $how];
    };
    if ($res['client_raw'] === '') {
        $err('Client is empty.');
    } else {
        $c = completedImportFindClient($db, $res['client_raw']);
        if ($c && empty($c['error'])) {
            $res['client_id'] = (int)$c['id'];
            $res['client_name'] = (string)$c['name'];
            $cmp('client', $res['client_raw'], $c['name'], $c['how']);
        } elseif (!empty($c['error'])) {
            // Looks like more than one existing client — don't guess.
            $err('Client "' . $res['client_raw'] . '" ' . $c['error']);
        } else {
            // Not in the client records: added (as a client record, no login) when this row is imported.
            $res['create_client'] = true;
            $res['client_name'] = preg_replace('/\s+/', ' ', $res['client_raw']);
            // Same new client earlier in this file (case / punctuation / LTD… aside) → that row's spelling.
            $nk = 'new-client:' . completedImportClientKey($res['client_name']);
            if (isset($seenKeys[$nk])) {
                $res['client_name'] = $seenKeys[$nk]['name'];
                $cmp('client', $res['client_raw'], $res['client_name'], 'new client (same as row ' . $seenKeys[$nk]['row'] . ')');
                $res['warnings'][] = 'New client "' . $res['client_name'] . '" will be added (same new client as row ' . $seenKeys[$nk]['row'] . ').';
            } else {
                $seenKeys[$nk] = ['name' => $res['client_name'], 'row' => $res['row']];
                $cmp('client', $res['client_raw'], $res['client_name'], 'new client');
                $res['warnings'][] = 'New client "' . $res['client_name'] . '" will be added.';
            }
        }
    }

    if ($res['port_raw'] === '') {
        $err('Port is empty.');
    } else {
        $p = completedImportResolvePort($db, $res['port_raw'], $res['country']);
        if (!empty($p['error'])) {
            $err($p['error']);
        } else {
            $res['port_id'] = (int)$p['id'];
            $res['port_name'] = (string)$p['name'];
            $cmp('port', $res['port_raw'], $p['name'], $p['how']);
            if ($p['create']) {
                $res['create_port'] = true;
                $res['port_plan'] = $p;
                $res['warnings'][] = 'New port "' . $p['create_port'] . '" + "' . $p['create_anchorage'] . '" (' . $p['country'] . ') will be created.';
            }
        }
    }

    $types = completedImportSplitCombo($res['survey_type_raw']);
    if (!$types) $err('Survey type is empty.');
    foreach ($types as $t) {
        $st = completedImportFindType($db, $t);
        if ($st && empty($st['error'])) {
            if (!in_array((int)$st['id'], $res['survey_type_ids'], true)) {
                $res['survey_type_ids'][] = (int)$st['id'];
                $res['survey_type_names'][] = (string)$st['name'];
            }
        } else {
            $err('Survey type "' . $t . '" ' . ($st['error'] ?? 'not found (add it in Admin Controls → Survey Types, or correct the name).'));
        }
    }
    // Same order the Assign Vessel form stores them in (survey type id order).
    if ($res['survey_type_ids']) {
        $pairs = array_combine($res['survey_type_ids'], $res['survey_type_names']);
        ksort($pairs);
        $res['survey_type_ids'] = array_keys($pairs);
        $res['survey_type_names'] = array_values($pairs);
        $cmp('survey_type', $res['survey_type_raw'], implode(' + ', $res['survey_type_names']), '');
    }

    $surveyors = completedImportSplitSurveyors($db, $res['surveyor_raw']);
    if (!$surveyors) $surveyors = ['']; // blank → the "Not Recorded" placeholder surveyor
    foreach ($surveyors as $s) {
        $u = completedImportFindSurveyor($db, $s);
        if (!empty($u['error'])) { $err($u['error']); continue; }
        $dupe = false;
        foreach ($res['surveyor_plan'] as $q) if (($u['id'] && $q['id'] === $u['id']) || (!$u['id'] && strcasecmp($q['name'], $u['name']) === 0)) $dupe = true;
        if ($dupe) continue;
        $res['surveyor_plan'][] = $u;
        $res['surveyor_names'][] = $u['name'];
        if ($u['id']) $res['surveyor_ids'][] = $u['id'];
        if ($u['create']) $res['warnings'][] = 'New surveyor "' . $u['name'] . '" will be added (inactive — cannot log in).';
    }
    if ($res['surveyor_plan']) {
        $cmp('surveyor', $res['surveyor_raw'] !== '' ? $res['surveyor_raw'] : '(blank)', implode(' + ', $res['surveyor_names']),
            implode(', ', array_unique(array_map(fn($u) => $u['how'], $res['surveyor_plan']))));
    }

    // Report date missing or not a date → the survey's Assign Date is used (Survey Completed Date: see below).
    $dateOf = function ($raw) { return trim((string)$raw) === '' ? null : bulkAssignParseDate($raw); };
    $res['assign_date'] = $dateOf($r['assign_date'] ?? '');
    if (trim((string)($r['assign_date'] ?? '')) !== '' && $res['assign_date'] === null) $err('Assign date "' . $r['assign_date'] . '" is not a valid date.');
    $res['report_date'] = $dateOf($r['report_date'] ?? '');
    $res['completed_date'] = $dateOf($r['completed_date'] ?? '');
    if ($res['report_date'] === null && $res['assign_date'] !== null) {
        $orig = trim((string)($r['report_date'] ?? ''));
        $res['report_date'] = $res['assign_date'];
        $cmp('report_date', $orig === '' ? '(blank)' : $orig, date('d-m-Y', strtotime($res['report_date'])), 'taken from Assign Date');
    }
    // Survey Completed Date comes only from the Excel: monthly figures are by this date, so
    // it is never filled in from the Report / Assign date (that put vessels in the wrong month).
    if ($res['completed_date'] === null) {
        $origC = trim((string)($r['completed_date'] ?? ''));
        $res['warnings'][] = ($origC === '' ? 'No Survey Completed Date' : 'Survey Completed Date "' . $origC . '" is not a date')
            . ' — saved blank, so this vessel is not counted in any month until the date is added.';
    }
    if ($res['report_date'] === null) $err('Report date and Assign date are both empty or not dates.');
    if ($res['assign_date'] === null) $res['assign_date'] = $res['completed_date'] ?? $res['report_date'];

    if ($res['survey_type_ids']) {
        $res['recovery_na'] = isRecoveryNotApplicable($db, implode(',', $res['survey_type_ids']));
    }
    // "NOT APPLICABLE" (or N/A, NA) in the Excel's recovery columns: recovery isn't needed for
    // this vessel — imported with no recovery, shown as NOT APPLICABLE and counted as 0.
    $isNaCell = fn($v) => in_array(strtolower(preg_replace('/\s+/', ' ', trim((string)$v))), ['not applicable', 'n/a', 'na', 'n.a', 'n.a.'], true);
    if (!$res['recovery_na']) {
        $naCols = []; $numCols = [];
        foreach (['recovery' => 'Recovery', 'vlsfo' => 'VLSFO', 'hsfo' => 'HSFO', 'lsmgo' => 'LSMGO'] as $src => $label) {
            $cell = trim((string)($r[$src] ?? ''));
            if ($cell === '') continue;
            if ($isNaCell($cell)) $naCols[] = $label; else $numCols[] = $label;
        }
        if ($naCols && $numCols) {
            $err(implode(' / ', $naCols) . ' says NOT APPLICABLE but ' . implode(' / ', $numCols) . ' has a value — keep only one.');
        } elseif ($naCols) {
            $res['recovery_na'] = true;
            $cmp('recovery', implode(', ', array_map(fn($c) => trim((string)$r[strtolower($c)]), $naCols)), RECOVERY_NA_LABEL, 'no recovery needed');
        }
    }
    if (!$res['recovery_na']) {
        foreach (['recovery' => 'recovery_amount', 'vlsfo' => 'vlsfo', 'hsfo' => 'hsfo', 'lsmgo' => 'lsmgo'] as $src => $dst) {
            [$ok, $val] = completedImportParseQty($r[$src] ?? null);
            if (!$ok) { $err(($src === 'recovery' ? 'Recovery' : strtoupper($src)) . ' value "' . $r[$src] . '" is not a number.'); continue; }
            $res[$dst] = ($val !== null && $val != 0.0) ? $val : null;
        }
        if ($res['recovery_amount'] === null && ($res['vlsfo'] !== null || $res['hsfo'] !== null || $res['lsmgo'] !== null)) {
            $res['recovery_amount'] = round((float)$res['vlsfo'] + (float)$res['hsfo'] + (float)$res['lsmgo'], 3);
        }
    }

    if ($res['errors']) {
        $res['status'] = 'error';
        return $res;
    }

    $clientKey = $res['create_client'] ? 'new:' . completedImportClientKey($res['client_name']) : (string)$res['client_id'];
    $key = completedImportVesselKey($res['vessel_name']) . '|' . $clientKey . '|' . substr((string)$res['report_date'], 0, 10) . '|' . implode(',', $res['survey_type_ids']);
    if (isset($seenKeys[$key])) {
        $res['status'] = 'duplicate';
        $res['warnings'][] = 'Same vessel, client, report date and survey type as row ' . $seenKeys[$key] . ' in this file.';
        return $res;
    }
    $seenKeys[$key] = $res['row'];

    $existing = completedImportFindExisting($db, $res['vessel_name'], $res['client_id'], $res['survey_type_ids'], (string)$res['report_date'], $res['report_number']);
    if (!$existing && ($near = completedImportNearbyExisting($db, $res['vessel_name'], $res['client_id'], (string)$res['report_date']))) {
        $res['warnings'][] = 'Possible duplicate: ' . $near['vessel_name'] . ' for this client is already in Completed on ' . date('d-m-Y', strtotime($near['d'])) . (!empty($near['report_number']) ? ' (' . $near['report_number'] . ')' : '') . ' — remove this row from the Excel if it is the same survey.';
        $res['possible_duplicate'] = true;
    }
    // Entered live in YSMS before this import (any report date / survey-type wording): same vessel,
    // client and recovery within a month → the same survey, skipped; same vessel + client within
    // 15 days → warning only. Each existing record is matched to at most one row of the file.
    $usedIds = $seenKeys['__live_ids'] ?? [];
    $liveDate = $res['completed_date'] ?? $res['report_date'];
    if (!$existing && ($live = completedImportLiveExisting($db, $res['vessel_name'], $res['client_id'], $liveDate, $res['recovery_amount'] !== null ? (float)$res['recovery_amount'] : null, true, 31, $usedIds))) {
        $existing = $live;
        $seenKeys['__live_ids'][] = (int)$live['id'];
        $res['warnings'][] = 'Same vessel, client and recovery (' . number_format((float)$live['recovery_amount'], 3) . ' MT) already in '
            . ($live['status'] === 'Completed' ? 'Completed' : 'Pending Reports') . (!empty($live['report_number']) ? ' (' . $live['report_number'] . ')' : '')
            . ', Survey Completed ' . date('d-m-Y', strtotime($live['d'])) . ' — not imported again.';
    } elseif (!$existing && ($near = completedImportLiveExisting($db, $res['vessel_name'], $res['client_id'], $liveDate, null, false, 15, []))) {
        $res['warnings'][] = 'Possible duplicate: ' . $near['vessel_name'] . ' for this client is already in ' . ($near['status'] === 'Completed' ? 'Completed' : 'Pending Reports')
            . (!empty($near['report_number']) ? ' (' . $near['report_number'] . ')' : '') . ', Survey Completed ' . date('d-m-Y', strtotime($near['d']))
            . ' — importing it would count the vessel twice; remove this row if it is the same survey.';
        $res['possible_duplicate'] = true;
    }
    if ($existing) {
        $res['status'] = 'duplicate';
        $res['existing_id'] = (int)$existing['id'];
        if (empty($live)) $res['warnings'][] = 'Already in Completed' . (!empty($existing['report_number']) ? ' (' . $existing['report_number'] . ')' : '') . '.';
    }
    return $res;
}

/** Next YMR/{SHORT}/{YYYY}/{MM}/{NNNN} using the report's own month, not today's. */
function completedImportNextReportNumber(PDO $db, string $short, string $date): string {
    $ts = strtotime($date) ?: time();
    $prefix = 'YMR/' . $short . '/' . date('Y', $ts) . '/' . date('m', $ts) . '/';
    $stmt = $db->prepare("SELECT report_number FROM surveys WHERE report_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $max = 0;
    while ($rn = $stmt->fetchColumn()) {
        if (preg_match('#/(\d{4})$#', (string)$rn, $m)) $max = max($max, (int)$m[1]);
    }
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function completedImportSurveyColumns(PDO $db): array {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        foreach ($db->query("SHOW COLUMNS FROM surveys")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }
    }
    return $cols;
}

/**
 * Client for a row whose client is not in the records yet: reuses one added
 * earlier (same name ignoring case / punctuation / LTD…, e.g. an earlier row of
 * this file), otherwise adds it with a short code — same as Assign Vessel's
 * "Add client" (client record only, no login). @return int client id
 */
function completedImportEnsureClient(PDO $db, string $name): int {
    $k = completedImportClientKey($name);
    foreach ($db->query("SELECT id, company_name FROM clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (strcasecmp(trim($c['company_name']), $name) === 0 || ($k !== '' && completedImportClientKey($c['company_name']) === $k)) return (int)$c['id'];
    }
    $code = allocateClientShortCode($db, $name);
    $db->prepare("INSERT INTO clients (company_name, short_code) VALUES (?, ?)")->execute([$name, $code]);
    return (int)$db->lastInsertId();
}

/** Inserts one validated ('ready') row. @return array{ok:bool,survey_id:int,report_number:string,error:string} */
function completedImportInsertRow(PDO $db, array $v, string $actorName): array {
    $cols = completedImportSurveyColumns($db);
    // Both of these may run DDL (ALTER TABLE), which would implicitly commit
    // an open transaction — so they must run before beginTransaction().
    static $columnWidened = false;
    if (!$columnWidened) { ensureReportNumberColumnWide($db); $columnWidened = true; }
    if (!empty($v['create_client'])) {
        try {
            $v['client_id'] = completedImportEnsureClient($db, (string)$v['client_name']);
        } catch (Throwable $e) {
            error_log('completedImportEnsureClient: ' . $e->getMessage());
            return ['ok' => false, 'survey_id' => 0, 'report_number' => '', 'error' => 'Could not add client "' . $v['client_name'] . '".'];
        }
    }
    $short = $v['report_number'] === '' ? ensureClientHasShortCode($db, (int)$v['client_id']) : '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $db->beginTransaction();

            $portId = (int)$v['port_id'];
            if ($v['create_port'] && !empty($v['port_plan'])) {
                $pp = $v['port_plan'];
                $ensure = function (string $name) use ($db, $pp): int {
                    $chk = $db->prepare("SELECT id FROM ports WHERE LOWER(TRIM(port_name)) = LOWER(?) LIMIT 1");
                    $chk->execute([$name]);
                    $id = (int)($chk->fetchColumn() ?: 0);
                    if ($id > 0) return $id;
                    $db->prepare("INSERT INTO ports (port_name, country) VALUES (?, ?)")->execute([portCaps($name), portCaps($pp['country'])]);
                    return (int)$db->lastInsertId();
                };
                $baseId = $ensure($pp['create_port']);
                $anchId = $ensure($pp['create_anchorage']);
                $portId = strcasecmp($v['port_name'], $pp['create_anchorage']) === 0 ? $anchId : $baseId;
            }

            $surveyorIds = [];
            foreach ($v['surveyor_plan'] as $u) {
                $sid = (int)$u['id'];
                if ($sid <= 0) {
                    $chk = $db->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Surveyor' AND LOWER(TRIM(u.full_name)) = LOWER(?) LIMIT 1");
                    $chk->execute([$u['name']]);
                    $sid = (int)($chk->fetchColumn() ?: 0);
                }
                if ($sid <= 0) {
                    $roleId = (int)$db->query("SELECT id FROM roles WHERE name = 'Surveyor' LIMIT 1")->fetchColumn();
                    $slug = trim(preg_replace('/[^a-z0-9]+/', '.', strtolower($u['name'])), '.') ?: 'surveyor';
                    $username = 'hist.' . substr($slug, 0, 40) . '.' . bin2hex(random_bytes(3));
                    // Record only: inactive, random unknown password → can never log in.
                    $db->prepare("INSERT INTO users (role_id, username, password, full_name, status) VALUES (?, ?, ?, ?, 'Inactive')")
                       ->execute([$roleId, $username, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $u['name']]);
                    $sid = (int)$db->lastInsertId();
                }
                if (!in_array($sid, $surveyorIds, true)) $surveyorIds[] = $sid;
            }
            $v['surveyor_ids'] = $surveyorIds;

            $reportNumber = $v['report_number'] !== '' ? $v['report_number'] : completedImportNextReportNumber($db, $short, (string)$v['report_date']);
            $firstSurveyor = (int)$v['surveyor_ids'][0];
            $data = [
                'vessel_name'           => $v['vessel_name'],
                'report_number'         => $reportNumber,
                'client_id'             => (int)$v['client_id'],
                'agent_name'            => $v['agent_name'] !== '' ? $v['agent_name'] : 'N/A',
                'surveyor_id'           => $firstSurveyor,
                'survey_type_id'        => (int)$v['survey_type_ids'][0],
                'survey_type_ids'       => implode(',', $v['survey_type_ids']),
                'port_id'               => $portId,
                'assign_date'           => $v['assign_date'],
                'survey_completed_date' => $v['completed_date'],
                'report_uploaded_date'  => $v['report_date'],
                'status'                => 'Completed',
                'remarks'               => $v['remarks'] !== '' ? $v['remarks'] : null,
                'notes'                 => 'Imported from Excel by ' . $actorName . ' on ' . date('d-m-Y') . '.',
                'imo_number'            => $v['imo_number'] !== '' ? $v['imo_number'] : null,
                'recovery_amount'       => $v['recovery_amount'],
                'vlsfo_recovery'        => $v['vlsfo'],
                'hsfo_recovery'         => $v['hsfo'],
                'lsmgo_recovery'        => $v['lsmgo'],
                'status_updated_by'     => $firstSurveyor,
                'status_updated_at'     => $v['report_date'],
                'client_submitted'      => 0,
            ];
            $data = array_filter($data, fn($k) => isset($cols[$k]), ARRAY_FILTER_USE_KEY);
            $names = array_keys($data);
            $sql = 'INSERT INTO surveys (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
            $db->prepare($sql)->execute(array_values($data));
            $surveyId = (int)$db->lastInsertId();

            $ins = $db->prepare("INSERT IGNORE INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)");
            foreach ($v['surveyor_ids'] as $sid) {
                $ins->execute([$surveyId, (int)$sid]);
            }

            $db->commit();
            return ['ok' => true, 'survey_id' => $surveyId, 'report_number' => $reportNumber, 'error' => ''];
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $dup = $e->getCode() == 23000 || stripos($e->getMessage(), 'Duplicate') !== false;
            if ($dup && $v['report_number'] === '') { usleep(30000); continue; } // generated number raced — retry
            error_log('completedImportInsertRow: ' . $e->getMessage());
            return ['ok' => false, 'survey_id' => 0, 'report_number' => '',
                    'error' => $dup ? 'Report number "' . $v['report_number'] . '" is already used.' : 'Database error while saving this row.'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('completedImportInsertRow: ' . $e->getMessage());
            return ['ok' => false, 'survey_id' => 0, 'report_number' => '', 'error' => 'Unexpected error while saving this row.'];
        }
    }
    return ['ok' => false, 'survey_id' => 0, 'report_number' => '', 'error' => 'Could not generate a unique report number.'];
}
