<?php
/**
 * Assign Vessel form: "Paste Email Content" → Auto-Fill. POST-only so
 * checkAuth()'s CSRF check applies — this triggers a billable external API
 * call, unlike the read-only GET lookups elsewhere in ajax/.
 *
 * Never logs the pasted email body — only lengths/exception messages, per
 * the feature's privacy requirements. The email itself is never persisted;
 * it exists only for the duration of this request.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email_autofill.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Client'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Defensive upper bound in case the SDK's own request timeout isn't honored
// by the underlying HTTP transport — this endpoint should never hang the
// browser indefinitely.
@set_time_limit(35);

$emailText = trim((string)($_POST['email_text'] ?? ''));

if ($emailText === '') {
    echo json_encode(['success' => false, 'message' => 'Please paste the email content first.']);
    exit;
}
if (mb_strlen($emailText) < 20) {
    echo json_encode(['success' => false, 'message' => 'That doesn\'t look like enough text to extract information from. Please paste the full email.']);
    exit;
}
// Generous upper bound — protects against pasting something enormous by
// accident, without meaningfully limiting a real email.
if (mb_strlen($emailText) > 20000) {
    $emailText = mb_substr($emailText, 0, 20000);
}

$db = getDB();

try {
    $extracted = extractSurveyInfoFromEmail($emailText);
} catch (EmailAutoFillException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    error_log('email_autofill.php: unexpected error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Something went wrong analyzing the email. Please try again.']);
    exit;
}

// Same role-scoped client visibility as the rest of this form — a Client
// user's own company is fixed and never offered as a match target, and
// staff-only surveyor assignment is intentionally never touched by this
// feature (it isn't something a client email can safely determine).
$is_client_role = (($_SESSION['role'] ?? '') === 'Client');
try {
    if ($is_client_role) {
        $clients = [];
    } else {
        $clients = $db->query("SELECT id, company_name FROM clients ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
    $ports = $db->query("SELECT id, port_name, country FROM ports ORDER BY port_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $surveyTypes = $db->query("SELECT id, type_name FROM survey_types")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('email_autofill.php: form-options lookup failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not match the extracted information. Please try again.']);
    exit;
}

$matched = matchExtractedInfoToFormOptions($extracted, $clients, $ports, $surveyTypes);

echo json_encode(['success' => true, 'data' => $matched]);
