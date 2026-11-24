<?php
require_once 'config/config.php';
require_once 'includes/mailer.php';
require_once 'includes/report_number.php';
require_once 'includes/notifications.php';
checkAuth();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Client', 'Sales'], true)) {
    header('Location: index.php');
    exit;
}
$is_client_role = (($_SESSION['role'] ?? '') === 'Client');
// 🌟 Sales: requests a survey for any client (all clients listed); like a client's own
// request, no surveyor is chosen here — the Admin assigns one (includes/sales.php).
$is_sales_role = (($_SESSION['role'] ?? '') === 'Sales');
$is_requester = $is_client_role || $is_sales_role;
// 🌟 Completed Vessel Entry (Admin): this same form, with the survey dates, recovery and report
// files, saves an already-completed survey straight into Completed Vessels
// (includes/completed_entry.php). Opened from Admin Controls → Completed Surveys → "Add Completed Vessel".
$completed_mode = (($_SESSION['role'] ?? '') === 'Admin') && (($_GET['completed'] ?? $_POST['completed_entry'] ?? '') === '1') && empty($_GET['edit_id']);
if ($completed_mode) require_once __DIR__ . '/includes/completed_entry.php';

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

// 🌟 SAFETY NET: appointment_date column (database/migration_appointment_date.sql)
try {
    $ad_exists = $db->query("SHOW COLUMNS FROM surveys LIKE 'appointment_date'")->fetchAll();
    if (empty($ad_exists)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN appointment_date DATE NULL DEFAULT NULL AFTER assign_date");
    }
} catch (Exception $e) {
    error_log('assign_vessel.php appointment_date column check/add error: ' . $e->getMessage());
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

// 🌟 SAFETY NET: client_submitted flag — lets Pending Vessels show Admin a
// "Client assigned this — pick a surveyor" reminder for vessels a Client
// submitted directly (surveyor_id stays the unassigned placeholder until
// Admin picks a real one).
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'client_submitted'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN client_submitted TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {
    error_log('assign_vessel.php client_submitted column: ' . $e->getMessage());
}





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

// 1. పోర్ట్స్ లిస్ట్ తెచ్చుకోవడం (country-wise, for the Country → Port cascading dropdown)
try {
    $col_exists = $db->query("SHOW COLUMNS FROM ports LIKE 'country'")->fetchAll();
    if (empty($col_exists)) {
        $db->exec("ALTER TABLE ports ADD COLUMN country VARCHAR(100) DEFAULT 'India' AFTER port_name");
    }
    $db->exec("UPDATE ports SET country = 'India' WHERE country IS NULL OR country = ''");

    // 🌟 One-time world-ports seed (see database/migration_world_ports.sql) so the
    // Country → Port dropdown has real options even if the SQL file was never run manually.
    $port_count = (int)$db->query("SELECT COUNT(*) FROM ports")->fetchColumn();
    if ($port_count < 15) {
        try { $db->exec("ALTER TABLE ports ADD UNIQUE KEY uniq_port_name (port_name)"); } catch (Throwable $ke) {}
        $world_ports = [
            ['Chennai Port','India'],['Mumbai (Nhava Sheva/JNPT)','India'],['Kolkata Port','India'],['Kandla Port','India'],['Cochin Port','India'],
            ['Paradip Port','India'],['Mundra Port','India'],['Tuticorin Port','India'],['Ennore Port','India'],['New Mangalore Port','India'],
            ['Shanghai Port','China'],['Shenzhen Port','China'],['Ningbo-Zhoushan Port','China'],['Qingdao Port','China'],['Guangzhou Port','China'],['Tianjin Port','China'],['Hong Kong Port','China'],
            ['Port of Singapore','Singapore'],
            ['Jebel Ali Port','United Arab Emirates'],['Port of Fujairah','United Arab Emirates'],['Khalifa Port','United Arab Emirates'],
            ['Jeddah Islamic Port','Saudi Arabia'],['King Abdulaziz Port (Dammam)','Saudi Arabia'],['Jubail Commercial Port','Saudi Arabia'],
            ['Port Klang','Malaysia'],['Tanjung Pelepas Port','Malaysia'],['Penang Port','Malaysia'],
            ['Tanjung Priok Port (Jakarta)','Indonesia'],['Tanjung Perak Port (Surabaya)','Indonesia'],['Belawan Port','Indonesia'],
            ['Busan Port','South Korea'],['Incheon Port','South Korea'],
            ['Port of Tokyo','Japan'],['Port of Yokohama','Japan'],['Port of Nagoya','Japan'],['Port of Kobe','Japan'],
            ['Colombo Port','Sri Lanka'],['Hambantota Port','Sri Lanka'],
            ['Chattogram Port','Bangladesh'],['Mongla Port','Bangladesh'],
            ['Karachi Port','Pakistan'],['Port Qasim','Pakistan'],['Gwadar Port','Pakistan'],
            ['Laem Chabang Port','Thailand'],['Bangkok Port','Thailand'],
            ['Cai Mep Port','Vietnam'],['Ho Chi Minh City Port','Vietnam'],['Hai Phong Port','Vietnam'],
            ['Manila Port','Philippines'],['Cebu Port','Philippines'],
            ['Port of Salalah','Oman'],['Port Sultan Qaboos','Oman'],
            ['Hamad Port','Qatar'],
            ['Shuwaikh Port','Kuwait'],['Shuaiba Port','Kuwait'],
            ['Khalifa Bin Salman Port','Bahrain'],
            ['Bandar Abbas Port','Iran'],['Bandar Imam Khomeini Port','Iran'],
            ['Port Said','Egypt'],['Alexandria Port','Egypt'],['Damietta Port','Egypt'],
            ['Port of Durban','South Africa'],['Port of Cape Town','South Africa'],['Port of Ngqura','South Africa'],
            ['Lagos Port (Apapa)','Nigeria'],['Tin Can Island Port','Nigeria'],
            ['Mombasa Port','Kenya'],
            ['Port of Rotterdam','Netherlands'],['Port of Amsterdam','Netherlands'],
            ['Port of Antwerp','Belgium'],['Port of Zeebrugge','Belgium'],
            ['Port of Hamburg','Germany'],['Port of Bremerhaven','Germany'],
            ['Port of Felixstowe','United Kingdom'],['Port of London','United Kingdom'],['Port of Southampton','United Kingdom'],
            ['Port of Marseille','France'],['Port of Le Havre','France'],
            ['Port of Valencia','Spain'],['Port of Algeciras','Spain'],['Port of Barcelona','Spain'],
            ['Port of Genoa','Italy'],['Port of Gioia Tauro','Italy'],['Port of Trieste','Italy'],
            ['Port of Piraeus','Greece'],
            ['Port of Ambarli','Turkey'],['Port of Mersin','Turkey'],
            ['Port of St. Petersburg','Russia'],['Port of Novorossiysk','Russia'],['Port of Vladivostok','Russia'],
            ['Port of Los Angeles','United States'],['Port of Long Beach','United States'],['Port of New York and New Jersey','United States'],['Port of Savannah','United States'],['Port of Houston','United States'],['Port of Charleston','United States'],
            ['Port of Vancouver','Canada'],['Port of Montreal','Canada'],
            ['Port of Manzanillo','Mexico'],['Port of Veracruz','Mexico'],
            ['Port of Santos','Brazil'],['Port of Rio de Janeiro','Brazil'],['Port of Paranagua','Brazil'],
            ['Port of Balboa','Panama'],['Port of Colon','Panama'],
            ['Port of Valparaiso','Chile'],['Port of San Antonio','Chile'],
            ['Port of Buenos Aires','Argentina'],
            ['Port of Melbourne','Australia'],['Port of Sydney','Australia'],['Port of Brisbane','Australia'],['Port of Fremantle','Australia'],
            ['Port of Auckland','New Zealand'],['Port of Tauranga','New Zealand'],
        ];
        $insP = $db->prepare("INSERT IGNORE INTO ports (port_name, country) VALUES (?, ?)");
        foreach ($world_ports as $wp) { $insP->execute($wp); }
    }
} catch (Exception $e) {
    error_log('assign_vessel.php ports.country column check/add error: ' . $e->getMessage());
}
ensurePortAnchorages($db);
$ports = $db->query("SELECT * FROM ports ORDER BY country ASC, port_name ASC")->fetchAll();
$port_countries = array_values(array_unique(array_map(function ($p) { return $p['country'] ?: 'India'; }, $ports)));
sort($port_countries);

// 2. క్లయింట్స్ లిస్ట్ తెచ్చుకోవడం
try {
    $clients = $db->query("SELECT id, company_name, short_code FROM clients ORDER BY company_name ASC")->fetchAll();
} catch (Throwable $e) {
    // short_code column may not exist yet on first load
    $clients = $db->query("SELECT id, company_name FROM clients ORDER BY company_name ASC")->fetchAll();
}

// 🌟 Client role: the form must show (and lock) only their own company — never
// the full client list, which they should not even see. Looked up here (not
// just inside the POST handler below) so the GET-rendered form can pre-select it.
$own_client_id = 0;
$own_client_name = '';
if ($is_client_role) {
    try {
        $ownStmt = $db->prepare("SELECT id, company_name FROM clients WHERE user_id = ? LIMIT 1");
        $ownStmt->execute([$_SESSION['user_id']]);
        $ownRow = $ownStmt->fetch(PDO::FETCH_ASSOC);
        if ($ownRow) {
            $own_client_id = (int)$ownRow['id'];
            $own_client_name = (string)$ownRow['company_name'];
        }
    } catch (Throwable $e) { error_log('assign_vessel own client lookup: ' . $e->getMessage()); }
}

// 3. సర్వేయర్స్ లిస్ట్ తెచ్చుకోవడం (role_id = 2)
$surveyors = $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND status = 'Active'")->fetchAll();

// 🌟 SAFETY NET: survey_surveyors junction table (multi-surveyor assignment)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_surveyors` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `survey_id` int(11) NOT NULL,
        `surveyor_id` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_survey_surveyor` (`survey_id`, `surveyor_id`),
        KEY `idx_survey_surveyors_surveyor` (`surveyor_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log('assign_vessel.php survey_surveyors table check/create error: ' . $e->getMessage());
}
ensureSurveyAttachmentsTable($db);

// 4. సర్వే టైప్స్ తెచ్చుకోవడం
$survey_types = $db->query("SELECT * FROM survey_types")->fetchAll();

// 🌟 EDIT MODE — reuse this exact same rich form (country/port cascading,
// multi survey-type, multi-surveyor, multi-file attachments) to edit an
// existing assignment, instead of the old, simpler inline form that used to
// live on vessel_detail.php. Admin only, same restriction the old edit form
// had (Client only ever creates new requests, never edits existing ones).
$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_mode = false;
$edit_survey = null;
$edit_surveyor_ids = [];
$edit_attachments = [];
if ($edit_id > 0) {
    if (($_SESSION['role'] ?? '') !== 'Admin') {
        header('Location: index.php');
        exit;
    }
    $editStmt = $db->prepare("SELECT * FROM surveys WHERE id = ?");
    $editStmt->execute([$edit_id]);
    $edit_survey = $editStmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_survey) {
        header('Location: vessels.php');
        exit;
    }
    $edit_mode = true;

    // Prefer the survey_surveyors junction table (accurate for multi-surveyor
    // assignments); fall back to the legacy single surveyor_id only when no
    // junction rows exist yet (pre-multi-surveyor assignments), and only if
    // it's a real surveyor (not the outsourcing/unassigned dummy id 1).
    try {
        $esStmt = $db->prepare("SELECT surveyor_id FROM survey_surveyors WHERE survey_id = ? ORDER BY id ASC");
        $esStmt->execute([$edit_id]);
        $edit_surveyor_ids = array_map('intval', $esStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        $edit_surveyor_ids = [];
    }
    if (empty($edit_surveyor_ids) && (int)($edit_survey['surveyor_id'] ?? 0) > 1) {
        $edit_surveyor_ids = [(int)$edit_survey['surveyor_id']];
    }

    $edit_attachments = getSurveyAttachments($db, $edit_id, $edit_survey['attachment_path'] ?? '');
}

$ce_dupes = [];
if ($completed_mode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'includes/completed_entry.php';
    $ce = completedEntryValidate($db, $_POST);
    if ($ce['errors']) {
        $error = implode('<br>', array_map('sanitize', $ce['errors']));
    } elseif ($ce['dupes'] && ($_POST['confirm_duplicate'] ?? '') !== '1') {
        $ce_dupes = $ce['dupes'];
        $error = 'Possible duplicate:<br>' . implode('<br>', array_map('sanitize', $ce['dupes'])) . '<br><b>Tick "Save anyway" below if this is a different survey.</b>';
    } else {
        $res = completedEntryInsert($db, $ce['v'], (int)$_SESSION['user_id'], (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin'));
        if (!$res['ok']) {
            $error = sanitize($res['error']);
        } else {
            $fs = ['saved' => [], 'rejected' => []];
            try { $fs = completedEntrySaveFiles($db, $res['id'], $_FILES); } catch (Throwable $e) { error_log('completed entry files: ' . $e->getMessage()); $fs['rejected'][] = 'files'; }
            error_log(sprintf('completed entry: user %d added survey %d (%s)', (int)$_SESSION['user_id'], $res['id'], $res['report_number']));
            $_SESSION['flash_msg'] = 'Completed vessel added (' . $res['report_number'] . ')' . ($fs['saved'] ? ' with ' . count($fs['saved']) . ' file(s)' : '') . '.'
                . ($fs['rejected'] ? ' Not saved (file type not allowed or upload failed): ' . implode(', ', $fs['rejected']) . '.' : '');
            $_SESSION['flash_type'] = $fs['rejected'] ? 'warning' : 'success';
            header('Location: completed_detail.php?id=' . $res['id']);
            exit;
        }
    }
}

// ఫార్మ్ సబ్మిషన్ ప్రాసెస్
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$completed_mode) {
    // 🌟 Present (and a real existing survey, and Admin) only when this
    // submission is an edit of an existing assignment rather than a brand
    // new one — see the branch at the bottom of this block.
    $post_edit_id = (int)($_POST['edit_id'] ?? 0);
    if ($post_edit_id > 0 && ($_SESSION['role'] ?? '') !== 'Admin') {
        $post_edit_id = 0; // never let a non-Admin update someone else's assignment
    }

    // 🌟 Vessel Name / Agent Name / Remarks always stored upper case, however
    // Admin/Client typed them — Vessel Name additionally always gets the
    // "MV. " prefix (normalizeVesselName), e.g. "vessel name" or "mv Vessel
    // Name" both become "MV. VESSEL NAME".
    $vessel_name = normalizeVesselName(trim($_POST['vessel_name']));
    $client_id = (int)$_POST['client_id'];
    $agent_name = mb_strtoupper(trim($_POST['agent_name']), 'UTF-8');
    $port_id = (int)$_POST['port_id'];

    // 🌟 Appointment Date — mandatory, validated as a real calendar date.
    $appointment_date_raw = trim($_POST['appointment_date'] ?? '');
    $appointment_date = '';
    if ($appointment_date_raw !== '') {
        $ad_parts = explode('-', $appointment_date_raw);
        if (count($ad_parts) === 3 && checkdate((int)$ad_parts[1], (int)$ad_parts[2], (int)$ad_parts[0])) {
            $appointment_date = $appointment_date_raw;
        }
    }

    // 🌟 బహుళ Survey Types (checkbox multi-select) — CSV గా వస్తుంది, ఉదా. "3,5,7"
    $survey_type_ids_csv = trim($_POST['survey_type_ids'] ?? '');
    $survey_type_ids_arr = array_values(array_unique(array_filter(array_map('intval', explode(',', $survey_type_ids_csv)))));
    $survey_type_ids_csv = implode(',', $survey_type_ids_arr);
    // పాత survey_type_id కాలమ్ (సింగిల్ FK) కోసం మొదటి ఎంచుకున్న టైప్‌ను ఉంచడం — మిగతా పేజీల్లో ఉన్న
    // JOIN survey_types లాజిక్ ఏమాత్రం మార్చకుండా అలాగే పని చేయడానికి
    $survey_type_id = !empty($survey_type_ids_arr) ? $survey_type_ids_arr[0] : 0;

    $surveyor_id = $_POST['surveyor_id'] ?? ''; // 'outsourcing' లేదా ID కావచ్చు (legacy single field, kept for back-compat)
    $remarks = mb_strtoupper(trim($_POST['remarks']), 'UTF-8');

    // 🌟 బహుళ Surveyors (checkbox multi-select) — CSV గా వస్తుంది, ఉదా. "3,5,7" లేదా "outsourcing"
    $surveyor_ids_csv = trim($_POST['surveyor_ids'] ?? '');
    $surveyor_ids_raw = array_values(array_filter(array_map('trim', explode(',', $surveyor_ids_csv)), function ($v) { return $v !== ''; }));
    $is_outsourcing = in_array('outsourcing', $surveyor_ids_raw, true);
    // Real (non-outsourcing) surveyor ids selected, in the order chosen
    $all_surveyor_ids = $is_outsourcing ? [] : array_values(array_unique(array_filter(array_map('intval', $surveyor_ids_raw))));
    if (!empty($all_surveyor_ids)) {
        $surveyor_id = (string)$all_surveyor_ids[0]; // primary = first selected, for backward compatibility
    } elseif ($is_outsourcing) {
        $surveyor_id = 'outsourcing';
    } elseif ($is_requester) {
        // 🌟 Clients / Sales don't pick a surveyor — leave unassigned; Admin assigns one later via Edit.
        $surveyor_id = 'unassigned';
        $remarks = $remarks !== '' ? $remarks : ($is_sales_role ? 'Requested by Sales (' . ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Sales') . ') — awaiting surveyor assignment.' : 'Submitted by client — awaiting surveyor assignment.');
    }

    // Client-submitted vessels are always tied to the client's own company
    if ($is_client_role) {
        try {
            $ccheck = $db->prepare("SELECT id FROM clients WHERE user_id = ? LIMIT 1");
            $ccheck->execute([$_SESSION['user_id']]);
            $own_client_id = (int)($ccheck->fetchColumn() ?: 0);
            if ($own_client_id > 0) { $client_id = $own_client_id; }
        } catch (Throwable $e) { error_log('assign_vessel client_id lock: ' . $e->getMessage()); }
    }

    // సర్వేయర్ ఐడి హ్యాండ్లింగ్ (Outsourcing అయితే యూజర్ టేబుల్‌లో లేని ఐడి లేదా అడ్మిన్ ఐడి సెట్ చేయవచ్చు)
    // ఇక్కడ ఔట్‌సోర్సింగ్ కోసం ఒక డమ్మీ వాల్యూ (उदा. 1 లేదా అడ్మిన్ ఐడి) లేదా ప్రత్యేక లాజిక్ ఇవ్వచ్చు.
    $final_surveyor_id = ($surveyor_id === 'outsourcing' || $surveyor_id === 'unassigned') ? 1 : (int)$surveyor_id;

    if($surveyor_id === 'outsourcing' && $remarks == '') {
        $remarks = "Outsourced Survey.";
    }

    if ($post_edit_id > 0) {
        // 🌟 EDIT MODE — update the existing survey row in place instead of
        // creating a new one. Report number, assign date and status are
        // deliberately left untouched (this is a correction tool, not a
        // re-assignment); everything else the create form can set is
        // editable here too. No surveyor email/WhatsApp/in-app notification
        // is sent on an ordinary edit — those already fired when the vessel
        // was first assigned, and re-notifying on every correction (e.g. a
        // typo fix in remarks) would be noisy and unexpected. The ONE
        // exception: a Client-submitted vessel that was still unassigned
        // (surveyor_id = 1, no real surveyor) is now getting a real
        // surveyor for the first time — that surveyor must be notified,
        // since nothing notified them when the vessel was first created.
        if (!empty($vessel_name) && $client_id > 0 && !empty($agent_name) && $port_id > 0 && !empty($survey_type_ids_arr) && !empty($surveyor_id) && $appointment_date !== '') {
            try {
                $upd = $db->prepare("
                    UPDATE surveys SET
                        vessel_name = ?, client_id = ?, agent_name = ?, surveyor_id = ?,
                        survey_type_id = ?, survey_type_ids = ?, port_id = ?, remarks = ?, appointment_date = ?
                    WHERE id = ?
                ");
                $upd->execute([$vessel_name, $client_id, $agent_name, $final_surveyor_id, $survey_type_id, $survey_type_ids_csv, $port_id, $remarks, $appointment_date, $post_edit_id]);

                // Replace the surveyor assignment set entirely with whatever
                // is selected now (full replace, matching "save this form's
                // complete state" semantics for an edit form).
                try {
                    $db->prepare("DELETE FROM survey_surveyors WHERE survey_id = ?")->execute([$post_edit_id]);
                    if (!empty($all_surveyor_ids)) {
                        $ssStmt = $db->prepare("INSERT IGNORE INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)");
                        foreach ($all_surveyor_ids as $sid_each) {
                            if ($sid_each > 0) $ssStmt->execute([$post_edit_id, $sid_each]);
                        }
                    }
                } catch (Throwable $sse) {
                    error_log('assign_vessel.php edit survey_surveyors sync: ' . $sse->getMessage());
                }

                // 🌟 First-time surveyor assignment on a previously-unassigned
                // Client submission (surveyor_id was 1, no real surveyor in the
                // junction table) — notify the newly assigned surveyor(s) now,
                // exactly like the create-path does. Any other edit stays silent.
                $wasUnassigned = (int)($edit_survey['surveyor_id'] ?? 0) === 1 && empty($edit_surveyor_ids);
                if ($wasUnassigned && !empty($all_surveyor_ids)) {
                    foreach ($all_surveyor_ids as $notify_sid) {
                        if ($notify_sid <= 0) continue;
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
                            $app_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/vessel_detail.php?id=' . $post_edit_id;
                            $admin_name = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');
                            $admin_email = '';
                            try {
                                $uid = (int)($_SESSION['user_id'] ?? 0);
                                if ($uid > 0) {
                                    $ast = $db->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
                                    $ast->execute([$uid]);
                                    $arow = $ast->fetch(PDO::FETCH_ASSOC);
                                    if ($arow) {
                                        if (!empty($arow['full_name'])) { $admin_name = (string)$arow['full_name']; }
                                        $admin_email = trim((string)($arow['email'] ?? ''));
                                    }
                                }
                            } catch (Throwable $ae) {
                                error_log('assign_vessel edit admin email: ' . $ae->getMessage());
                            }
                            $job = [
                                'vessel_name'       => $vessel_name,
                                'report_number'     => $edit_survey['report_number'] ?? '',
                                'client_name'       => $client_name,
                                'port_name'         => $port_name,
                                'survey_types'      => $type_names,
                                'agent_name'        => $agent_name,
                                'assign_date'       => date('d-m-Y'),
                                'remarks'           => $remarks,
                                'app_url'           => $app_url,
                                'assigned_by_name'  => $admin_name,
                                'assigned_by_email' => $admin_email,
                            ];
                            notifySurveyorOfAssignment($db, $notify_sid, $job);
                            $actor = (string)($_SESSION['full_name'] ?? 'Admin');
                            createNotification(
                                $db,
                                (int)$notify_sid,
                                'New vessel assigned',
                                $actor . ' assigned ' . $vessel_name . ' (' . ($edit_survey['report_number'] ?? '') . ') to you.',
                                'assign',
                                'vessel_detail.php?id=' . $post_edit_id,
                                (int)($_SESSION['user_id'] ?? 0)
                            );
                        } catch (Throwable $ne) {
                            error_log('assign_vessel edit first-assignment notify: ' . $ne->getMessage());
                        }
                    }
                }

                // 🌟 Any newly selected files are ADDED to the existing
                // attachments (same multi-file handling as create) — never
                // replaces what was already uploaded.
                if (!empty($_FILES['assignment_attachment']['name'])) {
                    $rawNames = (array)$_FILES['assignment_attachment']['name'];
                    $rawTmp   = (array)$_FILES['assignment_attachment']['tmp_name'];
                    $rawError = (array)$_FILES['assignment_attachment']['error'];
                    $rawSize  = (array)$_FILES['assignment_attachment']['size'];

                    $attDir = __DIR__ . '/uploads/assignments/';
                    if (!is_dir($attDir)) { @mkdir($attDir, 0755, true); }

                    $insAtt = $db->prepare('INSERT INTO survey_attachments (survey_id, file_name, file_path, file_size) VALUES (?, ?, ?, ?)');
                    $firstNewRel = null;
                    foreach ($rawNames as $i => $origName) {
                        if ($origName === '' || ($rawError[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                        $tmpName = $rawTmp[$i] ?? '';
                        if (!is_uploaded_file($tmpName)) continue;
                        $orig = basename($origName);
                        $safe = time() . '_' . $i . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                        $dest = $attDir . $safe;
                        if (!@move_uploaded_file($tmpName, $dest)) continue;
                        $rel = 'uploads/assignments/' . $safe;
                        if ($firstNewRel === null) $firstNewRel = $rel;
                        try {
                            $insAtt->execute([$post_edit_id, $orig, $rel, (int)($rawSize[$i] ?? 0)]);
                        } catch (Throwable $ue) {
                            error_log('assign_vessel.php edit attachment save: ' . $ue->getMessage());
                        }
                    }
                    // Only back-fill the legacy single-file column if it was empty before.
                    if ($firstNewRel !== null && empty($edit_survey['attachment_path'])) {
                        try {
                            $db->prepare('UPDATE surveys SET attachment_path = ? WHERE id = ?')->execute([$firstNewRel, $post_edit_id]);
                        } catch (Throwable $ue) {
                            error_log('assign_vessel.php edit attachment_path save: ' . $ue->getMessage());
                        }
                    }
                }

                $_SESSION['flash_msg'] = 'Vessel assignment updated successfully.';
                $redirect_page = (($edit_survey['status'] ?? '') === 'Completed') ? 'completed_detail.php' : 'vessel_detail.php';
                header('Location: ' . $redirect_page . '?id=' . $post_edit_id);
                exit;
            } catch (Throwable $e) {
                error_log('assign_vessel.php edit update error: ' . $e->getMessage());
                $error = 'Could not save the changes. Please check the details and try again.';
            }
        } else {
            $error = "Please fill all required fields.";
        }
    } elseif (!empty($vessel_name) && $client_id > 0 && !empty($agent_name) && $port_id > 0 && !empty($survey_type_ids_arr) && ($is_requester || !empty($surveyor_id)) && $appointment_date !== '') {
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
                    (vessel_name, report_number, client_id, agent_name, surveyor_id, survey_type_id, survey_type_ids, port_id, assign_date, appointment_date, status, remarks, client_submitted)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'Pending Vessel', ?, ?)
                ");

                if ($stmt->execute([$vessel_name, $report_number, $client_id, $agent_name, $final_surveyor_id, $survey_type_id, $survey_type_ids_csv, $port_id, $appointment_date, $remarks, $is_requester ? 1 : 0])) {
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
                    // the vessel's picture for the cards: looked up by the AI when the list is next shown (includes/vessel_image.php)
                    try { require_once __DIR__ . '/includes/vessel_image.php'; viQueue($db, (string)$vessel_name); } catch (Throwable $vie) { error_log('vessel picture queue: ' . $vie->getMessage()); }
                    
                    // 🌟 Optional assignment attachment(s) — the file input allows
                    // selecting multiple files at once (assignment_attachment[]), so
                    // $_FILES['assignment_attachment'] arrives as PHP's classic
                    // multi-file array (each key holding a list of values, one per
                    // file) rather than a single flat file. Normalize that into one
                    // array per file and save every one — none get skipped or
                    // overwritten, each gets its own uploads/assignments/ file and
                    // its own survey_attachments row.
                    if ($new_survey_id > 0 && !empty($_FILES['assignment_attachment']['name'])) {
                        $rawNames = (array)$_FILES['assignment_attachment']['name'];
                        $rawTmp   = (array)$_FILES['assignment_attachment']['tmp_name'];
                        $rawError = (array)$_FILES['assignment_attachment']['error'];
                        $rawSize  = (array)$_FILES['assignment_attachment']['size'];

                        $attDir = __DIR__ . '/uploads/assignments/';
                        if (!is_dir($attDir)) { @mkdir($attDir, 0755, true); }

                        $insAtt = $db->prepare('INSERT INTO survey_attachments (survey_id, file_name, file_path, file_size) VALUES (?, ?, ?, ?)');
                        $firstRel = null;

                        foreach ($rawNames as $i => $origName) {
                            if ($origName === '' || ($rawError[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                            $tmpName = $rawTmp[$i] ?? '';
                            if (!is_uploaded_file($tmpName)) continue;

                            $orig = basename($origName);
                            $safe = time() . '_' . $i . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                            $dest = $attDir . $safe;
                            if (!@move_uploaded_file($tmpName, $dest)) continue;

                            $rel = 'uploads/assignments/' . $safe;
                            if ($firstRel === null) $firstRel = $rel;
                            try {
                                $insAtt->execute([$new_survey_id, $orig, $rel, (int)($rawSize[$i] ?? 0)]);
                            } catch (Throwable $ue) {
                                error_log('assign attachment save: ' . $ue->getMessage());
                            }
                        }

                        // Keep the legacy single-file column pointed at the first
                        // upload too, so any older code still reading it directly
                        // keeps working exactly as before.
                        if ($firstRel !== null) {
                            try {
                                $db->prepare('UPDATE surveys SET attachment_path = ? WHERE id = ?')->execute([$firstRel, $new_survey_id]);
                            } catch (Throwable $ue) {
                                error_log('assign attachment_path save: ' . $ue->getMessage());
                            }
                        }
                    }

                    $inserted = true;
                    // Refresh preview for next assign on same page
                    $preview_report_number = ''; // next number only after client select

                    // 🌟 Record every selected surveyor (multi-assign) in the junction table
                    if ($new_survey_id > 0 && !empty($all_surveyor_ids)) {
                        try {
                            $ssStmt = $db->prepare("INSERT IGNORE INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)");
                            foreach ($all_surveyor_ids as $sid_each) {
                                if ($sid_each > 0) $ssStmt->execute([$new_survey_id, $sid_each]);
                            }
                        } catch (Throwable $sse) {
                            error_log('survey_surveyors insert: ' . $sse->getMessage());
                        }
                    }

                    // 🌟 Client submitted a vessel with no surveyor yet — alert admins
                    // to assign one, both in-app (banner) and by email, immediately.
                    if ($is_sales_role && $new_survey_id > 0) { // the Sales dashboard lists the requests they made
                        try {
                            require_once __DIR__ . '/includes/sales.php';
                            salesEnsure($db);
                            $db->prepare('INSERT IGNORE INTO sales_requests (survey_id, user_id) VALUES (?, ?)')->execute([$new_survey_id, (int)($_SESSION['user_id'] ?? 0)]);
                        } catch (Throwable $sre) { error_log('sales request log: ' . $sre->getMessage()); }
                    }
                    if ($is_requester && $new_survey_id > 0) {
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
                        $client_vessel_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/vessel_detail.php?id=' . $new_survey_id;
                        try {
                            notifyAllAdmins($db, $is_sales_role ? 'New survey request from Sales — needs a surveyor' : 'New vessel from client — needs a surveyor',
                                $vessel_name . ' (' . $report_number . ') was ' . ($is_sales_role ? 'requested by Sales (' . ($_SESSION['full_name'] ?? 'Sales') . ')' : 'submitted by a client') . ' and is waiting for a surveyor to be assigned.',
                                'assign', 'vessel_detail.php?id=' . (int)$new_survey_id, (int)($_SESSION['user_id'] ?? 0));
                        } catch (Throwable $cne) { error_log('client assign notif: ' . $cne->getMessage()); }
                        try {
                            $cst2 = $db->prepare('SELECT company_name FROM clients WHERE id = ?');
                            $cst2->execute([$client_id]);
                            $pst2 = $db->prepare('SELECT port_name FROM ports WHERE id = ?');
                            $pst2->execute([$port_id]);
                            notifyAdminsOfClientVesselRequest($db, [
                                'vessel_name'   => $vessel_name,
                                'report_number' => $report_number,
                                'client_name'   => (string)($cst2->fetchColumn() ?: ''),
                                'port_name'     => (string)($pst2->fetchColumn() ?: ''),
                                'survey_types'  => getCombinedSurveyTypeNames($db, $survey_type_ids_csv, ''),
                                'agent_name'    => $agent_name,
                                'app_url'       => $client_vessel_url,
                            ]);
                        } catch (Throwable $cme) { error_log('client assign admin email: ' . $cme->getMessage()); }
                    }

                    // 📧📱 Auto-notify assigned surveyor(s) (email + optional WhatsApp)
                    $notify_surveyor_ids = !empty($all_surveyor_ids) ? $all_surveyor_ids : ($final_surveyor_id > 0 && !$is_outsourcing && $surveyor_id !== 'unassigned' ? [$final_surveyor_id] : []);
                    foreach ($notify_surveyor_ids as $notify_sid) {
                        if ($notify_sid <= 0) continue;
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
                            $notify_msg = notifySurveyorOfAssignment($db, $notify_sid, $job);
                            if ($notify_msg !== '') {
                                $success .= ' · ' . $notify_msg;
                            }
                            // In-app notification to assigned surveyor
                            try {
                                $actor = (string)($_SESSION['full_name'] ?? 'Admin');
                                createNotification(
                                    $db,
                                    (int)$notify_sid,
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
    /* Vessel Name / Agent Name / Remarks: shown in caps as typed, matching how
       they're stored (server-side upper-cased on submit — see the POST
       handler above). Placeholders stay normal case for readability. */
    input[name="vessel_name"], input[name="agent_name"], textarea[name="remarks"] {
        text-transform: uppercase;
    }
    input[name="vessel_name"]::placeholder, input[name="agent_name"]::placeholder, textarea[name="remarks"]::placeholder {
        text-transform: none;
    }
    .form-group-custom input:focus, .form-group-custom select:focus, .form-group-custom textarea:focus {
        border-color: #3b32b3;
        background: white;
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
        padding: 8px 15px;
        border-bottom: 1px solid var(--border-color);
    }
    .ss-country-wrap select.ss-country-filter {
        width: 100%;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        margin-right: 6px;
        padding: 8px 10px 8px 42px;
        font-size: 12.5px;
        background: #f8fafc;
        color: var(--text-dark);
        appearance: auto;
    }
    .ss-option-country {
        float: right;
        font-size: 10.5px;
        color: #94a3b8;
        font-weight: 600;
    }
    .searchable-select .ss-search-wrap i {
        position: absolute;
        left: 27px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 12px;
    }
    .searchable-select .ss-search-input {
        width: 100%;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 8px 10px 8px 34px;
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
    .searchable-select .ss-option-other i { width: 14px; text-align: center; margin-right: 2px; }
    .searchable-select .ss-option-empty { padding: 12px; color: #94a3b8; font-size: 12px; text-align: center; }
    .ss-add-new-box {
        margin-top: 8px; padding: 10px; border: 1px dashed #3b32b3; border-radius: 10px;
        background: #f8f7ff;
    }
    .ss-add-new-box .ss-add-new-input,
    .ss-add-new-box .ss-add-new-input-extra {
        width: 100%; border: 1px solid var(--border-color); border-radius: 8px;
        padding: 8px 10px; font-size: 13px; background: #fff; outline: none;
    }
    .ss-add-new-box .ss-add-new-input-extra { margin-top: 6px; }
    .ss-add-new-box .ss-add-new-input:focus,
    .ss-add-new-box .ss-add-new-input-extra:focus { border-color: #3b32b3; }
    .ss-add-new-actions { display: flex; gap: 8px; margin-top: 8px; }
    .ss-add-new-confirm, .ss-add-new-cancel {
        border: none; border-radius: 8px; padding: 7px 14px; font-size: 12.5px; font-weight: 650;
    }
    .ss-add-new-confirm { background: #3b32b3; color: #fff; }
    .ss-add-new-confirm:disabled { opacity: .6; }
    .ss-add-new-cancel { background: #f1f5f9; color: var(--text-muted); }
    .ss-add-new-error { margin-top: 6px; font-size: 12px; color: #b91c1c; display: none; }
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

    .vdd-hero { display: none; }
    .vdd-form-card-head { display: none; }
    .vdd-field-icon { display: none; }

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
        .form-box-custom > #reportNumberGroup {
            grid-column: 1 / -1;
        }

        /* ═══ Desktop modern redesign — same hero + icon-chip visual
           language as the rest of the app's redesigned pages. Mobile
           (everything outside this block) is completely untouched. Every
           .searchable-select / .ss-* class name below is exactly what the
           JS at the bottom of this file already builds/queries against —
           only colors, radius and spacing change; no JS was touched. */
        .top-app-bar { display: none !important; }
        .scroll-content { max-width: 1400px; margin: 0 auto; padding: 8px 20px 40px; }

        .vdd-hero {
            position: relative; overflow: hidden; border-radius: 22px;
            background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%);
            padding: 26px 30px; margin-bottom: 4px;
            display: flex; align-items: center; gap: 16px;
            box-shadow: 0 14px 34px rgba(30,27,107,.22);
        }
        .vdd-hero-icon-bg {
            position: absolute; right: -26px; top: -40px; font-size: 170px; color: rgba(255,255,255,.07);
            pointer-events: none; line-height: 1;
        }
        .vdd-hero-ship {
            width: 52px; height: 52px; border-radius: 15px; flex: none; position: relative;
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center; color: #fff; font-size: 22px;
        }
        .vdd-hero-title { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -.01em; position: relative; }
        .vdd-hero-sub { font-size: 12.5px; color: rgba(255,255,255,.66); margin-top: 4px; font-weight: 500; position: relative; }

        .form-box-custom {
            border: 1px solid #e2e8f0 !important; border-radius: 20px !important;
            box-shadow: 0 4px 20px rgba(15,23,42,.05) !important; padding: 26px 28px !important;
        }
        .vdd-form-card-head {
            grid-column: 1 / -1; display: flex; align-items: center; gap: 10px;
            margin: -26px -28px 18px; padding: 18px 28px; border-bottom: 1px solid #f1f5f9;
        }
        .vdd-form-card-head-icon {
            width: 30px; height: 30px; border-radius: 9px; flex: none;
            background: linear-gradient(135deg, #8B7CF6, #4B2FD6); color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 13px;
        }
        .vdd-form-card-head-title { font-size: 15.5px; font-weight: 800; color: #0f172a; }
        .vdd-form-card-head-sub { font-size: 11.5px; color: #94a3b8; margin-top: 1px; font-weight: 500; }

        .form-group-custom label {
            display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: #64748b;
        }
        .form-group-custom label i.vdd-field-icon { display: inline-block; color: #A78BFA; font-size: 11px; width: 14px; text-align: center; }
        .form-group-custom input,
        .form-group-custom select,
        .form-group-custom textarea,
        .searchable-select .ss-trigger {
            border: 1.5px solid #e2e8f0 !important; border-radius: 12px !important;
            padding: 11px 14px !important; font-size: 13.5px !important; background: #F8FAFC !important;
        }
        .form-group-custom input:focus,
        .form-group-custom select:focus,
        .form-group-custom textarea:focus,
        .searchable-select .ss-trigger:focus {
            outline: none !important; border-color: #A78BFA !important;
            box-shadow: 0 0 0 4px rgba(91,45,238,.12) !important; background: #fff !important;
        }
        .searchable-select.open .ss-trigger {
            border-color: #A78BFA !important; box-shadow: 0 0 0 4px rgba(91,45,238,.12) !important; background: #fff !important;
        }
        .searchable-select .ss-panel {
            border: 1.5px solid #e2e8f0; border-radius: 14px; box-shadow: 0 16px 36px rgba(15,23,42,.14);
        }
        .searchable-select .ss-option:hover, .searchable-select .ss-option.ss-selected {
            background: #F3EEFE !important; color: #5B2DEE !important;
        }
        .ss-multi .ss-option.ss-selected { background: #F3EEFE !important; color: #5B2DEE !important; }
        .ss-multi .ss-option-checkbox { accent-color: #5B2DEE !important; }
        .searchable-select .ss-search-input, .ss-country-wrap select.ss-country-filter {
            border: 1.5px solid #e2e8f0 !important; border-radius: 10px !important ;background: #F8FAFC !important;
        }
        #assignVesselForm .searchable-select .ss-country-wrap select.ss-country-filter {
            padding-left: 27px !important;
        }
        .searchable-select .ss-search-input:focus {
            outline: none !important; border-color: #A78BFA !important; background: #fff !important;
        }
        #assignVesselForm .searchable-select .ss-search-input {
            padding:10px 230px 10px 32px !important;
        }

        .blue-action-btn {
            border: none !important; border-radius: 14px !important; height: 48px;
            background: linear-gradient(120deg, #1E1B6B, #4B2FD6) !important; font-weight: 800 !important;
            box-shadow: 0 10px 22px rgba(75,47,214,.3) !important; display: flex; align-items: center; justify-content: center; gap: 8px;
        }

        #vesselNameField ~ .form-group-custom > div[style*="f8fafc"] {
            border: 1px solid #e2e8f0 !important; border-radius: 12px !important; background: #F8FAFC !important;
        }
        input[type="file"][name="assignment_attachment[]"] {
            border: 1.5px dashed #C7D2E0 !important; border-radius: 14px !important;
            padding: 14px !important; background: #F8FAFC !important; cursor: pointer;
        }

        #duplicateVesselModal .modal-content {
            border: none; border-radius: 20px; box-shadow: 0 20px 44px rgba(15,23,42,.22); overflow: hidden;
        }
        #duplicateVesselModal .modal-header {
            background: linear-gradient(120deg, #FFF7ED, #FFEDD5); border-bottom: 1px solid #FED7AA; padding: 18px 22px;
        }
        #duplicateVesselModal .modal-body { padding: 22px; }
        #duplicateVesselModal .modal-footer { padding: 14px 22px; border-top: 1px solid #f1f5f9; }
        #duplicateVesselConfirmBtn {
            border-radius: 999px !important; padding: 0 20px !important; height: 40px;
            background: linear-gradient(120deg, #1E1B6B, #4B2FD6) !important; border: none !important;
            box-shadow: 0 8px 18px rgba(75,47,214,.28);
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

    /* 🌟 Manual Form | Paste Email tabs + Paste Email panel — Assign Vessel
       (AI Email -> Auto Vessel Assignment). Same color scheme/typography as
       the rest of this page (--accent-blue / #3b32b3 primary action color,
       var(--border-color), var(--text-muted)). */
    .vessel-input-tabs { display: flex; gap: 8px; }
    .vit-tab {
        flex: 1 1 0;
        display: flex; align-items: center; justify-content: center; gap: 7px;
        padding: 11px 10px; border-radius: 12px; border: 1px solid var(--border-color);
        background: #fff; color: var(--text-muted); font-size: 13px; font-weight: 650;
    }
    .vit-tab.active { background: #3b32b3; border-color: #3b32b3; color: #fff; }

    .paste-email-card {
        background: var(--card-bg); border: 1px solid var(--border-color);
        border-radius: 16px; padding: 16px;
    }
    .pe-head { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 12px; }
    .pe-head-icon {
        width: 36px; height: 36px; border-radius: 10px; background: #eef2ff; color: #3b32b3;
        display: flex; align-items: center; justify-content: center; font-size: 15px; flex: 0 0 auto;
    }
    .pe-head-title { font-size: 14px; font-weight: 700; color: var(--text-dark); }
    .pe-head-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
    #pasteEmailTextarea {
        width: 100%; border: 1px solid var(--border-color); border-radius: 12px;
        padding: 12px; font-size: 13px; background: #f8fafc; outline: none; resize: vertical;
    }
    #pasteEmailTextarea:focus { border-color: #3b32b3; background: #fff; }
    .pe-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
    .pe-btn {
        border: none; border-radius: 10px; padding: 11px 16px; font-size: 13px; font-weight: 650;
        display: inline-flex; align-items: center; gap: 7px;
    }
    .pe-btn-primary { background: #3b32b3; color: #fff; flex: 1 1 auto; justify-content: center; }
    .pe-btn-ghost { background: #f1f5f9; color: var(--text-muted); }
    .pe-loading { margin-top: 12px; font-size: 12.5px; color: var(--text-muted); display: flex; align-items: center; gap: 8px; }
    .pe-spinner {
        width: 15px; height: 15px; border-radius: 50%; border: 2px solid #dbe0f5; border-top-color: #3b32b3;
        display: inline-block; animation: pe-spin .7s linear infinite;
    }
    @keyframes pe-spin { to { transform: rotate(360deg); } }
    .pe-error { margin-top: 12px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 10px 12px; font-size: 12.5px; }

    .pe-multi { margin-top: 14px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 12px; }
    .pe-multi-title { font-size: 12.5px; font-weight: 700; color: #92400e; margin-bottom: 8px; }
    .pe-multi-list { display: flex; flex-direction: column; gap: 6px; }
    .pe-multi-item {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        background: #fff; border: 1px solid var(--border-color); border-radius: 10px; padding: 9px 11px;
        font-size: 12.5px; font-weight: 600; color: var(--text-dark);
    }
    .pe-multi-item button {
        border: none; background: #3b32b3; color: #fff; border-radius: 8px; padding: 6px 12px;
        font-size: 11.5px; font-weight: 650;
    }

    .pe-result { margin-top: 14px; }
    .pe-result-head { font-size: 13.5px; font-weight: 700; color: #15803d; display: flex; align-items: center; gap: 7px; }
    .pe-confidence {
        margin-left: auto; font-size: 11px; font-weight: 700; color: #3b32b3; background: #eef2ff;
        padding: 3px 9px; border-radius: 999px;
    }
    .pe-result-sub { font-size: 11.5px; color: var(--text-muted); margin: 4px 0 10px; }
    .pe-fields { display: flex; flex-direction: column; gap: 6px; }
    .pe-field-row {
        display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid var(--border-color);
        border-radius: 9px; padding: 8px 10px; font-size: 12.5px;
    }
    .pe-field-label { color: var(--text-muted); flex: 0 0 120px; font-weight: 600; }
    .pe-field-value { flex: 1; color: var(--text-dark); font-weight: 650; word-break: break-word; }
    .pe-field-value.empty { color: #cbd5e1; font-weight: 500; font-style: italic; }
    .pe-field-badge { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; flex: 0 0 auto; }
    .pe-field-badge.ok { background: #dcfce7; color: #16a34a; }
    .pe-field-badge.warn { background: #fef3c7; color: #b45309; }
    .pe-missing { margin-top: 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 12px; font-size: 12px; color: #92400e; }
    .pe-missing-item { display: block; margin-top: 3px; }
    .pe-result-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
    .pe-result-actions .pe-btn-primary { flex: 1 1 160px; }
    .pe-result-actions .pe-btn-ghost { flex: 1 1 120px; justify-content: center; }

    /* AI-populated field highlight on the Manual Form itself, + the small
       "✓ AI" badge injected next to its label. Both clear the moment the
       admin edits the field (see JS), since at that point it's their value. */
    .ai-filled { background: #eef2ff !important; border-color: #3b32b3 !important; }
    .ai-badge {
        display: inline-flex; align-items: center; gap: 3px; font-size: 9.5px; font-weight: 700;
        color: #3b32b3; background: #eef2ff; padding: 2px 7px; border-radius: 999px; margin-left: 6px;
        vertical-align: middle;
    }

    /* ═══════════ Ultra-modern Assign Vessel form (single card).
       Only appearance changes: every field keeps its name/id/class, so the
       existing searchable-select JS, validation and submit flow are untouched. */
    #assignVesselForm .avm-form {
        display: block !important; background: transparent !important; border: none !important;
        box-shadow: none !important; padding: 0 !important; margin: 14px 12px 24px !important; max-width: 1000px;
    }
    @media (min-width: 992px) {
        /* The page's older desktop rule (max-width + margin:auto, padding 20px)
           centred a narrow column on the whole window and ignored the 252px
           sidebar. Use the app's standard sidebar offset instead and centre
           one 960px column (hero, alerts, form) inside the remaining space. */
        .scroll-content {
            max-width: none !important; width: 100% !important; margin: 0 !important;
            padding: 20px 32px 40px 284px !important; box-sizing: border-box;
        }
        .scroll-content > .vdd-hero,
        .scroll-content > .alert,
        .scroll-content > div.mx-3,
        #manualFormPanel { max-width: 960px; margin-left: auto !important; margin-right: auto !important; }
        .scroll-content > .vdd-hero { margin-bottom: 18px !important; }
        #assignVesselForm .avm-form { margin: 0 0 32px !important; max-width: none; }
    }

    .avm-card {
        background: #fff; border: 1px solid #E9ECF5; border-radius: 22px; padding: 20px 16px 6px; margin-bottom: 14px;
        box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 12px 32px rgba(15,23,42,.05);
    }
    @media (min-width: 768px) { .avm-card { padding: 26px 26px 10px; } }

    .avm-grid { display: grid; grid-template-columns: 1fr; gap: 0 18px; }
    @media (min-width: 768px) {
        .avm-grid { grid-template-columns: 1fr 1fr; }
        .avm-grid > .avm-span-2 { grid-column: 1 / -1; }
    }
    #assignVesselForm .form-group-custom { margin-bottom: 16px; min-width: 0; }
    #assignVesselForm .form-group-custom label {
        display: flex !important; align-items: center; gap: 7px; flex-wrap: wrap;
        font-size: 12px !important; font-weight: 700 !important; color: #475569 !important;
        margin: 0 0 7px !important; letter-spacing: .01em;
    }
    #assignVesselForm .form-group-custom label i.vdd-field-icon {
        display: inline-flex !important; align-items: center; justify-content: center;
        width: 22px; height: 22px; border-radius: 7px; background: #F3EEFE; color: #7C3AED; font-size: 10.5px;
    }
    #assignVesselForm .form-group-custom input:not([type=checkbox]):not([type=hidden]):not([type=file]):not(.ss-search-input):not(.ss-add-new-input):not(.ss-add-new-input-extra),
    #assignVesselForm .form-group-custom textarea,
    #assignVesselForm .searchable-select .ss-trigger {
        border: 1.5px solid #E4E7EF !important; border-radius: 14px !important; background: #F9FAFC !important;
        padding: 12px 15px !important; min-height: 50px !important; font-size: 15px !important; color: #0f172a !important;
        box-shadow: inset 0 1px 2px rgba(15,23,42,.03) !important;
        transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    #assignVesselForm .form-group-custom textarea { min-height: 96px !important; line-height: 1.5; }
    #assignVesselForm .form-group-custom input:hover:not(:disabled):not([readonly]),
    #assignVesselForm .form-group-custom textarea:hover,
    #assignVesselForm .searchable-select .ss-trigger:hover { border-color: #CBD2E1 !important; background: #fff !important; }
    #assignVesselForm .form-group-custom input:focus,
    #assignVesselForm .form-group-custom textarea:focus,
    #assignVesselForm .searchable-select .ss-trigger:focus-visible,
    #assignVesselForm .searchable-select.open .ss-trigger {
        outline: none !important; border-color: #8B5CF6 !important; background: #fff !important;
        box-shadow: 0 0 0 4px rgba(139,92,246,.16) !important;
    }
    #assignVesselForm .form-group-custom input[readonly], #assignVesselForm .form-group-custom input:disabled {
        background: linear-gradient(120deg, #F5F3FF, #EEF2FF) !important; border-style: dashed !important; color: #3730A3 !important; font-weight: 800 !important;
    }
    #assignVesselForm .searchable-select .ss-trigger { display: flex; align-items: center; justify-content: space-between; text-align: left; }
    #assignVesselForm .searchable-select .ss-trigger-text:not(.placeholder) { color: #0f172a !important; font-weight: 700 !important; }
    /* Bootstrap's own .placeholder class (loading skeleton) paints a solid
       currentColor bar behind the "Select …" text — undo it here. */
    #assignVesselForm .searchable-select .ss-trigger-text.placeholder {
        background: transparent !important; cursor: inherit; min-height: 0; vertical-align: baseline;
        color: #94a3b8 !important; font-weight: 500 !important; font-size: 15px !important;
    }
    #assignVesselForm .searchable-select .ss-trigger .fa-chevron-down { color: #8B5CF6; transition: transform .2s ease; }
    #assignVesselForm .searchable-select.open .ss-trigger .fa-chevron-down { transform: rotate(180deg); }
    #assignVesselForm .searchable-select .ss-panel {
        border: 1px solid #E4E7EF !important; border-radius: 16px !important;
        box-shadow: 0 24px 48px rgba(15,23,42,.16) !important; overflow: hidden;
    }
    #assignVesselForm .searchable-select .ss-option { border-radius: 10px; margin: 2px 6px; }
    #assignVesselForm .searchable-select .ss-option:hover,
    #assignVesselForm .searchable-select .ss-option.ss-selected { background: #F3EEFE !important; color: #5B2DEE !important; }
    #assignVesselForm .ss-multi .ss-option-checkbox { accent-color: #7C3AED !important; }
    #assignVesselForm .searchable-select .ss-search-input { border-radius: 12px !important; }
    /* keep the search / country text clear of its icon (the desktop .form-group-custom input rule sets 14px !important) */
    .searchable-select .ss-search-wrap .ss-search-input,
    .searchable-select .ss-search-wrap select.ss-country-filter { padding-left: 34px !important; }

    /* file input as a drop zone */
    #assignVesselForm input[type="file"][name="assignment_attachment[]"] {
        width: 100%; border: 2px dashed #C9C2F5 !important; border-radius: 18px !important;
        padding: 18px !important; background: linear-gradient(135deg, #FAF8FF, #F5F7FF) !important;
        cursor: pointer; font-size: 13px; color: #475569; min-height: 0 !important;
        transition: border-color .15s ease, background .15s ease;
    }
    #assignVesselForm input[type="file"][name="assignment_attachment[]"]:hover { border-color: #8B5CF6 !important; background: #F5F3FF !important; }
    #assignVesselForm input[type="file"]::file-selector-button {
        border: none; border-radius: 12px; padding: 10px 16px; margin-right: 12px; cursor: pointer;
        background: linear-gradient(120deg, #4B2FD6, #7C3AED); color: #fff; font-weight: 700; font-size: 13px;
        box-shadow: 0 6px 14px rgba(91,45,238,.25);
    }
    #assignVesselForm .avm-existing > div[style] { border: 1px solid #E9ECF5 !important; border-radius: 12px !important; background: #F9FAFC !important; padding: 10px 14px !important; }

    /* sticky submit bar */
    .avm-submit-bar {
        display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
        background: #fff; border: 1px solid #E9ECF5; border-radius: 20px; padding: 12px 12px 12px 18px;
        box-shadow: 0 12px 32px rgba(15,23,42,.08);
    }
    @media (min-width: 992px) { .avm-submit-bar { position: sticky; bottom: 16px; z-index: 30; } }
    .avm-submit-hint { font-size: 12px; color: #64748b; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }
    .avm-submit-hint i { color: #A78BFA; }
    #assignVesselForm .blue-action-btn.avm-submit {
        width: auto !important; min-width: 240px; flex: 1 1 240px; max-width: 360px; margin: 0 !important; height: 52px;
        border: none !important; border-radius: 16px !important; color: #fff !important; font-weight: 800 !important; font-size: 15px !important;
        display: inline-flex !important; align-items: center; justify-content: center; gap: 10px;
        background: linear-gradient(120deg, #1E1B6B 0%, #4B2FD6 50%, #7C3AED 100%) !important;
        background-size: 200% 100% !important; background-position: 0% 0% !important;
        box-shadow: 0 12px 26px rgba(75,47,214,.35) !important;
        transition: background-position .4s ease, transform .15s ease, box-shadow .2s ease;
    }
    #assignVesselForm .blue-action-btn.avm-submit:hover:not(:disabled) { background-position: 100% 0% !important; transform: translateY(-1px); box-shadow: 0 16px 32px rgba(75,47,214,.42) !important; }
    #assignVesselForm .blue-action-btn.avm-submit:active:not(:disabled) { transform: translateY(0); }
    @media (max-width: 575.98px) {
        .avm-submit-bar { padding: 12px; }
        .avm-submit-hint { width: 100%; justify-content: center; }
        #assignVesselForm .blue-action-btn.avm-submit { max-width: none; width: 100% !important; }
    }

    @media (prefers-reduced-motion: reduce) { #assignVesselForm .blue-action-btn.avm-submit { transition: none; } }
</style>
<link rel="stylesheet" href="assets/css/app-pages.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-pages.css') ?>">

<?php
$page_title = $completed_mode ? 'Add Completed Vessel' : ($edit_mode ? 'Edit Vessel Assignment' : ($is_requester ? 'Request Survey' : 'Assign New Vessel'));
$back_url = $completed_mode ? 'admin_controls.php?tab=completed_surveys' : ($edit_mode ? 'vessel_detail.php?id=' . (int)$edit_id : ($is_sales_role ? 'sales_dashboard.php' : 'index.php'));
$page_testid = 'assign-vessel';
include 'includes/top_app_bar.php';
?>

<div class="scroll-content av-page">
    <div class="vdd-hero">
        <i class="fa-solid fa-ship vdd-hero-icon-bg" aria-hidden="true"></i>
        <div class="vdd-hero-ship"><i class="fa-solid fa-ship"></i></div>
        <div>
            <div class="vdd-hero-title" data-testid="av-hero-title"><?= $completed_mode ? 'Add Completed Vessel' : ($edit_mode ? 'Edit Vessel Assignment' : ($is_requester ? 'Request Survey' : 'Assign New Vessel')) ?></div>
            <div class="vdd-hero-sub"><?= $completed_mode ? 'Enter a survey that is already completed — it is saved straight into Completed Vessels' : ($edit_mode ? 'Update this vessel\'s survey assignment' : ($is_sales_role ? 'Request a survey for a client — the office assigns the surveyor' : 'Assign a vessel to a client, port, survey type and surveyor')) ?></div>
        </div>
    </div>

    <!-- Feedback Alerts -->
    <?php if($success): ?>
        <div class="alert alert-success mx-3 mt-3 py-2" style="font-size:12px;"><?= sanitize($success) ?></div>
        <?php if (!empty($GLOBALS['ysms_mail_failed'])): ?>
            <div class="alert alert-warning mx-3 py-2" style="font-size:12px;">
                <strong>Email notification could not be sent.</strong>
                <?php if (!$is_requester): ?>
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

    <div id="manualFormPanel">
    <?php
    // 🌟 Edit-mode pre-fill values — computed once here, used throughout the
    // form below so every field (including the client/port searchable
    // selects and the survey-type/surveyor multi-selects) opens already
    // showing the existing assignment's current values.
    $prefill_mode = $edit_mode;
    if ($completed_mode && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Completed Vessel Entry sent back with an error: show what was typed / chosen
        $prefill_mode = true;
        $edit_survey = ['vessel_name' => (string)($_POST['vessel_name'] ?? ''), 'client_id' => (int)($_POST['client_id'] ?? 0), 'port_id' => (int)($_POST['port_id'] ?? 0),
            'survey_type_ids' => (string)($_POST['survey_type_ids'] ?? ''), 'agent_name' => (string)($_POST['agent_name'] ?? ''), 'remarks' => (string)($_POST['remarks'] ?? ''), 'report_number' => ''];
        $edit_surveyor_ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['surveyor_ids'] ?? '')))));
    }
    $edit_client_id = $prefill_mode ? (int)($edit_survey['client_id'] ?? 0) : 0;
    $edit_client_name = '';
    if ($prefill_mode) {
        foreach ($clients as $c) {
            if ((int)$c['id'] === $edit_client_id) { $edit_client_name = $c['company_name']; break; }
        }
    }
    $edit_port_id = $prefill_mode ? (int)($edit_survey['port_id'] ?? 0) : 0;
    $edit_port_name = '';
    if ($prefill_mode) {
        foreach ($ports as $p) {
            if ((int)$p['id'] === $edit_port_id) { $edit_port_name = $p['port_name']; break; }
        }
    }
    $edit_type_ids = $prefill_mode ? array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($edit_survey['survey_type_ids'] ?? '')))))) : [];
    ?>
    <!-- Assignment Form -->
    <form action="assign_vessel.php<?= $completed_mode ? '?completed=1' : ($prefill_mode ? '?edit_id=' . (int)$edit_id : '') ?>" method="POST" id="assignVesselForm" enctype="multipart/form-data"<?= $completed_mode ? ' data-completed-entry="1"' : '' ?>><?= csrf_field() ?>
        <?php if ($completed_mode): ?><input type="hidden" name="completed_entry" value="1"><?php endif; ?>
        <?php if ($edit_mode): ?><input type="hidden" name="edit_id" value="<?= (int)$edit_id ?>"><?php endif; ?>
        <div class="form-box-custom avm-form" style="padding-top:20px;">
            <div class="avm-card">
                <div class="avm-grid">
            <div class="form-group-custom" id="vesselNameField">
                <label for="vessel_name"><i class="fa-solid fa-ship vdd-field-icon"></i> Vessel Name *</label>
                <input type="text" name="vessel_name" id="vessel_name" value="<?= $prefill_mode ? sanitize($edit_survey['vessel_name']) : '' ?>" placeholder="e.g. MV Pacific Dawn" required autocomplete="off" inputmode="text" style="font-size:16px;min-height:48px;">
            </div>

                        <?php if ($is_client_role): ?>
            <!-- 🌟 Client role: company is fixed to their own — no dropdown, no visibility
                 into other clients' names. Server-side (POST handler above) also locks
                 client_id to this value regardless of what's submitted. -->
            <div class="form-group-custom">
                <label><i class="fa-solid fa-building vdd-field-icon"></i> Client Name *</label>
                <input type="text" value="<?= sanitize($own_client_name ?: 'Your Company') ?>" disabled
                       style="background:#f1f5f9;color:#0b1e46;font-weight:700;" data-testid="client-name-locked">
                <input type="hidden" name="client_id" id="clientSelect" value="<?= (int)$own_client_id ?>">
            </div>
            <?php else: ?>
            <!-- 🌟 Client Name: searchable dropdown with search box inside.
                 Admin can also add a brand new client right here via "+ Add
                 New Client" — it is saved through the same endpoint Admin
                 Controls uses, so it shows up there too. -->
            <div class="form-group-custom">
                <label><i class="fa-solid fa-building vdd-field-icon"></i> Client Name *</label>
                <div class="searchable-select" data-ss-root="client">
                    <button type="button" class="ss-trigger" data-testid="client-select-trigger">
                        <span class="ss-trigger-text<?= ($prefill_mode && $edit_client_id) ? '' : ' placeholder' ?>" data-placeholder="Select Client" style="color:#64748b !important;font-weight:500;font-size:14px;"><?= ($prefill_mode && $edit_client_id) ? sanitize($edit_client_name) : 'Select Client' ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="ss-panel">
                        <div class="ss-search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="ss-search-input" placeholder="Search client..." autocomplete="off" data-testid="client-search-input">
                        </div>
                        <ul class="ss-options" data-testid="client-options-list">
                            <?php foreach($clients as $client): ?>
                                <li class="ss-option<?= ($prefill_mode && (int)$client['id'] === $edit_client_id) ? ' ss-selected' : '' ?>" data-value="<?= $client['id'] ?>" data-name="<?= strtolower(sanitize($client['company_name'])) ?>" data-short="<?= sanitize(strtoupper(trim($client['short_code'] ?? ''))) ?>"><?= sanitize($client['company_name']) ?><?php if (!empty($client['short_code'])): ?> <span style="color:#64748b;font-weight:600;">(<?= sanitize(strtoupper($client['short_code'])) ?>)</span><?php endif; ?></li>
                            <?php endforeach; ?>
                            <?php if (!$is_sales_role): ?><li class="ss-option ss-option-other" data-value="__add_new__"><i class="fa-solid fa-plus"></i> Add New Client</li><?php endif; ?>
                        </ul>
                    </div>
                </div>
                <select name="client_id" id="clientSelect" class="ss-hidden-select" tabindex="-1" aria-hidden="true">
                    <option value="">Select Client</option>
                    <?php foreach($clients as $client): ?>
                        <option value="<?= $client['id'] ?>"<?= ($prefill_mode && (int)$client['id'] === $edit_client_id) ? ' selected' : '' ?>><?= sanitize($client['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="ss-add-new-box" id="addNewBox-client" data-add-module="clients" data-ss-root="client" style="display:none;">
                    <input type="text" class="ss-add-new-input" placeholder="New client name">
                    <div class="ss-add-new-actions">
                        <button type="button" class="ss-add-new-confirm">Add</button>
                        <button type="button" class="ss-add-new-cancel">Cancel</button>
                    </div>
                    <div class="ss-add-new-error"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Report Number: shown only after client selected — YMR/{SHORT}/{YYYY}/{MM}/{NNNN}.
                 In edit mode this shows the assignment's existing (fixed) number instead of a
                 live preview — editing never generates a new one. -->
            <?php if (!$completed_mode): ?>
            <div class="form-group-custom avm-span-2" id="reportNumberGroup" style="<?= ($prefill_mode && !empty($edit_survey['report_number'])) ? '' : 'display:none;' ?>">
                <label><i class="fa-solid fa-hashtag vdd-field-icon"></i> Report Number <?php if (!$edit_mode): ?><span class="text-muted fw-normal">(auto)</span><?php endif; ?></label>
                <input type="text" id="reportNumberPreview" value="<?= $prefill_mode ? sanitize($edit_survey['report_number'] ?? '') : '' ?>" readonly
                       style="background:#f1f5f9; color:#0b1e46; font-weight:700; letter-spacing:0.5px; cursor:default;"
                       data-testid="report-number-preview" placeholder="Select client to generate">
                <?php if (!$edit_mode): ?>
                <div class="small text-muted mt-1" style="font-size:11px;">
                    <i class="fa-solid fa-lock me-1"></i>Format: <strong>YMR/AS/<?= date('Y') ?>/<?= date('m') ?>/0001</strong> — client short form + year + month + sequence.
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>


<div class="form-group-custom">
                <label><i class="fa-solid fa-people-group vdd-field-icon"></i> Agent Name *</label>
                <input type="text" name="agent_name" value="<?= $prefill_mode ? sanitize($edit_survey['agent_name']) : '' ?>" placeholder="e.g. Oceanus Agencies" required>
            </div>


            <?php if ($completed_mode): $cePost = fn($k) => sanitize((string)($_POST[$k] ?? '')); ?>
            <div class="form-group-custom">
                <label><i class="fa-solid fa-hashtag vdd-field-icon"></i> Report Number <span class="text-muted fw-normal" style="font-size:10.5px;">(optional — blank = automatic)</span></label>
                <input type="text" name="report_number" value="<?= $cePost('report_number') ?>" maxlength="60" placeholder="YMR/AS/<?= date('Y') ?>/<?= date('m') ?>/0001" data-testid="ce-report-number">
            </div>
            <div class="form-group-custom avm-span-2">
                <div class="ce-dates">
                    <div><label><i class="fa-solid fa-calendar-plus vdd-field-icon"></i> Survey Assigned Date *</label>
                        <input type="date" name="assign_date" value="<?= $cePost('assign_date') ?>" max="<?= date('Y-m-d') ?>" required data-testid="ce-assign-date"></div>
                    <div><label><i class="fa-solid fa-calendar-check vdd-field-icon"></i> Survey Completed Date *</label>
                        <input type="date" name="completed_date" value="<?= $cePost('completed_date') ?>" max="<?= date('Y-m-d') ?>" required data-testid="ce-completed-date"></div>
                    <div><label><i class="fa-solid fa-file-circle-check vdd-field-icon"></i> Report Completed Date *</label>
                        <input type="date" name="report_date" value="<?= $cePost('report_date') ?>" max="<?= date('Y-m-d') ?>" required data-testid="ce-report-date"></div>
                </div>
            </div>
            <?php else: ?>
            <div class="form-group-custom">
                <label><i class="fa-solid fa-calendar-day vdd-field-icon"></i> Appointment Date *</label>
                <input type="date" name="appointment_date" value="<?= $prefill_mode ? sanitize($edit_survey['appointment_date'] ?? '') : '' ?>" required>
            </div>
            <?php endif; ?>

            <!-- 🌟 Port Name: searchable dropdown with search box inside -->
            <div class="form-group-custom avm-span-2">
                <label><i class="fa-solid fa-location-dot vdd-field-icon"></i> Port Name *</label>
                <div class="searchable-select" data-ss-root="port">
                    <button type="button" class="ss-trigger" data-testid="port-select-trigger">
                        <span class="ss-trigger-text<?= ($prefill_mode && $edit_port_id) ? '' : ' placeholder' ?>" data-placeholder="Select Port" style="color:#64748b !important;font-weight:500;font-size:14px;"><?= ($prefill_mode && $edit_port_id) ? sanitize($edit_port_name) : 'Select Port' ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="ss-panel">
                        <div class="ss-search-wrap ss-country-wrap">
                            <i class="fa-solid fa-globe"></i>
                            <select class="ss-country-filter" id="portCountryFilter" data-testid="port-country-filter">
                                <option value="">All Countries</option>
                                <?php foreach ($port_countries as $pc): ?>
                                    <option value="<?= strtolower(sanitize($pc)) ?>"><?= sanitize($pc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ss-search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="ss-search-input" placeholder="Search port..." autocomplete="off" data-testid="port-search-input">
                        </div>
                        <ul class="ss-options" data-testid="port-options-list">
                            <?php foreach($ports as $port): ?>
                                <li class="ss-option<?= ($prefill_mode && (int)$port['id'] === $edit_port_id) ? ' ss-selected' : '' ?>" data-value="<?= $port['id'] ?>" data-name="<?= strtolower(sanitize($port['port_name'])) ?>" data-country="<?= strtolower(sanitize($port['country'] ?? 'India')) ?>"><?= sanitize($port['port_name']) ?> <span class="ss-option-country"><?= sanitize($port['country'] ?? 'India') ?></span></li>
                            <?php endforeach; ?>
                            <?php if (!$is_requester): ?>
                            <li class="ss-option ss-option-other" data-value="__add_new__"><i class="fa-solid fa-plus"></i> Add New Port</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <select name="port_id" id="portSelect" class="ss-hidden-select" tabindex="-1" aria-hidden="true">
                    <option value="">Select Port</option>
                    <?php foreach($ports as $port): ?>
                        <option value="<?= $port['id'] ?>"<?= ($prefill_mode && (int)$port['id'] === $edit_port_id) ? ' selected' : '' ?>><?= sanitize($port['port_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$is_requester): ?>
                <div class="ss-add-new-box" id="addNewBox-port" data-add-module="ports" data-ss-root="port" style="display:none;">
                    <input type="text" class="ss-add-new-input" placeholder="New port name">
                    <input type="text" class="ss-add-new-input-extra" data-field="country" placeholder="Country (leave blank for India)">
                    <div class="ss-add-new-actions">
                        <button type="button" class="ss-add-new-confirm">Add</button>
                        <button type="button" class="ss-add-new-cancel">Cancel</button>
                    </div>
                    <div class="ss-add-new-error"></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- 🌟 Survey Type: searchable MULTI-select (checkboxes) with search box inside -->
            <div class="form-group-custom avm-span-2">
                <label><i class="fa-solid fa-file-circle-check vdd-field-icon"></i> Survey Type * <span class="text-muted fw-normal" style="font-size:10.5px;">(select one or more)</span></label>
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
                            <?php foreach($survey_types as $type): $typeSelected = $prefill_mode && in_array((int)$type['id'], $edit_type_ids, true); ?>
                                <li class="ss-option<?= $typeSelected ? ' ss-selected' : '' ?>" data-value="<?= $type['id'] ?>" data-name="<?= strtolower(sanitize($type['type_name'])) ?>">
                                    <input type="checkbox" class="ss-option-checkbox" tabindex="-1"<?= $typeSelected ? ' checked' : '' ?>>
                                    <span class="ss-option-label"><?= sanitize($type['type_name']) ?></span>
                                </li>
                            <?php endforeach; ?>
                            <?php if (!$is_requester): ?>
                            <li class="ss-option ss-option-other" data-value="__add_new__"><i class="fa-solid fa-plus"></i> Add New Survey Type</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <input type="hidden" name="survey_type_ids" id="surveyTypeIdsInput" value="">
                <?php if (!$is_requester): ?>
                <div class="ss-add-new-box" id="addNewBox-surveyType" data-add-module="survey_types" data-ss-root="surveyType" style="display:none;">
                    <input type="text" class="ss-add-new-input" placeholder="New survey type name">
                    <div class="ss-add-new-actions">
                        <button type="button" class="ss-add-new-confirm">Add</button>
                        <button type="button" class="ss-add-new-cancel">Cancel</button>
                    </div>
                    <div class="ss-add-new-error"></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$is_requester): ?>
            <div class="form-group-custom avm-span-2">
                <label><i class="fa-solid fa-user-tie vdd-field-icon"></i> Assign Surveyor(s) * <span class="text-muted fw-normal" style="font-size:10.5px;">(select one or more)</span></label>
                <div class="searchable-select ss-multi" data-ss-root="surveyor" data-hidden-input="surveyorIdsInput">
                    <button type="button" class="ss-trigger" data-testid="surveyor-select-trigger">
                        <span class="ss-trigger-text placeholder" data-placeholder="Select Surveyor(s)" style="color:#64748b !important;font-weight:500;font-size:14px;">Select Surveyor(s)</span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="ss-panel">
                        <div class="ss-search-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" class="ss-search-input" placeholder="Search surveyor..." autocomplete="off" data-testid="surveyor-search-input">
                        </div>
                        <ul class="ss-options" data-testid="surveyor-options-list">
                            <?php foreach($surveyors as $surveyor): $surveyorSelected = $prefill_mode && in_array((int)$surveyor['id'], $edit_surveyor_ids, true); ?>
                                <li class="ss-option<?= $surveyorSelected ? ' ss-selected' : '' ?>" data-value="<?= (int)$surveyor['id'] ?>" data-name="<?= strtolower(sanitize($surveyor['full_name'])) ?>">
                                    <input type="checkbox" class="ss-option-checkbox" tabindex="-1"<?= $surveyorSelected ? ' checked' : '' ?>>
                                    <span class="ss-option-label"><?= sanitize($surveyor['full_name']) ?></span>
                                </li>
                            <?php endforeach; ?>
                            <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                                <li class="ss-option" data-value="outsourcing" data-name="outsourcing" style="color:#dc2626;font-weight:700;">
                                    <input type="checkbox" class="ss-option-checkbox" tabindex="-1">
                                    <span class="ss-option-label">Outsourcing</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <input type="hidden" name="surveyor_ids" id="surveyorIdsInput" value="">
            </div>
            <?php endif; ?>


            <div class="form-group-custom avm-span-2">
                <label><i class="fa-solid fa-comment vdd-field-icon"></i> Remarks (Optional)</label>
                <textarea name="remarks" rows="3" placeholder="Enter any specific instructions or notes..."><?= $prefill_mode ? sanitize($edit_survey['remarks'] ?? '') : '' ?></textarea>
            </div>

            <?php if ($edit_mode && !empty($edit_attachments)): ?>
            <div class="form-group-custom avm-span-2 avm-existing">
                <label><i class="fa-solid fa-paperclip vdd-field-icon"></i> Already Uploaded</label>
                <?php foreach ($edit_attachments as $att): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid var(--border-color);border-radius:10px;padding:8px 12px;margin-bottom:6px;font-size:12px;">
                        <span><i class="fa-solid fa-paperclip text-primary me-1"></i><?= sanitize($att['file_name']) ?></span>
                        <a href="<?= sanitize($att['file_path']) ?>" download class="text-primary"><i class="fa-solid fa-download"></i></a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($completed_mode): $ceMode = (($_POST['recovery_mode'] ?? '') === 'na') ? 'na' : 'enter'; ?>
            <div class="form-group-custom avm-span-2" data-testid="ce-recovery">
                <label><i class="fa-solid fa-droplet vdd-field-icon"></i> Recovery *</label>
                <div class="ce-rec-mode" role="radiogroup">
                    <label class="ce-pill"><input type="radio" name="recovery_mode" value="enter"<?= $ceMode === 'enter' ? ' checked' : '' ?> data-testid="ce-rec-enter"> <span>Enter recovery</span></label>
                    <label class="ce-pill"><input type="radio" name="recovery_mode" value="na"<?= $ceMode === 'na' ? ' checked' : '' ?> data-testid="ce-rec-na"> <span><?= RECOVERY_NA_LABEL ?></span></label>
                </div>
                <div class="ce-rec-auto" hidden data-testid="ce-rec-auto"><i class="fa-solid fa-circle-info"></i> The selected survey type has no recovery — saved as <?= RECOVERY_NA_LABEL ?>.</div>
                <div class="ce-rec-grid" data-ce-rec-fields>
                    <div><label>VLSFO (MT)</label><input type="text" inputmode="decimal" name="vlsfo" value="<?= $cePost('vlsfo') ?>" placeholder="0.000" data-ce-qty data-testid="ce-vlsfo"></div>
                    <div><label>HSFO (MT)</label><input type="text" inputmode="decimal" name="hsfo" value="<?= $cePost('hsfo') ?>" placeholder="0.000" data-ce-qty data-testid="ce-hsfo"></div>
                    <div><label>LSMGO (MT)</label><input type="text" inputmode="decimal" name="lsmgo" value="<?= $cePost('lsmgo') ?>" placeholder="0.000" data-ce-qty data-testid="ce-lsmgo"></div>
                    <div><label>Total (MT)</label><input type="text" value="" readonly tabindex="-1" class="ce-total" data-ce-total data-testid="ce-total"></div>
                </div>
            </div>
            <div class="form-group-custom avm-span-2">
                <label><i class="fa-solid fa-file-pdf vdd-field-icon"></i> Final Report PDF <span class="text-muted" style="font-weight:500;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <input type="file" name="final_report_pdf" accept=".pdf,application/pdf" data-testid="ce-final-pdf">
            </div>
            <div class="form-group-custom avm-span-2">
                <label><i class="fa-solid fa-cloud-arrow-up vdd-field-icon"></i> Other Files <span class="text-muted" style="font-weight:500;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <input type="file" name="completed_files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.xlsm,.csv,.jpg,.jpeg,.png,.zip" data-testid="ce-files">
                <div class="text-muted" style="font-size:11px;margin-top:4px;">Calculation sheet, photos, Word report… several at once.</div>
            </div>
            <?php if ($ce_dupes): ?>
            <div class="form-group-custom avm-span-2">
                <label class="ce-confirm"><input type="checkbox" name="confirm_duplicate" value="1" data-testid="ce-confirm-duplicate"> Save anyway — this is a different survey</label>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="form-group-custom avm-span-2">
                <label><i class="fa-solid fa-cloud-arrow-up vdd-field-icon"></i> Assignment Attachment(s) <span class="text-muted" style="font-weight:500;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <input type="file" name="assignment_attachment[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip" multiple>
                <div class="text-muted" style="font-size:11px;margin-top:4px;"><?= $edit_mode ? 'Any files selected here are added to the ones already uploaded above — nothing gets removed.' : 'You can select multiple files at once. Surveyor will see all of them on the vessel detail page.' ?></div>
            </div>
            <?php endif; ?>

                </div>
            </div>

            <div class="avm-submit-bar">
                <div class="avm-submit-hint"><i class="fa-solid fa-circle-info"></i> Fields marked <b>*</b> are required</div>
                <button type="submit" class="blue-action-btn avm-submit" data-testid="assign-vessel-submit">
                    <i class="fa-solid <?= $completed_mode ? 'fa-circle-check' : ($edit_mode ? 'fa-floppy-disk' : 'fa-paper-plane') ?>"></i> <?= $completed_mode ? 'Save to Completed Vessels' : ($edit_mode ? 'Save Changes' : 'Assign &amp; Save Vessel') ?>
                </button>
            </div>
        </div>
    </form>
    </div><!-- /#manualFormPanel -->
</div>

<?php /* 🌟 Duplicate-vessel-name confirmation — shown only when the entered
         name already matches a Pending Vessel entry (checked via AJAX right
         before the real submit). Not a hard block: Yes proceeds anyway. */ ?>
<div class="modal fade" id="duplicateVesselModal" tabindex="-1" aria-hidden="true" data-testid="duplicate-vessel-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> Already in Pending Vessels</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="font-size: 13.5px;">
                <p class="mb-2">A vessel named <strong id="dupVesselName"></strong> is already in Pending Vessels:</p>
                <div class="bg-light rounded-3 p-2 mb-2" style="font-size:12.5px;">
                    <div>Report No: <strong id="dupReportNumber"></strong></div>
                    <div>Client: <strong id="dupClientName"></strong></div>
                    <div>Port: <strong id="dupPortName"></strong></div>
                    <div>Assigned: <strong id="dupAssignDate"></strong></div>
                </div>
                <p class="mb-0 fw-bold">Are you sure you want to add this?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal" data-testid="duplicate-vessel-cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="duplicateVesselConfirmBtn" style="background:#3b32b3;border-color:#3b32b3;" data-testid="duplicate-vessel-confirm">Yes, Add Anyway</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// 🌟 includes/footer.php (included at the bottom of this page) loads jQuery
// a SECOND time — that second <script src> creates a brand new jQuery
// instance and replaces window.$/jQuery, so any $.ajaxSetup() default set
// here would be silently gone by the time a click handler actually fires
// $.ajax() later (same proven bug/fix as bulk_assign_vessels.php). Belt and
// suspenders: set ajaxSetup here AND pass the header explicitly on every
// individual $.ajax call below.
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
$.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });
</script>
<script>
    $(document).ready(function() {
        var EDIT_MODE = <?= $edit_mode ? 'true' : 'false' ?>; // editing an existing assignment, not creating a new one
        var COMPLETED_MODE = <?= $completed_mode ? 'true' : 'false' ?>; // Completed Vessel Entry (no Pending Vessels duplicate check)

        // ---- Inline "+ Add New" (Client / Port / Survey Type) ----
        // Admin only (see PHP gating around these boxes). Saves through the
        // same ajax/admin_master.php endpoint Admin Controls uses, so a
        // client/port/survey type added here shows up there too.
        var ADD_NEW_NAME_FIELD = { clients: 'company_name', ports: 'port_name', survey_types: 'type_name' };

        function openAddNewBox($root) {
            // .ss-add-new-box is a sibling of .searchable-select (both live
            // inside the same .form-group-custom), not a descendant of it.
            var $box = $root.closest('.form-group-custom').find('.ss-add-new-box');
            if (!$box.length) { console.warn('[AddNew] no add-new box found for root', $root.data('ss-root')); return; }
            $('.ss-add-new-box').not($box).hide();
            $box.show();
            $box.find('.ss-add-new-error').hide().text('');
            $box.find('.ss-add-new-input-extra').val('');
            var $input = $box.find('.ss-add-new-input').val('');
            setTimeout(function() { try { $input[0].focus(); } catch (e) {} }, 50);
        }

        $(document).on('click', '.ss-add-new-cancel', function() {
            var $box = $(this).closest('.ss-add-new-box');
            $box.hide().find('.ss-add-new-input, .ss-add-new-input-extra').val('');
            $box.find('.ss-add-new-error').hide().text('');
        });

        $(document).on('keydown', '.ss-add-new-input', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $(this).closest('.ss-add-new-box').find('.ss-add-new-confirm').trigger('click');
            } else if (e.key === 'Escape') {
                $(this).closest('.ss-add-new-box').find('.ss-add-new-cancel').trigger('click');
            }
        });

        $(document).on('click', '.ss-add-new-confirm', function() {
            console.log('[AddNew] confirm clicked');
            try {
                var $btn = $(this);
                var $box = $btn.closest('.ss-add-new-box');
                var module = $box.data('add-module');
                var ssRoot = $box.data('ss-root');
                var nameField = ADD_NEW_NAME_FIELD[module];
                var $input = $box.find('.ss-add-new-input');
                var $err = $box.find('.ss-add-new-error');
                var name = $.trim($input.val());
                console.log('[AddNew] module=', module, 'ssRoot=', ssRoot, 'nameField=', nameField, 'name=', name);
                if (!name) {
                    $err.text('Please enter a name.').show();
                    return;
                }
                $err.hide().text('');
                $btn.prop('disabled', true).text('Adding...');
                var payload = { module: module, action: 'add' };
                payload[nameField] = name;
                $box.find('.ss-add-new-input-extra').each(function() {
                    var field = $(this).data('field');
                    if (field) { payload[field] = $.trim($(this).val()); }
                });
                $.ajax({
                    url: 'ajax/admin_master.php',
                    method: 'POST',
                    data: payload,
                    dataType: 'json',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN }
                })
                    .done(function(resp) {
                        console.log('[AddNew] response:', resp);
                        if (resp && resp.success && resp.id) {
                            try {
                                var $root = $('[data-ss-root="' + ssRoot + '"]');
                                var api = $root.data('ssApi');
                                console.log('[AddNew] root found=', $root.length, 'api=', !!api);
                                if (api && api.addAndSelect) { api.addAndSelect(resp.id, name); }
                                $box.hide();
                                $input.val('');
                                $box.find('.ss-add-new-input-extra').val('');
                            } catch (uiErr) {
                                console.error('[AddNew] UI update error:', uiErr);
                                $err.text('Added, but could not update the list — please refresh the page.').show();
                            }
                        } else {
                            $err.text((resp && resp.message) ? resp.message : 'Could not add — try again.').show();
                        }
                    })
                    .fail(function(xhr) {
                        console.error('[AddNew] request failed:', xhr.status, xhr.responseText);
                        $err.text('Could not add (error ' + xhr.status + '). Open browser console for details.').show();
                    })
                    .always(function() {
                        $btn.prop('disabled', false).text('Add');
                    });
            } catch (outerErr) {
                console.error('[AddNew] handler threw:', outerErr);
                alert('Add New error: ' + outerErr.message);
            }
        });

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

            const $countryFilter = $root.find('.ss-country-filter');

            function applyFilter() {
                const term = String($search.val() || '').toLowerCase().trim();
                const countryVal = String($countryFilter.val() || '').toLowerCase().trim();
                let anyVisible = false;
                $optionsList.find('.ss-option').each(function() {
                    const $opt = $(this);
                    if ($opt.hasClass('ss-option-other')) { $opt.show(); return; }
                    const name = String($opt.data('name') || '').toLowerCase();
                    const country = String($opt.data('country') || '').toLowerCase();
                    const matchTerm = !term || name.indexOf(term) !== -1;
                    const matchCountry = !countryVal || country === countryVal;
                    const match = matchTerm && matchCountry;
                    $opt.toggle(match);
                    if (match) anyVisible = true;
                });
                $optionsList.find('.ss-option-empty').remove();
                if (!anyVisible) {
                    $optionsList.prepend('<li class="ss-option-empty">No matches found</li>');
                }
            }

            $search.on('input', applyFilter);
            $countryFilter.on('change', function(e) {
                e.stopPropagation();
                applyFilter();
            }).on('click', function(e) { e.stopPropagation(); });

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
                const $opt = $(this);
                if ($opt.hasClass('ss-option-other') && $opt.data('value') === '__add_new__') {
                    closePanel();
                    openAddNewBox($root);
                    return;
                }
                selectOption($opt);
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

        // ---- Multi searchable select (Survey Type / Surveyor) ----
        function initSearchableMultiSelect(rootEl) {
            const $root = $(rootEl);
            const $trigger = $root.find('.ss-trigger');
            const $triggerText = $root.find('.ss-trigger-text');
            const $panel = $root.find('.ss-panel');
            const $search = $root.find('.ss-search-input');
            const $optionsList = $root.find('.ss-options');
            const hiddenInputId = $root.data('hidden-input') || 'surveyTypeIdsInput';
            const $hiddenInput = $('#' + hiddenInputId);

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
                    openAddNewBox($root);
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
                var hidId = $root.data('hidden-input') || 'surveyTypeIdsInput';
                empty = !$.trim($('#' + hidId).val() || '');
            } else if ($hid.length) {
                empty = !$hid.val();
            }
            if (empty) {
                $txt.text(ph).addClass('placeholder').css({color:'#64748b', fontWeight:'500', fontSize:'14px', opacity:1, visibility:'visible'});
            }
        });


        // Report number appears only after a real client is selected. In edit
        // mode the field already shows the assignment's existing (fixed)
        // number — never overwrite it with a live "next number" preview.
        function refreshReportNumber(clientId) {
            if (EDIT_MODE) return;
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
        // Client role: client_id is a fixed hidden input (not a <select>), so no
        // "change" event ever fires — trigger the report-number preview once on load.
        if ($('#clientSelect').is('input[type=hidden]')) {
            refreshReportNumber($('#clientSelect').val());
        }

        // Outside click closes open panels
        $(document).on('click', function(e) {
            if ($(e.target).closest('.searchable-select').length) return;
            $('.searchable-select.open').removeClass('open');
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') { $('.searchable-select.open').removeClass('open'); }
        });

        // ---- Form submit validation ----
        var duplicateCheckedFor = null; // vessel name already confirmed past the duplicate warning
        $('#assignVesselForm').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const clientVal = String($('#clientSelect').val() || '');
            const portVal = String($('#portSelect').val() || '');
            const typeIdsVal = String($('#surveyTypeIdsInput').val() || '').trim();
            const vesselVal = String($('input[name="vessel_name"]').val() || '').trim();
            const agentVal = String($('input[name="agent_name"]').val() || '').trim();
            const surveyorVal = String($('#surveyorIdsInput').val() || '').trim();
            const surveyorFieldExists = $('#surveyorIdsInput').length > 0;

            const messages = [];

            if (!vesselVal) messages.push('Vessel Name is required.');
            if (!agentVal) messages.push('Agent Name is required.');

            if (!clientVal || clientVal === '0') {
                messages.push('Please select a Client.');
            }

            if (!portVal || portVal === '0') {
                messages.push('Please select a Port.');
            }

            if (!typeIdsVal) {
                messages.push('Please select at least one Survey Type.');
            }

            if (surveyorFieldExists && !surveyorVal) {
                messages.push('Please select a Surveyor.');
            }

            if (messages.length) {
                alert(messages.join('\n'));
                return false;
            }

            function doRealSubmit() {
                const $btn = $form.find('button[type="submit"]');
                $btn.prop('disabled', true).css('opacity', '0.7');
                setTimeout(function() { $btn.prop('disabled', false).css('opacity', '1'); }, 10000);
                $form[0].submit(); // native submit — bypasses this jQuery handler, no re-check loop
            }

            // Editing an existing Pending Vessel entry always "matches" itself by
            // name, so the duplicate warning would otherwise fire on every save —
            // skip it entirely in edit mode.
            if (COMPLETED_MODE) {
                var cm = [];
                var d1 = $('[name=assign_date]').val(), d2 = $('[name=completed_date]').val(), d3 = $('[name=report_date]').val();
                if (!d1 || !d2 || !d3) cm.push('Please enter all three dates.');
                else { if (d2 < d1) cm.push('Survey Completed Date cannot be before the Survey Assigned Date.'); if (d3 < d2) cm.push('Report Completed Date cannot be before the Survey Completed Date.'); }
                if ($('[name=recovery_mode]:checked').val() !== 'na' && !$('[data-ce-qty]').filter(function () { return $.trim(this.value) !== ''; }).length) cm.push('Enter the recovery (VLSFO / HSFO / LSMGO), or choose NOT APPLICABLE.');
                $('[data-ce-qty]').each(function () { var x = $.trim(this.value).replace(/,/g, ''); if (x !== '' && (isNaN(x) || +x < 0)) cm.push($(this).closest('div').find('label').text() + ' must be a number.'); });
                if (cm.length) { alert(cm.join('\n')); return false; }
            }
            if (EDIT_MODE || COMPLETED_MODE || duplicateCheckedFor === vesselVal) {
                doRealSubmit();
                return false;
            }

            // 🌟 Warn if this vessel name already has an entry in Pending Vessels —
            // not a hard block, just a confirmation before creating what may be a
            // duplicate. If the check itself fails, don't block assignment over it.
            const $submitBtn = $form.find('button[type="submit"]');
            $submitBtn.prop('disabled', true).css('opacity', '0.7');
            $.ajax({
                url: 'ajax/check_pending_vessel_duplicate.php',
                method: 'GET',
                data: { vessel_name: vesselVal },
                dataType: 'json'
            }).done(function(resp) {
                if (resp && resp.exists && resp.entry) {
                    $submitBtn.prop('disabled', false).css('opacity', '1');
                    $('#dupVesselName').text(resp.entry.vessel_name || vesselVal);
                    $('#dupReportNumber').text(resp.entry.report_number || 'N/A');
                    $('#dupClientName').text(resp.entry.client_name || 'N/A');
                    $('#dupPortName').text(resp.entry.port_name || 'N/A');
                    $('#dupAssignDate').text(resp.entry.assign_date || 'N/A');
                    const modalEl = document.getElementById('duplicateVesselModal');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                    $('#duplicateVesselConfirmBtn').off('click').on('click', function() {
                        duplicateCheckedFor = vesselVal;
                        modal.hide();
                        doRealSubmit();
                    });
                } else {
                    duplicateCheckedFor = vesselVal;
                    doRealSubmit();
                }
            }).fail(function() {
                doRealSubmit();
            });

            return false;
        });
    });
</script>

<?php if ($completed_mode): ?>
<style>
    .ce-dates { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .ce-dates label, .ce-rec-grid label { display: block; }
    .ce-rec-mode { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
    .ce-pill { display: inline-flex !important; align-items: center; gap: 8px; border: 1px solid #CBD5E1; border-radius: 10px; padding: 9px 14px; cursor: pointer; background: #fff; font-weight: 700 !important; font-size: 13px !important; text-transform: none !important; letter-spacing: 0 !important; color: #334155 !important; margin: 0 !important; }
    .ce-pill:has(input:checked) { border-color: #16a34a; background: #f0fdf4; color: #15803d !important; }
    .ce-pill input { width: auto !important; min-height: 0 !important; margin: 0; accent-color: #16a34a; }
    .ce-rec-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .ce-rec-grid[hidden], .ce-rec-auto[hidden] { display: none !important; }
    .ce-rec-grid .ce-total { background: #f1f5f9 !important; font-weight: 800; color: #15803d !important; }
    .ce-rec-auto { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; border-radius: 10px; padding: 10px 12px; font-size: 12.5px; font-weight: 600; margin-bottom: 4px; }
    .ce-confirm { display: flex !important; align-items: center; gap: 8px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 12px; color: #92400e !important; text-transform: none !important; letter-spacing: 0 !important; font-size: 13px !important; }
    .ce-confirm input { width: auto !important; min-height: 0 !important; }
    @media (max-width: 767.98px) { .ce-dates { grid-template-columns: 1fr; } .ce-rec-grid { grid-template-columns: 1fr 1fr; } }
</style>
<script>
// Completed Vessel Entry: recovery NOT APPLICABLE / enter, live total
(function () {
    var NA_TYPES = <?= json_encode(completedEntryNaTypeIds($survey_types)) ?>;
    var form = document.getElementById('assignVesselForm'); if (!form) return;
    var fields = form.querySelector('[data-ce-rec-fields]'), auto = form.querySelector('[data-testid=ce-rec-auto]'), modes = form.querySelector('.ce-rec-mode');
    function typesAllNa() {
        var ids = (document.getElementById('surveyTypeIdsInput').value || '').split(',').filter(Boolean).map(Number);
        return ids.length > 0 && ids.every(function (i) { return NA_TYPES.indexOf(i) >= 0; });
    }
    function total() {
        var t = 0, any = false;
        form.querySelectorAll('[data-ce-qty]').forEach(function (i) { var v = parseFloat(String(i.value).replace(/,/g, '')); if (!isNaN(v)) { t += v; any = true; } });
        form.querySelector('[data-ce-total]').value = any ? t.toFixed(3) : '';
    }
    function sync() {
        var forced = typesAllNa();
        if (forced) form.querySelector('[name=recovery_mode][value=na]').checked = true;
        modes.hidden = forced; auto.hidden = !forced;
        var na = form.querySelector('[name=recovery_mode]:checked').value === 'na';
        fields.hidden = na;
        form.querySelectorAll('[data-ce-qty]').forEach(function (i) { i.disabled = na; });
        total();
    }
    form.addEventListener('input', function (e) { if (e.target.matches('[data-ce-qty]')) total(); });
    form.addEventListener('change', function (e) { if (e.target.name === 'recovery_mode') sync(); });
    var h = document.getElementById('surveyTypeIdsInput');
    if (h) { var last = h.value; setInterval(function () { if (h.value !== last) { last = h.value; sync(); } }, 300); }
    sync();
})();
</script>
<?php endif; ?>
<?php if (!$is_requester && !$edit_mode && !$completed_mode): ?>
<script>
// 🌟 Paste Email (AI Email -> Vessel Assignment). Separate ready block from
// the one above — purely additive, never touches the existing manual-form
// JS. Only rendered for Admin, and only when creating a new assignment
// (not editing one).
$(document).ready(function() {
    if (!$('#pasteEmailPanel').length) return;

    var lastExtractionMeta = null;
    var currentVessel = null;

    function resetResultUI() {
        $('#pasteEmailError').hide().text('');
        $('#pasteEmailMultiPicker').hide();
        $('#pasteEmailMultiList').empty();
        $('#pasteEmailResult').hide();
        $('#pasteEmailFieldsList').empty();
        $('#pasteEmailMissing').hide().empty();
        currentVessel = null;
    }

    // ---- Tabs ----
    $('#tabBtnManual').on('click', function() {
        $(this).addClass('active');
        $('#tabBtnPasteEmail').removeClass('active');
        $('#manualFormPanel').show();
        $('#pasteEmailPanel').hide();
    });
    $('#tabBtnPasteEmail').on('click', function() {
        $(this).addClass('active');
        $('#tabBtnManual').removeClass('active');
        $('#pasteEmailPanel').show();
        $('#manualFormPanel').hide();
    });

    $('#clearPasteEmailBtn').on('click', function() {
        $('#pasteEmailTextarea').val('');
        resetResultUI();
    });

    function showExtractError(resp) {
        var msg = (resp && resp.message) ? resp.message : 'Unable to extract vessel details from this email. Please try again or use Manual Form.';
        $('#pasteEmailError').text(msg).show();
    }

    function runExtraction() {
        var text = $.trim($('#pasteEmailTextarea').val());
        if (!text) {
            Swal.fire({ icon: 'warning', title: 'Please paste the client email first.', confirmButtonColor: '#3b32b3' });
            return;
        }
        resetResultUI();
        $('#pasteEmailLoading').show();
        $('#extractEmailBtn').prop('disabled', true).css('opacity', '0.7');

        $.ajax({
            url: 'ajax/email_vessel_extract.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ email_text: text }),
            dataType: 'json',
            headers: { 'X-CSRF-Token': CSRF_TOKEN }
        }).done(function(resp) {
            $('#pasteEmailLoading').hide();
            $('#extractEmailBtn').prop('disabled', false).css('opacity', '1');
            if (!resp || !resp.success) { showExtractError(resp); return; }

            lastExtractionMeta = resp;
            var vessels = resp.vessels || [];
            if (!vessels.length) {
                $('#pasteEmailError').text('No vessel information could be found in this email. Please check the content, or use Manual Form.').show();
                return;
            }
            if (vessels.length > 1) {
                renderMultiPicker(vessels);
            } else {
                renderResult(vessels[0]);
            }
        }).fail(function(xhr) {
            $('#pasteEmailLoading').hide();
            $('#extractEmailBtn').prop('disabled', false).css('opacity', '1');
            showExtractError(xhr.responseJSON);
        });
    }

    $('#extractEmailBtn').on('click', runExtraction);
    $('#reExtractBtn').on('click', runExtraction);

    function renderMultiPicker(vessels) {
        $('#pasteEmailMultiPicker').show();
        var $list = $('#pasteEmailMultiList').empty();
        vessels.forEach(function(v, idx) {
            var $item = $('<div class="pe-multi-item"></div>');
            $item.append($('<span></span>').text((idx + 1) + '. ' + (v.vessel_name || 'Unnamed vessel')));
            var $btn = $('<button type="button">Review</button>');
            $btn.on('click', function() { renderResult(v); });
            $item.append($btn);
            $list.append($item);
        });
    }

    var FIELD_LABELS = {
        vessel_name: 'Vessel Name', imo_number: 'IMO Number', client_name: 'Client',
        port_name: 'Port', agent_name: 'Agent', survey_type: 'Survey Type',
        cargo_name: 'Cargo', cargo_quantity: 'Quantity', eta: 'ETA', etd: 'ETD',
        berth: 'Berth', voyage_number: 'Voyage No.', bill_of_lading: 'Bill of Lading',
        terminal_name: 'Terminal', master_name: 'Master', agent_email: 'Agent Email',
        agent_phone: 'Agent Phone', client_email: 'Client Email'
    };
    var DISPLAY_ORDER = ['vessel_name', 'imo_number', 'client_name', 'port_name', 'agent_name', 'survey_type',
        'cargo_name', 'cargo_quantity', 'eta', 'etd', 'berth', 'voyage_number', 'bill_of_lading',
        'terminal_name', 'master_name', 'agent_email', 'agent_phone', 'client_email'];

    function renderResult(vessel) {
        currentVessel = vessel;
        $('#pasteEmailMultiPicker').hide();
        $('#pasteEmailResult').show();

        var conf = lastExtractionMeta && lastExtractionMeta.extraction_confidence !== null && lastExtractionMeta.extraction_confidence !== undefined
            ? lastExtractionMeta.extraction_confidence : null;
        $('#pasteEmailConfidence').text(conf !== null ? ('AI Confidence: ' + conf + '%') : '');

        var $fields = $('#pasteEmailFieldsList').empty();
        var uncertainSet = vessel.uncertain_fields || [];
        // Fields backed by a searchable-select dropdown — show what will
        // actually get SELECTED (the fuzzy-matched real row), not just the
        // raw text the AI read, so a wrong match is visible before the
        // admin clicks "Review & Assign Vessel" rather than hidden behind a
        // plain "✓ AI" badge. matched_*_score is 0-100; a match on a
        // different-looking name than what was extracted gets a "Check"
        // badge even when the AI itself was confident about the text.
        var MATCH_FIELDS = {
            client_name: { idKey: 'matched_client_id', labelKey: 'matched_client_label', scoreKey: 'matched_client_score' },
            port_name: { idKey: 'matched_port_id', labelKey: 'matched_port_label', scoreKey: 'matched_port_score' }
        };
        DISPLAY_ORDER.forEach(function(key) {
            var val = vessel[key] || '';
            var uncertain = uncertainSet.indexOf(key) !== -1;
            var $row = $('<div class="pe-field-row"></div>');
            $row.append($('<span class="pe-field-label"></span>').text(FIELD_LABELS[key] || key));

            var match = MATCH_FIELDS[key];
            var valueText = val || 'Not found';
            var badgeWarn = uncertain;
            if (match && val) {
                var matchedId = vessel[match.idKey];
                var matchedLabel = vessel[match.labelKey];
                var matchedScore = vessel[match.scoreKey];
                if (matchedId && matchedLabel) {
                    var differs = matchedLabel.toLowerCase().trim() !== val.toLowerCase().trim();
                    valueText = val + (differs ? ('  →  will select: ' + matchedLabel + ' (' + matchedScore + '%)') : '');
                    if (differs) badgeWarn = true;
                } else {
                    valueText = val + '  —  not found in existing list, select manually';
                    badgeWarn = true;
                }
            }

            $row.append($('<span></span>').addClass('pe-field-value' + (val ? '' : ' empty')).text(valueText));
            if (val) {
                $row.append($('<span></span>').addClass('pe-field-badge ' + (badgeWarn ? 'warn' : 'ok')).text(badgeWarn ? '⚠ Check' : '✓ AI'));
            }
            $fields.append($row);
        });

        var missing = vessel.missing_fields || [];
        var $m = $('#pasteEmailMissing');
        if (missing.length) {
            $m.empty().show().append('<strong>Missing information:</strong>');
            missing.forEach(function(f) {
                $m.append($('<span class="pe-missing-item"></span>').text('⚠ ' + (FIELD_LABELS[f] || f)));
            });
        } else {
            $m.hide().empty();
        }
    }

    $('#backPasteAnotherBtn').on('click', function() {
        $('#pasteEmailTextarea').val('');
        resetResultUI();
    });

    function markAiFilled($input) {
        if (!$input || !$input.length) return;
        $input.addClass('ai-filled');
        var $label = $input.closest('.form-group-custom').find('label').first();
        $label.find('.ai-badge').remove();
        $label.append('<span class="ai-badge">✓ AI</span>');
        $input.one('input change', function() {
            $(this).removeClass('ai-filled');
            $(this).closest('.form-group-custom').find('.ai-badge').remove();
        });
    }

    // Reuses the existing searchable-select widgets exactly as a real click
    // would (no duplicate selection logic) — finds the matching <li
    // data-value="..."> inside that widget's own option list and triggers
    // a real click on it, which runs the widget's own selectOption()/
    // toggleOption() and keeps the hidden select/input perfectly in sync.
    function clickOption($root, value) {
        if (!$root.length || value === null || value === undefined || value === '') return false;
        var $opt = $root.find('.ss-options .ss-option[data-value="' + value + '"]');
        if ($opt.length) { $opt.trigger('click'); return true; }
        return false;
    }

    function applyAiVesselToForm(v) {
        // 🌟 IMPORTANT: assign_vessel.php's existing form is reused exactly
        // as-is — no new fields. Only vessel_name / client / agent_name /
        // port / survey type / remarks are real existing fields, so those
        // are the only ones this function ever writes into. Everything else
        // the AI found (imo_number, cargo, ETA, berth, voyage number, bill
        // of lading, master name, agent/client email/phone) has no existing
        // field to go into, so it's folded into the existing Remarks
        // textarea below instead — never dropped, never given a new input.
        if (v.vessel_name) { $('#vessel_name').val(v.vessel_name); markAiFilled($('#vessel_name')); }
        if (v.agent_name) { $('input[name="agent_name"]').val(v.agent_name); markAiFilled($('input[name="agent_name"]')); }

        if (v.matched_client_id) {
            var $clientRoot = $('[data-ss-root="client"]');
            if (clickOption($clientRoot, v.matched_client_id)) markAiFilled($clientRoot.find('.ss-trigger'));
        }

        if (v.matched_port_id) {
            var $portRoot = $('[data-ss-root="port"]');
            if (clickOption($portRoot, v.matched_port_id)) markAiFilled($portRoot.find('.ss-trigger'));
        }

        if (v.matched_survey_type_ids && v.matched_survey_type_ids.length) {
            var $stRoot = $('[data-ss-root="surveyType"]');
            // Clear anything already selected first (e.g. from reviewing a
            // different vessel a moment ago) so only THIS vessel's matched
            // types end up checked.
            $stRoot.find('.ss-options .ss-option.ss-selected').each(function() { $(this).trigger('click'); });
            var anyApplied = false;
            v.matched_survey_type_ids.forEach(function(id) { if (clickOption($stRoot, id)) anyApplied = true; });
            if (anyApplied) markAiFilled($stRoot.find('.ss-trigger'));
        }

        // Everything with no real form field (per the request's own "don't
        // duplicate existing DB fields" instruction) is folded into Remarks
        // so none of it is lost, clearly labeled as coming from the email.
        var extraMap = {
            'IMO Number': v.imo_number,
            'Terminal': v.terminal_name, 'Cargo': v.cargo_name,
            'Cargo Quantity': [v.cargo_quantity, v.cargo_unit].filter(Boolean).join(' '),
            'ETA': v.eta, 'ETD': v.etd, 'Survey Date': v.survey_date, 'Survey Time': v.survey_time,
            'Berth': v.berth, 'Voyage No.': v.voyage_number, 'Bill of Lading': v.bill_of_lading,
            'Master': v.master_name, 'Agent Email': v.agent_email, 'Agent Phone': v.agent_phone,
            'Client Email': v.client_email
        };
        var extraLines = [];
        Object.keys(extraMap).forEach(function(label) {
            if (extraMap[label]) extraLines.push(label + ': ' + extraMap[label]);
        });
        if (v.remarks) extraLines.push(v.remarks);
        if (extraLines.length) {
            var $remarks = $('textarea[name="remarks"]');
            var existing = $.trim($remarks.val());
            var block = '[From client email — AI extracted]\n' + extraLines.join('\n');
            $remarks.val(existing ? (existing + '\n\n' + block) : block);
            markAiFilled($remarks);
        }

        if (v.client_name && !v.matched_client_id) {
            Swal.fire({ icon: 'info', title: 'Client "' + v.client_name + '" was not found in the existing client list — please select it manually.', confirmButtonColor: '#3b32b3' });
        } else if (v.port_name && !v.matched_port_id) {
            Swal.fire({ icon: 'info', title: 'Port "' + v.port_name + '" was not found in the existing port list — please select it manually.', confirmButtonColor: '#3b32b3' });
        }
    }

    $('#reviewAssignBtn').on('click', function() {
        if (!currentVessel) return;
        applyAiVesselToForm(currentVessel);
        $('#tabBtnManual').trigger('click');
        Swal.fire({ icon: 'success', title: 'Form filled from email — please review before assigning.', confirmButtonColor: '#3b32b3', timer: 2000, showConfirmButton: false });
        setTimeout(function() {
            try { document.getElementById('manualFormPanel').scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }, 150);
    });
});
</script>
<?php endif; ?>

<?php
include 'includes/nav.php';
include 'includes/footer.php';
?>
