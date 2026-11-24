<?php
/**
 * Master's email from the vessel's "Ships Particulars" document (report_detail.php → Email to
 * Master for Redelivery fills To with it; ajax/master_email.php).
 *
 *  - The document: a file of the survey (Survey Files = uploads, or the files given when the vessel
 *    was assigned = survey_attachments) whose name contains "particular" — inside a ZIP too (only
 *    the entries named "particular", or every entry of a ZIP itself named so) — or a file the user
 *    uploads with "Upload Ships Particulars" (read once, not stored; a ZIP: only its particulars).
 *  - Its text: PDF text layer (smalot/pdfparser), Word .docx, Excel, .txt / .csv.
 *  - The email: an address labelled Master / Captain (the word on the same line just before it, or
 *    "master" / "capt" in the address itself). An address with no such label is NOT used — To then
 *    stays empty for the user to type (nothing is guessed).
 *  - A scanned PDF or a picture (no text): Gemini is asked for the Master's email only, when the
 *    AI key is set; the answer is marked as read by AI so the user checks it.
 * Read only; document contents are never logged or stored.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

const ME_MAX_BYTES = 14 * 1024 * 1024;
const ME_DOC_EXT = ['pdf', 'docx', 'xlsx', 'xls', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp'];
const ME_EMAIL_RE = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}/i';

/** The survey's Ships Particulars files (newest first): [['name','abs','source']]. */
function meParticularsFiles(PDO $db, int $surveyId): array {
    $out = [];
    $add = function (array $rows, string $src) use (&$out) {
        $base = realpath(dirname(__DIR__) . '/uploads');
        foreach ($rows as $r) {
            $name = (string)($r['file_name'] ?? '');
            $isZip = strtolower(pathinfo((string)$r['file_path'], PATHINFO_EXTENSION)) === 'zip';
            if (!$isZip && !preg_match('/particular/i', $name . ' ' . basename((string)$r['file_path']))) continue;
            $abs = realpath(dirname(__DIR__) . '/' . ltrim((string)$r['file_path'], '/'));
            if ($abs === false || $base === false || strncmp($abs, $base, strlen($base)) !== 0 || !is_file($abs)) continue;
            $out[] = ['name' => preg_replace('/^[0-9]+_/', '', $name) ?: basename($abs), 'abs' => $abs, 'source' => $src, 'zip' => $isZip];
        }
    };
    $q = $db->prepare('SELECT file_name, file_path FROM uploads WHERE survey_id = ? ORDER BY id DESC');
    $q->execute([$surveyId]);
    $add($q->fetchAll(PDO::FETCH_ASSOC), 'Survey Files');
    try {
        ensureSurveyAttachmentsTable($db);
        $q = $db->prepare('SELECT file_name, file_path FROM survey_attachments WHERE survey_id = ? ORDER BY id DESC');
        $q->execute([$surveyId]);
        $add($q->fetchAll(PDO::FETCH_ASSOC), 'Assignment files');
    } catch (Throwable $e) { error_log('master email attachments: ' . $e->getMessage()); }
    return $out;
}

/** A new temporary file name with this extension (the readers look at the extension). */
function meTempPath(string $ext): string {
    $base = tempnam(sys_get_temp_dir(), 'mep');
    @unlink($base);
    return $base . '.' . $ext;
}

/**
 * The Ships Particulars inside a ZIP, copied to temporary files (the caller deletes them):
 * [['name', 'abs']]. Only entries whose name contains "particular" (every document entry when the
 * ZIP's own name does); at most 50 entries looked at, 14 MB each.
 */
function meZipParticulars(string $zipAbs, string $zipName): array {
    $out = [];
    $z = new ZipArchive();
    if ($z->open($zipAbs) !== true) return [];
    $all = preg_match('/particular/i', $zipName);
    for ($i = 0; $i < min($z->numFiles, 500) && count($out) < 50; $i++) {
        $st = $z->statIndex($i);
        $entry = (string)($st['name'] ?? '');
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (substr($entry, -1) === '/' || !in_array($ext, ME_DOC_EXT, true) || (int)($st['size'] ?? 0) > ME_MAX_BYTES) continue;
        if (!$all && !preg_match('/particular/i', basename($entry))) continue;
        $data = $z->getFromIndex($i);
        if ($data === false) continue;
        $tmp = meTempPath($ext); // only the extension is kept, never the entry path
        file_put_contents($tmp, $data);
        $out[] = ['name' => basename($entry) . ' (in ' . $zipName . ')', 'abs' => $tmp];
    }
    $z->close();
    return $out;
}

/** The Master's email from these documents: text first, then AI for scans. Result as meMasterEmail(). */
function meEmailFromDocs(array $docs): array {
    foreach ($docs as $f) {
        $email = meMasterEmailFromText(meText($f['abs']));
        if ($email !== '') return ['email' => $email, 'file' => $f['name'], 'by' => 'text', 'note' => 'From ' . $f['name']];
    }
    foreach ($docs as $f) {
        $email = meMasterEmailByAi($f['abs']);
        if ($email !== '') return ['email' => $email, 'file' => $f['name'], 'by' => 'ai', 'note' => 'Read by AI from ' . $f['name'] . ' — please check'];
    }
    return ['email' => '', 'file' => $docs[0]['name'] ?? '', 'by' => '', 'note' => $docs ? 'No email marked as the Master\'s in ' . $docs[0]['name'] . '.' : 'No Ships Particulars document found.'];
}

/**
 * An uploaded file (the "Upload Ships Particulars" button): [result, error]. Read from PHP's
 * temporary upload only — not stored. A ZIP: only its Ships Particulars entries.
 */
function meEmailFromUpload(array $f): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) return [null, 'No file was received.'];
    $name = basename((string)($f['name'] ?? 'file'));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, array_merge(ME_DOC_EXT, ['zip']), true)) return [null, 'Upload a PDF, Word (.docx), Excel, picture or ZIP file.'];
    if ((int)$f['size'] <= 0 || (int)$f['size'] > 30 * 1024 * 1024) return [null, 'The file is too large (30 MB at most).'];
    $tmps = [];
    try {
        if ($ext === 'zip') {
            $docs = meZipParticulars($f['tmp_name'], $name);
            $tmps = array_column($docs, 'abs');
            if (!$docs) return [['email' => '', 'file' => $name, 'by' => '', 'note' => 'No Ships Particulars file inside ' . $name . '.'], null];
        } else {
            if ((int)$f['size'] > ME_MAX_BYTES) return [null, 'The file is too large (14 MB at most).'];
            $tmp = meTempPath($ext);
            if (!move_uploaded_file($f['tmp_name'], $tmp)) return [null, 'The file could not be read.'];
            $tmps[] = $tmp;
            $docs = [['name' => $name, 'abs' => $tmp]];
        }
        return [meEmailFromDocs($docs), null];
    } finally {
        foreach ($tmps as $t) @unlink($t);
    }
}

/** Plain text of a document ('' when it has none we can read, e.g. a scan). */
function meText(string $abs): string {
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (filesize($abs) > ME_MAX_BYTES) return '';
    try {
        if ($ext === 'pdf') {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($abs);
            $t = '';
            foreach ($pdf->getPages() as $i => $p) { if ($i >= 15) break; $t .= $p->getText() . "\n"; }
            return $t;
        }
        if ($ext === 'docx') {
            $z = new ZipArchive();
            if ($z->open($abs) !== true) return '';
            $xml = (string)$z->getFromName('word/document.xml');
            $z->close();
            $xml = preg_replace('#</w:p>|<w:br[^>]*/>|<w:tab[^>]*/>|</w:tc>#', "\n", $xml);
            return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if (in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($abs);
            $t = '';
            foreach ($book->getAllSheets() as $sheet) {
                foreach ($sheet->toArray(null, true, false, false) as $row) $t .= implode('  ', array_map('strval', array_filter($row, fn($v) => $v !== null && $v !== ''))) . "\n";
            }
            return $t;
        }
        if ($ext === 'txt') return (string)file_get_contents($abs);
    } catch (Throwable $e) {
        error_log('master email: could not read a ' . $ext . ' file'); // no contents logged
    }
    return '';
}

/** The email labelled Master / Captain in the text, or ''. */
function meMasterEmailFromText(string $text): string {
    if (!preg_match_all(ME_EMAIL_RE, $text, $m, PREG_OFFSET_CAPTURE)) return '';
    $label = '/\b(master|captain|capt|c\/o\s*master)\b/i';
    $best = ''; $bestScore = 0;
    foreach ($m[0] as [$email, $pos]) {
        // PDF table cells can run together ("Master Email" + "capt@x.com" → "Emailcapt@x.com"): a label
        // word written with capitals stuck to a lower-case address is the label, not part of it.
        if (preg_match('/^(E-?mail|E-?MAIL|Mail|MAIL|ID|Id|Address|ADDRESS|Master|MASTER|Captain|CAPTAIN|Capt|CAPT)(?=[a-z0-9])/', $email, $g)) {
            $email = substr($email, strlen($g[1]));
            $pos += strlen($g[1]);
        }
        // ...and the next cell's capitalised word can stick to the end ("ops@x.comMaster")
        if (preg_match('/^([^@]+@[a-z0-9.\-]*?\.[a-z]{2,24})(?=[A-Z])/', $email, $g)) $email = $g[1];
        $score = 0;
        if (preg_match('/master|capt/i', $email)) $score += 3;
        $before = substr($text, max(0, $pos - 120), min(120, $pos));
        $line = substr($before, (int)strrpos("\n" . $before, "\n")); // the same line, before the address
        $rest = trim(preg_replace('/e-?mail|mail|id|address|[:\-\s()\/.]/i', '', $line));
        $prev = substr($before, 0, max(0, strlen($before) - strlen($line)));
        $prevLine = substr(rtrim($prev, "\r\n"), (int)strrpos("\n" . rtrim($prev, "\r\n"), "\n"));
        if (preg_match($label, $line)) $score += 2; // "Master email: x@y" on one line
        elseif ($rest === '' && preg_match($label, $prevLine)) $score += 2; // "Master" then the address (or "Email: x@y") on the next line
        if ($score > $bestScore) { $bestScore = $score; $best = $email; }
    }
    return $bestScore >= 2 ? strtolower(trim($best, '.')) : '';
}

/** Asks Gemini for the Master's email on a scan / picture: '' when not set up or not found. */
function meMasterEmailByAi(string $abs): string {
    if (!is_file(__DIR__ . '/gemini_json.php')) return '';
    require_once __DIR__ . '/gemini_json.php';
    if (geminiApiKey() === '') return '';
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? '';
    if ($mime === '' || filesize($abs) > ME_MAX_BYTES) return '';
    $res = geminiJson([
        ['text' => "This is a ship's particulars document. Return JSON {\"master_email\": string|null}: the email address that is "
            . "labelled for the Master / Captain of the ship, exactly as printed. If no email is clearly the Master's, return null. Do not guess."],
        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode((string)file_get_contents($abs))]],
    ], 60, 'master email', ['fast' => true]);
    $v = trim((string)($res['data']['master_email'] ?? ''));
    return (!empty($res['ok']) && preg_match('/^' . trim(ME_EMAIL_RE, '/i') . '$/i', $v)) ? strtolower($v) : '';
}

/**
 * The Master's email for a survey: ['email' => '' | address, 'file' => document name, 'by' => 'text'|'ai'|'',
 * 'note' => what happened, for the page]. Cached for the session per file set.
 */
function meMasterEmail(PDO $db, int $surveyId): array {
    $files = meParticularsFiles($db, $surveyId);
    $none = ['email' => '', 'file' => '', 'by' => '', 'note' => 'No Ships Particulars document found in this vessel\'s files.'];
    if (!$files) return $none;
    $key = 'me_' . $surveyId . '_' . md5(implode('|', array_map(fn($f) => $f['abs'] . '@' . filemtime($f['abs']), $files)));
    if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) return $_SESSION[$key];
    $docs = []; $tmps = [];
    try {
        foreach ($files as $f) {
            if (empty($f['zip'])) { $docs[] = $f; continue; }
            foreach (meZipParticulars($f['abs'], $f['name']) as $d) { $docs[] = $d; $tmps[] = $d['abs']; } // only its particulars
        }
        $out = $docs ? meEmailFromDocs($docs) : $none;
    } finally {
        foreach ($tmps as $t) @unlink($t);
    }
    $_SESSION[$key] = $out;
    return $out;
}
