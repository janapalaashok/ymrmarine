<?php
require_once 'config/config.php';
require_once 'includes/document_replace_helpers.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();

// 🌟 SAFETY NET: hsfo_recovery column, same as ajax/upload_handler.php —
// covers an Admin editing recovery here before any file has been uploaded
// since this column was added.
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'hsfo_recovery'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN hsfo_recovery DECIMAL(12,3) DEFAULT NULL");
    }
} catch (Exception $e) { error_log('report_detail.php hsfo_recovery column: ' . $e->getMessage()); }

$stmt = $db->prepare("
    SELECT s.*, c.company_name, u.full_name as surveyor_name, p.port_name, t.type_name
    FROM surveys s 
    JOIN clients c ON s.client_id = c.id 
    JOIN users u ON s.surveyor_id = u.id
    JOIN ports p ON s.port_id = p.id
    JOIN survey_types t ON s.survey_type_id = t.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$survey = $stmt->fetch();

if (!$survey) { die("Report asset not found."); }

// 🌟 Recovery doesn't apply to some survey types (Draft Survey, BQS, etc.) —
// see RECOVERY_NOT_APPLICABLE_TYPES in config/config.php.
$recoveryNA = isRecoveryNotApplicable($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''));
// Onhire surveys show recovery with a minus sign (display only — stored and summed as positive)
$recoveryOnhire = isOnhireSurvey($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''));

$current_user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$is_admin = ($role === 'Admin');
$is_super_admin = ($role === 'Super Admin');
// Super Admin can view every report like Admin (read-only — no Upload Final
// Documentation), so it must not be scoped down like a Surveyor/Client.
$has_full_access = ($is_admin || $is_super_admin);
if (!$has_full_access && !isSurveyorAssignedToSurvey($db, $survey['id'], $current_user_id)) {
    $isOwnClient = (($_SESSION['role'] ?? '') === 'Client') && (int)$survey['client_id'] === getClientIdForUser($db, $current_user_id);
    if (!$isOwnClient) {
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
                    You don't have permission to view this report. It isn't assigned to your account.
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

// 🌟 Recovery edit (Admin only): Total / VLSFO / LSMGO recovery for this
// vessel. No redirect — re-renders the same request with the saved values.
$recovery_message = '';
$recovery_error = '';
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_recovery']) && !$recoveryNA) {
    $parseRecovery = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!is_numeric($v)) return false;
        return round(abs((float)$v), 3); // stored positive (an Onhire minus is display only)
    };
    $totalVal = $parseRecovery($_POST['recovery_amount'] ?? '');
    $vlsfoVal = $parseRecovery($_POST['vlsfo_recovery'] ?? '');
    $lsmgoVal = $parseRecovery($_POST['lsmgo_recovery'] ?? '');
    $hsfoVal = $parseRecovery($_POST['hsfo_recovery'] ?? '');
    if ($totalVal === false || $vlsfoVal === false || $lsmgoVal === false || $hsfoVal === false) {
        $recovery_error = 'Recovery values must be numbers.';
    } else {
        try {
            $upd = $db->prepare("UPDATE surveys SET recovery_amount = ?, vlsfo_recovery = ?, lsmgo_recovery = ?, hsfo_recovery = ? WHERE id = ?");
            $upd->execute([$totalVal, $vlsfoVal, $lsmgoVal, $hsfoVal, $id]);
            $survey['recovery_amount'] = $totalVal;
            $survey['vlsfo_recovery'] = $vlsfoVal;
            $survey['lsmgo_recovery'] = $lsmgoVal;
            $survey['hsfo_recovery'] = $hsfoVal;
            $recovery_message = 'Recovery values updated.';
        } catch (Throwable $e) {
            error_log('report_detail recovery update: ' . $e->getMessage());
            $recovery_error = 'Could not save recovery values. Please try again.';
        }
    }
}

// 🌟 మునుపటి స్టేజ్ లో అప్‌లోడ్ చేసిన ఫైల్స్ లిస్ట్ తీసుకోవడం
$uploads_stmt = $db->prepare("SELECT * FROM uploads WHERE survey_id = ?");
$uploads_stmt->execute([$id]);
$uploaded_files = $uploads_stmt->fetchAll();

// 🌟 Single source of truth for the Replace button — Admin always,
// Surveyor only if actually assigned to this survey; Super Admin/Client
// never (re-checked independently in ajax/document_replace.php too).
$can_replace_docs = canReplaceDocument($db, $role, (int)$current_user_id, (int)$survey['id']);

// 🌟 "Send Email to Master for Redelivery" — always opens Gmail's own
// compose window (mail.google.com), not mailto:, since mailto: opens
// whatever the OS/browser has set as its default mail handler (Outlook,
// in practice, for this deployment) rather than Gmail specifically. To/Cc
// are left out of these URLs; To is added when Send is clicked from the To field (typed, or
// filled by "Fetch Master Email" from the vessel's Ships Particulars document —
// includes/master_email.php). Nothing is sent automatically.
$redelivery_type = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
$redelivery_surveyor = getCombinedSurveyorNames($db, $survey['id'] ?? 0, $survey['surveyor_name'] ?? 'N/A');
$redelivery_date = !empty($survey['survey_completed_date']) ? date('d M Y', strtotime($survey['survey_completed_date'])) : '';
$redelivery_subject = 'Redelivery Details Needed for ' . $survey['vessel_name'];
$redelivery_body = "Dear Captain,\n\nGood day.\n\nWith reference to the {$redelivery_type} carried out on board  {$survey['vessel_name']}  at {$survey['port_name']} on {$redelivery_date}, kindly send us the redelivery details to make a final report.\n\nWe would appreciate it if you could please forward the same at your earliest convenience.\n\n\n\nThank you for your cooperation.\n\nBest regards,\n{$redelivery_surveyor}\nYMR Marine Solutions LLP";
$redelivery_gmail_url = 'https://mail.google.com/mail/?view=cm&fs=1&su=' . rawurlencode($redelivery_subject) . '&body=' . rawurlencode($redelivery_body);

// Mobile: open the Gmail app itself (not a browser tab) so the user lands in
// an already-signed-in app instead of being asked to log in again, with To/Cc
// still editable by hand inside the app. iOS uses Gmail's own "co" (compose)
// URL scheme. Android Gmail has no such custom scheme — it only registers
// itself as a handler for the standard mailto: intent — so we route a
// mailto: intent and force it to Gmail specifically via package=, falling
// back to the web compose URL only if Gmail isn't installed.
$redelivery_gmail_ios_url = 'googlegmail://co?subject=' . rawurlencode($redelivery_subject) . '&body=' . rawurlencode($redelivery_body);
$redelivery_gmail_android_url = 'intent://send?subject=' . rawurlencode($redelivery_subject) . '&body=' . rawurlencode($redelivery_body) . '#Intent;scheme=mailto;package=com.google.android.gm;S.browser_fallback_url=' . rawurlencode($redelivery_gmail_url) . ';end';

// Pre-fill the "from account" field with the logged-in user's own email
// (same column/safety-net pattern as profile.php), so it defaults to
// whoever is actually clicking the button instead of starting blank.
$has_email_col = $db->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
$my_email = '';
if ($has_email_col) {
    $myEmailStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $myEmailStmt->execute([$current_user_id]);
    $my_email = (string)($myEmailStmt->fetchColumn() ?: '');
}

require_once 'includes/admin_inline_edit.php';
aieInit($db, $survey);

include 'includes/header.php';
?>

<style>
/* 🌟 Document Replace — View/Download/Replace row actions. Unconditional
   (not inside the @media blocks below): View/Download render for every
   role that can reach this page, including Client — only the Replace
   button itself is PHP-gated to Admin/assigned-Surveyor. */
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
@media (min-width: 992px) {
    .detail-bottom-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        max-width: none;
        width: calc(100% - 32px);
        margin: 8px 16px 20px;
        align-items: stretch; /* equal height cards */
    }
    .detail-bottom-row > .info-table-list,
    .detail-bottom-row > .detail-files-panel,
    .detail-bottom-row > .form-box {
        margin: 0 !important;
        max-width: none;
        height: 100%;
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 16px 18px;
        box-shadow: 0 2px 8px rgba(15,23,42,.04);
    }
    .detail-bottom-row > .form-box form {
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .detail-actions-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: auto;
        padding-top: 12px;
    }
    .detail-actions-row > a,
    .detail-actions-row > button {
        flex: 1 1 140px;
        width: auto !important;
        max-width: none;
        margin: 0 !important;
    }
    .info-row { padding: 10px 16px; }
}
/* Mobile alignment fixes */
@media (max-width: 991.98px) {
    .scroll-content { padding-bottom: 90px; }
    .info-table-list {
        margin: 8px 12px !important;
        width: calc(100% - 24px) !important;
        max-width: none !important;
        border-radius: 12px;
        overflow: hidden;
    }
    .info-row {
        display: flex;
        flex-direction: column;
        align-items: flex-start !important;
        gap: 4px;
        padding: 12px 14px !important;
    }
    .info-row .info-label {
        font-size: 11px !important;
        width: 100%;
    }
    .info-row .info-value {
        font-size: 13px !important;
        width: 100%;
        text-align: left !important;
        word-break: break-word;
    }
    .detail-bottom-row {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin: 8px 12px 24px !important;
        width: calc(100% - 24px) !important;
        max-width: none !important;
    }
    .detail-bottom-row > .detail-files-panel,
    .detail-bottom-row > .form-box {
        margin: 0 !important;
        width: 100% !important;
        max-width: none !important;
        border-radius: 12px;
        padding: 14px !important;
    }
    .detail-actions-row {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 12px;
    }
    .detail-actions-row > a,
    .detail-actions-row > button {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        text-align: center;
        justify-content: center;
    }
    .form-box .form-control,
    .form-box .form-control-sm {
        font-size: 14px;
    }
}

    /* ═══ Desktop modern redesign — same hero + icon-chip card visual
       language as vessel_detail.php. Mobile (".detail-mobile-view", the
       markup above/below unchanged) keeps the existing layout; only
       >=992px swaps to this. Page-scoped <style>. */
    .detail-mobile-view { display: block; }
    .detail-desktop-view { display: none; }
    @media (min-width: 992px) {
        .detail-mobile-view { display: none !important; }
        .detail-desktop-view { display: block !important; }
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
        .vdd-hero-stats { display: flex; align-items: center; gap: 10px; position: relative; flex-wrap: wrap; }
        .vdd-hero-stat {
            background: rgba(255,255,255,.1); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,.16); border-radius: 14px; padding: 9px 18px; text-align: center; min-width: 84px;
        }
        .vdd-hero-stat .n { font-size: 18px; font-weight: 800; color: #fff; line-height: 1.1; }
        .vdd-hero-stat .l { font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: rgba(255,255,255,.55); margin-top: 3px; }

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
        .vdd-info-icon {
            width: 34px; height: 34px; border-radius: 10px; flex: none;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
        }
        .vdd-info-label { flex: 0 0 150px; font-size: 12.5px; color: #64748b; font-weight: 600; }
        .vdd-info-value { font-size: 13.5px; color: #0f172a; font-weight: 700; min-width: 0; }
        .vdd-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 800;
            padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
        }
        .vdd-badge .dot { width: 5px; height: 5px; border-radius: 50%; background: #2563EB; flex: none; }
        .vdd-badge-soft {
            display: inline-flex; align-items: center; background: #F8FAFC; color: #334155;
            font-size: 11.5px; font-weight: 700; padding: 4px 11px; border-radius: 999px; border: 1px solid #e2e8f0;
        }

        /* Recovery figures — 2x2 stat-tile grid */
        .vdd-recovery-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; padding: 18px 22px 22px; }
        .vdd-recovery-tile {
            background: #F8FAFC; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 16px;
            display: flex; align-items: center; gap: 12px;
        }
        .vdd-recovery-icon {
            width: 36px; height: 36px; border-radius: 10px; flex: none;
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }
        .vdd-recovery-value { font-size: 16px; font-weight: 800; color: #0f172a; line-height: 1.15; }
        .vdd-recovery-label { font-size: 10.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; margin-top: 1px; }

        /* Modern form fields (Edit Recovery) */
        .vdd-field { margin-bottom: 12px; }
        .vdd-field label { display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 5px; }
        .vdd-field input {
            width: 100%; box-sizing: border-box; border: 1.5px solid #e2e8f0; border-radius: 12px;
            padding: 10px 14px; font-size: 13.5px; color: #0f172a; font-family: inherit; background: #F8FAFC;
        }
        .vdd-field input:focus { outline: none; border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); background: #fff; }
        .vdd-save-btn {
            width: 100%; border: none; background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff;
            height: 44px; border-radius: 12px; font-weight: 800; font-size: 13px; cursor: pointer;
            box-shadow: 0 8px 18px rgba(75,47,214,.28);
        }
        .vdd-alert { border-radius: 12px; padding: 10px 14px; font-size: 12.5px; font-weight: 600; margin-bottom: 12px; }
        .vdd-alert-success { background: #D1FAE5; color: #065f46; }
        .vdd-alert-danger { background: #FEE2E2; color: #991b1b; }

        /* Upload rows (Formal report / extra docs) */
        .vdd-upload-row {
            border: 1.5px dashed #C7D2E0; border-radius: 14px; padding: 14px 16px;
            display: flex; align-items: center; gap: 12px; background: #F8FAFC;
        }
        .vdd-upload-row + .vdd-upload-row,
        .vdd-upload-row + .vdd-extra-row,
        .vdd-extra-row + .vdd-upload-row { margin-top: 10px; }
        .vdd-upload-icon { width: 36px; height: 36px; border-radius: 10px; flex: none; display: flex; align-items: center; justify-content: center; font-size: 15px; }
        .vdd-upload-label { font-size: 12.5px; font-weight: 700; color: #0f172a; }
        .vdd-upload-hint { font-size: 11px; color: #94a3b8; margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .vdd-upload-hint.picked { color: #16A34A; font-weight: 600; }
        .vdd-upload-hint.picked i { margin-right: 3px; }
        .vdd-upload-choose {
            position: relative; flex: none; overflow: hidden;
            display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #e2e8f0;
            color: #334155; font-size: 11.5px; font-weight: 700; padding: 7px 14px; border-radius: 999px;
        }
        .vdd-upload-choose input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; }

        .vdd-extra-row {
            border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px 14px; background: #fff;
            display: flex; align-items: center; gap: 10px; position: relative;
        }
        .vdd-extra-row label { flex: none; font-size: 11px; font-weight: 700; color: #94a3b8; }
        .vdd-extra-row input[type=file] { flex: 1 1 auto; font-size: 12px; min-width: 0; }
        .vdd-extra-remove {
            flex: none; width: 26px; height: 26px; border-radius: 8px; border: none; background: #FEE2E2; color: #B91C1C;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
        }
        .vdd-add-more-btn {
            width: 100%; margin-top: 10px; border: 1.5px dashed #C7D2E0; background: #fff; color: #5B2DEE;
            border-radius: 12px; padding: 10px; font-size: 12.5px; font-weight: 700; cursor: pointer;
        }
        .vdd-add-more-btn:hover { background: #FAF8FF; }

        .vdd-btn-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .vdd-btn-row > * {
            flex: 1 1 160px; border: none; border-radius: 12px; height: 44px; padding: 0 16px;
            font-weight: 800; font-size: 12.5px; color: #fff !important; text-decoration: none !important;
            display: inline-flex; align-items: center; justify-content: center; gap: 7px; cursor: pointer;
        }
        .vdd-btn-primary { background: linear-gradient(120deg, #1E1B6B, #4B2FD6); box-shadow: 0 8px 18px rgba(75,47,214,.28); }
        .vdd-btn-green { background: #16A34A; box-shadow: 0 8px 18px rgba(22,163,74,.25); }
        .vdd-btn-blue { background: #2563EB; box-shadow: 0 8px 18px rgba(37,99,235,.25); }

        /* File list (downloads) */
        .vdd-file-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px 14px;
        }
        .vdd-file-row + .vdd-file-row { margin-top: 10px; }
        .vdd-file-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none; font-size: 15px; }
        .vdd-file-name { font-size: 12.5px; font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 260px; }
        .vdd-file-dl {
            flex: none; width: 34px; height: 34px; border-radius: 10px; background: #F8FAFC; border: 1px solid #e2e8f0;
            color: #5B2DEE; display: flex; align-items: center; justify-content: center; text-decoration: none !important;
        }
        .vdd-file-dl:hover { background: #5B2DEE; color: #fff; border-color: #5B2DEE; }
        .vdd-file-empty { text-align: center; padding: 30px 20px; color: #94a3b8; font-size: 12.5px; }

        .vdd-invoice-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; border: none; border-radius: 14px; height: 48px;
            background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff !important; font-weight: 800; font-size: 13.5px;
            text-decoration: none !important; box-shadow: 0 10px 22px rgba(75,47,214,.3);
        }
    }
</style>


<div class="scroll-content">
    <?php $page_title = 'Pending Report Details'; $back_url = 'reports.php'; $page_testid = 'report-detail'; include 'includes/top_app_bar.php'; ?>
    <?php $overdueAlertKind = 'report'; include 'includes/overdue_alert.php'; // Report Overdue (4+ days since survey completion) ?>

    <?php
        require_once __DIR__ . '/includes/detail_app.php';
        [$daImg, $daReal] = daPhoto($db, (string)$survey['vessel_name']);
        $daRec = fn($v) => $v !== null ? recoveryDisplay($v, $recoveryOnhire, 3) . ' MT' : '—';
        $daRecOpen = $recovery_message || $recovery_error;
    ?>
    <link rel="stylesheet" href="assets/css/detail-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/detail-app.css') ?>">
    <div class="detail-mobile-view dapp dapp-yellow" data-testid="report-detail-app">
        <div class="dapp-band" aria-hidden="true"></div>
        <div class="dapp-card dapp-hero">
            <div class="dapp-hero-top">
                <div class="dapp-photo<?= $daReal ? ' real' : '' ?>"><img src="<?= sanitize($daImg) ?>" alt="" onerror="this.onerror=null;this.src='<?= VI_DEFAULT ?>';this.parentNode.classList.remove('real')" data-testid="rd-photo"></div>
                <div class="dapp-hn"><small>Vessel</small><span class="dapp-name"><?= sanitize($survey['vessel_name']) ?><?= aie('vessel_name') ?></span>
                    <div class="dapp-chips"><span class="dapp-chip ref"><i class="fa-solid fa-hashtag"></i> <?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?><?= aie('report_number') ?></span><span class="dapp-chip st-report"><i class="fa-solid fa-hourglass-half"></i> Pending Report</span></div></div>
            </div>
            <div class="dapp-acts">
                <?php if (!$is_super_admin && $role !== 'Client'): ?>
                <a href="report_generator.php?id=<?= (int)$survey['id'] ?>" class="dapp-act wd" data-testid="generate-word-report-button"><i class="fa-solid fa-file-word"></i>Word Report</a>
                <a href="photo_report_generator.php?id=<?= (int)$survey['id'] ?>" class="dapp-act ph" data-testid="generate-photo-report-button"><i class="fa-regular fa-images"></i>Photo Report</a>
                <?php endif; ?>
                <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                <a href="invoice_generator.php?id=<?= (int)$survey['id'] ?>" class="dapp-act iv" data-testid="generate-invoice-button"><i class="fa-solid fa-file-invoice-dollar"></i>Invoice</a>
                <?php endif; ?>
                <?php
                    $daShare = implode("\n", array_filter([
                        (string)$survey['vessel_name'],
                        !empty($survey['report_number']) ? 'Report No: ' . $survey['report_number'] : null,
                        'Client: ' . ($survey['company_name'] ?? '—'),
                        'Survey Type: ' . getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A'),
                        'Port: ' . ($survey['port_name'] ?? '—'),
                        'Survey Completed: ' . date('d M Y', strtotime($survey['survey_completed_date'])),
                        'Status: Pending Report',
                    ]));
                ?>
                <button type="button" class="dapp-act sh" data-pv-share data-share-text="<?= sanitize($daShare) ?>" data-testid="report-detail-share"><i class="fa-solid fa-share-nodes"></i>Share</button>
            </div>
        </div>

        <div class="dapp-card">
            <div class="dapp-sec-h"><span class="si"><i class="fa-solid fa-ship"></i></span><b>Survey Details</b></div>
            <div class="dapp-grid">
                <?= daField('fa-solid fa-handshake', 'Client', daVal($survey['company_name'] ?? ''), aie('client_id')) ?>
                <?= daField('fa-solid fa-clipboard-list', 'Survey Type', '<span class="dapp-tag">' . sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) . '</span>', aie('survey_type_ids')) ?>
                <?= daField('fa-solid fa-location-dot', 'Port', daVal($survey['port_name'] ?? ''), aie('port_id'), '', 'rd-port-mobile') ?>
                <?= daField('fa-regular fa-calendar-check', 'Survey Completed', daVal(date('d M Y', strtotime($survey['survey_completed_date'])), 'ok'), aie('survey_completed_date')) ?>
                <?= daField('fa-solid fa-user-tie', 'Surveyor', daPeople((string)getCombinedSurveyorNames($db, $survey['id'] ?? 0, $survey['surveyor_name'] ?? 'N/A')), aie('surveyors'), 'full last') ?>
            </div>
            <?php if ($recoveryNA): ?>
                <div class="dapp-grid" style="padding-top:0"><?= daField('fa-solid fa-droplet', 'Recovery', daVal(RECOVERY_NA_LABEL), '', 'full last', 'recovery-na-mobile') ?></div>
            <?php else: ?>
                <?= daRecovery($daRec($survey['recovery_amount']), $daRec($survey['vlsfo_recovery']), $daRec($survey['lsmgo_recovery']), $daRec($survey['hsfo_recovery']), $is_admin ? 'rdRecForm' : '', 'rd-recovery-mobile') ?>
                <?php if ($is_admin): ?>
                <?php if ($recovery_message): ?><div class="dapp-alert ok" data-testid="recovery-success"><?= sanitize($recovery_message) ?></div><?php endif; ?>
                <?php if ($recovery_error): ?><div class="dapp-alert err" data-testid="recovery-error"><?= sanitize($recovery_error) ?></div><?php endif; ?>
                <div class="dapp-rec-form" id="rdRecForm" data-testid="recovery-edit-box"<?= $daRecOpen ? '' : ' hidden' ?>>
                    <form method="POST"><?= csrf_field() ?>
                        <div class="mb-2"><label class="form-label fw-bold text-secondary" style="font-size:11px;">Total Recovery (MT)</label>
                            <input type="number" step="0.001" min="0" name="recovery_amount" class="form-control form-control-sm" value="<?= $survey['recovery_amount'] !== null ? sanitize((string)abs((float)$survey['recovery_amount'])) : '' ?>" data-testid="recovery-field-total"></div>
                        <div class="mb-2"><label class="form-label fw-bold text-secondary" style="font-size:11px;">VLSFO Recovery (MT)</label>
                            <input type="number" step="0.001" min="0" name="vlsfo_recovery" class="form-control form-control-sm" value="<?= $survey['vlsfo_recovery'] !== null ? sanitize((string)abs((float)$survey['vlsfo_recovery'])) : '' ?>" data-testid="recovery-field-vlsfo"></div>
                        <div class="mb-2"><label class="form-label fw-bold text-secondary" style="font-size:11px;">LSMGO Recovery (MT)</label>
                            <input type="number" step="0.001" min="0" name="lsmgo_recovery" class="form-control form-control-sm" value="<?= $survey['lsmgo_recovery'] !== null ? sanitize((string)abs((float)$survey['lsmgo_recovery'])) : '' ?>" data-testid="recovery-field-lsmgo"></div>
                        <div class="mb-2"><label class="form-label fw-bold text-secondary" style="font-size:11px;">HSFO Recovery (MT)</label>
                            <input type="number" step="0.001" min="0" name="hsfo_recovery" class="form-control form-control-sm" value="<?= $survey['hsfo_recovery'] !== null ? sanitize((string)abs((float)$survey['hsfo_recovery'])) : '' ?>" data-testid="recovery-field-hsfo"></div>
                        <div class="d-flex gap-2"><button type="submit" name="update_recovery" class="btn btn-primary btn-sm w-100" data-testid="save-recovery-button">Save Recovery</button>
                            <button type="button" class="btn btn-light btn-sm w-100" data-dapp-toggle="rdRecForm">Cancel</button></div>
                    </form>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="dapp-card">
            <div class="dapp-sec-h"><span class="si" style="background:#fff1e6;color:#ea580c"><i class="fa-solid fa-folder-open"></i></span><b>Survey Files</b><span class="r"><?= count($uploaded_files) ?> file<?= count($uploaded_files) === 1 ? '' : 's' ?></span></div>
            <div class="dapp-files detail-files-panel">
        <?php if(count($uploaded_files) > 0): ?>
            <?php foreach($uploaded_files as $file): ?>
                <?php
                    $raw_name = $file['file_name'] ?? '';
                    $clean_name = preg_replace('/^[0-9]+_/', '', $raw_name);
                    if ($clean_name === '' || $clean_name === null) $clean_name = $raw_name;
                    $icon = 'fa-file-pdf text-danger';
                    $lower = strtolower($raw_name . ' ' . ($file['file_type'] ?? ''));
                    if (str_contains($lower, 'xlsx') || str_contains($lower, 'xls') || str_contains($lower, 'excel')) $icon = 'fa-file-excel text-success';
                    elseif (str_contains($lower, 'doc') || str_contains($lower, 'word')) $icon = 'fa-file-word text-primary';
                    if (str_ends_with(strtolower($raw_name), '.zip')) $icon = 'fa-file-zipper text-warning';
                ?>
                <div class="bg-white p-2 rounded-3 border d-flex align-items-center justify-content-between mb-2 shadow-sm" style="font-size: 12px;" data-doc-row data-doc-id="<?= (int)$file['id'] ?>">
                    <div class="d-flex align-items-center gap-2" style="max-width:62%;">
                        <i class="fa-regular <?= $icon ?> fs-5"></i>
                        <span class="text-dark fw-semibold text-truncate" data-doc-name-display title="<?= sanitize($clean_name) ?>"><?= sanitize($clean_name) ?></span>
                    </div>
                    <div class="doc-actions-row">
                        <a href="download.php?upload_id=<?= (int)$file['id'] ?>&inline=1" target="_blank" rel="noopener" class="doc-view-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                        <a href="download.php?upload_id=<?= (int)$file['id'] ?>" data-doc-download-link class="doc-view-btn" title="Download"><i class="fa-solid fa-download"></i></a>
                        <?php if ($can_replace_docs): ?>
                        <button type="button" class="doc-replace-btn" title="Replace / Re-upload"
                                data-doc-id="<?= (int)$file['id'] ?>"
                                data-doc-name="<?= sanitize($clean_name) ?>"
                                data-doc-type="<?= sanitize($file['file_type']) ?>"
                                data-doc-size="<?= sanitize($file['file_size']) ?>"
                                data-doc-uploaded-at="<?= sanitize(fmtDocumentDateTime($file['uploaded_at'] ?? '')) ?>"
                                data-doc-uploaded-by="<?= sanitize($file['uploaded_by'] ? getUserFullNameById($db, (int)$file['uploaded_by']) : '—') ?>">
                            <i class="fa-solid fa-rotate"></i> Replace
                        </button>
                        <?php endif; ?><?= aieFileButtons($file) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-muted small ps-2">No files attached to this vessel yet.</div>
        <?php endif; ?>
        <?= aieAddFilesButton() ?>
            </div>
        </div>

    <?php if (!$is_super_admin && $role !== 'Client'): ?>
        <div class="dapp-card form-box">
            <div class="dapp-sec-h"><span class="si" style="background:#eaf1ff;color:#1D63ED"><i class="fa-solid fa-cloud-arrow-up"></i></span><b>Final Documentation</b></div>
            <form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data" id="reportUploadForm" class="report-upload-form dapp-up"><?= csrf_field() ?>
                <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
                <input type="hidden" name="current_status" value="<?= $survey['status'] ?>">
                <input type="hidden" name="no_report_confirmed" id="noReportConfirmed" class="no-report-confirmed" value="0">
                <label class="dapp-ur" for="finalReportInput"><span class="fi fi-pdf"><i class="fa-solid fa-file-pdf"></i></span><span class="t"><b>Final Report</b><small>PDF</small></span>
                    <input type="file" name="final_report_pdf" id="finalReportInput" class="final-report-input" accept=".pdf,application/pdf" data-testid="final-report-pdf-input"></label>
                <label class="dapp-ur" for="zipFolderInput"><span class="fi fi-zip"><i class="fa-solid fa-file-zipper"></i></span><span class="t"><b>ZIP Folder</b><small>Photos / supporting documents · max 30 MB</small></span>
                    <input type="file" name="zip_folder" id="zipFolderInput" class="zip-folder-input" accept=".zip,application/zip,application/x-zip-compressed" data-testid="zip-folder-input"></label>
                <div id="additionalFilesContainer"></div>
                <button type="button" class="dapp-ghost add-more-files-btn" data-target="additionalFilesContainer"><i class="fa-solid fa-plus"></i> Add More Files (optional)</button>
                <button type="submit" class="dapp-btn wide" id="uploadAndMoveBtn" data-testid="complete-report-submit-button"><i class="fa-solid fa-circle-check"></i> Complete &amp; Move</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($role !== 'Client'): ?>
        <div class="dapp-card redelivery-block" data-testid="redelivery-block-mobile">
            <div class="dapp-sec-h"><span class="si" style="background:#e6fbf7;color:#0f766e"><i class="fa-solid fa-envelope-open-text"></i></span><b>Email to Master</b></div>
            <div style="padding:0 16px 4px;font-size:11.5px;color:#64748b">For redelivery · your Gmail address (optional) picks which account composes.</div>
            <div style="padding:0 16px 6px">
                <div style="display:flex;gap:8px">
                    <input type="email" placeholder="To — Master's email" data-redelivery-to aria-label="To — Master's email" data-testid="redelivery-to-input" style="flex:1;min-width:0;box-sizing:border-box;border:1.5px solid #d6e0ee;border-radius:14px;padding:10px 12px;font-size:13px">
                    <button type="button" data-redelivery-fetch data-testid="redelivery-fetch-button" style="border:0;border-radius:14px;background:linear-gradient(135deg,#4B2FD6,#7C3AED);color:#fff;font-weight:700;font-size:12.5px;padding:0 12px;white-space:nowrap;cursor:pointer"><i class="fa-solid fa-magnifying-glass"></i> Fetch Master Email</button>
                </div>
                <label style="display:inline-flex;align-items:center;gap:5px;margin-top:6px;padding:4px 10px;border:1px dashed #a78bfa;border-radius:999px;background:#F5F3FF;color:#5B2DEE;font-size:11.5px;font-weight:700;cursor:pointer" data-testid="redelivery-upload-button"><i class="fa-solid fa-upload"></i> Upload Ships Particulars<input type="file" accept=".pdf,.docx,.xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.webp,.zip" data-redelivery-upload hidden></label>
                <div style="font-size:11px;color:#64748b;margin-top:3px" data-redelivery-note></div>
            </div>
            <div class="dapp-mail">
                <input type="email" placeholder="Your Gmail address" value="<?= sanitize($my_email) ?>" data-redelivery-from data-testid="redelivery-from-input">
                <a href="#" data-redelivery-open data-base-url="<?= sanitize($redelivery_gmail_url) ?>" data-ios-url="<?= sanitize($redelivery_gmail_ios_url) ?>" data-android-url="<?= sanitize($redelivery_gmail_android_url) ?>" class="dapp-btn" data-testid="redelivery-email-button"><i class="fa-solid fa-paper-plane"></i> Send</a>
            </div>
        </div>
    <?php endif; ?>
    </div><!-- /.detail-mobile-view -->
<script>window.VC_CFG = window.VC_CFG || <?= json_encode(['csrf' => csrf_token(), 'def' => VI_DEFAULT]) ?>;</script>
<script src="assets/js/vessel-cards.js?v=<?= @filemtime(__DIR__ . '/assets/js/vessel-cards.js') ?>"></script>
<script>document.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-dapp-toggle]'); if (!b) return; var t = document.getElementById(b.getAttribute('data-dapp-toggle')); if (t) { t.hidden = !t.hidden; if (!t.hidden) t.scrollIntoView({ behavior: 'smooth', block: 'center' }); } });</script>

    <div class="detail-desktop-view">
        <div class="vdd-wrap">
            <a href="<?= sanitize($back_url ?: 'reports.php') ?>" class="vdd-back">
                <span class="vdd-back-icon"><i class="fa-solid fa-chevron-left"></i></span>
                Back
            </a>

            <div class="vdd-hero">
                <i class="fa-solid fa-file-lines vdd-hero-icon-bg" aria-hidden="true"></i>
                <div class="vdd-hero-left">
                    <div class="vdd-hero-ship"><i class="fa-solid fa-file-lines"></i></div>
                    <div>
                        <div class="vdd-hero-title-row">
                            <div class="vdd-hero-title"><?= sanitize($survey['vessel_name']) ?></div><?= aie('vessel_name') ?>
                        </div>
                        <div class="vdd-hero-sub">
                            <?php if (!empty($survey['report_number'])): ?>Report <?= sanitize($survey['report_number']) ?> &nbsp;·&nbsp; <?php endif; ?>
                            <?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?> &nbsp;·&nbsp; Completed <?= sanitize(date('d M Y', strtotime($survey['survey_completed_date']))) ?>
                        </div>
                    </div>
                </div>
                <?php if (!$recoveryNA): ?>
                <div class="vdd-hero-stats">
                    <div class="vdd-hero-stat">
                        <div class="n"><?= $survey['recovery_amount'] !== null ? sanitize(recoveryDisplay($survey['recovery_amount'], $recoveryOnhire, 2)) : '—' ?></div>
                        <div class="l">Total MT</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="vdd-grid">
                <div>
                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="fa-solid fa-file-lines"></i></div>
                            <div class="vdd-card-head-title">Survey Information</div>
                        </div>
                        <div class="vdd-info-rows">
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#F1F5FF;color:#4F46E5;"><i class="fa-solid fa-ship"></i></div>
                                <div class="vdd-info-label">Vessel Name<?= aie('vessel_name') ?></div>
                                <div class="vdd-info-value"><?= sanitize($survey['vessel_name']) ?></div>
                            </div>
                            <div class="vdd-info-row alt" data-testid="rd-port-desktop">
                                <div class="vdd-info-icon" style="background:#ECFEFF;color:#0891B2;"><i class="fa-solid fa-location-dot"></i></div>
                                <div class="vdd-info-label">Port<?= aie('port_id') ?></div>
                                <div class="vdd-info-value"><?= sanitize($survey['port_name'] ?? '—') ?></div>
                            </div>
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#F1F5FF;color:#4F46E5;"><i class="fa-solid fa-hashtag"></i></div>
                                <div class="vdd-info-label">Report No<?= aie('report_number') ?></div>
                                <div class="vdd-info-value"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></div>
                            </div>
                            <div class="vdd-info-row alt">
                                <div class="vdd-info-icon" style="background:#F1F5FF;color:#4F46E5;"><i class="fa-solid fa-building"></i></div>
                                <div class="vdd-info-label">Client Name<?= aie('client_id') ?></div>
                                <div class="vdd-info-value"><?= sanitize($survey['company_name']) ?></div>
                            </div>
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:linear-gradient(135deg,#8B7CF6,#4B2FD6);color:#fff;"><i class="fa-solid fa-user-tie"></i></div>
                                <div class="vdd-info-label">Surveyor Name<?= aie('surveyors') ?></div>
                                <div class="vdd-info-value" style="color:#5B2DEE;"><?= sanitize(getCombinedSurveyorNames($db, $survey['id'] ?? 0, $survey['surveyor_name'] ?? 'N/A')) ?></div>
                            </div>
                            <div class="vdd-info-row alt">
                                <div class="vdd-info-icon" style="background:#F3EEFE;color:#5B2DEE;"><i class="fa-solid fa-file-circle-check"></i></div>
                                <div class="vdd-info-label">Survey Type<?= aie('survey_type_ids') ?></div>
                                <div><span class="vdd-badge"><span class="dot"></span><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></div>
                            </div>
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#D1FAE5;color:#047857;"><i class="fa-solid fa-calendar-check"></i></div>
                                <div class="vdd-info-label">Survey Completed<?= aie('survey_completed_date') ?></div>
                                <div class="vdd-info-value" style="color:#047857;"><?= sanitize(date('d M Y', strtotime($survey['survey_completed_date']))) ?></div>
                            </div>
                            <?php if ($recoveryNA): ?>
                            <div class="vdd-info-row alt" data-testid="recovery-na-desktop">
                                <div class="vdd-info-icon" style="background:#ECFEFF;color:#0891B2;"><i class="fa-solid fa-droplet"></i></div>
                                <div class="vdd-info-label">Recovery</div>
                                <div class="vdd-info-value"><?= sanitize(RECOVERY_NA_LABEL) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!$recoveryNA): ?>
                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#ECFEFF;color:#0891B2;"><i class="fa-solid fa-droplet"></i></div>
                            <div class="vdd-card-head-title">Recovery Summary<?= aie('recovery') ?></div>
                        </div>
                        <div class="vdd-recovery-grid">
                            <div class="vdd-recovery-tile">
                                <div class="vdd-recovery-icon" style="background:#F3EEFE;color:#5B2DEE;"><i class="fa-solid fa-droplet"></i></div>
                                <div>
                                    <div class="vdd-recovery-value"><?= $survey['recovery_amount'] !== null ? sanitize(recoveryDisplay($survey['recovery_amount'], $recoveryOnhire, 3)) . ' MT' : '—' ?></div>
                                    <div class="vdd-recovery-label">Total</div>
                                </div>
                            </div>
                            <div class="vdd-recovery-tile">
                                <div class="vdd-recovery-icon" style="background:#EFF6FF;color:#1D4ED8;"><i class="fa-solid fa-droplet"></i></div>
                                <div>
                                    <div class="vdd-recovery-value"><?= $survey['vlsfo_recovery'] !== null ? sanitize(recoveryDisplay($survey['vlsfo_recovery'], $recoveryOnhire, 3)) . ' MT' : '—' ?></div>
                                    <div class="vdd-recovery-label">VLSFO</div>
                                </div>
                            </div>
                            <div class="vdd-recovery-tile">
                                <div class="vdd-recovery-icon" style="background:#D1FAE5;color:#047857;"><i class="fa-solid fa-droplet"></i></div>
                                <div>
                                    <div class="vdd-recovery-value"><?= $survey['lsmgo_recovery'] !== null ? sanitize(recoveryDisplay($survey['lsmgo_recovery'], $recoveryOnhire, 3)) . ' MT' : '—' ?></div>
                                    <div class="vdd-recovery-label">LSMGO</div>
                                </div>
                            </div>
                            <div class="vdd-recovery-tile">
                                <div class="vdd-recovery-icon" style="background:#FFF7ED;color:#EA580C;"><i class="fa-solid fa-droplet"></i></div>
                                <div>
                                    <div class="vdd-recovery-value"><?= $survey['hsfo_recovery'] !== null ? sanitize(recoveryDisplay($survey['hsfo_recovery'], $recoveryOnhire, 3)) . ' MT' : '—' ?></div>
                                    <div class="vdd-recovery-label">HSFO</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#FFFBEB;color:#B45309;"><i class="fa-solid fa-folder-open"></i></div>
                            <div class="vdd-card-head-title">Download Pre-Uploaded Survey Files</div>
                        </div>
                        <div class="vdd-card-body">
                            <?php if (count($uploaded_files) > 0): foreach ($uploaded_files as $file):
                                $vdd_raw_name = $file['file_name'] ?? '';
                                $vdd_clean_name = preg_replace('/^[0-9]+_/', '', $vdd_raw_name);
                                if ($vdd_clean_name === '' || $vdd_clean_name === null) $vdd_clean_name = $vdd_raw_name;
                                $vdd_icon = 'fa-file-pdf'; $vdd_icon_bg = '#FEE2E2'; $vdd_icon_fg = '#B91C1C';
                                $vdd_lower = strtolower($vdd_raw_name . ' ' . ($file['file_type'] ?? ''));
                                if (str_contains($vdd_lower, 'xlsx') || str_contains($vdd_lower, 'xls') || str_contains($vdd_lower, 'excel')) { $vdd_icon = 'fa-file-excel'; $vdd_icon_bg = '#D1FAE5'; $vdd_icon_fg = '#047857'; }
                                elseif (str_contains($vdd_lower, 'doc') || str_contains($vdd_lower, 'word')) { $vdd_icon = 'fa-file-word'; $vdd_icon_bg = '#EEF2FF'; $vdd_icon_fg = '#4F46E5'; }
                                if (str_ends_with(strtolower($vdd_raw_name), '.zip')) { $vdd_icon = 'fa-file-zipper'; $vdd_icon_bg = '#FEF3C7'; $vdd_icon_fg = '#B45309'; }
                            ?>
                            <div class="vdd-file-row" data-doc-row data-doc-id="<?= (int)$file['id'] ?>">
                                <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                                    <div class="vdd-file-icon" style="background:<?= $vdd_icon_bg ?>;color:<?= $vdd_icon_fg ?>;"><i class="fa-solid <?= $vdd_icon ?>"></i></div>
                                    <div class="vdd-file-name" data-doc-name-display title="<?= sanitize($vdd_clean_name) ?>"><?= sanitize($vdd_clean_name) ?></div>
                                </div>
                                <div class="doc-actions-row">
                                    <a href="download.php?upload_id=<?= (int)$file['id'] ?>&inline=1" target="_blank" rel="noopener" class="doc-view-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                                    <a href="download.php?upload_id=<?= (int)$file['id'] ?>" data-doc-download-link class="doc-view-btn" title="Download"><i class="fa-solid fa-download"></i></a>
                                    <?php if ($can_replace_docs): ?>
                                    <button type="button" class="doc-replace-btn" title="Replace / Re-upload"
                                            data-doc-id="<?= (int)$file['id'] ?>"
                                            data-doc-name="<?= sanitize($vdd_clean_name) ?>"
                                            data-doc-type="<?= sanitize($file['file_type']) ?>"
                                            data-doc-size="<?= sanitize($file['file_size']) ?>"
                                            data-doc-uploaded-at="<?= sanitize(fmtDocumentDateTime($file['uploaded_at'] ?? '')) ?>"
                                            data-doc-uploaded-by="<?= sanitize($file['uploaded_by'] ? getUserFullNameById($db, (int)$file['uploaded_by']) : '—') ?>">
                                        <i class="fa-solid fa-rotate"></i> Replace
                                    </button>
                                    <?php endif; ?><?= aieFileButtons($file) ?>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="vdd-file-empty"><i class="fa-solid fa-folder-open d-block mb-2" style="font-size:22px;opacity:.5;"></i>No files attached to this vessel yet.</div>
                            <?php endif; ?>
                            <?= aieAddFilesButton() ?>
                        </div>
                    </div>
                </div>

                <div>
                    <?php if ($is_admin && !$recoveryNA): ?>
                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#F3EEFE;color:#5B2DEE;"><i class="fa-solid fa-droplet"></i></div>
                            <div class="vdd-card-head-title">Edit Recovery</div>
                        </div>
                        <div class="vdd-card-body" data-testid="recovery-edit-box-desktop">
                            <?php if ($recovery_message): ?><div class="vdd-alert vdd-alert-success" data-testid="recovery-success-desktop"><?= sanitize($recovery_message) ?></div><?php endif; ?>
                            <?php if ($recovery_error): ?><div class="vdd-alert vdd-alert-danger" data-testid="recovery-error-desktop"><?= sanitize($recovery_error) ?></div><?php endif; ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <div class="vdd-field">
                                    <label>Total Recovery (MT)</label>
                                    <input type="number" step="0.001" min="0" name="recovery_amount" value="<?= $survey['recovery_amount'] !== null ? sanitize((string)abs((float)$survey['recovery_amount'])) : '' ?>" data-testid="recovery-field-total-desktop">
                                </div>
                                <div class="vdd-field">
                                    <label>VLSFO Recovery (MT)</label>
                                    <input type="number" step="0.001" min="0" name="vlsfo_recovery" value="<?= $survey['vlsfo_recovery'] !== null ? sanitize((string)abs((float)$survey['vlsfo_recovery'])) : '' ?>" data-testid="recovery-field-vlsfo-desktop">
                                </div>
                                <div class="vdd-field">
                                    <label>LSMGO Recovery (MT)</label>
                                    <input type="number" step="0.001" min="0" name="lsmgo_recovery" value="<?= $survey['lsmgo_recovery'] !== null ? sanitize((string)abs((float)$survey['lsmgo_recovery'])) : '' ?>" data-testid="recovery-field-lsmgo-desktop">
                                </div>
                                <div class="vdd-field">
                                    <label>HSFO Recovery (MT)</label>
                                    <input type="number" step="0.001" min="0" name="hsfo_recovery" value="<?= $survey['hsfo_recovery'] !== null ? sanitize((string)abs((float)$survey['hsfo_recovery'])) : '' ?>" data-testid="recovery-field-hsfo-desktop">
                                </div>
                                <button type="submit" name="update_recovery" class="vdd-save-btn" data-testid="save-recovery-button-desktop">Save Recovery</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$is_super_admin && $role !== 'Client'): ?>
                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:linear-gradient(135deg,#8B7CF6,#4B2FD6);color:#fff;"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                            <div class="vdd-card-head-title">Upload Final Documentation</div>
                        </div>
                        <div class="vdd-card-body">
                            <form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data" class="report-upload-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
                                <input type="hidden" name="current_status" value="<?= $survey['status'] ?>">
                                <input type="hidden" name="no_report_confirmed" class="no-report-confirmed" value="0">

                                <div class="vdd-upload-row">
                                    <div class="vdd-upload-icon" style="background:#FEE2E2;color:#DC2626;"><i class="fa-solid fa-file-pdf"></i></div>
                                    <div style="flex:1 1 auto;min-width:0;">
                                        <div class="vdd-upload-label">1. Final Report</div>
                                        <div class="vdd-upload-hint" data-upload-hint data-default="PDF document (.pdf)">PDF document (.pdf)</div>
                                    </div>
                                    <label class="vdd-upload-choose"><i class="fa-solid fa-arrow-up-from-bracket"></i> Choose file<input type="file" name="final_report_pdf" class="final-report-input vdd-file-input" accept=".pdf,application/pdf" data-testid="final-report-pdf-input-desktop"></label>
                                </div>

                                <div class="vdd-upload-row">
                                    <div class="vdd-upload-icon" style="background:#FEF3C7;color:#B45309;"><i class="fa-solid fa-file-zipper"></i></div>
                                    <div style="flex:1 1 auto;min-width:0;">
                                        <div class="vdd-upload-label">2. ZIP Folder <span style="font-weight:500;color:#64748b;">(optional)</span></div>
                                        <div class="vdd-upload-hint" data-upload-hint data-default="Photos / supporting documents (.zip, max 30 MB)">Photos / supporting documents (.zip, max 30 MB)</div>
                                    </div>
                                    <label class="vdd-upload-choose"><i class="fa-solid fa-arrow-up-from-bracket"></i> Choose file<input type="file" name="zip_folder" class="zip-folder-input vdd-file-input" accept=".zip,application/zip,application/x-zip-compressed" data-testid="zip-folder-input-desktop"></label>
                                </div>

                                <div class="vdd-additional-files-desktop" data-additional-files-container></div>

                                <button type="button" class="vdd-add-more-btn add-more-files-btn">
                                    <i class="fa-solid fa-plus-circle"></i> Add More Files (Optional)
                                </button>

                                <div class="vdd-btn-row">
                                    <button type="submit" class="vdd-btn-primary" data-testid="complete-report-submit-button-desktop">Complete &amp; Move</button>
                                    <a href="report_generator.php?id=<?= $survey['id'] ?>" class="vdd-btn-green" data-testid="generate-word-report-button-desktop">
                                        <i class="fa-solid fa-file-lines"></i> Generate Word Report
                                    </a>
                                    <a href="photo_report_generator.php?id=<?= $survey['id'] ?>" class="vdd-btn-blue" data-testid="generate-photo-report-button-desktop">
                                        <i class="fa-regular fa-images"></i> Generate Photo Report
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($role !== 'Client'): ?>
                    <div class="vdd-card redelivery-block" data-testid="redelivery-block-desktop">
                        <div class="vdd-card-body">
                            <div class="vdd-field" style="margin-bottom:10px;">
                                <label>To — Master's email</label>
                                <div style="display:flex;gap:8px;align-items:stretch">
                                    <input type="email" placeholder="master@…" data-redelivery-to data-testid="redelivery-to-input-desktop" style="flex:1;min-width:0">
                                    <button type="button" data-redelivery-fetch data-testid="redelivery-fetch-button-desktop" style="border:0;border-radius:14px;background:linear-gradient(135deg,#4B2FD6,#7C3AED);color:#fff;font-weight:700;font-size:12.5px;padding:0 12px;white-space:nowrap;cursor:pointer;border-radius:10px"><i class="fa-solid fa-magnifying-glass"></i> Fetch Master Email</button>
                                </div>
                                <label style="display:inline-flex;align-items:center;gap:5px;margin-top:6px;padding:4px 10px;border:1px dashed #a78bfa;border-radius:999px;background:#F5F3FF;color:#5B2DEE;font-size:11.5px;font-weight:700;cursor:pointer" data-testid="redelivery-upload-button-desktop"><i class="fa-solid fa-upload"></i> Upload Ships Particulars<input type="file" accept=".pdf,.docx,.xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.webp,.zip" data-redelivery-upload hidden></label>
                                <div style="font-size:11px;color:#64748b;margin-top:3px" data-redelivery-note></div>
                            </div>
                            <div class="vdd-field" style="margin-bottom:10px;">
                                <label>Your Gmail address (optional — picks which account composes)</label>
                                <input type="email" placeholder="you@gmail.com" value="<?= sanitize($my_email) ?>" data-redelivery-from data-testid="redelivery-from-input-desktop">
                            </div>
                            <a href="#" data-redelivery-open data-base-url="<?= sanitize($redelivery_gmail_url) ?>" data-ios-url="<?= sanitize($redelivery_gmail_ios_url) ?>" data-android-url="<?= sanitize($redelivery_gmail_android_url) ?>" class="vdd-invoice-btn" style="background:linear-gradient(120deg,#0f766e,#0d9488);" data-testid="redelivery-email-button-desktop">
                                <i class="fa-solid fa-envelope"></i> Send Email to Master for Redelivery
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                    <div class="vdd-card">
                        <div class="vdd-card-body">
                            <a href="invoice_generator.php?id=<?= (int)$survey['id'] ?>" class="vdd-invoice-btn" data-testid="generate-invoice-button-desktop">
                                <i class="fa-solid fa-file-invoice-dollar"></i> Generate Invoice
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div><!-- /.detail-desktop-view -->

    <?php
    // Survey Report: Start / Resume Survey, and the Admin's tank history
    // Survey Report: started / resumed from Survey Reports in the menu (survey_reports.php?survey_id=…), not from this page
    ?>

    <script>
    // 🌟 File-input "chosen" confirmation for the desktop Upload Final
    // Documentation card — purely a display confirmation, same pattern as
    // vessel_detail.php; field names/behavior are unchanged.
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
        #noReportConfirmModal .modal-content { border: none; border-radius: 20px; box-shadow: 0 20px 44px rgba(15,23,42,.22); overflow: hidden; }
        #noReportConfirmModal .modal-body { padding: 30px 26px 18px; }
        #noReportConfirmModal .no-report-icon {
            width: 48px; height: 48px; border-radius: 14px; margin: 0 auto 14px;
            background: #FEF3C7; color: #B45309; display: flex; align-items: center; justify-content: center; font-size: 20px;
        }
        #noReportConfirmModal .modal-footer { border-top: 1px solid #f1f5f9; padding: 16px 26px 22px; gap: 10px; }
        #noReportConfirmModal .modal-footer .btn {
            border-radius: 999px; font-weight: 700; font-size: 12.5px; height: 40px; border: none;
        }
        #noReportConfirmModal .btn-outline-secondary { background: #F1F5F9; color: #475569; }
        #noReportConfirmModal .btn-primary { background: #5B2DEE; box-shadow: 0 8px 18px rgba(91,45,238,.3); }
    </style>

    <!-- 🌟 Confirmation modal shown when Upload and Move is clicked without a report document -->
    <div class="modal fade" id="noReportConfirmModal" tabindex="-1" aria-hidden="true" data-testid="no-report-confirm-modal">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <div class="no-report-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <p class="fw-semibold mb-0" style="font-size: 14px;">Do you really want to continue without the Final Report (PDF)?</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal" data-testid="no-report-confirm-cancel">Cancel</button>
                    <button type="button" class="btn btn-primary flex-fill" id="noReportContinueBtn" data-testid="no-report-confirm-continue">Yes, Continue</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function() {
        // 🌟 Both the mobile form (#reportUploadForm / #additionalFilesContainer)
        // and the desktop redesign's duplicate form share these classes, so the
        // "Add More Files" / no-report-confirm logic below works for whichever
        // one the viewport is currently showing — matched by id when present
        // (mobile, unchanged) or by walking up to the closest form (desktop).
        $('.add-more-files-btn').each(function() {
            var $btn = $(this);
            var targetId = $btn.data('target');
            var $container = targetId ? $('#' + targetId) : $btn.closest('form').find('[data-additional-files-container]');
            var fileIndex = 1;
            $btn.on('click', function() {
                var html = `
                    <div class="mb-3 p-2 border rounded bg-light position-relative">
                        <label class="form-label fw-bold text-muted" style="font-size:11px;">Extra Documents #${fileIndex} <span style="font-weight:500;">— select one or more files (PDF, Word, Excel, PowerPoint, images, ZIP…)</span></label>
                        <input type="file" name="extra_files[]" class="form-control form-control-sm extra-files-input" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.xlsm,.csv,.txt,.rtf,.ppt,.pptx,.odt,.ods,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.tif,.tiff,.bmp,.zip,.rar,.7z,.msg,.eml" data-testid="extra-files-input">
                        <button type="button" class="btn btn-sm text-danger position-absolute end-0 top-0 mt-1 remove-file-btn"><i class="fa-solid fa-trash"></i></button>
                    </div>`;
                $container.append(html);
                fileIndex++;
            });
        });

        $(document).on('click', '.remove-file-btn', function() {
            $(this).parent().remove();
        });

        // 🌟 If no Final Report (PDF) is selected, confirm before continuing the
        // workflow. activeForm tracks which instance (mobile or desktop) is
        // waiting on the confirm modal, since both share the same modal.
        var activeForm = null;
        $('.report-upload-form').each(function() {
            var $form = $(this);
            $form.on('submit', function(e) {
                var hasFile = $form.find('.final-report-input')[0].files.length > 0;
                var alreadyConfirmed = $form.find('.no-report-confirmed').val() === '1';
                if (!hasFile && !alreadyConfirmed) {
                    e.preventDefault();
                    activeForm = $form;
                    var confirmModal = new bootstrap.Modal(document.getElementById('noReportConfirmModal'));
                    confirmModal.show();
                    return;
                }
                // Then ask for the Report Completed date (saved as the report date).
                if ($form.attr('data-rcd-done') !== '1' && window.ysmsAskReportDate) {
                    e.preventDefault();
                    window.ysmsAskReportDate($form[0]);
                }
            });
        });

        $('#noReportContinueBtn').on('click', function() {
            if (!activeForm) return;
            activeForm.find('.no-report-confirmed').val('1');
            bootstrap.Modal.getInstance(document.getElementById('noReportConfirmModal')).hide();
            activeForm.trigger('submit');
        });
    });
</script>

<script>
// "Send Email to Master for Redelivery" — an optional "Your Gmail address"
// field lets the user pick which signed-in Google account the compose
// window opens under (Gmail's own &authuser= param), purely by what they
// type here. Nothing is sent automatically and To/Cc stay blank either way.
// On phones this opens the Gmail app directly (already signed in) instead
// of a browser tab that would ask the user to log in again; desktop keeps
// opening the Gmail web compose tab as before.
// To: "Fetch Master Email" reads the Master's email from the vessel's uploaded Ships Particulars
// document (ajax/master_email.php) and puts it in To; when it can't, the user types it.
function rdNote(text, color) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-redelivery-note]'), function (n) { n.textContent = text; n.style.color = color || '#64748b'; });
}
var RD_API = 'ajax/master_email.php?id=<?= (int)$survey['id'] ?>', RD_CSRF = <?= json_encode(csrf_token()) ?>;
function rdBusy(on, label) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-redelivery-fetch]'), function (b) {
        b.dataset.label = b.dataset.label || b.innerHTML;
        b.disabled = on; b.innerHTML = on ? '<i class="fa-solid fa-spinner fa-spin"></i> ' + label : b.dataset.label;
    });
}
// the answer of a Fetch or an Upload: To filled, or "enter it manually"
function rdApply(j) {
    var email = (j && j.email) || '';
    if (email) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-redelivery-to]'), function (i) { i.value = email; });
        rdNote((j.by === 'ai' ? '⚠ ' : '✓ ') + (j.note || ''), j.by === 'ai' ? '#b45309' : '#047857');
        return;
    }
    rdNote('Master email could not be fetched — please enter it manually.' + (j && (j.error || j.note) ? ' (' + (j.error || j.note) + ')' : ''), '#b91c1c');
    var vis = Array.prototype.filter.call(document.querySelectorAll('[data-redelivery-to]'), function (i) { return i.offsetParent !== null; })[0];
    if (vis) vis.focus();
}
document.addEventListener('click', function (ev) {
    var fb = ev.target.closest && ev.target.closest('[data-redelivery-fetch]');
    if (!fb || fb.disabled) return;
    ev.preventDefault();
    rdBusy(true, 'Fetching…');
    rdNote('Looking for the Master\'s email in the uploaded documents…');
    fetch(RD_API, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, email: '' }; })
        .then(rdApply)
        .then(function () { rdBusy(false); });
});
// "Upload Ships Particulars": the chosen file is read once for the Master's email (not stored);
// in a ZIP only its Ships Particulars files are read.
document.addEventListener('change', function (ev) {
    var inp = ev.target;
    if (!inp.matches || !inp.matches('[data-redelivery-upload]') || !inp.files || !inp.files[0]) return;
    var fd = new FormData();
    fd.append('particulars', inp.files[0]);
    fd.append('csrf_token', RD_CSRF);
    rdBusy(true, 'Reading…');
    rdNote('Reading ' + inp.files[0].name + ' for the Master\'s email…');
    fetch(RD_API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': RD_CSRF } })
        .then(function (r) { return r.json(); })
        .catch(function () { return { ok: false, email: '', error: 'The file could not be sent.' }; })
        .then(rdApply)
        .then(function () { rdBusy(false); inp.value = ''; });
});
// the two To fields (phone / computer layout) stay the same
document.addEventListener('input', function (ev) {
    if (!ev.target.matches || !ev.target.matches('[data-redelivery-to]')) return;
    Array.prototype.forEach.call(document.querySelectorAll('[data-redelivery-to]'), function (i) { if (i !== ev.target) i.value = ev.target.value; });
});
document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-redelivery-open]');
    if (!btn) return;
    ev.preventDefault();
    var block = btn.closest('.redelivery-block');
    var input = block ? block.querySelector('[data-redelivery-from]') : null;
    var email = input ? input.value.trim() : '';
    var ua = navigator.userAgent || '';
    (function () {
        var toEl = block ? block.querySelector('[data-redelivery-to]') : null;
        var to = toEl ? toEl.value.trim() : '';
        if (!/^[^\s@,;]+@[^\s@,;]+\.[A-Za-z]{2,24}$/.test(to)) to = ''; // only a single, valid address
        var webUrl = btn.getAttribute('data-base-url') + (to ? '&to=' + encodeURIComponent(to) : '') + (email ? '&authuser=' + encodeURIComponent(email) : '');
        if (/Android/i.test(ua)) {
            var a = btn.getAttribute('data-android-url');
            window.location.href = to ? a.replace('intent://send?', 'intent://' + encodeURIComponent(to).replace('%40', '@') + '?') : a;
            return;
        }
        if (/iPhone|iPad|iPod/i.test(ua)) {
            var appOpened = false;
            var onBlur = function () { appOpened = true; window.removeEventListener('blur', onBlur); };
            window.addEventListener('blur', onBlur);
            window.location.href = btn.getAttribute('data-ios-url') + (to ? '&to=' + encodeURIComponent(to) : '');
            setTimeout(function () {
                window.removeEventListener('blur', onBlur);
                if (!appOpened) window.location.href = webUrl;
            }, 1500);
            return;
        }
        window.open(webUrl, '_blank', 'noopener');
    })();
});
</script>

<script>
// ZIP folder + upload size guard. Cloud Run refuses any request over 32 MB
// before it reaches PHP (the user would just see a generic error page), so
// oversized selections are stopped here with a clear message instead.
(function () {
    var ZIP_MAX = 30 * 1024 * 1024, TOTAL_MAX = 31 * 1024 * 1024;
    function warn(title, text) {
        if (window.Swal) Swal.fire({ icon: 'warning', title: title, text: text, confirmButtonColor: '#1e3a8a' });
        else alert(title + '\n\n' + text);
    }
    function mb(b) { return (b / 1048576).toFixed(1) + ' MB'; }
    document.addEventListener('change', function (e) {
        var input = e.target;
        if (input.classList && input.classList.contains('final-report-input') && input.files && input.files[0] && !/\.pdf$/i.test(input.files[0].name)) {
            warn('PDF only', 'The Final Report must be a PDF (.pdf) file. Please choose the PDF version of the report.');
            input.value = '';
            input.dispatchEvent(new Event('ysms:cleared', { bubbles: true }));
            return;
        }
        if (!input.classList || !input.classList.contains('zip-folder-input') || !input.files || !input.files[0]) return;
        var f = input.files[0];
        if (!/\.zip$/i.test(f.name)) {
            warn('Only .zip files', 'Please choose a .zip file for the ZIP Folder.');
            input.value = '';
        } else if (f.size > ZIP_MAX) {
            warn('ZIP folder too large', f.name + ' is ' + mb(f.size) + '. The maximum is 30 MB — please split it into smaller ZIP files or reduce photo sizes.');
            input.value = '';
        }
        if (!input.value) input.dispatchEvent(new Event('ysms:cleared', { bubbles: true }));
    });
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('report-upload-form')) return;
        var total = 0;
        form.querySelectorAll('input[type=file]').forEach(function (i) {
            for (var k = 0; i.files && k < i.files.length; k++) total += i.files[k].size;
        });
        if (total > TOTAL_MAX) {
            e.preventDefault();
            e.stopImmediatePropagation();
            warn('Files too large to upload together', 'The selected files add up to ' + mb(total) + ' (limit 31 MB per upload). Please upload them in smaller batches.');
        }
    }, true);
})();
</script>

<?php $rcdMin = !empty($survey['survey_completed_date']) && strpos((string)$survey['survey_completed_date'], '0000') !== 0 ? date('Y-m-d', strtotime($survey['survey_completed_date'])) : ''; ?>
<div class="rcd-modal" id="rcdModal" hidden data-testid="report-date-modal" role="dialog" aria-modal="true" aria-labelledby="rcdTitle">
    <div class="rcd-box">
        <div class="rcd-head">
            <div class="rcd-ico"><i class="fa-solid fa-file-circle-check"></i></div>
            <div>
                <div class="rcd-title" id="rcdTitle">Report Completed Date</div>
                <div class="rcd-sub"><?= sanitize($survey['vessel_name']) ?></div>
            </div>
        </div>
        <label class="rcd-label" for="rcdDate">Report Completed Date <span class="req">*</span></label>
        <input type="date" id="rcdDate" class="rcd-input" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"<?= $rcdMin !== '' && $rcdMin <= date('Y-m-d') ? ' min="' . sanitize($rcdMin) . '"' : '' ?> data-testid="report-date-input">
        <?php if ($rcdMin !== ''): ?><div class="rcd-hint">Survey completed on <?= sanitize(date('d M Y', strtotime($rcdMin))) ?>. The Completed list is ordered by this date.</div><?php endif; ?>
        <div class="rcd-error" data-rcd-error hidden></div>
        <div class="rcd-actions">
            <button type="button" class="rcd-btn ghost" data-rcd-cancel>Cancel</button>
            <button type="button" class="rcd-btn primary" data-rcd-ok data-testid="report-date-submit"><i class="fa-solid fa-check"></i> Complete &amp; Move</button>
        </div>
    </div>
</div>
<style>
.rcd-modal { position: fixed; inset: 0; z-index: 9998; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.rcd-modal[hidden] { display: none; }
.rcd-box { background: #fff; border-radius: 18px; width: 100%; max-width: 400px; padding: 20px; box-shadow: 0 24px 60px rgba(0,0,0,.25); }
.rcd-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.rcd-ico { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg,#14532D,#16A34A); color: #fff; display: flex; align-items: center; justify-content: center; flex: none; }
.rcd-title { font-size: 16px; font-weight: 800; color: #0f172a; }
.rcd-sub { font-size: 12px; color: #64748b; }
.rcd-label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin: 8px 0 6px; }
.rcd-label .req { color: #DC2626; }
.rcd-input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 9px 11px; font-size: 14px; background: #F8FAFC; }
.rcd-input:focus { outline: none; border-color: #A78BFA; background: #fff; }
.rcd-hint { font-size: 11px; color: #94a3b8; margin-top: 6px; }
.rcd-error { margin-top: 10px; font-size: 12px; color: #B91C1C; background: #FEF2F2; border-radius: 10px; padding: 8px 10px; font-weight: 600; }
.rcd-actions { display: flex; gap: 10px; margin-top: 18px; }
.rcd-btn { flex: 1; border: none; border-radius: 12px; padding: 11px 14px; font-size: 13px; font-weight: 700; }
.rcd-btn.primary { background: linear-gradient(120deg,#14532D,#16A34A); color: #fff; }
.rcd-btn.ghost { background: #F1F5F9; color: #334155; }
</style>
<script>
// Pending Report → Completed: ask the Report Completed date before the form
// is sent. The upload form's own submit handler calls ysmsAskReportDate().
(function () {
    var modal = document.getElementById('rcdModal'), input = document.getElementById('rcdDate');
    var err = modal.querySelector('[data-rcd-error]'), pending = null;
    function close() { modal.hidden = true; pending = null; }
    function showErr(m) { err.textContent = m; err.hidden = !m; }
    window.ysmsAskReportDate = function (form) {
        pending = form; showErr(''); modal.hidden = false;
        setTimeout(function () { input.focus(); }, 30);
    };
    modal.querySelector('[data-rcd-cancel]').addEventListener('click', function () {
        if (pending) { var nr = pending.querySelector('.no-report-confirmed'); if (nr) nr.value = '0'; }
        close();
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.querySelector('[data-rcd-cancel]').click(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) modal.querySelector('[data-rcd-cancel]').click(); });
    modal.querySelector('[data-rcd-ok]').addEventListener('click', function () {
        if (!pending) return;
        var v = input.value;
        if (!v) return showErr('Please choose the Report Completed date.');
        if (input.max && v > input.max) return showErr('Report Completed date cannot be in the future.');
        if (input.min && v < input.min) return showErr('Report Completed date cannot be before the Survey Completed date.');
        var form = pending;
        var h = form.querySelector('input[name=report_completed_date]');
        if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'report_completed_date'; form.appendChild(h); }
        h.value = v;
        form.setAttribute('data-rcd-done', '1');
        modal.hidden = true; pending = null;
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
})();
</script>

<?php
aieRender();
include 'includes/nav.php';
include 'includes/footer.php';

// 🌟 Document Replace / Re-upload — modal + behavior, Admin/Surveyor only.
// Included after footer.php's own jQuery/Bootstrap <script> tags — same
// "last jQuery load wins" fix already used elsewhere in this app (see
// assign_vessel.php) — so $.ajaxSetup here and document_replace.js's own
// jQuery reference both bind to the SAME final jQuery instance.
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