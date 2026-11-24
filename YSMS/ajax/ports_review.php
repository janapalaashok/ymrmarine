<?php
/**
 * Admin Controls > Ports > Review ports (ports_review.php). POST only, Admin + CSRF (checkAuth).
 *   action=suggest  ports (JSON [{id, name, country}], at most 80) → AI suggestions
 *                   [{id, name, country, note}] — nothing is saved
 *   action=apply    rows (JSON [{id, name, country}]) → each port's name / country updated
 *                   (prepared statements; a name another port already has is refused)
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';
function prOut(array $b, int $code = 200): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') prOut(['ok' => false, 'error' => 'POST only.'], 405);
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') prOut(['ok' => false, 'error' => 'Not allowed.'], 403);
$uid = (int)($_SESSION['user_id'] ?? 0);
session_write_close();

$action = (string)($_POST['action'] ?? '');
$db = getDB();

try {
    if ($action === 'suggest') {
        require_once dirname(__DIR__) . '/includes/gemini_json.php';
        $in = json_decode((string)($_POST['ports'] ?? ''), true);
        if (!is_array($in) || !$in || count($in) > 80) prOut(['ok' => false, 'error' => 'Send 1 to 80 ports.'], 400);
        $ids = array_map(fn($p) => (int)($p['id'] ?? 0), $in);
        // the names / countries are read again from the database, not taken from the request
        $q = $db->prepare('SELECT id, port_name, country FROM ports WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute($ids);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) prOut(['ok' => false, 'error' => 'Ports not found.'], 404);
        $list = implode("\n", array_map(fn($r) => json_encode(['id' => (int)$r['id'], 'name' => $r['port_name'], 'country' => $r['country']], JSON_UNESCAPED_UNICODE), $rows));
        $prompt = "PORT LIST CHECK for a marine (bunker) survey company. Each line is one sea port / anchorage / terminal from its database:\n$list\n\n"
            . "For EVERY id return the name and the country, in UPPER CASE:\n"
            . "- name: the same name, with only clear spelling mistakes corrected (e.g. VISAKAPATNAM -> VISAKHAPATNAM). Keep words like PORT, ANCHORAGE, OPL, TERMINAL and any text in brackets. Do not translate, shorten or rename a port to another name.\n"
            . "- country: the country the port is in, as its common English short name (e.g. INDIA, UNITED ARAB EMIRATES, SOUTH KOREA). If you are not sure, null.\n"
            . "- note: a few words when you changed the name or are unsure, else empty.\n"
            . "Return ONE JSON object only: {\"items\": [{\"id\": 1, \"name\": \"...\", \"country\": \"...\", \"note\": \"\"}]}";
        @set_time_limit(180);
        $res = geminiJson([['text' => $prompt]], 150, 'ports review');
        if (!$res['ok']) prOut(['ok' => false, 'error' => $res['message'] ?? 'The AI check failed. Please try again.'], 502);
        $known = array_flip(array_map(fn($r) => (int)$r['id'], $rows));
        $out = [];
        foreach ((array)($res['data']['items'] ?? []) as $it) {
            $id = (int)($it['id'] ?? 0);
            if (!isset($known[$id]) || isset($out[$id])) continue;
            $name = portCaps(is_scalar($it['name'] ?? null) ? (string)$it['name'] : '');
            $country = portCaps(is_scalar($it['country'] ?? null) ? (string)$it['country'] : '');
            $out[$id] = ['id' => $id, 'name' => mb_substr($name, 0, 150), 'country' => mb_substr($country, 0, 100),
                'note' => mb_substr(trim((string)(is_scalar($it['note'] ?? null) ? $it['note'] : '')), 0, 160)];
        }
        prOut(['ok' => true, 'items' => array_values($out)]);
    }

    if ($action === 'apply') {
        $in = json_decode((string)($_POST['rows'] ?? ''), true);
        if (!is_array($in) || !$in || count($in) > 2000) prOut(['ok' => false, 'error' => 'Nothing to save.'], 400);
        $get = $db->prepare('SELECT port_name, country FROM ports WHERE id = ?');
        $dup = $db->prepare('SELECT id, port_name FROM ports WHERE port_name = ? AND id <> ? LIMIT 1');
        $upd = $db->prepare('UPDATE ports SET port_name = ?, country = ? WHERE id = ?');
        $done = 0; $failed = [];
        foreach ($in as $r) {
            $id = (int)($r['id'] ?? 0);
            $name = portCaps(is_scalar($r['name'] ?? null) ? (string)$r['name'] : '');
            $country = portCaps(is_scalar($r['country'] ?? null) ? (string)$r['country'] : '');
            if ($id <= 0) continue;
            if ($name === '' || mb_strlen($name) > 150) { $failed[] = ['id' => $id, 'error' => 'Port name is empty or too long.']; continue; }
            if ($country === '' || mb_strlen($country) > 100 || preg_match('/^NOT\s*SPECIFIED$/', $country)) { $failed[] = ['id' => $id, 'error' => 'Choose the country.']; continue; }
            $get->execute([$id]);
            $old = $get->fetch(PDO::FETCH_ASSOC);
            if (!$old) { $failed[] = ['id' => $id, 'error' => 'This port no longer exists.']; continue; }
            if ($old['port_name'] === $name && (string)$old['country'] === $country) continue;
            $dup->execute([$name, $id]);
            if ($d = $dup->fetch(PDO::FETCH_ASSOC)) { $failed[] = ['id' => $id, 'error' => 'Port #' . (int)$d['id'] . ' already has the name ' . $d['port_name'] . '.']; continue; }
            try {
                $upd->execute([$name, $country, $id]);
                $done++;
            } catch (PDOException $e) {
                error_log('ports review apply #' . $id . ': ' . $e->getMessage());
                $failed[] = ['id' => $id, 'error' => 'Could not save this port.'];
            }
        }
        error_log(sprintf('ports review: user %d updated %d ports, %d not saved', $uid, $done, count($failed)));
        prOut(['ok' => true, 'updated' => $done, 'failed' => $failed]);
    }
} catch (Throwable $e) {
    error_log('ajax/ports_review ' . $action . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    prOut(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
prOut(['ok' => false, 'error' => 'Unknown action.'], 400);
