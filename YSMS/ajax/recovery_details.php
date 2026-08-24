<?php
require_once __DIR__ . '/../config/config.php';
checkAuth();

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// period=total  -> అన్ని అప్‌లోడ్ అయిన రికార్డులు (Total Recovery కార్డ్)
// period=month  -> ఈ నెల అప్‌లోడ్ అయిన రికార్డులు మాత్రమే (This Month Recovery కార్డ్)
$period = isset($_GET['period']) && $_GET['period'] === 'month' ? 'month' : 'total';

$sql = "
    SELECT s.vessel_name, s.survey_type_ids, t.type_name,
           s.vlsfo_recovery, s.lsmgo_recovery, s.recovery_amount, s.report_uploaded_date
    FROM surveys s
    LEFT JOIN survey_types t ON s.survey_type_id = t.id
    WHERE s.recovery_amount IS NOT NULL
";

$params = [];

if ($period === 'month') {
    $sql .= " AND MONTH(s.report_uploaded_date) = MONTH(CURDATE()) AND YEAR(s.report_uploaded_date) = YEAR(CURDATE())";
}

if ($role !== 'Admin') {
    $sql .= " AND s.surveyor_id = ?";
    $params[] = $user_id;
}

$sql .= " ORDER BY s.report_uploaded_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
foreach ($rows as $row) {
    $data[] = [
        'vessel_name'    => $row['vessel_name'] ?? '',
        'survey_type'    => getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? 'N/A'),
        'vlsfo_recovery' => $row['vlsfo_recovery'] !== null ? number_format((float)$row['vlsfo_recovery'], 3) : '0.000',
        'lsmgo_recovery' => $row['lsmgo_recovery'] !== null ? number_format((float)$row['lsmgo_recovery'], 3) : '0.000',
        'total_recovery' => $row['recovery_amount'] !== null ? number_format((float)$row['recovery_amount'], 3) : '0.000',
    ];
}

echo json_encode(['success' => true, 'period' => $period, 'rows' => $data]);
exit;
