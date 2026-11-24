<?php
// Monthly Data: Appointments / Survey Completed / Recovery of one month (Admin / Super Admin).
// Read only — rows and totals from includes/monthly_data.php; sort and filter in the page,
// Download (monthly_data_export.php) sends the rows on screen (filtered, in the shown order).
require_once 'config/config.php';
require_once 'includes/monthly_data.php';
checkAuth();
if (!mdCanView()) { header('Location: index.php'); exit; }

$db = getDB();
$tab = mdTab($_GET);
// ?day=YYYY-MM-DD: Appointments of that day only (dashboard "Today's Appointments"); the month is the day's month
$day = $tab === 'appointments' ? mdDay($_GET) : '';
[$y, $m, $start] = mdMonth($day !== '' ? ['month' => substr($day, 0, 7)] : $_GET);
$monthVal = sprintf('%04d-%02d', $y, $m);
$monthLabel = date('F Y', strtotime($start));
$dayLabel = $day !== '' ? ($day === date('Y-m-d') ? 'Today, ' : '') . strtoupper(date('d-M-Y', strtotime($day))) : '';
$periodLabel = $day !== '' ? $dayLabel : $monthLabel;
[$rows, $tot] = mdData($db, $tab, $y, $m, $day);
$cols = mdColumns($tab);
$mt = fn($v) => number_format((float)$v, 3);

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/list-page.css?v=<?= @filemtime(__DIR__ . '/assets/css/list-page.css') ?>">
<style>
/* sortable headings + month picker (rest: assets/css/list-page.css) */
.lp .md-daychip { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; background: #F5F3FF; border: 1px solid #DDD6FE; color: #4c1d95; border-radius: 14px; padding: 9px 14px; margin-bottom: 10px; font-size: 13px; }
.lp .md-daychip a { margin-left: auto; color: #5B2DEE; font-weight: 700; text-decoration: none; }
.lp .md-daychip a:hover { text-decoration: underline; }
.lp .md-sort { all: unset; display: flex; align-items: center; gap: 5px; cursor: pointer; width: 100%; box-sizing: border-box; }
.lp th.num .md-sort { justify-content: flex-end; }
.lp .md-sort:hover { color: #5B2DEE; }
.lp .md-sort .ar { font-size: 9px; opacity: .35; }
.lp th[aria-sort="ascending"] .ar, .lp th[aria-sort="descending"] .ar { opacity: 1; color: #5B2DEE; }
.lp th[aria-sort="ascending"], .lp th[aria-sort="descending"] { color: #5B2DEE; }
.lp .lp-table tbody tr[data-href] { cursor: pointer; }
.lp td.na { color: #94a3b8; font-weight: 700; }
.lp .md-month { display: flex; align-items: center; gap: 8px; }
.lp .md-month input { font-family: inherit; }
@media (min-width: 992px) {
    .lp .lp-table td { white-space: normal; }
    .lp .lp-table td.num, .lp .lp-table td.dt, .lp .lp-table td:first-child, .lp .lp-table td.st { white-space: nowrap; }
    .lp .lp-table th, .lp .lp-table td { padding-left: 9px; padding-right: 9px; }
    .lp .md-month .lp-pill { max-width: none; }
}
@media (max-width: 991.98px) {
    .lp .md-month { flex: 1 1 100%; }
    .lp .md-month .lp-pill { flex: 1 1 auto; height: 44px; font-size: 14px; }
    .lp .md-col { flex: 1 1 100%; }
}
</style>

<div class="scroll-content lp" data-testid="monthly-data-page">
    <?php $page_title = 'Monthly Data'; $back_url = ''; $page_testid = 'monthly-data'; include 'includes/top_app_bar.php'; ?>
    <div class="lp-wrap">
        <div class="lp-hero" data-testid="md-hero">
            <i class="fa-solid fa-calendar-days lp-hero-icon-bg" aria-hidden="true"></i>
            <div>
                <div class="lp-hero-title">Monthly Data</div>
                <div class="lp-hero-sub"><?= sanitize(MD_TABS[$tab]) ?> · <?= sanitize($periodLabel) ?></div>
            </div>
            <div class="lp-hero-stats">
                <div class="lp-hero-stat"><div class="n" data-testid="md-count"><?= (int)$tot['count'] ?></div><div class="l"><?= $tab === 'appointments' ? 'Appointments' : 'Surveys completed' ?></div></div>
                <?php if ($tab === 'recovery'): ?>
                <div class="lp-hero-stat"><div class="n" data-testid="md-total"><?= $mt($tot['total']) ?> <small>MT</small></div><div class="l">Total recovery</div></div>
                <?php endif; ?>
            </div>
        </div>

        <nav class="lp-tabs" role="tablist" data-testid="md-tabs">
            <?php foreach (MD_TABS as $k => $label): ?>
            <a class="lp-tab<?= $k === $tab ? ' on' : '' ?>" role="tab" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>" href="monthly_data.php?tab=<?= $k ?>&amp;month=<?= $monthVal ?>" data-testid="md-tab-<?= $k ?>"><?= sanitize($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="lp-toolbar">
            <form method="get" class="md-month" data-testid="md-month-form">
                <input type="hidden" name="tab" value="<?= $tab ?>">
                <label class="lp-pill-label" for="mdMonth">Month</label>
                <input type="month" id="mdMonth" class="lp-pill" name="month" value="<?= $monthVal ?>" required onchange="this.form.submit()" aria-label="Month" data-testid="md-month">
                <noscript><button type="submit" class="lp-btn-apply">Show</button></noscript>
            </form>
            <?php if ($rows): ?>
            <label class="lp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Filter rows…" data-md-q aria-label="Filter text" data-testid="md-filter"></label>
            <select class="lp-pill md-col" data-md-col aria-label="Filter column" data-testid="md-filter-col">
                <option value="">All columns</option>
                <?php foreach ($cols as $i => [$key, $label]): ?><option value="<?= $i + 1 ?>"><?= sanitize($label) ?></option><?php endforeach; ?>
            </select>
            <form method="post" action="monthly_data_export.php" data-md-dl-form data-no-loading style="display:contents">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="<?= $tab ?>"><input type="hidden" name="month" value="<?= $monthVal ?>"><input type="hidden" name="day" value="<?= sanitize($day) ?>">
                <input type="hidden" name="ids" value="all" data-md-ids>
                <button type="submit" class="lp-btn-dl" data-testid="md-download"><i class="fa-solid fa-file-excel"></i> Download Excel</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if ($day !== ''): ?>
        <div class="md-daychip" data-testid="md-day-chip"><i class="fa-solid fa-calendar-day"></i> Showing appointments of <b><?= sanitize($dayLabel) ?></b> only
            <a href="monthly_data.php?tab=appointments&amp;month=<?= $monthVal ?>" data-testid="md-day-clear">Show all of <?= sanitize($monthLabel) ?> <i class="fa-solid fa-xmark"></i></a></div>
        <?php endif; ?>
        <div class="lp-strip" data-testid="md-strip">
            <span><?= $tab === 'appointments' ? 'Appointments' : 'Surveys completed' ?> <b><?= (int)$tot['count'] ?></b></span>
            <?php if ($tab === 'recovery'): ?><span>Total Recovery <b><?= $mt($tot['total']) ?> MT</b></span><?php endif; ?>
            <span class="r"><?= sanitize($periodLabel) ?></span>
        </div>
        <?php if ($rows): ?><div class="lp-meta" data-md-shown data-testid="md-shown"></div><?php endif; ?>

        <div class="lp-table-card">
            <?php if (!$rows): ?>
                <div class="lp-empty" data-testid="md-empty"><i class="fa-solid fa-calendar-xmark"></i>No <?= $tab === 'appointments' ? 'appointments' : 'surveys completed' ?> <?= $day !== '' ? 'on' : 'in' ?> <?= sanitize($periodLabel) ?>.</div>
            <?php else: ?>
            <div class="lp-table-wrap">
            <table class="lp-table" data-md-table data-testid="md-table">
                <thead><tr>
                    <th aria-sort="none"><button type="button" class="md-sort" data-md-sort="0" data-kind="num">#<span class="ar">▲▼</span></button></th>
                    <?php foreach ($cols as $i => [$key, $label, $kind]): ?>
                    <th class="<?= $kind === 'num' ? 'num' : '' ?>" aria-sort="none"><button type="button" class="md-sort" data-md-sort="<?= $i + 1 ?>" data-kind="<?= $kind ?>" data-testid="md-sort-<?= $key ?>"><?= sanitize($label) ?><span class="ar">▲▼</span></button></th>
                    <?php endforeach; ?>
                    <th class="num">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $n => $r): ?>
                    <tr data-id="<?= (int)$r['id'] ?>" data-href="<?= sanitize($r['url']) ?>" data-testid="md-row-<?= (int)$r['id'] ?>">
                        <td class="lp-num" data-v="<?= $n + 1 ?>"><?= $n + 1 ?></td>
                        <?php foreach ($cols as [$key, $label, $kind]): [$txt, $sv] = mdCell($r, $key, $kind); ?>
                        <?php if ($key === 'vessel'): ?>
                        <td data-v="<?= sanitize($sv) ?>"><a class="lp-vessel" href="<?= sanitize($r['url']) ?>"><?= sanitize($txt) ?></a></td>
                        <?php elseif ($key === 'type'): ?>
                        <td data-v="<?= sanitize($sv) ?>"><?= $txt !== '—' ? '<span class="lp-badge green" style="white-space:normal"><span class="dot"></span>' . sanitize($txt) . '</span>' : '—' ?></td>
                        <?php elseif ($key === 'stage'): $bc = ['Completed' => 'green', 'Pending Report' => 'orange', 'Pending Vessel' => '', 'Cancelled' => 'red', 'Missed' => 'amber'][$r['stage']] ?? 'grey'; ?>
                        <td class="st" data-v="<?= sanitize($sv) ?>"><span class="lp-badge <?= $bc ?>"><span class="dot"></span><?= sanitize($txt) ?></span></td>
                        <?php else: ?>
                        <td class="<?= $kind === 'num' ? 'num' . ($r['na'] ? ' na' : '') : ($kind === 'date' ? 'dt' : '') ?>" data-v="<?= sanitize($sv) ?>"<?= $kind === 'num' ? ' data-n="' . ($r['na'] || $r[$key] === null ? '' : sanitize((string)$r[$key])) . '"' : '' ?>><?= sanitize($txt) ?></td>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <td><div class="lp-actions"><a href="<?= sanitize($r['url']) ?>" data-testid="md-view-<?= (int)$r['id'] ?>"><i class="fa-solid fa-eye"></i> View</a></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($tab === 'recovery'): ?>
                <tfoot><tr data-md-foot>
                    <td colspan="<?= count($cols) - 3 ?>">Total <span data-md-foot-n></span></td>
                    <?php foreach (['vlsfo', 'lsmgo', 'hsfo', 'total'] as $k): ?><td class="num" data-md-sum="<?= $k ?>" data-testid="md-foot-<?= $k ?>"><?= $mt($tot[$k]) ?></td><?php endforeach; ?>
                    <td></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <div class="lp-meta" style="margin-top:8px;font-size:11.5px;color:#94a3b8">
            <?php if ($tab === 'appointments'): ?>Vessels whose Appointment Date is in the month, in every stage; a vessel without an Appointment Date counts by its Survey Assigned date (Date From shows which). Click a vessel to open it at its current stage.
            <?php elseif ($tab === 'completed'): ?>Vessels in Pending Reports and Completed with their Survey Completed Date in the month (same as the dashboard Month Summary).
            <?php else: ?>The same vessels as Survey Completed, with their recovery (same rules as the Recovery page). N/A = survey type without recovery (<?= RECOVERY_NA_LABEL ?>), counted as 0; Onhire is shown with a minus and counted as positive in the totals.<?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
  var table = document.querySelector('[data-md-table]'); if (!table) return;
  var tbody = table.tBodies[0], rows = [].slice.call(tbody.rows);
  var q = document.querySelector('[data-md-q]'), col = document.querySelector('[data-md-col]');
  var shown = document.querySelector('[data-md-shown]'), total = rows.length;
  var foot = table.querySelector('[data-md-foot]'), fmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  var sumCols = {}; // recovery: column index of each sum
  [].slice.call(table.tHead.rows[0].cells).forEach(function (th, i) { var b = th.querySelector('[data-md-sort]'); if (b && /^(VLSFO|LSMGO|HSFO|Total)/.test(b.textContent)) sumCols[b.textContent.split(' ')[0].toLowerCase()] = i; });
  function visible() { return rows.filter(function (r) { return !r.hidden; }); }
  function update() {
    var vis = visible();
    vis.forEach(function (r, i) { r.cells[0].textContent = i + 1; }); // # = position in the list shown
    shown.textContent = vis.length === total ? 'Showing all ' + total + ' rows' : 'Showing ' + vis.length + ' of ' + total + ' rows (filtered)';
    if (foot) {
      // totals of the rows shown (NOT APPLICABLE / empty cells count 0; always positive)
      Object.keys(sumCols).forEach(function (k) {
        var s = 0; vis.forEach(function (r) { var n = r.cells[sumCols[k]].getAttribute('data-n'); if (n) s += Math.abs(parseFloat(n)); });
        var c = foot.querySelector('[data-md-sum="' + k + '"]'); if (c) c.textContent = fmt.format(s);
      });
      foot.querySelector('[data-md-foot-n]').textContent = vis.length === total ? '' : '(' + vis.length + ' shown)';
    }
  }
  function filter() {
    var t = (q.value || '').trim().toLowerCase(), c = col.value;
    rows.forEach(function (r) {
      var hay = c ? r.cells[+c].textContent : [].slice.call(r.cells, 1, -1).map(function (x) { return x.textContent; }).join(' ');
      r.hidden = t !== '' && hay.toLowerCase().indexOf(t) < 0;
    });
    update();
  }
  q.addEventListener('input', filter); col.addEventListener('change', filter);
  table.tHead.addEventListener('click', function (e) {
    var b = e.target.closest('[data-md-sort]'); if (!b) return;
    var th = b.parentNode, i = +b.getAttribute('data-md-sort'), kind = b.getAttribute('data-kind');
    var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
    [].slice.call(table.tHead.rows[0].cells).forEach(function (h) { h.setAttribute('aria-sort', 'none'); });
    th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
    var key = function (r) { var v = r.cells[i].getAttribute('data-v') || ''; return kind === 'num' ? (v === '' ? -Infinity : parseFloat(v)) : v; };
    rows.sort(function (a, b) {
      var x = key(a), y = key(b);
      if (kind !== 'num') { if (x === '' && y !== '') return 1; if (y === '' && x !== '') return -1; } // empty last
      return (x < y ? -1 : x > y ? 1 : 0) * dir || (+a.cells[0].getAttribute('data-v') - +b.cells[0].getAttribute('data-v'));
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
    update();
  });
  tbody.addEventListener('click', function (e) {
    if (e.target.closest('a')) return;
    var tr = e.target.closest('tr[data-href]'); if (tr) location.href = tr.getAttribute('data-href');
  });
  // Download: the rows on screen (filtered), in the order shown
  var form = document.querySelector('[data-md-dl-form]');
  form.addEventListener('submit', function () {
    form.querySelector('[data-md-ids]').value = visible().map(function (r) { return r.getAttribute('data-id'); }).join(',');
  });
  update();
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
