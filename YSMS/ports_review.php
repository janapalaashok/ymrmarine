<?php
/**
 * Admin Controls > Ports > Review ports (Admin only).
 * Every port with its proposed name and country in capitals; ports whose country is "Not specified"
 * (or blank / unknown) are marked and need a country. "AI check" suggests spelling fixes and
 * countries (nothing saved). Only "Save changes" writes, for the rows the Admin keeps ticked
 * (ajax/ports_review.php: Admin + CSRF, prepared statements).
 */
require_once 'config/config.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}
$db = getDB();
ensurePortsCountryColumn($db);
$ports = $db->query('SELECT p.id, p.port_name, p.country, (SELECT COUNT(*) FROM surveys s WHERE s.port_id = p.id) AS used
    FROM ports p ORDER BY p.port_name')->fetchAll(PDO::FETCH_ASSOC);
$prNoCountry = fn(string $c): bool => (bool)preg_match('/^(NOT\s*SPECIFIED|UNKNOWN|N\/?A|NONE|-+|\.+)$/', portCaps($c));
$rows = [];
$names = [];
foreach ($ports as $p) $names[portCaps($p['port_name'])][] = (int)$p['id'];
$countries = [];
foreach ($ports as $p) {
    $c = portCaps((string)$p['country']);
    $missing = $prNoCountry($c);
    $newCountry = $missing ? '' : ($c === '' ? 'INDIA' : $c); // a blank country means India (as everywhere in the app)
    if ($newCountry !== '') $countries[$newCountry] = true;
    $n = portCaps($p['port_name']);
    $rows[] = ['id' => (int)$p['id'], 'name' => $p['port_name'], 'country' => (string)$p['country'], 'used' => (int)$p['used'],
        'newName' => $n, 'newCountry' => $newCountry, 'missing' => $missing, 'blank' => $c === '',
        'spaces' => trim($p['port_name']) !== $p['port_name'] || preg_match('/\s{2,}/', $p['port_name']),
        'dupOf' => array_values(array_filter($names[$n], fn($i) => $i !== (int)$p['id']))];
}
ksort($countries);
$stats = ['total' => count($rows), 'missing' => count(array_filter($rows, fn($r) => $r['missing'])),
    'change' => count(array_filter($rows, fn($r) => $r['newName'] !== $r['name'] || $r['newCountry'] !== $r['country']))];

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/app-pages.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-pages.css') ?>">
<style>
    .prv-hero { border-radius: 22px; background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%); padding: 22px 24px; margin: 12px 12px 16px; display: flex; align-items: center; gap: 16px; box-shadow: 0 14px 34px rgba(30,27,107,.22); color: #fff; }
    .prv-hero i { width: 50px; height: 50px; border-radius: 15px; flex: none; background: rgba(255,255,255,.12); display: flex; align-items: center; justify-content: center; font-size: 21px; }
    .prv-hero b { display: block; font-size: 20px; font-weight: 800; }
    .prv-hero small { display: block; font-size: 12px; color: rgba(255,255,255,.75); margin-top: 3px; }
    .prv-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 16px; margin: 0 12px 16px; box-shadow: 0 4px 20px rgba(15,23,42,.05); }
    .prv-stats { display: flex; flex-wrap: wrap; gap: 10px; }
    .prv-stat { flex: 1 1 110px; border-radius: 14px; padding: 10px 12px; text-align: center; background: #F1F5F9; color: #334155; }
    .prv-stat .n { font-size: 20px; font-weight: 800; } .prv-stat .l { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .prv-stat.miss { background: #FEF2F2; color: #DC2626; } .prv-stat.chg { background: #F5F3FF; color: #5B21B6; }
    .prv-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 14px; }
    .prv-btn { display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 12px; padding: 10px 16px; font-size: 13px; font-weight: 700; cursor: pointer; }
    .prv-btn.pri { background: linear-gradient(120deg, #14532D, #16A34A); color: #fff; }
    .prv-btn.ai { background: #F5F3FF; color: #5B21B6; border: 1.5px solid #C4B5FD; }
    .prv-btn.ghost { background: #F1F5F9; color: #334155; }
    .prv-btn:disabled { opacity: .55; cursor: not-allowed; }
    .prv-bar input[type=search] { flex: 1 1 200px; min-height: 40px; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 6px 12px; font-size: 13px; }
    .prv-bar select { min-height: 40px; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 6px 10px; font-size: 13px; }
    .prv-note { font-size: 12.5px; color: #475569; margin-top: 10px; } .prv-note.err { color: #B91C1C; font-weight: 600; } .prv-note.ok { color: #166534; font-weight: 600; }
    .prv-wrap { overflow: auto; border: 1px solid #e2e8f0; border-radius: 14px; max-height: 70vh; }
    table.prv { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.prv th { position: sticky; top: 0; z-index: 1; background: #F8FAFC; text-align: left; padding: 9px 10px; font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.prv td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: top; }
    table.prv tr.miss td { background: #FFF7F7; }
    table.prv input[type=text] { width: 100%; min-width: 150px; border: 1.5px solid #e2e8f0; border-radius: 9px; padding: 6px 8px; font-size: 12.5px; font-weight: 700; text-transform: uppercase; }
    table.prv input.chg { border-color: #A78BFA; background: #FAF8FF; }
    table.prv input.need { border-color: #F87171; background: #FEF2F2; }
    .prv-old { font-size: 11px; color: #94a3b8; margin-top: 3px; }
    .prv-tag { display: inline-block; margin: 3px 4px 0 0; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700; }
    .prv-tag.m { background: #FEE2E2; color: #B91C1C; } .prv-tag.d { background: #FFEDD5; color: #9A3412; } .prv-tag.s { background: #F1F5F9; color: #475569; }
    .prv-ai { margin-top: 4px; font-size: 11.5px; color: #5B21B6; }
    .prv-ai button { margin-left: 6px; border: 1px solid #C4B5FD; background: #fff; color: #5B21B6; border-radius: 8px; padding: 1px 8px; font-weight: 700; font-size: 11px; }
    @media (min-width: 992px) { .top-app-bar { display: none !important; } .prv-hero, .prv-card { margin-left: 0; margin-right: 0; } }
</style>
<?php
$page_title = 'Review Ports';
$back_url = 'admin_controls.php';
$page_testid = 'ports-review';
include 'includes/top_app_bar.php';
?>
<div class="scroll-content" data-testid="ports-review-page">
    <div class="prv-hero"><i class="fa-solid fa-anchor-circle-check"></i><div><b>Review Ports</b><small>Port names and countries in capitals; give every port its country. Nothing changes until you press Save changes.</small></div></div>

    <div class="prv-card">
        <div class="prv-stats">
            <div class="prv-stat"><div class="n"><?= $stats['total'] ?></div><div class="l">Ports</div></div>
            <div class="prv-stat chg"><div class="n" data-testid="prv-change-count"><?= $stats['change'] ?></div><div class="l">To change</div></div>
            <div class="prv-stat miss"><div class="n" data-testid="prv-missing-count"><?= $stats['missing'] ?></div><div class="l">Country not specified</div></div>
        </div>
        <div class="prv-bar">
            <input type="search" placeholder="Search port or country…" data-prv-q aria-label="Search">
            <select data-prv-show aria-label="Show">
                <option value="all">All ports</option>
                <option value="change">Only ports that change</option>
                <option value="missing">Only country not specified</option>
            </select>
            <button type="button" class="prv-btn ai" data-prv-ai data-testid="prv-ai"><i class="fa-solid fa-wand-magic-sparkles"></i> AI check names &amp; countries</button>
            <button type="button" class="prv-btn pri" data-prv-save data-testid="prv-save"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
        </div>
        <div class="prv-note" data-prv-note data-testid="prv-note">The new name and country are filled in for you (capitals, single spaces; a blank country is India). Check the rows marked red, then press Save changes. Untick a row to leave that port as it is.</div>
    </div>

    <div class="prv-card">
        <div class="prv-wrap">
            <table class="prv" data-testid="prv-table">
                <thead><tr><th><input type="checkbox" data-prv-all checked aria-label="All"></th><th>#</th><th>Port name</th><th>Country</th><th>Vessels</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr data-id="<?= $r['id'] ?>" class="<?= $r['missing'] ? 'miss' : '' ?>" data-testid="prv-row">
                        <td><input type="checkbox" data-prv-on checked aria-label="Change this port"></td>
                        <td><?= $r['id'] ?></td>
                        <td>
                            <input type="text" data-prv-name value="<?= sanitize($r['newName']) ?>" data-old="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" maxlength="150">
                            <div class="prv-old">Now: <?= sanitize($r['name']) ?: '—' ?></div>
                            <?php if ($r['dupOf']): ?><span class="prv-tag d">Same name as #<?= implode(', #', $r['dupOf']) ?></span><?php endif; ?>
                            <?php if ($r['spaces']): ?><span class="prv-tag s">Extra spaces</span><?php endif; ?>
                            <div class="prv-ai" data-prv-ai-name></div>
                        </td>
                        <td>
                            <input type="text" data-prv-country list="prvCountries" value="<?= sanitize($r['newCountry']) ?>" data-old="<?= htmlspecialchars($r['country'], ENT_QUOTES) ?>" maxlength="100" placeholder="Choose the country">
                            <div class="prv-old">Now: <?= $r['country'] !== '' ? sanitize($r['country']) : '(blank = India)' ?></div>
                            <?php if ($r['missing']): ?><span class="prv-tag m">Country not specified</span><?php endif; ?>
                            <div class="prv-ai" data-prv-ai-country></div>
                        </td>
                        <td><?= $r['used'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <datalist id="prvCountries"><?php foreach (array_keys($countries) as $c): ?><option value="<?= sanitize($c) ?>"></option><?php endforeach; ?></datalist>
    </div>
</div>
<script>window.PRV_CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/js/ports_review.js?v=<?= @filemtime(__DIR__ . '/assets/js/ports_review.js') ?: time() ?>"></script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
