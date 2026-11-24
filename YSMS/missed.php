<?php
/**
 * Missed Vessels — vessels our team could not attend, moved here by hand from Pending Vessels
 * with a reason (includes/missed_vessels.php has the rules and permissions).
 * Computer: table; phone (<992px): cards. Search (vessel / client), filters (client, surveyor,
 * port, date), sort (latest missed first), 25 per page. Actions: View Details, Edit Remarks,
 * Restore to Pending Vessels (modals: assets/js/missed-vessels.js → ajax/missed_vessels.php).
 */
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/missed_vessels.php';
checkAuth();

if (!missedCanViewList()) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$setupError = '';
try { missedEnsureSchema($db); } catch (Throwable $e) {
    error_log('missed.php schema: ' . $e->getMessage());
    $setupError = 'Missed Vessels is not set up on this database yet. Please run database/migration_missed_vessels.sql.';
}
$showSurveyor = missedShowsSurveyor();

$rows = []; $total = 0; $pages = 1; $page = 1; $per = 25;
$clients = $ports = $surveyors = [];
[$f, $where, $params] = missedFilters($_GET);
if ($setupError === '') {
    try {
        $cnt = $db->prepare('SELECT COUNT(*) ' . MISSED_FROM . " WHERE $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();
        $pages = max(1, (int)ceil($total / $per));
        $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
        $q = $db->prepare(MISSED_SELECT . MISSED_FROM . " WHERE $where ORDER BY " . missedOrderSql($f['sort']) . " LIMIT $per OFFSET " . (($page - 1) * $per));
        $q->execute($params);
        $rows = array_map(fn($r) => missedRowView($db, $r), $q->fetchAll(PDO::FETCH_ASSOC));

        // filter choices: from every missed vessel this user can see
        [$scope, $sp] = missedScope();
        $o = $db->prepare('SELECT s.id, c.company_name, p.port_name, u.full_name AS surveyor_name ' . MISSED_FROM . " WHERE s.status = 'Missed' AND $scope");
        $o->execute($sp);
        foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['company_name'])) $clients[$r['company_name']] = true;
            if (!empty($r['port_name'])) $ports[$r['port_name']] = true;
            if ($showSurveyor) foreach (listFilterSplitNames(getCombinedSurveyorNames($db, $r['id'], $r['surveyor_name'] ?? '')) as $n) $surveyors[$n] = true;
        }
        foreach ([&$clients, &$ports, &$surveyors] as &$list) { $list = array_keys($list); natcasesort($list); $list = array_values($list); }
        unset($list);
    } catch (Throwable $e) {
        error_log('missed.php list: ' . $e->getMessage());
        $setupError = 'Could not load the missed vessels. Please refresh the page.';
    }
}
$allMissed = $setupError === '' ? missedCount($db) : 0;
$filtered = $f['q'] !== '' || $f['client'] !== '' || $f['surveyor'] !== '' || $f['port'] !== '' || $f['from'] !== '' || $f['to'] !== '';

function mvQs(array $f, array $extra = []): string {
    $q = array_filter(array_merge(['q' => $f['q'], 'client' => $f['client'], 'surveyor' => $f['surveyor'], 'port' => $f['port'],
        'from' => $f['from'], 'to' => $f['to'], 'sort' => $f['sort'] === 'latest' ? '' : $f['sort']], $extra), fn($v) => $v !== '' && $v !== null);
    return $q ? '?' . http_build_query($q) : '';
}
$fmtDate = fn(string $d) => $d !== '' && strpos($d, '0000') !== 0 && strtotime($d) ? date('d M Y', strtotime($d)) : '—';
$fmtTime = fn(string $d) => $d !== '' && strpos($d, '0000') !== 0 && strtotime($d) ? date('h:i A', strtotime($d)) : '';
$mvPager = '';
if ($pages > 1) {
    $mvPager = '<div class="lp-pages">' . ($page > 1 ? '<a href="missed.php' . sanitize(mvQs($f, ['page' => $page - 1])) . '" aria-label="Previous page" data-testid="mv-prev"><i class="fa-solid fa-chevron-left"></i></a>' : '<span class="dis"><i class="fa-solid fa-chevron-left"></i></span>');
    for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) $mvPager .= $p === $page ? '<span class="on">' . $p . '</span>' : '<a href="missed.php' . sanitize(mvQs($f, ['page' => $p])) . '">' . $p . '</a>';
    $mvPager .= ($page < $pages ? '<a href="missed.php' . sanitize(mvQs($f, ['page' => $page + 1])) . '" aria-label="Next page" data-testid="mv-next"><i class="fa-solid fa-chevron-right"></i></a>' : '<span class="dis"><i class="fa-solid fa-chevron-right"></i></span>') . '</div>';
}

$page_title = 'Missed Vessels';
$moreOpen = $f['client'] !== '' || $f['surveyor'] !== '' || $f['port'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['sort'] !== 'latest';
$mvSvg = [ // card icons (same as the Pending Vessels cards)
    'client' => '<path d="M11 17l2 2a1 1 0 0 0 3-3"/><path d="M14 14l2.5 2.5a1 1 0 0 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="M21 3l1 11h-2"/><path d="M3 3L2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
    'port' => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    'type' => '<rect x="5" y="4" width="14" height="17" rx="2.5"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M11 10.5h5M11 14h5M11 17.5h5"/>',
    'person' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
    'date' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    'note' => '<rect x="4" y="3.5" width="16" height="17" rx="3"/><path d="M8 8.5h8M8 12h8M8 15.5h8"/>',
];
$mvItem = fn(string $ic, string $label, string $val) => '<div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true">' . $mvSvg[$ic] . '</svg><div class="pvz-t"><span class="pvz-l">' . sanitize($label) . '</span><b>' . ($val !== '' ? sanitize($val) : '—') . '</b></div></div>';
include 'includes/header.php';
echo missedAssets();
?>
<link rel="stylesheet" href="assets/css/mobile-card.css?v=<?= @filemtime(__DIR__ . '/assets/css/mobile-card.css') ?>">
<link rel="stylesheet" href="assets/css/list-page.css?v=<?= @filemtime(__DIR__ . '/assets/css/list-page.css') ?>">
<style>
/* Missed Vessels: amber cards (rest: assets/css/list-page.css + mobile-card.css) */
@media (max-width: 991.98px) {
    .lp .vessel-list-container { padding: 0 !important; }
    .lp .vessel-list-container .vessel-card.cc-card.pvz.pvz-amber { border-color: #f59e0b !important; box-shadow: 0 6px 16px rgba(245,158,11,.12) !important; margin: 0 0 14px !important; cursor: default; }
    .lp .pvz-amber .pvz-bar, .lp .pvz-amber .pvz-upd-bar { background: #f59e0b; }
    .lp .pvz-amber .pvz-i > svg, .lp .pvz-amber .pvz-upd-k svg { stroke: #d97706; }
    .lp .pvz-amber .pvz-upd-k { color: #b45309; }
    .lp .pvz-amber .pvz-name a { color: inherit; text-decoration: none; }
    .lp .pvz .lp-actions { justify-content: stretch; padding: 10px 0 4px; }
    .lp .pvz .lp-actions > * { flex: 1 1 auto; justify-content: center; }
    .lp .mv-more { display: none !important; }
    .lp form.show .mv-more { display: block !important; }
    .lp form.show .lp-pill.mv-more { display: inline-block !important; }
    .lp .mv-ftog { flex: 1 1 calc(50% - 4px); height: 42px; border-radius: 999px; border: 1px solid #e2e8f0; background: #fff; color: #334155; font-weight: 700; font-size: 13px; }
    .lp .lp-search { flex-basis: 100%; }
    .lp .lp-pill-date { display: none; }
}
@media (min-width: 992px) {
    .lp .mv-ftog { display: none; }
    .lp .lp-table td { white-space: normal; }
    .lp .lp-table td.nw { white-space: nowrap; }
    .lp .mv-rem { max-width: 280px; overflow-wrap: anywhere; color: #334155; }
    .lp .lp-table .lp-actions { flex-direction: column; align-items: stretch; min-width: 120px; }
    .lp .lp-table .lp-actions > * { justify-content: center; padding: 6px 12px; }
    .lp .lp-pill-date { font-size: 10.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
    .lp input[type=date].lp-pill { max-width: 150px; }
}
</style>
<div class="scroll-content mvp lp" data-testid="missed-vessels-page">
<?php $back_url = ''; $page_testid = 'missed-vessels'; include 'includes/top_app_bar.php'; ?>
<div class="lp-wrap">
    <div class="lp-hero" data-testid="mv-hero">
        <i class="fa-solid fa-triangle-exclamation lp-hero-icon-bg" aria-hidden="true"></i>
        <div>
            <div class="lp-hero-title">Missed Vessels</div>
            <div class="lp-hero-sub">Surveys our team could not attend, moved here from Pending Vessels with a reason</div>
        </div>
        <div class="lp-hero-stats">
            <div class="lp-hero-stat" data-testid="mv-total"><div class="n" data-mv-count="missed"><?= (int)$allMissed ?></div><div class="l">Missed vessels</div></div>
            <?php if ($filtered): ?><div class="lp-hero-stat"><div class="n"><?= (int)$total ?></div><div class="l">Matching</div></div><?php endif; ?>
        </div>
    </div>

    <?php if ($setupError !== ''): ?>
        <div class="alert alert-warning" data-testid="mv-setup-error"><?= sanitize($setupError) ?></div>
    <?php endif; ?>

    <form class="lp-toolbar<?= $moreOpen ? ' show' : '' ?>" method="get" action="missed.php" data-testid="mv-filters">
        <label class="lp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= sanitize($f['q']) ?>" placeholder="Vessel or client…" aria-label="Search vessel or client" data-testid="mv-search"></label>
        <button type="button" class="mv-ftog" onclick="this.form.classList.toggle('show')" aria-label="Show filters" data-testid="mv-filter-toggle"><i class="fa-solid fa-sliders"></i> Filters</button>
        <select class="lp-pill mv-more" name="client" aria-label="Client" data-testid="mv-f-client"><option value="">All clients</option><?php foreach ($clients as $v): ?><option<?= $v === $f['client'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?></select>
        <?php if ($showSurveyor): ?>
        <select class="lp-pill mv-more" name="surveyor" aria-label="Surveyor" data-testid="mv-f-surveyor"><option value="">All surveyors</option><?php foreach ($surveyors as $v): ?><option<?= $v === $f['surveyor'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <select class="lp-pill mv-more" name="port" aria-label="Port" data-testid="mv-f-port"><option value="">All ports</option><?php foreach ($ports as $v): ?><option<?= $v === $f['port'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?></select>
        <span class="lp-pill-date">Missed</span>
        <input type="date" class="lp-pill mv-more" name="from" value="<?= sanitize($f['from']) ?>" aria-label="Missed from" title="Missed from" data-testid="mv-f-from">
        <input type="date" class="lp-pill mv-more" name="to" value="<?= sanitize($f['to']) ?>" aria-label="Missed to" title="Missed to" data-testid="mv-f-to">
        <select class="lp-pill mv-more" name="sort" aria-label="Sort" data-testid="mv-sort">
            <option value="latest"<?= $f['sort'] === 'latest' ? ' selected' : '' ?>>Latest missed first</option>
            <option value="oldest"<?= $f['sort'] === 'oldest' ? ' selected' : '' ?>>Oldest missed first</option>
            <option value="vessel"<?= $f['sort'] === 'vessel' ? ' selected' : '' ?>>Vessel name A–Z</option>
        </select>
        <button type="submit" class="lp-btn-apply" data-testid="mv-apply"><i class="fa-solid fa-filter"></i> Apply</button>
        <?php if ($filtered || $f['sort'] !== 'latest'): ?><a class="lp-btn-clear" href="missed.php" data-testid="mv-clear"><i class="fa-solid fa-rotate-left"></i> Clear</a><?php endif; ?>
    </form>

    <div class="lp-strip"><span>Missed vessels <b data-mv-count="missed"><?= (int)$allMissed ?></b></span><?php if ($filtered): ?><span class="r"><?= (int)$total ?> matching</span><?php endif; ?></div>
    <div class="lp-meta" data-testid="mv-meta"><?= $total ? 'Showing <b>' . (($page - 1) * $per + 1) . '–' . min($total, $page * $per) . '</b> of <b>' . $total . '</b>' . ($filtered ? ' matching' : '') . ' · ' . (['latest' => 'Latest missed first', 'oldest' => 'Oldest missed first', 'vessel' => 'Vessel name A–Z'][$f['sort']] ?? 'Latest missed first') : '0 vessels' ?></div>

    <?php if (!$rows): ?>
        <div class="lp-table-card"><div class="lp-empty" data-testid="mv-empty"><i class="fa-solid fa-triangle-exclamation"></i><?= $filtered ? 'No missed vessels match these filters.' : 'No missed vessels.' ?></div></div>
    <?php else: ?>
    <div class="lp-table-card lp-desk-only">
        <div class="lp-table-wrap">
            <table class="lp-table" data-testid="mv-table">
                <thead><tr><th>#</th><th>Vessel</th><th>Client</th><th>Port</th><th>Survey Type</th><?php if ($showSurveyor): ?><th>Surveyor</th><?php endif; ?><th>Missed On / By</th><th>Reason / Remarks</th><th style="text-align:right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr data-mv-row="<?= $r['id'] ?>" data-testid="mv-row-<?= $r['id'] ?>">
                        <td class="lp-num nw"><?= ($page - 1) * $per + $i + 1 ?></td>
                        <td><a class="lp-vessel" href="vessel_detail.php?id=<?= $r['id'] ?>"><?= sanitize($r['vessel']) ?></a><div style="margin-top:4px"><span class="lp-badge amber"><span class="dot"></span>Missed</span></div></td>
                        <td><?= sanitize($r['client']) ?: '—' ?><?php if ($r['agent'] !== ''): ?><div class="lp-sub">Agent: <?= sanitize($r['agent']) ?></div><?php endif; ?></td>
                        <td><?= sanitize($r['port']) ?: '—' ?></td>
                        <td><?= $r['type'] !== '' ? '<span class="lp-badge"><span class="dot"></span>' . sanitize($r['type']) . '</span>' : '—' ?></td>
                        <?php if ($showSurveyor): ?><td><?= sanitize($r['surveyor']) ?: '—' ?></td><?php endif; ?>
                        <td class="nw"><?= $fmtDate($r['missed_at']) ?><div class="lp-sub"><?= $fmtTime($r['missed_at']) ?></div><div class="lp-sub" data-testid="mv-marked-by-<?= $r['id'] ?>">by <?= sanitize($r['marked_by']) ?: '—' ?></div></td>
                        <td><div class="mv-rem" data-mv-remarks-of="<?= $r['id'] ?>" data-testid="mv-remarks-<?= $r['id'] ?>"><?= sanitize($r['remarks']) ?: '—' ?></div>
                            <?php if ($r['edited_at'] !== ''): ?><div class="lp-sub">Edited <?= missedWhen($r['edited_at']) ?><?= $r['edited_by'] !== '' ? ' by ' . sanitize($r['edited_by']) : '' ?></div><?php endif; ?></td>
                        <td><div class="lp-actions">
                            <a href="vessel_detail.php?id=<?= $r['id'] ?>" data-testid="mv-view-<?= $r['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                            <?php if ($r['can_edit']): ?><button type="button" class="ed" <?= missedBtnData('edit', $r) ?> data-testid="mv-edit-<?= $r['id'] ?>"><i class="fa-solid fa-pen"></i> Edit Remarks</button><?php endif; ?>
                            <?php if ($r['can_restore']): ?><button type="button" class="rs" <?= missedBtnData('restore', $r) ?> data-testid="mv-restore-<?= $r['id'] ?>"><i class="fa-solid fa-rotate-left"></i> Restore</button><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?><div class="lp-pager"><span>25 per page</span><?= $mvPager ?? '' ?></div><?php endif; ?>
    </div>

    <div class="vessel-list-container lp-mob-only" data-testid="mv-cards">
        <?php foreach ($rows as $r): ?>
        <div class="vessel-card cc-card pvz pvz-amber" data-mv-row="<?= $r['id'] ?>" data-testid="mv-card-<?= $r['id'] ?>">
            <div class="pvz-head"><span class="pvz-bar" aria-hidden="true"></span><span class="cc-name pvz-name"><a href="vessel_detail.php?id=<?= $r['id'] ?>"><?= sanitize($r['vessel']) ?></a></span><span class="lp-badge amber" style="flex:none">Missed</span></div>
            <div class="pvz-grid<?= $showSurveyor ? '' : ' pvz-no-surveyor' ?>">
                <?= $mvItem('client', 'Client', $r['client']) ?>
                <?= $mvItem('port', 'Port', $r['port']) ?>
                <?= $mvItem('type', 'Survey Type', $r['type']) ?>
                <?php if ($showSurveyor): ?><?= $mvItem('person', 'Surveyor', $r['surveyor']) ?><?php endif; ?>
                <?= $mvItem('date', 'Missed On', trim($fmtDate($r['missed_at']) . ' ' . $fmtTime($r['missed_at']))) ?>
                <?= $mvItem('person', 'Marked By', $r['marked_by']) ?>
            </div>
            <div class="pvz-upd">
                <div class="pvz-upd-k"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $mvSvg['note'] ?></svg><span>Reason</span></div>
                <span class="pvz-upd-bar" aria-hidden="true"></span>
                <div class="pvz-upd-text" data-mv-remarks-of="<?= $r['id'] ?>"><?= sanitize($r['remarks']) ?: '—' ?></div>
            </div>
            <div class="lp-actions">
                <a href="vessel_detail.php?id=<?= $r['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                <?php if ($r['can_edit']): ?><button type="button" class="ed" <?= missedBtnData('edit', $r) ?> data-testid="mvc-edit-<?= $r['id'] ?>"><i class="fa-solid fa-pen"></i> Edit Remarks</button><?php endif; ?>
                <?php if ($r['can_restore']): ?><button type="button" class="rs" <?= missedBtnData('restore', $r) ?> data-testid="mvc-restore-<?= $r['id'] ?>"><i class="fa-solid fa-rotate-left"></i> Restore</button><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($pages > 1): ?><div class="lp-pager lp-mob-only" style="border:0;background:none;padding:6px 0"><span></span><?= $mvPager ?? '' ?></div><?php endif; ?>
    <?php endif; ?>
    <div data-testid="mv-pager"></div>
</div>
</div>
<script>
// a restored vessel leaves this list: reload once the last one on this page is gone (keeps paging right)
document.addEventListener('mv:removed', function () { if (!document.querySelector('[data-mv-row]')) location.reload(); });
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
