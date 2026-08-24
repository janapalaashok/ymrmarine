<?php
// 🌟 Admin Controls లో "Assigned Vessels" మరియు "Pending Reports" ట్యాబ్‌ల కోసం —
// surveys టేబుల్‌పై list + delete ఇచ్చే AJAX ఎండ్‌పాయింట్ (అడ్మిన్ మాత్రమే వాడగలరు).
// Edit ఇప్పటికే ఉన్న vessel_detail.php?id=X&edit=1 పేజీ ద్వారానే జరుగుతుంది (బిజినెస్ లాజిక్
// మార్చకుండా, ఉన్న ఎడిట్ ఫారమ్‌నే తిరిగి వాడటం). ఇక్కడ కేవలం లిస్ట్ చూపించడం + డిలీట్ చేయడం మాత్రమే.

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/notifications.php';
checkAuth();
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

// రెండు వర్చువల్ మాడ్యూల్స్ మాత్రమే ఇక్కడ అనుమతించబడతాయి — surveys.status ఆధారంగా ఫిల్టర్
$type_status_map = [
    'assigned_vessels' => 'Pending Vessel',
    'pending_reports'  => 'Pending Report',
];

$type = isset($_REQUEST['type']) ? trim((string)$_REQUEST['type']) : '';
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'list';

if ($type === '' || !isset($type_status_map[$type])) {
    echo json_encode(['success' => false, 'message' => 'Unknown list type.']);
    exit;
}

$status = $type_status_map[$type];
$db = getDB();

try {
    if ($action === 'list') {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));

        $sql = "
            SELECT s.id, s.vessel_name, s.assign_date, s.status,
                   c.company_name, p.port_name, u.full_name AS surveyor_name,
                   st.type_name, s.survey_type_ids
            FROM surveys s
            JOIN clients c ON s.client_id = c.id
            LEFT JOIN ports p ON s.port_id = p.id
            LEFT JOIN users u ON s.surveyor_id = u.id
            LEFT JOIN survey_types st ON s.survey_type_id = st.id
            WHERE s.status = ?
        ";
        $params = [$status];

        if ($q !== '') {
            $sql .= " AND (s.vessel_name LIKE ? OR c.company_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)";
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like);
        }

        $sql .= " ORDER BY s.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['survey_type_display'] = getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? '');
            unset($row['survey_type_ids'], $row['type_name']);
        }
        unset($row);

        echo json_encode(['success' => true, 'rows' => $rows]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid record.']);
            exit;
        }

        // ఈ id నిజంగా ఈ ట్యాబ్ చూపించే status లోనే ఉందా అని నిర్ధారించుకోవడం
        // (వేరే ట్యాబ్ నుండి పొరపాటున వేరే స్టేటస్ రికార్డును తీసేయకుండా ఉండటానికి)
        $chk = $db->prepare("SELECT id FROM surveys WHERE id = ? AND status = ?");
        $chk->execute([$id, $status]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Record not found in this list.']);
            exit;
        }

        // uploads / survey_survey_types రెండూ surveys.id మీద ON DELETE CASCADE తో
        // ఉన్నాయి కాబట్టి, ఇక్కడ ఒక్క DELETE సరిపోతుంది.
        // Notify surveyor before delete
        try {
            $info = $db->prepare("SELECT vessel_name, surveyor_id FROM surveys WHERE id = ?");
            $info->execute([$id]);
            $row = $info->fetch(PDO::FETCH_ASSOC) ?: [];
            $sid = (int)($row['surveyor_id'] ?? 0);
            $vname = $row['vessel_name'] ?? ('#' . $id);
            if ($sid > 0) {
                createNotification($db, $sid, 'Vessel deleted',
                    ($_SESSION['full_name'] ?? 'Admin') . ' deleted assignment for ' . $vname . '.',
                    'delete', 'vessels.php', (int)($_SESSION['user_id'] ?? 0));
            }
        } catch (Throwable $ne) { error_log('delete notif: '.$ne->getMessage()); }

        $del = $db->prepare("DELETE FROM surveys WHERE id = ?");
        $del->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Record deleted successfully.']);
        exit;
    }


    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid record.']);
            exit;
        }
        // Only Pending Vessel can be cancelled from Assigned Vessels tab
        if ($status !== 'Pending Vessel') {
            echo json_encode(['success' => false, 'message' => 'Only assigned (pending) vessels can be cancelled here.']);
            exit;
        }
        try {
            $db->exec("ALTER TABLE surveys MODIFY COLUMN status ENUM('Pending Vessel','Pending Report','Completed','Cancelled') DEFAULT 'Pending Vessel'");
        } catch (Exception $e) {}

        $chk = $db->prepare("SELECT id FROM surveys WHERE id = ? AND status = ?");
        $chk->execute([$id, $status]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Record not found in this list.']);
            exit;
        }

        $upd = $db->prepare("UPDATE surveys SET status = 'Cancelled', status_updated_by = ?, status_updated_at = NOW() WHERE id = ?");
        try {
            $upd->execute([$_SESSION['user_id'] ?? null, $id]);
        } catch (Exception $e) {
            // status_updated_at may not exist
            $upd = $db->prepare("UPDATE surveys SET status = 'Cancelled' WHERE id = ?");
            $upd->execute([$id]);
        }

        try {
            $info = $db->prepare("SELECT vessel_name, surveyor_id FROM surveys WHERE id = ?");
            $info->execute([$id]);
            $row = $info->fetch(PDO::FETCH_ASSOC) ?: [];
            $sid = (int)($row['surveyor_id'] ?? 0);
            $vname = $row['vessel_name'] ?? ('#' . $id);
            if ($sid > 0) {
                createNotification($db, $sid, 'Vessel cancelled',
                    ($_SESSION['full_name'] ?? 'Admin') . ' cancelled ' . $vname . '.',
                    'cancel', 'cancelled.php', (int)($_SESSION['user_id'] ?? 0));
            }
        } catch (Throwable $ne) { error_log('cancel notif: '.$ne->getMessage()); }

        echo json_encode(['success' => true, 'message' => 'Vessel moved to Cancelled.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Exception $e) {
    error_log('admin_surveys.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again.']);
}
