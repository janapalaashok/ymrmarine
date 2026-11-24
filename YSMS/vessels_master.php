<?php
require_once 'config/config.php';
require_once 'includes/vessel_history.php';
checkAuth();

/**
 * Vessels Master Data: every different vessel YMR has attended (a survey done — Pending Report or
 * Completed), one row per vessel however many times it was attended, with each survey's date, type
 * and port. Same vessel = the same name (ignoring the MV/MT prefix, case and punctuation) or the
 * same IMO number, as on the vessel page's "Previous surveys of this vessel".
 * Admin / Super Admin only. Not in the sidebar: opened by its address (vessels_master.php).
 * Read-only: nothing is changed here.
 */
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'Super Admin'], true)) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$hasImo = vesselHistoryHasImo($db);

$typeName = [];
try {
    foreach ($db->query('SELECT id, type_name FROM survey_types')->fetchAll(PDO::FETCH_ASSOC) as $t) $typeName[(int)$t['id']] = (string)$t['type_name'];
} catch (Throwable $e) { error_log('vessels master types: ' . $e->getMessage()); }

$rows = [];
try {
    $rows = $db->query("SELECT s.id, s.vessel_name, s.status, s.survey_type_ids, s.survey_type_id, " . ($hasImo ? 's.imo_number' : "'' AS imo_number") . ",
            COALESCE(" . surveyCompletedDateSql('s') . ", IF(YEAR(s.appointment_date) > 0, s.appointment_date, NULL)) AS survey_date,
            p.port_name, p.country
        FROM surveys s LEFT JOIN ports p ON p.id = s.port_id
        WHERE s.status IN ('Pending Report','Completed')")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('vessels master: ' . $e->getMessage()); }

// Group the surveys into vessels: the same name key or the same IMO joins two surveys.
$parent = [];
$find = function ($i) use (&$parent) { while ($parent[$i] !== $i) { $parent[$i] = $parent[$parent[$i]]; $i = $parent[$i]; } return $i; };
$byKey = [];
foreach ($rows as $i => $r) {
    $parent[$i] = $i;
    foreach (['k:' . vesselHistoryKey((string)$r['vessel_name']), 'i:' . vesselHistoryImo($r['imo_number'] ?? '')] as $tag) {
        if ($tag === 'k:' || $tag === 'i:') continue;
        if (isset($byKey[$tag])) { $a = $find($i); $b = $find($byKey[$tag]); if ($a !== $b) $parent[$a] = $b; }
        else $byKey[$tag] = $i;
    }
}
$groups = [];
foreach ($rows as $i => $r) {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$r['survey_type_ids'])))));
    if (!$ids && (int)$r['survey_type_id'] > 0) $ids = [(int)$r['survey_type_id']];
    $types = implode(' + ', array_values(array_filter(array_map(fn($t) => $typeName[$t] ?? '', $ids))));
    $groups[$find($i)][] = [
        'id' => (int)$r['id'], 'name' => trim((string)$r['vessel_name']), 'imo' => vesselHistoryImo($r['imo_number'] ?? ''),
        'date' => $r['survey_date'] ? substr((string)$r['survey_date'], 0, 10) : '',
        'type' => $types !== '' ? $types : '—',
        'port' => trim((string)($r['port_name'] ?? '')), 'country' => trim((string)($r['country'] ?? '')),
        'url' => ($r['status'] === 'Completed' ? 'completed_detail.php' : 'report_detail.php') . '?id=' . (int)$r['id'],
    ];
}
$vessels = [];
foreach ($groups as $list) {
    usort($list, fn($a, $b) => strcmp($b['date'], $a['date']) ?: $b['id'] <=> $a['id']); // newest first; no date last
    $imos = array_values(array_unique(array_filter(array_column($list, 'imo'))));
    $vessels[] = ['name' => mb_strtoupper($list[0]['name']), 'key' => vesselHistoryKey($list[0]['name']), 'imo' => implode(', ', $imos), 'surveys' => $list, 'last' => $list[0]['date']];
}
usort($vessels, fn($a, $b) => strcmp($a['key'], $b['key'])); // A–Z ignoring the MV / MT prefix

$totalSurveys = count($rows);
$repeat = count(array_filter($vessels, fn($v) => count($v['surveys']) > 1));
$fmt = fn($d) => $d !== '' ? strtoupper(date('d-M-y', strtotime($d))) : '—';

include 'includes/header.php';
?>
<style>
.vm-wrap { padding: 0 16px 24px; max-width: 1300px; margin: 0 auto; }
.vm-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 12px 0; }
.vm-card { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px 14px; min-width: 0; }
.vm-card-l { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .3px; }
.vm-card-v { font-size: 22px; font-weight: 800; color: #0f172a; margin-top: 2px; }
.vm-tools { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.vm-tools input, .vm-tools select { border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 8px 11px; font-size: 13px; background: #fff; }
.vm-tools input { flex: 1 1 240px; min-width: 0; }
.vm-box { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; }
.vm-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.vm-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 760px; }
.vm-table th { background: #0f172a; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 9px 10px; text-align: left; position: sticky; top: 0; }
.vm-table td { padding: 9px 10px; border-top: 1px solid #f1f5f9; color: #0f172a; vertical-align: top; }
.vm-table tr:nth-child(even) td { background: #fafbfc; }
.vm-name { font-weight: 800; font-size: 13.5px; }
.vm-n { display: inline-block; min-width: 26px; text-align: center; padding: 2px 8px; border-radius: 999px; background: #E0F2FE; color: #075985; font-weight: 800; }
.vm-n.many { background: #DCFCE7; color: #166534; }
.vm-sv { display: grid; grid-template-columns: 82px minmax(0, 1.3fr) minmax(0, 1fr); gap: 8px; padding: 4px 0; text-decoration: none; color: inherit; border-bottom: 1px dashed #e2e8f0; }
.vm-sv:last-child { border-bottom: 0; }
.vm-sv:hover { color: #0369a1; }
.vm-sv b { font-weight: 800; }
.vm-sv span { overflow-wrap: anywhere; }
.vm-empty { text-align: center; padding: 36px 16px; color: #94a3b8; }
@media (max-width: 575.98px) {
    .vm-cards { grid-template-columns: 1fr 1fr; } .vm-cards .vm-card:first-child { grid-column: 1 / -1; }
    .vm-card-v { font-size: 19px; }
    /* phone: one card per vessel — name, IMO and times on top, the surveys below */
    .vm-table { min-width: 0; } .vm-table thead { display: none; }
    .vm-table tr { display: grid; grid-template-columns: 30px minmax(0, 1fr) auto; column-gap: 8px; padding: 10px 12px; border-top: 1px solid #e2e8f0; }
    .vm-table tr:nth-child(even) td { background: transparent; } .vm-table tr:nth-child(even) { background: #fafbfc; }
    .vm-table td { display: block; padding: 0; border: 0; }
    .vm-table td.vm-i { color: #94a3b8; font-weight: 700; }
    .vm-table td.vm-imo { grid-column: 2; grid-row: 2; font-size: 11.5px; color: #64748b; }
    .vm-table td.vm-imo::before { content: 'IMO '; font-weight: 700; }
    .vm-table td.vm-t { grid-column: 3; grid-row: 1; }
    .vm-table td.vm-list { grid-column: 1 / -1; margin-top: 8px; }
    .vm-sv { grid-template-columns: 74px minmax(0, 1fr); row-gap: 1px; font-size: 12px; }
    .vm-sv span:last-child { grid-column: 2; color: #64748b; font-size: 11.5px; }
    .vm-table tr[hidden] { display: none; }
}
</style>
<div class="scroll-content" data-testid="vessels-master-page">
    <?php $page_title = 'Vessels Master Data'; $back_url = 'index.php'; $page_testid = 'vessels-master'; include 'includes/top_app_bar.php'; ?>
    <div class="vm-wrap">
        <div class="vm-cards">
            <div class="vm-card"><div class="vm-card-l">Different vessels attended</div><div class="vm-card-v" data-testid="vm-count-vessels"><?= count($vessels) ?></div></div>
            <div class="vm-card"><div class="vm-card-l">Surveys done</div><div class="vm-card-v" data-testid="vm-count-surveys"><?= $totalSurveys ?></div></div>
            <div class="vm-card"><div class="vm-card-l">Attended more than once</div><div class="vm-card-v" data-testid="vm-count-repeat"><?= $repeat ?></div></div>
        </div>
        <div class="vm-tools">
            <input type="search" id="vmSearch" placeholder="Search vessel, IMO, port, survey type..." aria-label="Search" data-testid="vm-search">
            <select id="vmSort" aria-label="Sort" data-testid="vm-sort">
                <option value="name">Vessel name A–Z</option>
                <option value="last">Latest survey first</option>
                <option value="times">Most attended first</option>
            </select>
        </div>
        <div class="vm-box">
            <div class="vm-scroll">
                <table class="vm-table" data-testid="vm-table">
                    <thead><tr><th style="width:44px">#</th><th style="width:22%">Vessel</th><th style="width:90px">IMO</th><th style="width:70px">Times</th><th>Surveys (date · type of survey · port)</th></tr></thead>
                    <tbody id="vmBody">
                    <?php foreach ($vessels as $i => $v): ?>
                        <tr data-testid="vm-row" data-name="<?= sanitize($v['key']) ?>" data-last="<?= sanitize($v['last']) ?>" data-times="<?= count($v['surveys']) ?>"
                            data-q="<?= sanitize(mb_strtolower($v['name'] . ' ' . $v['imo'] . ' ' . implode(' ', array_map(fn($s) => $s['type'] . ' ' . $s['port'] . ' ' . $s['country'] . ' ' . $s['name'], $v['surveys'])))) ?>">
                            <td class="vm-i"><?= $i + 1 ?></td>
                            <td class="vm-name"><?= sanitize($v['name']) ?></td>
                            <td class="vm-imo"><?= $v['imo'] !== '' ? sanitize($v['imo']) : '—' ?></td>
                            <td class="vm-t"><span class="vm-n<?= count($v['surveys']) > 1 ? ' many' : '' ?>"><?= count($v['surveys']) ?></span></td>
                            <td class="vm-list">
                                <?php foreach ($v['surveys'] as $s): ?>
                                <a class="vm-sv" href="<?= sanitize($s['url']) ?>" data-testid="vm-survey"><b><?= $fmt($s['date']) ?></b><span><?= sanitize(mb_strtoupper($s['type'])) ?></span><span><?= $s['port'] !== '' ? sanitize(mb_strtoupper($s['port'] . ($s['country'] !== '' ? ', ' . $s['country'] : ''))) : '—' ?></span></a>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="vm-empty" id="vmEmpty" data-testid="vm-empty"<?= $vessels ? ' hidden' : '' ?>><?= $vessels ? 'No vessel matches the search.' : 'No surveys done yet.' ?></div>
        </div>
    </div>
</div>
<script>
(function () {
    var body = document.getElementById('vmBody'), q = document.getElementById('vmSearch'), sort = document.getElementById('vmSort'), empty = document.getElementById('vmEmpty');
    var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));
    function apply() {
        var by = sort.value, t = q.value.trim().toLowerCase(), n = 0;
        rows.sort(function (a, b) {
            if (by === 'last') return (b.dataset.last || '').localeCompare(a.dataset.last || '') || a.dataset.name.localeCompare(b.dataset.name);
            if (by === 'times') return (+b.dataset.times - +a.dataset.times) || a.dataset.name.localeCompare(b.dataset.name);
            return a.dataset.name.localeCompare(b.dataset.name);
        });
        rows.forEach(function (r) {
            var show = !t || r.dataset.q.indexOf(t) !== -1;
            r.hidden = !show;
            if (show) r.querySelector('.vm-i').textContent = ++n;
            body.appendChild(r);
        });
        if (rows.length) empty.hidden = n > 0;
    }
    q.addEventListener('input', apply); sort.addEventListener('change', apply);
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
