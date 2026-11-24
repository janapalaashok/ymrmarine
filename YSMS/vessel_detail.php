<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
require_once 'includes/agents_mail.php';
require_once 'includes/mailer.php';
require_once 'includes/document_replace_helpers.php';
require_once 'includes/vessel_dates.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();
try { ensureAgentsTable($db); } catch (Throwable $e) {}
$error = '';
$success = '';
$current_user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$is_admin = ($role === 'Admin');
$is_super_admin = ($role === 'Super Admin');
// Super Admin can view every survey like Admin (read-only — no Edit, no Upload,
// no Send-Email-to-Agent), so it must not be scoped down like a Surveyor/Client.
$has_full_access = ($is_admin || $is_super_admin);

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
vesselDatesEnsure($db);

// Who may change this vessel's live status / dates (checked on the server for every POST):
//   'dates'  — Admin, or a surveyor assigned to this vessel (live status and ETA / ETB / ETC / Expected Supply Date)
//   'status' — the others who can open this page (Super Admin, the vessel's own client): live status only, as before
//   ''       — anyone else (another surveyor's vessel, another client's): nothing
$vdWriteAccess = function () use ($db, $id, $is_admin, $has_full_access, $role, $current_user_id): string {
    if ($is_admin) return 'dates';
    if ($role === 'Surveyor' && isSurveyorAssignedToSurvey($db, $id, $current_user_id)) return 'dates';
    if ($has_full_access) return 'status';
    if ($role === 'Client') {
        $q = $db->prepare('SELECT client_id FROM surveys WHERE id = ?');
        $q->execute([$id]);
        $cid = (int)$q->fetchColumn();
        if ($cid > 0 && $cid === getClientIdForUser($db, $current_user_id)) return 'status';
    }
    return '';
};

// ETA / ETB / ETC / Expected Supply Date: the four fields saved together (an empty field = no date).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_vessel_dates'])) {
    $in = is_array($_POST['vessel_date'] ?? null) ? $_POST['vessel_date'] : [];
    $vals = [];
    $bad = [];
    foreach (VESSEL_DATE_FIELDS as $k => [$col, $label]) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v === '') { $vals[$col] = null; continue; }
        $dt = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$dt || $dt->format('Y-m-d') !== $v || (int)$dt->format('Y') < 2000 || (int)$dt->format('Y') > 2100) $bad[] = $label;
        else $vals[$col] = $v;
    }
    if ($vdWriteAccess() !== 'dates') {
        $error = 'You cannot change the dates of this vessel.';
    } elseif ($bad) {
        $error = 'Not a valid date: ' . implode(', ', $bad) . '.';
    } else {
        try {
            // column names come from the fixed list in includes/vessel_dates.php
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($vals)));
            $db->prepare("UPDATE surveys SET $set WHERE id = ?")->execute([...array_values($vals), $id]);
            $success = 'Vessel dates saved.';
        } catch (Throwable $e) {
            error_log('vessel dates update: ' . $e->getMessage());
            $error = 'The dates could not be saved. Please try again.';
        }
    }
}

// 🌟 సర్వేయర్ సబ్మిట్ చేసినప్పుడు custom_live_status అప్‌డేట్ చేసే పక్కా లాజిక్
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_latest_status'])) {
    $latest_status = trim((string)($_POST['latest_status'] ?? ''));
    if ($vdWriteAccess() === '') {
        // not this user's vessel: nothing is changed (the page below then shows Access Denied)
        $error = 'You cannot update the status of this vessel.';
    } else
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

// Submit popup (Pending Vessel): asks the Survey Completed date, and the
// recovery figures when no Calculation Sheet is uploaded.
$vdRecoveryNA = isRecoveryNotApplicable($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''));
$vdHasExcel = false;
try {
    $xs = $db->prepare("SELECT 1 FROM uploads WHERE survey_id = ? AND file_type = 'Formal Report Excel' LIMIT 1");
    $xs->execute([$survey['id']]);
    $vdHasExcel = (bool)$xs->fetchColumn();
} catch (Throwable $e) { error_log('vessel_detail excel check: ' . $e->getMessage()); }

// 🔒 Authorization check (IDOR fix): a non-admin (Surveyor) may only view
// surveys assigned to their own account. Previously checkAuth() only verified
// that *someone* was logged in, so any authenticated user could view any
// other surveyor's survey — including client and financial details — just by
// changing the ?id= in the URL. Admins are unrestricted, matching existing
// admin permissions elsewhere in this file.
if (!$has_full_access && !isSurveyorAssignedToSurvey($db, $survey['id'], $current_user_id)) {
    $isOwnClient = ($role === 'Client') && (int)$survey['client_id'] === getClientIdForUser($db, $current_user_id);
    if (!$isOwnClient) {
    http_response_code(403);
    include 'includes/header.php';
    ?>
    <style>
        .no-access-wrap { min-height: calc(100vh - 160px); padding: 30px 20px; display: flex; align-items: center; justify-content: center; }
        .no-access-card { max-width: 520px; width: 100%; padding: 38px 24px; border-radius: 20px; background: #fff; border: 1px solid var(--border-color); box-shadow: 0 12px 28px rgba(15,23,42,.07); text-align: center; }
        .no-access-icon { width: 68px; height: 68px; margin: 0 auto 18px; border-radius: 18px; display: flex; align-items: center; justify-content: center; background: #fef2f2; color: #b91c1c; font-size: 28px; }
        /* .blue-action-btn is display:flex/width:100% by default (mobile-first),
           so on desktop it hugs the left edge of this centered card instead of
           centering itself — force it to center within this card specifically. */
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
}
$is_client_viewer = ($role === 'Client');

// డేట్ వాలిడేషన్ చెక్
$display_date = '--/--/----';
if (!empty($survey['assign_date']) && $survey['assign_date'] != '0000-00-00 00:00:00' && $survey['assign_date'] != '0000-00-00') {
    $display_date = date('d M Y', strtotime($survey['assign_date']));
}

// 🌟 Shared appointment details (assign-vessel data) for WhatsApp + Email
$combined_survey_type = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
$combined_surveyor_names = getCombinedSurveyorNames($db, $survey['id'] ?? 0, $survey['surveyor_name'] ?? 'N/A');
$client_name = $survey['company_name'] ?? 'N/A';

$appointment_lines = [
    "Vessel Name: " . ($survey['vessel_name'] ?? 'N/A'),
    "Report No: " . (!empty($survey['report_number']) ? $survey['report_number'] : 'N/A'),
    "Client: " . $client_name,
    "Survey Type: " . $combined_survey_type,
    "Assigned Date: " . $display_date,
    "Agent: " . ($survey['agent_name'] ?? 'N/A'),
    "Surveyor: " . $combined_surveyor_names,
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

// Email — always opens Gmail's own compose window (mail.google.com), not
// mailto:, since mailto: opens whatever the OS/browser has set as its
// default mail handler (Outlook, in practice, for this deployment) rather
// than Gmail specifically. "To" is left out of the URL so it stays blank
// for manual entry.
$mail_subject = "New Survey Appointment - " . ($survey['vessel_name'] ?? 'Vessel') . " (" . $client_name . ")";
$mail_body = "Dear Sir,\n\n"
    . "Good day to you.\n\n"
    . "Please find the below appointment from client (" . $client_name . ").\n\n"
    . $appointment_block . "\n\n"
    . "Thank you.\n"
    . "YMR Marine Solutions";
$mail_share_url = 'https://mail.google.com/mail/?view=cm&fs=1&su=' . rawurlencode($mail_subject) . '&body=' . rawurlencode($mail_body);

// Mobile: open the Gmail app itself (not a browser tab) so the user lands in
// an already-signed-in app instead of being asked to log in again, with To/Cc
// still editable by hand inside the app. iOS uses Gmail's own "co" (compose)
// URL scheme. Android Gmail has no such custom scheme — it only registers
// itself as a handler for the standard mailto: intent — so we route a
// mailto: intent and force it to Gmail specifically via package=, falling
// back to the web compose URL only if Gmail isn't installed.
$mail_ios_url = 'googlegmail://co?subject=' . rawurlencode($mail_subject) . '&body=' . rawurlencode($mail_body);
$mail_android_url = 'intent://send?subject=' . rawurlencode($mail_subject) . '&body=' . rawurlencode($mail_body) . '#Intent;scheme=mailto;package=com.google.android.gm;S.browser_fallback_url=' . rawurlencode($mail_share_url) . ';end';

// Pre-fill the "from account" prompt with the logged-in user's own email
// (same column/safety-net pattern as profile.php), so it defaults to
// whoever is actually clicking the button instead of starting blank.
$has_email_col = $db->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
$my_email = '';
if ($has_email_col) {
    $myEmailStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $myEmailStmt->execute([$current_user_id]);
    $my_email = (string)($myEmailStmt->fetchColumn() ?: '');
}

// 🌟 Desktop hero status pill + surveyor avatar helpers — display only,
// same avatar helpers as the list pages' redesign, duplicated page-scoped.
function pvInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach (array_slice($parts ?: [], 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini !== '' ? $ini : '?';
}
function pvAvatarGradient(string $name): string {
    static $palette = [
        ['#8B7CF6', '#4B2FD6'],
        ['#22D3C8', '#0D9488'],
        ['#FBBF24', '#F59E0B'],
        ['#F472B6', '#DB2777'],
        ['#60A5FA', '#2563EB'],
    ];
    $idx = crc32($name) % count($palette);
    [$from, $to] = $palette[$idx];
    return "linear-gradient(135deg, $from, $to)";
}
// 🌟 Fetched once, reused by both the mobile card list and the desktop
// attachment list below (same array, iterated twice — no extra query).
$assign_attachments = getSurveyAttachments($db, $survey['id'], $survey['attachment_path'] ?? '');

// 🌟 Documents the Surveyor has uploaded via the "Upload Required Reports"
// form below (ajax/upload_handler.php writes these into `uploads`) — this
// page never listed them before (only report_detail.php/completed_detail.php
// did), even though a Pending Vessel can already hold some (e.g. one of
// the two required files, or an extra Field Report, before the required
// pair completes and moves it to Pending Report). Same query/row shape as
// those two pages, so Document Replace works identically everywhere.
$surveyor_uploaded_docs = [];
try {
    $udStmt = $db->prepare("SELECT * FROM uploads WHERE survey_id = ? ORDER BY id ASC");
    $udStmt->execute([$survey['id']]);
    $surveyor_uploaded_docs = $udStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('vessel_detail.php uploads fetch: ' . $e->getMessage()); }

// 🌟 Single source of truth for the Replace button — Admin always,
// Surveyor only if actually assigned to this survey (page access above
// already guarantees that for any Surveyor who got this far, but this is
// re-derived independently rather than assumed).
$can_replace_docs = canReplaceDocument($db, $role, $current_user_id, (int)$survey['id']);

require_once 'includes/admin_inline_edit.php';
aieInit($db, $survey);

// Missed Vessels (includes/missed_vessels.php): Mark as Missed on a Pending Vessel, and the
// reason + history + Edit Remarks / Restore on a Missed one — each only for who may do it.
require_once 'includes/missed_vessels.php';
$mvSurvey = ['id' => (int)$survey['id'], 'status' => (string)($survey['status'] ?? '')];
$mvBtn = ['id' => (int)$survey['id'], 'vessel' => (string)$survey['vessel_name'], 'client' => (string)($survey['company_name'] ?? ''),
    'port' => (string)($survey['port_name'] ?? ''), 'surveyor' => (string)$combined_surveyor_names];
$mvCanMark = missedCanMark($db, $mvSurvey);
$mvIsMissed = $mvSurvey['status'] === MISSED_STATUS;
$mvSees = missedCanSee($db, $mvSurvey);
$mvRec = null; $mvHist = [];
try {
    if ($mvSees) { $mvRec = $mvIsMissed ? missedRecord($db, (int)$survey['id']) : null; $mvHist = missedHistory($db, (int)$survey['id']); }
} catch (Throwable $e) { error_log('vessel_detail missed: ' . $e->getMessage()); }
$mvBlock = '';
if ($mvSees && ($mvIsMissed || $mvHist)) {
    ob_start();
    if ($mvIsMissed): ?>
    <div class="mv-banner" data-testid="vd-missed-banner">
        <div class="mv-banner-h"><i class="fa-solid fa-triangle-exclamation"></i><b>Missed Vessel</b><span class="mvp-pill">Missed</span></div>
        <div class="mv-banner-r" data-mv-remarks-of="<?= (int)$survey['id'] ?>" data-testid="vd-missed-remarks"><?= sanitize((string)($mvRec['remarks'] ?? '')) ?: '—' ?></div>
        <div class="mv-banner-m">Marked <?= missedWhen($mvRec['marked_at'] ?? null) ?><?= !empty($mvRec['marked_by_name']) ? ' by ' . sanitize($mvRec['marked_by_name']) : '' ?><?php if (!empty($mvRec['remarks_updated_at'])): ?> · Edited <?= missedWhen($mvRec['remarks_updated_at']) ?><?= !empty($mvRec['edited_by_name']) ? ' by ' . sanitize($mvRec['edited_by_name']) : '' ?><?php endif; ?></div>
        <?php if (missedCanEdit($db, $mvSurvey) || missedCanRestore($mvSurvey)): ?>
        <div class="mv-banner-a">
            <?php if (missedCanEdit($db, $mvSurvey)): ?><button type="button" <?= missedBtnData('edit', $mvBtn + ['remarks' => (string)($mvRec['remarks'] ?? '')]) ?> data-mv-reload data-testid="vd-missed-edit"><i class="fa-solid fa-pen"></i> Edit Remarks</button><?php endif; ?>
            <?php if (missedCanRestore($mvSurvey)): ?><button type="button" class="rs" <?= missedBtnData('restore', $mvBtn) ?> data-mv-reload data-testid="vd-missed-restore"><i class="fa-solid fa-rotate-left"></i> Restore to Pending Vessels</button><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif;
    if ($mvHist): ?>
    <div class="mv-hist" data-testid="vd-status-history">
        <h4><i class="fa-solid fa-clock-rotate-left"></i> Status History</h4>
        <ol>
        <?php foreach ($mvHist as $h): ?>
            <li class="<?= sanitize((string)$h['action_type']) ?>">
                <div class="t"><?= sanitize(missedActionLabel((string)$h['action_type'])) ?></div>
                <div class="m"><?= missedWhen($h['action_at']) ?><?= !empty($h['by_name']) ? ' · ' . sanitize($h['by_name']) : '' ?><?= ($h['previous_status'] && $h['new_status'] && $h['previous_status'] !== $h['new_status']) ? ' · ' . sanitize($h['previous_status']) . ' → ' . sanitize($h['new_status']) : '' ?></div>
                <?php if ((string)$h['remarks'] !== ''): ?><div class="r"><?= sanitize((string)$h['remarks']) ?></div><?php endif; ?>
                <?php if ($h['action_type'] === 'remark_updated' && (string)$h['previous_remarks'] !== ''): ?><div class="r">Previous: <s><?= sanitize((string)$h['previous_remarks']) ?></s></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
    </div>
    <?php endif;
    $mvBlock = ob_get_clean();
}

include 'includes/header.php';
if ($mvCanMark || $mvBlock !== '') echo missedAssets();
?>

<style>
    .status-update-card { background: #ffffff; margin: 15px 20px; border-radius: 16px; padding: 20px; border: 1px solid var(--border-color); }
    .small-status-btn { background: #3b32b3; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .status-input { width: 100%; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 12px; font-size: 13px; background: #f8fafc; outline: none; resize: none; }
    .vessel-edit-btn { display: inline-flex; align-items: center; gap: 6px; background: #fff; color: #00a3df; border: 1px solid #00a3df; border-radius: 8px; padding: 5px 12px; font-size: 11px; font-weight: 700; text-decoration: none; }
    .vessel-edit-btn:hover { background: #00a3df; color: #fff; }
    /* 🌟 Document Replace — View/Download/Replace row actions (unconditional:
       this page's uploads list is Admin/Surveyor-only already, but these
       three classes are also used identically on report_detail.php /
       completed_detail.php where Client/Super Admin see View/Download too). */
    .doc-replace-btn {
        display: inline-flex; align-items: center; gap: 5px; border: 1px solid var(--border-color);
        background: #fff; color: #3b32b3; border-radius: 8px; padding: 0 10px; height: 32px; font-size: 11.5px; font-weight: 650;
    }
    .doc-replace-btn:hover { border-color: #3b32b3; background: #eef2ff; }
    .doc-view-btn {
        display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border-color);
        background: #fff; color: var(--text-muted); border-radius: 8px; width: 32px; height: 32px; font-size: 13px;
    }
    .doc-view-btn:hover { color: #3b32b3; border-color: #3b32b3; }
    .doc-actions-row { display: flex; align-items: center; gap: 6px; flex: 0 0 auto; }
    /* WhatsApp brand green */
    .btn-whatsapp {
        display: inline-flex; align-items: center; gap: 6px;
        background: #25D366; color: #fff !important; border: 1px solid #128C7E;
        border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700;
        text-decoration: none !important; box-shadow: 0 1px 3px rgba(18,140,126,.25);
    }
    .btn-whatsapp:hover { background: #128C7E; color: #fff !important; }
    /* Gmail red */
    .btn-email {
        display: inline-flex; align-items: center; gap: 6px;
        background: #EA4335; color: #fff !important; border: 1px solid #D93025;
        border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700;
        text-decoration: none !important; box-shadow: 0 1px 3px rgba(234,67,53,.25);
    }
    .btn-email:hover { background: #D93025; color: #fff !important; }

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
        .detail-main-row > .status-update-card {
            margin: 0 !important;
            max-width: none !important;
            padding: 14px 18px !important;
            height: auto !important;
            align-self: start;
        }
        .info-row { padding: 10px 16px; }
        .blue-action-btn { max-width: none; width: 100%; }
    }

    /* ═══ Desktop modern redesign — hero banner + icon-chip info card +
       modern upload/status cards, same visual language as the vessels.php
       list-page redesign (gradient hero, .vd-badge-style pills). Mobile
       (".detail-mobile-view", the markup above/below unchanged) keeps the
       existing simple layout; only >=992px swaps to this. Page-scoped
       <style>, so it doesn't leak into other detail pages. */
    .detail-mobile-view { display: block; }

    /* Mobile vessel card (v2) */
    @media (max-width: 991.98px) {
        .info-table-list.vdm-card { background: #fff; border: 1px solid #e8edf4; border-radius: 22px; box-shadow: 0 8px 22px rgba(15,23,42,.07) !important; padding: 14px 12px 12px; margin: 12px 14px; overflow: visible; }
        .vdm-head { display: flex; align-items: center; gap: 12px; padding: 2px 2px 12px; min-width: 0; }
        .vdm-ship { flex: none; width: 54px; height: 54px; border-radius: 16px; background: #d1fae5; color: #047857; font-size: 23px; display: flex; align-items: center; justify-content: center; }
        .vdm-div { flex: none; width: 2px; height: 30px; border-radius: 2px; background: #cfd6e0; }
        .vdm-name { flex: 1 1 auto; min-width: 0; font-size: 20px; font-weight: 800; color: #0b1324; text-transform: uppercase; letter-spacing: .01em; line-height: 1.2; overflow-wrap: anywhere; }
        .vdm-send-wrap { position: relative; flex: none; }
        .vdm-send { width: 46px; height: 46px; border: none; border-radius: 14px; background: linear-gradient(180deg, #2fd12f 0%, #16b316 100%); color: #fff; font-size: 18px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(22,179,22,.3); }
        .vdm-send-menu { position: absolute; right: 0; top: 52px; z-index: 30; background: #fff; border: 1px solid #e5e8ee; border-radius: 14px; box-shadow: 0 12px 28px rgba(15,23,42,.16); padding: 6px; min-width: 160px; }
        .vdm-send-menu[hidden] { display: none; }
        .vdm-send-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; font-size: 14px; font-weight: 700; color: #0f172a; text-decoration: none; }
        .vdm-send-item.wa i { color: #16a34a; font-size: 18px; } .vdm-send-item.mail i { color: #1D63ED; font-size: 16px; }
        .vdm-send-item:active { background: #f1f5f9; }
        .vdm-rows { padding: 4px 4px 8px; }
        .vdm-row { display: grid; grid-template-columns: 26px 108px 10px minmax(0, 1fr) auto; align-items: center; column-gap: 9px; padding: 9px 0; min-height: 44px; }
        .vdm-row > i { text-align: center; color: #1D63ED; font-size: 19px; }
        .vdm-k { font-size: 14.5px; font-weight: 700; color: #475569; line-height: 1.2; white-space: nowrap; }
        .vdm-e:empty { display: none; } .vdm-e .aie-btn { margin: 0; }
        .vdm-name .aie-btn { vertical-align: middle; }
        .vdm-c { font-size: 15px; font-weight: 800; color: #0f172a; text-align: center; }
        .vdm-v { min-width: 0; }
        .vdm-v b { font-size: 15px; font-weight: 800; color: #0b1324; text-transform: uppercase; line-height: 1.25; overflow-wrap: anywhere; }
        .vdm-v b.vdm-date { text-transform: none; font-size: 15.5px; }
        .vdm-type { display: inline-block; max-width: 100%; background: #3f8a1c; color: #fff; border-radius: 999px; padding: 5px 16px; font-size: 13px; font-weight: 800; text-transform: uppercase; line-height: 1.3; overflow-wrap: anywhere; }
        .vdm-port { display: inline-flex; align-items: center; gap: 7px; max-width: 100%; background: #fff; color: #15803d; border: 2px solid #22c55e; border-radius: 999px; padding: 3px 13px; font-size: 13px; font-weight: 800; text-transform: uppercase; line-height: 1.3; overflow-wrap: anywhere; }
        .vdm-status { display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; background: #fff; border: 2px solid; border-radius: 999px; padding: 4px 14px; font-size: 14px; font-weight: 800; }
        .vdm-status.pending { color: #EA580C; border-color: #FB923C; }
        .vdm-status.report { color: #B45309; border-color: #F59E0B; }
        .vdm-status.done { color: #15803D; border-color: #22C55E; }
        .vdm-status.other { color: #334155; border-color: #cbd5e1; }
        .vdm-extra .vdm-k { font-size: 13px; } .vdm-extra > i { font-size: 16px; } .vdm-extra .vdm-v b { font-size: 13.5px; }
        .vdm-remarks { font-size: 13px; font-weight: 600; color: #0f172a; overflow-wrap: anywhere; }
        .vdm-agent-mail { margin-top: 5px; font-size: 11.5px; line-height: 1.45; text-align: left; } .vdm-agent-mail .btn { text-align: left; }
        .vdm-foot { display: flex; align-items: center; gap: 10px; background: #fdf6e9; border-radius: 18px; padding: 12px 12px 12px 14px; margin-top: 4px; }
        .vdm-update { flex: 1 1 auto; min-width: 0; }
        .vdm-update-text { font-size: 14px; font-weight: 600; color: #1e293b; line-height: 1.35; overflow-wrap: anywhere; }
        .vdm-update-text i { color: #1D63ED; margin-right: 3px; } .vdm-update-text i.vdm-info { color: #f5b301; }
        .vdm-update-by { font-size: 11px; color: #64748b; margin-top: 2px; }
        .vdm-edit { flex: none; display: inline-flex; align-items: center; gap: 7px; background: #fff; color: #1D63ED; border: 2px solid #1D63ED; border-radius: 16px; padding: 8px 16px; font-size: 15px; font-weight: 800; text-decoration: none; }
        .vdm-note { flex: none; width: 46px; height: 46px; border: none; border-radius: 15px; background: linear-gradient(180deg, #6d3df0 0%, #4f22d6 100%); color: #fff; font-size: 18px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(79,34,214,.3); }
        @media (max-width: 370px) { .vdm-row { grid-template-columns: 22px 96px 8px minmax(0, 1fr) auto; column-gap: 7px; } .vdm-status { font-size: 12px; padding: 3px 10px; gap: 5px; } .vdm-status i { font-size: 11px; } .vdm-type, .vdm-port { font-size: 12px; padding-left: 10px; padding-right: 10px; } .vdm-k { font-size: 13px; } .vdm-v b { font-size: 13.5px; } .vdm-name { font-size: 17px; } .vdm-edit { padding: 7px 12px; font-size: 14px; } }
    }
    .detail-desktop-view { display: none; }
    @media (min-width: 992px) {
        .detail-mobile-view { display: none !important; }
        .detail-desktop-view { display: block !important; }

        /* Hero already carries the title + back link; the shared top bar
           (hamburger/title/profile) is redundant here, same as the list
           pages — desktop sidebar carries nav/profile. Mobile untouched. */
        .top-app-bar { display: none !important; }

        .vdd-wrap { padding: 8px 20px 40px; max-width: 1400px; margin: 0 auto; }

        .vdd-back {
            display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
            font-size: 13px; font-weight: 600; color: #64748b; text-decoration: none;
        }
        .vdd-back:hover { color: #5B2DEE; }
        .vdd-back-icon {
            width: 32px; height: 32px; border-radius: 10px; background: #fff;
            border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center;
            color: #475569; flex: none;
        }

        .vdd-hero {
            position: relative; overflow: hidden; border-radius: 22px;
            background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%);
            padding: 26px 30px; margin-bottom: 18px;
            display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap;
            box-shadow: 0 14px 34px rgba(30,27,107,.22);
        }
        .vdd-hero-icon-bg {
            position: absolute; right: -26px; top: -40px; font-size: 190px; color: rgba(255,255,255,.07);
            pointer-events: none; line-height: 1;
        }
        .vdd-hero-left { display: flex; align-items: center; gap: 16px; min-width: 0; position: relative; }
        .vdd-hero-ship {
            width: 56px; height: 56px; border-radius: 16px; flex: none;
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center; color: #fff; font-size: 24px;
        }
        .vdd-hero-title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .vdd-hero-title { font-size: 24px; font-weight: 800; color: #fff; letter-spacing: -.01em; }
        .vdd-hero-sub { font-size: 13px; color: rgba(255,255,255,.66); margin-top: 6px; font-weight: 500; }
        .vdd-hero-actions { display: flex; align-items: center; gap: 10px; position: relative; flex: none; flex-wrap: wrap; }
        .vdd-hero-actions a {
            display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px;
            border-radius: 999px; font-weight: 700; font-size: 13px; white-space: nowrap; text-decoration: none !important;
        }
        .vdd-btn-whatsapp { background: #25D366; color: #fff !important; box-shadow: 0 8px 18px rgba(37,211,102,.35); }
        .vdd-btn-email { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.28); color: #fff !important; }
        .vdd-btn-edit { background: #fff; color: #1E1B6B !important; }

        .vdd-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 18px; align-items: start; }
        .vdd-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
            box-shadow: 0 4px 20px rgba(15,23,42,.05); overflow: hidden;
        }
        .vdd-card + .vdd-card { margin-top: 18px; }
        .vdd-card-head {
            padding: 18px 22px; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; gap: 10px;
        }
        .vdd-card-head-icon {
            width: 30px; height: 30px; border-radius: 9px; flex: none;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
        }
        .vdd-card-head-title { font-size: 15px; font-weight: 800; color: #0f172a; }
        .vdd-card-body { padding: 20px 22px 22px; }

        .vdd-info-rows { padding: 8px 10px; }
        .vdd-info-row {
            display: flex; align-items: center; gap: 14px; padding: 13px 12px; border-radius: 12px;
        }
        .vdd-info-row.alt { background: #FAFAFE; }
        .vdd-info-row.align-top { align-items: flex-start; }
        .vdd-info-icon {
            width: 34px; height: 34px; border-radius: 10px; flex: none;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
        }
        .vdd-info-label { flex: 0 0 150px; font-size: 12.5px; color: #64748b; font-weight: 600; }
        .vdd-info-row.align-top .vdd-info-label { margin-top: 6px; }
        .vdd-info-value { font-size: 13.5px; color: #0f172a; font-weight: 700; min-width: 0; }
        .vdd-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 800;
            padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
        }
        .vdd-badge .dot { width: 5px; height: 5px; border-radius: 50%; background: #2563EB; flex: none; }
        .vdd-agent-send { display: inline-flex; align-items: center; gap: 5px; margin-top: 5px; font-size: 11.5px; font-weight: 700; color: #5B2DEE; }
        .vdd-avatar {
            width: 34px; height: 34px; border-radius: 10px; flex: none; color: #fff; font-size: 11px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
        }

        .vdd-remarks {
            margin: 6px 18px 18px; padding: 14px 16px; border-radius: 14px;
            background: #FFF7ED; border: 1px solid #FED7AA; display: flex; gap: 12px; align-items: flex-start;
        }
        .vdd-remarks-icon {
            width: 30px; height: 30px; border-radius: 9px; background: #FDBA74; color: #7C2D12;
            display: flex; align-items: center; justify-content: center; flex: none;
        }
        .vdd-remarks-label { font-size: 11px; font-weight: 800; color: #C2410C; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 3px; }
        .vdd-remarks-text { font-size: 13px; color: #7C2D12; font-weight: 600; line-height: 1.5; }
        .vdd-remarks-text a { color: #9A3412; text-decoration: underline; font-weight: 800; }

        .vdd-upload-row {
            border: 1.5px dashed #C7D2E0; border-radius: 14px; padding: 14px 16px;
            display: flex; align-items: center; gap: 12px; background: #F8FAFC;
        }
        .vdd-upload-row + .vdd-upload-row { margin-top: 10px; }
        .vdd-upload-icon { width: 36px; height: 36px; border-radius: 10px; flex: none; display: flex; align-items: center; justify-content: center; font-size: 15px; }
        .vdd-upload-label { font-size: 12.5px; font-weight: 700; color: #0f172a; }
        .vdd-upload-label .req { color: #DC2626; }
        .vdd-upload-hint { font-size: 11px; color: #94a3b8; margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .vdd-upload-hint.picked { color: #16A34A; font-weight: 600; }
        .vdd-upload-hint.picked i { margin-right: 3px; }
        .vdd-upload-choose {
            position: relative; flex: none; overflow: hidden;
            display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #e2e8f0;
            color: #334155; font-size: 11.5px; font-weight: 700; padding: 7px 14px; border-radius: 999px;
        }
        .vdd-upload-choose input[type=file] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%;
        }
        .vdd-submit-btn {
            margin-top: 14px; width: 100%; height: 46px; border: none; border-radius: 14px;
            background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; font-weight: 800; font-size: 13.5px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 10px 22px rgba(75,47,214,.3); cursor: pointer;
        }

        .vdd-status-textarea {
            width: 100%; box-sizing: border-box; border: 1.5px solid #e2e8f0; border-radius: 14px;
            padding: 13px 14px; font-size: 13px; font-family: inherit; background: #F8FAFC;
            resize: none; color: #334155; min-height: 68px;
        }
        .vdd-status-textarea:focus { outline: none; border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); }
        .vdd-status-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; gap: 10px; }
        .vdd-status-meta { font-size: 11px; color: #94a3b8; font-weight: 500; }
        .vdd-status-btn {
            border: none; background: #5B2DEE; color: #fff; height: 38px; padding: 0 20px;
            border-radius: 999px; font-weight: 700; font-size: 12.5px; box-shadow: 0 8px 18px rgba(91,45,238,.3); flex: none;
        }

        .vdd-attachment {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px 14px; margin-bottom: 10px;
        }
        .vdd-attachment-icon { width: 34px; height: 34px; border-radius: 10px; background: #EEF2FF; color: #4F46E5; display: flex; align-items: center; justify-content: center; flex: none; }

        /* 🌟 Surveyor Uploaded Documents (desktop) — same row style as
           report_detail.php / completed_detail.php's "Dossier Archive",
           not previously needed on this page since it had no uploads-table
           listing until Document Replace added one. */
        .vdd-file-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px 14px;
        }
        .vdd-file-row + .vdd-file-row { margin-top: 10px; }
        .vdd-file-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none; font-size: 15px; }
        .vdd-file-name { font-size: 12.5px; font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .vdd-file-meta { display: flex; align-items: center; gap: 6px; margin-top: 3px; }
        .vdd-badge-soft {
            display: inline-flex; align-items: center; background: #F8FAFC; color: #334155;
            font-size: 11.5px; font-weight: 700; padding: 4px 11px; border-radius: 999px; border: 1px solid #e2e8f0;
        }
    }
</style>

<?php
// ETA / ETB / ETC / Expected Supply Date — shown above Latest Update / Live Status (mobile and desktop).
$vdCanDates = $vdWriteAccess() === 'dates';
$vdDates = vesselDatesOf($survey);
$vdDatesHtml = function (string $ui) use ($vdCanDates, $vdDates, $survey): string {
    if (!$vdCanDates && !$vdDates) return '';
    $id = (int)$survey['id'];
    ob_start(); ?>
    <div class="vdates vdates-<?= $ui ?>" data-testid="vd-dates-<?= $ui ?>">
        <div class="vdates-h"><i class="fa-regular fa-calendar"></i> Vessel dates</div>
        <?php if ($vdCanDates): ?>
        <form method="POST" action="vessel_detail.php?id=<?= $id ?>" class="vdates-form">
            <?= csrf_field() ?>
            <div class="vdates-grid">
                <?php foreach (VESSEL_DATE_FIELDS as $k => [$col, $label]): $ymd = $vdDates[$k]['ymd'] ?? ''; ?>
                <label class="vdates-f"><span><?= sanitize($label) ?></span>
                    <span class="vdates-in"><input type="date" name="vessel_date[<?= $k ?>]" value="<?= sanitize($ymd) ?>" data-testid="vd-date-<?= $k ?>-<?= $ui ?>">
                    <button type="button" class="vdates-x" title="Clear <?= sanitize($label) ?>" aria-label="Clear <?= sanitize($label) ?>" onclick="this.previousElementSibling.value=''" data-testid="vd-date-clear-<?= $k ?>-<?= $ui ?>">&times;</button></span>
                    <small><?= $ymd !== '' ? sanitize($vdDates[$k]['text']) : 'Not set' ?></small>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="update_vessel_dates" value="1" class="vdates-save" data-testid="vd-dates-save-<?= $ui ?>">Save dates</button>
        </form>
        <?php else: ?>
        <div class="vdates-list">
            <?php foreach ($vdDates as $k => $d): ?>
            <div class="vdates-chip" data-testid="vd-date-<?= $k ?>"><span><?= sanitize($d['label']) ?>:</span> <b><?= sanitize($d['text']) ?></b></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php return (string)ob_get_clean();
};
?>
<style>
.vdates { border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px 14px; margin-bottom: 12px; background: #fff; }
.vdates-dapp { margin: 0 0 12px; }
.vdates-h { font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
.vdates-h i { color: #5B2DEE; }
.vdates-form { margin: 0; }
.vdates-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 12px; }
.vdates-f { display: flex; flex-direction: column; gap: 4px; margin: 0; min-width: 0; }
.vdates-f > span:first-child { font-size: 12px; font-weight: 700; color: #475569; }
.vdates-in { display: flex; align-items: center; gap: 4px; }
.vdates-in input { flex: 1; min-width: 0; border: 1.5px solid #d6e0ee; border-radius: 10px; padding: 7px 8px; font-size: 13px; min-height: 40px; background: #fff; }
.vdates-x { border: 0; background: #f1f5f9; color: #b91c1c; width: 28px; height: 28px; border-radius: 50%; font-size: 16px; line-height: 26px; padding: 0; flex: none; cursor: pointer; }
.vdates-f small { font-size: 11px; color: #5B2DEE; font-weight: 700; letter-spacing: .02em; }
.vdates-save { margin-top: 12px; width: 100%; border: 0; border-radius: 10px; background: linear-gradient(135deg, #5B2DEE, #7c3aed); color: #fff; font-weight: 700; min-height: 42px; cursor: pointer; }
.vdates-list { display: flex; flex-wrap: wrap; gap: 6px; }
.vdates-chip { display: inline-flex; align-items: center; gap: 4px; background: #F3EEFE; border: 1px solid #ddd0fb; color: #3b1d9a; border-radius: 999px; padding: 4px 11px; font-size: 12.5px; white-space: nowrap; }
.vdates-chip span { font-weight: 600; } .vdates-chip b { font-weight: 800; letter-spacing: .02em; }
@media (max-width: 480px) { .vdates-grid { grid-template-columns: 1fr; } }
</style>
<div class="scroll-content">
    <?php $page_title = 'Vessel Survey Details'; $back_url = 'vessels.php'; $page_testid = 'vessel-detail'; include 'includes/top_app_bar.php'; ?>
    <?php $overdueAlertKind = 'survey'; include 'includes/overdue_alert.php'; // Survey Pending (7+ days since appointment) ?>

    <?php if($success): ?><div class="alert alert-success mx-3 mt-3 py-2" style="font-size:12px;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger mx-3 mt-3 py-2" style="font-size:12px;"><?= $error ?></div><?php endif; ?>

    <?php
        require_once __DIR__ . '/includes/detail_app.php';
        [$daImg, $daReal] = daPhoto($db, (string)$survey['vessel_name']);
        [$daSt, $daStCls] = daStatus((string)($survey['status'] ?? ''));
        $daAppt = (!empty($survey['appointment_date']) && strpos((string)$survey['appointment_date'], '0000-00-00') !== 0) ? date('d M Y', strtotime($survey['appointment_date'])) : '';
        $daRemarks = !empty($survey['remarks']) ? (string)$survey['remarks'] : '';
    ?>
    <link rel="stylesheet" href="assets/css/detail-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/detail-app.css') ?>">
    <div class="detail-mobile-view dapp dapp-blue" data-testid="vessel-detail-app">
        <div class="dapp-band" aria-hidden="true"></div>
        <div class="dapp-card dapp-hero" data-testid="vessel-detail-mobile-card">
            <div class="dapp-hero-top">
                <div class="dapp-photo<?= $daReal ? ' real' : '' ?>"><img src="<?= sanitize($daImg) ?>" alt="" onerror="this.onerror=null;this.src='<?= VI_DEFAULT ?>';this.parentNode.classList.remove('real')" data-testid="vd-photo"></div>
                <div class="dapp-hn"><small>Vessel</small><span class="dapp-name" data-testid="vdm-vessel-name"><?= sanitize($survey['vessel_name']) ?><?= aie('vessel_name') ?></span>
                    <div class="dapp-chips"><span class="dapp-chip ref"><i class="fa-solid fa-hashtag"></i> <?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?><?= aie('report_number') ?></span><span class="dapp-chip <?= $daStCls ?>" data-testid="vdm-status"><i class="fa-solid fa-clock"></i> <?= sanitize($daSt) ?></span></div></div>
            </div>
            <div class="dapp-acts">
                <a href="<?= sanitize($whatsapp_share_url) ?>" target="_blank" rel="noopener" class="dapp-act wa" data-testid="vessel-detail-whatsapp-link" title="Send via WhatsApp"><i class="fa-brands fa-whatsapp"></i>WhatsApp</a>
                <a href="<?= sanitize($mail_share_url) ?>" target="_blank" rel="noopener" class="dapp-act em" data-mail-from-picker data-base-url="<?= sanitize($mail_share_url) ?>" data-ios-url="<?= sanitize($mail_ios_url) ?>" data-android-url="<?= sanitize($mail_android_url) ?>" data-my-email="<?= sanitize($my_email) ?>" data-testid="vessel-detail-mail-link" title="Send via Email (Gmail)"><i class="fa-solid fa-envelope"></i>Email</a>
                <?php if ($is_admin): ?>
                <a href="assign_vessel.php?edit_id=<?= (int)$survey['id'] ?>" class="dapp-act ed" data-testid="vessel-detail-edit-link"><i class="fa-solid fa-pen"></i>Edit</a>
                <?php endif; ?>
                <button type="button" class="dapp-act sh" data-pv-share data-share-text="<?= sanitize($appointment_block) ?>" data-testid="vessel-detail-share"><i class="fa-solid fa-share-nodes"></i>Share</button>
            </div>
            <?php if ($mvCanMark): ?>
            <div class="mv-card-act" style="padding:10px 0 0"><button type="button" class="mv-mark-btn" <?= missedBtnData('mark', $mvBtn) ?> data-mv-reload data-testid="vessel-detail-mark-missed"><i class="fa-solid fa-triangle-exclamation"></i> Mark as Missed</button></div>
            <?php endif; ?>
        </div>
        <?= $mvBlock ?>

        <div class="dapp-card">
            <div class="dapp-sec-h"><span class="si"><i class="fa-solid fa-ship"></i></span><b>Survey Details</b></div>
            <div class="dapp-grid">
                <?= daField('fa-solid fa-handshake', 'Client', daVal($survey['company_name'] ?? ''), aie('client_id')) ?>
                <?= daField('fa-solid fa-clipboard-list', 'Survey Type', '<span class="dapp-tag">' . sanitize($combined_survey_type) . '</span>', aie('survey_type_ids')) ?>
                <?= daField('fa-solid fa-location-dot', 'Port', daVal($survey['port_name'] ?? ''), aie('port_id')) ?>
                <?= daField('fa-regular fa-calendar', 'Assigned Date', '<b class="dapp-v">' . $display_date . '</b>', aie('assign_date')) ?>
                <?= daField('fa-regular fa-calendar-check', 'Appointment', daVal($daAppt), aie('appointment_date'), 'full') ?>
                <div class="dapp-f full"><i class="fa-solid fa-people-group"></i><div><span class="dapp-l">Agent<?= aie('agent_name') ?></span><?= daVal($survey['agent_name'] ?? '') ?>
                    <?php if ($is_admin && !empty($survey['agent_name'])): ?>
                        <?php $agent_sent_at = $survey['agent_email_sent_at'] ?? null; $agent_sent_label = $agent_sent_at ? date('d M Y, h:i A', strtotime($agent_sent_at)) : ''; ?>
                        <div id="agentEmailActions" class="dapp-agentmail" data-survey-id="<?= (int)$survey['id'] ?>">
                            <span id="agentEmailStatusWrap" style="<?= $agent_sent_at ? '' : 'display:none;' ?>">
                                <span class="text-success" id="agentEmailSentLabel"><i class="fa-solid fa-circle-check"></i> Email sent to agent on <span id="agentEmailSentAt"><?= sanitize($agent_sent_label) ?></span></span>
                                <button type="button" id="btnAgentEmailAgain" class="btn btn-link p-0 ms-1" style="vertical-align:baseline;">Send email again</button>
                            </span>
                            <button type="button" id="btnAgentEmailSend" class="btn btn-link p-0" style="<?= $agent_sent_at ? 'display:none;' : '' ?>">Send email to agent for latest update</button>
                        </div>
                    <?php endif; ?>
                </div></div>
                <?= daField('fa-solid fa-user-tie', 'Surveyors', daPeople((string)$combined_surveyor_names), aie('surveyors'), 'full last') ?>
            </div>
            <?php if ($daRemarks !== '' || aie('remarks') !== ''): ?>
            <div class="dapp-rem" data-testid="vd-remarks"><span><i class="fa-solid fa-comment-dots"></i> Admin Remarks<?= aie('remarks') ?></span>
                <p><?php
                    if ($daRemarks === '') echo 'N/A';
                    elseif (strlen($daRemarks) > 150) echo sanitize(substr($daRemarks, 0, 150)) . '… <a href="#" data-bs-toggle="modal" data-bs-target="#remarksModal" onclick="event.stopPropagation();" data-testid="vessel-detail-read-more-remarks">Read more</a>';
                    else echo sanitize($daRemarks);
                ?></p></div>
            <?php endif; ?>
        </div>

        <?= $vdDatesHtml('dapp') ?>
        <div class="dapp-card status-update-card" data-testid="vd-live-status">
            <div class="dapp-sec-h"><span class="si" style="background:#e7f8ee;color:#16a34a"><i class="fa-solid fa-tower-broadcast"></i></span><b>Live Status</b></div>
            <form action="vessel_detail.php?id=<?= $survey['id'] ?>" method="POST" class="dapp-upd">
                <?= csrf_field() ?>
                <div class="dapp-tl" data-testid="vdm-latest-update"><span class="dot"></span><div>
                    <?php if (!empty($survey['custom_live_status'])): ?>
                        <b><?= sanitize($survey['custom_live_status']) ?></b>
                        <small><?= !empty($survey['status_updated_at']) ? formatLatestUpdateWhen($survey['status_updated_at']) : '' ?> by <i><?= sanitize($survey['modifier_name'] ?? 'User') ?></i></small>
                    <?php else: ?>
                        <b style="color:#64748b;font-weight:600">No updates recorded yet.</b>
                    <?php endif; ?>
                </div></div>
                <div class="dapp-inp">
                    <textarea name="latest_status" class="status-input" rows="2" placeholder="Type the latest update…" required><?= !empty($survey['custom_live_status']) ? sanitize($survey['custom_live_status']) : '' ?></textarea>
                    <button type="submit" name="update_latest_status" class="dapp-btn small-status-btn"><i class="fa-solid fa-paper-plane"></i> Update</button>
                </div>
            </form>
        </div>

    <?php if (!$is_client_viewer && !$is_super_admin): ?>
        <div class="dapp-card form-box" data-testid="vd-upload">
            <div class="dapp-sec-h"><span class="si"><i class="fa-solid fa-cloud-arrow-up"></i></span><b>Upload Reports</b></div>
            <div class="dapp-up">
    <?php
    // 🌟 One or more files uploaded at assignment time (Assign Vessel form
    // now allows selecting multiple at once). getSurveyAttachments() reads
    // the new survey_attachments table, falling back to the legacy single
    // surveys.attachment_path column for vessels assigned before multi-file
    // upload existed, so older assignments still show their one attachment.
    // (Fetched once above; reused here and in the desktop view below.)
    foreach ($assign_attachments as $assign_att_row):
        $assign_att = $assign_att_row['file_path'];
        $att_fs = (strpos($assign_att, '/') === 0 || preg_match('#^[A-Za-z]:#', $assign_att)) ? $assign_att : (__DIR__ . '/' . ltrim($assign_att, '/'));
        $att_url = $assign_att;
        $att_label = $assign_att_row['file_name'] ?: basename($assign_att);
        // Strip numeric time (+ index) prefix for display if present
        $att_label_clean = preg_replace('/^[0-9]+(_[0-9]+)?_/', '', $att_label);
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
    <?php endif; endforeach; ?>

    <?php if (!empty($surveyor_uploaded_docs)): ?>
    <div class="px-3 mb-3">
        <div class="fw-bold text-dark mb-2" style="font-size: 12.5px;"><i class="fa-solid fa-folder-open text-warning me-1"></i> Surveyor Uploaded Documents</div>
        <?php foreach ($surveyor_uploaded_docs as $doc):
            $doc_clean = preg_replace('/^[0-9]+_/', '', $doc['file_name'] ?? '');
            if ($doc_clean === '' || $doc_clean === null) $doc_clean = $doc['file_name'];
            $doc_icon = 'fa-file-lines text-secondary';
            if (str_contains((string)$doc['file_type'], 'PDF')) $doc_icon = 'fa-file-pdf text-danger';
            elseif (str_contains((string)$doc['file_type'], 'Excel')) $doc_icon = 'fa-file-excel text-success';
            elseif (str_contains((string)$doc['file_type'], 'Word')) $doc_icon = 'fa-file-word text-primary';
            $doc_uploader = $doc['uploaded_by'] ? getUserFullNameById($db, (int)$doc['uploaded_by']) : '—';
        ?>
        <div class="bg-white p-2 rounded-3 border d-flex align-items-center justify-content-between mb-2 shadow-sm" style="font-size:12px;" data-doc-row data-doc-id="<?= (int)$doc['id'] ?>">
            <div class="d-flex align-items-center gap-2" style="max-width:62%;">
                <i class="fa-regular <?= $doc_icon ?>"></i>
                <div style="overflow:hidden;">
                    <div class="text-dark fw-semibold text-truncate" data-doc-name-display title="<?= sanitize($doc_clean) ?>"><?= sanitize($doc_clean) ?></div>
                    <div class="text-muted" style="font-size:10.5px;"><?= sanitize($doc['file_type']) ?> · <span data-doc-size-display><?= sanitize($doc['file_size']) ?></span></div>
                </div>
            </div>
            <div class="doc-actions-row">
                <a href="download.php?upload_id=<?= (int)$doc['id'] ?>&inline=1" target="_blank" rel="noopener" class="doc-view-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                <a href="download.php?upload_id=<?= (int)$doc['id'] ?>" data-doc-download-link class="doc-view-btn" title="Download"><i class="fa-solid fa-download"></i></a>
                <?php if ($can_replace_docs): ?>
                <button type="button" class="doc-replace-btn" title="Replace / Re-upload"
                        data-doc-id="<?= (int)$doc['id'] ?>"
                        data-doc-name="<?= sanitize($doc_clean) ?>"
                        data-doc-type="<?= sanitize($doc['file_type']) ?>"
                        data-doc-size="<?= sanitize($doc['file_size']) ?>"
                        data-doc-uploaded-at="<?= sanitize(fmtDocumentDateTime($doc['uploaded_at'] ?? '')) ?>"
                        data-doc-uploaded-by="<?= sanitize($doc_uploader) ?>">
                    <i class="fa-solid fa-rotate"></i> Replace
                </button>
                <?php endif; ?><?= aieFileButtons($doc) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>


            <form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data" data-report-upload-form>
                <?= csrf_field() ?>
                <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
                <input type="hidden" name="current_status" value="<?= $survey['status'] ?>">
                <label class="dapp-ur"><span class="fi fi-pdf"><i class="fa-solid fa-file-pdf"></i></span><span class="t"><b>Formal Report <span class="dapp-req">*</span></b><small>PDF · required</small></span>
                    <input type="file" name="pdf_report" accept=".pdf" required></label>
                <label class="dapp-ur"><span class="fi fi-xls"><i class="fa-solid fa-file-excel"></i></span><span class="t"><b>Calculation Sheet</b><small>Excel · optional</small></span>
                    <input type="file" name="excel_report" accept=".xlsx, .xls"></label>
                <label class="dapp-ur"><span class="fi fi-add"><i class="fa-solid fa-folder-plus"></i></span><span class="t"><b>Additional Files</b><small>Excel, Word or PDF · several at once</small></span>
                    <input type="file" name="additional_files[]" accept=".xlsx,.xls,.docx,.doc,.pdf" multiple></label>
                <button type="submit" class="dapp-btn wide" data-report-upload-submit><i class="fa-solid fa-paper-plane"></i> Submit &amp; Send</button>
            </form>
            </div>
        </div>
    <?php endif; ?>
    </div><!-- /.detail-mobile-view -->
<script>window.VC_CFG = window.VC_CFG || <?= json_encode(['csrf' => csrf_token(), 'def' => VI_DEFAULT]) ?>;</script>
<script src="assets/js/vessel-cards.js?v=<?= @filemtime(__DIR__ . '/assets/js/vessel-cards.js') ?>"></script>


    <div class="detail-desktop-view">
        <div class="vdd-wrap">
            <a href="<?= sanitize($back_url ?: 'vessels.php') ?>" class="vdd-back">
                <span class="vdd-back-icon"><i class="fa-solid fa-chevron-left"></i></span>
                Back
            </a>

            <div class="vdd-hero">
                <i class="fa-solid fa-ship vdd-hero-icon-bg" aria-hidden="true"></i>
                <div class="vdd-hero-left">
                    <div class="vdd-hero-ship"><i class="fa-solid fa-ship"></i></div>
                    <div>
                        <div class="vdd-hero-title-row">
                            <div class="vdd-hero-title"><?= sanitize($survey['vessel_name']) ?></div><?= aie('vessel_name') ?>
                        </div>
                        <div class="vdd-hero-sub">
                            <?php if (!empty($survey['report_number'])): ?>Report <?= sanitize($survey['report_number']) ?> &nbsp;·&nbsp; <?php endif; ?>
                            <?= sanitize($combined_survey_type) ?> &nbsp;·&nbsp; Assigned <?= sanitize($display_date) ?>
                        </div>
                    </div>
                </div>
                <div class="vdd-hero-actions">
                    <a href="<?= sanitize($whatsapp_share_url) ?>" target="_blank" rel="noopener" class="vdd-btn-whatsapp" data-testid="vessel-detail-whatsapp-link-desktop" title="Send via WhatsApp"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                    <a href="<?= sanitize($mail_share_url) ?>" target="_blank" rel="noopener" class="vdd-btn-email" data-mail-from-picker data-base-url="<?= sanitize($mail_share_url) ?>" data-ios-url="<?= sanitize($mail_ios_url) ?>" data-android-url="<?= sanitize($mail_android_url) ?>" data-my-email="<?= sanitize($my_email) ?>" data-testid="vessel-detail-mail-link-desktop" title="Send via Email (Gmail)"><i class="fa-solid fa-envelope"></i> Email</a>
                    <?php if ($is_admin): ?>
                        <a href="assign_vessel.php?edit_id=<?= (int)$survey['id'] ?>" class="vdd-btn-edit" data-testid="vessel-detail-edit-link-desktop"><i class="fa-solid fa-pen"></i> Edit</a>
                    <?php endif; ?>
                    <?php if ($mvCanMark): ?>
                        <button type="button" class="mv-mark-btn" style="padding:9px 14px;font-size:13px;border-radius:10px" <?= missedBtnData('mark', $mvBtn) ?> data-mv-reload data-testid="vessel-detail-mark-missed-desktop"><i class="fa-solid fa-triangle-exclamation"></i> Mark as Missed</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($mvBlock !== ''): ?><div class="vdd-mv"><?= $mvBlock ?></div><?php endif; ?>

            <div class="vdd-grid">
                <div>
                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="fa-solid fa-file-lines"></i></div>
                            <div class="vdd-card-head-title">Survey Information</div>
                        </div>
                        <div class="vdd-info-rows">
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#F1F5FF;color:#4F46E5;"><i class="fa-solid fa-building"></i></div>
                                <div class="vdd-info-label">Client Name<?= aie('client_id') ?></div>
                                <div class="vdd-info-value"><?= sanitize($survey['company_name']) ?></div>
                            </div>
                            <div class="vdd-info-row alt">
                                <div class="vdd-info-icon" style="background:#F1F5FF;color:#4F46E5;"><i class="fa-solid fa-hashtag"></i></div>
                                <div class="vdd-info-label">Report No<?= aie('report_number') ?></div>
                                <div class="vdd-info-value"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></div>
                            </div>
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#F3EEFE;color:#5B2DEE;"><i class="fa-solid fa-file-circle-check"></i></div>
                                <div class="vdd-info-label">Survey Type<?= aie('survey_type_ids') ?></div>
                                <div><span class="vdd-badge"><span class="dot"></span><?= sanitize($combined_survey_type) ?></span></div>
                            </div>
                            <div class="vdd-info-row alt">
                                <div class="vdd-info-icon" style="background:#ECFEFF;color:#0891B2;"><i class="fa-solid fa-calendar-days"></i></div>
                                <div class="vdd-info-label">Assigned Date<?= aie('assign_date') ?></div>
                                <div class="vdd-info-value"><?= $display_date ?></div>
                            </div>
                            <div class="vdd-info-row align-top">
                                <div class="vdd-info-icon" style="background:#FFF7ED;color:#EA580C;"><i class="fa-solid fa-people-group"></i></div>
                                <div class="vdd-info-label">Agent Name<?= aie('agent_name') ?></div>
                                <div>
                                    <div class="vdd-info-value"><?= sanitize($survey['agent_name']) ?></div>
                                    <?php if ($is_admin && !empty($survey['agent_name'])):
                                        $vdd_agent_sent_at = $survey['agent_email_sent_at'] ?? null;
                                        $vdd_agent_sent_label = $vdd_agent_sent_at ? date('d M Y, h:i A', strtotime($vdd_agent_sent_at)) : '';
                                    ?>
                                        <div id="agentEmailActionsDesktop" style="margin-top:6px;font-size:11.5px;line-height:1.45;" data-survey-id="<?= (int)$survey['id'] ?>">
                                            <span id="agentEmailStatusWrapDesktop" style="<?= $vdd_agent_sent_at ? '' : 'display:none;' ?>">
                                                <span class="text-success" id="agentEmailSentLabelDesktop"><i class="fa-solid fa-circle-check"></i> Email sent to agent on <span id="agentEmailSentAtDesktop"><?= sanitize($vdd_agent_sent_label) ?></span></span>
                                                <button type="button" id="btnAgentEmailAgainDesktop" class="btn btn-link p-0 ms-1 vdd-agent-send" style="<?= $vdd_agent_sent_at ? '' : 'display:none;' ?>">Send email again</button>
                                            </span>
                                            <button type="button" id="btnAgentEmailSendDesktop" class="btn btn-link p-0 vdd-agent-send" style="<?= $vdd_agent_sent_at ? 'display:none;' : '' ?>"><i class="fa-solid fa-paper-plane"></i> Send email to agent for latest update</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="vdd-info-row alt">
                                <div class="vdd-avatar" style="background: <?= pvAvatarGradient($combined_surveyor_names) ?>;"><?= sanitize(pvInitials($combined_surveyor_names)) ?></div>
                                <div class="vdd-info-label">Surveyor Name<?= aie('surveyors') ?></div>
                                <div class="vdd-info-value" style="color:#5B2DEE;"><?= sanitize($combined_surveyor_names) ?></div>
                            </div>
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#ECFEFF;color:#0891B2;"><i class="fa-solid fa-location-dot"></i></div>
                                <div class="vdd-info-label">Port Name<?= aie('port_id') ?></div>
                                <div class="vdd-info-value"><?= sanitize($survey['port_name']) ?></div>
                            </div>
                        </div>

                        <div class="vdd-remarks">
                            <div class="vdd-remarks-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                            <div>
                                <div class="vdd-remarks-label">Admin Remarks<?= aie('remarks') ?></div>
                                <div class="vdd-remarks-text">
                                    <?php
                                        $remarks_full = !empty($survey['remarks']) ? (string)$survey['remarks'] : '';
                                        if ($remarks_full === '') {
                                            echo 'N/A';
                                        } elseif (strlen($remarks_full) > 150) {
                                            echo sanitize(substr($remarks_full, 0, 150)) . '... <a href="#" data-bs-toggle="modal" data-bs-target="#remarksModal" onclick="event.stopPropagation();" data-testid="vessel-detail-read-more-remarks-desktop">Read More</a>';
                                        } else {
                                            echo sanitize($remarks_full);
                                        }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <?php if (!$is_client_viewer && !$is_super_admin): ?>
                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:linear-gradient(135deg,#8B7CF6,#4B2FD6);color:#fff;"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                            <div class="vdd-card-head-title">Upload Required Reports</div>
                        </div>
                        <div class="vdd-card-body">
                            <?php foreach ($assign_attachments as $vdd_att_row):
                                $vdd_att = $vdd_att_row['file_path'];
                                $vdd_att_fs = (strpos($vdd_att, '/') === 0 || preg_match('#^[A-Za-z]:#', $vdd_att)) ? $vdd_att : (__DIR__ . '/' . ltrim($vdd_att, '/'));
                                $vdd_att_url = $vdd_att;
                                $vdd_att_label = $vdd_att_row['file_name'] ?: basename($vdd_att);
                                $vdd_att_label_clean = preg_replace('/^[0-9]+(_[0-9]+)?_/', '', $vdd_att_label);
                                if (is_file($vdd_att_fs)):
                            ?>
                            <div class="vdd-attachment">
                                <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                                    <div class="vdd-attachment-icon"><i class="fa-solid fa-paperclip"></i></div>
                                    <div style="min-width:0;">
                                        <div style="font-size:12.5px;font-weight:700;color:#0f172a;">Assignment Attachment</div>
                                        <div style="font-size:11px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($vdd_att_label_clean) ?></div>
                                    </div>
                                </div>
                                <a href="<?= sanitize($vdd_att_url) ?>" download="<?= sanitize($vdd_att_label_clean) ?>" style="flex:none;width:32px;height:32px;border-radius:9px;background:#F8FAFC;border:1px solid #e2e8f0;color:#5B2DEE;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-download"></i></a>
                            </div>
                            <?php endif; endforeach; ?>

                            <?php if (!empty($surveyor_uploaded_docs)): ?>
                            <div style="font-size:12px;font-weight:700;color:#0f172a;margin:14px 0 8px;"><i class="fa-solid fa-folder-open text-warning me-1"></i> Surveyor Uploaded Documents</div>
                            <?php foreach ($surveyor_uploaded_docs as $doc):
                                $doc_clean = preg_replace('/^[0-9]+_/', '', $doc['file_name'] ?? '');
                                if ($doc_clean === '' || $doc_clean === null) $doc_clean = $doc['file_name'];
                                $doc_icon = 'fa-file-lines'; $doc_icon_bg = '#F1F5F9'; $doc_icon_fg = '#475569';
                                if (str_contains((string)$doc['file_type'], 'PDF')) { $doc_icon = 'fa-file-pdf'; $doc_icon_bg = '#FEE2E2'; $doc_icon_fg = '#B91C1C'; }
                                elseif (str_contains((string)$doc['file_type'], 'Excel')) { $doc_icon = 'fa-file-excel'; $doc_icon_bg = '#D1FAE5'; $doc_icon_fg = '#047857'; }
                                elseif (str_contains((string)$doc['file_type'], 'Word')) { $doc_icon = 'fa-file-word'; $doc_icon_bg = '#EEF2FF'; $doc_icon_fg = '#4F46E5'; }
                                $doc_uploader = $doc['uploaded_by'] ? getUserFullNameById($db, (int)$doc['uploaded_by']) : '—';
                            ?>
                            <div class="vdd-file-row" data-doc-row data-doc-id="<?= (int)$doc['id'] ?>">
                                <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                                    <div class="vdd-file-icon" style="background:<?= $doc_icon_bg ?>;color:<?= $doc_icon_fg ?>;"><i class="fa-solid <?= $doc_icon ?>"></i></div>
                                    <div style="min-width:0;">
                                        <div class="vdd-file-name" data-doc-name-display title="<?= sanitize($doc_clean) ?>"><?= sanitize($doc_clean) ?></div>
                                        <div class="vdd-file-meta">
                                            <span class="vdd-badge-soft" style="font-size:10px;padding:2px 8px;"><?= sanitize($doc['file_type']) ?></span>
                                            <span style="font-size:11px;color:#94a3b8;" data-doc-size-display><?= sanitize($doc['file_size']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="doc-actions-row">
                                    <a href="download.php?upload_id=<?= (int)$doc['id'] ?>&inline=1" target="_blank" rel="noopener" class="doc-view-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                                    <a href="download.php?upload_id=<?= (int)$doc['id'] ?>" data-doc-download-link class="doc-view-btn" title="Download"><i class="fa-solid fa-download"></i></a>
                                    <?php if ($can_replace_docs): ?>
                                    <button type="button" class="doc-replace-btn" title="Replace / Re-upload"
                                            data-doc-id="<?= (int)$doc['id'] ?>"
                                            data-doc-name="<?= sanitize($doc_clean) ?>"
                                            data-doc-type="<?= sanitize($doc['file_type']) ?>"
                                            data-doc-size="<?= sanitize($doc['file_size']) ?>"
                                            data-doc-uploaded-at="<?= sanitize(fmtDocumentDateTime($doc['uploaded_at'] ?? '')) ?>"
                                            data-doc-uploaded-by="<?= sanitize($doc_uploader) ?>">
                                        <i class="fa-solid fa-rotate"></i> Replace
                                    </button>
                                    <?php endif; ?><?= aieFileButtons($doc) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>

                            <form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data" data-report-upload-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
                                <input type="hidden" name="current_status" value="<?= $survey['status'] ?>">
                                <div class="vdd-upload-row">
                                    <div class="vdd-upload-icon" style="background:#FEE2E2;color:#B91C1C;"><i class="fa-solid fa-file-pdf"></i></div>
                                    <div style="flex:1 1 auto;min-width:0;">
                                        <div class="vdd-upload-label">1. Formal Report (PDF) <span class="req">*</span></div>
                                        <div class="vdd-upload-hint" data-upload-hint data-default="PDF only">PDF only</div>
                                    </div>
                                    <label class="vdd-upload-choose"><i class="fa-solid fa-arrow-up-from-bracket"></i> Choose file<input type="file" class="vdd-file-input" name="pdf_report" accept=".pdf" required></label>
                                </div>
                                <div class="vdd-upload-row">
                                    <div class="vdd-upload-icon" style="background:#D1FAE5;color:#047857;"><i class="fa-solid fa-file-excel"></i></div>
                                    <div style="flex:1 1 auto;min-width:0;">
                                        <div class="vdd-upload-label">2. Calculation Sheet (Excel) <span style="color:#94a3b8;font-weight:500;">(optional)</span></div>
                                        <div class="vdd-upload-hint" data-upload-hint data-default=".xlsx or .xls">.xlsx or .xls</div>
                                    </div>
                                    <label class="vdd-upload-choose"><i class="fa-solid fa-arrow-up-from-bracket"></i> Choose file<input type="file" class="vdd-file-input" name="excel_report" accept=".xlsx, .xls"></label>
                                </div>
                                <div class="vdd-upload-row">
                                    <div class="vdd-upload-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="fa-solid fa-paperclip"></i></div>
                                    <div style="flex:1 1 auto;min-width:0;">
                                        <div class="vdd-upload-label">Additional Files <span style="color:#94a3b8;font-weight:500;">(optional)</span></div>
                                        <div class="vdd-upload-hint" data-upload-hint data-default="Excel, Word or PDF — multiple allowed">Excel, Word or PDF — multiple allowed</div>
                                    </div>
                                    <label class="vdd-upload-choose"><i class="fa-solid fa-arrow-up-from-bracket"></i> Choose files<input type="file" class="vdd-file-input" name="additional_files[]" accept=".xlsx,.xls,.docx,.doc,.pdf" multiple></label>
                                </div>
                                <button type="submit" class="vdd-submit-btn" data-report-upload-submit><i class="fa-solid fa-paper-plane"></i> Submit &amp; Send</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?= $vdDatesHtml('vdd') ?>
                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#F3EEFE;color:#5B2DEE;"><i class="fa-solid fa-pen-fancy"></i></div>
                            <div class="vdd-card-head-title">Latest Update / Live Status</div>
                        </div>
                        <div class="vdd-card-body">
                            <form action="vessel_detail.php?id=<?= $survey['id'] ?>" method="POST">
                                <?= csrf_field() ?>
                                <textarea name="latest_status" class="vdd-status-textarea" rows="2" placeholder="Update the current operational event…" required><?= !empty($survey['custom_live_status']) ? sanitize($survey['custom_live_status']) : '' ?></textarea>
                                <div class="vdd-status-foot">
                                    <div class="vdd-status-meta">
                                        <?php if (!empty($survey['status_updated_at'])): ?>
                                            <span style="color:#334155;font-weight:700;"><?= formatLatestUpdateWhen($survey['status_updated_at']) ?></span> By <span style="color:#5B2DEE;font-weight:700;"><?= sanitize($survey['modifier_name'] ?? 'User') ?></span>
                                        <?php else: ?>
                                            <span style="color:#334155;font-weight:700;">No updates yet</span>
                                        <?php endif; ?>
                                    </div>
                                    <button type="submit" name="update_latest_status" class="vdd-status-btn">Update</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /.detail-desktop-view -->

    <?php /* Survey Report: started / resumed from Survey Reports in the menu (survey_reports.php?survey_id=…), not from this page */ ?>

    <?php
    // Same vessel surveyed before (any client) — for the surveyor's reference. Admin / Super Admin / Surveyor only.
    if (in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)) {
        require_once 'includes/vessel_history.php';
        try { $vesselHistoryRows = vesselHistory($db, $survey); } catch (Throwable $e) { error_log('vessel history: ' . $e->getMessage()); $vesselHistoryRows = []; }
        include 'includes/vessel_history_card.php';
    }
    ?>

    <script>
    // 🌟 File-input "chosen" confirmation for the desktop Upload Required
    // Reports card — purely a display confirmation (small text under the
    // label turns green and shows the picked file name(s)); the form
    // fields/names/behavior are unchanged, nothing here touches submission.
    document.querySelectorAll('.vdd-file-input').forEach(function (input) {
        input.addEventListener('change', function () {
            var row = input.closest('.vdd-upload-row');
            var hint = row ? row.querySelector('[data-upload-hint]') : null;
            if (!hint) return;
            if (input.files && input.files.length) {
                var names = Array.prototype.map.call(input.files, function (f) { return f.name; });
                var label = names.length > 1 ? (names.length + ' files selected') : names[0];
                hint.innerHTML = '<i class="fa-solid fa-circle-check"></i>' + label.replace(/</g, '&lt;');
                hint.classList.add('picked');
                hint.title = names.join(', ');
            } else {
                hint.textContent = hint.getAttribute('data-default') || '';
                hint.classList.remove('picked');
                hint.removeAttribute('title');
            }
        });
    });
    </script>

    <style>
        #remarksModal .modal-content { border: none; border-radius: 20px; box-shadow: 0 20px 44px rgba(15,23,42,.22); overflow: hidden; }
        #remarksModal .modal-header {
            background: linear-gradient(120deg, #FFF7ED, #FFEDD5); border-bottom: 1px solid #FED7AA;
            padding: 18px 22px; display: flex; align-items: center; gap: 12px;
        }
        #remarksModal .modal-header::before {
            content: '\f071'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 15px;
            width: 34px; height: 34px; border-radius: 10px; flex: none;
            background: #FDBA74; color: #7C2D12; display: flex; align-items: center; justify-content: center;
        }
        #remarksModal .modal-title { font-size: 15px; font-weight: 800; color: #7C2D12; }
        #remarksModal .btn-close { margin-left: auto; opacity: .55; }
        #remarksModal .btn-close:hover { opacity: 1; }
        #remarksModal .modal-body { padding: 22px; font-size: 14px; line-height: 1.65; color: #334155; background: #fff; }
        #remarksModal .modal-footer { border-top: 1px solid #f1f5f9; padding: 14px 22px; }
        #remarksModal .modal-footer .btn-modern-close {
            border: none; background: #5B2DEE; color: #fff; height: 38px; padding: 0 20px;
            border-radius: 999px; font-weight: 700; font-size: 12.5px; box-shadow: 0 8px 18px rgba(91,45,238,.3);
        }
    </style>
        <?php if (!empty($remarks_full) && strlen($remarks_full) > 150): ?>
        <div class="modal fade" id="remarksModal" tabindex="-1" aria-hidden="true" data-testid="vessel-detail-remarks-modal">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Admin Remarks</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="white-space: pre-wrap;"><?= sanitize($remarks_full) ?></div>
                    <div class="modal-footer">
                        <button type="button" class="btn-modern-close" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>



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

    // 🌟 The desktop redesign duplicates this agent-email mini-widget
    // (id="…Desktop") next to the mobile one so it works in both the
    // mobile card view and the desktop hero/card view — keep both pairs
    // of elements in sync everywhere below.
    function showSent(label) {
        ['agentEmailStatusWrap', 'agentEmailStatusWrapDesktop'].forEach(function(id) {
            var wrap = document.getElementById(id);
            if (wrap) wrap.style.display = '';
        });
        ['agentEmailSentAt', 'agentEmailSentAtDesktop'].forEach(function(id) {
            var at = document.getElementById(id);
            if (at) at.textContent = label || '';
        });
        ['btnAgentEmailSend', 'btnAgentEmailSendDesktop'].forEach(function(id) {
            var sendBtn = document.getElementById(id);
            if (sendBtn) sendBtn.style.display = 'none';
        });
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

    function allAgentEmailButtons() {
        return ['btnAgentEmailSend', 'btnAgentEmailAgain', 'btnAgentEmailSendDesktop', 'btnAgentEmailAgainDesktop']
            .map(function(id) { return document.getElementById(id); })
            .filter(Boolean);
    }

    function startSendFlow() {
        var buttons = allAgentEmailButtons();
        buttons.forEach(function(b) { b.disabled = true; });

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
                buttons.forEach(function(b) { b.disabled = false; });
            });
    }

    allAgentEmailButtons().forEach(function(b) {
        b.addEventListener('click', function(e) { e.preventDefault(); startSendFlow(); });
    });

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

<script>
// Email button: an optional prompt for "Your Gmail address" picks which
// signed-in Google account the compose window opens under (Gmail's own
// &authuser= param). Cancel/leave it blank and the link opens exactly as
// before — this is purely what the user types, nothing automatic.
// On phones this opens the Gmail app directly (already signed in) instead
// of a browser tab that would ask the user to log in again; desktop keeps
// opening the Gmail web compose tab as before.
document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-mail-from-picker]');
    if (!btn) return;
    ev.preventDefault();
    var email = (window.prompt('Your Gmail address (optional — picks which account composes; leave blank to use the default account):', btn.getAttribute('data-my-email') || '') || '').trim();
    var webUrl = btn.getAttribute('data-base-url') + (email ? '&authuser=' + encodeURIComponent(email) : '');
    var ua = navigator.userAgent || '';

    if (/Android/i.test(ua)) {
        window.location.href = btn.getAttribute('data-android-url');
        return;
    }
    if (/iPhone|iPad|iPod/i.test(ua)) {
        var appOpened = false;
        var onBlur = function () { appOpened = true; window.removeEventListener('blur', onBlur); };
        window.addEventListener('blur', onBlur);
        window.location.href = btn.getAttribute('data-ios-url');
        setTimeout(function () {
            window.removeEventListener('blur', onBlur);
            if (!appOpened) window.location.href = webUrl;
        }, 1500);
        return;
    }
    window.open(webUrl, '_blank', 'noopener');
});
</script>

<div class="vdc-modal" id="vdCompleteModal" hidden data-recovery-na="<?= $vdRecoveryNA ? '1' : '0' ?>" data-has-excel="<?= $vdHasExcel ? '1' : '0' ?>" data-testid="complete-modal" role="dialog" aria-modal="true" aria-labelledby="vdCompleteTitle">
    <div class="vdc-box">
        <div class="vdc-head">
            <div class="vdc-ico"><i class="fa-solid fa-flag-checkered"></i></div>
            <div>
                <div class="vdc-title" id="vdCompleteTitle">Survey details</div>
                <div class="vdc-sub"><?= sanitize($survey['vessel_name']) ?></div>
            </div>
        </div>
        <label class="vdc-label" for="vdcDate">Survey Completed Date <span class="req">*</span></label>
        <input type="date" id="vdcDate" class="vdc-input" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" data-testid="complete-date">
        <div class="vdc-note" data-vdc-excel-note hidden><i class="fa-solid fa-file-excel"></i> Recovery will be read from the Calculation Sheet.</div>
        <div data-vdc-recovery hidden>
            <div class="vdc-label">Recovery (MT) <span class="req">*</span></div>
            <div class="vdc-grid">
                <div><span>VLSFO</span><input type="number" step="0.001" inputmode="decimal" class="vdc-input" data-vdc-part name="vdc_vlsfo" data-testid="complete-vlsfo"></div>
                <div><span>HSFO</span><input type="number" step="0.001" inputmode="decimal" class="vdc-input" data-vdc-part name="vdc_hsfo" data-testid="complete-hsfo"></div>
                <div><span>LSMGO</span><input type="number" step="0.001" inputmode="decimal" class="vdc-input" data-vdc-part name="vdc_lsmgo" data-testid="complete-lsmgo"></div>
                <div><span>Total</span><input type="number" step="0.001" inputmode="decimal" class="vdc-input vdc-total" id="vdcTotal" data-testid="complete-total"></div>
            </div>
            <div class="vdc-hint">Total fills in from VLSFO + HSFO + LSMGO; enter 0 if there was no recovery.</div>
        </div>
        <div class="vdc-error" data-vdc-error hidden></div>
        <div class="vdc-actions">
            <button type="button" class="vdc-btn ghost" data-vdc-cancel>Cancel</button>
            <button type="button" class="vdc-btn primary" data-vdc-ok data-testid="complete-submit"><i class="fa-solid fa-paper-plane"></i> Submit &amp; Send</button>
        </div>
    </div>
</div>
<style>
.vdc-modal { position: fixed; inset: 0; z-index: 9998; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.vdc-modal[hidden] { display: none; }
.vdc-box { background: #fff; border-radius: 18px; width: 100%; max-width: 420px; padding: 20px; box-shadow: 0 24px 60px rgba(0,0,0,.25); max-height: calc(100vh - 32px); overflow: auto; }
.vdc-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.vdc-ico { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg,#1E1B6B,#4B2FD6); color: #fff; display: flex; align-items: center; justify-content: center; flex: none; }
.vdc-title { font-size: 16px; font-weight: 800; color: #0f172a; }
.vdc-sub { font-size: 12px; color: #64748b; }
.vdc-label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin: 10px 0 6px; }
.vdc-label .req { color: #DC2626; }
.vdc-input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 9px 11px; font-size: 14px; background: #F8FAFC; min-width: 0; }
.vdc-input:focus { outline: none; border-color: #A78BFA; background: #fff; }
.vdc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.vdc-grid span { display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 4px; }
.vdc-total { font-weight: 800; color: #1E1B6B; }
.vdc-hint { font-size: 11px; color: #94a3b8; margin-top: 6px; }
.vdc-note { margin-top: 12px; font-size: 12px; color: #047857; background: #ECFDF5; border-radius: 10px; padding: 8px 10px; }
.vdc-error { margin-top: 12px; font-size: 12px; color: #B91C1C; background: #FEF2F2; border-radius: 10px; padding: 8px 10px; font-weight: 600; }
.vdc-actions { display: flex; gap: 10px; margin-top: 18px; }
.vdc-btn { flex: 1; border: none; border-radius: 12px; padding: 11px 14px; font-size: 13px; font-weight: 700; }
.vdc-btn.primary { background: linear-gradient(120deg,#1E1B6B,#4B2FD6); color: #fff; }
.vdc-btn.ghost { background: #F1F5F9; color: #334155; }
</style>
<script>
// Pending Vessel submit: before uploading, ask for the Survey Completed date
// and — when no Calculation Sheet (Excel) is attached or already uploaded and
// recovery applies to this survey type — the recovery figures.
(function () {
    var modal = document.getElementById('vdCompleteModal');
    if (!modal) return;
    var pendingForm = null;
    var recBox = modal.querySelector('[data-vdc-recovery]'), note = modal.querySelector('[data-vdc-excel-note]');
    var err = modal.querySelector('[data-vdc-error]'), total = document.getElementById('vdcTotal');
    var parts = modal.querySelectorAll('[data-vdc-part]');
    var totalTouched = false;
    function close() { modal.hidden = true; pendingForm = null; }
    function showErr(m) { err.textContent = m; err.hidden = !m; }
    parts.forEach(function (p) { p.addEventListener('input', function () {
        if (totalTouched) return;
        var any = false, sum = 0;
        parts.forEach(function (x) { if (x.value !== '') { any = true; sum += parseFloat(x.value) || 0; } });
        total.value = any ? (Math.round(sum * 1000) / 1000) : '';
    }); });
    total.addEventListener('input', function () { totalTouched = total.value !== ''; });
    modal.querySelector('[data-vdc-cancel]').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

    document.addEventListener('submit', function (ev) {
        var form = ev.target.closest('[data-report-upload-form]');
        if (!form || form.dataset.vdcConfirmed === '1') return;
        var st = form.querySelector('[name=current_status]');
        if (!st || st.value !== 'Pending Vessel') return;
        ev.preventDefault();
        ev.stopImmediatePropagation();
        var excelInput = form.querySelector('[name=excel_report]');
        var hasExcel = modal.dataset.hasExcel === '1' || (excelInput && excelInput.files.length > 0);
        var needRecovery = modal.dataset.recoveryNa !== '1' && !hasExcel;
        recBox.hidden = !needRecovery;
        note.hidden = !(hasExcel && modal.dataset.recoveryNa !== '1');
        showErr('');
        pendingForm = form;
        modal.hidden = false;
        setTimeout(function () { document.getElementById('vdcDate').focus(); }, 30);
    }, true);

    modal.querySelector('[data-vdc-ok]').addEventListener('click', function () {
        if (!pendingForm) return;
        var date = document.getElementById('vdcDate').value;
        var max = document.getElementById('vdcDate').getAttribute('max');
        if (!date) return showErr('Please choose the Survey Completed date.');
        if (date > max) return showErr('Survey Completed date cannot be in the future.');
        var form = pendingForm;
        form.querySelectorAll('[data-vdc-hidden]').forEach(function (h) { h.remove(); });
        var add = function (name, value) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = name; h.value = value; h.setAttribute('data-vdc-hidden', '');
            form.appendChild(h);
        };
        add('survey_completed_date', date);
        if (!recBox.hidden) {
            if (total.value === '') return showErr('Please enter the recovery (enter 0 if none).');
            add('manual_recovery', '1');
            add('manual_vlsfo', modal.querySelector('[name=vdc_vlsfo]').value);
            add('manual_hsfo', modal.querySelector('[name=vdc_hsfo]').value);
            add('manual_lsmgo', modal.querySelector('[name=vdc_lsmgo]').value);
            add('manual_total', total.value);
        }
        form.dataset.vdcConfirmed = '1';
        modal.hidden = true;
        pendingForm = null;
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
})();
</script>

<?php // Upload progress (% sent) for the report upload forms: assets/js/submit-feedback.js ?>

<?php
aieRender();
include 'includes/nav.php';
include 'includes/footer.php';

// 🌟 Document Replace / Re-upload — modal + behavior, Admin/Surveyor only
// (page access above already guarantees any Surveyor here is the assigned
// one). Included after footer.php's own jQuery/Bootstrap <script> tags —
// same "last jQuery load wins" fix already used elsewhere in this app
// (see assign_vessel.php) — so $.ajaxSetup here and document_replace.js's
// own jQuery reference both bind to the SAME final jQuery instance.
if (in_array($role, ['Admin', 'Surveyor'], true)) {
    include 'includes/document_replace_modal.php';
    ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        $.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });
    </script>
    <script src="assets/js/document_replace.js"></script>
    <?php
}
?>
