<?php
// 🌟 report_generator.php లోని "Select Excel File" & "Select Word Template"
// సెర్చబుల్ డ్రాప్‌డౌన్‌ల కోసం డేటా అందించే AJAX ఎండ్‌పాయింట్ (రీడ్-ఓన్లీ).
// ఇది ఎగ్జిస్టింగ్ రిపోర్ట్ జనరేషన్ లాజిక్ ను ఏమాత్రం మార్చదు — కేవలం ఫైల్ సోర్స్ మాత్రమే మారుస్తుంది.
require_once __DIR__ . '/../config/config.php';
checkAuth();

header('Content-Type: application/json');

$db = getDB();
$action = $_GET['action'] ?? '';

// 🌟 `uploads` టేబుల్ లో ఏదైనా టైమ్‌స్టాంప్ కాలమ్ ఉందేమో డైనమిక్‌గా చెక్ చేసే హెల్పర్
// (existing table structure ఎలా ఉన్నా క్రాష్ కాకుండా ఉండటానికి)
function detect_upload_date_column($db) {
    static $col = null;
    if ($col !== null) return $col;
    $candidates = ['created_at', 'uploaded_at', 'upload_date', 'date_uploaded', 'created'];
    try {
        $existing = [];
        $rows = $db->query("SHOW COLUMNS FROM uploads")->fetchAll();
        foreach ($rows as $r) $existing[] = $r['Field'];
        foreach ($candidates as $c) {
            if (in_array($c, $existing, true)) { $col = $c; return $col; }
        }
    } catch (Exception $e) {}
    $col = false;
    return $col;
}

// 🌟 `surveys` టేబుల్ లో ఒక కాలమ్ ఉందో లేదో చెక్ చేసే హెల్పర్ (imo_number, survey_type_ids
// లాంటివి వేర్వేరు మైగ్రేషన్ల ద్వారా యాడ్ అయ్యేవి — లైవ్ DB లో ఇంకా రన్ కాకపోతే క్రాష్ కాకుండా)
function has_surveys_column($db, $column_name) {
    static $existing = null;
    if ($existing === null) {
        $existing = [];
        try {
            $rows = $db->query("SHOW COLUMNS FROM surveys")->fetchAll();
            foreach ($rows as $r) $existing[] = $r['Field'];
        } catch (Exception $e) {}
    }
    return in_array($column_name, $existing, true);
}

function json_out($data) {
    echo json_encode($data);
    exit;
}

if ($action === 'list_excels') {
    // Report Generator లో వాడేందుకు అందుబాటులో ఉన్న అన్ని అప్‌లోడ్ చేసిన Excel ఫైల్స్
    // (Pending Vessel దశలో అప్‌లోడ్ అయిన 'Formal Report Excel' ఎంట్రీలు)
    $date_col = detect_upload_date_column($db);
    $date_select = $date_col ? "u.`$date_col` AS upload_date" : "NULL AS upload_date";

    // 🌟 ఐచ్ఛిక కాలమ్‌లు (వేర్వేరు మైగ్రేషన్ల ద్వారా యాడ్ అయ్యేవి) లైవ్ DB లో ఉన్నా లేకపోయినా
    // క్వెరీ క్రాష్ కాకుండా డైనమిక్‌గా బిల్డ్ చేయడం
    $has_imo = has_surveys_column($db, 'imo_number');
    $has_type_ids = has_surveys_column($db, 'survey_type_ids');
    $imo_select = $has_imo ? "s.imo_number" : "NULL AS imo_number";
    $type_ids_select = $has_type_ids ? "s.survey_type_ids" : "NULL AS survey_type_ids";

    // 🌟 Admin కి అన్ని Excel ఫైల్స్ కనిపిస్తాయి. Surveyor (లేదా ఇతర రోల్) కి తనకు
    // assign అయిన సర్వేల Excel ఫైల్స్ మాత్రమే కనిపిస్తాయి.
    $is_admin = (($_SESSION['role'] ?? '') === 'Admin');

    try {
        $sql = "
            SELECT u.id AS upload_id, u.file_name, u.file_path, $date_select,
                   s.id AS survey_id, s.vessel_name, $imo_select, $type_ids_select,
                   st.type_name
            FROM uploads u
            JOIN surveys s ON u.survey_id = s.id
            LEFT JOIN survey_types st ON s.survey_type_id = st.id
            WHERE u.file_type = 'Formal Report Excel'
            " . ($is_admin ? "" : "AND s.surveyor_id = :uid") . "
            ORDER BY u.id DESC
        ";
        $stmt = $db->prepare($sql);
        if (!$is_admin) {
            $stmt->bindValue(':uid', $_SESSION['user_id'] ?? 0, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'upload_id'   => (int)$r['upload_id'],
                'survey_id'   => (int)$r['survey_id'],
                'vessel_name' => $r['vessel_name'],
                'imo_number'  => $r['imo_number'],
                'survey_type' => getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? ''),
                'upload_date' => $r['upload_date'],
                'file_name'   => $r['file_name'],
            ];
        }
        json_out(['success' => true, 'data' => $out]);
    } catch (Exception $e) {
        error_log('report_generator_data.php list_excels error: ' . $e->getMessage());
        json_out(['success' => false, 'message' => 'Database error while loading Excel files.']);
    }
}

if ($action === 'get_excel') {
    $upload_id = (int)($_GET['upload_id'] ?? 0);
    if ($upload_id <= 0) {
        json_out(['success' => false, 'message' => 'No Excel selected.']);
    }
    try {
        $stmt = $db->prepare("SELECT file_path FROM uploads WHERE id = ? AND file_type = 'Formal Report Excel'");
        $stmt->execute([$upload_id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_out(['success' => false, 'message' => 'Excel not found.', 'debug' => "No uploads row with id=$upload_id and file_type='Formal Report Excel'."]);
        }
        $expected_path = __DIR__ . '/../' . $row['file_path'];
        $full_path = realpath($expected_path);
        $uploads_dir = realpath(__DIR__ . '/../uploads');
        if (!$full_path || !$uploads_dir || strpos($full_path, $uploads_dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($full_path)) {
            json_out(['success' => false, 'message' => 'Excel not found.', 'debug' => "DB has file_path='" . $row['file_path'] . "' but no file exists on disk at: " . $expected_path]);
        }
        json_out(['success' => true, 'data' => base64_encode(file_get_contents($full_path))]);
    } catch (Exception $e) {
        error_log('report_generator_data.php get_excel error: ' . $e->getMessage());
        json_out(['success' => false, 'message' => 'Database error while loading the Excel file.']);
    }
}

if ($action === 'list_templates') {
    try {
        $stmt = $db->query("SELECT id, template_name, survey_type, file_path FROM word_templates ORDER BY template_name ASC");
        $rows = $stmt->fetchAll();
        json_out(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        // 🌟 మైగ్రేషన్ ఇంకా రన్ చేయకపోతే (టేబుల్ లేకపోతే) ఖాళీ లిస్ట్ ఇవ్వడం, పేజీ క్రాష్ కాకుండా
        json_out(['success' => true, 'data' => []]);
    }
}

if ($action === 'get_template') {
    $template_id = (int)($_GET['template_id'] ?? 0);
    if ($template_id <= 0) {
        json_out(['success' => false, 'message' => 'No Template selected.']);
    }
    try {
        $stmt = $db->prepare("SELECT file_path FROM word_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_out(['success' => false, 'message' => 'Template missing.', 'debug' => "No word_templates row with id=$template_id."]);
        }
        $expected_path = __DIR__ . '/../' . $row['file_path'];
        $full_path = realpath($expected_path);
        $templates_dir = realpath(__DIR__ . '/../word_templates');
        if (!$full_path || !$templates_dir || strpos($full_path, $templates_dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($full_path)) {
            json_out(['success' => false, 'message' => 'Template missing.', 'debug' => "DB has file_path='" . $row['file_path'] . "' but no file exists on disk at: " . $expected_path]);
        }
        json_out(['success' => true, 'data' => base64_encode(file_get_contents($full_path))]);
    } catch (Exception $e) {
        error_log('report_generator_data.php get_template error: ' . $e->getMessage());
        json_out(['success' => false, 'message' => 'Database error while loading the template.']);
    }
}

json_out(['success' => false, 'message' => 'Invalid request.']);
