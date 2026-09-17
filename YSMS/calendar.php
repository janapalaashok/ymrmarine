<?php
/**
 * Survey scheduling calendar — a month-grid view of every survey's
 * assign_date, color-coded by status, so Admin/Super Admin/Surveyor can see
 * at a glance what's scheduled on which day instead of scrolling lists.
 * Role-scoped the same way as vessels.php: Admin/Super Admin see everything,
 * a Surveyor sees only vessels assigned to them (primary or via the
 * survey_surveyors junction table), a Client sees only their own vessels.
 */
require_once 'config/config.php';
checkAuth();

$db = getDB();
$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
$is_client_role = ($role === 'Client');
$client_row_id = $is_client_role ? getClientIdForUser($db, $user_id) : 0;

// ── Month being viewed (default: current month) ──
$month_param = trim((string)($_GET['month'] ?? ''));
if (preg_match('/^\d{4}-\d{2}$/', $month_param)) {
    $view_ts = strtotime($month_param . '-01');
} else {
    $view_ts = strtotime(date('Y-m-01'));
}
$year = (int)date('Y', $view_ts);
$month = (int)date('n', $view_ts);
$month_start = date('Y-m-01', $view_ts);
$month_end = date('Y-m-t', $view_ts);
$prev_month = date('Y-m', strtotime('-1 month', $view_ts));
$next_month = date('Y-m', strtotime('+1 month', $view_ts));
$today = date('Y-m-d');

// ── Optional surveyor filter (Admin/Super Admin only) ──
$filter_surveyor_id = $is_full_access ? (int)($_GET['surveyor_id'] ?? 0) : 0;

$where = ['s.assign_date BETWEEN ? AND ?'];
$params = [$month_start, $month_end];
if ($is_client_role) {
    $where[] = 's.client_id = ?';
    $params[] = $client_row_id;
} elseif (!$is_full_access) {
    $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
    $params[] = $user_id;
    $params[] = $user_id;
} elseif ($filter_surveyor_id > 0) {
    $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
    $params[] = $filter_surveyor_id;
    $params[] = $filter_surveyor_id;
}

$sql = "
    SELECT s.id, s.vessel_name, s.status, s.assign_date, p.port_name, u.full_name AS surveyor_name
    FROM surveys s
    JOIN ports p ON s.port_id = p.id
    JOIN users u ON s.surveyor_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.assign_date ASC, s.id ASC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Group by day-of-month
$by_day = [];
foreach ($rows as $r) {
    if (empty($r['assign_date']) || $r['assign_date'] === '0000-00-00') continue;
    $d = (int)date('j', strtotime($r['assign_date']));
    $by_day[$d][] = $r;
}

$surveyor_list = $is_full_access
    ? $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND status = 'Active' ORDER BY full_name ASC")->fetchAll()
    : [];

$status_class = [
    'Pending Vessel' => 'cal-status-pending-vessel',
    'Pending Report' => 'cal-status-pending-report',
    'Completed'      => 'cal-status-completed',
    'Cancelled'      => 'cal-status-cancelled',
];

$first_weekday = (int)date('w', strtotime($month_start)); // 0=Sun
$days_in_month = (int)date('t', $view_ts);

include 'includes/header.php';
?>
<style>
    .cal-page { padding: 16px 16px 110px; }
    .cal-heading { font-size: 19px; font-weight: 750; color: var(--text-dark); margin: 0 0 14px; }
    .cal-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
    .cal-nav { display: flex; align-items: center; gap: 10px; }
    .cal-nav a { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 9px; background: #fff; border: 1px solid var(--border-color); color: var(--text-dark); text-decoration: none; }
    .cal-month-label { font-size: 15px; font-weight: 700; color: var(--text-dark); min-width: 140px; text-align: center; }
    .cal-filter select { font-size: 12.5px; padding: 7px 10px; border-radius: 9px; border: 1px solid var(--border-color); background: #fff; }
    .cal-legend { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; font-size: 11px; color: var(--text-muted); }
    .cal-legend span { display: inline-flex; align-items: center; gap: 5px; }
    .cal-legend i { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .cal-dow { text-align: center; font-size: 10.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; padding-bottom: 4px; }
    .cal-cell { background: #fff; border: 1px solid var(--border-color); border-radius: 10px; min-height: 84px; padding: 6px; }
    .cal-cell.empty { background: transparent; border: none; }
    .cal-cell.today { border-color: var(--accent-purple, #6d5ae6); border-width: 2px; }
    .cal-date-num { font-size: 11px; font-weight: 700; color: var(--text-dark); margin-bottom: 4px; }
    .cal-item { font-size: 10px; padding: 2px 5px; border-radius: 5px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #fff; }
    .cal-item.cal-status-pending-vessel { background: #f59e0b; }
    .cal-item.cal-status-pending-report { background: #3b82f6; }
    .cal-item.cal-status-completed { background: #16a34a; }
    .cal-item.cal-status-cancelled { background: #94a3b8; }
    .cal-more { font-size: 10px; color: var(--text-muted); font-weight: 600; }
    @media (max-width: 576px) {
        .cal-grid { gap: 3px; }
        .cal-cell { min-height: 62px; padding: 4px; }
        .cal-dow { font-size: 9px; }
        .cal-item { font-size: 9px; }
    }
</style>
<div class="scroll-content">
    <?php $page_title = 'Survey Calendar'; $back_url = 'index.php'; $page_testid = 'calendar'; include 'includes/top_app_bar.php'; ?>
    <main class="cal-page" data-testid="calendar-page">
        <h2 class="cal-heading">Survey Calendar</h2>

        <div class="cal-toolbar">
            <div class="cal-nav">
                <a href="?month=<?= $prev_month ?><?= $filter_surveyor_id ? '&surveyor_id=' . $filter_surveyor_id : '' ?>" data-testid="calendar-prev-month"><i class="fa-solid fa-chevron-left"></i></a>
                <div class="cal-month-label"><?= date('F Y', $view_ts) ?></div>
                <a href="?month=<?= $next_month ?><?= $filter_surveyor_id ? '&surveyor_id=' . $filter_surveyor_id : '' ?>" data-testid="calendar-next-month"><i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <?php if ($is_full_access && !empty($surveyor_list)): ?>
            <div class="cal-filter">
                <form method="GET" id="calSurveyorForm">
                    <input type="hidden" name="month" value="<?= date('Y-m', $view_ts) ?>">
                    <select name="surveyor_id" onchange="document.getElementById('calSurveyorForm').submit()" data-testid="calendar-surveyor-filter">
                        <option value="0">All Surveyors</option>
                        <?php foreach ($surveyor_list as $sv): ?>
                        <option value="<?= (int)$sv['id'] ?>" <?= $filter_surveyor_id === (int)$sv['id'] ? 'selected' : '' ?>><?= sanitize($sv['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <div class="cal-legend">
            <span><i style="background:#f59e0b;"></i> Pending Vessel</span>
            <span><i style="background:#3b82f6;"></i> Pending Report</span>
            <span><i style="background:#16a34a;"></i> Completed</span>
            <span><i style="background:#94a3b8;"></i> Cancelled</span>
        </div>

        <div class="cal-grid">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
                <div class="cal-dow"><?= $dow ?></div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $first_weekday; $i++): ?>
                <div class="cal-cell empty"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $days_in_month; $d++):
                $is_today = ($today === sprintf('%04d-%02d-%02d', $year, $month, $d));
                $day_items = $by_day[$d] ?? [];
            ?>
                <div class="cal-cell<?= $is_today ? ' today' : '' ?>" data-testid="calendar-day-<?= $d ?>">
                    <div class="cal-date-num"><?= $d ?></div>
                    <?php foreach (array_slice($day_items, 0, 3) as $item): ?>
                        <a href="vessel_detail.php?id=<?= (int)$item['id'] ?>" class="cal-item <?= $status_class[$item['status']] ?? 'cal-status-pending-vessel' ?>" title="<?= sanitize($item['vessel_name'] . ' — ' . $item['port_name'] . ' (' . $item['status'] . ')') ?>">
                            <?= sanitize($item['vessel_name']) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($day_items) > 3): ?>
                        <div class="cal-more">+<?= count($day_items) - 3 ?> more</div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
    </main>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
