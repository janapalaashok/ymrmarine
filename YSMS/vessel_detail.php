<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
require_once 'includes/agents_mail.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();
try { ensureAgentsTable($db); } catch (Throwable $e) {}
$error = '';
$success = '';
$current_user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$is_admin = ($role === 'Admin');
$edit_mode = $is_admin && isset($_GET['edit']);

// Safety net: ensure live-status columns exist (see database/migration_custom_live_status.sql)
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'custom_live_status'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN custom_live_status TEXT NULL DEFAULT NULL");
    }
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'status_updated_by'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN status_updated_by INT(11) NULL DEFAULT NULL");
    }
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'status_updated_at'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN status_updated_at DATETIME NULL DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('vessel_detail.php live-status column check: ' . $e->getMessage());
}

// 🌟 అడ్మిన్ మాత్రమే వెసెల్/సర్వే వివరాలను మార్చగలరు (existing workflow, this page)
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_survey_details'])) {
    $u_vessel_name = trim($_POST['vessel_name']);
    $u_client_id = (int)$_POST['client_id'];
    $u_agent_name = trim($_POST['agent_name']);
    $u_port_id = (int)$_POST['port_id'];
    $u_survey_type_id = (int)$_POST['survey_type_id'];
    $u_surveyor_id = (int)$_POST['surveyor_id'];
    $u_assign_date = trim($_POST['assign_date']);
    $u_remarks = trim($_POST['remarks']);

    try {
        $update_details = $db->prepare("
            UPDATE surveys
            SET vessel_name = ?, client_id = ?, agent_name = ?, port_id = ?, survey_type_id = ?, surveyor_id = ?, assign_date = ?, remarks = ?
            WHERE id = ?
        ");
        if ($update_details->execute([$u_vessel_name, $u_client_id, $u_agent_name, $u_port_id, $u_survey_type_id, $u_surveyor_id, $u_assign_date, $u_remarks, $id])) {
            $success = "Vessel details updated successfully!";
            $edit_mode = false;
            try {
                $sid = (int)$u_surveyor_id;
                if ($sid > 0) {
                    createNotification($db, $sid, 'Vessel details edited',
                        ($_SESSION['full_name'] ?? 'Admin') . ' updated details for ' . $u_vessel_name . '.',
                        'edit', 'vessel_detail.php?id=' . (int)$id, (int)$current_user_id);
                }
            } catch (Throwable $ne) { error_log('edit notif: '.$ne->getMessage()); }
        } else {
            $error = "Failed to update vessel details.";
        }
    } catch (Exception $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}

// 🌟 సర్వేయర్ సబ్మిట్ చేసినప్పుడు custom_live_status అప్‌డేట్ చేసే పక్కా లాజిక్
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_latest_status'])) {
    $latest_status = trim($_POST['latest_status']);
    
    try {
        $update_status = $db->prepare("
            UPDATE surveys 
            SET custom_live_status = ?, status_updated_by = ?, status_updated_at = NOW() 
            WHERE id = ?
        ");
        if ($update_status->execute([$latest_status, $current_user_id, $id])) {
            $success = "Latest update registered successfully!";
            try {
                // Surveyor live status → notify admins
                $vname = '';
                try {
                    $vs = $db->prepare('SELECT vessel_name, surveyor_id FROM surveys WHERE id = ?');
                    $vs->execute([$id]);
                    $vr = $vs->fetch(PDO::FETCH_ASSOC) ?: [];
                    $vname = $vr['vessel_name'] ?? ('#' . $id);
                } catch (Throwable $x) {}
                $who = $_SESSION['full_name'] ?? 'Surveyor';
                if (($_SESSION['role'] ?? '') === 'Surveyor') {
                    notifyAllAdmins($db, 'Live status updated',
                        $who . ' updated ' . $vname . ': ' . $latest_status,
                        'status', 'vessel_detail.php?id=' . (int)$id, (int)$current_user_id);
                } else {
                    // Admin updated status → notify assigned surveyor
                    $sid = (int)($vr['surveyor_id'] ?? 0);
                    if ($sid > 0) {
                        createNotification($db, $sid, 'Live status updated by admin',
                            $who . ' set status on ' . $vname . ': ' . $latest_status,
                            'status', 'vessel_detail.php?id=' . (int)$id, (int)$current_user_id);
                    }
                }
            } catch (Throwable $ne) { error_log('status notif: '.$ne->getMessage()); }
        } else {
            $error = "Failed to update status.";
        }
    } catch (Exception $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}

// వెసెల్ పూర్తి వివరాలు లోడ్ చేయడం (LEFT JOIN తో మోడిఫైయర్ పేరు తెచ్చుకోవడం)
$stmt = $db->prepare("
    SELECT s.*, c.company_name, u.full_name as surveyor_name, p.port_name, t.type_name,
           uu.full_name as modifier_name
    FROM surveys s 
    JOIN clients c ON s.client_id = c.id 
    JOIN users u ON s.surveyor_id = u.id
    JOIN ports p ON s.port_id = p.id
    JOIN survey_types t ON s.survey_type_id = t.id
    LEFT JOIN users uu ON s.status_updated_by = uu.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$survey = $stmt->fetch();

if (!$survey) { die("Survey details missing."); }

// 🔒 Authorization check (IDOR fix): a non-admin (Surveyor) may only view
// surveys assigned to their own account. Previously checkAuth() only verified
// that *someone* was logged in, so any authenticated user could view any
// other surveyor's survey — including client and financial details — just by
// changing the ?id= in the URL. Admins are unrestricted, matching existing
// admin permissions elsewhere in this file.
if (!$is_admin && (int)$survey['surveyor_id'] !== (int)$current_user_id) {
        http_response_code(403);
    include 'includes/header.php';
    ?>
    <style>
        .no-access-wrap { min-height: calc(100vh - 160px); padding: 30px 20px; display: flex; align-items: center; justify-content: center; }
        .no-access-card { max-width: 520px; width: 100%; padding: 38px 24px; border-radius: 20px; background: #fff; border: 1px solid var(--border-color); box-shadow: 0 12px 28px rgba(15,23,42,.07); text-align: center; }
        .no-access-icon { width: 68px; height: 68px; margin: 0 auto 18px; border-radius: 18px; display: flex; align-items: center; justify-content: center; background: #fef2f2; color: #b91c1c; font-size: 28px; }
       .no-access-card .blue-action-btn { margin-left: auto; margin-right: auto; }
    </style>
    <div class="scroll-content">
        <?php $page_title = 'Access Denied'; $back_url = 'index.php'; $page_testid = 'no-access'; include 'includes/top_app_bar.php'; ?>
        <main class="no-access-wrap" data-testid="no-access-page">
            <section class="no-access-card">
                <div class="no-access-icon"><i class="fa-solid fa-lock"></i></div>
                <h2 class="fw-bold text-dark" style="font-size:22px;" data-testid="no-access-heading">Access Denied</h2>
                <p class="text-muted mb-4" style="font-size:13px;" data-testid="no-access-message">
                    You don't have permission to view this survey. It isn't assigned to your account.
                </p>
                <a href="index.php" class="blue-action-btn text-decoration-none" data-testid="no-access-home-link"><i class="fa-solid fa-house"></i> Return Home</a>
            </section>
        </main>
    </div>
    <?php
    include 'includes/nav.php';
    include 'includes/footer.php';
    exit;
}

// అడ్మిన్ ఎడిట్ ఫారమ్ కోసం డ్రాప్‌డౌన్ లిస్టులు (clients, ports, survey types, surveyors)
if ($edit_mode) {
    $clients_list = $db->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
    $ports_list = $db->query("SELECT id, port_name FROM ports ORDER BY port_name")->fetchAll();
    $survey_types_list = $db->query("SELECT id, type_name FROM survey_types ORDER BY type_name")->fetchAll();
    $surveyors_list = $db->query("SELECT u.id, u.full_name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'Surveyor' ORDER BY u.full_name")->fetchAll();
}

// డేట్ వాలిడేషన్ చెక్
$display_date = '--/--/----';
if (!empty($survey['assign_date']) && $survey['assign_date'] != '0000-00-00 00:00:00' && $survey['assign_date'] != '0000-00-00') {
    $display_date = date('d M Y', strtotime($survey['assign_date']));
}

// 🌟 Shared appointment details (assign-vessel data) for WhatsApp + Email
$combined_survey_type = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
$client_name = $survey['company_name'] ?? 'N/A';

$appointment_lines = [
    "Vessel Name: " . ($survey['vessel_name'] ?? 'N/A'),
    "Report No: " . (!empty($survey['report_number']) ? $survey['report_number'] : 'N/A'),
    "Client: " . $client_name,
    "Survey Type: " . $combined_survey_type,
    "Assigned Date: " . $display_date,
    "Agent: " . ($survey['agent_name'] ?? 'N/A'),
    "Surveyor: " . ($survey['surveyor_name'] ?? 'N/A'),
    "Port: " . ($survey['port_name'] ?? 'N/A'),
    "Status: " . ($survey['status'] ?? 'N/A'),
];
if (!empty($survey['custom_live_status'])) {
    $appointment_lines[] = "Latest Update: " . $survey['custom_live_status'];
}
if (!empty($survey['remarks'])) {
    $appointment_lines[] = "Admin Remarks: " . $survey['remarks'];
}
$appointment_block = implode("\n", $appointment_lines);

// WhatsApp — heading: New Survey Appointment
$whatsapp_text = "*New Survey Appointment*\n\n" . $appointment_block;
$whatsapp_number = preg_replace('/\D+/', '', WHATSAPP_NUMBER);
$whatsapp_share_url = $whatsapp_number !== ''
    ? 'https://wa.me/' . $whatsapp_number . '?text=' . rawurlencode($whatsapp_text)
    : 'https://api.whatsapp.com/send?text=' . rawurlencode($whatsapp_text);

// Email — default Outlook/mailto template with greeting + full assignment data
// (mailto: opens user's own mail app; "To" left blank for manual entry)
$mail_subject = "New Survey Appointment - " . ($survey['vessel_name'] ?? 'Vessel') . " (" . $client_name . ")";
$mail_body = "Dear Sir,\n\n"
    . "Good day to you.\n\n"
    . "Please find the below appointment from client (" . $client_name . ").\n\n"
    . $appointment_block . "\n\n"
    . "Thank you.\n"
    . "YMR Marine Solutions";
$mail_share_url = 'mailto:?subject=' . rawurlencode($mail_subject) . '&body=' . rawurlencode($mail_body);

include 'includes/header.php';
?>

<style>
    .status-update-card { background: #ffffff; margin: 15px 20px; border-radius: 16px; padding: 20px; border: 1px solid var(--border-color); }
    .small-status-btn { background: #3b32b3; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .status-input { width: 100%; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 12px; font-size: 13px; background: #f8fafc; outline: none; resize: none; }
    .vessel-edit-btn { display: inline-flex; align-items: center; gap: 6px; background: #fff; color: #00a3df; border: 1px solid #00a3df; border-radius: 8px; padding: 5px 12px; font-size: 11px; font-weight: 700; text-decoration: none; }
    .vessel-edit-btn:hover { background: #00a3df; color: #fff; }
    /* WhatsApp brand green */
    .btn-whatsapp {
        display: inline-flex; align-items: center; gap: 6px;
        background: #25D366; color: #fff !important; border: 1px solid #128C7E;
        border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700;
        text-decoration: none !important; box-shadow: 0 1px 3px rgba(18,140,126,.25);
    }
    .btn-whatsapp:hover { background: #128C7E; color: #fff !important; }
    /* Microsoft / Outlook blue */
    .btn-email {
        display: inline-flex; align-items: center; gap: 6px;
        background: #0078D4; color: #fff !important; border: 1px solid #106EBE;
        border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700;
        text-decoration: none !important; box-shadow: 0 1px 3px rgba(0,120,212,.25);
    }
    .btn-email:hover { background: #106EBE; color: #fff !important; }

    /* 🖥️ Desktop: main details | upload side-by-side; live status content-height only */
    @media (min-width: 992px) {
        .detail-main-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            width: 100%;
            max-width: none;
            margin: 10px 16px 12px;
            align-items: stretch;
            box-sizing: border-box;
            padding-right: 16px;
        }
        .detail-main-row > .info-table-list,
        .detail-main-row > .form-box {
            margin: 0 !important;
            max-width: none !important;
            height: 100%;
        }
        .detail-main-row > .form-box {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 2px 8px rgba(15,23,42,.04);
        }
        .status-update-card {
            margin: 0 16px 16px !important;
            max-width: none !important;
            width: calc(100% - 32px);
            padding: 14px 18px !important;
            height: auto !important;
            align-self: start;
        }
        .info-row { padding: 10px 16px; }
        .blue-action-btn { max-width: none; width: 100%; }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Vessel Survey Details'; $back_url = 'vessels.php'; $page_testid = 'vessel-detail'; include 'includes/top_app_bar.php'; ?>

    <?php if($success): ?><div class="alert alert-success mx-3 mt-3 py-2" style="font-size:12px;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger mx-3 mt-3 py-2" style="font-size:12px;"><?= $error ?></div><?php endif; ?>

    <?php if ($edit_mode): ?>
        <div class="info-table-list shadow-sm p-3">
            <form action="vessel_detail.php?id=<?= $survey['id'] ?>&edit=1" method="POST">
                <div class="mb-2">
                    <label class="form-label fw-bold text-secondary" style="font-size:11px;">Vessel Name</label>
                    <input type="text" name="vessel_name" class="form-control form-control-sm" value="<?= sanitize($survey['vessel_name']) ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-bold text-secondary" style="font-size:11px;">Client</label>
                    <select name="client_id" class="form-select form-select-sm" required>
                        <?php foreach ($clients_list as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$survey['client_id']) ? 'selected' : '' ?>><?= sanitize($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-bold text-secondary" style="font-size:11px;">Assigned Date</label>
                    <input type="date" name="assign_date" class="form-control form-control-sm" value="<?= sanitize(date('Y-m-d', strtotime($survey['assign_date']))) ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-bold text-secondary" style="font-size:11px;">Agent Name</label>
                    <input type="text" name="agent_name" class="form-control form-control-sm" value="<?= sanitize($survey['agent_name']) ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-bold text-secondary" style="font-size:11px;">Surveyor</label>
                    <select name="surveyor_id" class="form-select form-select-sm" required>
                        <?php foreach ($surveyors_list as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === (int)$survey['surveyor_id']) ? 'selected' : '' ?>><?= sanitize($s['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-bold text-secondary" style="font-size:11px;">Survey Type</label>
                    <select name="survey_type_id" class="form-select form-select-sm" required>
                        <?php foreach ($survey_types_list as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === (int)$survey['survey_type_id']) ? 'selected' : '' ?>><?= sanitize($t['type_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-bold text-secondary" style="font-size:11px;">Port</label>
                    <select name="port_id" class="form-select form-select-sm" required>
                        <?php foreach ($ports_list as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$survey['port_id']) ? 'selected' : '' ?>><?= sanitize($p['port_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold text-secondary" style="font-size:11px;">Admin Remarks</label>
                    <textarea name="remarks" class="form-control form-control-sm" rows="2"><?= !empty($survey['remarks']) ? sanitize($survey['remarks']) : '' ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" name="update_survey_details" class="small-status-btn flex-fill">Save Changes</button>
                    <a href="vessel_detail.php?id=<?= $survey['id'] ?>" class="btn btn-outline-secondary btn-sm flex-fill text-center">Cancel</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="detail-main-row">
        <div class="info-table-list shadow-sm">
            <div class="text-end p-2 d-flex justify-content-end gap-2 flex-wrap">
                <a href="<?= sanitize($whatsapp_share_url) ?>" target="_blank" rel="noopener" class="btn-whatsapp" data-testid="vessel-detail-whatsapp-link" title="Send via WhatsApp"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                <a href="<?= sanitize($mail_share_url) ?>" class="btn-email" data-testid="vessel-detail-mail-link" title="Send via Email (Outlook)"><i class="fa-solid fa-envelope"></i> Email</a>
                <?php if ($is_admin): ?>
                    <a href="vessel_detail.php?id=<?= $survey['id'] ?>&edit=1" class="vessel-edit-btn" data-testid="vessel-detail-edit-link"><i class="fa-solid fa-pen"></i> Edit</a>
                <?php endif; ?>
            </div>
            <div class="info-row"><span class="info-label">Vessel Name</span><span class="info-value"><?= sanitize($survey['vessel_name']) ?></span></div>
            <div class="info-row"><span class="info-label">Report No</span><span class="info-value fw-bold text-dark"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></span></div>
            <div class="info-row"><span class="info-label">Client Name</span><span class="info-value"><?= sanitize($survey['company_name']) ?></span></div>
            <div class="info-row"><span class="info-label">Survey Type</span><span class="info-value text-primary"><?= sanitize($combined_survey_type) ?></span></div>
            <div class="info-row"><span class="info-label">Assigned Date</span><span class="info-value fw-bold text-dark"><?= $display_date ?></span></div>
            <div class="info-row" style="align-items:flex-start;">
                <span class="info-label">Agent Name</span>
                <span class="info-value">
                    <?= sanitize($survey['agent_name']) ?>
                    <?php if ($is_admin && !empty($survey['agent_name'])): ?>
                        <?php
                            $agent_sent_at = $survey['agent_email_sent_at'] ?? null;
                            $agent_sent_label = $agent_sent_at ? date('d M Y, h:i A', strtotime($agent_sent_at)) : '';
                        ?>
                        <div id="agentEmailActions" style="margin-top:6px;font-size:11.5px;line-height:1.45;" data-survey-id="<?= (int)$survey['id'] ?>">
                            <span id="agentEmailStatusWrap" style="<?= $agent_sent_at ? '' : 'display:none;' ?>">
                                <span class="text-success" id="agentEmailSentLabel"><i class="fa-solid fa-circle-check"></i> Email sent to agent on <span id="agentEmailSentAt"><?= sanitize($agent_sent_label) ?></span></span>
                                <button type="button" id="btnAgentEmailAgain" class="btn btn-link p-0 ms-1" style="font-size:11.5px;vertical-align:baseline;">Send email again</button>
                            </span>
                            <button type="button" id="btnAgentEmailSend" class="btn btn-link p-0" style="font-size:11.5px;<?= $agent_sent_at ? 'display:none;' : '' ?>">Send email to agent for latest update</button>
                        </div>
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row"><span class="info-label">Surveyor Name</span><span class="info-value text-primary"><?= sanitize($survey['surveyor_name']) ?></span></div>
            <div class="info-row"><span class="info-label">Port Name</span><span class="info-value"><?= sanitize($survey['port_name']) ?></span></div>

            <div class="info-row"><span class="info-label" style="color: #ea580c;">Admin Remarks</span><span class="info-value text-dark fw-semibold">
                <?php
                    $remarks_full = !empty($survey['remarks']) ? $survey['remarks'] : '';
                    if ($remarks_full === '') {
                        echo 'N/A';
                    } elseif (strlen($remarks_full) > 150) {
                        echo sanitize(substr($remarks_full, 0, 150)) . '... <a href="#" class="text-primary fw-bold" data-bs-toggle="modal" data-bs-target="#remarksModal" onclick="event.stopPropagation();" data-testid="vessel-detail-read-more-remarks">Read More</a>';
                    } else {
                        echo sanitize($remarks_full);
                    }
                ?>
            </span></div>
        </div>
    <div class="form-box mx-3 p-3 bg-white rounded-3 border shadow-sm mt-2">
        <div class="fw-bold text-dark mb-3" style="font-size: 14px;"><i class="fa-solid fa-cloud-arrow-up text-primary"></i> Upload Required Reports</div>
        
    <?php
    $assign_att = $survey['attachment_path'] ?? ($survey['assignment_attachment'] ?? '');
    if (!empty($assign_att)):
        $att_fs = (strpos($assign_att, '/') === 0 || preg_match('#^[A-Za-z]:#', $assign_att)) ? $assign_att : (__DIR__ . '/' . ltrim($assign_att, '/'));
        $att_url = $assign_att;
        $att_label = basename($assign_att);
        // Strip numeric time prefix for display if present
        $att_label_clean = preg_replace('/^[0-9]+_/', '', $att_label);
        if (is_file($att_fs)):
?>
    <div class="px-3 mb-3">
        <div class="bg-white p-3 rounded-3 border shadow-sm d-flex align-items-center justify-content-between">
            <div>
                <div class="fw-bold text-dark" style="font-size:13px;"><i class="fa-solid fa-paperclip text-primary me-1"></i> Assignment Attachment</div>
                <div class="text-muted" style="font-size:11px;"><?= sanitize($att_label_clean) ?></div>
            </div>
            <a href="<?= sanitize($att_url) ?>" download="<?= sanitize($att_label_clean) ?>" class="btn btn-sm btn-light border text-primary"><i class="fa-solid fa-download"></i></a>
        </div>
    </div>
    <?php endif; endif; ?>

<form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
            <input type="hidden" name="current_status" value="<?= $survey['status'] ?>">
            <div class="mb-2">
                <label class="form-label fw-bold text-secondary" style="font-size:11px;">1. Formal Report (PDF) *</label>
                <input type="file" name="pdf_report" class="form-control form-control-sm" accept=".pdf" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary" style="font-size:11px;">2. Calculation Sheet (Excel) *</label>
                <input type="file" name="excel_report" class="form-control form-control-sm" accept=".xlsx, .xls" required>
            </div>
            <div class="mt-3 mb-2">
                <label class="form-label small fw-semibold text-muted">Additional Files (optional)</label>
                <input type="file" name="additional_files[]" class="form-control form-control-sm" accept=".xlsx,.xls,.docx,.doc,.pdf" multiple>
                <div class="text-muted" style="font-size:11px;">Excel, Word or PDF. You can select multiple files.</div>
            </div>
            <button type="submit" class="blue-action-btn w-100" style="background: #1e3a8a;">Submit & Send</button>
        </form>
    </div>
        </div><!-- /.detail-main-row -->
        <?php if (!empty($remarks_full) && strlen($remarks_full) > 150): ?>
        <div class="modal fade" id="remarksModal" tabindex="-1" aria-hidden="true" data-testid="vessel-detail-remarks-modal">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Admin Remarks</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="font-size: 13.5px; white-space: pre-wrap;"><?= sanitize($remarks_full) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="status-update-card shadow-sm">
        <form action="vessel_detail.php?id=<?= $survey['id'] ?>" method="POST">
            <div class="mb-3">
                <label class="text-dark fw-bold mb-2" style="font-size:13px;">
                    <i class="fa-solid fa-pen-fancy text-primary me-1"></i> Latest Update / Live Status
                </label>
                <textarea name="latest_status" class="status-input" rows="2" placeholder="Update the current operational event..." required><?= !empty($survey['custom_live_status']) ? sanitize($survey['custom_live_status']) : '' ?></textarea>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <div style="font-size: 11px; color: var(--text-muted);">
                    <?php if (!empty($survey['status_updated_at'])): ?>
                        Last Active: <span class="text-dark fw-bold"><?= date('d M - h:i A', strtotime($survey['status_updated_at'])) ?></span> By <span class="text-primary fw-bold"><?= sanitize($survey['modifier_name'] ?? 'User') ?></span>
                    <?php endif; ?>
                </div>
                <button type="submit" name="update_latest_status" class="small-status-btn">Update</button>
            </div>
        </form>
    </div>




</div>

<?php if ($is_admin): ?>
<style>
    .agent-email-modal-backdrop {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45);
        z-index: 2000; align-items: center; justify-content: center; padding: 16px;
    }
    .agent-email-modal-backdrop.show { display: flex; }
    .agent-email-modal {
        background: #fff; border-radius: 16px; width: min(420px, 100%);
        box-shadow: 0 20px 40px rgba(15,23,42,.2); overflow: hidden;
    }
    .agent-email-modal-head {
        padding: 14px 16px; border-bottom: 1px solid #e2e8f0;
        font-weight: 700; font-size: 15px; color: #0f172a;
        display: flex; justify-content: space-between; align-items: center;
    }
    .agent-email-modal-body { padding: 16px; }
    .agent-email-modal-body label { font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px; }
    .agent-email-modal-body input {
        width: 100%; border: 1px solid #cbd5e1; border-radius: 10px;
        padding: 10px 12px; font-size: 14px; margin-bottom: 12px;
    }
    .agent-email-modal-foot {
        padding: 12px 16px; border-top: 1px solid #e2e8f0;
        display: flex; gap: 8px; justify-content: flex-end;
    }
    .agent-email-modal-foot .btn-cancel {
        border: 1px solid #e2e8f0; background: #fff; border-radius: 10px; padding: 8px 14px; font-weight: 600;
    }
    .agent-email-modal-foot .btn-send {
        border: 0; background: #3b32b3; color: #fff; border-radius: 10px; padding: 8px 14px; font-weight: 600;
    }
    #agentEmailActions .btn-link { color: #3b32b3; text-decoration: none; font-weight: 600; }
    #agentEmailActions .btn-link:hover { text-decoration: underline; }
</style>
<div class="agent-email-modal-backdrop" id="agentEmailModal" role="dialog" aria-modal="true">
    <div class="agent-email-modal">
        <div class="agent-email-modal-head">
            <span><i class="fa-solid fa-envelope me-1 text-primary"></i> Agent email</span>
            <button type="button" id="agentEmailModalClose" style="border:0;background:transparent;font-size:18px;line-height:1;">&times;</button>
        </div>
        <div class="agent-email-modal-body">
            <p class="text-muted mb-2" style="font-size:12px;">No email on file for this agent. Enter email(s) to send the latest update. They will also be saved under Admin Controls → Agents.</p>
            <label>Agent email *</label>
            <input type="email" id="agentEmailPrimary" placeholder="agent@example.com" required>
            <label>Additional emails <span class="text-muted fw-normal">(optional, comma-separated)</span></label>
            <input type="text" id="agentEmailOptional" placeholder="cc1@example.com, cc2@example.com">
            <div id="agentEmailModalError" class="text-danger" style="font-size:12px;display:none;"></div>
        </div>
        <div class="agent-email-modal-foot">
            <button type="button" class="btn-cancel" id="agentEmailModalCancel">Cancel</button>
            <button type="button" class="btn-send" id="agentEmailModalSend">Save &amp; Send</button>
        </div>
    </div>
</div>
<script>
(function() {
    var surveyId = <?= (int)$survey['id'] ?>;
    var $modal = document.getElementById('agentEmailModal');
    if (!$modal) return;

    function showSent(label) {
        var wrap = document.getElementById('agentEmailStatusWrap');
        var sendBtn = document.getElementById('btnAgentEmailSend');
        var at = document.getElementById('agentEmailSentAt');
        if (at) at.textContent = label || '';
        if (wrap) wrap.style.display = '';
        if (sendBtn) sendBtn.style.display = 'none';
    }

    function openModal() {
        document.getElementById('agentEmailModalError').style.display = 'none';
        document.getElementById('agentEmailPrimary').value = '';
        document.getElementById('agentEmailOptional').value = '';
        $modal.classList.add('show');
    }
    function closeModal() { $modal.classList.remove('show'); }

    function doSend(extraData) {
        var body = new URLSearchParams();
        body.set('action', 'send');
        body.set('survey_id', String(surveyId));
        if (extraData) {
            Object.keys(extraData).forEach(function(k) { body.set(k, extraData[k]); });
        }
        return fetch('ajax/send_agent_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); });
    }

    function startSendFlow() {
        var sendBtn = document.getElementById('btnAgentEmailSend');
        var againBtn = document.getElementById('btnAgentEmailAgain');
        [sendBtn, againBtn].forEach(function(b) { if (b) b.disabled = true; });

        fetch('ajax/send_agent_update.php?action=lookup&survey_id=' + surveyId, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) {
                    alert((d && d.message) ? d.message : 'Could not load agent emails');
                    return;
                }
                if (d.has_emails) {
                    if (!confirm('Send latest update email to:\n' + (d.emails || []).join(', ') + ' ?')) return;
                    return doSend({}).then(function(res) {
                        if (res && res.success) {
                            showSent(res.sent_label);
                            alert(res.message || 'Email sent.');
                        } else if (res && res.need_email) {
                            openModal();
                        } else {
                            alert((res && res.message) ? res.message : 'Send failed');
                        }
                    });
                } else {
                    openModal();
                }
            })
            .catch(function() { alert('Network error'); })
            .finally(function() {
                [sendBtn, againBtn].forEach(function(b) { if (b) b.disabled = false; });
            });
    }

    var btn = document.getElementById('btnAgentEmailSend');
    var again = document.getElementById('btnAgentEmailAgain');
    if (btn) btn.addEventListener('click', function(e) { e.preventDefault(); startSendFlow(); });
    if (again) again.addEventListener('click', function(e) { e.preventDefault(); startSendFlow(); });

    document.getElementById('agentEmailModalClose').addEventListener('click', closeModal);
    document.getElementById('agentEmailModalCancel').addEventListener('click', closeModal);
    $modal.addEventListener('click', function(e) { if (e.target === $modal) closeModal(); });

    document.getElementById('agentEmailModalSend').addEventListener('click', function() {
        var primary = (document.getElementById('agentEmailPrimary').value || '').trim();
        var optional = (document.getElementById('agentEmailOptional').value || '').trim();
        var err = document.getElementById('agentEmailModalError');
        if (!primary) {
            err.textContent = 'Agent email is required.';
            err.style.display = 'block';
            return;
        }
        var btnSend = this;
        btnSend.disabled = true;
        doSend({ email_primary: primary, email_optional: optional })
            .then(function(res) {
                if (res && res.success) {
                    closeModal();
                    showSent(res.sent_label);
                    alert(res.message || 'Email sent.');
                } else {
                    err.textContent = (res && res.message) ? res.message : 'Send failed';
                    err.style.display = 'block';
                }
            })
            .catch(function() {
                err.textContent = 'Network error';
                err.style.display = 'block';
            })
            .finally(function() { btnSend.disabled = false; });
    });
})();
</script>
<?php endif; ?>

<?php
include 'includes/nav.php';
include 'includes/footer.php';
?>
