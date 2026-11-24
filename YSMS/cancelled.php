<?php
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/report_table.php';
checkAuth();

$db = getDB();

/* Cancelled Vessels: the same table as Pending Reports / Completed Vessels (includes/report_table.php:
   search, filters, sorting and paging in SQL; the phone cards and the Download use the same state).
   Who sees what: Admin / Super Admin every cancelled vessel, a Client its own company's, a Surveyor
   the vessels they are assigned to. Read-only: View / Edit open vessel_detail.php. */
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$client_id = ($role === 'Client') ? getClientIdForUser($db, $user_id) : 0;
// Admin and Super Admin both see every cancelled vessel.
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
$page_title = 'Cancelled Vessels';

/* Default order: Survey Completed Date, newest first, vessels without one last (the Date menu switches it). */
[$rtScopeWhere, $rtScopeParams] = rtScope('Cancelled', $role, (int)$user_id, (int)$client_id, $is_full_access);
$rt = rtState($_GET, 'survey', $is_full_access);
[$rtWhereSql, $rtParams] = rtWhere($rt, $rtScopeWhere, $rtScopeParams);
[$rtTotal, $rtRows, $rtPage, $rtPages] = rtFetchPage($db, $rt, $rtWhereSql, $rtParams);
// vessel pictures for the mobile cards (AI search, includes/vessel_image.php); the YMR ship until one is found
require_once __DIR__ . '/includes/vessel_image.php';
$viStates = [];
try { $viStates = viStates($db, array_map(fn($x) => (string)($x['vessel_name'] ?? ''), $rtRows)); } catch (Throwable $e) { error_log('vessel pictures: ' . $e->getMessage()); }
$rt['page'] = $rtPage;
$rtOffset = ($rtPage - 1) * RT_PER_PAGE;
$rtOpts = rtOptions($db, $rtScopeWhere, $rtScopeParams, $is_full_access);
$rtYears = rtYears($db, $rtScopeWhere, $rtScopeParams, $rt['date']);
$rtBase = 'cancelled.php';
// Download: export_cancelled.php with the same search / filters; with a month or date choice, exactly the vessels the table matches
$rtExportQ = array_filter(['q' => $rt['q'], 'type' => $rt['type'], 'place' => $rt['place'], 'client' => $rt['client'], 'surveyor' => $rt['surveyor']]);
if (rtHasFilters($rt)) {
    $idStmt = $db->prepare('SELECT s.id ' . rtFromSql() . " WHERE $rtWhereSql LIMIT 5000");
    $idStmt->execute($rtParams);
    $rtExportQ = ['ids' => implode(',', $idStmt->fetchAll(PDO::FETCH_COLUMN)) ?: '0'];
}
$rtExport = 'export_cancelled.php' . ($rtExportQ ? '?' . http_build_query($rtExportQ) : '');
$rtChipsHtml = rtChips($rtBase, $rt);

// 🌟 Desktop hero banner stat + surveyor avatar helpers — display only,
// same helpers as vessels.php's redesign, duplicated here page-scoped.
function pvInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach (array_slice($parts ?: [], 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini !== '' ? $ini : '?';
}
function pvAvatarGradient(string $name): string {
    static $palette = [
        ['#8B7CF6', '#4B2FD6'],
        ['#22D3C8', '#0D9488'],
        ['#FBBF24', '#F59E0B'],
        ['#F472B6', '#DB2777'],
        ['#60A5FA', '#2563EB'],
    ];
    $idx = crc32($name) % count($palette);
    [$from, $to] = $palette[$idx];
    return "linear-gradient(135deg, $from, $to)";
}
$pv_total = $rtTotal;

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/report-table.css?v=<?= @filemtime(__DIR__ . '/assets/css/report-table.css') ?>">
<link rel="stylesheet" href="assets/css/mobile-card.css?v=<?= @filemtime(__DIR__ . '/assets/css/mobile-card.css') ?>">

<style>

    /* ═══ Mobile modern header + card redesign (same pattern as
       vessels.php) — page-scoped, only applies while this page's own
       <style> block is loaded, so it doesn't leak into other pages even
       though they share .top-app-bar/.vessel-card/.badge-place/etc.
       class names. Desktop (≥992px) is untouched. */
    @media (max-width: 991.98px) {
        .top-app-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 900;
            background: linear-gradient(160deg, #1d6fe0 0%, #123f99 65%, #0b1e46 100%);
            border-bottom: none;
            box-shadow: 0 6px 18px rgba(11,30,70,.25);
        }
        .top-app-bar-title { color: #fff; font-size: 22px; font-weight: 800; letter-spacing: .02em; }
        .top-app-bar .hamburger-menu-btn {
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.32);
            color: #fff;
        }
        .top-app-bar .hamburger-menu-btn:hover { background: rgba(255,255,255,.26); }
        .top-app-bar .profile-menu-trigger { position: relative; }
        .top-app-bar .profile-avatar { border: 2px solid rgba(255,255,255,.85); }
        .top-app-bar .profile-menu-trigger::after {
            content: ''; position: absolute; bottom: 1px; right: 1px;
            width: 11px; height: 11px; border-radius: 50%;
            background: #22c55e; border: 2px solid #1560c9;
        }
        .list-mobile-view { padding-top: 88px; }

        .search-filter-row { padding: 0 14px 12px; }
        .search-control { border-radius: 14px; padding: 12px 12px 12px 38px; }
        .search-input-wrapper i { top: 14px; }
        .filter-btn { border-radius: 14px; }

        .pv-actions-row { display: flex; gap: 10px; padding: 0 14px 14px; }
        .pv-export-btn {
            flex: 1 1 auto;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            background: linear-gradient(90deg, #16a34a, #0ea5b0);
            color: #fff; text-decoration: none; font-weight: 700; font-size: 13.5px;
            border-radius: 14px; padding: 12px 14px;
            box-shadow: 0 8px 18px rgba(16,163,74,.25);
        }
        .pv-import-btn {
            flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            background: #fff; color: #4B2FD6; border: 1.5px solid #C4B5FD; text-decoration: none;
            font-weight: 700; font-size: 13px; border-radius: 14px; padding: 0 12px;
        }
        .pv-sort-wrap {
            flex: 0 0 auto; display: flex; align-items: center; gap: 6px;
            background: #fff; border: 1px solid var(--border-color); border-radius: 14px;
            padding: 0 12px 0 14px; color: var(--text-dark);
        }
        .pv-sort-wrap i { font-size: 13px; color: #1d6fe0; }
        .pv-sort-select {
            border: none; background: transparent; appearance: none; -webkit-appearance: none;
            font-weight: 700; font-size: 13px; color: var(--text-dark);
            padding: 12px 18px 12px 6px;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6'><path d='M0 0l5 6 5-6z' fill='%231d6fe0'/></svg>");
            background-repeat: no-repeat; background-position: right 0 center;
        }

        .vessel-card { display: flex; flex-direction: column; align-items: stretch; border: none; border-radius: 20px; box-shadow: 0 8px 22px rgba(15,23,42,.08); background: #fff; }
        .pv-card-top { display: flex; flex-direction: column; align-items: stretch; gap: 8px; padding: 16px 16px 10px; }
        .pv-card-headline { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .pv-ship-icon {
            width: 40px; height: 40px; border-radius: 12px; flex: none;
            background: #FEE2E2; color: #B91C1C; font-size: 17px;
            display: flex; align-items: center; justify-content: center;
        }
        .pv-row-num {
            flex: none; width: 22px; height: 22px; border-radius: 50%;
            background: #F1F5F9; color: #64748b; font-size: 11px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
        }
        .pv-vessel-name { font-size: 16.5px; font-weight: 800; color: var(--text-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; flex: 1 1 auto; }
        /* Mobile alignment: cards line up with the search bar (14px gutter),
           no double padding (header.php's .vessel-card padding + this page's
           .pv-card-top padding), and the action row always fits the screen. */
        .vessel-list-container { padding: 0 14px 16px !important; }
        .vessel-list-container .vessel-card { padding: 0 !important; margin: 0 0 14px !important; width: 100%; }
        .pv-actions-row { display: flex; gap: 8px; padding: 0 14px 14px; align-items: stretch; }
        .pv-actions-row > * { min-width: 0; height: 46px; box-sizing: border-box; }
        .pv-export-btn { flex: 0 0 auto !important; padding: 0 14px !important; white-space: nowrap; font-size: 13px !important; }
        .pv-import-btn { flex: 0 0 auto !important; padding: 0 14px !important; white-space: nowrap; }
        .pv-sort-wrap { flex: 1 1 0 !important; padding: 0 8px 0 12px !important; overflow: hidden; }
        @media (max-width: 400px) {
            .pv-sort-wrap > i { display: none; }
            .pv-export-btn, .pv-import-btn { padding: 0 11px !important; }
        }
        .pv-sort-select { width: 100%; min-width: 0; padding: 12px 16px 12px 4px !important; text-overflow: ellipsis; white-space: nowrap; overflow: hidden; }
        .top-app-bar-title { font-size: clamp(15px, 4.9vw, 20px) !important; letter-spacing: 0 !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pv-card-top { padding: 16px 16px 8px !important; }
        .pv-type-pill {
            align-self: flex-end; max-width: 100%; text-align: right;
            background: #6b8e23; color: #fff;
            padding: 5px 10px; border-radius: 50px; font-weight: 600; font-size: 10px;
            text-transform: uppercase; display: inline-block; line-height: 1.4;
        }
        .pv-info-rows { display: flex; flex-direction: column; gap: 7px; padding: 0 16px 16px; }
        .pv-info-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-dark); }
        .pv-info-row i { width: 16px; text-align: center; color: #94a3b8; font-size: 12px; flex: none; }
        .pv-info-label { color: var(--text-muted); flex: none; }
        .pv-info-val { font-weight: 650; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 1 1 auto; }
        .pv-info-row .badge-place { margin: 0; }
        .pv-status-pill {
            display: inline-flex; align-items: center; gap: 5px;
            background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7;
            font-size: 10px; padding: 4px 9px; border-radius: 50px; font-weight: 700;
        }
    }

    .list-desktop-view { display: none; }
    .list-mobile-view { display: block; }
    @media (min-width: 992px) {
        .list-mobile-view { display: none !important; }
        .list-desktop-view { display: block !important; }

        /* The shared top app bar (title + profile) is redundant here — the
           hero banner below already shows the "Completed Vessels" title, and
           the desktop sidebar already carries navigation/profile. Mobile
           (where this is the only header, with the hamburger menu) is
           untouched — this rule only fires at >=992px. */
        .top-app-bar { display: none !important; }

        /* Desktop table UI — same "premium" redesign as vessels.php: hero
           banner, pill-style toolbar with custom dropdowns, icon-chip table
           cells. Sidebar and nav are untouched (page-scoped <style>). */
        .vd-wrap { padding: 8px 20px 40px; max-width: 1400px; margin: 0 auto; }

        .vd-hero {
            position: relative; overflow: hidden; border-radius: 20px;
            background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%);
            padding: 15px 26px; margin-bottom: 10px;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;
            box-shadow: 0 10px 28px rgba(30,27,107,.22);
        }
        .vd-hero-icon-bg {
            position: absolute; right: -22px; top: -38px; font-size: 150px; color: rgba(255,255,255,.07);
            pointer-events: none; line-height: 1;
        }
        .vd-hero-title { font-size: 19px; font-weight: 800; color: #fff; letter-spacing: -.01em; position: relative; }
        .vd-hero-sub { font-size: 11.5px; color: rgba(255,255,255,.62); margin-top: 2px; font-weight: 500; position: relative; }
        .vd-hero-stats { display: flex; align-items: center; gap: 10px; position: relative; flex-wrap: wrap; }
        .vd-hero-stat {
            background: rgba(255,255,255,.1); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,.16); border-radius: 14px; padding: 7px 16px; text-align: center; min-width: 68px;
        }
        .vd-hero-stat .n { font-size: 17px; font-weight: 800; color: #fff; line-height: 1.1; }
        .vd-hero-stat .l { font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: rgba(255,255,255,.55); margin-top: 2px; }

        .vd-toolbar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            margin-bottom: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
            padding: 9px 14px; box-shadow: 0 2px 10px rgba(15,23,42,.05);
        }
        .vd-toolbar-inner {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            flex: 1 1 auto; min-width: 0;
        }
        .vd-search {
            display: flex; align-items: center; gap: 7px;
            background: #F8FAFC; border: 1.5px solid #e2e8f0; border-radius: 999px;
            padding: 0 12px; height: 34px; flex: 0 0 150px; min-width: 120px; box-sizing: border-box;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .vd-search:focus-within { border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); }
        .vd-search i { color: #94A3B8; font-size: 12.5px; flex: none; }
        .vd-search input[type=search] { border: none; background: transparent; font-size: 12.5px; color: #0f172a; flex: 1 1 auto; height: 100%; min-width: 0; }
        .vd-search input[type=search]:focus { outline: none; }

        /* Custom-styled dropdown: a pill trigger + floating menu, built by JS
           over the real <select data-desk-*> (kept in the DOM, just hidden)
           so the existing filter/sort logic below — which reads these
           selects' .value and listens for their 'change' event — is
           completely unchanged. */
        .vd-dd { position: relative; flex: 0 0 auto; }
        .vd-dd select { display: none; }
        .vd-dd-trigger {
            display: flex; align-items: center; gap: 5px;
            background: #F8FAFC; border: 1px solid #e2e8f0; border-radius: 999px;
            padding: 0 10px; height: 34px; box-sizing: border-box; max-width: 128px;
            font-size: 12px; font-weight: 600; color: #334155; cursor: pointer; white-space: nowrap;
            transition: border-color .15s ease, background .15s ease;
        }
        .vd-dd-trigger:hover { border-color: #c7d2e0; background: #fff; }
        .vd-dd-trigger.open { border-color: #A78BFA; background: #fff; box-shadow: 0 0 0 3px rgba(91,45,238,.12); }
        .vd-dd-trigger .vd-dd-icon { font-size: 11px; color: #94A3B8; flex: none; }
        .vd-dd-trigger .vd-dd-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1 1 auto; }
        .vd-dd-trigger .vd-dd-chevron { font-size: 8px; color: #94A3B8; flex: none; transition: transform .15s ease; }
        .vd-dd-trigger.open .vd-dd-chevron { transform: rotate(180deg); }
        .vd-dd-menu {
            position: absolute; top: calc(100% + 6px); left: 0; min-width: 170px; max-width: 240px;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
            box-shadow: 0 16px 36px rgba(15,23,42,.14); padding: 6px; z-index: 40; max-height: 260px; overflow-y: auto;
            opacity: 0; visibility: hidden; transform: translateY(-6px) scale(.98); transform-origin: top left;
            transition: opacity .15s ease, transform .15s ease, visibility .15s ease;
        }
        .vd-dd-menu.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .vd-dd-item {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 8px 10px 8px 12px; border-radius: 10px; font-size: 13px; font-weight: 500; color: #334155;
            cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .vd-dd-item:hover { background: #F3EEFE; color: #5B2DEE; }
        .vd-dd-item.selected { background: #F3EEFE; color: #5B2DEE; font-weight: 700; }
        .vd-dd-item.selected::after {
            content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 10px; color: #5B2DEE; flex: none;
        }

        .vd-clear-btn {
            display: inline-flex; align-items: center; gap: 5px; padding: 0 10px; height: 34px; border-radius: 999px;
            font-size: 12px; font-weight: 600; color: #94A3B8; text-decoration: none; border: none;
            background: transparent; cursor: pointer; white-space: nowrap;
        }
        .vd-clear-btn:hover { color: #5B2DEE; }

        .btn-export {
            display: inline-flex; align-items: center; gap: 7px; margin-left: auto; flex: 0 0 auto;
            background: linear-gradient(120deg, #14B8A6, #0D9488); color: #fff;
            padding: 0 16px; border-radius: 999px; font-weight: 700; font-size: 12.5px; height: 34px; box-sizing: border-box;
            text-decoration: none; box-shadow: 0 6px 16px rgba(13,148,136,.3); white-space: nowrap;
            transition: box-shadow .18s ease, transform .18s ease;
        }
        .btn-export:hover { box-shadow: 0 10px 22px rgba(13,148,136,.4); transform: translateY(-1px); color: #fff; }
        .btn-import-completed {
            display: inline-flex; align-items: center; gap: 7px; flex: 0 0 auto; margin-left: 8px;
            background: #fff; color: #4B2FD6; border: 1.5px solid #C4B5FD;
            padding: 0 14px; border-radius: 999px; font-weight: 700; font-size: 12.5px; height: 34px; box-sizing: border-box;
            text-decoration: none; white-space: nowrap;
        }
        .btn-import-completed:hover { background: #F5F3FF; color: #4B2FD6; }
        .vd-export-icon {
            width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,.22);
            display: inline-flex; align-items: center; justify-content: center; font-size: 9px;
        }

        .vd-meta { font-size: 12.5px; color: #64748b; margin-bottom: 6px; font-weight: 500; }
        .vd-table-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
            overflow: hidden; box-shadow: 0 4px 20px rgba(15,23,42,.05);
        }
        .vd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .vd-table thead th {
            background: #F8FAFC; color: #64748b; font-weight: 700; font-size: 10.5px;
            letter-spacing: .06em; text-transform: uppercase;
            padding: 13px 12px; text-align: left; white-space: nowrap; border-bottom: 1px solid #e2e8f0;
        }
        .vd-table tbody td {
            padding: 13px 12px; border-bottom: 1px solid #f1f5f9;
            vertical-align: middle; color: #334155;
        }
        .vd-table tbody tr:hover { background: #FAF8FF; }
        .vd-table tbody tr:last-child td { border-bottom: none; }

        .vd-vessel-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .vd-vessel-icon {
            width: 36px; height: 36px; border-radius: 11px; flex: none;
            background: linear-gradient(135deg, #EEF2FF, #DCE3FF); color: #4F46E5;
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }
        .vd-vessel { font-weight: 700; color: #0f172a; text-decoration: none; font-size: 13.5px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
        .vd-vessel:hover { color: #5B2DEE; }
        .vd-sub { font-size: 11px; color: #94a3b8; margin-top: 1px; font-weight: 500; }

        .vd-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 800;
            padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
            max-width: 100%; box-shadow: 0 0 0 1px rgba(37,99,235,.08);
        }
        .vd-badge .dot { width: 5px; height: 5px; border-radius: 50%; background: #2563EB; flex: none; }

        .vd-port-cell { display: flex; align-items: center; gap: 8px; }
        .vd-port-icon { width: 26px; height: 26px; border-radius: 8px; background: #ECFEFF; color: #0891B2; display: flex; align-items: center; justify-content: center; font-size: 11px; flex: none; }

        .vd-avatar-cell { display: flex; align-items: center; gap: 8px; }
        .vd-avatar {
            width: 24px; height: 24px; border-radius: 50%; color: #fff; font-size: 9.5px; font-weight: 800;
            display: flex; align-items: center; justify-content: center; flex: none;
        }

        .vd-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .vd-actions a {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 8px 14px; border-radius: 999px; font-size: 12px; font-weight: 700;
            text-decoration: none; border: 1.5px solid #e2e8f0; color: #475569; background: #fff;
            transition: all .15s ease;
        }
        .vd-actions a:hover { background: #5B2DEE; color: #fff; border-color: #5B2DEE; box-shadow: 0 6px 16px rgba(91,45,238,.3); }
        .vd-actions a.edit { color: #C2410C; border-color: #FED7AA; }
        .vd-actions a.edit:hover { background: #EA580C; color: #fff; border-color: #EA580C; box-shadow: 0 6px 16px rgba(234,88,12,.3); }

        .vd-pagination {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px; padding: 16px 20px; background: #F8FAFC;
            border-top: 1px solid #e2e8f0;
        }
        .vd-page-info { font-size: 12.5px; color: #64748b; font-weight: 500; }
        .vd-page-links { display: flex; gap: 6px; flex-wrap: wrap; }
        .vd-page-links a, .vd-page-links span {
            min-width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 10px; font-size: 12.5px; font-weight: 700; text-decoration: none;
            border: 1px solid #e2e8f0; background: #fff; color: #475569; padding: 0 8px;
        }
        .vd-page-links a:hover { border-color: #A78BFA; color: #5B2DEE; background: #FAF8FF; }
        .vd-page-links .active { background: linear-gradient(135deg, #8B7CF6, #4B2FD6); color: #fff; border-color: transparent; box-shadow: 0 4px 12px rgba(91,45,238,.35); }
        .vd-page-links .disabled { opacity: .4; pointer-events: none; }
        .vd-empty { text-align: center; padding: 56px 20px; color: #94a3b8; }
    }

.vd-badge { background: #FEE2E2 !important; color: #991B1B !important; box-shadow: 0 0 0 1px rgba(153,27,27,.1) !important; }
.vd-badge .dot { background: #DC2626 !important; }
@media (min-width: 992px) { .vd-hero { background: linear-gradient(120deg, #0f172a 0%, #5B1A1A 48%, #DC2626 100%); box-shadow: 0 10px 28px rgba(127,29,29,.22); } }
.vessel-list-container .vessel-card.cc-card.pvz.pvz-red { border-color: #dc3545 !important; border-width: 2.5px !important; box-shadow: 0 6px 16px rgba(220,53,69,.12) !important; }
.pvz-red .pvz-bar { background: #dc3545; width: 4px; }
.pvz-red .pvz-i > svg { stroke: #dc3545; }
.cn-date { display: flex; align-items: center; gap: 6px; margin: 12px 0 4px; padding: 9px 12px; border-radius: 14px; background: #FEF2F2; color: #7F1D1D; font-size: 12.5px; }
.cn-date b { color: #991B1B; white-space: nowrap; }
.cn-st { margin-left: auto; background: #DC2626; color: #fff; font-size: 10.5px; font-weight: 800; padding: 3px 10px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em; }
.cn-rem { margin: 8px 0 4px; padding: 8px 12px; border-radius: 12px; background: #FEF2F2; color: #7F1D1D; font-size: 12px; line-height: 1.4; }
</style>
<div class="scroll-content" data-list-scope data-testid="cancelled-vessels-page">
    <?php $page_title = 'Cancelled Vessels'; $back_url = ''; $page_testid = 'cancelled-vessels'; include 'includes/top_app_bar.php'; ?>

    <div class="list-mobile-view">
    <div class="rt-mobile-top">
        <?= rtMobileSearch($rtBase, $rt, $rtExport) ?>
        <div class="ls-region" data-live-region="m-top">
        <?= rtMobileBar($rt, $is_full_access) ?>
        <?= $rtChipsHtml ?>
        <?= rtMeta($rt, $rtTotal, $rtOffset, count($rtRows)) ?>
        </div>
    </div>
    <div class="vessel-list-container" data-live-region="m-list">
        <?php if (count($rtRows) > 0): foreach ($rtRows as $rtI => $survey):
            $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
            $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
            $rtD = rtRowDate($survey, $rt['date']);
        ?>
        <?php
            $cvTc = static function ($x) { $x = trim((string)$x); return $x === '' ? '' : ucwords(strtolower($x), " -/(.&'"); };
            $cvName = (string)(($survey['vessel_name'] ?? '') ?: 'Vessel');
            $viK = viKey($cvName); $viS = $viStates[$viK] ?? ['status' => 'unknown', 'img' => VI_DEFAULT];
            $cvShare = implode("\n", array_filter([
                $cvName,
                'Client: ' . (($survey['company_name'] ?? '') ?: '—'),
                'Type of Survey: ' . $typeLabel,
                'Port: ' . ($survey['port_name'] ?? 'N/A'),
                'Agent: ' . (($survey['agent_name'] ?? '') ?: '—'),
                RT_DATE_FIELDS[$rt['date']]['label'] . ': ' . ($rtD !== '' ? date('d M Y', strtotime($rtD)) : '—'),
                'Status: Cancelled',
            ]));
        ?>
        <div class="vessel-card cc-card pvz pvz-red" data-pv-badge="Cancelled" data-pv-theme="red" onclick="location.href='vessel_detail.php?id=<?= (int)$survey['id'] ?>'"
             data-survey-card
             data-name="<?= sanitize(strtolower($survey['vessel_name'] ?? '')) ?>"
             data-type="<?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? '')) ?>"
             data-place="<?= sanitize($survey['port_name'] ?? '') ?>"
             data-client="<?= sanitize($survey['company_name'] ?? '') ?>"
             data-surveyor="<?= sanitize($surveyorNames) ?>"
             data-status="cancelled"
             data-search="<?= sanitize(strtolower(implode(' ', [$survey['vessel_name'] ?? '', $survey['company_name'] ?? '', $survey['type_name'] ?? '', $survey['port_name'] ?? '']))) ?>"
             data-testid="cancelled-card-<?= (int)$survey['id'] ?>">
            <span class="cc-num" data-testid="pv-row-num" hidden><?= $rtOffset + $rtI + 1 ?></span>
            <div class="pvz-head">
                <span class="pvz-photo<?= $viS['status'] === 'found' ? ' real' : '' ?>"><img src="<?= sanitize($viS['img']) ?>" alt="" width="64" height="54" data-vimg="<?= sanitize($viK) ?>" data-vimg-st="<?= sanitize($viS['status']) ?>" onerror="this.onerror=null;this.src='<?= VI_DEFAULT ?>';this.parentNode.classList.remove('real')" data-testid="cancelled-photo-<?= (int)$survey['id'] ?>"></span>
                <span class="pvz-bar" aria-hidden="true"></span>
                <span class="cc-name pvz-name"><?= sanitize($cvName) ?></span>
                <button type="button" class="pvz-share" data-pv-share data-share-text="<?= sanitize($cvShare) ?>" aria-label="Share vessel details" title="Share" data-testid="cancelled-share-<?= (int)$survey['id'] ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V3.5M7.5 8 12 3.5 16.5 8"/><path d="M8 11H6.5A2.5 2.5 0 0 0 4 13.5v5A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5v-5A2.5 2.5 0 0 0 17.5 11H16"/></svg></button>
            </div>
            <div class="pvz-grid">
                <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 17l2 2a1 1 0 0 0 3-3"/><path d="M14 14l2.5 2.5a1 1 0 0 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="M21 3l1 11h-2"/><path d="M3 3L2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/></svg><div class="pvz-t"><span class="pvz-l">Client</span><b><?= sanitize($cvTc($survey['company_name'] ?? '')) ?: '—' ?></b></div></div>
                <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2.5"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M11 10.5h5M11 14h5M11 17.5h5"/><circle cx="8.3" cy="10.5" r=".6"/><circle cx="8.3" cy="14" r=".6"/><circle cx="8.3" cy="17.5" r=".6"/></svg><div class="pvz-t"><span class="pvz-l">Type of Survey</span><b><?= sanitize($typeLabel) ?></b></div></div>
                <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><div class="pvz-t"><span class="pvz-l">Port</span><b><?= sanitize($cvTc($survey['port_name'] ?? '')) ?: 'N/A' ?></b></div></div>
                <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7.5" r="3"/><circle cx="5.5" cy="9.5" r="2.3"/><circle cx="18.5" cy="9.5" r="2.3"/><path d="M7 20v-1.5c0-2.8 2.2-4.8 5-4.8s5 2 5 4.8V20zM2 19.5v-1c0-2 1.4-3.4 3.5-3.4M22 19.5v-1c0-2-1.4-3.4-3.5-3.4"/></svg><div class="pvz-t"><span class="pvz-l">Agent</span><b><?= sanitize($cvTc($survey['agent_name'] ?? '')) ?: '—' ?></b></div></div>
            </div>
            <div class="cn-date" data-testid="cancelled-date-mobile"><i class="fa-regular fa-calendar"></i> <?= sanitize(RT_DATE_FIELDS[$rt['date']]['label']) ?>: <b><?= $rtD !== '' ? sanitize(date('d M Y', strtotime($rtD))) : '—' ?></b><span class="cn-st">Cancelled</span></div>
            <?php if (trim((string)($survey['remarks'] ?? '')) !== ''): ?>
            <div class="cn-rem" data-testid="cancelled-remarks-<?= (int)$survey['id'] ?>"><b>Remarks ·</b> <?= sanitize(mb_strimwidth(trim((string)$survey['remarks']), 0, 220, '…')) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div class="py-3"><?= rtPager($rtBase, $rt, $rtPage, $rtPages) ?></div>
        <?php else: ?>
        <div class="bg-white p-4 text-center text-muted rounded-3 border border-dashed my-3"><i class="fa-solid fa-folder-open d-block mb-2 fs-2"></i><?= rtHasFilters($rt) ? 'No matching records.' : 'No cancelled vessels.' ?></div>
        <?php endif; ?>
    </div>
    </div>


    
    <div class="list-desktop-view vessels-desktop-view">
        <div class="vd-wrap">

            <div class="vd-hero" data-testid="cancelled-hero" data-live-region="d-hero">
                <i class="fa-solid fa-ban vd-hero-icon-bg" aria-hidden="true"></i>
                <div>
                    <div class="vd-hero-title">Cancelled Vessels</div>
                    <div class="vd-hero-sub"><?= (int)$rtTotal ?> survey<?= $rtTotal === 1 ? '' : 's' ?> cancelled</div>
                </div>
                <div class="vd-hero-stats">
                    <div class="vd-hero-stat">
                        <div class="n" data-testid="rt-total"><?= (int)$rtTotal ?></div>
                        <div class="l">Total</div>
                    </div>
                </div>
            </div>

            <div class="vd-toolbar">
                <div class="vd-toolbar-inner">
                    <?= rtDeskSearch($rtBase, $rt) ?>
                    <?php if ($is_full_access): ?>
                    <button type="button" class="vd-dd-trigger rt-tb-dd<?= $rt['surveyor'] ? ' has-value' : '' ?>" data-rt-open="surveyor" data-testid="rt-surveyor-filter">
                        <i class="fa-solid fa-user-tie vd-dd-icon"></i><span class="vd-dd-label"><?= $rt['surveyor'] ? (count($rt['surveyor']) === 1 ? sanitize($rt['surveyor'][0]) : count($rt['surveyor']) . ' surveyors') : 'All surveyors' ?></span><i class="fa-solid fa-chevron-down vd-dd-chevron"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <a href="<?= sanitize($rtExport) ?>" class="btn-export" data-testid="rt-download" data-live-region="d-export"><span class="vd-export-icon"><i class="fa-solid fa-arrow-down"></i></span> Download</a>
            </div>
            <div class="ls-region" data-live-region="d-list">
            <?= $rtChipsHtml ?>
            <?= rtMeta($rt, $rtTotal, $rtOffset, count($rtRows)) ?>
            <div class="vd-table-card">
                <?php if (!$rtRows): ?>
                <div class="rt-empty" data-testid="rt-empty"><i class="fa-solid fa-ship"></i><?= rtHasFilters($rt) ? 'No vessels match these filters.' : 'No cancelled vessels.' ?></div>
                <?php endif; ?>
                <div class="vd-table-wrap" style="overflow-x:auto;">
                    <table class="vd-table rt-table">
                        <thead>
                            <tr>
                                <?= rtHead($rt, 'num', '#') ?>
                                <?= rtHead($rt, 'vessel', 'Vessel') ?>
                                <?= rtHead($rt, 'client', 'Client / Agent') ?>
                                <?= rtHead($rt, 'type', 'Survey Type') ?>
                                <?= rtHead($rt, 'port', 'Port') ?>
                                <?php if ($is_full_access): ?><?= rtHead($rt, 'surveyor', 'Surveyor') ?><?php endif; ?>
                                <?= rtHead($rt, 'date', 'Date', rtDateShort($rt['date'])) ?>
                                <th class="rt-th static">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rtRows)): foreach ($rtRows as $i => $survey):
                                $vessel_name = $survey['vessel_name'] ?: 'Vessel';
                                $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
                                $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
                                $date_src = $survey['report_uploaded_date'] ?? $survey['survey_completed_date'] ?? $survey['assign_date'] ?? '';
                                $date_disp = (!empty($date_src) && $date_src !== '0000-00-00' && $date_src !== '0000-00-00 00:00:00')
                                    ? date('d M Y H:i', strtotime($date_src)) : '—';
                                $ts = strtotime($date_src ?: '1970-01-01') ?: 0;
                            ?>
                            <tr class="vd-row"
                                data-search="<?= sanitize(strtolower(implode(' ', [$vessel_name, $survey['company_name'] ?? '', $survey['agent_name'] ?? '', $typeLabel, $survey['port_name'] ?? '', $surveyorNames, $survey['report_number'] ?? '']))) ?>"
                                data-name="<?= sanitize(strtolower($vessel_name)) ?>"
                                data-type="<?= sanitize(strtolower(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? ''))) ?>"
                                data-place="<?= sanitize(strtolower($survey['port_name'] ?? '')) ?>"
                                data-client="<?= sanitize(strtolower($survey['company_name'] ?? '')) ?>"
                                data-surveyor="<?= sanitize(strtolower($surveyorNames)) ?>"
                                data-testid="cancelled-row-<?= (int)$survey['id'] ?>">
                                <td style="color:#94a3b8;font-weight:600;" data-testid="rt-num"><?= $rtOffset + $i + 1 ?></td>
                                <td>
                                    <div class="vd-vessel-cell">
                                        <div class="vd-vessel-icon"><i class="fa-solid fa-ship"></i></div>
                                        <div style="min-width:0;">
                                            <a class="vd-vessel" href="vessel_detail.php?id=<?= (int)$survey['id'] ?>"><?= sanitize($vessel_name) ?></a>
                                            <?php if (!empty($survey['report_number'])): ?>
                                                <div class="vd-sub">Report: <?= sanitize($survey['report_number']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:600;color:#334155;"><?= sanitize($survey['company_name'] ?? '') ?></div>
                                    <div class="vd-sub">Agent: <?= sanitize($survey['agent_name'] ?? '—') ?></div>
                                </td>
                                <td><span class="vd-badge"><span class="dot"></span><?= sanitize($typeLabel) ?></span></td>
                                <td>
                                    <div class="vd-port-cell">
                                        <div class="vd-port-icon"><i class="fa-solid fa-location-dot"></i></div>
                                        <span><?= sanitize($survey['port_name'] ?? 'N/A') ?></span>
                                    </div>
                                </td>
                                <?php if ($is_full_access): ?>
                                <td><div class="vd-avatar-cell"><span class="vd-avatar" style="background:<?= pvAvatarGradient($surveyorNames) ?>"><?= sanitize(pvInitials($surveyorNames)) ?></span><span><?= sanitize($surveyorNames) ?></span></div></td>
                                <?php endif; ?>
                                <?php $rtD = rtRowDate($survey, $rt['date']); ?>
                                <td style="white-space:nowrap;color:#64748b;font-size:12.5px;"><?= $rtD !== '' ? sanitize(date('d M Y', strtotime($rtD))) : '—' ?></td>
                                <td>
                                    <div class="vd-actions">
                                        <a href="vessel_detail.php?id=<?= (int)$survey['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                                        <?php if ($role === 'Admin'): ?>
                                        <a class="edit" href="vessel_detail.php?id=<?= (int)$survey['id'] ?>" data-testid="cn-edit-desk-<?= (int)$survey['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="rt-foot">
                    <div>25 per page</div>
                    <?= rtPager($rtBase, $rt, $rtPage, $rtPages) ?>
                </div>
            </div>
            </div>
        </div>
    </div>


<div class="ls-region" data-live-region="menus"><?= rtMenus($rtBase, $rt, $rtOpts, $rtYears, $is_full_access) ?></div>
<script src="assets/js/live-search.js?v=<?= @filemtime(__DIR__ . '/assets/js/live-search.js') ?>"></script>
<script src="assets/js/report-table.js?v=<?= @filemtime(__DIR__ . '/assets/js/report-table.js') ?>"></script>

<style>
.rt-tb-dd.has-value { border-color: #A78BFA; background: #F3EEFE; color: #5B2DEE; }
</style>
<script>window.VC_CFG = <?= json_encode(['csrf' => csrf_token(), 'def' => VI_DEFAULT]) ?>;</script>
<script src="assets/js/vessel-cards.js?v=<?= @filemtime(__DIR__ . '/assets/js/vessel-cards.js') ?>"></script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
