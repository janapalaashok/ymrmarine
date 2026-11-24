<?php
/**
 * Survey Report — the one page for survey reports, with or without signing in (a page of its own,
 * no YSMS menu). Editor: assets/js/survey-report.js. The preview opens in a window (modal).
 *   survey_report_public.php            choose the report type → a new report (a private link)
 *   survey_report_public.php?t=<token>  that report (the secret link; keep it to continue later).
 *                                       Rules and limits: includes/survey_report_guest.php; API ajax/survey_report_public.php
 *   survey_report_public.php?draft=<x>  a report started without internet: kept on this device (the editor
 *                                       reads it there) and made on the server as soon as there is a connection
 *   survey_report_public.php?id=<id>    a report of a vessel (or any report, for the Admin) — signed in only
 *                                       (survey_report.php sends here). Access: includes/survey_reports.php; API ajax/survey_reports.php
 */
require_once 'config/config.php';
require_once 'includes/survey_report_guest.php';
require_once 'includes/survey_reports.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer'); // the secret link never leaves in a Referer header

$token = srgToken($_GET['t'] ?? '');
$draft = $token === '' && preg_match('/^[a-z0-9]{4,24}$/', (string)($_GET['draft'] ?? '')) ? (string)$_GET['draft'] : '';
$rid = $token === '' && $draft === '' ? (int)($_GET['id'] ?? 0) : 0;
$kinds = [];
$notFound = false;
$srBack = 'survey_report_public.php'; $srBackLabel = 'New report';
if ($rid > 0) {
    // a report by its number: only for a signed-in user who may use Survey Reports (checked again by the API)
    if (empty($_SESSION['user_id'])) { header('Location: login.php?next=' . rawurlencode('survey_report_public.php?id=' . $rid)); exit; }
    if (!srCanUse()) { header('Location: index.php'); exit; }
}
try {
    $db = getDB();
    if ($token !== '') { if (!srgReport($db, $token)) { $notFound = true; $token = ''; } }
    if ($rid > 0) {
        $srR = srReport($db, $rid);
        if (!$srR) { $notFound = true; $rid = 0; }
        elseif (!empty($srR['survey'])) {
            $sv = $srR['survey'];
            $srBack = match ((string)$sv['status']) { 'Pending Report' => 'report_detail.php?id=' . (int)$sv['id'], 'Completed' => 'completed_detail.php?id=' . (int)$sv['id'], default => 'vessel_detail.php?id=' . (int)$sv['id'] };
            $srBackLabel = (string)$sv['vessel_name'];
        } else { $srBack = 'survey_reports.php'; $srBackLabel = 'Survey Reports'; }
    }
    if ($token === '' && $rid === 0 && $draft === '') $kinds = srgKinds($db);
} catch (Throwable $e) {
    error_log('survey_report_public: ' . $e->getMessage());
}
$editing = $token !== '' || $rid > 0 || $draft !== '';
$v = fn(string $f) => @filemtime(__DIR__ . '/' . $f);
$signedIn = !empty($_SESSION['user_id']);
$signedName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
$signedRole = (string)($_SESSION['role'] ?? '');
$here = 'survey_report_public.php' . ($token !== '' ? '?t=' . $token : ($rid > 0 ? '?id=' . $rid : ''));
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($signedName)) ?: [], 0, 2)))) ?: 'U';
$kindIcons = ['rob' => 'fa-gas-pump', 'offhire' => 'fa-anchor-circle-xmark', 'onhire' => 'fa-anchor-circle-check', 'offhire3' => 'fa-people-group', 'onhire3' => 'fa-people-group', 'offhire4' => 'fa-people-line'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Survey Report — <?= sanitize(SITE_NAME) ?></title>
    <link rel="icon" href="assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/survey-report.css?v=<?= $v('assets/css/survey-report.css') ?>">
    <style>
        body.sr-public { margin: 0; background: #f3f6fb; font-family: 'Lexend', system-ui, sans-serif; color: #0f172a; min-height: 100vh; display: flex; flex-direction: column; }
        /* top bar */
        .srp-top { position: sticky; top: 0; z-index: 1030; background: linear-gradient(90deg, #0b1e46, #12346e); color: #fff; box-shadow: 0 2px 14px rgba(11,30,70,.25); }
        .srp-top-in { display: flex; align-items: center; gap: 12px; padding: 10px 20px; max-width: 1500px; margin: 0 auto; }
        .srp-brand { display: flex; align-items: center; gap: 11px; text-decoration: none; color: #fff; min-width: 0; }
        .srp-brand .lg { width: 40px; height: 40px; border-radius: 11px; background: #fff; display: grid; place-items: center; flex: none; box-shadow: 0 2px 8px rgba(0,0,0,.18); }
        .srp-brand .lg img { width: 30px; height: 30px; object-fit: contain; }
        .srp-brand b { display: block; font-size: 16px; font-weight: 800; letter-spacing: .06em; line-height: 1.1; }
        .srp-brand small { display: block; font-size: 11.5px; color: #9fc3ff; letter-spacing: .04em; }
        .srp-top-sp { flex: 1; }
        .srp-user { display: flex; align-items: center; gap: 10px; }
        .srp-av { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #00A3DF, #6C4CF1); display: grid; place-items: center; font-weight: 800; font-size: 13.5px; flex: none; }
        .srp-un { line-height: 1.15; min-width: 0; } .srp-un b { display: block; font-size: 13.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; } .srp-un small { color: #9fc3ff; font-size: 11.5px; }
        .srp-tb { height: 36px; padding: 0 14px; border-radius: 9px; font: 700 13px 'Lexend', system-ui, sans-serif; display: inline-flex; align-items: center; gap: 7px; text-decoration: none; border: 1px solid rgba(255,255,255,.35); color: #fff; background: rgba(255,255,255,.06); white-space: nowrap; }
        .srp-tb:hover { background: rgba(255,255,255,.16); color: #fff; }
        .srp-tb.solid { background: #fff; color: #0b1e46; border-color: #fff; } .srp-tb.solid:hover { background: #e8f1ff; color: #0b1e46; }
        @media (max-width: 575.98px) { .srp-un, .srp-tb .t { display: none; } .srp-top-in { padding: 8px 12px; } .srp-brand small { display: none; } }
        /* landing */
        .srp-hero { background: radial-gradient(1200px 400px at 80% -10%, rgba(0,163,223,.35), transparent 60%), linear-gradient(180deg, #12346e, #0b1e46); color: #fff; padding: 46px 20px 120px; text-align: center; }
        .srp-hero h1 { font-size: clamp(26px, 4vw, 38px); font-weight: 800; margin: 0 0 10px; letter-spacing: -.01em; }
        .srp-hero p { margin: 0 auto; max-width: 620px; color: #c8dcff; font-size: 15px; }
        .srp-hero .tag { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #7fd3ff; background: rgba(0,163,223,.14); border: 1px solid rgba(127,211,255,.35); padding: 5px 12px; border-radius: 999px; margin-bottom: 16px; }
        .srp-main { max-width: 860px; width: 100%; margin: -86px auto 0; padding: 0 16px 40px; position: relative; }
        .srp-panel { background: #fff; border-radius: 20px; box-shadow: 0 18px 50px rgba(11,30,70,.16); padding: 26px; border: 1px solid #e6edf7; }
        .srp-panel[hidden] { display: none; }
        .srp-panel h2 { font-size: 19px; font-weight: 800; margin: 0 0 4px; color: #0b1e46; }
        .srp-panel .sub { color: #64748b; font-size: 13.5px; margin: 0 0 18px; }
        .srp-opts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .srp-opt { display: flex; gap: 14px; align-items: flex-start; text-align: left; padding: 18px; border-radius: 16px; border: 1.5px solid #dbe5f2; background: #fff; cursor: pointer; text-decoration: none; color: #0f172a; transition: all .15s; font: inherit; }
        .srp-opt:hover { border-color: #0b63e5; box-shadow: 0 8px 22px rgba(11,99,229,.12); transform: translateY(-1px); color: #0f172a; }
        .srp-opt .ic { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; font-size: 19px; flex: none; }
        .srp-opt.a .ic { background: #e8f1ff; color: #0b63e5; } .srp-opt.b .ic { background: #e9f9ef; color: #16a34a; }
        .srp-opt b { display: block; font-size: 15.5px; margin-bottom: 3px; } .srp-opt span { font-size: 12.5px; color: #64748b; }
        .srp-kinds { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; }
        .srp-kind { display: flex; align-items: center; gap: 12px; padding: 14px; border-radius: 14px; border: 1.5px solid #dbe5f2; background: #fff; cursor: pointer; text-align: left; font: inherit; color: #0f172a; transition: all .15s; }
        .srp-kind:hover { border-color: #0b63e5; background: #f7faff; box-shadow: 0 6px 18px rgba(11,99,229,.1); }
        .srp-kind[disabled] { opacity: .6; cursor: progress; }
        .srp-kind .ic { width: 40px; height: 40px; border-radius: 11px; background: linear-gradient(135deg, #0b63e5, #00A3DF); color: #fff; display: grid; place-items: center; flex: none; }
        .srp-kind b { font-size: 14.5px; display: block; } .srp-kind span { font-size: 12px; color: #64748b; }
        .srp-kind .go { margin-left: auto; color: #94a3b8; }
        .srp-who { display: flex; align-items: center; gap: 10px; background: #f0f7ff; border: 1px solid #d5e6fb; border-radius: 12px; padding: 10px 14px; margin-bottom: 18px; font-size: 13.5px; color: #1e3a8a; }
        .srp-last { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-top: 16px; padding: 12px 14px; border-radius: 12px; background: #fffbeb; border: 1px solid #fde68a; font-size: 13.5px; color: #92400e; }
        .srp-last[hidden] { display: none; }
        .srp-drafts { margin-top: 16px; padding: 12px 14px; border-radius: 12px; background: #eff6ff; border: 1px solid #bfdbfe; font-size: 13.5px; }
        .srp-drafts[hidden] { display: none; }
        .srp-drafts a { display: block; font-weight: 700; color: #1d4ed8; text-decoration: none; margin-top: 6px; }
        .srp-last a { font-weight: 700; color: #b45309; text-decoration: none; }
        .srp-feat { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 18px; }
        .srp-feat div { display: flex; gap: 10px; align-items: center; font-size: 12.5px; color: #475569; background: #fff; border: 1px solid #e6edf7; border-radius: 14px; padding: 12px 14px; }
        .srp-feat i { color: #0b63e5; font-size: 16px; }
        .srp-foot { margin-top: auto; text-align: center; font-size: 12px; color: #94a3b8; padding: 18px; }
        @media (max-width: 640px) { .srp-opts, .srp-feat { grid-template-columns: 1fr; } .srp-panel { padding: 18px; } .srp-hero { padding: 30px 16px 104px; } }
        .sr-public .sr-wrap { margin: 0 auto; padding-bottom: 60px; width: 100%; max-width: 1320px; box-sizing: border-box; }
        .sr-public .sr-wiz { max-width: none; }
        body.sr-public[data-sr-step=tanks] .sr-wrap { max-width: none; }
        @media (min-width: 992px) { .sr-public .sr-v4 .sr-head { background: #fff; border: 1px solid #e6edf7; border-radius: 14px; padding: 10px 14px; margin-top: 14px; box-shadow: 0 4px 14px rgba(11,30,70,.05); } }
        .sr-public .srp-link { background: #fff; border-color: #e6edf7; box-shadow: 0 4px 14px rgba(11,30,70,.05); }
        /* the preview is never a side panel here: the Preview button opens it as a window */
        .sr-public .sr-v4 .sr-split { display: block; }
        .sr-public .sr-pv-fab, .sr-public .sr-pv-exp { display: none !important; }
        .sr-public .sr-pv { display: none; }
        body.sr-public.sr-pv-open .sr-v4 .sr-pv { display: flex; flex-direction: column; position: fixed; z-index: 2000; background: #fff; inset: 0; top: 0; height: auto; border: 0; border-radius: 0; }
        .sr-public .sr-pv-x { display: inline-flex !important; }
        .srp-pv-btn { height: 38px; padding: 0 16px; border: 0; border-radius: 10px; font: 700 13.5px 'Lexend', system-ui, sans-serif; color: #fff;
            background: linear-gradient(135deg, #00A3DF, #6C4CF1); box-shadow: 0 6px 16px rgba(108,76,241,.28); display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
        @media (min-width: 992px) {
            body.sr-public.sr-pv-open::after { content: ''; position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 1999; }
            body.sr-public.sr-pv-open .sr-v4 .sr-pv { inset: 3vh auto 3vh 50%; transform: translateX(-50%); width: min(980px, 94vw); border-radius: 14px; overflow: hidden; box-shadow: 0 24px 60px rgba(15,23,42,.35); }
        }
        /* phone: Previous / Next fixed to the very bottom (this page has no app menu below), no Preview button on Tank Details */
        @media (max-width: 991.98px) {
            .sr-public .sr-wiz-nav { bottom: 0; padding-bottom: calc(10px + env(safe-area-inset-bottom, 0px)); box-shadow: 0 -4px 14px rgba(11,30,70,.08); }
            .sr-public .sr-wrap { padding-bottom: 96px; }
            body.sr-public[data-sr-step=tanks] .srp-pv-btn { display: none !important; }
            .srp-pv-btn { bottom: calc(84px + env(safe-area-inset-bottom, 0px)) !important; } /* other steps: above Previous / Next */
        }
        @media (max-width: 991.98px) { .srp-pv-btn { position: fixed; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom, 0px)); z-index: 1040; height: 46px; border-radius: 999px; } }
        .srp-link { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: #eef5ff; border: 1px solid #cfe0fb; color: #1e3a8a; border-radius: 12px; padding: 10px 14px; font-size: 13px; margin-bottom: 14px; }
        .srp-link input { flex: 1 1 260px; min-width: 0; height: 34px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; font-size: 12.5px; color: #334155; background: #fff; }
        .srp-link button { height: 34px; border: 1px solid #0b63e5; background: #fff; color: #0b63e5; border-radius: 8px; font-weight: 700; font-size: 12.5px; padding: 0 12px; }
    </style>
</head>
<body class="sr-public">
<header class="srp-top" data-testid="srp-top">
    <div class="srp-top-in">
        <a class="srp-brand" href="survey_report_public.php"><span class="lg"><img src="assets/images/logo.png" alt=""></span><span><b>YMR MARINE</b><small>Survey Report</small></span></a>
        <span class="srp-top-sp"></span>
        <?php if ($signedIn): ?>
            <div class="srp-user" data-testid="srp-signed-in">
                <span class="srp-av"><?= sanitize($initials) ?></span>
                <span class="srp-un"><b><?= sanitize($signedName) ?></b><small><?= sanitize($signedRole) ?></small></span>
                <a class="srp-tb" href="logout.php?next=<?= rawurlencode($here) ?>" data-testid="srp-logout"><i class="fa-solid fa-right-from-bracket"></i><span class="t"> Logout</span></a>
            </div>
        <?php else: ?>
            <a class="srp-tb solid" href="login.php?next=<?= rawurlencode($here) ?>" data-testid="srp-top-login"><i class="fa-solid fa-right-to-bracket"></i><span class="t"> Login</span></a>
        <?php endif; ?>
    </div>
</header>
<?php if (!$editing): ?>
<section class="srp-hero">
    <span class="tag"><i class="fa-solid fa-ship"></i> Bunker survey</span>
    <h1>Survey Report</h1>
    <p>Enter the vessel, survey and tank readings on site. The report is saved as you type, previewed as it will print, and made on the YMR letterhead.</p>
</section>
<main class="srp-main">
    <?php if ($notFound): ?>
        <div class="sr-note err mb-3" data-testid="srp-not-found">This report was not found. Start a new report below, or use the link you saved.</div>
    <?php endif; ?>
    <?php if (!$signedIn): ?>
    <section class="srp-panel" data-srp-choice data-testid="srp-choice">
        <h2>How would you like to continue?</h2>
        <p class="sub">Sign in with your YSMS account, or start straight away without signing in.</p>
        <div class="srp-opts">
            <a class="srp-opt a" href="login.php?next=survey_report_public.php" data-testid="srp-login"><span class="ic"><i class="fa-solid fa-user-shield"></i></span><span><b>Login</b><span>With your YSMS account — the report is kept with your account.</span></span></a>
            <button type="button" class="srp-opt b" data-srp-guest data-testid="srp-guest"><span class="ic"><i class="fa-solid fa-arrow-right"></i></span><span><b>Continue without login</b><span>Start now; keep the report's private link to come back to it.</span></span></button>
        </div>
    </section>
    <?php endif; ?>
    <section class="srp-panel" data-testid="srp-new"<?= $signedIn ? '' : ' hidden data-srp-kinds' ?>>
        <?php if ($signedIn): ?><div class="srp-who"><i class="fa-solid fa-circle-check"></i><span>Signed in as <b><?= sanitize($signedName) ?></b> — this report will be kept with your account.</span></div><?php endif; ?>
        <h2>Start a new report</h2>
        <?php if (!$kinds): ?>
            <div class="sr-note warn" data-testid="srp-no-kinds">Survey reports are not available right now.</div>
        <?php else: ?>
            <p class="sub">Choose the report type.</p>
            <div class="srp-kinds">
                <?php foreach ($kinds as $k => $l): ?>
                    <button type="button" class="srp-kind" data-new-kind="<?= sanitize($k) ?>" data-testid="srp-new-<?= sanitize($k) ?>"><span class="ic"><i class="fa-solid <?= sanitize($kindIcons[$k] ?? 'fa-file-lines') ?>"></i></span><span><b><?= sanitize($l) ?></b><span>New report</span></span><i class="fa-solid fa-chevron-right go"></i></button>
                <?php endforeach; ?>
            </div>
            <div class="srp-drafts" data-drafts hidden data-testid="srp-drafts"></div>
            <div class="srp-last" data-last hidden data-testid="srp-last"><span><i class="fa-solid fa-clock-rotate-left"></i> You have a report on this device.</span><a href="#" data-last-link>Continue it <i class="fa-solid fa-arrow-right"></i></a></div>
            <div class="sr-note err mt-3" data-new-err hidden data-testid="srp-new-err"></div>
        <?php endif; ?>
    </section>
    <div class="srp-feat">
        <div><i class="fa-solid fa-floppy-disk"></i> Saved as you type</div>
        <div><i class="fa-solid fa-file-pdf"></i> Preview and PDF on the YMR letterhead</div>
        <div><i class="fa-solid fa-link"></i> Private link to continue later</div>
    </div>
</main>
<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>, busy = false;
  // without signing in: the choice first (remembered for this browser tab)
  var choice = document.querySelector('[data-srp-choice]'), kinds = document.querySelector('[data-srp-kinds]');
  var lastA = document.querySelector('[data-last-link]');
  function guest() { if (choice) choice.hidden = true; if (kinds) kinds.hidden = false; try { sessionStorage.setItem('ysms_srp_guest', '1'); } catch (e) {} }
  try { if (sessionStorage.getItem('ysms_srp_guest') === '1') guest(); } catch (e) {}
  var gb = document.querySelector('[data-srp-guest]'); if (gb) gb.addEventListener('click', guest);
  try { var last = localStorage.getItem('ysms_srp_last'); if (last && /^[0-9a-f]{64}$/.test(last)) { var l = document.querySelector('[data-last]'); if (l) { l.hidden = false; lastA.href = '?t=' + last; } } } catch (e) {}
  // ---- without internet: this page, the editor and the starting form of each report type are kept on the device ----
  var KINDS = Array.prototype.map.call(document.querySelectorAll('[data-new-kind]'), function (x) { return x.getAttribute('data-new-kind'); });
  function rd(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function wr(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('survey_report_sw.js', { scope: './survey_report' }).catch(function () {});
  function keepOffline() {
    if (!navigator.onLine) return;
    KINDS.forEach(function (k) { // the form a new report of this type starts with (template fields, grades, wording)
      fetch('ajax/survey_report_public.php?a=starter&kind=' + encodeURIComponent(k), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) wr('ysms_srp_starter_' + k, { at: Date.now(), res: j }); }).catch(function () {});
    });
    if (!('caches' in window)) return;
    var shell = new URL('survey_report_public.php?draft=shell', location.href).href;
    caches.open('ysms-sr-v1').then(function (c) {
      fetch(location.href, { credentials: 'same-origin' }).then(function (r) { if (r.ok) c.put(location.href.split('#')[0], r); }).catch(function () {});
      return fetch(shell, { credentials: 'same-origin' }).then(function (r) {
        if (!r.ok) return;
        c.put(shell, r.clone());
        return r.text().then(function (html) { // every script, style and font the editor loads
          var urls = [], re = /(?:src|href)="([^"]+\.(?:js|css)(?:\?[^"]*)?)"/g, m;
          while ((m = re.exec(html))) urls.push(new URL(m[1].replace(/&amp;/g, '&'), location.href).href);
          ['assets/images/ymr-logo-horizontal.svg', 'assets/images/survey-letterhead.png', 'assets/images/logo.png'].forEach(function (u) { urls.push(new URL(u, location.href).href); });
          urls.forEach(function (u) {
            var same = new URL(u).origin === location.origin;
            c.match(u).then(function (hit) { if (hit) return; fetch(u, same ? { credentials: 'same-origin' } : { mode: 'no-cors' }).then(function (x) { if (x.ok || x.type === 'opaque') c.put(u, x); }).catch(function () {}); });
          });
        });
      }).catch(function () {});
    }).catch(function () {});
  }
  window.addEventListener('load', function () { setTimeout(keepOffline, 800); });
  // reports started without internet, waiting to be sent
  (function () {
    var box = document.querySelector('[data-drafts]'), list = [];
    try { Object.keys(localStorage).forEach(function (k) { var m = /^ysms_srp_draft_([a-z0-9]+)$/.exec(k); if (m) { var d = rd(k); if (d) list.push({ id: m[1], d: d }); } }); } catch (e) {}
    if (!box || !list.length) return;
    box.hidden = false;
    box.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Reports started without internet (sent when online):' + list.map(function (x) {
      var dt = x.d.res && x.d.res.report ? x.d.res.report : {}, ed = rd('ysms_sr_draft-' + x.id), v = (ed && ed.data && ed.data.fields && ed.data.fields.vessel_name) || 'New report';
      return '<a href="?draft=' + x.id + '" data-testid="srp-draft-' + x.id + '">' + String(v).replace(/[<>&"]/g, '') + ' · ' + String(dt.kind_label || '').replace(/[<>&"]/g, '') + ' <i class="fa-solid fa-arrow-right"></i></a>';
    }).join('');
  })();
  function offlineNew(kind, b) {
    var st = rd('ysms_srp_starter_' + kind), m = document.querySelector('[data-new-err]');
    if (!st || !st.res) { busy = false; b.disabled = false; if (m) { m.hidden = false; m.textContent = 'No internet. Open this page once while online, then a new report can be started without internet on this device.'; } return; }
    var id = Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    wr('ysms_srp_draft_' + id, { kind: kind, at: Date.now(), res: st.res });
    location.href = '?draft=' + id;
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-new-kind]'); if (!b || busy) return;
    busy = true; b.disabled = true;
    var kind = b.getAttribute('data-new-kind');
    if (!navigator.onLine) { offlineNew(kind, b); return; }
    var f = new FormData(); f.append('csrf_token', csrf); f.append('a', 'new'); f.append('kind', kind);
    fetch('ajax/survey_report_public.php', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json(); }, function () { return { offline: true }; })
      .then(function (j) {
        if (j && j.offline) { offlineNew(kind, b); return; } // no connection after all: start it on this device
        if (j && j.ok && j.token) { location.replace('?t=' + j.token); return; } throw new Error(j && j.error || 'Could not make the report.'); })
      .catch(function (err) { busy = false; b.disabled = false; var m = document.querySelector('[data-new-err]'); if (m) { m.hidden = false; m.textContent = err.message || 'No connection.'; } });
  });
})();
</script>
<?php else: ?>
<div class="sr-wrap sr-v4" data-testid="survey-report-page">
    <div class="sr-head">
        <div class="sr-crumbs"><a href="<?= sanitize($srBack) ?>" class="sr-back" data-testid="sr-back" title="Back to <?= sanitize($srBackLabel) ?>"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="sr-crumb"><span data-kindcrumb>Survey</span> Report <i>/</i> <b data-crumb>Vessel Details</b></span>
            <h1 data-title hidden>Survey report</h1><span data-subtitle hidden></span></div>
        <nav class="sr-hsteps" data-steps aria-label="Steps"></nav>
        <div class="sr-head-r">
            <span class="sr-chip-h"><span>Vessel:</span> <b data-vchip data-testid="sr-vchip">—</b></span>
            <span class="sr-chip-h"><span>Report:</span> <b data-kchip>—</b></span>
            <span class="sr-chip-h"><i class="fa-regular fa-calendar"></i> <span>Date:</span> <b data-dchip>—</b></span>
            <span class="sr-net" data-net data-testid="sr-net"></span>
            <span class="sr-save" data-save-state data-testid="sr-save-state"></span>
            <span class="sr-live" data-live hidden data-testid="sr-live"></span>
            <?php if ($token !== ''): ?><button type="button" class="srp-copy-h" data-srp-copy title="Copy the link of this report (keep it to continue later)" data-testid="srp-copy-head"><i class="fa-solid fa-link"></i><span> Copy link</span></button><?php endif; ?>
            <button type="button" class="srp-pv-btn" data-pv="open" data-testid="srp-preview-open"><i class="fa-solid fa-eye"></i> Preview</button>
            <button type="button" class="sr-pv-fab" data-pv="open" data-testid="sr-pv-open" hidden></button>
        </div>
    </div>
    <?php if ($token !== ''): ?><div class="srp-link" data-testid="srp-link"><i class="fa-solid fa-link"></i> <span>Keep this link to continue your report later:</span>
        <input type="text" readonly data-srp-url aria-label="Report link" data-testid="srp-link-url">
        <button type="button" data-srp-copy data-testid="srp-link-copy"><i class="fa-regular fa-copy"></i> Copy</button></div><?php endif; ?>
    <div class="sr-split">
        <div class="sr-split-l">
            <div data-banners></div>
            <div data-form data-testid="sr-form"></div>
        </div>
        <aside class="sr-pv" data-testid="sr-preview" aria-label="Report preview" role="dialog" aria-modal="true">
            <div class="sr-pv-tabs">
                <button type="button" class="sr-pv-tab" data-pv="load" data-pv-tab="pdf" data-testid="sr-preview-report"><i class="fa-regular fa-file-lines"></i> Report Preview</button>
                <button type="button" class="sr-pv-tab" data-pv="excel" data-pv-tab="excel" data-testid="sr-excel-view"><i class="fa-solid fa-table"></i> Excel View</button>
                <span class="sr-pv-sp"></span>
                <button type="button" class="sr-pv-b sr-pv-x" data-pv="close" title="Close" aria-label="Close preview" data-testid="sr-pv-close"><i class="fa-solid fa-xmark"></i> Close</button>
            </div>
            <div class="sr-pv-bar" data-pv-bar hidden>
                <span class="sr-pv-st" data-pv-status data-testid="sr-pv-status"></span>
                <span class="sr-pv-sp"></span>
                <button type="button" class="sr-pv-b" data-pv="prev" title="Previous page" aria-label="Previous page" data-testid="sr-pv-prev"><i class="fa-solid fa-chevron-left"></i></button>
                <span class="sr-pv-n" data-pv-page data-testid="sr-pv-pageno">– / –</span>
                <button type="button" class="sr-pv-b" data-pv="next" title="Next page" aria-label="Next page" data-testid="sr-pv-next"><i class="fa-solid fa-chevron-right"></i></button>
                <button type="button" class="sr-pv-b" data-pv="out" title="Zoom out" aria-label="Zoom out" data-testid="sr-pv-out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                <button type="button" class="sr-pv-b sr-pv-z" data-pv="fit" title="Fit width" data-pv-zoomv data-testid="sr-pv-zoom">100%</button>
                <button type="button" class="sr-pv-b" data-pv="in" title="Zoom in" aria-label="Zoom in" data-testid="sr-pv-in"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            </div>
            <div class="sr-pv-c" data-pv-canvas data-testid="sr-pv-canvas"><div class="sr-pv-empty" data-testid="sr-pv-empty"><i class="fa-regular fa-file-lines"></i><b>Report preview</b><span>The report as it will print, made from the saved values.</span></div></div>
            <div class="sr-pv-note">Preview only — nothing is stored.</div>
        </aside>
    </div>
</div>
<?php if ($token !== ''): ?>
<script>
(function () {
  var url = location.origin + location.pathname + '?t=' + <?= json_encode($token) ?>;
  try { localStorage.setItem('ysms_srp_last', <?= json_encode($token) ?>); } catch (e) {}
  var inp = document.querySelector('[data-srp-url]'); inp.value = url;
  document.querySelectorAll('[data-srp-copy]').forEach(function (cb) { cb.addEventListener('click', function () {
    var b = this, was = b.innerHTML, done = function () { b.innerHTML = '<i class="fa-solid fa-check"></i> Copied'; setTimeout(function () { b.innerHTML = was; }, 2000); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { inp.select(); document.execCommand('copy'); done(); });
    else { inp.select(); document.execCommand('copy'); done(); }
  }); });
})();
</script>
<?php endif; ?>
<script>window.SR_PAGE = <?= json_encode(($token !== '' ? ['id' => $token, 'guest' => true, 'api' => 'ajax/survey_report_public.php']
    : ($draft !== '' ? ['id' => 'draft-' . $draft, 'draft' => $draft, 'guest' => true, 'api' => 'ajax/survey_report_public.php'] : ['id' => $rid, 'api' => 'ajax/survey_reports.php']))
    + ['std' => true, 'csrf' => csrf_token(),
    // Tank Details on a computer: one plain 9-column table per grade (the Excel-style sheet, survey-report-xl.js, is off)
    'xl' => false,
    'pdfjs' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', 'pdfjsWorker' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="assets/js/report-calc.js?v=<?= $v('assets/js/report-calc.js') ?>"></script>
<script src="assets/js/survey-report.js?v=<?= $v('assets/js/survey-report.js') ?>"></script>
<?php endif; ?>
<footer class="srp-foot">© <?= date('Y') ?> YMR Marine · Survey Management System</footer>
</body>
</html>
