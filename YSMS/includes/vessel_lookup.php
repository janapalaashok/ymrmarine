<?php
/**
 * Vessel lookup by IMO number — used by Survey Reports (includes/survey_reports.php) and the
 * Report Generator's "IMO Lookup" (ajax/report_imo_lookup.php). Fast first: particulars found
 * for this IMO before (table survey_vessel_lookup, kept 60 days), the latest survey report of
 * this IMO, then Gemini + Google Search. Values are checked with the Survey Report field types.
 */
require_once __DIR__ . '/survey_form.php';
require_once __DIR__ . '/gemini_json.php';

/** Whether a table exists in this database (asked once per request). */
function vlTableExists(PDO $db, string $table): bool {
    static $seen = [];
    if (!array_key_exists($table, $seen)) {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $q->execute([$table]);
        $seen[$table] = (int)$q->fetchColumn() > 0;
    }
    return $seen[$table];
}

/** Vessel particulars the "Vessel lookup" button may fill (field key => what the prompt asks for). */
const SR_LOOKUP_FIELDS = [
    'vessel_name' => 'current ship name',
    'flag' => 'flag state (country)',
    'port_of_registry' => 'port of registry',
    'grt' => 'gross tonnage (whole number)',
    'nrt' => 'net tonnage (whole number)',
    'owners' => 'registered owner company',
    'operator' => 'ship manager / operator company',
    'builder' => 'shipbuilder (yard name)',
    'keel_month' => 'keel laid month (month name, e.g. MAY)',
    'keel_year' => 'keel laid year (4 digits; if only the build year is known, null)',
    'callsign' => 'call sign',
    'loa' => 'length overall in metres',
    'breadth' => 'breadth moulded in metres',
    'depth' => 'depth moulded in metres',
    'summer_draft' => 'summer draft in metres',
    'summer_dwt' => 'summer deadweight in tonnes (whole number)',
    'class' => 'classification society abbreviation (e.g. LR, DNV, ABS, BV, NK, KR, CCS, RINA, IRS)',
    'holds' => 'number of cargo holds (whole number)',
];

/**
 * Look up a vessel's particulars by IMO number with Gemini + Google Search. Only values the web
 * sources state for this IMO are returned (each checked against the field's type); nothing is
 * saved here — the page puts them into the form for the surveyor to check.
 * → ['ok', 'fields' => [key => value], 'sources' => [[title, uri]]] | ['ok' => false, 'error']
 */
function srVesselLookup(string $imoRaw, array $types): array {
    [$imo, $err] = sfValidate('imo', $imoRaw);
    if ($imo === '' || $err) return ['ok' => false, 'error' => $err ?: 'Enter the IMO number first.'];
    if (geminiApiKey() === '') return ['ok' => false, 'error' => 'Vessel lookup is not set up on this server (Gemini API key missing). Fill the fields by hand.'];
    $want = [];
    foreach (SR_LOOKUP_FIELDS as $k => $d) if (isset($types[$k])) $want[$k] = $d;
    $prompt = "VESSEL LOOKUP BY IMO NUMBER.\nSearch the web for the merchant ship with IMO number $imo (e.g. on Equasis-like registries, MarineTraffic, VesselFinder, BalticShipping, classification society registers, shipbuilder pages).\n"
        . "Return ONE JSON object only, no other text, with these keys:\n"
        . "  \"found\": true/false, \"imo\": the IMO number of the ship the values belong to,\n";
    foreach ($want as $k => $d) $prompt .= "  \"$k\": $d,\n";
    $prompt .= "Rules: use only values a source states for THIS IMO number. If a value is not found or sources disagree, use null. Never guess or estimate. Numbers without units or thousands separators. Text in UPPER CASE.";
    $res = geminiJson([['text' => $prompt]], 90, 'vessel lookup', ['search' => true, 'fast' => true]);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['message'] ?? 'Vessel lookup failed. Please try again.'];
    $d = $res['data'];
    if (empty($d['found']) || preg_replace('/\D/', '', (string)($d['imo'] ?? '')) !== $imo) {
        return ['ok' => false, 'error' => 'No vessel particulars were found for IMO ' . $imo . '. Fill the fields by hand.'];
    }
    $out = [];
    foreach ($want as $k => $_) {
        $v = $d[$k] ?? null;
        if ($v === null || is_array($v) || is_bool($v)) continue;
        $v = (string)$v;
        if ($types[$k] === 'int' || strpos($types[$k], 'num:') === 0) {
            $v = str_replace(',', '', $v);
            if (strpos($types[$k], 'num:') === 0 && is_numeric($v)) $v = rtrim(rtrim(number_format((float)$v, (int)substr($types[$k], 4), '.', ''), '0'), '.');
            if ($types[$k] === 'int' && is_numeric($v)) $v = (string)(int)round((float)$v);
        } else {
            $v = mb_strtoupper($v);
        }
        [$clean, $e] = sfValidate($types[$k], $v);
        if ($clean !== '' && !$e) $out[$k] = $clean;
    }
    if (!$out) return ['ok' => false, 'error' => 'No vessel particulars were found for IMO ' . $imo . '. Fill the fields by hand.'];
    return ['ok' => true, 'imo' => $imo, 'fields' => $out, 'sources' => array_slice($res['sources'] ?? [], 0, 8)];
}

/**
 * Vessel lookup, fast first: (1) particulars found for this IMO before (kept 60 days), (2) the latest report
 * of this IMO typed in by a surveyor, (3) the AI web search (slow, 20-60 s) — its answer is kept for next time.
 * The PHP session is released before the AI is asked, so autosave and other requests do not wait for it.
 */
const SR_LOOKUP_KEEP_DAYS = 60;
function srLookupEnsure(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_vessel_lookup` (
        `imo` varchar(7) NOT NULL PRIMARY KEY, `fields` longtext NOT NULL, `sources` longtext NULL, `fetched_at` datetime NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function srLookupFast(PDO $db, string $imoRaw, array $types, int $exceptReport = 0): array {
    [$imo, $err] = sfValidate('imo', $imoRaw);
    if ($imo === '' || $err) return ['ok' => false, 'error' => $err ?: 'Enter the IMO number first.'];
    $keep = function (array $f) use ($types): array {
        $out = [];
        foreach ($f as $k => $v) {
            if (!isset($types[$k]) || !isset(SR_LOOKUP_FIELDS[$k]) || !is_scalar($v) || (string)$v === '') continue;
            [$clean, $e] = sfValidate($types[$k], (string)$v);
            if ($clean !== '' && !$e) $out[$k] = $clean;
        }
        return $out;
    };
    try {
        srLookupEnsure($db);
        $q = $db->prepare('SELECT fields, sources, fetched_at FROM survey_vessel_lookup WHERE imo = ? AND fetched_at > ?');
        $q->execute([$imo, date('Y-m-d H:i:s', time() - SR_LOOKUP_KEEP_DAYS * 86400)]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $f = $keep((array)json_decode((string)$row['fields'], true));
            if ($f) return ['ok' => true, 'imo' => $imo, 'fields' => $f, 'sources' => (array)json_decode((string)$row['sources'], true), 'from' => 'saved', 'at' => $row['fetched_at']];
        }
        // the latest report of this IMO (what a surveyor typed and checked) — where Survey Reports exist
        if (vlTableExists($db, 'survey_reports')) {
            $q = $db->prepare("SELECT id, data, updated_at FROM survey_reports WHERE id <> ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.fields.imo')) = ? ORDER BY updated_at DESC LIMIT 1");
            $q->execute([$exceptReport, $imo]);
            if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $d = json_decode((string)$row['data'], true);
                $f = $keep((array)($d['fields'] ?? []));
                if (count($f) >= 3) return ['ok' => true, 'imo' => $imo, 'fields' => $f, 'sources' => [], 'from' => 'report', 'at' => $row['updated_at']];
            }
        }
    } catch (Throwable $e) { error_log('vessel lookup (fast): ' . $e->getMessage()); }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $res = srVesselLookup($imo, $types);
    if ($res['ok']) {
        try {
            $db->prepare('REPLACE INTO survey_vessel_lookup (imo, fields, sources, fetched_at) VALUES (?, ?, ?, ?)')
               ->execute([$imo, json_encode($res['fields']), json_encode($res['sources'] ?? []), date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { error_log('vessel lookup (keep): ' . $e->getMessage()); }
        $res['from'] = 'web';
    }
    return $res;
}
