<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/agents_mail.php';
require_once __DIR__ . '/../includes/mailer.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'Admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin only.']);
    exit;
}

$db = getDB();
ensureAgentsTable($db);

$action = $_POST['action'] ?? $_GET['action'] ?? 'lookup';
$surveyId = (int)($_POST['survey_id'] ?? $_GET['survey_id'] ?? 0);

if ($surveyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid survey.']);
    exit;
}

// Load survey context
try {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, p.port_name, t.type_name, u.full_name AS surveyor_name
        FROM surveys s
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN ports p ON s.port_id = p.id
        LEFT JOIN survey_types t ON s.survey_type_id = t.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'DB error.']);
    exit;
}

if (!$survey) {
    echo json_encode(['success' => false, 'message' => 'Survey not found.']);
    exit;
}

$agentName = normalizeAgentName((string)($survey['agent_name'] ?? ''));
if ($agentName === '') {
    echo json_encode(['success' => false, 'message' => 'Agent name is empty on this vessel.']);
    exit;
}

if ($action === 'lookup') {
    $emails = getAgentEmailsForSurvey($db, $agentName);
    $sentAt = $survey['agent_email_sent_at'] ?? null;
    echo json_encode([
        'success' => true,
        'agent_name' => $agentName,
        'emails' => $emails,
        'has_emails' => count($emails) > 0,
        'agent_email_sent_at' => $sentAt,
        'sent_label' => $sentAt ? date('d M Y, h:i A', strtotime($sentAt)) : null,
    ]);
    exit;
}

if ($action === 'send') {
    $emails = [];
    // From POST: emails as comma string or array
    if (!empty($_POST['emails']) && is_array($_POST['emails'])) {
        foreach ($_POST['emails'] as $e) {
            $emails = array_merge($emails, parseAgentEmails((string)$e));
        }
    } elseif (!empty($_POST['emails'])) {
        $emails = parseAgentEmails((string)$_POST['emails']);
    }
    // primary + optional fields
    if (!empty($_POST['email_primary'])) {
        $emails = array_merge(parseAgentEmails((string)$_POST['email_primary']), $emails);
    }
    if (!empty($_POST['email_optional'])) {
        $emails = array_merge($emails, parseAgentEmails((string)$_POST['email_optional']));
    }

    if (empty($emails)) {
        // try stored
        $emails = getAgentEmailsForSurvey($db, $agentName);
    }

    if (empty($emails)) {
        echo json_encode(['success' => false, 'need_email' => true, 'message' => 'Enter agent email.']);
        exit;
    }

    // Save / merge into agents master
    upsertAgentEmails($db, $agentName, $emails);
    $emails = getAgentEmailsForSurvey($db, $agentName);

    // Admin profile for From name / Reply-To
    $adminUser = ['full_name' => $_SESSION['full_name'] ?? 'Admin', 'email' => '', 'phone' => ''];
    try {
        $u = $db->prepare("SELECT full_name, email, phone FROM users WHERE id = ? LIMIT 1");
        $u->execute([(int)$_SESSION['user_id']]);
        $row = $u->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $adminUser['full_name'] = $row['full_name'] ?: $adminUser['full_name'];
            $adminUser['email'] = trim((string)($row['email'] ?? ''));
            $adminUser['phone'] = trim((string)($row['phone'] ?? ''));
        }
    } catch (Throwable $e) {
        // phone column may be missing — try without it
        try {
            $u = $db->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
            $u->execute([(int)$_SESSION['user_id']]);
            $row = $u->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $adminUser['full_name'] = $row['full_name'] ?: $adminUser['full_name'];
                $adminUser['email'] = trim((string)($row['email'] ?? ''));
            }
        } catch (Throwable $e2) {}
    }

    $result = sendAgentLatestUpdateEmail($db, $survey, $emails, $adminUser);
    if (empty($result['ok'])) {
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Send failed']);
        exit;
    }

    try {
        $db->prepare("UPDATE surveys SET agent_email_sent_at = NOW() WHERE id = ?")->execute([$surveyId]);
    } catch (Throwable $e) {
        error_log('agent_email_sent_at: ' . $e->getMessage());
    }

    $sentLabel = date('d M Y, h:i A');
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'emails' => $emails,
        'agent_email_sent_at' => date('Y-m-d H:i:s'),
        'sent_label' => $sentLabel,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
