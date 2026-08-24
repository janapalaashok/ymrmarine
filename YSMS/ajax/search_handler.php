<?php
require_once __DIR__ . '/../config/config.php';
checkAuth();

$type = isset($_GET['type']) ? $_GET['type'] : 'vessel';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$db = getDB();

if ($type === 'vessel') {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name 
        FROM surveys s 
        JOIN clients c ON s.client_id = c.id 
        WHERE s.status = 'Pending Vessel' AND (s.vessel_name LIKE ? OR c.company_name LIKE ?)
        ORDER BY s.assign_date DESC
    ");
    $stmt->execute(["%$q%", "%$q%"]);
    $results = $stmt->fetchAll();
    
    foreach ($results as $survey) {
        echo '
        <div class="vessel-card" onclick="location.href=\'vessel_detail.php?id='.$survey['id'].'\'">
            <div class="vessel-avatar-info">
                <div class="vessel-initial-avatar">'.strtoupper(substr($survey['vessel_name'], 3, 1)).'</div>
                <div>
                    <h4 class="vessel-name-title">'.sanitize($survey['vessel_name']).'</h4>
                    <p class="vessel-client-sub">Client: '.sanitize($survey['company_name']).'</p>
                </div>
            </div>
            <div class="vessel-badge-date">
                <span class="badge-status badge-assigned">Assigned</span>
                <div class="badge-date-text">'.date('d M Y', strtotime($survey['assign_date'])).'</div>
            </div>
        </div>';
    }
} elseif ($type === 'report') {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, t.type_name 
        FROM surveys s 
        JOIN clients c ON s.client_id = c.id 
        JOIN survey_types t ON s.survey_type_id = t.id
        WHERE s.status = 'Pending Report' AND (s.vessel_name LIKE ? OR c.company_name LIKE ?)
        ORDER BY s.survey_completed_date DESC
    ");
    $stmt->execute(["%$q%", "%$q%"]);
    $results = $stmt->fetchAll();

    foreach ($results as $survey) {
        $badge = ($survey['overdue_days'] > 0) ? '<span class="badge-status badge-overdue">Overdue</span><div class="text-danger fw-bold" style="font-size:11px;">'.$survey['overdue_days'].' Days</div>' : '<span class="badge-status badge-due">Due by</span><div class="text-warning fw-bold" style="font-size:11px;">'.$survey['due_days'].' Day</div>';
        echo '
        <div class="vessel-card" onclick="location.href=\'report_detail.php?id='.$survey['id'].'\'">
            <div class="vessel-avatar-info">
                <div class="vessel-initial-avatar" style="background:#fef3c7; color:#d97706;">'.strtoupper(substr($survey['vessel_name'], 3, 1)).'</div>
                <div>
                    <h4 class="vessel-name-title">'.sanitize($survey['vessel_name']).'</h4>
                    <p class="vessel-client-sub" style="font-size:11px;">Client: '.sanitize($survey['company_name']).'</p>
                    <p class="text-muted m-0" style="font-size:10px;">Report Type: '.sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')).'</p>
                </div>
            </div>
            <div class="vessel-badge-date">
                '.$badge.'
                <div class="text-muted" style="font-size: 9px; margin-top:2px;">Due: 07 Jun 2025</div>
            </div>
        </div>';
    }
} elseif ($type === 'completed') {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name 
        FROM surveys s 
        JOIN clients c ON s.client_id = c.id 
        WHERE s.status = 'Completed' AND (s.vessel_name LIKE ? OR c.company_name LIKE ?)
        ORDER BY s.survey_completed_date DESC
    ");
    $stmt->execute(["%$q%", "%$q%"]);
    $results = $stmt->fetchAll();

    foreach ($results as $survey) {
        echo '
        <div class="vessel-card" onclick="location.href=\'completed_detail.php?id='.$survey['id'].'\'">
            <div class="vessel-avatar-info">
                <div class="vessel-initial-avatar" style="background:#e0f2fe; color:#0369a1;">'.strtoupper(substr($survey['vessel_name'], 3, 1)).'</div>
                <div>
                    <h4 class="vessel-name-title">'.sanitize($survey['vessel_name']).'</h4>
                    <p class="vessel-client-sub">Client: '.sanitize($survey['company_name']).'</p>
                    <p class="text-muted m-0" style="font-size:11px;">Completed: '.date('d M Y', strtotime($survey['survey_completed_date'])).'</p>
                </div>
            </div>
            <i class="fa-solid fa-chevron-right text-secondary" style="font-size: 14px;"></i>
        </div>';
    }
}