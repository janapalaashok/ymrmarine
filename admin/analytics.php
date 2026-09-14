<?php
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/analytics.php';
date_default_timezone_set('Asia/Kolkata');

$pdo = getDB();
ensureSiteVisitsTable($pdo);

$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['today', 'month', 'year', 'all'], true)) {
    $range = 'month';
}
$rangeLabels = ['today' => 'Today', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Time'];
$rangeWhere = [
    'today' => "visit_date = CURDATE()",
    'month' => "visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    'year'  => "visit_date >= DATE_FORMAT(CURDATE(), '%Y-01-01')",
    'all'   => "1=1",
];

// Headline tiles — all four periods at once, exactly what was asked for.
$headline = [];
foreach ($rangeWhere as $key => $sql) {
    $row = $pdo->query("SELECT COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS uniques FROM site_visits WHERE $sql")
        ->fetch(PDO::FETCH_ASSOC);
    $headline[$key] = $row ?: ['visits' => 0, 'uniques' => 0];
}

// Device + country breakdown for the selected range.
$deviceRows = $pdo->query("SELECT device_type, COUNT(*) AS cnt FROM site_visits WHERE {$rangeWhere[$range]} GROUP BY device_type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
$deviceTotal = array_sum(array_column($deviceRows, 'cnt'));
$deviceMax = max(1, ...array_map(fn($r) => (int)$r['cnt'], $deviceRows ?: [['cnt' => 0]]));

$countryRows = $pdo->query("SELECT country, COUNT(*) AS cnt FROM site_visits WHERE {$rangeWhere[$range]} GROUP BY country ORDER BY cnt DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
$countryTotal = array_sum(array_column($countryRows, 'cnt'));
$countryMax = max(1, ...array_map(fn($r) => (int)$r['cnt'], $countryRows ?: [['cnt' => 0]]));
$onlyUnknownCountry = count($countryRows) > 0 && count(array_filter($countryRows, fn($r) => $r['country'] !== 'Unknown')) === 0;

// Daily trend — last 30 days, independent of the range selector, with gap days filled as 0.
$trendRaw = $pdo->query(
    "SELECT visit_date, COUNT(*) AS cnt FROM site_visits
     WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY visit_date"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$trend = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trend[$d] = (int)($trendRaw[$d] ?? 0);
}
$trendMax = max(1, ...array_values($trend));

function rangeUrl(string $r): string { return 'analytics.php?range=' . urlencode($r); }
?>
<style>
.an-tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 900px) { .an-tiles { grid-template-columns: repeat(2, 1fr); } }
.an-tile { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.1rem 1.2rem; }
.an-tile .lbl { font-size: 0.78rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.4rem; }
.an-tile .val { font-size: 1.9rem; font-weight: 700; color: var(--text); line-height: 1.1; }
.an-tile .sub { font-size: 0.8rem; color: var(--muted); margin-top: 0.3rem; }
.an-range { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
.an-range a { padding: 0.45rem 0.95rem; border-radius: 999px; border: 1px solid var(--border); font-size: 0.85rem; color: var(--text); background: var(--card); }
.an-range a.active { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
.an-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 900px) { .an-grid2 { grid-template-columns: 1fr; } }
.an-bar-row { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.65rem; }
.an-bar-label { width: 130px; flex-shrink: 0; font-size: 0.85rem; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.an-bar-track { flex: 1; background: var(--bg); border-radius: 4px; height: 10px; overflow: hidden; }
.an-bar-fill { background: var(--accent); height: 100%; border-radius: 4px; }
.an-bar-count { width: 78px; flex-shrink: 0; text-align: right; font-size: 0.82rem; color: var(--muted); }
.an-note { font-size: 0.8rem; color: var(--muted); margin-top: 0.5rem; }
.an-trend { display: flex; align-items: flex-end; gap: 3px; height: 120px; margin-top: 0.5rem; }
.an-trend-bar { flex: 1; background: var(--accent); border-radius: 3px 3px 0 0; min-height: 2px; }
.an-trend-labels { display: flex; justify-content: space-between; font-size: 0.72rem; color: var(--muted); margin-top: 0.4rem; }
.an-empty { color: var(--muted); font-size: 0.85rem; padding: 1rem 0; }
</style>

<div class="an-tiles">
  <?php foreach ($rangeLabels as $key => $label): $h = $headline[$key]; ?>
  <div class="an-tile">
    <div class="lbl"><?= e($label) ?></div>
    <div class="val"><?= number_format((int)$h['visits']) ?></div>
    <div class="sub"><?= number_format((int)$h['uniques']) ?> unique visitors</div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-title">Visits — last 30 days</div>
  <div class="an-trend">
    <?php foreach ($trend as $d => $cnt): $h = max(2, round(($cnt / $trendMax) * 100)); ?>
    <div class="an-trend-bar" style="height:<?= $h ?>%" title="<?= e(date('d M', strtotime($d))) ?>: <?= (int)$cnt ?> visits"></div>
    <?php endforeach; ?>
  </div>
  <div class="an-trend-labels">
    <span><?= e(date('d M', strtotime(array_key_first($trend)))) ?></span>
    <span><?= e(date('d M', strtotime(array_key_last($trend)))) ?></span>
  </div>
</div>

<div class="an-range">
  <?php foreach ($rangeLabels as $key => $label): ?>
  <a href="<?= rangeUrl($key) ?>" class="<?= $range === $key ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="an-grid2">
  <div class="card">
    <div class="card-title">Device — <?= e($rangeLabels[$range]) ?></div>
    <?php if (empty($deviceRows)): ?>
      <div class="an-empty">No visits recorded yet for this period.</div>
    <?php else: foreach ($deviceRows as $r):
        $pct = $deviceTotal > 0 ? round(($r['cnt'] / $deviceTotal) * 100) : 0;
        $w = round(($r['cnt'] / $deviceMax) * 100);
    ?>
    <div class="an-bar-row">
      <div class="an-bar-label"><?= e($r['device_type']) ?></div>
      <div class="an-bar-track"><div class="an-bar-fill" style="width:<?= $w ?>%"></div></div>
      <div class="an-bar-count"><?= number_format((int)$r['cnt']) ?> (<?= $pct ?>%)</div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Country — <?= e($rangeLabels[$range]) ?></div>
    <?php if (empty($countryRows)): ?>
      <div class="an-empty">No visits recorded yet for this period.</div>
    <?php else: foreach ($countryRows as $r):
        $pct = $countryTotal > 0 ? round(($r['cnt'] / $countryTotal) * 100) : 0;
        $w = round(($r['cnt'] / $countryMax) * 100);
    ?>
    <div class="an-bar-row">
      <div class="an-bar-label"><?= e(ymrCountryName($r['country'])) ?></div>
      <div class="an-bar-track"><div class="an-bar-fill" style="width:<?= $w ?>%"></div></div>
      <div class="an-bar-count"><?= number_format((int)$r['cnt']) ?> (<?= $pct ?>%)</div>
    </div>
    <?php endforeach; endif; ?>
    <?php if ($onlyUnknownCountry): ?>
    <div class="an-note">
      Country shows as "Unknown" for every visit in this period — likely because these are all
      IPv6 visitors (the bundled offline lookup only covers IPv4) or there's simply no data yet
      for this period.
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="an-note" style="margin-top:1.5rem;">
  Country data — where not provided by a CDN header — comes from a bundled, offline IPv4 lookup
  based on the DB-IP "Country Lite" dataset, © <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP.com</a>,
  licensed under <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a>.
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
