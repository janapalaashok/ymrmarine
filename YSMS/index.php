<?php
require_once 'config/config.php';
checkAuth();

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// ⚙️ రోల్ బేస్డ్ మెట్రిక్స్ కాలిక్యులేషన్స్ (Admin vs Surveyor)
if ($role === 'Admin') {
    $pending_vessels = $db->query("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Vessel'")->fetchColumn();
    $pending_reports = $db->query("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Report'")->fetchColumn();
    $completed_vessels = $db->query("SELECT COUNT(*) FROM surveys WHERE status = 'Completed'")->fetchColumn();

    // అడ్మిన్ కి మొత్తం వెసెల్స్ లోని O21 రికవరీ సమ్ (అన్ని అప్‌లోడ్ అయిన ఎక్సెల్ రిపోర్ట్స్ నుండి)
    $total_recovery = $db->query("SELECT SUM(recovery_amount) FROM surveys WHERE recovery_amount IS NOT NULL")->fetchColumn();
    
    // ఈ కరెంట్ మంత్ కి సంబంధించిన రికవరీ సమ్ మాత్రమే (రిపోర్ట్ అప్‌లోడ్ అయిన నెల ఆధారంగా)
    $month_recovery = $db->query("SELECT SUM(recovery_amount) FROM surveys WHERE recovery_amount IS NOT NULL AND MONTH(COALESCE(survey_completed_date, report_uploaded_date)) = MONTH(CURDATE()) AND YEAR(COALESCE(survey_completed_date, report_uploaded_date)) = YEAR(CURDATE())")->fetchColumn();

    // అడ్మిన్ కి M20 (VLSFO) రికవరీ సమ్
    $total_vlsfo = $db->query("SELECT SUM(vlsfo_recovery) FROM surveys WHERE vlsfo_recovery IS NOT NULL")->fetchColumn();

    // అడ్మిన్ కి O20 (LSMGO) రికవరీ సమ్
    $total_lsmgo = $db->query("SELECT SUM(lsmgo_recovery) FROM surveys WHERE lsmgo_recovery IS NOT NULL")->fetchColumn();
} else {
    // సర్వేయర్ కి కేవలం తనకు assign చేసినవి మాత్రమే
    $stmt_v = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Vessel' AND surveyor_id = ?");
    $stmt_v->execute([$user_id]);
    $pending_vessels = $stmt_v->fetchColumn();

    $stmt_r = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Report' AND surveyor_id = ?");
    $stmt_r->execute([$user_id]);
    $pending_reports = $stmt_r->fetchColumn();

    $stmt_c = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Completed' AND surveyor_id = ?");
    $stmt_c->execute([$user_id]);
    $completed_vessels = $stmt_c->fetchColumn();

    // సర్వేయర్ కి కేవలం తను అప్‌లోడ్ చేసిన రిపోర్ట్స్ లోని టోటల్ రికవరీ సమ్ మాత్రమే
    $stmt_t_rec = $db->prepare("SELECT SUM(recovery_amount) FROM surveys WHERE recovery_amount IS NOT NULL AND surveyor_id = ?");
    $stmt_t_rec->execute([$user_id]);
    $total_recovery = $stmt_t_rec->fetchColumn();
    
    // సర్వేయర్ కి ఈ నెలలో వచ్చిన రికవరీ సమ్ మాత్రమే
    $stmt_m_rec = $db->prepare("SELECT SUM(recovery_amount) FROM surveys WHERE recovery_amount IS NOT NULL AND surveyor_id = ? AND MONTH(COALESCE(survey_completed_date, report_uploaded_date)) = MONTH(CURDATE()) AND YEAR(COALESCE(survey_completed_date, report_uploaded_date)) = YEAR(CURDATE())");
    $stmt_m_rec->execute([$user_id]);
    $month_recovery = $stmt_m_rec->fetchColumn();

    // సర్వేయర్ కి తన VLSFO (M20) రికవరీ సమ్ మాత్రమే
    $stmt_vlsfo = $db->prepare("SELECT SUM(vlsfo_recovery) FROM surveys WHERE vlsfo_recovery IS NOT NULL AND surveyor_id = ?");
    $stmt_vlsfo->execute([$user_id]);
    $total_vlsfo = $stmt_vlsfo->fetchColumn();

    // సర్వేయర్ కి తన LSMGO (O20) రికవరీ సమ్ మాత్రమే
    $stmt_lsmgo = $db->prepare("SELECT SUM(lsmgo_recovery) FROM surveys WHERE lsmgo_recovery IS NOT NULL AND surveyor_id = ?");
    $stmt_lsmgo->execute([$user_id]);
    $total_lsmgo = $stmt_lsmgo->fetchColumn();
}


// 🌟 Recent Survey Recovery (latest vessel with recovery)
if ($role === 'Admin') {
    $recent_row = $db->query("SELECT vessel_name, vlsfo_recovery, lsmgo_recovery, recovery_amount FROM surveys WHERE recovery_amount IS NOT NULL ORDER BY COALESCE(survey_completed_date, report_uploaded_date) DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $avg_ships = $db->query("SELECT COUNT(*) FROM surveys WHERE status = 'Completed'")->fetchColumn();
    $months_span = $db->query("SELECT GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(survey_completed_date, report_uploaded_date, assign_date)), CURDATE()) + 1) FROM surveys WHERE status IN ('Completed','Pending Report')")->fetchColumn();
    $avg_ships_per_month = $months_span > 0 ? round(((float)$avg_ships) / (float)$months_span, 1) : 0;
    $avg_recovery_per_ship = $db->query("SELECT AVG(recovery_amount) FROM surveys WHERE recovery_amount IS NOT NULL AND recovery_amount > 0")->fetchColumn();
    $avg_recovery_per_surveyor = $db->query("SELECT AVG(t.s) FROM (SELECT SUM(recovery_amount) AS s FROM surveys WHERE recovery_amount IS NOT NULL GROUP BY surveyor_id) t")->fetchColumn();
} else {
    $stmt_recent = $db->prepare("SELECT vessel_name, vlsfo_recovery, lsmgo_recovery, recovery_amount FROM surveys WHERE recovery_amount IS NOT NULL AND surveyor_id = ? ORDER BY COALESCE(survey_completed_date, report_uploaded_date) DESC, id DESC LIMIT 1");
    $stmt_recent->execute([$user_id]);
    $recent_row = $stmt_recent->fetch(PDO::FETCH_ASSOC);
    $stmt_avg = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Completed' AND surveyor_id = ?");
    $stmt_avg->execute([$user_id]);
    $avg_ships = $stmt_avg->fetchColumn();
    $stmt_ms = $db->prepare("SELECT GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(survey_completed_date, report_uploaded_date, assign_date)), CURDATE()) + 1) FROM surveys WHERE surveyor_id = ? AND status IN ('Completed','Pending Report')");
    $stmt_ms->execute([$user_id]);
    $months_span = $stmt_ms->fetchColumn();
    $avg_ships_per_month = $months_span > 0 ? round(((float)$avg_ships) / (float)$months_span, 1) : 0;
    $avg_recovery_per_ship = null;
    $avg_recovery_per_surveyor = null;
}
$recent_vessel_name = $recent_row['vessel_name'] ?? '—';
$recent_vlsfo = !empty($recent_row['vlsfo_recovery']) ? number_format((float)$recent_row['vlsfo_recovery'], 3) . ' MT' : '0.000 MT';
$recent_lsmgo = !empty($recent_row['lsmgo_recovery']) ? number_format((float)$recent_row['lsmgo_recovery'], 3) . ' MT' : '0.000 MT';
$avg_ships_per_month_disp = number_format((float)$avg_ships_per_month, 1);
$avg_recovery_per_ship_disp = !empty($avg_recovery_per_ship) ? number_format((float)$avg_recovery_per_ship, 3) . ' MT' : '0.000 MT';
$avg_recovery_per_surveyor_disp = !empty($avg_recovery_per_surveyor) ? number_format((float)$avg_recovery_per_surveyor, 3) . ' MT' : '0.000 MT';

// 🌟 డెసిమల్స్ 3 స్థానాలకు మార్చి (0.000) వెనుక 'MT' యాడ్ చేయడం
$total_recovery = !empty($total_recovery) ? number_format((float)$total_recovery, 3) . ' MT' : '0.000 MT';
$month_recovery = !empty($month_recovery) ? number_format((float)$month_recovery, 3) . ' MT' : '0.000 MT';
$total_vlsfo = !empty($total_vlsfo) ? number_format((float)$total_vlsfo, 3) . ' MT' : '0.000 MT';
$total_lsmgo = !empty($total_lsmgo) ? number_format((float)$total_lsmgo, 3) . ' MT' : '0.000 MT';

include 'includes/header.php';
?>

<div class="scroll-content">
    <div class="dash-header">
        <div class="user-welcome">
            <h2>Hi, <?= sanitize($_SESSION['full_name']) ?> 👋</h2>
            <p><?= getGreeting() ?>! Have a productive day.</p>
        </div>
        <div class="dash-header-right" style="display:flex;align-items:center;gap:8px;">
            <?php include 'includes/notifications_bell.php'; ?>
            <?php include 'includes/profile_dropdown.php'; ?>
        </div>
    </div>

    <div class="overview-section">
        <div class="section-title-row">
            <span class="section-title">Today's Overview</span>
            <div class="date-select-badge">
                <?= date('d M Y') ?> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 10px;"></i>
            </div>
        </div>
        
        <div class="overview-grid">
            <div class="ov-card pending-vessel" onclick="location.href='vessels.php'">
                <div class="ov-count"><?= $pending_vessels ?></div>
                <div class="ov-label">Pending<br>Vessel's</div>
            </div>
            <div class="ov-card pending-report" onclick="location.href='reports.php'">
                <div class="ov-count"><?= $pending_reports ?></div>
                <div class="ov-label">Pending<br>Reports</div>
            </div>
            <div class="ov-card completed" onclick="location.href='completed.php'">
                <div class="ov-count"><?= $completed_vessels ?></div>
                <div class="ov-label">Completed<br>Vessels</div>
            </div>
        </div>
    </div>

    <div class="overview-section mt-4">
        <div class="section-title-row">
            <span class="section-title">Statistics</span>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card" role="button" style="cursor:pointer;" onclick="openRecoveryModal('total')" data-testid="total-recovery-card">
            <span class="stat-title">Total Recovery</span>
            <!-- 🌟 $ సింబల్ తీసివేసి కేవలం వాల్యూ (MT తో కలిపి) డిస్‌ప్లే అవుతుంది -->
            <div class="stat-val"><?= $total_recovery ?></div>
            <div class="mt-2" style="height: 30px; overflow: hidden;">
                <svg viewBox="0 0 100 30" width="100%" height="100%" preserveAspectRatio="none">
                    <path d="M0,25 Q15,5 30,20 T60,10 T90,15 T100,5" fill="none" stroke="#166534" stroke-width="2"/>
                </svg>
            </div>
        </div>
        <div class="stat-card" role="button" style="cursor:pointer;" onclick="openRecoveryModal('month')" data-testid="month-recovery-card">
            <span class="stat-title">This Month Recovery</span>
            <!-- 🌟 $ సింబల్ తీసివేసి కేవలం వాల్యూ (MT తో కలిపి) డిస్‌ప్లే అవుతుంది -->
            <div class="stat-val"><?= $month_recovery ?></div>
            <div class="mt-2" style="height: 30px; overflow: hidden;">
                <svg viewBox="0 0 100 30" width="100%" height="100%" preserveAspectRatio="none">
                    <path d="M0,20 Q20,25 40,10 T70,18 T100,8" fill="none" stroke="#1e40af" stroke-width="2"/>
                </svg>
            </div>
        </div>
        <!-- 🌟 కొత్త కార్డ్: Recovery in VLSFO (TABLES 54B - M20 సెల్ నుండి) -->
        <div class="stat-card">
            <span class="stat-title">Recovery in VLSFO</span>
            <div class="stat-val"><?= $total_vlsfo ?></div>
        </div>
        <!-- 🌟 కొత్త కార్డ్: Recovery in LSMGO (TABLES 54B - O20 సెల్ నుండి) -->
        <div class="stat-card">
            <span class="stat-title">Recovery in LSMGO</span>
            <div class="stat-val"><?= $total_lsmgo ?></div>
        </div>
        <div class="stat-card" data-testid="recent-survey-recovery-card">
            <span class="stat-title">Recent Survey Recovery</span>
            <div class="stat-val" style="font-size:14px;"><?= sanitize($recent_vessel_name) ?></div>
            <div class="text-muted mt-1" style="font-size:11px;">VLSFO: <?= $recent_vlsfo ?> · LSMGO: <?= $recent_lsmgo ?></div>
        </div>
        <div class="stat-card" data-testid="avg-ships-per-month-card">
            <span class="stat-title">Average Ships Per Month</span>
            <div class="stat-val"><?= $avg_ships_per_month_disp ?></div>
        </div>
        <?php if ($role === 'Admin'): ?>
        <div class="stat-card" data-testid="avg-recovery-per-ship-card">
            <span class="stat-title">Average Recovery Per Ship</span>
            <div class="stat-val"><?= $avg_recovery_per_ship_disp ?></div>
        </div>
        <div class="stat-card" data-testid="avg-recovery-per-surveyor-card">
            <span class="stat-title">Average Recovery Per Surveyor</span>
            <div class="stat-val"><?= $avg_recovery_per_surveyor_disp ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick links: stacked on mobile, one neat row on desktop -->
    <div class="dashboard-action-links">
        <div class="overview-section dashboard-action-item mt-4">
            <a href="formats_download.php" class="bg-white p-3 rounded-4 d-flex justify-content-between align-items-center shadow-sm text-decoration-none h-100" style="border: 1px solid var(--border-color);" data-testid="formats-download-link">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-3 d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-cloud-arrow-down"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark" style="font-size: 14px;">Formats Download</div>
                        <div class="text-muted" style="font-size: 11px;">Download survey formats</div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right text-secondary" style="font-size: 14px;"></i>
            </a>
        </div>

        <div class="overview-section dashboard-action-item mt-3">
            <a href="generate_permission_copy.php" class="bg-white p-3 rounded-4 d-flex justify-content-between align-items-center shadow-sm text-decoration-none h-100" style="border: 1px solid var(--border-color);" data-testid="generate-permission-copy-link">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-3 d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-file-signature"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark" style="font-size: 14px;">Generate Permission Copy</div>
                        <div class="text-muted" style="font-size: 11px;">Create a port/customs permission copy</div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right text-secondary" style="font-size: 14px;"></i>
            </a>
        </div>

        <div class="overview-section dashboard-action-item mt-3">
            <a href="coming_soon.php?feature=Vessel+Lineups" class="bg-white p-3 rounded-4 d-flex justify-content-between align-items-center shadow-sm text-decoration-none h-100" style="border: 1px solid var(--border-color);" data-testid="vessel-line-up-link">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-3 d-flex justify-content-center align-items-center" style="width: 40px; height: 40px;">
                        <i class="fa-solid fa-anchor"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark" style="font-size: 14px;">Vessel Lineups (Indian Ports)</div>
                        <div class="text-muted" style="font-size: 11px;">Coming soon — Working / Waiting / Expected vessels</div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right text-secondary" style="font-size: 14px;"></i>
            </a>
        </div>
    </div>
</div>

<?php include 'includes/recovery_detail.php'; ?>

<?php 
include 'includes/nav.php';
include 'includes/footer.php';
?>