<?php
/**
 * GET ?id=<survey id> → { ok, email, file, by: 'text'|'ai'|'', note } — the Master's email from the
 * vessel's Ships Particulars document (includes/master_email.php), for "Email to Master for
 * Redelivery" on report_detail.php ("Fetch Master Email").
 * POST ?id=<survey id> + file `particulars` → the same, read from the uploaded file only ("Upload
 * Ships Particulars"; a ZIP: only its Ships Particulars files). The file is not stored. CSRF checked
 * by checkAuth() for every POST.
 * Read only. Same people as the button: not Clients; Admin /
 * Super Admin any vessel, a Surveyor only a vessel assigned to them.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/master_email.php';
checkAuth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
$q = $db->prepare('SELECT id FROM surveys WHERE id = ?');
$q->execute([$id]);
if ($id <= 0 || !$q->fetchColumn()) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Vessel not found.']); exit; }
$allowed = in_array($role, ['Admin', 'Super Admin'], true) || ($role === 'Surveyor' && isSurveyorAssignedToSurvey($db, $id, $uid));
if (!$allowed) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not allowed.']); exit; }
try {
    @set_time_limit(90);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        [$r, $err] = meEmailFromUpload($_FILES['particulars'] ?? []);
        if ($err !== null) { http_response_code(422); echo json_encode(['ok' => false, 'email' => '', 'error' => $err, 'note' => $err]); exit; }
    } else {
        $r = meMasterEmail($db, $id);
    }
    echo json_encode(['ok' => true] + $r);
} catch (Throwable $e) {
    error_log('master_email: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'email' => '', 'note' => 'Could not read the Ships Particulars document.']);
}
