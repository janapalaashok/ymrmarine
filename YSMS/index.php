<?php
require_once 'config/config.php';
require_once 'includes/dashboard_month.php';
require_once 'includes/recovery_list.php';
// Current calendar month in the app's timezone (not the DB server's) for "This Month" figures.
$cm_start = date('Y-m-01');
$cm_end = date('Y-m-01', strtotime('first day of next month'));
checkAuth();

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// 🌟 SAFETY NET: hsfo_recovery column (TABLES 54B!N20), same as
// ajax/upload_handler.php — the dashboard's SUM(hsfo_recovery) queries
// below need this column to exist even before the first Excel is
// (re-)uploaded after this feature ships.
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'hsfo_recovery'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN hsfo_recovery DECIMAL(12,3) DEFAULT NULL");
    }
} catch (Exception $e) { error_log('index.php hsfo_recovery column: ' . $e->getMessage()); }

// 🌟 Client's own client_id (needed to scope their dashboard/vessel data)
$client_row_id = 0;
if ($role === 'Client') {
    try {
        $ccheck = $db->prepare("SELECT id FROM clients WHERE user_id = ? LIMIT 1");
        $ccheck->execute([$user_id]);
        $client_row_id = (int)($ccheck->fetchColumn() ?: 0);
    } catch (Throwable $e) { error_log('index.php client lookup: ' . $e->getMessage()); }
}

// ⚙️ రోల్ బేస్డ్ మెట్రిక్స్ కాలిక్యులేషన్స్ (Admin vs Surveyor vs Client)
if (in_array($role, ['Admin', 'Super Admin'], true)) {
    $pending_vessels = $db->query("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Vessel'")->fetchColumn();
    $pending_reports = $db->query("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Report'")->fetchColumn();
    $completed_vessels = $db->query("SELECT COUNT(*) FROM surveys WHERE status = 'Completed'")->fetchColumn();

    // Total Recovery: every vessel that has left Pending Vessels (Pending Report +
    // Completed). One row per vessel, so a vessel is counted once whatever stage it is in;
    // the stored value is the Excel-extracted or manually entered recovery.
    $total_recovery = $db->query("SELECT SUM(ABS(recovery_amount)) FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . "")->fetchColumn();
    
    // This Month Recovery: same vessels, Survey Completed Date in the current calendar month.
    $month_recovery = $db->query("SELECT SUM(ABS(recovery_amount)) FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " AND " . surveyCompletedDateSql('surveys') . " >= '$cm_start' AND " . surveyCompletedDateSql('surveys') . " < '$cm_end'")->fetchColumn();




    // 🌟 ప్రతి సర్వేయర్ కి పూర్తి రిపోర్ట్ (dashboard bottom table): Ships
    // Attended (as of now / this month) + Total Recovery (as of now / this
    // month) — role_id = 2 (Surveyor) ఉన్న ప్రతి యూజర్ కి, రికార్డులు లేని
    // వారికి కూడా (LEFT JOIN) 0గా చూపించడానికి.
    // 🌟 BUG FIX: a survey assigned to more than one surveyor (survey_surveyors
    // junction table) was only credited to the PRIMARY surveyor_id, so a
    // co-surveyor's ships/recovery here read lower than their own
    // completed.php/vessels.php view (which already matches surveyor_id OR
    // survey_surveyors membership) — join the same way those pages do.
    // Same rules as the Total / This Month Recovery cards: a ship counts as
    // attended once it has left Pending Vessels (Pending Report + Completed),
    // and "This Month" is the Survey Completed Date in the current month
    // (app timezone). Previously ships counted only Completed and the month
    // used the DB server's CURDATE() with a report-date fallback, so the
    // ships and recovery columns disagreed with each other and with the cards.
    $sfrRec = recoveryCountedSql('s'); // NOT APPLICABLE survey types count as 0
    $sfrDone = "s.status IN ('Pending Report','Completed')";
    $sfrCd = surveyCompletedDateSql('s');
    $sfrMonth = "$sfrCd >= '$cm_start' AND $sfrCd < '$cm_end'";
    $sfrApplies = recoveryAppliesSql('s'); // ships whose survey type has recovery (Avg Recovery per ship)
    $surveyor_full_report = $db->query("
        SELECT
            u.id, u.full_name, u.status AS user_status,
            COUNT(DISTINCT CASE WHEN $sfrDone THEN s.id END) AS ships_total,
            COUNT(DISTINCT CASE WHEN $sfrDone AND $sfrMonth THEN s.id END) AS ships_month,
            COALESCE(SUM(CASE WHEN $sfrDone THEN $sfrRec END), 0) AS recovery_total,
            COALESCE(SUM(CASE WHEN $sfrDone AND $sfrMonth THEN $sfrRec END), 0) AS recovery_month,
            COUNT(DISTINCT CASE WHEN $sfrDone AND $sfrApplies THEN s.id END) AS ships_rec,
            MIN(CASE WHEN $sfrDone THEN $sfrCd END) AS first_done
        FROM users u
        LEFT JOIN surveys s ON (s.surveyor_id = u.id OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = u.id))
        WHERE u.role_id = 2
        GROUP BY u.id, u.full_name, u.status
        ORDER BY u.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Vessels with no surveyor login (outsourced / unassigned placeholder) are
    // credited to OUTSOURCED_CREDIT_SURVEYOR's row (no separate "outsourced" row).
    $sfrNoSurveyor = RECOVERY_NO_SURVEYOR_SQL;
    $sfr_unassigned = $db->query("
        SELECT COUNT(*) AS ships_total,
               COUNT(CASE WHEN $sfrMonth THEN 1 END) AS ships_month,
               COALESCE(SUM($sfrRec), 0) AS recovery_total,
               COALESCE(SUM(CASE WHEN $sfrMonth THEN $sfrRec END), 0) AS recovery_month,
               COUNT(CASE WHEN $sfrApplies THEN 1 END) AS ships_rec, MIN($sfrCd) AS first_done
        FROM surveys s WHERE $sfrDone AND $sfrNoSurveyor
    ")->fetch(PDO::FETCH_ASSOC);
    [$sfrCreditId, $sfrCreditName] = outsourcedCreditSurveyor($db);
    if ($sfrCreditId === -1) {
        // No Surveyor login with that name: still list them, with the outsourced vessels.
        $surveyor_full_report[] = ['id' => -1, 'full_name' => $sfrCreditName, 'user_status' => 'Active',
            'ships_total' => 0, 'ships_month' => 0, 'recovery_total' => 0, 'recovery_month' => 0, 'ships_rec' => 0, 'first_done' => null];
        usort($surveyor_full_report, fn($x, $y) => strcasecmp($x['full_name'], $y['full_name']));
    }
    foreach ($surveyor_full_report as &$sfrRow) {
        if ((int)$sfrRow['id'] === $sfrCreditId) {
            foreach (['ships_total', 'ships_month', 'recovery_total', 'recovery_month', 'ships_rec'] as $k) {
                $sfrRow[$k] += $sfr_unassigned[$k];
            }
            $fd = array_filter([$sfrRow['first_done'] ?? null, $sfr_unassigned['first_done'] ?? null]);
            $sfrRow['first_done'] = $fd ? min($fd) : null;
        }
    }
    unset($sfrRow);
    // Footer: every vessel once (a vessel shared by two surveyors appears in
    // both their rows, so the rows can add up to more than this).
    $sfr_total = $db->query("
        SELECT COUNT(*) AS ships_total,
               COUNT(CASE WHEN $sfrMonth THEN 1 END) AS ships_month,
               COALESCE(SUM($sfrRec), 0) AS recovery_total,
               COALESCE(SUM(CASE WHEN $sfrMonth THEN $sfrRec END), 0) AS recovery_month,
               COUNT(CASE WHEN $sfrApplies THEN 1 END) AS ships_rec, MIN($sfrCd) AS first_done
        FROM surveys s WHERE $sfrDone
    ")->fetch(PDO::FETCH_ASSOC);
    // Averages: per ship = total recovery / ships whose survey type has recovery; per month = total
    // recovery / months from the first Survey Completed month to this month (both ends counted).
    $sfrAvg = function (array &$r) {
        $r['avg_ship'] = (int)$r['ships_rec'] > 0 ? (float)$r['recovery_total'] / (int)$r['ships_rec'] : 0.0;
        $first = substr((string)($r['first_done'] ?? ''), 0, 7);
        $months = preg_match('/^\d{4}-\d{2}$/', $first) ? max(1, ((int)date('Y') - (int)substr($first, 0, 4)) * 12 + (int)date('n') - (int)substr($first, 5, 2) + 1) : 0;
        $r['avg_month'] = $months > 0 ? (float)$r['recovery_total'] / $months : 0.0;
    };
    foreach ($surveyor_full_report as &$sfrRow) $sfrAvg($sfrRow);
    unset($sfrRow);
    if ($sfr_total) $sfrAvg($sfr_total);

    // 🌟 Client Performance — Full Report: the same figures per client (each vessel belongs to one
    //    client, so the rows add up to the footer). Clients with at least one ship attended.
    $client_full_report = [];
    try {
        $client_full_report = $db->query("
            SELECT c.id, COALESCE(NULLIF(TRIM(c.company_name), ''), 'Client not recorded') AS full_name,
                   COUNT(*) AS ships_total,
                   COUNT(CASE WHEN $sfrMonth THEN 1 END) AS ships_month,
                   COALESCE(SUM($sfrRec), 0) AS recovery_total,
                   COALESCE(SUM(CASE WHEN $sfrMonth THEN $sfrRec END), 0) AS recovery_month,
                   COUNT(CASE WHEN $sfrApplies THEN 1 END) AS ships_rec, MIN($sfrCd) AS first_done
            FROM surveys s LEFT JOIN clients c ON c.id = s.client_id
            WHERE $sfrDone
            GROUP BY c.id, c.company_name
            ORDER BY full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($client_full_report as &$cfrRow) $sfrAvg($cfrRow);
        unset($cfrRow);
    } catch (Throwable $e) { error_log('dashboard client full report: ' . $e->getMessage()); }
} elseif ($role === 'Client') {
    // క్లయింట్ కి కేవలం తన కంపెనీ వెసెల్స్ మాత్రమే
    $stmt_v = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Vessel' AND client_id = ?");
    $stmt_v->execute([$client_row_id]);
    $pending_vessels = $stmt_v->fetchColumn();

    $stmt_r = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Report' AND client_id = ?");
    $stmt_r->execute([$client_row_id]);
    $pending_reports = $stmt_r->fetchColumn();

    $stmt_c = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Completed' AND client_id = ?");
    $stmt_c->execute([$client_row_id]);
    $completed_vessels = $stmt_c->fetchColumn();

    // క్లయింట్ కి తన కంపెనీ వెసెల్స్ లోని రికవరీ సమ్ మాత్రమే
    // (గతంలో ఇక్కడ 0 హార్డ్‌కోడ్ చేయబడింది — ఎప్పుడూ డేటాబేస్ నుండి తీసుకోబడలేదు — బగ్ ఫిక్స్)
    $stmt_t_rec = $db->prepare("SELECT SUM(ABS(recovery_amount)) FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " AND client_id = ?");
    $stmt_t_rec->execute([$client_row_id]);
    $total_recovery = $stmt_t_rec->fetchColumn();

    $stmt_m_rec = $db->prepare("SELECT SUM(ABS(recovery_amount)) FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " AND client_id = ? AND " . surveyCompletedDateSql('surveys') . " >= '$cm_start' AND " . surveyCompletedDateSql('surveys') . " < '$cm_end'");
    $stmt_m_rec->execute([$client_row_id]);
    $month_recovery = $stmt_m_rec->fetchColumn();



} else {
    // సర్వేయర్ కి కేవలం తనకు assign చేసినవి మాత్రమే — primary surveys.surveyor_id
    // మరియు survey_surveyors జంక్షన్ టేబుల్ (multi-surveyor) రెండూ కలిపి.
    $assignedSql = "(surveyor_id = ? OR id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))";
    $stmt_v = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Vessel' AND $assignedSql");
    $stmt_v->execute([$user_id, $user_id]);
    $pending_vessels = $stmt_v->fetchColumn();

    $stmt_r = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Report' AND $assignedSql");
    $stmt_r->execute([$user_id, $user_id]);
    $pending_reports = $stmt_r->fetchColumn();

    $stmt_c = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Completed' AND $assignedSql");
    $stmt_c->execute([$user_id, $user_id]);
    $completed_vessels = $stmt_c->fetchColumn();

    // సర్వేయర్ కి కేవలం తను అప్‌లోడ్ చేసిన రిపోర్ట్స్ లోని టోటల్ రికవరీ సమ్ మాత్రమే
    $stmt_t_rec = $db->prepare("SELECT SUM(ABS(recovery_amount)) FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " AND $assignedSql");
    $stmt_t_rec->execute([$user_id, $user_id]);
    $total_recovery = $stmt_t_rec->fetchColumn();

    // సర్వేయర్ కి ఈ నెలలో వచ్చిన రికవరీ సమ్ మాత్రమే
    $stmt_m_rec = $db->prepare("SELECT SUM(ABS(recovery_amount)) FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " AND $assignedSql AND " . surveyCompletedDateSql('surveys') . " >= '$cm_start' AND " . surveyCompletedDateSql('surveys') . " < '$cm_end'");
    $stmt_m_rec->execute([$user_id, $user_id]);
    $month_recovery = $stmt_m_rec->fetchColumn();



}


// 🌟 Recent Survey Recovery (latest vessel with recovery)
if (in_array($role, ['Admin', 'Super Admin'], true)) {
    $recent_row = $db->query("SELECT vessel_name, survey_type_ids, vlsfo_recovery, lsmgo_recovery, hsfo_recovery, recovery_amount FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " ORDER BY survey_completed_date DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $avg_ships = $db->query("SELECT COUNT(*) FROM surveys WHERE status = 'Completed'")->fetchColumn();
    $months_span = $db->query("SELECT GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(survey_completed_date, report_uploaded_date, assign_date)), CURDATE()) + 1) FROM surveys WHERE status IN ('Completed','Pending Report')")->fetchColumn();
    $avg_ships_per_month = $months_span > 0 ? round(((float)$avg_ships) / (float)$months_span, 1) : 0;
    $avg_recovery_per_ship = $db->query("SELECT AVG(ABS(recovery_amount)) FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " AND ABS(recovery_amount) > 0")->fetchColumn();
    $avg_recovery_per_surveyor = $db->query("SELECT AVG(t.s) FROM (SELECT SUM(ABS(recovery_amount)) AS s FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " GROUP BY surveyor_id) t")->fetchColumn();
    $avg_ships_per_surveyor = $db->query("SELECT AVG(t.cnt) FROM (SELECT COUNT(*) AS cnt FROM surveys WHERE status = 'Completed' GROUP BY surveyor_id) t")->fetchColumn();
} elseif ($role === 'Client') {
    $stmt_recent = $db->prepare("SELECT vessel_name, survey_type_ids, vlsfo_recovery, lsmgo_recovery, hsfo_recovery, recovery_amount FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " AND client_id = ? ORDER BY survey_completed_date DESC, id DESC LIMIT 1");
    $stmt_recent->execute([$client_row_id]);
    $recent_row = $stmt_recent->fetch(PDO::FETCH_ASSOC);
    // (గతంలో ఇక్కడ avg_ships_per_month 0 హార్డ్‌కోడ్ చేయబడింది — బగ్ ఫిక్స్)
    $stmt_avg = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Completed' AND client_id = ?");
    $stmt_avg->execute([$client_row_id]);
    $avg_ships = $stmt_avg->fetchColumn();
    $stmt_ms = $db->prepare("SELECT GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(survey_completed_date, report_uploaded_date, assign_date)), CURDATE()) + 1) FROM surveys WHERE client_id = ? AND status IN ('Completed','Pending Report')");
    $stmt_ms->execute([$client_row_id]);
    $months_span = $stmt_ms->fetchColumn();
    $avg_ships_per_month = $months_span > 0 ? round(((float)$avg_ships) / (float)$months_span, 1) : 0;
    $avg_recovery_per_ship = null;
    $avg_recovery_per_surveyor = null;
    $avg_ships_per_surveyor = null;
} else {
    $assignedSql = "(surveyor_id = ? OR id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))";
    $stmt_recent = $db->prepare("SELECT vessel_name, survey_type_ids, vlsfo_recovery, lsmgo_recovery, hsfo_recovery, recovery_amount FROM surveys WHERE status IN ('Pending Report','Completed') AND recovery_amount IS NOT NULL AND " . recoveryAppliesSql('surveys') . " AND $assignedSql ORDER BY survey_completed_date DESC, id DESC LIMIT 1");
    $stmt_recent->execute([$user_id, $user_id]);
    $recent_row = $stmt_recent->fetch(PDO::FETCH_ASSOC);
    $stmt_avg = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = 'Completed' AND $assignedSql");
    $stmt_avg->execute([$user_id, $user_id]);
    $avg_ships = $stmt_avg->fetchColumn();
    $stmt_ms = $db->prepare("SELECT GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(survey_completed_date, report_uploaded_date, assign_date)), CURDATE()) + 1) FROM surveys WHERE $assignedSql AND status IN ('Completed','Pending Report')");
    $stmt_ms->execute([$user_id, $user_id]);
    $months_span = $stmt_ms->fetchColumn();
    $avg_ships_per_month = $months_span > 0 ? round(((float)$avg_ships) / (float)$months_span, 1) : 0;
    $avg_recovery_per_ship = null;
    $avg_recovery_per_surveyor = null;
    $avg_ships_per_surveyor = null;
}
$recent_vessel_name = $recent_row['vessel_name'] ?? '—';
// Onhire survey → its recovery is shown with a minus sign (display only)
$recent_onhire = !empty($recent_row) && isOnhireSurvey($db, (string)($recent_row['survey_type_ids'] ?? ''));
$recent_vlsfo = !empty($recent_row['vlsfo_recovery']) ? recoveryDisplay($recent_row['vlsfo_recovery'], $recent_onhire) . ' MT' : '0.000 MT';
$recent_lsmgo = !empty($recent_row['lsmgo_recovery']) ? recoveryDisplay($recent_row['lsmgo_recovery'], $recent_onhire) . ' MT' : '0.000 MT';
$recent_hsfo = !empty($recent_row['hsfo_recovery']) ? recoveryDisplay($recent_row['hsfo_recovery'], $recent_onhire) . ' MT' : '0.000 MT';
$avg_ships_per_month_disp = number_format((float)$avg_ships_per_month, 1);
$avg_recovery_per_ship_disp = !empty($avg_recovery_per_ship) ? number_format((float)$avg_recovery_per_ship, 3) . ' MT' : '0.000 MT';
$avg_recovery_per_surveyor_disp = !empty($avg_recovery_per_surveyor) ? number_format((float)$avg_recovery_per_surveyor, 3) . ' MT' : '0.000 MT';

// 🌟 డెసిమల్స్ 3 స్థానాలకు మార్చి (0.000) వెనుక 'MT' యాడ్ చేయడం
$total_recovery = !empty($total_recovery) ? number_format((float)$total_recovery, 3) . ' MT' : '0.000 MT';
$month_recovery = !empty($month_recovery) ? number_format((float)$month_recovery, 3) . ' MT' : '0.000 MT';

include 'includes/header.php';

// 🌟 New-format popup flag (set at login.php if a format was uploaded/replaced within 7 days)
$show_format_popup = !empty($_SESSION['show_format_popup']);
unset($_SESSION['show_format_popup']);
?>

<?php if ($show_format_popup): ?>
<div class="format-popup-overlay" id="formatPopupOverlay">
    <div class="format-popup-card" role="dialog" aria-modal="true" aria-labelledby="formatPopupTitle">
        <button type="button" class="format-popup-close" id="formatPopupClose" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="format-popup-icon"><i class="fa-solid fa-file-excel"></i></div>
        <h5 id="formatPopupTitle" class="format-popup-title">New formats updated</h5>
        <p class="format-popup-msg">Admin has updated the survey formats recently. Please use these latest formats.</p>
        <a href="formats_download.php" class="format-popup-btn">
            <i class="fa-solid fa-download"></i> Open Formats
        </a>
    </div>
</div>
<style>
.format-popup-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;animation:fpFade .18s ease;}
.format-popup-card{position:relative;background:#fff;border-radius:20px;max-width:340px;width:100%;padding:28px 22px 24px;text-align:center;box-shadow:0 25px 60px rgba(0,0,0,.35);animation:fpUp .25s cubic-bezier(.2,.8,.2,1);}
.format-popup-close{position:absolute;top:12px;right:12px;background:#f1f5f9;border:none;width:30px;height:30px;border-radius:50%;color:#475569;font-size:14px;display:flex;align-items:center;justify-content:center;}
.format-popup-icon{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#16a34a,#0ea5e9);color:#fff;font-size:24px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;}
.format-popup-title{font-weight:700;font-size:17px;color:#0f172a;margin:0 0 8px;}
.format-popup-msg{font-size:13.5px;color:#64748b;margin:0 0 18px;line-height:1.5;}
.format-popup-btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#4338ca,#0ea5e9);color:#fff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 20px;border-radius:12px;}
@keyframes fpFade{from{opacity:0}to{opacity:1}}
@keyframes fpUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
</style>
<script>
document.getElementById('formatPopupClose').addEventListener('click', function(){
    document.getElementById('formatPopupOverlay').remove();
});
</script>
<?php endif; ?>

<link rel="stylesheet" href="assets/css/home-app.css?v=<?= @filemtime(__DIR__ . '/assets/css/home-app.css') ?>">
<div class="scroll-content ha-page">
    <div class="ha-band" aria-hidden="true"></div>
    <div class="dash-header">
        <div class="user-welcome">
            <h2>Hi, <?= sanitize($_SESSION['full_name']) ?> 👋</h2>
            <p><?= getGreeting() ?>! Have a productive day.</p>
        </div>
        <div class="dash-header-right" style="display:flex;align-items:center;gap:8px;">
            <?php include 'includes/profile_dropdown.php'; ?>
        </div>
    </div>

    <div class="overview-section" style="margin-top:0;">
        <div class="global-search-wrap">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="globalSearchInput" class="search-control" placeholder="Search vessel name, agent, client, port name..." autocomplete="off" data-testid="global-search-input">
            </div>
            <div id="globalSearchResults" class="global-search-results"></div>
        </div>
    </div>

    <?php
    // Share-as-picture button of a dashboard card (the card is the closest [data-share]; assets/js/card-share.js)
    $csBtn = fn(string $cls = '') => '<button type="button" class="cs-btn' . ($cls !== '' ? ' ' . $cls : '') . '" data-cs aria-label="Share as picture" title="Share as picture" data-testid="card-share-btn"><i class="fa-solid fa-share" aria-hidden="true"></i></button>';
    ?>
    <style>
    /* share-as-picture buttons */
    .cs-btn { flex: none; width: 30px; height: 30px; border-radius: 50%; border: 1px solid #e2e8f0; background: #fff; color: #475569; font-size: 12.5px; display: inline-flex; align-items: center; justify-content: center; padding: 0; cursor: pointer; box-shadow: 0 2px 6px rgba(15,23,42,.08); transition: background .15s, color .15s; }
    button.cs-btn.cs-btn[data-cs] { min-height: 0; min-width: 0; } /* beat header.php mobile button min-height */
    .cs-btn:hover, .cs-btn:focus-visible { background: #1E1B6B; color: #fff; border-color: #1E1B6B; }
    .cs-btn.busy i { animation: cs-spin 1s linear infinite; }
    @keyframes cs-spin { to { transform: rotate(360deg); } }
    .cs-btn.cs-abs { position: absolute; top: 8px; right: 8px; z-index: 2; }
    .stat-card[data-share], .recovery-row-card[data-share] { position: relative; }
    .stat-card[data-share] .stat-title { margin-right: 34px; }
    .cs-btn.cs-light { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.35); color: #fff; box-shadow: none; }
    .cs-btn.cs-light:hover, .cs-btn.cs-light:focus-visible { background: #fff; color: #1E1B6B; border-color: #fff; }
    .section-title-row .cs-btn { margin-left: 8px; }
    .cs-title-acts { display: inline-flex; align-items: center; gap: 4px; }
    /* the picture of a highlight card: its subtitle (date / month) is shown, the "Open ..." line is not */
    .dash-hl a.cs-snap .s { display: block !important; } .dash-hl a.cs-snap .go { display: none !important; }
    .cs-snap .sfr-link { display: none !important; }
    @media (max-width: 575.98px) { .cs-btn.cs-abs { top: 6px; right: 6px; width: 26px; height: 26px; font-size: 11px; } }
    </style>
    <style>
    /* Dashboard highlight cards: Pending Vessels / Reports / Completed and Appointments & Surveys Completed */
    .dash-hl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 10px; }
    /* card: big number | divider | title, subtitle, OPEN ... link */
    .dash-hl a { position: relative; overflow: hidden; display: flex; flex-direction: row; align-items: stretch; padding: 20px 22px; min-height: 124px; border-radius: 20px; color: #fff; text-decoration: none; box-shadow: 0 12px 26px rgba(15,23,42,.16); transition: transform .15s ease, box-shadow .15s ease; min-width: 0; }
    .dash-hl a:hover { transform: translateY(-2px); box-shadow: 0 16px 32px rgba(15,23,42,.22); color: #fff; }
    .dash-hl .pv { background: linear-gradient(120deg, #1e3a8a 0%, #3b82f6 100%); }
    .dash-hl .pr { background: linear-gradient(120deg, #c2410c 0%, #f59e0b 100%); }
    .dash-hl .cv { background: linear-gradient(120deg, #047857 0%, #10b981 100%); }
    .dash-hl .t { background: linear-gradient(120deg, #4B2FD6 0%, #7C3AED 100%); }
    .dash-hl .m { background: linear-gradient(120deg, #0f4cc0 0%, #1d6fe0 100%); }
    .dash-hl .c { background: linear-gradient(120deg, #047857 0%, #10b981 100%); }
    .dash-hl .ic { position: absolute; right: 14px; top: 46px; font-size: 58px; opacity: .14; pointer-events: none; } /* below the share button (top 8px, 30px tall) */
    .dash-hl .n { flex: none; display: flex; align-items: center; justify-content: center; min-width: 56px; padding-right: 18px; margin-right: 18px; border-right: 2px solid rgba(255,255,255,.85); font-size: 46px; font-weight: 800; line-height: 1; letter-spacing: -1px; font-variant-numeric: tabular-nums; }
    .dash-hl .tx { display: flex; flex-direction: column; justify-content: center; gap: 4px; min-width: 0; padding-right: 30px; }
    .dash-hl .l { font-size: 16px; font-weight: 800; line-height: 1.2; }
    .dash-hl .s { font-size: 12.5px; opacity: .92; font-weight: 500; }
    .dash-hl .go { margin-top: 8px; font-size: 10.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; opacity: .95; white-space: nowrap; }
    .dash-hl .go i { font-size: 9px; margin-left: 6px; }
    @media (max-width: 991.98px) { .ha-page .ha-band { height: 200px; } } /* the blue band ends behind the first row of cards (no Today's Overview heading) */
    @media (max-width: 1399.98px) {
        .dash-hl { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .dash-hl > a:last-child:nth-child(odd) { grid-column: 1 / -1; } /* the third card takes the full row */
    }
    @media (max-width: 575.98px) { /* phones keep the earlier compact cards: three a row, number over title */
        .dash-hl { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .dash-hl > a:last-child:nth-child(odd) { grid-column: auto; }
        .dash-hl a { flex-direction: column; gap: 4px; padding: 12px 10px; min-height: 0; border-radius: 14px; box-shadow: 0 10px 24px rgba(15,23,42,.14); }
        .dash-hl .n { display: block; min-width: 0; padding: 0; margin: 0; border: 0; font-size: 24px; line-height: 1.05; letter-spacing: normal; }
        .dash-hl .tx { padding-right: 0; gap: 4px; }
        .dash-hl .l { font-size: 11.5px; font-weight: 700; line-height: 1.25; } .dash-hl .s, .dash-hl .go { display: none; }
        .dash-hl .ic { font-size: 44px; right: -6px; top: -8px; }
    }
    </style>
    <div class="overview-section ha-ov">
        <div class="dash-hl ov-hl" style="margin-top:0" data-testid="dash-overview">
            <a class="pv" href="vessels.php" data-testid="dash-ov-pending-vessels" data-share="Pending Vessels">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-ship ic" aria-hidden="true"></i>
                <span class="n"><?= (int)$pending_vessels ?></span><span class="tx"><span class="l">Pending Vessels</span><span class="s">Waiting for survey</span><span class="go">Open Pending Vessels <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
            <a class="pr" href="reports.php" data-testid="dash-ov-pending-reports" data-share="Pending Reports">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-file-lines ic" aria-hidden="true"></i>
                <span class="n"><?= (int)$pending_reports ?></span><span class="tx"><span class="l">Pending Reports</span><span class="s">Survey done, report to upload</span><span class="go">Open Pending Reports <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
            <a class="cv" href="completed.php" data-testid="dash-ov-completed" data-share="Completed Vessels">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-circle-check ic" aria-hidden="true"></i>
                <span class="n"><?= (int)$completed_vessels ?></span><span class="tx"><span class="l">Completed Vessels</span><span class="s">Report uploaded</span><span class="go">Open Completed <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
        </div>
    </div>

    <?php if (in_array($role, ['Admin', 'Super Admin'], true)):
        // 🌟 Today's appointments, this month's appointments (Monthly Data rule: Appointment Date, else the
        //    Survey Assigned date) and this month's surveys completed (Monthly Data > Survey Completed).
        require_once 'includes/monthly_data.php';
        $hlToday = date('Y-m-d');
        $hlMonthStart = date('Y-m-01');
        $hlMonthEnd = date('Y-m-d', strtotime($hlMonthStart . ' +1 month'));
        $hl = ['today' => 0, 'month' => 0, 'done' => 0];
        try {
            $hl['today'] = mdAppointmentCount($db, $hlToday, date('Y-m-d', strtotime($hlToday . ' +1 day')));
            $hl['month'] = mdAppointmentCount($db, $hlMonthStart, $hlMonthEnd);
            // Monthly Data > Survey Completed: Pending Report + Completed by their Survey Completed Date
            $hl['done'] = (int)dashboardMonthStats($db, 'Admin', 0, 0, (int)date('Y'), (int)date('n'))['ships'];
        } catch (Throwable $e) { error_log('dashboard highlights: ' . $e->getMessage()); }
        $hlMonth = date('Y-m');
    ?>
    <div class="overview-section mt-4" data-testid="dash-highlights">
        <div class="section-title-row"><span class="section-title"><i class="fa-solid fa-calendar-days me-1" style="color:#5B2DEE"></i> Appointments &amp; Surveys Completed</span></div>
        <div class="dash-hl">
            <a class="t" href="monthly_data.php?tab=appointments&amp;month=<?= $hlMonth ?>&amp;day=<?= $hlToday ?>" data-testid="dash-hl-today" data-share="Today's Appointments">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-calendar-day ic" aria-hidden="true"></i>
                <span class="n"><?= (int)$hl['today'] ?></span><span class="tx"><span class="l">Today's Appointments</span><span class="s"><?= strtoupper(date('d-M-Y')) ?></span><span class="go">Open in Monthly Data <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
            <a class="m" href="monthly_data.php?tab=appointments&amp;month=<?= $hlMonth ?>" data-testid="dash-hl-month" data-share="This Month's Appointments">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-calendar-week ic" aria-hidden="true"></i>
                <span class="n"><?= (int)$hl['month'] ?></span><span class="tx"><span class="l">This Month's Appointments</span><span class="s"><?= date('F Y') ?></span><span class="go">Open in Monthly Data <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
            <a class="c" href="monthly_data.php?tab=completed&amp;month=<?= $hlMonth ?>" data-testid="dash-hl-completed" data-share="Surveys Completed This Month">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-circle-check ic" aria-hidden="true"></i>
                <span class="n"><?= (int)$hl['done'] ?></span><span class="tx"><span class="l">Surveys Completed This Month</span><span class="s">By Survey Completed Date · <?= date('F Y') ?></span><span class="go">Open in Monthly Data <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Surveyors see the month charts, Need Attention and the overdue boxes for their own vessels only
    // (assigned to them, or shared with them through survey_surveyors) — the same rule as their other pages.
    $dashScope = $role === 'Surveyor' ? ' AND (s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))' : '';
    $dashScopeP = $role === 'Surveyor' ? [(int)$user_id, (int)$user_id] : [];
    ?>
    <?php if (in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)):
        // 🌟 Month-wise Appointments (Monthly Data rule) and Recovery (Pending Report + Completed by Survey
        //    Completed Date, NOT APPLICABLE types count 0 — same as Monthly Data / Recovery), Jan–Dec, from 2026.
        require_once 'includes/monthly_data.php';
        $mcFirst = 2026;
        $mcLast = max($mcFirst, (int)date('Y'));
        $mcData = [];
        for ($y = $mcFirst; $y <= $mcLast; $y++) $mcData[$y] = ['appt' => array_fill(0, 12, 0), 'rec' => array_fill(0, 12, 0.0)];
        try {
            $from = $mcFirst . '-01-01'; $to = ($mcLast + 1) . '-01-01';
            $q = $db->prepare('SELECT YEAR(d) AS y, MONTH(d) AS m, COUNT(*) AS n FROM (SELECT ' . MD_APPT_DATE_SQL . ' AS d FROM surveys s WHERE 1 = 1' . $dashScope . ') x WHERE d >= ? AND d < ? GROUP BY y, m');
            $q->execute(array_merge($dashScopeP, [$from, $to]));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $mcData[(int)$r['y']]['appt'][(int)$r['m'] - 1] = (int)$r['n'];
            $cd = surveyCompletedDateSql('s');
            $q = $db->prepare("SELECT YEAR($cd) AS y, MONTH($cd) AS m, COALESCE(SUM(" . recoveryCountedSql('s') . "), 0) AS t FROM surveys s
                WHERE s.status IN ('Pending Report','Completed')$dashScope AND $cd >= ? AND $cd < ? GROUP BY y, m");
            $q->execute(array_merge($dashScopeP, [$from, $to]));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $mcData[(int)$r['y']]['rec'][(int)$r['m'] - 1] = round((float)$r['t'], 3);
        } catch (Throwable $e) { error_log('dashboard month charts: ' . $e->getMessage()); }
    ?>
    <style>
    .mc-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .mc-year { border: 1px solid var(--border-color, #e2e8f0); border-radius: 999px; padding: 5px 12px; font-size: 13px; font-weight: 700; color: #0f172a; background: #fff; }
    .mc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 10px; }
    @media (max-width: 767.98px) { .mc-grid { grid-template-columns: 1fr; } }
    .mc-card { background: #fff; border: 1px solid var(--border-color, #e2e8f0); border-radius: 18px; padding: 14px 16px 10px; box-shadow: 0 4px 16px rgba(15,23,42,.05); position: relative; min-width: 0; }
    .mc-t { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
    .mc-t b { font-size: 14px; font-weight: 800; color: #0f172a; display: inline-flex; align-items: center; gap: 7px; }
    .mc-t b i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
    .mc-t span { font-size: 12px; color: #64748b; font-weight: 600; margin-left: auto; }
    .mc-t span strong { color: #0f172a; font-size: 14px; }
    .mc-svg { display: block; width: 100%; height: auto; margin-top: 6px; overflow: visible; }
    .mc-svg text { font-family: inherit; }
    .mc-bar { cursor: pointer; }
    .mc-hit { fill: transparent; cursor: pointer; }
    .mc-tip { position: absolute; pointer-events: none; background: #0f172a; color: #fff; font-size: 12px; font-weight: 600; padding: 6px 10px; border-radius: 8px; white-space: nowrap; transform: translate(-50%, -100%); opacity: 0; transition: opacity .1s; z-index: 5; }
    .mc-tip.on { opacity: 1; }
    .mc-tip small { display: block; font-weight: 500; opacity: .75; }
    </style>
    <div class="overview-section mt-4" data-testid="dash-month-charts">
        <div class="section-title-row mc-head">
            <span class="section-title"><i class="fa-solid fa-chart-column me-1" style="color:#1d6fe0"></i> Month-wise Appointments &amp; Recovery</span>
            <select class="mc-year" data-mc-year aria-label="Year" data-testid="dash-mc-year">
                <?php for ($y = $mcLast; $y >= $mcFirst; $y--): ?><option value="<?= $y ?>"<?= $y === (int)date('Y') ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?>
            </select>
        </div>
        <div class="mc-grid">
            <div class="mc-card" data-mc="appt" data-testid="dash-mc-appt" data-share="Month-wise Appointments {year}">
                <div class="mc-t"><b><i style="background:#1d6fe0"></i>Appointments</b><span>Total <strong data-mc-total>0</strong></span><?= $csBtn() ?></div>
                <svg class="mc-svg" viewBox="0 0 600 230" role="img" aria-label="Appointments per month"></svg>
                <div class="mc-tip"></div>
            </div>
            <div class="mc-card" data-mc="rec" data-testid="dash-mc-rec" data-share="Month-wise Recovery {year}">
                <div class="mc-t"><b><i style="background:#059669"></i>Recovery (MT)</b><span>Total <strong data-mc-total>0</strong></span><?= $csBtn() ?></div>
                <svg class="mc-svg" viewBox="0 0 600 230" role="img" aria-label="Recovery per month"></svg>
                <div class="mc-tip"></div>
            </div>
        </div>
    </div>
    <script>
    (function () {
      var LINK = <?= json_encode($role !== 'Surveyor') ?>; // Monthly Data is Admin / Super Admin only
      var DATA = <?= json_encode($mcData) ?>, MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      var CFG = { appt: { color: '#1d6fe0', tab: 'appointments', fmt: function (v) { return String(v); }, unit: function (v) { return v === 1 ? 'appointment' : 'appointments'; } },
                  rec: { color: '#059669', tab: 'recovery', fmt: function (v) { return v.toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }, unit: function () { return 'MT recovery'; } } };
      var sel = document.querySelector('[data-mc-year]'), NS = 'http://www.w3.org/2000/svg';
      function el(n, a, t) { var e = document.createElementNS(NS, n); for (var k in a) e.setAttribute(k, a[k]); if (t != null) e.textContent = t; return e; }
      function nice(max) { if (max <= 0) return 1; var p = Math.pow(10, Math.floor(Math.log10(max))), f = max / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; }
      function draw(card, key, year) {
        var c = CFG[key], vals = (DATA[year] || {})[key] || [], svg = card.querySelector('svg'), tip = card.querySelector('.mc-tip');
        while (svg.firstChild) svg.removeChild(svg.firstChild);
        var L = 44, R = 8, T = 18, B = 26, W = 600, H = 230, pw = W - L - R, ph = H - T - B, top = nice(Math.max.apply(null, vals.concat([0])));
        if (key === 'appt') top = Math.max(4, Math.ceil(top / 4) * 4); // whole numbers on the count axis
        var total = vals.reduce(function (a, b) { return a + b; }, 0);
        card.querySelector('[data-mc-total]').textContent = c.fmt(key === 'rec' ? Math.round(total * 1000) / 1000 : total) + (key === 'rec' ? ' MT' : '');
        for (var g = 0; g <= 4; g++) { // recessive grid + y labels
          var v = top * g / 4, y = T + ph - ph * g / 4;
          svg.appendChild(el('line', { x1: L, x2: W - R, y1: y, y2: y, stroke: g ? '#eef2f7' : '#cbd5e1', 'stroke-width': 1 }));
          svg.appendChild(el('text', { x: L - 8, y: y + 4, 'text-anchor': 'end', 'font-size': 11, fill: '#94a3b8' }, String(Math.round(v * 10) / 10)));
        }
        var slot = pw / 12, bw = Math.min(30, slot * 0.6), maxI = vals.indexOf(Math.max.apply(null, vals));
        vals.forEach(function (v, i) {
          var x = L + slot * i + (slot - bw) / 2, h = top ? ph * v / top : 0, y = T + ph - h;
          if (h > 0) {
            var r = Math.min(4, h, bw / 2); // 4px rounded top, square on the baseline
            svg.appendChild(el('path', { class: 'mc-bar', fill: c.color, d: 'M' + x + ',' + (T + ph) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y + 'H' + (x + bw - r) + 'Q' + (x + bw) + ',' + y + ' ' + (x + bw) + ',' + (y + r) + 'V' + (T + ph) + 'Z' }));
          }
          if (v > 0) { // every month's value; the highest one bold in the chart's colour
            var top1 = i === maxI;
            svg.appendChild(el('text', { x: x + bw / 2, y: y - 6, 'text-anchor': 'middle', 'font-size': top1 ? 12.5 : 10.5, 'font-weight': top1 ? 800 : 600, fill: top1 ? c.color : '#64748b' },
              // big recovery figures are shortened to one decimal so neighbours don't touch (the tooltip has the exact value)
              !top1 && key === 'rec' && v >= 1000 ? v.toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : c.fmt(v)));
          }
          svg.appendChild(el('text', { x: L + slot * i + slot / 2, y: H - 8, 'text-anchor': 'middle', 'font-size': 11, fill: '#64748b' }, MON[i]));
          var hit = el('rect', { class: 'mc-hit', x: L + slot * i, y: T, width: slot, height: ph + B, 'data-testid': 'dash-mc-' + key + '-' + (i + 1) });
          var mm = year + '-' + ('0' + (i + 1)).slice(-2);
          hit.addEventListener('mouseenter', function () {
            var box = svg.getBoundingClientRect(), cb = card.getBoundingClientRect(), k = box.width / W; // SVG has no offsetLeft: place from the card's box
            tip.innerHTML = '<span></span><small></small>'; tip.firstChild.textContent = c.fmt(v) + ' ' + c.unit(v); tip.lastChild.textContent = MON[i] + ' ' + year + (LINK ? ' · click to open' : '');
            tip.style.left = (box.left - cb.left + (L + slot * i + slot / 2) * k) + 'px'; tip.style.top = (box.top - cb.top + Math.max(y, T + 10) * k - 6) + 'px'; tip.classList.add('on');
          });
          hit.addEventListener('mouseleave', function () { tip.classList.remove('on'); });
          if (LINK) hit.addEventListener('click', function () { location.href = 'monthly_data.php?tab=' + c.tab + '&month=' + mm; });
          else hit.style.cursor = 'default';
          var tt = el('title', {}, MON[i] + ' ' + year + ': ' + c.fmt(v)); hit.appendChild(tt);
          svg.appendChild(hit);
        });
      }
      function all() { var y = sel.value; ['appt', 'rec'].forEach(function (k) { draw(document.querySelector('[data-mc="' + k + '"]'), k, y); }); }
      sel.addEventListener('change', all);
      all();
    })();
    </script>
    <?php endif; ?>

    <?php if (in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)):
        // 🌟 Need Attention: vessels whose ETA / ETB / ETC / Expected Supply Date (set on the vessel page) is today,
        //    tomorrow or the day after — a 3 x 4 table, red strongest for today. Pending Vessels only.
        require_once 'includes/vessel_dates.php';
        vesselDatesEnsure($db);
        $naDays = [];
        for ($d = 0; $d < 3; $d++) $naDays[] = date('Y-m-d', strtotime("+$d day"));
        $naCols = ['eta' => 'eta_date', 'etb' => 'etb_date', 'etc' => 'etc_date', 'esd' => 'expected_supply_date'];
        $naGrid = [];
        foreach ($naDays as $d) foreach ($naCols as $k => $c) $naGrid[$d][$k] = [];
        try {
            $q = $db->prepare("SELECT s.id, s.vessel_name, s.status, s.survey_type_ids, s.eta_date, s.etb_date, s.etc_date, s.expected_supply_date, st.type_name, p.port_name
                FROM surveys s LEFT JOIN survey_types st ON st.id = s.survey_type_id LEFT JOIN ports p ON p.id = s.port_id
                WHERE s.status = 'Pending Vessel' AND (s.eta_date BETWEEN ? AND ? OR s.etb_date BETWEEN ? AND ? OR s.etc_date BETWEEN ? AND ? OR s.expected_supply_date BETWEEN ? AND ?)$dashScope
                ORDER BY s.vessel_name ASC, s.id ASC");
            $q->execute(array_merge([$naDays[0], $naDays[2], $naDays[0], $naDays[2], $naDays[0], $naDays[2], $naDays[0], $naDays[2]], $dashScopeP));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                foreach ($naCols as $k => $c) {
                    $v = substr((string)($r[$c] ?? ''), 0, 10);
                    if (!isset($naGrid[$v][$k])) continue;
                    $naGrid[$v][$k][] = [
                        'id' => (int)$r['id'], 'name' => (string)$r['vessel_name'],
                        'type' => getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? ''),
                        'port' => (string)($r['port_name'] ?? ''),
                        'url' => 'vessel_detail.php?id=' . (int)$r['id'],
                    ];
                }
            }
        } catch (Throwable $e) { error_log('dashboard need attention: ' . $e->getMessage()); }
        $naLabels = ['TODAY', 'TOMORROW', 'DAY AFTER'];
        $naTotal = 0; foreach ($naGrid as $row) foreach ($row as $cell) $naTotal += count($cell);
    ?>
    <style>
    .na-card { background: #fff; border: 1px solid var(--border-color, #e2e8f0); border-radius: 18px; box-shadow: 0 4px 16px rgba(15,23,42,.05); overflow: hidden; margin-top: 10px; }
    .na-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .na-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 700px; table-layout: fixed; }
    .na-table th, .na-table td { text-transform: uppercase; font-weight: 800; }
    .na-table thead th { background: #0f172a; color: #fff; font-size: 13px; letter-spacing: .08em; padding: 11px 12px; text-align: center; }
    .na-table thead th:first-child { width: 128px; text-align: left; background: #1e293b; }
    .na-table tbody th { text-align: left; padding: 12px; font-size: 12.5px; letter-spacing: .04em; border-top: 1px solid rgba(255,255,255,.6); vertical-align: top; }
    .na-table tbody th small { display: block; font-size: 11px; font-weight: 700; opacity: .8; margin-top: 2px; letter-spacing: .02em; }
    .na-table td { vertical-align: top; padding: 8px; border-top: 1px solid #fff; border-left: 1px solid #fff; }
    .na-d0 th { background: #b91c1c; color: #fff; } .na-d0 td.has { background: #fca5a5; }
    .na-d1 th { background: #ef4444; color: #fff; } .na-d1 td.has { background: #fecaca; }
    .na-d2 th { background: #f87171; color: #fff; } .na-d2 td.has { background: #fee2e2; }
    .na-table td:not(.has) { background: #fff; }
    .na-v { display: block; text-decoration: none; color: #450a0a; padding: 7px 9px; border-radius: 10px; background: rgba(255,255,255,.55); margin-bottom: 6px; }
    .na-v:last-child { margin-bottom: 0; }
    .na-v:hover { background: #fff; color: #7f1d1d; }
    .na-v b { display: block; font-size: 14.5px; line-height: 1.2; font-weight: 800; overflow-wrap: anywhere; }
    .na-v small { display: block; font-size: 10.5px; font-weight: 700; color: #7f1d1d; margin-top: 2px; line-height: 1.3; }
    .na-empty { padding: 12px 16px; font-size: 12.5px; color: #64748b; font-weight: 600; }
    @media (max-width: 575.98px) {
        .na-table { min-width: 600px; }
        .na-table thead th:first-child { width: 94px; }
        .na-table tbody th { letter-spacing: 0; }
        .na-table thead th, .na-table tbody th { padding: 9px 8px; font-size: 11px; }
        .na-table td { padding: 6px; }
        .na-v { padding: 6px 7px; } .na-v b { font-size: 12.5px; } .na-v small { font-size: 9.5px; }
    }
    </style>
    <div class="overview-section mt-4" data-testid="dash-need-attention" data-share="Need Attention">
        <div class="section-title-row"><span class="section-title"><i class="fa-solid fa-triangle-exclamation me-1" style="color:#dc2626"></i> Need Attention</span><?= $csBtn() ?></div>
        <div class="na-card">
            <div class="na-wrap">
                <table class="na-table" data-testid="dash-na-table">
                    <thead><tr><th>Day</th><th>ETA</th><th>ETB</th><th>ETC</th><th>EXP. SUPPLY</th></tr></thead>
                    <tbody>
                    <?php foreach ($naDays as $i => $d): ?>
                        <tr class="na-d<?= $i ?>">
                            <th scope="row"><?= $naLabels[$i] ?><small><?= strtoupper(date('d-M-y', strtotime($d))) ?></small></th>
                            <?php foreach (array_keys($naCols) as $k): $cell = $naGrid[$d][$k]; ?>
                            <td class="<?= $cell ? 'has' : '' ?>" data-testid="dash-na-<?= $i ?>-<?= $k ?>">
                                <?php foreach ($cell as $v): ?>
                                <a class="na-v" href="<?= sanitize($v['url']) ?>"><b><?= sanitize(mb_strtoupper($v['name'])) ?></b><small><?= sanitize(mb_strtoupper(trim($v['type'] . ($v['port'] !== '' ? ' · ' . $v['port'] : ''), ' ·'))) ?></small></a>
                                <?php endforeach; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$naTotal): ?><div class="na-empty" data-testid="dash-na-empty">No pending vessel with ETA, ETB, ETC or Expected Supply Date today, tomorrow or the day after.</div><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)):
        // 🌟 Overdue lists: Pending Vessels more than 30 days after their Appointment Date, and Pending Reports
        //    more than 15 days after their Survey Completed Date — oldest first, days counted as on the vessel
        //    pages' overdue alert (calendar days since that date). Vessels without that date are not listed.
        $odLists = ['pv' => [], 'pr' => []];
        try {
            $odSql = function (string $status, string $col, int $days) use ($db, $dashScope, $dashScopeP): array {
                $today = date('Y-m-d'); // the app's day (Asia/Kolkata), as the vessel pages' overdue alert
                $q = $db->prepare("SELECT s.id, s.vessel_name, DATEDIFF(?, DATE(s.$col)) AS days FROM surveys s
                    WHERE s.status = ? AND YEAR(s.$col) > 0 AND DATEDIFF(?, DATE(s.$col)) > ?$dashScope ORDER BY days DESC, s.id ASC");
                $q->execute(array_merge([$today, $status, $today, $days], $dashScopeP));
                return $q->fetchAll(PDO::FETCH_ASSOC);
            };
            $odLists['pv'] = $odSql('Pending Vessel', 'appointment_date', 30);
            $odLists['pr'] = $odSql('Pending Report', 'survey_completed_date', 15);
        } catch (Throwable $e) { error_log('dashboard overdue lists: ' . $e->getMessage()); }
        $odBoxes = [
            'pv' => ['title' => 'Pending Vessels Overdue', 'sub' => 'More than 30 days since the Appointment Date', 'icon' => 'fa-ship', 'page' => 'vessel_detail.php', 'col' => 'Days since appointment'],
            'pr' => ['title' => 'Pending Reports Overdue', 'sub' => 'More than 15 days since the Survey Completed Date', 'icon' => 'fa-file-lines', 'page' => 'report_detail.php', 'col' => 'Days since survey'],
        ];
    ?>
    <style>
    .od-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 10px; }
    @media (max-width: 767.98px) { .od-grid { grid-template-columns: 1fr; } }
    .od-box { background: #fff; border: 1px solid var(--border-color, #e2e8f0); border-radius: 18px; box-shadow: 0 4px 16px rgba(15,23,42,.05); overflow: hidden; display: flex; flex-direction: column; min-width: 0; }
    .od-h { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: linear-gradient(120deg, #7f1d1d 0%, #dc2626 100%); color: #fff; }
    .od-h.pr { background: linear-gradient(120deg, #9a3412 0%, #f97316 100%); }
    .od-h i { font-size: 18px; opacity: .9; }
    .od-h b { display: block; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; }
    .od-h small { display: block; font-size: 11px; opacity: .85; }
    .od-h .n { margin-left: auto; font-size: 22px; font-weight: 800; }
    .od-list { max-height: 330px; overflow-y: auto; }
    .od-row { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: #0f172a; }
    .od-row:hover { background: #FEF2F2; color: #0f172a; }
    .od-row .v { flex: 1 1 auto; min-width: 0; font-size: 13.5px; font-weight: 800; text-transform: uppercase; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .od-row .d { flex: none; font-size: 12px; font-weight: 800; color: #b91c1c; background: #FEE2E2; border-radius: 999px; padding: 3px 10px; white-space: nowrap; }
    .od-row.pr .d { color: #9a3412; background: #FFEDD5; }
    .od-colh { display: flex; justify-content: space-between; padding: 8px 14px; background: #F8FAFC; border-bottom: 1px solid #e2e8f0; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .06em; }
    .od-empty { padding: 18px 14px; font-size: 13px; color: #16a34a; font-weight: 600; }
    </style>
    <div class="overview-section mt-4" data-testid="dash-overdue">
        <div class="od-grid">
            <?php foreach ($odBoxes as $k => $bx): $list = $odLists[$k]; ?>
            <div class="od-box" data-testid="dash-od-<?= $k ?>" data-share="<?= $bx['title'] ?>">
                <div class="od-h <?= $k ?>"><i class="fa-solid <?= $bx['icon'] ?>" aria-hidden="true"></i><div><b><?= $bx['title'] ?></b><small><?= $bx['sub'] ?></small></div><span class="n" data-testid="dash-od-<?= $k ?>-count"><?= count($list) ?></span><?= $csBtn('cs-light') ?></div>
                <?php if ($list): ?>
                <div class="od-colh"><span>Vessel</span><span><?= $bx['col'] ?></span></div>
                <div class="od-list">
                    <?php foreach ($list as $r): ?>
                    <a class="od-row <?= $k ?>" href="<?= $bx['page'] ?>?id=<?= (int)$r['id'] ?>" data-testid="dash-od-<?= $k ?>-row"><span class="v"><?= sanitize($r['vessel_name']) ?></span><span class="d"><?= (int)$r['days'] ?> days</span></a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="od-empty"><i class="fa-solid fa-circle-check me-1"></i> Nothing overdue.</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($role === 'Super Admin'):
        // 🌟 Super Admin Analytics — four cards in the Pending Vessels card design:
        //    ships per day / month (Completed ships over the span of their completed dates) and
        //    recovery per month / per ship (the Surveyor Performance "All vessels" averages).
        // Average ships per year/month/day, based on the actual date span of
        // completed surveys (first completed → most recently completed).
        $span_row = $db->query("
            SELECT COUNT(*) AS cnt,
                   MIN(COALESCE(survey_completed_date, report_uploaded_date)) AS min_d,
                   MAX(COALESCE(survey_completed_date, report_uploaded_date)) AS max_d
            FROM surveys WHERE status = 'Completed'
        ")->fetch(PDO::FETCH_ASSOC);

        $sa_completed_count = (int)($span_row['cnt'] ?? 0);
        $sa_avg_per_year = 0.0; $sa_avg_per_month = 0.0; $sa_avg_per_day = 0.0;
        if ($sa_completed_count > 0 && !empty($span_row['min_d']) && !empty($span_row['max_d'])) {
            $minTs = strtotime($span_row['min_d']);
            $maxTs = strtotime($span_row['max_d']);
            $daySpan = max(1, (int)round(($maxTs - $minTs) / 86400) + 1);
            $monthSpan = max(1, ((int)date('Y', $maxTs) - (int)date('Y', $minTs)) * 12 + ((int)date('n', $maxTs) - (int)date('n', $minTs)) + 1);
            $yearSpan = max(1, (int)date('Y', $maxTs) - (int)date('Y', $minTs) + 1);
            $sa_avg_per_day = $sa_completed_count / $daySpan;
            $sa_avg_per_month = $sa_completed_count / $monthSpan;
            $sa_avg_per_year = $sa_completed_count / $yearSpan;
        }
        $sa_rec_month = (float)($sfr_total['avg_month'] ?? 0);
        $sa_rec_ship = (float)($sfr_total['avg_ship'] ?? 0);
    ?>
    <style>
    .dash-hl.sa-hl { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (max-width: 575.98px) { .dash-hl.sa-hl { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>
    <div class="overview-section mt-4" data-testid="dash-sa-analytics">
        <div class="section-title-row"><span class="section-title"><i class="fa-solid fa-chart-line me-1" style="color:#4B2FD6"></i> Super Admin Analytics</span></div>
        <div class="dash-hl sa-hl">
            <a class="pv" href="completed.php" data-testid="avg-ships-per-day-card" data-share="Average Ships Per Day">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-calendar-day ic" aria-hidden="true"></i>
                <span class="n"><?= number_format($sa_avg_per_day, 2) ?></span><span class="tx"><span class="l">Average Ships Per Day</span><span class="s">Completed ships ÷ days, first to last completed</span><span class="go">Open Completed <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
            <a class="pr" href="completed.php" data-testid="avg-ships-per-month-super-card" data-share="Average Ships Per Month">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-calendar-days ic" aria-hidden="true"></i>
                <span class="n"><?= number_format($sa_avg_per_month, 1) ?></span><span class="tx"><span class="l">Average Ships Per Month</span><span class="s">Completed ships ÷ months, first to last completed</span><span class="go">Open Completed <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
            <a class="t" href="recovery.php" data-testid="avg-recovery-per-month-card" data-share="Avg Recovery per Month">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-gas-pump ic" aria-hidden="true"></i>
                <span class="n"><?= number_format($sa_rec_month, 3) ?></span><span class="tx"><span class="l">Avg Recovery per Month</span><span class="s">MT · total recovery ÷ months since the first ship</span><span class="go">Open Recovery <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
            <a class="c" href="recovery.php" data-testid="avg-recovery-per-ship-card" data-share="Avg Recovery per Ship">
                <?= $csBtn('cs-abs cs-light') ?>
                <i class="fa-solid fa-ship ic" aria-hidden="true"></i>
                <span class="n"><?= number_format($sa_rec_ship, 3) ?></span><span class="tx"><span class="l">Avg Recovery per Ship</span><span class="s">MT · total recovery ÷ ships with recovery</span><span class="go">Open Recovery <i class="fa-solid fa-chevron-right"></i></span></span>
            </a>
        </div>
    </div>
    <?php endif; ?>
    <?php if (in_array($role, ['Admin', 'Super Admin'], true)):
        // 🌟 Country-wise and Client-wise ships & recovery — the Surveyor Performance rules: a ship counts once
        //    it has left Pending Vessels (Pending Report + Completed); recovery as the Total Recovery card
        //    (NOT APPLICABLE survey types count as 0). Country = the port's country (blank → India, as the
        //    other country figures); a vessel without a port / client is listed as "not set".
        $cwLists = ['country' => [], 'client' => []];
        try {
            ensurePortsCountryColumn($db);
            $cwRec = recoveryCountedSql('s');
            $cwDone = "s.status IN ('Pending Report','Completed')";
            $cwLists['country'] = $db->query("
                SELECT CASE WHEN p.id IS NULL THEN 'Port not set' ELSE COALESCE(NULLIF(TRIM(p.country), ''), 'India') END AS name,
                       COUNT(*) AS ships, COALESCE(SUM($cwRec), 0) AS recovery
                FROM surveys s LEFT JOIN ports p ON p.id = s.port_id
                WHERE $cwDone GROUP BY name ORDER BY ships DESC, recovery DESC, name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            $cwLists['client'] = $db->query("
                SELECT COALESCE(NULLIF(TRIM(c.company_name), ''), 'Client not set') AS name,
                       COUNT(*) AS ships, COALESCE(SUM($cwRec), 0) AS recovery
                FROM surveys s LEFT JOIN clients c ON c.id = s.client_id
                WHERE $cwDone GROUP BY s.client_id, name ORDER BY ships DESC, recovery DESC, name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('dashboard country / client tables: ' . $e->getMessage()); }
        $cwBoxes = [
            'country' => ['title' => 'Country-wise Ships & Recovery', 'sub' => 'Ships attended and recovery by the port\'s country', 'icon' => 'fa-earth-asia', 'col' => 'Country'],
            'client' => ['title' => 'Client-wise Ships & Recovery', 'sub' => 'Ships attended and recovery by client', 'icon' => 'fa-building', 'col' => 'Client'],
        ];
        $cwMt = fn($v) => number_format((float)$v, 3);
    ?>
    <style>
    .cw-h { background: linear-gradient(120deg, #1e3a8a 0%, #3b82f6 100%); }
    .cw-h.client { background: linear-gradient(120deg, #4B2FD6 0%, #7C3AED 100%); }
    .cw-colh, .cw-row, .cw-tot { display: grid; grid-template-columns: minmax(0, 1fr) 64px 118px; gap: 10px; align-items: center; padding: 9px 14px; }
    .cw-colh { background: #F8FAFC; border-bottom: 1px solid #e2e8f0; font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .06em; }
    .cw-colh span + span, .cw-row span + span, .cw-tot span + span { text-align: right; }
    .cw-row { border-bottom: 1px solid #f1f5f9; color: #0f172a; font-size: 13px; }
    .cw-row:hover { background: #F8FAFC; }
    .cw-row .v { font-weight: 800; text-transform: uppercase; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cw-row .sh { font-weight: 800; }
    .cw-row .rc { font-weight: 800; color: #4B2FD6; white-space: nowrap; }
    .cw-u { font-size: 10px; font-weight: 700; color: #94a3b8; margin-left: 2px; }
    .cw-tot { margin-top: auto; background: #1E1B6B; color: #fff; font-weight: 800; font-size: 13px; }
    .cw-tot .cw-u { color: rgba(255,255,255,.6); }
    .cs-snap .od-list { max-height: none !important; }
    @media (max-width: 575.98px) { .cw-colh, .cw-row, .cw-tot { grid-template-columns: minmax(0, 1fr) 44px 104px; gap: 8px; } .cw-row .v { white-space: normal; overflow-wrap: anywhere; } }
    </style>
    <div class="overview-section mt-4" data-testid="dash-country-client">
        <div class="od-grid">
            <?php foreach ($cwBoxes as $k => $bx): $list = $cwLists[$k]; ?>
            <div class="od-box" data-testid="dash-cw-<?= $k ?>" data-share="<?= $bx['title'] ?>">
                <div class="od-h cw-h <?= $k ?>"><i class="fa-solid <?= $bx['icon'] ?>" aria-hidden="true"></i><div><b><?= $bx['title'] ?></b><small><?= $bx['sub'] ?></small></div><span class="n" data-testid="dash-cw-<?= $k ?>-count" title="<?= count($list) ?> <?= $k === 'country' ? 'countries' : 'clients' ?>"><?= count($list) ?></span><?= $csBtn('cs-light') ?></div>
                <?php if ($list): ?>
                <div class="cw-colh"><span><?= $bx['col'] ?></span><span>Ships</span><span>Recovery</span></div>
                <div class="od-list">
                    <?php foreach ($list as $r): ?>
                    <div class="cw-row" data-testid="dash-cw-<?= $k ?>-row"><span class="v" title="<?= sanitize($r['name']) ?>"><?= sanitize($r['name']) ?></span><span class="sh"><?= (int)$r['ships'] ?></span><span class="rc"><?= $cwMt($r['recovery']) ?><span class="cw-u">MT</span></span></div>
                    <?php endforeach; ?>
                </div>
                <div class="cw-tot" data-testid="dash-cw-<?= $k ?>-total"><span>Total</span><span><?= array_sum(array_map(fn($r) => (int)$r['ships'], $list)) ?></span><span><?= $cwMt(array_sum(array_map(fn($r) => (float)$r['recovery'], $list))) ?><span class="cw-u">MT</span></span></div>
                <?php else: ?>
                <div class="od-empty" style="color:#64748b"><i class="fa-solid fa-circle-info me-1"></i> No ships attended yet.</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (in_array($role, ['Admin', 'Super Admin'], true)): ?>
    <div class="overview-section mt-4" data-share="Surveyor Performance">
        <div class="section-title-row">
            <span class="section-title">Surveyor Performance — Full Report</span>
            <span class="cs-title-acts"><a href="recovery.php" class="sfr-link" data-testid="sfr-recovery-link">Recovery details <i class="fa-solid fa-chevron-right"></i></a><?= $csBtn() ?></span>
        </div>
        <?php
            $spInitials = function (string $n): string { $w = preg_split('/\s+/', trim($n)); return strtoupper(mb_substr($w[0] ?? '', 0, 1) . mb_substr($w[count($w) - 1] ?? '', 0, 1)); };
            $spGrad = ['#4B2FD6,#7C3AED', '#0f4cc0,#1d6fe0', '#047857,#10b981', '#c2410c,#f59e0b', '#be185d,#ec4899', '#0e7490,#06b6d4'];
            $spMt = fn($v) => number_format((float)$v, 3);
        ?>
        <style>
        .sp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 20px; box-shadow: 0 4px 20px rgba(15,23,42,.05); overflow: hidden; margin-top: 10px; }
        .sp-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .sp-table { width: 100%; border-collapse: collapse; min-width: 860px; font-size: 13px; }
        .sp-table thead th { background: #F8FAFC; color: #64748b; font-weight: 700; font-size: 10.5px; letter-spacing: .06em; text-transform: uppercase; padding: 13px 12px; text-align: right; border-bottom: 1px solid #e2e8f0; line-height: 1.35; vertical-align: bottom; }
        .sp-table thead th:first-child { text-align: left; }
        .sp-table thead th.grp-rec { color: #5B2DEE; }
        .sp-table tbody td { padding: 12px; border-bottom: 1px solid #f1f5f9; text-align: right; color: #334155; white-space: nowrap; vertical-align: middle; }
        .sp-table tbody tr:hover td { background: #FAF8FF; }
        .sp-table tbody tr:last-child td { border-bottom: none; }
        .sp-table td:first-child, .sp-table th:first-child { position: sticky; left: 0; z-index: 1; }
        .sp-table tbody td:first-child { text-align: left; background: #fff; }
        .sp-table thead th:first-child { background: #F8FAFC; }
        .sp-name { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #0f172a; font-weight: 700; font-size: 13.5px; }
        .sp-name:hover { color: #5B2DEE; }
        .sp-av { width: 32px; height: 32px; border-radius: 50%; color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex: none; }
        .sp-inactive { font-size: 10px; font-weight: 700; color: #94a3b8; background: #F1F5F9; border-radius: 999px; padding: 1px 7px; margin-left: 4px; }
        .sp-n { font-weight: 800; color: #0f172a; font-size: 14px; }
        .sp-pill { display: inline-block; min-width: 28px; text-align: center; padding: 3px 10px; border-radius: 999px; font-weight: 800; font-size: 12.5px; background: #EFF6FF; color: #1D4ED8; }
        .sp-pill.zero { background: #F1F5F9; color: #94a3b8; }
        .sp-rec { font-weight: 800; color: #4B2FD6; }
        .sp-rec-m { font-weight: 800; color: #047857; }
        .sp-avg { color: #475569; font-weight: 600; }
        .sp-u { font-size: 10.5px; font-weight: 700; color: #94a3b8; margin-left: 2px; }
        .sp-table tfoot td { padding: 13px 12px; text-align: right; white-space: nowrap; font-weight: 800; color: #fff; background: #1E1B6B; }
        .sp-table tfoot td:first-child { text-align: left; position: sticky; left: 0; background: #0f172a; }
        .sp-table tfoot small { display: block; font-size: 10px; font-weight: 600; opacity: .65; }
        .sp-table tfoot .sp-u { color: rgba(255,255,255,.6); }
        .sp-empty { padding: 28px; text-align: center; color: #94a3b8; }
        /* column pager: phones only */
        .sp-nav { display: none; }
        @media (max-width: 575.98px) {
            .sp-table { min-width: 0; }
            .sp-table tr > :nth-child(n+2) { display: none; }
            .sp-table[data-col="2"] tr > :nth-child(2), .sp-table[data-col="3"] tr > :nth-child(3), .sp-table[data-col="4"] tr > :nth-child(4),
            .sp-table[data-col="5"] tr > :nth-child(5), .sp-table[data-col="6"] tr > :nth-child(6), .sp-table[data-col="7"] tr > :nth-child(7) { display: table-cell; }
            .sp-table td:first-child, .sp-table th:first-child { width: 50%; }
            .sp-table tbody td:first-child { white-space: normal; }
            .sp-table thead th { vertical-align: middle; padding: 10px 8px; letter-spacing: .03em; }
            .sp-th { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
            .sp-th-t { text-align: center; }
            .sp-table button.sp-nav[data-sp-nav] { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 26px; height: 26px; min-height: 0; min-width: 0; padding: 0; border-radius: 50%; border: 1px solid #e2e8f0; background: #fff; color: #5B2DEE; font-size: 10px; cursor: pointer; }
            .sp-table button.sp-nav[data-sp-nav]:active { background: #F3EEFE; }
            .cs-snap .sp-table button.sp-nav[data-sp-nav] { display: none; } /* the picture shows the columns only */
        }
        </style>
        <div class="sp-card" data-testid="surveyor-full-report-card">
            <div class="sp-wrap">
                <table class="sp-table" data-col="2" data-testid="surveyor-full-report-grid">
                    <thead>
                        <tr>
                            <th>Surveyor Name</th>
<?php
                            // Phones show the name and one figure column at a time; < and > step through them.
                            $spCols = [['Total Ships<br>Attended', ''], ['Ships Attended -<br>This Month', ''], ['Total<br>Recovery', 'grp-rec'], ['Recovery -<br>This Month', 'grp-rec'], ['Avg Recovery<br>per Ship', ''], ['Avg Recovery<br>per Month', '']];
                            foreach ($spCols as $ci => [$label, $cls]): ?>
                            <th<?= $cls !== '' ? ' class="' . $cls . '"' : '' ?>><span class="sp-th"><?php if ($ci > 0): ?><button type="button" class="sp-nav" data-sp-nav="-1" aria-label="Previous column" data-testid="sfr-prev"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button><?php endif; ?><span class="sp-th-t"><?= $label ?></span><?php if ($ci < count($spCols) - 1): ?><button type="button" class="sp-nav" data-sp-nav="1" aria-label="Next column" data-testid="sfr-next"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button><?php endif; ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($surveyor_full_report)): foreach ($surveyor_full_report as $i => $row): ?>
                        <tr data-testid="sfr-row">
                            <td><a class="sp-name" href="recovery.php?surveyor=<?= (int)$row['id'] ?>"><span class="sp-av" style="background:linear-gradient(135deg,<?= $spGrad[abs(crc32((string)$row['full_name'])) % count($spGrad)] ?>)"><?= sanitize($spInitials((string)$row['full_name'])) ?></span><span><?= sanitize($row['full_name']) ?><?php if (($row['user_status'] ?? '') === 'Inactive'): ?><span class="sp-inactive">INACTIVE</span><?php endif; ?></span></a></td>
                            <td><span class="sp-n"><?= (int)$row['ships_total'] ?></span></td>
                            <td><span class="sp-pill<?= (int)$row['ships_month'] ? '' : ' zero' ?>"><?= (int)$row['ships_month'] ?></span></td>
                            <td><span class="sp-rec"><?= $spMt($row['recovery_total']) ?></span><span class="sp-u">MT</span></td>
                            <td><span class="sp-rec-m"><?= $spMt($row['recovery_month']) ?></span><span class="sp-u">MT</span></td>
                            <td><span class="sp-avg"><?= $spMt($row['avg_ship']) ?></span><span class="sp-u">MT</span></td>
                            <td><span class="sp-avg"><?= $spMt($row['avg_month']) ?></span><span class="sp-u">MT</span></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="sp-empty">No surveyors found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($sfr_total)): ?>
                    <tfoot>
                        <tr class="sfr-total" data-testid="sfr-total-row">
                            <td>All vessels<small>each vessel once</small></td>
                            <td data-testid="sfr-total-ships"><?= (int)$sfr_total['ships_total'] ?></td>
                            <td data-testid="sfr-month-ships"><?= (int)$sfr_total['ships_month'] ?></td>
                            <td data-testid="sfr-total-recovery"><?= $spMt($sfr_total['recovery_total']) ?><span class="sp-u">MT</span></td>
                            <td data-testid="sfr-month-recovery"><?= $spMt($sfr_total['recovery_month']) ?><span class="sp-u">MT</span></td>
                            <td data-testid="sfr-avg-ship"><?= $spMt($sfr_total['avg_ship']) ?><span class="sp-u">MT</span></td>
                            <td data-testid="sfr-avg-month"><?= $spMt($sfr_total['avg_month']) ?><span class="sp-u">MT</span></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-sp-nav]'); if (!b) return;
        var t = b.closest('.sp-table'), c = (+t.getAttribute('data-col') || 2) + (+b.getAttribute('data-sp-nav'));
        t.setAttribute('data-col', String(Math.min(7, Math.max(2, c))));
    });
    </script>

    <div class="overview-section mt-4" data-share="Client Performance" data-testid="client-full-report">
        <div class="section-title-row">
            <span class="section-title">Client Performance — Full Report</span>
            <span class="cs-title-acts"><a href="recovery.php" class="sfr-link" data-testid="cfr-recovery-link">Recovery details <i class="fa-solid fa-chevron-right"></i></a><?= $csBtn() ?></span>
        </div>
        <div class="sp-card" data-testid="client-full-report-card">
            <div class="sp-wrap">
                <table class="sp-table" data-col="2" data-testid="client-full-report-grid">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <?php foreach ($spCols as $ci => [$label, $cls]): ?>
                            <th<?= $cls !== '' ? ' class="' . $cls . '"' : '' ?>><span class="sp-th"><?php if ($ci > 0): ?><button type="button" class="sp-nav" data-sp-nav="-1" aria-label="Previous column" data-testid="cfr-prev"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button><?php endif; ?><span class="sp-th-t"><?= $label ?></span><?php if ($ci < count($spCols) - 1): ?><button type="button" class="sp-nav" data-sp-nav="1" aria-label="Next column" data-testid="cfr-next"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button><?php endif; ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($client_full_report)): foreach ($client_full_report as $row): $cfrName = (string)$row['full_name']; ?>
                        <tr data-testid="cfr-row">
                            <td><?php if ($row['id'] !== null): ?><a class="sp-name" href="recovery.php?client=<?= rawurlencode($cfrName) ?>"><?php else: ?><span class="sp-name"><?php endif; ?><span class="sp-av" style="background:linear-gradient(135deg,<?= $spGrad[abs(crc32($cfrName)) % count($spGrad)] ?>)"><?= sanitize($spInitials($cfrName)) ?></span><span><?= sanitize($cfrName) ?></span><?= $row['id'] !== null ? '</a>' : '</span>' ?></td>
                            <td><span class="sp-n"><?= (int)$row['ships_total'] ?></span></td>
                            <td><span class="sp-pill<?= (int)$row['ships_month'] ? '' : ' zero' ?>"><?= (int)$row['ships_month'] ?></span></td>
                            <td><span class="sp-rec"><?= $spMt($row['recovery_total']) ?></span><span class="sp-u">MT</span></td>
                            <td><span class="sp-rec-m"><?= $spMt($row['recovery_month']) ?></span><span class="sp-u">MT</span></td>
                            <td><span class="sp-avg"><?= $spMt($row['avg_ship']) ?></span><span class="sp-u">MT</span></td>
                            <td><span class="sp-avg"><?= $spMt($row['avg_month']) ?></span><span class="sp-u">MT</span></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="sp-empty">No ships attended yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!empty($sfr_total)): ?>
                    <tfoot>
                        <tr data-testid="cfr-total-row">
                            <td>All clients<small>every vessel once</small></td>
                            <td data-testid="cfr-total-ships"><?= (int)$sfr_total['ships_total'] ?></td>
                            <td><?= (int)$sfr_total['ships_month'] ?></td>
                            <td data-testid="cfr-total-recovery"><?= $spMt($sfr_total['recovery_total']) ?><span class="sp-u">MT</span></td>
                            <td><?= $spMt($sfr_total['recovery_month']) ?><span class="sp-u">MT</span></td>
                            <td><?= $spMt($sfr_total['avg_ship']) ?><span class="sp-u">MT</span></td>
                            <td><?= $spMt($sfr_total['avg_month']) ?><span class="sp-u">MT</span></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick links: stacked on mobile, one neat row on desktop -->
    <!-- Formats Download / Generate Permission Copy / Vessel Lineups: Surveyor only.
         Admin, Client and Super Admin no longer see these on the dashboard. -->
    <?php if ($role === 'Surveyor'): ?>
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

        <div class="overview-section dashboard-action-item mt-3 ha-soon">
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
    <?php endif; ?>
</div>

<script>
(function() {
    var input = document.getElementById('globalSearchInput');
    var box = document.getElementById('globalSearchResults');
    if (!input || !box) return;

    var statusClassMap = {
        'Pending Vessel': 'gsr-status-pending-vessel',
        'Pending Report': 'gsr-status-pending-report',
        'Completed': 'gsr-status-completed',
        'Cancelled': 'gsr-status-cancelled'
    };

    var debounceTimer = null;
    var activeRequest = null;

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function closeResults() {
        box.classList.remove('open');
        box.innerHTML = '';
    }

    function renderResults(results) {
        if (!results.length) {
            box.innerHTML = '<div class="gsr-empty">No matching vessel, agent, client or port found.</div>';
            box.classList.add('open');
            return;
        }
        var html = '';
        results.forEach(function(r) {
            var subParts = [];
            if (r.client_name) subParts.push(r.client_name);
            if (r.agent_name) subParts.push(r.agent_name);
            if (r.port_name) subParts.push(r.port_name);
            var statusClass = statusClassMap[r.status] || 'gsr-status-pending-vessel';
            html += '<a class="gsr-item" href="' + escapeHtml(r.detail_url) + '">' +
                        '<div class="gsr-item-main">' +
                            '<div class="gsr-item-name">' + escapeHtml(r.vessel_name) + '</div>' +
                            '<div class="gsr-item-sub">' + escapeHtml(subParts.join(' · ')) + '</div>' +
                        '</div>' +
                        '<span class="gsr-item-status ' + statusClass + '">' + escapeHtml(r.status) + '</span>' +
                    '</a>';
        });
        box.innerHTML = html;
        box.classList.add('open');
    }

    function runSearch(term) {
        if (activeRequest) activeRequest.abort();
        var controller = new AbortController();
        activeRequest = controller;
        box.innerHTML = '<div class="gsr-loading">Searching…</div>';
        box.classList.add('open');
        fetch('ajax/global_search.php?q=' + encodeURIComponent(term), { signal: controller.signal })
            .then(function(res) { return res.json(); })
            .then(function(data) { renderResults(data.results || []); })
            .catch(function(err) { if (err.name !== 'AbortError') closeResults(); });
    }

    input.addEventListener('input', function() {
        var term = input.value.trim();
        clearTimeout(debounceTimer);
        if (term.length < 2) { closeResults(); return; }
        debounceTimer = setTimeout(function() { runSearch(term); }, 280);
    });

    input.addEventListener('focus', function() {
        if (input.value.trim().length >= 2 && box.innerHTML !== '') box.classList.add('open');
    });

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeResults(); input.blur(); }
    });

    document.addEventListener('click', function(e) {
        if (!box.contains(e.target) && e.target !== input) closeResults();
    });
})();
</script>

<?php include 'includes/recovery_detail.php'; ?>

<style>
.dm-card { grid-column: span 2; }
.dm-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.dm-selects { display: flex; gap: 6px; }
.dm-select { border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 4px 8px; font-size: 12.5px; font-weight: 600; background: #F8FAFC; color: #0f172a; }
.dm-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
.dm-l { font-size: 11px; color: #64748b; font-weight: 600; margin-top: 2px; }
.dm-note { font-size: 10.5px; color: #94a3b8; }
.dm-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.dm-dl { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 10px; background: #ECFDF5; border: 1px solid #A7F3D0; color: #047857; font-size: 12px; font-weight: 700; text-decoration: none; }
.dm-dl:hover { background: #047857; color: #fff; }
.dm-card.loading .stat-val { opacity: .4; }
.sfr-link { font-size: 12.5px; font-weight: 700; color: #4B2FD6; text-decoration: none; white-space: nowrap; }
.sfr-link i { font-size: 10px; }
.sfr-total td { font-weight: 800; border-top: 2px solid #cbd5e1; background: #F8FAFC; }
</style>
<script>
(function () {
    var card = document.querySelector('[data-testid=month-summary-card]');
    if (!card) return;
    var mSel = card.querySelector('[data-dm-month]'), ySel = card.querySelector('[data-dm-year]');
    function load() {
        card.querySelector('[data-dm-download]').href = 'export_month_summary.php?y=' + encodeURIComponent(ySel.value) + '&m=' + encodeURIComponent(mSel.value);
        card.classList.add('loading');
        fetch('ajax/dashboard_month.php?y=' + encodeURIComponent(ySel.value) + '&m=' + encodeURIComponent(mSel.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.success) return;
                card.querySelector('[data-dm-ships]').textContent = j.ships;
                card.querySelector('[data-dm-recovery]').textContent = j.recovery;
                card.querySelector('[data-dm-label]').textContent = 'By Survey Completed Date · ' + j.label;
            })
            .finally(function () { card.classList.remove('loading'); });
    }
    mSel.addEventListener('change', load);
    ySel.addEventListener('change', load);
})();
</script>
<?php
include 'includes/nav.php';
?>
<script src="assets/js/card-share.js?v=<?= @filemtime(__DIR__ . '/assets/js/card-share.js') ?>"></script>
<?php
include 'includes/footer.php';
?>