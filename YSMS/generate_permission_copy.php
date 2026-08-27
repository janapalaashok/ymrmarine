<?php
require_once 'config/config.php';
checkAuth();

$db = getDB();
$role = $_SESSION['role'] ?? '';

// 🌟 7. Generate Permission Copy
// ----------------------------------------------------------------------
// ఈ పేజీలో యూజర్ ఒక ఫారమ్ నింపి (Date, Port, To details, Vessel Name, Berth No,
// Survey Type, Surveyors' Name/Age/Aadhar) "Generate" నొక్కితే, అడ్మిన్
// ముందుగా అప్‌లోడ్ చేసిన ప్రీ-డిఫైన్డ్ Word (.docx) టెంప్లేట్‌లో ఉన్న
// ప్లేస్‌హోల్డర్లను ఈ వివరాలతో నింపి, పూర్తయిన Word ఫైల్‌ను డౌన్‌లోడ్ చేస్తుంది.
//
// టెంప్లేట్ .docx ఫైల్‌లో ఈ ప్లేస్‌హోల్డర్లను వాడాలి (కర్లీ బ్రేసెస్‌తో సహా):
//   {{DATE}}  {{PORT}}
//   {{RECIPIENT_DESIGNATION}}  {{RECIPIENT_COMPANY}}  {{RECIPIENT_LOCATION}}
//   {{VESSEL_NAME}}  {{BERTH_NO}}  {{SURVEY_TYPE}}
//   {{SURVEYOR_1_NAME}} {{SURVEYOR_1_AGE}} {{SURVEYOR_1_AADHAR}}
//   {{SURVEYOR_2_NAME}} {{SURVEYOR_2_AGE}} {{SURVEYOR_2_AADHAR}}
//   {{SURVEYOR_3_NAME}} {{SURVEYOR_3_AGE}} {{SURVEYOR_3_AADHAR}}
//   {{SURVEYOR_4_NAME}} {{SURVEYOR_4_AGE}} {{SURVEYOR_4_AADHAR}}
// (Surveyor slots optional - యూజర్ ఎంతమందిని నింపితే అంతమందికే లైన్లు కనిపిస్తాయి,
//  ఖాళీగా ఉన్న స్లాట్ల లిస్ట్ లైన్ మొత్తం టెంప్లేట్ నుండి తీసివేయబడుతుంది.)
// ----------------------------------------------------------------------

$MAX_SURVEYORS = 4;

$template_dir = __DIR__ . '/uploads/permission_templates';
if (!is_dir($template_dir)) {
    mkdir($template_dir, 0755, true);
}

$upload_error = '';
$upload_success = false;

// --- Admin: Upload / Replace the predefined Word template ---------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_template']) && $role === 'Admin') {
    if (!isset($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_error = 'Please select a Word (.docx) template to upload.';
    } else {
        $original_name = basename($_FILES['template_file']['name']);
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if ($extension !== 'docx') {
            $upload_error = 'Only Word (.docx) files are allowed for the template.';
        } else {
            // ఎప్పుడూ ఒకే active template ఉండేలా - పాత టెంప్లేట్‌లను తీసివేసి కొత్తది సేవ్ చేయడం
            foreach (glob($template_dir . '/*.docx') as $old_file) {
                @unlink($old_file);
            }
            $destination = $template_dir . '/permission_copy_template_' . time() . '.docx';
            if (move_uploaded_file($_FILES['template_file']['tmp_name'], $destination)) {
                $upload_success = true;
            } else {
                $upload_error = 'The template could not be uploaded. Please try again.';
            }
        }
    }
}

// --- Find the currently active template ---------------------------------
$active_template = '';
$templates_found = glob($template_dir . '/*.docx');
if (!empty($templates_found)) {
    rsort($templates_found);
    $active_template = $templates_found[0];
}

// --- Generate: fill placeholders in the template and stream the file ----
$generate_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_copy'])) {
    if (empty($active_template) || !is_file($active_template)) {
        $generate_error = 'No predefined Word template has been uploaded yet. Please ask Admin to upload one first.';
    } else {
        $p_date = trim($_POST['permission_date'] ?? '');
        $p_port = trim($_POST['permission_port'] ?? '');
        $p_recipient_designation = trim($_POST['recipient_designation'] ?? '');
        $p_recipient_company = trim($_POST['recipient_company'] ?? '');
        $p_recipient_location = trim($_POST['recipient_location'] ?? '');
        $p_vessel = trim($_POST['vessel_name'] ?? '');
        $p_berth_no = trim($_POST['berth_no'] ?? '');
        $p_survey_type = trim($_POST['survey_type'] ?? '');

        // --- Surveyors: Name / Age / Aadhar, up to $MAX_SURVEYORS rows -----
        $p_surveyors = [];
        if (isset($_POST['surveyor_name'])) {
            $names = array_map('trim', (array)$_POST['surveyor_name']);
            $ages = array_map('trim', (array)($_POST['surveyor_age'] ?? []));
            $aadhars = array_map('trim', (array)($_POST['surveyor_aadhar'] ?? []));
            foreach ($names as $i => $name) {
                if ($name === '') continue;
                $p_surveyors[] = [
                    'name'   => $name,
                    'age'    => $ages[$i] ?? '',
                    'aadhar' => $aadhars[$i] ?? '',
                ];
                if (count($p_surveyors) >= $MAX_SURVEYORS) break;
            }
        }

        if (empty($p_date) || empty($p_port) || empty($p_recipient_designation) ||
            empty($p_recipient_company) || empty($p_recipient_location) ||
            empty($p_vessel) || empty($p_berth_no) || empty($p_survey_type)) {
            $generate_error = 'Please fill Date, Port, To details (Designation, Company, Location), Vessel Name, Berth No and Survey Type before generating.';
        } else {
            $formatted_date = date('d M Y', strtotime($p_date)) ?: $p_date;
            $replacements = [
                '{{DATE}}'                   => $formatted_date,
                '{{PORT}}'                   => $p_port,
                '{{RECIPIENT_DESIGNATION}}'  => $p_recipient_designation,
                '{{RECIPIENT_COMPANY}}'      => $p_recipient_company,
                '{{RECIPIENT_LOCATION}}'     => $p_recipient_location,
                '{{VESSEL_NAME}}'            => $p_vessel,
                '{{BERTH_NO}}'               => $p_berth_no,
                '{{SURVEY_TYPE}}'            => $p_survey_type,
            ];

            // నింపిన surveyor స్లాట్‌లకు placeholder value లు, నింపని వాటికి
            // ఆ లిస్ట్ లైన్ మొత్తం తీసివేయడానికి స్లాట్ నంబర్లు సేకరించడం
            $empty_surveyor_slots = [];
            for ($i = 1; $i <= $MAX_SURVEYORS; $i++) {
                if (isset($p_surveyors[$i - 1])) {
                    $replacements['{{SURVEYOR_' . $i . '_NAME}}']   = $p_surveyors[$i - 1]['name'];
                    $replacements['{{SURVEYOR_' . $i . '_AGE}}']    = $p_surveyors[$i - 1]['age'];
                    $replacements['{{SURVEYOR_' . $i . '_AADHAR}}'] = $p_surveyors[$i - 1]['aadhar'];
                } else {
                    $empty_surveyor_slots[] = $i;
                }
            }

            $gen_result = generatePermissionCopyDocx($active_template, $replacements, $empty_surveyor_slots);
            if ($gen_result['success'] === false) {
                // 🌟 నిజమైన కారణం చూపించడం (temp folder permission, invalid zip, etc.)
                // వల్ల డీబగ్ చేయడం సులభం అవుతుంది - generic మెసేజ్ కాకుండా.
                $generate_error = 'Could not generate the Word document: ' . $gen_result['error'];
            } else {
                $generated_path = $gen_result['path'];
                $download_name = 'Permission Copy - ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $p_vessel) . ' - ' . date('d-m-Y', strtotime($p_date)) . '.docx';
                header('Content-Description: File Transfer');
                header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                header('Content-Disposition: attachment; filename="' . $download_name . '"');
                header('Content-Length: ' . filesize($generated_path));
                header('Cache-Control: must-revalidate');
                readfile($generated_path);
                @unlink($generated_path);
                exit;
            }
        }
    }
}

/**
 * 🌟 అప్‌లోడ్ చేసిన .docx టెంప్లేట్‌ను తీసుకుని, లోపల ఉన్న {{PLACEHOLDER}} లను
 * ఇచ్చిన విలువలతో మార్చి, తాత్కాలిక కాపీని తయారు చేసి దాని పాత్‌ను తిరిగి ఇస్తుంది.
 * (docx అంటే లోపల XML ఫైళ్లు ఉన్న ఒక Zip ఫైల్ మాత్రమే - కాబట్టి ZipArchive +
 * సాధారణ టెక్స్ట్ replace తో ఇది సాధ్యమే, ఏ ఎక్స్‌టర్నల్ లైబ్రరీ అవసరం లేదు.)
 *
 * @param string $template_path        active .docx template path
 * @param array  $replacements         {{PLACEHOLDER}} => value మ్యాప్
 * @param int[]  $empty_surveyor_slots నింపని surveyor స్లాట్ నంబర్లు (1-4) -
 *                                     వీటికి సంబంధించిన లిస్ట్ లైన్ (paragraph)
 *                                     మొత్తం తీసివేయబడుతుంది.
 */
function generatePermissionCopyDocx($template_path, $replacements, $empty_surveyor_slots = []) {
    // 🌟 sys_get_temp_dir() (సాధారణంగా /tmp) కొన్ని shared hosting సర్వర్లలో
    //    open_basedir రిస్ట్రిక్షన్ వల్ల యాక్సెస్ కాదు - అందుకే PHP దాన్ని
    //    "Could not generate..." అని ఏ కారణం చెప్పకుండా false ఇచ్చేస్తుంది.
    //    దాని బదులు మన uploads ఫోల్డర్ లోపలే ఒక writable temp సబ్‌ఫోల్డర్ వాడటం.
    $temp_dir = dirname($template_path, 2) . '/tmp_generated';
    if (!is_dir($temp_dir)) {
        @mkdir($temp_dir, 0755, true);
    }
    if (!is_dir($temp_dir) || !is_writable($temp_dir)) {
        return ['success' => false, 'error' => "Temp folder '$temp_dir' is missing or not writable by PHP. Please create it and give it write permission (chmod 755/775)."];
    }

    if (!is_readable($template_path)) {
        return ['success' => false, 'error' => "Template file '$template_path' is not readable by PHP (check file permissions)."];
    }

    $temp_path = $temp_dir . '/permission_copy_' . uniqid() . '.docx';
    if (!copy($template_path, $temp_path)) {
        $err = error_get_last();
        return ['success' => false, 'error' => 'Failed to copy the template file to a working location. ' . ($err['message'] ?? '')];
    }

    if (!class_exists('ZipArchive')) {
        @unlink($temp_path);
        return ['success' => false, 'error' => "PHP's 'zip' extension is not enabled on this server. Please ask your host / server admin to enable php-zip (ext-zip), then restart PHP."];
    }

    $zip = new ZipArchive();
    $open_result = $zip->open($temp_path);
    if ($open_result !== true) {
        @unlink($temp_path);
        return ['success' => false, 'error' => "The template file could not be opened as a .docx/zip archive (ZipArchive error code: $open_result). Please re-upload a valid .docx template (open it in Word and 'Save As' .docx again if unsure)."];
    }

    $xml_targets = ['word/document.xml', 'word/header1.xml', 'word/footer1.xml'];
    $found_document_xml = false;
    foreach ($xml_targets as $entry) {
        $content = $zip->getFromName($entry);
        if ($content === false) continue;
        if ($entry === 'word/document.xml') $found_document_xml = true;

        // 🌟 Word కొన్నిసార్లు ఒకే placeholder ను బహుళ <w:t> రన్‌ల మధ్య విడగొడుతుంది.
        //    కాబట్టి <w:t> tags మధ్య ఉన్న markup ను తీసేసి, తర్వాత replace చేస్తున్నాం.
        $content = preg_replace('/(\{\{)\s*<\/w:t>.*?<w:t[^>]*>\s*/s', '$1', $content) ?? $content;
        $content = preg_replace('/\s*<\/w:t>.*?<w:t[^>]*>\s*(\}\})/s', '$1', $content) ?? $content;

        // 🌟 నింపని surveyor స్లాట్‌ల కోసం, ఆ placeholder ఉన్న <w:p>...</w:p>
        //    పేరాగ్రాఫ్ మొత్తాన్ని తొలగించడం (ఖాళీ బుల్లెట్ లైన్ కనిపించకుండా)
        foreach ($empty_surveyor_slots as $slot_num) {
            $content = preg_replace(
                '/<w:p\b(?:(?!<\/w:p>).)*?\{\{SURVEYOR_' . $slot_num . '_NAME\}\}(?:(?!<\/w:p>).)*?<\/w:p>/s',
                '',
                $content
            ) ?? $content;
        }

        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
        $zip->addFromString($entry, $content);
    }
    $zip->close();

    if (!$found_document_xml) {
        @unlink($temp_path);
        return ['success' => false, 'error' => "The uploaded file does not look like a valid .docx (word/document.xml missing). Please re-upload a proper .docx template."];
    }

    return ['success' => true, 'path' => $temp_path, 'error' => ''];
}

$survey_types_list = $db->query("SELECT id, type_name FROM survey_types ORDER BY type_name ASC")->fetchAll();
$surveyors_list = $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND status = 'Active' ORDER BY full_name ASC")->fetchAll();

include 'includes/header.php';
?>
<style>
    .pc-page { padding: 22px 18px 110px; }
    .pc-heading { font-size: 20px; font-weight: 750; color: var(--text-dark); margin: 0 0 6px; }
    .pc-subtitle { color: var(--text-muted); font-size: 12.5px; margin-bottom: 18px; }
    .pc-card { background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 18px; margin-bottom: 16px; box-shadow: 0 4px 12px rgba(15,23,42,.04); }
    .pc-section-label { font-size: 12.5px; font-weight: 700; color: var(--text-dark); margin: 4px 0 10px; }
    .pc-label { font-size: 12px; font-weight: 650; color: var(--text-muted); margin-bottom: 5px; display: block; }
    .pc-control { width: 100%; border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 12px; font-size: 13.5px; background: #f8fafc; outline: none; color: var(--text-dark); margin-bottom: 14px; }
    .pc-control:focus { border-color: var(--accent-purple); background: #fff; }
    .pc-multi-row { display: flex; gap: 8px; margin-bottom: 8px; }
    .pc-multi-row input, .pc-multi-row select { flex: 1; }
    .pc-multi-remove { flex: 0 0 auto; border: 1px solid #fca5a5; background: #fff; color: #dc2626; border-radius: 8px; width: 38px; }
    .pc-add-more { border: 1px dashed var(--accent-purple); background: #f5f4ff; color: var(--accent-purple); border-radius: 8px; padding: 7px 12px; font-size: 12px; font-weight: 650; margin-bottom: 14px; }
    .pc-add-more:disabled { opacity: .5; }
    .pc-generate-btn { background: var(--accent-purple); color: #fff; border: none; width: 100%; padding: 12px; border-radius: 10px; font-size: 14px; font-weight: 650; }
    .pc-template-status { display: flex; align-items: center; gap: 10px; font-size: 12.5px; padding: 10px 12px; border-radius: 10px; background: #f8fafc; border: 1px solid var(--border-color); margin-bottom: 12px; }
    .pc-upload-form { display: flex; gap: 8px; }
    .pc-upload-form input[type="file"] { flex: 1; font-size: 12px; }
    .pc-upload-form button { white-space: nowrap; border: 0; border-radius: 9px; padding: 9px 14px; background: #1e3a8a; color: #fff; font-size: 12px; font-weight: 650; }
    @media (max-width: 520px) { .pc-upload-form { flex-direction: column; } }
</style>
<div class="scroll-content">
    <?php $page_title = 'Generate Permission Copy'; $back_url = 'index.php'; $page_testid = 'generate-permission-copy'; include 'includes/top_app_bar.php'; ?>
    <main class="pc-page" data-testid="generate-permission-copy-page">
        <h2 class="pc-heading">Generate Permission Copy</h2>
        <p class="pc-subtitle">Fill the details below to generate a permission copy Word document from the predefined template.</p>

        <?php if ($role === 'Admin'): ?>
        <section class="pc-card" data-testid="permission-template-upload-panel">
            <div class="fw-bold text-dark mb-2" style="font-size:13px;"><i class="fa-solid fa-file-word text-primary me-1"></i> Predefined Word Template</div>
            <div class="pc-template-status" data-testid="permission-template-status">
                <?php if ($active_template): ?>
                    <i class="fa-solid fa-circle-check text-success"></i> Template uploaded: <strong><?= sanitize(basename($active_template)) ?></strong>
                <?php else: ?>
                    <i class="fa-solid fa-triangle-exclamation text-warning"></i> No template uploaded yet.
                <?php endif; ?>
            </div>
            <?php if ($upload_error): ?><div class="alert alert-danger py-2" style="font-size:12px;" data-testid="permission-template-upload-error"><?= sanitize($upload_error) ?></div><?php endif; ?>
            <form method="POST" enctype="multipart/form-data" class="pc-upload-form" data-testid="permission-template-upload-form"><?= csrf_field() ?>
                <input type="file" name="template_file" accept=".docx" required class="form-control" data-testid="permission-template-file-input">
                <button type="submit" name="upload_template" data-testid="permission-template-upload-submit"><i class="fa-solid fa-upload me-1"></i> Upload / Replace</button>
            </form>
            <div class="pc-field-hint text-muted mt-2" style="font-size:11px;">
                Template must contain placeholders:
                <code>{{DATE}}</code>, <code>{{PORT}}</code>,
                <code>{{RECIPIENT_DESIGNATION}}</code>, <code>{{RECIPIENT_COMPANY}}</code>, <code>{{RECIPIENT_LOCATION}}</code>,
                <code>{{VESSEL_NAME}}</code>, <code>{{BERTH_NO}}</code>, <code>{{SURVEY_TYPE}}</code>,
                and for each surveyor (1 to <?= (int)$MAX_SURVEYORS ?>):
                <code>{{SURVEYOR_1_NAME}}</code>, <code>{{SURVEYOR_1_AGE}}</code>, <code>{{SURVEYOR_1_AADHAR}}</code> ... up to <code>{{SURVEYOR_<?= (int)$MAX_SURVEYORS ?>_AADHAR}}</code>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($generate_error): ?><div class="alert alert-danger py-2" style="font-size:12px;" data-testid="permission-generate-error"><?= sanitize($generate_error) ?></div><?php endif; ?>

        <form method="POST" class="pc-card" id="permissionCopyForm" data-testid="permission-copy-form"><?= csrf_field() ?>
            <div>
                <label class="pc-label">Date *</label>
                <input type="date" name="permission_date" class="pc-control" required value="<?= date('Y-m-d') ?>" data-testid="permission-date-input">
            </div>
            <div>
                <label class="pc-label">Port / Place *</label>
                <input type="text" name="permission_port" class="pc-control" placeholder="e.g. Visakhapatnam" required data-testid="permission-port-input">
            </div>

            <div class="pc-section-label">To</div>
            <div>
                <label class="pc-label">Designation *</label>
                <input type="text" name="recipient_designation" class="pc-control" placeholder="e.g. The Superintendent of the customs" required data-testid="permission-recipient-designation-input">
            </div>
            <div>
                <label class="pc-label">Company / Port Authority *</label>
                <input type="text" name="recipient_company" class="pc-control" placeholder="e.g. Adani Gangavaram Port Limited" required data-testid="permission-recipient-company-input">
            </div>
            <div>
                <label class="pc-label">Location *</label>
                <input type="text" name="recipient_location" class="pc-control" placeholder="e.g. Gangavaram, Visakhapatnam." required data-testid="permission-recipient-location-input">
            </div>

            <div>
                <label class="pc-label">Vessel Name *</label>
                <input type="text" name="vessel_name" class="pc-control" placeholder="Enter vessel name" required data-testid="permission-vessel-input">
            </div>
            <div>
                <label class="pc-label">Berth No *</label>
                <input type="text" name="berth_no" class="pc-control" placeholder="e.g. B-6" required data-testid="permission-berth-input">
            </div>

            <div>
                <label class="pc-label">Survey Type *</label>
                <select name="survey_type" class="pc-control" required data-testid="permission-survey-type-select">
                    <option value="">Select survey type</option>
                    <?php foreach ($survey_types_list as $st): ?>
                        <option value="<?= sanitize($st['type_name']) ?>"><?= sanitize($st['type_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="pc-label">Surveyors (up to <?= (int)$MAX_SURVEYORS ?>, optional)</label>
                <div id="surveyorFieldsContainer" data-testid="permission-surveyor-fields"></div>
                <button type="button" class="pc-add-more" id="addSurveyorFieldBtn" data-testid="permission-add-surveyor-button"><i class="fa-solid fa-plus me-1"></i> Add Surveyor</button>
            </div>

            <button type="submit" name="generate_copy" class="pc-generate-btn mt-2" data-testid="permission-generate-button"><i class="fa-solid fa-file-arrow-down me-1"></i> Generate &amp; Download</button>
        </form>
    </main>
</div>

<script>
    const surveyorOptions = <?= json_encode(array_map(function($s){ return $s['full_name']; }, $surveyors_list)) ?>;
    const MAX_SURVEYORS = <?= (int)$MAX_SURVEYORS ?>;

    function updateAddSurveyorButtonState() {
        const rowCount = document.getElementById('surveyorFieldsContainer').children.length;
        document.getElementById('addSurveyorFieldBtn').disabled = rowCount >= MAX_SURVEYORS;
    }

    function addSurveyorField() {
        const container = document.getElementById('surveyorFieldsContainer');
        if (container.children.length >= MAX_SURVEYORS) return;

        const wrap = document.createElement('div');
        wrap.className = 'pc-multi-row';
        let optionsHtml = '<option value="">Select surveyor</option>';
        surveyorOptions.forEach(function(name) {
            optionsHtml += '<option value="' + name.replace(/"/g, '&quot;') + '">' + name + '</option>';
        });
        wrap.innerHTML =
            '<select name="surveyor_name[]" class="pc-control" style="margin-bottom:0;">' + optionsHtml + '</select>' +
            '<input type="text" name="surveyor_age[]" class="pc-control" style="margin-bottom:0; max-width:80px;" placeholder="Age">' +
            '<input type="text" name="surveyor_aadhar[]" class="pc-control" style="margin-bottom:0;" placeholder="Aadhar No.">' +
            '<button type="button" class="pc-multi-remove" onclick="this.parentElement.remove(); updateAddSurveyorButtonState();"><i class="fa-solid fa-xmark"></i></button>';
        container.appendChild(wrap);
        updateAddSurveyorButtonState();
    }

    document.getElementById('addSurveyorFieldBtn').addEventListener('click', addSurveyorField);
    // ప్రతి ఫారమ్‌లో కనీసం ఒక్క (optional) row అయినా కనిపించేలా మొదటగా ఒక్కటి యాడ్ చేయడం
    addSurveyorField();
</script>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
