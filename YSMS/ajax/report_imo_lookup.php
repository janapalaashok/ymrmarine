<?php
/**
 * Report Generator — "IMO Lookup": the vessel particulars of an IMO number, found the same way
 * as on the Survey Report page (srLookupFast: saved lookup → the latest survey report of this
 * IMO → AI + web search). POST only, logged-in Admin / Super Admin / Surveyor + CSRF (checkAuth).
 *   imo  → { ok, imo, fields: { vessel_name, port_of_registry, grt, nrt, ... }, sources, from, at }
 * The page puts the values into the form for the user to check; no report is changed here.
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';
function rilOut(array $b, int $code = 200): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rilOut(['ok' => false, 'error' => 'POST only.'], 405);
checkAuth();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin', 'Surveyor'], true)) rilOut(['ok' => false, 'error' => 'Not allowed.'], 403);

$last = (int)($_SESSION['rg_lookup_at'] ?? 0);
if (time() - $last < 5) rilOut(['ok' => false, 'error' => 'Please wait a few seconds before the next lookup.'], 429);
$_SESSION['rg_lookup_at'] = time();
session_write_close();

require_once dirname(__DIR__) . '/includes/vessel_lookup.php';

try {
    // field key → type, as the Survey Report form checks them (only the particulars the lookup finds)
    $types = [];
    foreach (array_keys(SR_LOOKUP_FIELDS) as $k) if (isset(SF_FIELDS[$k])) $types[$k] = SF_FIELDS[$k][1];
    @set_time_limit(120);
    $res = srLookupFast(getDB(), (string)($_POST['imo'] ?? ''), $types, 0);
    rilOut($res, $res['ok'] ? 200 : 422);
} catch (Throwable $e) {
    error_log('ajax/report_imo_lookup: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    rilOut(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
