<?php
/**
 * Client short codes + report number helpers
 * Format: YMR/{SHORT}/{YYYY}/{MM}/{NNNN}  e.g. YMR/AS/2026/08/0001
 */

function ensureClientShortCodeColumn(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = [];
        foreach ($db->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }
        if (empty($cols['short_code'])) {
            $db->exec("ALTER TABLE clients ADD COLUMN short_code VARCHAR(10) DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('ensureClientShortCodeColumn: ' . $e->getMessage());
    }
    $done = true;
}

function ensureReportNumberColumnWide(PDO $db): void {
    try {
        $db->exec("ALTER TABLE surveys MODIFY COLUMN report_number VARCHAR(50) DEFAULT NULL");
    } catch (Throwable $e) {
        // may not exist yet
    }
}

/** Significant words only (drop legal suffixes / stop words) */
function clientNameWords(string $name): array {
    $name = trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name));
    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $skip = ['ltd','llp','pvt','private','limited','inc','llc','co','company','the','of','and','&'];
    $words = [];
    foreach ($parts as $p) {
        $l = strtolower($p);
        if (in_array($l, $skip, true)) continue;
        if (mb_strlen($p) < 1) continue;
        $words[] = $p;
    }
    // if everything skipped, fall back to original tokens
    if (empty($words)) {
        $words = $parts ?: ['XX'];
    }
    return $words;
}

/**
 * Build candidate short codes for a company name (2–3 letter uppercase).
 */
function buildShortCodeCandidates(string $companyName): array {
    $words = clientNameWords($companyName);
    $cands = [];
    $push = function (string $s) use (&$cands) {
        $s = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $s));
        if (strlen($s) < 2) return;
        if (strlen($s) > 4) $s = substr($s, 0, 4);
        if (!in_array($s, $cands, true)) $cands[] = $s;
    };

    $w0 = $words[0] ?? 'X';
    $w1 = $words[1] ?? '';
    $w2 = $words[2] ?? '';

    // Multi-word: first letters of first two words
    if ($w1 !== '') {
        $push(mb_substr($w0, 0, 1) . mb_substr($w1, 0, 1));           // AS
        $push(mb_substr($w0, 0, 1) . mb_substr($w1, 0, 2));           // AEQ? no — A + first2 of 2nd
        $push(mb_substr($w0, 0, 2) . mb_substr($w1, 0, 1));           // AQS style → AQ+S
        $push(mb_substr($w0, 0, 1) . mb_substr($w1, -1));            // last of 2nd
        $push(mb_substr($w0, 0, 1) . mb_substr($w0, -1));            // first+last of first
        $push(mb_substr($w0, 0, 2));                                  // first 2 of first word
        $push(mb_substr($w0, 0, 1) . mb_substr($w1, 0, 1) . mb_substr($w1, -1));
        if ($w2 !== '') {
            $push(mb_substr($w0, 0, 1) . mb_substr($w1, 0, 1) . mb_substr($w2, 0, 1));
        }
        // vowel alternatives from first word (Allianz → AL, AZ, AN)
        $letters = preg_replace('/[^A-Za-z]/', '', $w0);
        if (strlen($letters) >= 2) {
            $push(substr($letters, 0, 2));
            for ($i = 1; $i < min(strlen($letters), 5); $i++) {
                $push($letters[0] . $letters[$i]);
            }
        }
    } else {
        // Single significant word (Bainbridge → BB)
        $letters = preg_replace('/[^A-Za-z]/', '', $w0);
        if ($letters === '') $letters = 'XX';
        $push(substr($letters, 0, 1) . substr($letters, 0, 1)); // BB from B
        $push(substr($letters, 0, 2));
        $push(substr($letters, 0, 1) . substr($letters, -1));
        for ($i = 1; $i < min(strlen($letters), 6); $i++) {
            $push($letters[0] . $letters[$i]);
        }
        $push(substr($letters, 0, 3));
    }

    // Last-resort numeric suffixes
    $base = $cands[0] ?? 'XX';
    for ($n = 2; $n <= 9; $n++) {
        $push(substr($base, 0, 2) . $n);
    }
    return $cands;
}

function getUsedShortCodes(PDO $db, ?int $excludeId = null): array {
    ensureClientShortCodeColumn($db);
    $used = [];
    try {
        $sql = "SELECT id, short_code FROM clients WHERE short_code IS NOT NULL AND short_code != ''";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if ($excludeId && (int)$r['id'] === $excludeId) continue;
            $used[strtoupper($r['short_code'])] = true;
        }
    } catch (Throwable $e) {}
    return $used;
}

function allocateClientShortCode(PDO $db, string $companyName, ?int $excludeId = null): string {
    ensureClientShortCodeColumn($db);
    $used = getUsedShortCodes($db, $excludeId);
    foreach (buildShortCodeCandidates($companyName) as $code) {
        if (empty($used[$code])) {
            return $code;
        }
    }
    // absolute fallback
    $n = 1;
    do {
        $code = 'C' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        $n++;
    } while (!empty($used[$code]) && $n < 1000);
    return $code;
}

function ensureClientHasShortCode(PDO $db, int $clientId): string {
    ensureClientShortCodeColumn($db);
    $stmt = $db->prepare("SELECT company_name, short_code FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'XX';
    $code = trim((string)($row['short_code'] ?? ''));
    if ($code !== '') return strtoupper($code);
    $code = allocateClientShortCode($db, (string)$row['company_name'], $clientId);
    try {
        $db->prepare("UPDATE clients SET short_code = ? WHERE id = ?")->execute([$code, $clientId]);
    } catch (Throwable $e) {
        error_log('ensureClientHasShortCode save: ' . $e->getMessage());
    }
    return $code;
}

/** Backfill short codes for all clients missing them */
function backfillAllClientShortCodes(PDO $db): void {
    ensureClientShortCodeColumn($db);
    try {
        $rows = $db->query("SELECT id, company_name, short_code FROM clients ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (trim((string)($r['short_code'] ?? '')) !== '') continue;
            $code = allocateClientShortCode($db, (string)$r['company_name'], (int)$r['id']);
            $db->prepare("UPDATE clients SET short_code = ? WHERE id = ?")->execute([$code, (int)$r['id']]);
        }
    } catch (Throwable $e) {
        error_log('backfillAllClientShortCodes: ' . $e->getMessage());
    }
}

/**
 * Next report number for client: YMR/{SHORT}/{YYYY}/{MM}/{NNNN}
 * Sequence is per client short + year + month.
 */
function generateNextReportNumberForClient(PDO $db, int $clientId): string {
    ensureReportNumberColumnWide($db);
    $short = ensureClientHasShortCode($db, $clientId);
    $year = date('Y');
    $month = date('m');
    $prefix = 'YMR/' . $short . '/' . $year . '/' . $month . '/';
    $next = 1;
    try {
        $stmt = $db->prepare("SELECT report_number FROM surveys WHERE report_number LIKE ? ORDER BY report_number DESC LIMIT 50");
        $stmt->execute([$prefix . '%']);
        $max = 0;
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $rn = (string)$row[0];
            if (preg_match('#/(\d{4})$#', $rn, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        $next = $max + 1;
    } catch (Throwable $e) {
        error_log('generateNextReportNumberForClient: ' . $e->getMessage());
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/** Legacy helper — prefer generateNextReportNumberForClient */
function generateNextReportNumber(PDO $db, ?int $clientId = null): string {
    if ($clientId && $clientId > 0) {
        return generateNextReportNumberForClient($db, $clientId);
    }
    // fallback without client (should not be used for new assigns)
    $year = date('Y');
    $month = date('m');
    return 'YMR/XX/' . $year . '/' . $month . '/0001';
}
