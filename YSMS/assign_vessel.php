<?php
require_once 'config/config.php';
require_once 'includes/mailer.php';
require_once 'includes/report_number.php';
require_once 'includes/notifications.php';
checkAuth();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Client'], true)) {
    header('Location: index.php');
    exit;
}
$is_client_role = (($_SESSION['role'] ?? '') === 'Client');

$db = getDB();
$error = '';
$success = '';

// 🌟 SAFETY NET: లైవ్ డేటాబేస్‌లో `survey_type_ids` కాలమ్ లేకపోతే (database/migration_multi_survey_type.sql
// రన్ చేయకపోతే) ప్రతి assign attempt ఇక్కడే "Unknown column" SQL error తో ఫెయిల్ అవుతుంది —
// దీన్నే యూజర్ "database error" గా చూస్తున్నారు. వర్క్‌ఫ్లో/లాజిక్/UI ఏమీ మార్చకుండా, కాలమ్
// ఉందో లేదో చెక్ చేసి, లేకపోతే ఆటోమేటిక్‌గా యాడ్ చేయడం ద్వారా ఈ ఎర్రర్‌ను ఫిక్స్ చేస్తున్నాం.
try {
    $col_exists = $db->query("SHOW COLUMNS FROM surveys LIKE 'survey_type_ids'")->fetchAll();
    if (empty($col_exists)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN survey_type_ids VARCHAR(255) DEFAULT NULL AFTER survey_type_id");
        $db->exec("UPDATE surveys SET survey_type_ids = survey_type_id WHERE survey_type_ids IS NULL");
    }
} catch (Exception $e) {
    error_log('assign_vessel.php survey_type_ids column check/add error: ' . $e->getMessage());
}

// 🌟 SAFETY NET: report_number column (unique YMR-YYYY-NNNNN)
try {
    $rn_exists = $db->query("SHOW COLUMNS FROM surveys LIKE 'report_number'")->fetchAll();
    if (empty($rn_exists)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN report_number VARCHAR(50) DEFAULT NULL AFTER vessel_name");
        // Unique index — NULLs allowed for old rows; new rows always get a value
        try {
            $db->exec("ALTER TABLE surveys ADD UNIQUE INDEX uq_surveys_report_number (report_number)");
        } catch (Exception $ie) {
            // Index may already exist or engine may not allow — ignore
            error_log('assign_vessel.php report_number unique index: ' . $ie->getMessage());
        }
    }
} catch (Exception $e) {
    error_log('assign_vessel.php report_number column check/add error: ' . $e->getMessage());
}

// 🌟 SAFETY NET: assignment attachment_path column
try {
    $att_exists = $db->query("SHOW COLUMNS FROM surveys LIKE 'attachment_path'")->fetchAll();
    if (empty($att_exists)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('assign_vessel.php attachment_path column: ' . $e->getMessage());
}

// 🌟 SAFETY NET: live status columns (used by vessels.php / vessel_detail / export)
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
    error_log('assign_vessel.php live-status columns: ' . $e->getMessage());
}

try {
    $db->exec("ALTER TABLE surveys MODIFY COLUMN assign_date DATETIME NULL");
} catch (Exception $e) {}





// Preview number shown on the form (recomputed on every page load)
// Ensure client short codes exist
try {
    ensureClientShortCodeColumn($db);
    backfillAllClientShortCodes($db);
    ensureReportNumberColumnWide($db);
} catch (Throwable $e) {
    error_log('assign short code init: ' . $e->getMessage());
}
// Report number only after client is selected
$preview_report_number = '';

// 1. పోర్ట్స్ లిస్ట్ తెచ్చుకోవడం
$ports = $db->query("SELECT * FROM ports")->fetchAll();

// 2. క్లయింట్స్ లిస్ట్ తెచ్చుకోవడం
try {
    $clients = $db->query("SELECT id, company_name, short_code FROM clients ORDER BY company_name ASC")->fetchAll();
} catch (Throwable $e) {
    // short_code column may not exist yet on first load
    $clients = $db->query("SELECT id, company_name FROM clients ORDER BY company_name ASC")->fetchAll();
}
$my_client_id = 0;
if ($is_client_role) {
    $my_client_id = getClientIdForUser($db, (int)$_SESSION['user_id']);
    // A client-role user may only ever assign vessels under their own company.
    $clients = array_values(array_filter($clients, fn($c) => (int)$c['id'] === $my_client_id));
}

// 3. సర్వేయర్స్ లిస్ట్ తెచ్చుకోవడం (role_id = 2)
$surveyors = $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND status = 'Active'")->fetchAll();

// 4. సర్వే టైప్స్ తెచ్చుకోవడం
$survey_types = $db->query("SELECT * FROM survey_types")->fetchAll();

// ఫార్మ్ సబ్మిషన్ ప్రాసెస్
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vessel_name = normalizeVesselName(trim($_POST['vessel_name']));
    $client_id = $is_client_role ? $my_client_id : (int)$_POST['client_id'];
    $agent_name = trim($_POST['agent_name']);
    $port_id = (int)$_POST['port_id'];

    // 🌟 బహుళ Survey Types (checkbox multi-select) — CSV గా వస్తుంది, ఉదా. "3,5,7"
    $survey_type_ids_csv = trim($_POST['survey_type_ids'] ?? '');
    $survey_type_ids_arr = array_values(array_unique(array_filter(array_map('intval', explode(',', $survey_type_ids_csv)))));
    $survey_type_ids_csv = implode(',', $survey_type_ids_arr);
    // పాత survey_type_id కాలమ్ (సింగిల్ FK) కోసం మొదటి ఎంచుకున్న టైప్‌ను ఉంచడం — మిగతా పేజీల్లో ఉన్న
    // JOIN survey_types లాజిక్ ఏమాత్రం మార్చకుండా అలాగే పని చేయడానికి
    $survey_type_id = !empty($survey_type_ids_arr) ? $survey_type_ids_arr[0] : 0;

    $surveyor_id = $_POST['surveyor_id']; // 'outsourcing' లేదా ID కావచ్చు
    $remarks = trim($_POST['remarks']);

    // సర్వేయర్ ఐడి హ్యాండ్లింగ్ (Outsourcing అయితే యూజర్ టేబుల్‌లో లేని ఐడి లేదా అడ్మిన్ ఐడి సెట్ చేయవచ్చు)
    // ఇక్కడ ఔట్‌సోర్సింగ్ కోసం ఒక డమ్మీ వాల్యూ (उदा. 1 లేదా అడ్మిన్ ఐడి) లేదా ప్రత్యేక లాజిక్ ఇవ్వచ్చు.
    $final_surveyor_id = ($surveyor_id === 'outsourcing') ? 1 : (int)$surveyor_id; 
    if($surveyor_id === 'outsourcing' && $remarks == '') {
        $remarks = "Outsourced Survey.";
    }

    if (!empty($vessel_name) && $client_id > 0 && !empty($agent_name) && $port_id > 0 && !empty($survey_type_ids_arr) && !empty($surveyor_id)) {
        // 🌟 DB ఇన్సర్ట్‌ను try/catch లో ఉంచడం — ఏదైనా DB ఎర్రర్ వస్తే (ఉదా. FK మిస్‌మ్యాచ్,
        // మిస్సింగ్ కాలమ్ మొదలైనవి) తెల్లతెరతో సైట్ క్రాష్ అవ్వకుండా, ఫారమ్ మీదే స్పష్టమైన
        // ఎర్రర్ మెసేజ్ చూపించడానికి (ఇతర పేజీల్లో — వెసెల్ డీటెయిల్ లాంటివి — ఇదే పద్ధతి వాడారు)
        // Report number is generated at insert time (not from form) so concurrent assigns stay unique.
        $maxAttempts = 5;
        $inserted = false;
        for ($attempt = 0; $attempt < $maxAttempts && !$inserted; $attempt++) {
            try {
                $report_number = generateNextReportNumberForClient($db, (int)$client_id);
                $stmt = $db->prepare("
                    INSERT INTO surveys 
                    (vessel_name, report_number, client_id, agent_name, surveyor_id, survey_type_id, survey_type_ids, port_id, assign_date, status, remarks) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending Vessel', ?)
                ");

                if ($stmt->execute([$vessel_name, $report_number, $client_id, $agent_name, $final_surveyor_id, $survey_type_id, $survey_type_ids_csv, $port_id, $remarks])) {
                    // Capture id immediately after INSERT (before any other query)
                    $new_survey_id = (int)$db->lastInsertId();
                    if ($new_survey_id <= 0) {
                        try {
                            $idStmt = $db->prepare('SELECT id FROM surveys WHERE report_number = ? ORDER BY id DESC LIMIT 1');
                            $idStmt->execute([$report_number]);
                            $new_survey_id = (int)($idStmt->fetchColumn() ?: 0);
                        } catch (Throwable $ie) {
                            $new_survey_id = 0;
                        }
                    }
                    $success = "Vessel assigned successfully! Report No: " . $report_number;
                    
                    // Optional assignment attachment
                    if ($new_survey_id > 0 && !empty($_FILES['assignment_attachment']['name']) && is_uploaded_file($_FILES['assignment_attachment']['tmp_name'])) {
                        $attDir = __DIR__ . '/uploads/assignments/';
                        if (!is_dir($attDir)) { @mkdir($attDir, 0755, true); }
                        $orig = basename($_FILES['assignment_attachment']['name']);
                        $safe = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                        $dest = $attDir . $safe;
                        if (@move_uploaded_file($_FILES['assignment_attachment']['tmp_name'], $dest)) {
                            $rel = 'uploads/assignments/' . $safe;
                            try {
                                $db->prepare('UPDATE surveys SET attachment_path = ? WHERE id = ?')->execute([$rel, $new_survey_id]);
                            } catch (Throwable $ue) {
                                error_log('assign attachment save: ' . $ue->getMessage());
                            }
                        }
                    }

                    $inserted = true;
                    // Refresh preview for next assign on same page
                    $preview_report_number = ''; // next number only after client select

                    // 📧📱 Auto-notify assigned surveyor (email + optional WhatsApp)
                    if ($surveyor_id !== 'outsourcing' && $final_surveyor_id > 0) {
                        try {
                            $client_name = '';
                            $port_name = '';
                            $cst = $db->prepare('SELECT company_name FROM clients WHERE id = ?');
                            $cst->execute([$client_id]);
                            $client_name = (string)($cst->fetchColumn() ?: '');
                            $pst = $db->prepare('SELECT port_name FROM ports WHERE id = ?');
                            $pst->execute([$port_id]);
                            $port_name = (string)($pst->fetchColumn() ?: '');
                            $type_names = function_exists('getCombinedSurveyTypeNames')
                                ? getCombinedSurveyTypeNames($db, $survey_type_ids_csv, '')
                                : $survey_type_ids_csv;
                            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $base = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
                            // Deep-link to this vessel's detail page (not the list)
                            if (!empty($new_survey_id) && $new_survey_id > 0) {
                                $app_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/vessel_detail.php?id=' . $new_survey_id;
                            } else {
                                $app_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/vessels.php';
                            }
                                                        // Assigning admin profile (From / Reply-To on surveyor email)
                            $admin_name = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');
                            $admin_email = '';
                            try {
                                $uid = (int)($_SESSION['user_id'] ?? 0);
                                if ($uid > 0) {
                                    $ast = $db->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
                                    $ast->execute([$uid]);
                                    $arow = $ast->fetch(PDO::FETCH_ASSOC);
                                    if ($arow) {
                                        if (!empty($arow['full_name'])) {
                                            $admin_name = (string)$arow['full_name'];
                                        }
                                        $admin_email = trim((string)($arow['email'] ?? ''));
                                    }
                                }
                            } catch (Throwable $ae) {
                                error_log('assign_vessel admin email: ' . $ae->getMessage());
                            }
                            $job = [
                                'vessel_name'        => $vessel_name,
                                'report_number'      => $report_number,
                                'client_name'        => $client_name,
                                'port_name'          => $port_name,
                                'survey_types'       => $type_names,
                                'agent_name'         => $agent_name,
                                'assign_date'        => date('d-m-Y'),
                                'remarks'            => $remarks,
                                'app_url'            => $app_url,
                                'assigned_by_name'   => $admin_name,
                                'assigned_by_email'  => $admin_email,
                            ];
                            $notify_msg = notifySurveyorOfAssignment($db, $final_surveyor_id, $job);
                            if ($notify_msg !== '') {
                                $success .= ' · ' . $notify_msg;
                            }
                            // In-app notification to assigned surveyor
                            try {
                                $actor = (string)($_SESSION['full_name'] ?? 'Admin');
                                createNotification(
                                    $db,
                                    (int)$final_surveyor_id,
                                    'New vessel assigned',
                                    $actor . ' assigned ' . $vessel_name . ' (' . $report_number . ') to you.',
                                    'assign',
                                    'vessel_detail.php?id=' . (int)$new_survey_id,
                                    (int)($_SESSION['user_id'] ?? 0)
                                );
                            } catch (Throwable $ne2) {
                                error_log('assign in-app notif: ' . $ne2->getMessage());
                            }
                        } catch (Throwable $ne) {
                            error_log('assign_vessel notify: ' . $ne->getMessage());
                            $success .= ' · Notification skipped (see server log).';
                        }
                    }
                } else {
                    $error = "Failed to assign vessel. Please try again.";
                    break;
                }
            } catch (PDOException $e) {
                // 23000 = integrity constraint (duplicate report_number) — retry with next number
                if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate') !== false) {
                    error_log('assign_vessel.php duplicate report_number, retrying: ' . $e->getMessage());
                    usleep(50000);
                    continue;
                }
                error_log('assign_vessel.php insert error: ' . $e->getMessage());
                $error = "Could not assign the vessel due to a database error. Please check the details and try again.";
                break;
            } catch (Exception $e) {
                error_log('assign_vessel.php insert error: ' . $e->getMessage());
                $error = "Could not assign the vessel due to a database error. Please check the details and try again.";
                break;
            }
        }
        if (!$inserted && $error === '') {
            $error = "Could not generate a unique report number. Please try again.";
        }
    } else {
        $error = "Please fill all required fields.";
    }
}
?>

<?php
include 'includes/header.php';
?>

<style>
    .form-box-custom {
        background: white;
        margin: 15px 20px;
        border-radius: 16px;
        padding: 20px;
        border: 1px solid var(--border-color);
    }
    .form-group-custom {
        margin-bottom: 15px;
    }
    .form-group-custom label {
        font-size: 12px;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 5px;
        display: block;
    }
    .form-group-custom input, .form-group-custom select, .form-group-custom textarea {
        width: 100%;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 13.5px;
        color: var(--text-dark);
        outline: none;
        background: #f8fafc;
    }
    .form-group-custom input:focus, .form-group-custom select:focus, .form-group-custom textarea:focus {
        border-color: #3b32b3;
        background: white;
    }
    #otherClientContainer, #otherPortContainer, #otherSurveyTypeContainer {
        display: none;
        background: #f1f5f9;
        padding: 12px;
        border-radius: 10px;
        margin-top: -10px;
        margin-bottom: 15px;
    }

    /* Searchable dropdown */
    .searchable-select { position: relative; }
    .searchable-select .ss-trigger {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 12px 14px;
        font-size: 14px;
        color: #0f172a;
        background: #ffffff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        text-align: left;
        cursor: pointer;
        min-height: 48px;
        box-shadow: none;
    }
    .searchable-select .ss-trigger:focus { outline: none; border-color: #3b32b3; background: #fff; }
    .searchable-select .ss-trigger-text {
        flex: 1 1 auto;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        line-height: 1.4;
        visibility: visible !important;
        opacity: 1 !important;
        font-size: 14px !important;
    }
    .searchable-select .ss-trigger-text.placeholder {
        color: #64748b !important;
        font-weight: 500 !important;
        opacity: 1 !important;
    }
    .searchable-select .ss-trigger-text:not(.placeholder) {
        color: #0f172a !important;
        font-weight: 600 !important;
    }
    .searchable-select .ss-trigger i { color: var(--text-muted); font-size: 11px; transition: transform .15s ease; flex-shrink: 0; }
    .searchable-select.open .ss-trigger i { transform: rotate(180deg); }
    .searchable-select .ss-panel {
        display: none;
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
        z-index: 300;
        overflow: hidden;
        max-height: min(320px, 55vh);
        -webkit-overflow-scrolling: touch;
    }
    .searchable-select.open .ss-panel { display: block; }
    .searchable-select .ss-search-wrap {
        position: relative;
        padding: 8px 10px;
        border-bottom: 1px solid var(--border-color);
    }
    .searchable-select .ss-search-wrap i {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 12px;
    }
    .searchable-select .ss-search-input {
        width: 100%;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 8px 10px 8px 30px;
        font-size: 13px;
        background: #f8fafc;
        outline: none;
    }
    .searchable-select .ss-search-input:focus { border-color: #3b32b3; background: #fff; }
    .searchable-select .ss-options { list-style: none; margin: 0; padding: 6px; max-height: 220px; overflow-y: auto; }
    .searchable-select .ss-option {
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 13px;
        cursor: pointer;
        color: var(--text-dark);
    }
    .searchable-select .ss-option:hover,
    .searchable-select .ss-option.ss-selected { background: #eef2ff; color: #3b32b3; }
    .searchable-select .ss-option-other { color: #3b32b3; font-weight: 700; border-top: 1px dashed var(--border-color); margin-top: 4px; padding-top: 12px; }
    .searchable-select .ss-option-empty { padding: 12px; color: #94a3b8; font-size: 12px; text-align: center; }
    /* Native select is only for form submit value — never show it */
    .ss-hidden-select,
    .form-group-custom select.ss-hidden-select,
    select.ss-hidden-select {
        position: absolute !important;
        left: -9999px !important;
        width: 1px !important;
        height: 1px !important;
        min-height: 0 !important;
        max-height: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: 0 !important;
        overflow: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
        appearance: none !important;
        -webkit-appearance: none !important;
        background: transparent !important;
        clip: rect(0,0,0,0) !important;
    }

    /* Multi-select (Survey Type checkboxes) — required for selection to work */
    .ss-multi .ss-option {
        display: flex;
        align-items: center;
        gap: 9px;
        min-height: 44px;
        -webkit-tap-highlight-color: rgba(59, 50, 179, 0.15);
        touch-action: manipulation;
    }
    .ss-multi .ss-option-checkbox {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
        accent-color: #3b32b3;
        pointer-events: none;
    }
    .ss-multi .ss-option.ss-selected {
        background: #eef2ff;
        color: var(--text-dark);
        font-weight: 600;
    }
    .ss-multi .ss-option-other .ss-option-checkbox { display: none; }
    .ss-multi .ss-option-label { flex: 1; text-align: left; }

    /* Dropdown panel must sit above form / bottom nav */
    .searchable-select { position: relative; z-index: 1; }
    .searchable-select.open { z-index: 300; }
    .searchable-select .ss-panel {
        z-index: 300;
        max-height: min(320px, 55vh);
    }
    .searchable-select .ss-trigger {
        min-height: 44px;
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
    }

    /* Desktop 2-col */
    @media (min-width: 992px) {
        .form-box-custom {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 20px;
            max-width: 900px;
            margin: 20px auto;
            padding: 28px;
        }
        .form-box-custom > .form-group-custom:last-of-type,
        .form-box-custom > button,
        .form-box-custom > .blue-action-btn,
        .form-box-custom > [class*="action"],
        .form-box-custom > .alert {
            grid-column: 1 / -1;
        }
        .form-box-custom > #otherClientContainer,
        .form-box-custom > #otherPortContainer,
        .form-box-custom > #otherSurveyTypeContainer,
        .form-box-custom > #reportNumberGroup {
            grid-column: 1 / -1;
        }
    }

    /* Mobile: top bar fixed in flow ABOVE scroll; form never clipped */
    @media (max-width: 991.98px) {
        .top-app-bar {
            position: relative !important;
            top: auto !important;
            z-index: 900 !important;
            background: #fff !important;
            padding-top: calc(10px + env(safe-area-inset-top, 0px)) !important;
            padding-bottom: 10px !important;
            flex-shrink: 0 !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        .form-box-custom {
            margin: 16px 12px 28px !important;
            padding: 20px 14px 22px !important;
            overflow: visible !important;
        }
        #vesselNameField {
            display: block !important;
            margin: 0 0 16px 0 !important;
            padding: 0 !important;
        }
        #vesselNameField label {
            display: block !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            color: #64748b !important;
            margin: 0 0 8px 0 !important;
            line-height: 1.4 !important;
        }
        #vesselNameField input,
        .form-group-custom input,
        .form-group-custom select,
        .form-group-custom textarea {
            font-size: 16px !important;
            min-height: 48px !important;
        }
        .form-box-custom { overflow: visible !important; }
        .searchable-select.open { z-index: 400; }
        .searchable-select.open .ss-panel { z-index: 400; }
    }
</style>

<?php
$page_title = 'Assign New Vessel';
$back_url = 'index.php';
$page_testid = 'assign-vessel';
include 'includes/top_app_bar.php';
?>

<div class="scroll-content">
    <!-- Feedback Alerts -->
    <?php if($success): ?>
        <div class="alert alert-success mx-3 mt-3 py-2" style="font-size:12px;"><?= sanitize($success) ?></div>
        <?php if (!empty($GLOBALS['ysms_mail_failed'])): ?>
            <div class="alert alert-warning mx-3 py-2" style="font-size:12px;">
                <strong>Email not delivered.</strong>
                        <?php if (!empty($GLOBALS['ysms_mail_failed'])): ?>
            <div class="alert alert-warning mx-3 py-2" style="font-size:12px;">
                <strong>Email notification could not be sent.</strong>
                <?php if (!$is_client_role): ?>
                    <div class="mt-1 text-muted">The surveyor can still be reached via WhatsApp below. If this keeps happening, check the mail settings or run <a href="test_email.php">the mail test page</a>.</div>
                <?php else: ?>
                    <div class="mt-1 text-muted">The surveyor can still be reached via WhatsApp below, or the office can follow up directly.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($GLOBALS['ysms_last_wa_link'])): ?>
            <div class="mx-3 mb-2">
                <a href="<?= htmlspecialchars($GLOBALS['ysms_last_wa_link'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"
                   class="btn btn-sm w-100" style="background:#25D366;color:#fff;font-weight:650;border-radius:10px;padding:10px;">
                    <i class="fa-brands fa-whatsapp me-1"></i> Open WhatsApp chat with surveyor
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger mx-3 mt-3 py-2" style="font-size:12px;"><?= $error ?></div><?php endif; ?>

    <!-- Assignment Form -->
    <form action="assign_vessel.php" method="POST" id="assignVesselForm" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="form-box-custom shadow-sm" style="padding-top:20px;">
            
            <div class="form-group-custom" id="vesselNameField">
                <label for="vessel_name">Vessel Name *</label>
                <input type="text" name="vessel_name" id="vessel_name" placeholder="e.g. MV Pacific Dawn" required autocomplete="off" inputmode="text" style="font-size:16px;min-height:48px;">
            </div>

                        <!-- 🌟 Client Name: searchable dropdown with search box inside + Other -->
            <div class="form-group-custom">
                <label>Client Name *</label>
                <div class="searchable-select" data-ss-root="client">
                    <button type="button" class="ss-trigger" data-testid="client-select-trigger">
                        <span class="ss-trigger-text placeholder" data-placeholder="Select Client" style="color:#64748b !important;font-weight:500;font-size:14px;">Select Client</span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="ss-panel">
                        <div class="ss-search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="ss-search-input" placeholder="Search client..." autocomplete="off" data-testid="client-search-input">
                        </div>
                        <ul class="ss-options" data-testid="client-options-list">
                            <?php foreach($clients as $client): ?>
                                <li class="ss-option" data-value="<?= $client['id'] ?>" data-name="<?= strtolower(sanitize($client['company_name'])) ?>" data-short="<?= sanitize(strtoupper(trim($client['short_code'] ?? ''))) ?>"><?= sanitize($client['company_name']) ?><?php if (!empty($client['short_code'])): ?> <span style="color:#64748b;font-weight:600;">(<?= sanitize(strtoupper($client['short_code'])) ?>)</span><?php endif; ?></li>
                            <?php endforeach; ?>
                            <?php if (!$is_client_role): ?>
                            <li class="ss-option ss-option-other" data-value="other_client" data-name="other">+ Other (Add New Client)</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <select name="client_id" id="clientSelect" class="ss-hidden-select" tabindex="-1" aria-hidden="true">
                    <option value="">Select Client</option>
                    <?php foreach($clients as $client): ?>
                        <option value="<?= $client['id'] ?>"><?= sanitize($client['company_name']) ?></option>
                    <?php endforeach; ?>
                    <?php if (!$is_client_role): ?>
                    <option value="other_client">Other</option>
                    <?php endif; ?>
                </select>
            </div>
            <!-- Client "Other" టెక్స్ట్ ఫీల్డ్ (డైనమిక్, AJAX సేవ్) -->
            <div id="otherClientContainer" <?= $is_client_role ? 'style="display:none;"' : '' ?>>
                <div class="form-group-custom m-0">
                    <label class="text-primary"><i class="fa-solid fa-pen"></i> Enter Client Name *</label>
                    <div class="d-flex gap-2">
                        <input type="text" id="newClientNameInput" placeholder="Enter new client name" style="flex: 1;" data-testid="new-client-name-input">
                        <button type="button" id="saveNewClientBtn" class="btn btn-sm" style="background:#3b32b3; color:#fff; font-weight:600;" data-testid="save-new-client-button">Save</button>
                    </div>
                    <div id="newClientStatus" class="small mt-1"></div>
                </div>
            </div>

            <!-- Report Number: shown only after client selected — YMR/{SHORT}/{YYYY}/{MM}/{NNNN} -->
            <div class="form-group-custom" id="reportNumberGroup" style="display:none;">
                <label>Report Number <span class="text-muted fw-normal">(auto)</span></label>
                <input type="text" id="reportNumberPreview" value="" readonly
                       style="background:#f1f5f9; color:#0b1e46; font-weight:700; letter-spacing:0.5px; cursor:default;"
                       data-testid="report-number-preview" placeholder="Select client to generate">
                <div class="small text-muted mt-1" style="font-size:11px;">
                    <i class="fa-solid fa-lock me-1"></i>Format: <strong>YMR/AS/<?= date('Y') ?>/<?= date('m') ?>/0001</strong> — client short form + year + month + sequence.
                </div>
            </div>

            
<div class="form-group-custom">
                <label>Agent Name *</label>
                <input type="text" name="agent_name" placeholder="e.g. Oceanus Agencies" required>
            </div>

            <!-- 🌟 Port Name: searchable dropdown with search box inside + Other -->
            <div class="form-group-custom">
                <label>Port Name *</label>
                <div class="searchable-select" data-ss-root="port">
                    <button type="button" class="ss-trigger" data-testid="port-select-trigger">
                        <span class="ss-trigger-text placeholder" data-placeholder="Select Port" style="color:#64748b !important;font-weight:500;font-size:14px;">Select Port</span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="ss-panel">
                        <div class="ss-search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="ss-search-input" placeholder="Search port..." autocomplete="off" data-testid="port-search-input">
                        </div>
                        <ul class="ss-options" data-testid="port-options-list">
                            <?php foreach($ports as $port): ?>
                                <li class="ss-option" data-value="<?= $port['id'] ?>" data-name="<?= strtolower(sanitize($port['port_name'])) ?>"><?= sanitize($port['port_name']) ?></li>
                            <?php endforeach; ?>
                            <li class="ss-option ss-option-other" data-value="other_port" data-name="other">+ Other (Add New Port)</li>
                        </ul>
                    </div>
                </div>
                <select name="port_id" id="portSelect" class="ss-hidden-select" tabindex="-1" aria-hidden="true">
                    <option value="">Select Port</option>
                    <?php foreach($ports as $port): ?>
                        <option value="<?= $port['id'] ?>"><?= sanitize($port['port_name']) ?></option>
                    <?php endforeach; ?>
                    <option value="other_port">Other</option>
                </select>
            </div>
            <!-- Port "Other" టెక్స్ట్ ఫీల్డ్ (డైనమిక్, AJAX సేవ్) -->
            <div id="otherPortContainer">
                <div class="form-group-custom m-0">
                    <label class="text-primary"><i class="fa-solid fa-pen"></i> Enter Port Name *</label>
                    <div class="d-flex gap-2">
                        <input type="text" id="newPortNameInput" placeholder="Enter new port name" style="flex: 1;" data-testid="new-port-name-input">
                        <button type="button" id="saveNewPortBtn" class="btn btn-sm" style="background:#3b32b3; color:#fff; font-weight:600;" data-testid="save-new-port-button">Save</button>
                    </div>
                    <div id="newPortStatus" class="small mt-1"></div>
                </div>
            </div>

            <!-- 🌟 Survey Type: searchable MULTI-select (checkboxes) with search box inside + Other -->
            <div class="form-group-custom">
                <label>Survey Type * <span class="text-muted fw-normal" style="font-size:10.5px;">(select one or more)</span></label>
                <div class="searchable-select ss-multi" data-ss-root="surveyType">
                    <button type="button" class="ss-trigger" data-testid="survey-type-select-trigger">
                        <span class="ss-trigger-text placeholder" data-placeholder="Select Survey Type" style="color:#64748b !important;font-weight:500;font-size:14px;">Select Survey Type</span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="ss-panel">
                        <div class="ss-search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="ss-search-input" placeholder="Search survey type..." autocomplete="off" data-testid="survey-type-search-input">
                        </div>
                        <ul class="ss-options" data-testid="survey-type-options-list">
                            <?php foreach($survey_types as $type): ?>
                                <li class="ss-option" data-value="<?= $type['id'] ?>" data-name="<?= strtolower(sanitize($type['type_name'])) ?>">
                                    <input type="checkbox" class="ss-option-checkbox" tabindex="-1">
                                    <span class="ss-option-label"><?= sanitize($type['type_name']) ?></span>
                                </li>
                            <?php endforeach; ?>
                            <li class="ss-option ss-option-other" data-value="other_survey_type" data-name="other">+ Other (Add New Survey Type)</li>
                        </ul>
                    </div>
                </div>
                <input type="hidden" name="survey_type_ids" id="surveyTypeIdsInput" value="">
            </div>
            <!-- Survey Type "Other" టెక్స్ట్ ఫీల్డ్ (డైనమిక్, AJAX సేవ్) -->
            <div id="otherSurveyTypeContainer">
                <div class="form-group-custom m-0">
                    <label class="text-primary"><i class="fa-solid fa-pen"></i> Enter Survey Type *</label>
                    <div class="d-flex gap-2">
                        <input type="text" id="newSurveyTypeInput" placeholder="Enter your custom survey style name" style="flex: 1;" data-testid="new-survey-type-input">
                        <button type="button" id="saveNewSurveyTypeBtn" class="btn btn-sm" style="background:#3b32b3; color:#fff; font-weight:600;" data-testid="save-new-survey-type-button">Save</button>
                    </div>
                    <div id="newSurveyTypeStatus" class="small mt-1"></div>
                </div>
            </div>

            <div class="form-group-custom">
                <label>Assign Surveyor *</label>
                <div class="searchable-select" data-ss-root="surveyor">
                    <button type="button" class="ss-trigger" data-testid="surveyor-select-trigger">
                        <span class="ss-trigger-text placeholder" data-placeholder="Select Surveyor" style="color:#64748b !important;font-weight:500;font-size:14px;">Select Surveyor</span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="ss-panel">
                        <div class="ss-search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="ss-search-input" placeholder="Search surveyor..." autocomplete="off" data-testid="surveyor-search-input">
                        </div>
                        <ul class="ss-options" data-testid="surveyor-options-list">
                            <?php foreach($surveyors as $surveyor): ?>
                                <li class="ss-option" data-value="<?= (int)$surveyor['id'] ?>" data-name="<?= strtolower(sanitize($surveyor['full_name'])) ?>"><?= sanitize($surveyor['full_name']) ?></li>
                            <?php endforeach; ?>
                            <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                                <li class="ss-option ss-option-other" data-value="outsourcing" data-name="outsourcing" style="color:#dc2626;font-weight:700;">Outsourcing</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <select name="surveyor_id" id="surveyorSelect" class="ss-hidden-select" tabindex="-1" aria-hidden="true" required>
                    <option value="">Select Surveyor</option>
                    <?php foreach($surveyors as $surveyor): ?>
                        <option value="<?= (int)$surveyor['id'] ?>"><?= sanitize($surveyor['full_name']) ?></option>
                    <?php endforeach; ?>
                    <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                        <option value="outsourcing">Outsourcing</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group-custom">
                <label>Remarks (Optional)</label>
                <textarea name="remarks" rows="3" placeholder="Enter any specific instructions or notes..."></textarea>
            </div>

            
            <div class="form-group-custom">
                <label>Assignment Attachment <span class="text-muted" style="font-weight:500;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <input type="file" name="assignment_attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip">
                <div class="text-muted" style="font-size:11px;margin-top:4px;">Surveyor will see this file on vessel detail page.</div>
            </div>

            <button type="submit" class="blue-action-btn mt-3" style="background: #3b32b3;">
                <i class="fa-solid fa-ship"></i> Assign & Save Vessel
            </button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $(document).ready(function() {

        // ---- Single searchable select (Client / Port) ----
        function initSearchableSelect(rootEl) {
            const $root = $(rootEl);
            const $trigger = $root.find('.ss-trigger');
            const $triggerText = $root.find('.ss-trigger-text');
            const $panel = $root.find('.ss-panel');
            const $search = $root.find('.ss-search-input');
            const $optionsList = $root.find('.ss-options');
            const $hiddenSelect = $root.nextAll('select.ss-hidden-select').first();

            function closePanel() {
                $root.removeClass('open');
                if (!$('.searchable-select.open').length) {
                    $('.scroll-content').css('overflow-y', '');
                }
            }
            function openPanel() {
                $('.searchable-select.open').not($root).removeClass('open');
                $root.addClass('open');
                // Prevent scroll-content from clipping the dropdown panel
                $('.scroll-content').css('overflow-y', 'visible');
                $search.val('').trigger('input');
                setTimeout(function() { try { $search[0].focus(); } catch (e) {} }, 50);
            }

            $trigger.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if ($root.hasClass('open')) { closePanel(); } else { openPanel(); }
            });

            $panel.on('click', function(e) { e.stopPropagation(); });
            $search.on('click', function(e) { e.stopPropagation(); });

            $search.on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const $firstMatch = $optionsList.find('.ss-option:visible').not('.ss-option-other').first();
                    if ($firstMatch.length) { selectOption($firstMatch); }
                } else if (e.key === 'Escape') {
                    closePanel();
                }
            });

            $search.on('input', function() {
                const term = String($(this).val() || '').toLowerCase().trim();
                let anyVisible = false;
                $optionsList.find('.ss-option').each(function() {
                    const $opt = $(this);
                    if ($opt.hasClass('ss-option-other')) { $opt.show(); return; }
                    const name = String($opt.data('name') || '').toLowerCase();
                    const match = !term || name.indexOf(term) !== -1;
                    $opt.toggle(match);
                    if (match) anyVisible = true;
                });
                $optionsList.find('.ss-option-empty').remove();
                if (!anyVisible) {
                    $optionsList.prepend('<li class="ss-option-empty">No matches found</li>');
                }
            });

            function selectOption($opt) {
                const value = $opt.data('value');
                const label = $.trim($opt.text());
                $hiddenSelect.val(String(value)).trigger('change');
                $optionsList.find('.ss-option').removeClass('ss-selected');
                // Mark selected unless it is a pure "+ Other" add-new action
                const isAddOther = $opt.hasClass('ss-option-other') && String(value).indexOf('other_') === 0;
                if (!isAddOther) {
                    $opt.addClass('ss-selected');
                    $triggerText.text(label || ($triggerText.attr('data-placeholder') || 'Select')).toggleClass('placeholder', !label);
                } else {
                    $triggerText.text(label).removeClass('placeholder');
                }
                if (!isAddOther && label) {
                    $triggerText.text(label).removeClass('placeholder');
                }
                closePanel();
            }

            $optionsList.on('click', '.ss-option', function(e) {
                e.preventDefault();
                e.stopPropagation();
                selectOption($(this));
            });

            $root.data('ssApi', {
                addAndSelect: function(id, name) {
                    $optionsList.find('.ss-option[data-value="' + id + '"]').remove();
                    $hiddenSelect.find('option[value="' + id + '"]').remove();
                    const $li = $('<li class="ss-option"></li>').attr('data-value', id).attr('data-name', String(name).toLowerCase()).text(name);
                    $optionsList.find('.ss-option-other').before($li);
                    const $opt = $('<option></option>').attr('value', id).text(name);
                    $hiddenSelect.append($opt);
                    selectOption($li);
                },
                reset: function() {
                    $hiddenSelect.val('').trigger('change');
                    $optionsList.find('.ss-option').removeClass('ss-selected');
                    const ph = $triggerText.attr('data-placeholder') || $triggerText.data('placeholder') || 'Select';
                    $triggerText.text(ph).addClass('placeholder');
                }
            });

            // Keep explicit placeholder; never overwrite with empty text
            const phAttr = $triggerText.attr('data-placeholder') || $triggerText.text() || 'Select';
            $triggerText.attr('data-placeholder', phAttr);
            $triggerText.data('placeholder', phAttr);
            if (!$hiddenSelect.val()) {
                $triggerText.text(phAttr).addClass('placeholder');
            }
        }

        // ---- Multi searchable select (Survey Type) ----
        function initSearchableMultiSelect(rootEl) {
            const $root = $(rootEl);
            const $trigger = $root.find('.ss-trigger');
            const $triggerText = $root.find('.ss-trigger-text');
            const $panel = $root.find('.ss-panel');
            const $search = $root.find('.ss-search-input');
            const $optionsList = $root.find('.ss-options');
            const $hiddenInput = $('#surveyTypeIdsInput');

            function closePanel() {
                $root.removeClass('open');
                if (!$('.searchable-select.open').length) {
                    $('.scroll-content').css('overflow-y', '');
                }
            }
            function openPanel() {
                $('.searchable-select.open').not($root).removeClass('open');
                $root.addClass('open');
                // Prevent scroll-content from clipping the dropdown panel
                $('.scroll-content').css('overflow-y', 'visible');
                $search.val('').trigger('input');
                setTimeout(function() { try { $search[0].focus(); } catch (e) {} }, 50);
            }

            $trigger.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if ($root.hasClass('open')) { closePanel(); } else { openPanel(); }
            });

            $panel.on('click', function(e) { e.stopPropagation(); });
            $search.on('click', function(e) { e.stopPropagation(); });

            $search.on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const $firstMatch = $optionsList.find('.ss-option:visible').not('.ss-option-other').first();
                    if ($firstMatch.length) { toggleOption($firstMatch); }
                } else if (e.key === 'Escape') {
                    closePanel();
                }
            });

            $search.on('input', function() {
                const term = String($(this).val() || '').toLowerCase().trim();
                let anyVisible = false;
                $optionsList.find('.ss-option').each(function() {
                    const $opt = $(this);
                    if ($opt.hasClass('ss-option-other')) { $opt.show(); return; }
                    const name = String($opt.data('name') || '').toLowerCase();
                    const match = !term || name.indexOf(term) !== -1;
                    $opt.toggle(match);
                    if (match) anyVisible = true;
                });
                $optionsList.find('.ss-option-empty').remove();
                if (!anyVisible) {
                    $optionsList.prepend('<li class="ss-option-empty">No matches found</li>');
                }
            });

            function syncTriggerAndHidden() {
                const $selected = $optionsList.find('.ss-option.ss-selected').not('.ss-option-other');
                const ids = [];
                const names = [];
                $selected.each(function() {
                    ids.push(String($(this).data('value')));
                    names.push($.trim($(this).find('.ss-option-label').text()) || $.trim($(this).text()));
                });
                $hiddenInput.val(ids.join(','));
                if (names.length) {
                    $triggerText.text(names.join(' + ')).removeClass('placeholder');
                } else {
                    $triggerText.text($triggerText.data('placeholder') || 'Select Survey Type').addClass('placeholder');
                }
            }

            function toggleOption($opt) {
                if ($opt.hasClass('ss-option-other') || $opt.hasClass('ss-option-empty')) return;
                const nowSelected = !$opt.hasClass('ss-selected');
                $opt.toggleClass('ss-selected', nowSelected);
                $opt.find('.ss-option-checkbox').prop('checked', nowSelected);
                syncTriggerAndHidden();
            }

            $optionsList.on('click', '.ss-option', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const $opt = $(this);
                if ($opt.hasClass('ss-option-empty')) return;
                if ($opt.hasClass('ss-option-other')) {
                    closePanel();
                    $('#otherSurveyTypeContainer').show();
                    try { $('#newSurveyTypeInput')[0].focus(); } catch (err) {}
                    return;
                }
                toggleOption($opt);
            });

            $root.data('ssApi', {
                addAndSelect: function(id, name) {
                    $optionsList.find('.ss-option[data-value="' + id + '"]').remove();
                    const $li = $('<li class="ss-option"></li>').attr('data-value', id).attr('data-name', String(name).toLowerCase());
                    $li.append('<input type="checkbox" class="ss-option-checkbox" tabindex="-1">');
                    $li.append($('<span class="ss-option-label"></span>').text(name));
                    $optionsList.find('.ss-option-other').before($li);
                    $li.addClass('ss-selected');
                    $li.find('.ss-option-checkbox').prop('checked', true);
                    syncTriggerAndHidden();
                }
            });

            $triggerText.data('placeholder', $triggerText.text());
            // Ensure hidden starts empty
            if (!$hiddenInput.val()) { $hiddenInput.val(''); }
            syncTriggerAndHidden();
        }

        // Init all searchable selects
        $('.searchable-select').each(function() {
            if ($(this).hasClass('ss-multi')) {
                initSearchableMultiSelect(this);
            } else {
                initSearchableSelect(this);
            }
        });

        // Force visible "Select …" labels if still empty (prevents blank gray triggers)
        $('.searchable-select').each(function() {
            var $root = $(this);
            var $txt = $root.find('.ss-trigger-text');
            var $hid = $root.nextAll('select.ss-hidden-select').first();
            var ph = $txt.attr('data-placeholder') || $txt.data('placeholder') || 'Select';
            var empty = true;
            if ($root.hasClass('ss-multi')) {
                empty = !$.trim($('#surveyTypeIdsInput').val() || '');
            } else if ($hid.length) {
                empty = !$hid.val();
            }
            if (empty) {
                $txt.text(ph).addClass('placeholder').css({color:'#64748b', fontWeight:'500', fontSize:'14px', opacity:1, visibility:'visible'});
            }
        });


        // Report number appears only after a real client is selected
        function refreshReportNumber(clientId) {
            var $grp = $('#reportNumberGroup');
            var $inp = $('#reportNumberPreview');
            if (!clientId || clientId === 'other_client' || clientId === '0') {
                $grp.hide();
                $inp.val('');
                return;
            }
            $inp.val('Loading…');
            $grp.show();
            $.getJSON('ajax/preview_report_number.php', { client_id: clientId })
                .done(function(res) {
                    if (res && res.success && res.report_number) {
                        $inp.val(res.report_number);
                    } else {
                        $inp.val('');
                        $grp.hide();
                    }
                })
                .fail(function() {
                    $inp.val('');
                    $grp.hide();
                });
        }
        $('#clientSelect').on('change', function() {
            refreshReportNumber($(this).val());
        });

        // Outside click closes open panels
        $(document).on('click', function(e) {
            if ($(e.target).closest('.searchable-select').length) return;
            $('.searchable-select.open').removeClass('open');
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') { $('.searchable-select.open').removeClass('open'); }
        });

        // ---- "+ Other" handlers ----
        function wireOtherOption(config) {
            const $hiddenSelect = config.selectSel ? $(config.selectSel) : $();
            const $ssRoot = $('[data-ss-root="' + config.ssKey + '"]');
            const $container = $(config.containerSel);
            const $input = $(config.inputSel);
            const $saveBtn = $(config.saveBtnSel);
            const $status = $(config.statusSel);

            if ($hiddenSelect.length) {
                $hiddenSelect.on('change', function() {
                    if ($(this).val() === config.otherValue) {
                        $container.show();
                        try { $input[0].focus(); } catch (e) {}
                    } else {
                        $container.hide();
                        $status.text('');
                    }
                });
            }

            $saveBtn.on('click', function() {
                const newName = String($input.val() || '').trim();
                if (!newName) {
                    $status.removeClass('text-success').addClass('text-danger').text('Please enter a name first.');
                    return;
                }
                $saveBtn.prop('disabled', true).text('Saving...');
                $status.removeClass('text-danger text-success').text('');

                $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: config.postData(newName),
                    dataType: 'json'
                }).done(function(res) {
                    if (res && res.success) {
                        const api = $ssRoot.data('ssApi');
                        if (api && typeof api.addAndSelect === 'function') {
                            api.addAndSelect(res.id, res.name || newName);
                        }
                        $container.hide();
                        $input.val('');
                        $status.removeClass('text-danger').addClass('text-success').text(res.existed ? 'Selected existing entry.' : 'Saved and selected.');
                    } else {
                        $status.removeClass('text-success').addClass('text-danger').text((res && res.message) ? res.message : 'Could not save. Please try again.');
                    }
                }).fail(function() {
                    $status.removeClass('text-success').addClass('text-danger').text('Network error. Please try again.');
                }).always(function() {
                    $saveBtn.prop('disabled', false).text('Save');
                });
            });
        }

        wireOtherOption({
            ssKey: 'client',
            selectSel: '#clientSelect',
            containerSel: '#otherClientContainer',
            inputSel: '#newClientNameInput',
            saveBtnSel: '#saveNewClientBtn',
            statusSel: '#newClientStatus',
            otherValue: 'other_client',
            ajaxUrl: 'ajax/add_client.php',
            postData: function(name) { return { company_name: name }; }
        });

        wireOtherOption({
            ssKey: 'port',
            selectSel: '#portSelect',
            containerSel: '#otherPortContainer',
            inputSel: '#newPortNameInput',
            saveBtnSel: '#saveNewPortBtn',
            statusSel: '#newPortStatus',
            otherValue: 'other_port',
            ajaxUrl: 'ajax/add_port.php',
            postData: function(name) { return { port_name: name }; }
        });

        wireOtherOption({
            ssKey: 'surveyType',
            selectSel: null,
            containerSel: '#otherSurveyTypeContainer',
            inputSel: '#newSurveyTypeInput',
            saveBtnSel: '#saveNewSurveyTypeBtn',
            statusSel: '#newSurveyTypeStatus',
            otherValue: 'other_survey_type',
            ajaxUrl: 'ajax/add_survey_type.php',
            postData: function(name) { return { type_name: name }; }
        });

        // ---- Form submit validation ----
        $('#assignVesselForm').on('submit', function(e) {
            const clientVal = String($('#clientSelect').val() || '');
            const portVal = String($('#portSelect').val() || '');
            const typeIdsVal = String($('#surveyTypeIdsInput').val() || '').trim();
            const vesselVal = String($('input[name="vessel_name"]').val() || '').trim();
            const agentVal = String($('input[name="agent_name"]').val() || '').trim();
            const surveyorVal = String($('select[name="surveyor_id"]').val() || '');

            const messages = [];

            if (!vesselVal) messages.push('Vessel Name is required.');
            if (!agentVal) messages.push('Agent Name is required.');

            if (clientVal === 'other_client') {
                $('#newClientStatus').removeClass('text-success').addClass('text-danger').text('Please save the new client before submitting.');
                $('#otherClientContainer').show();
                messages.push('Please save the new client first.');
            } else if (!clientVal || clientVal === '0') {
                messages.push('Please select a Client.');
            }

            if (portVal === 'other_port') {
                $('#newPortStatus').removeClass('text-success').addClass('text-danger').text('Please save the new port before submitting.');
                $('#otherPortContainer').show();
                messages.push('Please save the new port first.');
            } else if (!portVal || portVal === '0') {
                messages.push('Please select a Port.');
            }

            if (!typeIdsVal) {
                messages.push('Please select at least one Survey Type.');
            }

            if (!surveyorVal) {
                messages.push('Please select a Surveyor.');
            }

            if (messages.length) {
                e.preventDefault();
                alert(messages.join('\n'));
                return false;
            }

            const $btn = $(this).find('button[type="submit"]');
            $btn.prop('disabled', true).css('opacity', '0.7');
            setTimeout(function() { $btn.prop('disabled', false).css('opacity', '1'); }, 10000);
            return true;
        });
    });
</script>

<?php 
include 'includes/nav.php';
include 'includes/footer.php';
?>
