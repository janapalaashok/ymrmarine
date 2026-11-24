<?php
/**
 * Pending Vessels
 * - Mobile (<992px): original card list + client-side search/filters
 * - Desktop (≥992px): modern table + server-side filters + 10/page pagination
 * Single nav link: vessels.php
 */
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/vessel_dates.php';
checkAuth();

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
// Admin and Super Admin both see every pending vessel (Super Admin is read-only —
// no Edit button — but must not be scoped down to "their own" surveys like a Surveyor).
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
$can_edit = ($role === 'Admin');
$is_client_role = ($role === 'Client');
$client_row_id = 0;
if ($is_client_role) {
    $client_row_id = getClientIdForUser($db, (int)$user_id);
}

vesselDatesEnsure($db); // ETA / ETB / ETC / Expected Supply Date (Latest Update column)
// Safety net: ensure live-status columns exist (export + filters use s.custom_live_status)
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'custom_live_status'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN custom_live_status TEXT NULL DEFAULT NULL");
    }
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'status_updated_by'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN status_updated_by INT(11) NULL DEFAULT NULL");
    }
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'status_updated_at'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN status_updated_at DATETIME NULL DEFAULT NULL");
    }
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'client_submitted'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN client_submitted TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {
    error_log('vessels.php live-status column check: ' . $e->getMessage());
}

/* ── Mobile data: full list (client-side filter in app.js) ── */
if ($is_full_access) {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, u.full_name as surveyor_name, uu.full_name as modifier_name, st.type_name, p.port_name 
        FROM surveys s 
        JOIN clients c ON s.client_id = c.id 
        JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Pending Vessel' 
        ORDER BY " . SURVEY_APPOINTMENT_ORDER_SQL . " DESC, s.id DESC
    ");
    $stmt->execute();
} elseif ($is_client_role) {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, u.full_name as surveyor_name, uu.full_name as modifier_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Pending Vessel' AND s.client_id = ?
        ORDER BY " . SURVEY_APPOINTMENT_ORDER_SQL . " DESC, s.id DESC
    ");
    $stmt->execute([$client_row_id]);
} else {
    // 🌟 A vessel assigned to more than one surveyor (survey_surveyors junction
    // table) must show up for every one of them, not only the primary
    // surveys.surveyor_id — otherwise a secondary assigned surveyor can never
    // see their own assignment in this list.
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, u.full_name as surveyor_name, uu.full_name as modifier_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Pending Vessel' AND (s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))
        ORDER BY " . SURVEY_APPOINTMENT_ORDER_SQL . " DESC, s.id DESC
    ");
    $stmt->execute([$user_id, $user_id]);
}
$surveys = $stmt->fetchAll();
// vessel pictures (AI search, includes/vessel_image.php); the YMR ship until one is found
require_once __DIR__ . '/includes/vessel_image.php';
$viStates = [];
try { $viStates = viStates($db, array_map(fn($r) => (string)($r['vessel_name'] ?? ''), $surveys)); } catch (Throwable $e) { error_log('vessel pictures: ' . $e->getMessage()); }
$viImg = function (string $name) use ($viStates): array {
    $k = viKey($name); $st = $viStates[$k] ?? ['status' => 'unknown', 'img' => VI_DEFAULT];
    return [$k, $st['img'], $st['status']];
};

$survey_types_filter = [];
$survey_places_filter = [];
$survey_clients_filter = [];
$survey_surveyors_filter = [];
foreach ($db->query("SELECT type_name FROM survey_types ORDER BY type_name ASC")->fetchAll() as $st_row) {
    if (!empty($st_row['type_name'])) $survey_types_filter[$st_row['type_name']] = $st_row['type_name'];
}
foreach ($surveys as $row) {
    if (!empty($row['port_name'])) $survey_places_filter[$row['port_name']] = $row['port_name'];
    if (!empty($row['company_name'])) $survey_clients_filter[$row['company_name']] = $row['company_name'];
    $rowSurveyorNames = getCombinedSurveyorNames($db, $row['id'], $row['surveyor_name'] ?? '');
    foreach (listFilterSplitNames($rowSurveyorNames) as $svName) $survey_surveyors_filter[$svName] = $svName;
}

/* ── Desktop data: server-side filter + pagination (10/page) ── */
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$q = trim((string)($_GET['q'] ?? ''));
$filter_type = listFilterFirst($_GET, 'type');
$filter_place = listFilterFirst($_GET, 'place');
$filter_client = listFilterFirst($_GET, 'client');
$filter_status = trim((string)($_GET['status'] ?? ''));
$filter_surveyor = listFilterFirst($_GET, 'surveyor');
$sort = trim((string)($_GET['sort'] ?? 'newest'));

$where = ["s.status = 'Pending Vessel'"];
$params = [];
if ($is_client_role) {
    $where[] = 's.client_id = ?';
    $params[] = $client_row_id;
} elseif (!$is_full_access) {
    // 🌟 Include vessels assigned via survey_surveyors (multi-surveyor), not
    // just the primary surveys.surveyor_id — see mobile query above.
    $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
    $params[] = $user_id;
    $params[] = $user_id;
}
if ($q !== '') {
    $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filter_type !== '') { $where[] = 'st.type_name = ?'; $params[] = $filter_type; }
if ($filter_place !== '') { $where[] = 'p.port_name = ?'; $params[] = $filter_place; }
if ($filter_client !== '') { $where[] = 'c.company_name = ?'; $params[] = $filter_client; }
if ($filter_status === 'updated') {
    $where[] = "(s.custom_live_status IS NOT NULL AND s.custom_live_status <> '')";
} elseif ($filter_status === 'pending') {
    $where[] = "(s.custom_live_status IS NULL OR s.custom_live_status = '')";
}
if ($is_full_access && $filter_surveyor !== '') {
    // Match against the same "Name1 + Name2" combined string the filter
    // dropdown and the list rows show (getCombinedSurveyorNames()), not just
    // the primary surveyor, so filtering by a multi-surveyor vessel works.
    $where[] = "COALESCE((SELECT GROUP_CONCAT(u2.full_name ORDER BY ss2.id SEPARATOR ' + ') FROM survey_surveyors ss2 JOIN users u2 ON ss2.surveyor_id = u2.id WHERE ss2.survey_id = s.id), u.full_name) = ?";
    $params[] = $filter_surveyor;
}
$whereSql = implode(' AND ', $where);
// 🌟 Sort by the actual assignment timestamp (assign_date), latest first by
// default — not by id/insert order, which can drift from assign_date once
// records are edited. id is only a tie-breaker for identical timestamps.
$orderSql = SURVEY_APPOINTMENT_ORDER_SQL . ' DESC, s.id DESC';
if ($sort === 'oldest') $orderSql = SURVEY_APPOINTMENT_ORDER_SQL . ' ASC, s.id ASC';
elseif ($sort === 'name-asc') $orderSql = 's.vessel_name ASC';
elseif ($sort === 'name-desc') $orderSql = 's.vessel_name DESC';

$countStmt = $db->prepare("
    SELECT COUNT(*) FROM surveys s
    JOIN clients c ON s.client_id = c.id
    JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN ports p ON s.port_id = p.id
    WHERE $whereSql
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$deskStmt = $db->prepare("
    SELECT s.*, c.company_name, u.full_name as surveyor_name, uu.full_name as modifier_name, st.type_name, p.port_name
    FROM surveys s
    JOIN clients c ON s.client_id = c.id
    JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN users uu ON s.status_updated_by = uu.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN ports p ON s.port_id = p.id
    WHERE $whereSql
    ORDER BY $orderSql
    LIMIT $perPage OFFSET $offset
");
$deskStmt->execute($params);
$deskSurveys = $deskStmt->fetchAll(PDO::FETCH_ASSOC);

function vd_qs(array $extra = []): string {
    $base = [
        'q' => $_GET['q'] ?? '',
        'type' => $_GET['type'] ?? '',
        'place' => $_GET['place'] ?? '',
        'client' => $_GET['client'] ?? '',
        'surveyor' => $_GET['surveyor'] ?? '',
        'sort' => $_GET['sort'] ?? 'newest',
    ];
    $merged = array_filter(array_merge($base, $extra), fn($v) => $v !== '' && $v !== null);
    return http_build_query($merged);
}

// 🌟 Desktop hero banner stats + surveyor avatar helpers — display only,
// derived from the same $surveys/$is_full_access already computed above.
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

$pv_total = count($surveys);
$pv_no_update = 0;
$pv_needs_surveyor = 0;
foreach ($surveys as $pv_row) {
    if (empty($pv_row['custom_live_status'])) $pv_no_update++;
    if ($is_full_access && (int)($pv_row['client_submitted'] ?? 0) === 1 && (int)($pv_row['surveyor_id'] ?? 0) === 1) $pv_needs_surveyor++;
}

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/report-table.css?v=<?= @filemtime(__DIR__ . '/assets/css/report-table.css') ?>">
<link rel="stylesheet" href="assets/css/mobile-card.css?v=<?= @filemtime(__DIR__ . '/assets/css/mobile-card.css') ?>">

<style>
    /* ═══ MOBILE card styles (unchanged) ═══ */
    .vessel-card {
        border: 2px solid #00a3df;
        background-color: #fff;
        padding: 0;
        border-radius: 12px;
        margin-bottom: 15px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        align-items: stretch;
        overflow: hidden;
    }
    .badge-assigned {
        background: #00a3df;
        color: #fff;
        padding: 5px 10px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 10px;
        text-transform: uppercase;
        display: inline-block;
        max-width: 100%;
        text-align: left;
        line-height: 1.4;
        box-shadow: 0 2px 4px rgba(0, 163, 223, 0.3);
    }
    .vessel-edit-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #fff;
        color: #00a3df;
        border: 1px solid #00a3df;
        border-radius: 8px;
        padding: 5px 12px;
        font-size: 11px;
        font-weight: 700;
        text-decoration: none;
        margin-top: 8px;
    }
    /* 🌟 Client-submitted, still-unassigned reminder — visible chip, not a
       hover-only tooltip, so it works on mobile too. */
    .badge-needs-surveyor {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
        padding: 4px 9px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 10.5px;
        margin: 4px 0;
    }
    .badge-needs-surveyor i { font-size: 10px; }
    .vessel-edit-btn:hover { background: #00a3df; color: #fff; }
    .scroll-content { padding-bottom: 70px; }
    .vessel-card-top-content {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        padding: 15px 15px 10px 15px;
        width: 100%;
        box-sizing: border-box;
    }
    .vessel-avatar-info {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        flex: 1 1 auto;
        min-width: 0;
        text-align: left;
    }
    .vessel-name-title { margin: 0; text-align: left; word-break: break-word; }
    .vessel-client-sub { margin: 2px 0 0 0; text-align: left; }
    .vessel-badge-date { flex: 0 0 auto; text-align: right; max-width: 42%; }
    .vessel-live-status-bar {
        background: #f8fafc;
        border-top: 1px dashed var(--border-color);
        padding: 10px 15px;
        margin-top: 0;
        font-size: 11.5px;
        width: 100%;
        box-sizing: border-box;
        text-align: left;
    }
    @media (max-width: 991px) {
        .vessel-card { flex-direction: column; align-items: stretch !important; padding: 0 !important; overflow: hidden; }
        .vessel-card-top-content { display: flex; justify-content: space-between; align-items: center; padding: 15px 15px 5px 15px; }
    }

    /* ═══ Mobile "Pending Vessels" hero header + modern card redesign ═══
       Page-scoped (this <style> block only loads on vessels.php), so none
       of this leaks into reports.php/completed.php/cancelled.php even
       though they share the same .top-app-bar/.vessel-card/.badge-place/
       etc. class names. Desktop (≥992px) is untouched.

       The header itself reuses the shared includes/top_app_bar.php
       component as-is (hamburger left, title centered, profile right),
       just reskinned blue below — position:fixed (not the shared
       position:sticky) so it's pinned to the viewport unconditionally,
       independent of .mobile-container/.scroll-content's own height/flex
       sizing. .vessels-mobile-view gets a matching padding-top so page
       content isn't hidden underneath it. */
    @media (max-width: 991.98px) {
        /* position:fixed (not sticky) — guarantees the bar stays pinned to
           the viewport regardless of any ancestor flex/height quirks in
           .mobile-container/.scroll-content; .vessels-mobile-view gets a
           matching padding-top below so content isn't hidden under it. */
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
        .vessels-mobile-view { padding-top: 88px; }

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

        /* Vessel card body redesign */
        .vessel-card { display: flex; flex-direction: column; align-items: stretch; border: none; border-radius: 20px; box-shadow: 0 8px 22px rgba(15,23,42,.08); }
        .pv-card-top { display: flex; flex-direction: column; align-items: stretch; gap: 8px; padding: 16px 16px 10px; }
        .pv-card-headline { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .pv-ship-icon {
            width: 40px; height: 40px; border-radius: 12px; flex: none;
            background: #eaf3ff; color: #1d6fe0; font-size: 17px;
            display: flex; align-items: center; justify-content: center;
        }
        .pv-row-num {
            flex: none; width: 22px; height: 22px; border-radius: 50%;
            background: #F1F5F9; color: #64748b; font-size: 11px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
        }
        .pv-vessel-name { font-size: 16.5px; font-weight: 800; color: var(--text-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; flex: 1 1 auto; }
        .pv-type-pill { align-self: flex-end; max-width: 100%; text-align: right; }
        .pv-info-rows { display: flex; flex-direction: column; gap: 7px; padding: 0 16px 14px; }
        .pv-info-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-dark); }
        .pv-info-row i { width: 16px; text-align: center; color: #94a3b8; font-size: 12px; flex: none; }
        .pv-info-label { color: var(--text-muted); flex: none; }
        .pv-info-val { font-weight: 650; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 1 1 auto; }
        .pv-info-row .badge-place { margin: 0; }
        .vessel-live-status-bar {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            background: #fffaf0; border-top: none; padding: 12px 16px;
        }
        .pv-footer-text { flex: 1 1 auto; min-width: 0; }
        .pv-edit-btn { margin: 0; flex: none; }
    }

    /* Hide desktop table on mobile; hide mobile cards on desktop */
    .vessels-desktop-view { display: none; }
    .vessels-mobile-view { display: block; }

    @media (max-width: 991.98px) {
        /* Pending Vessels mobile card: no photo, full vessel name; the old details stay only for Share */
        .pvz .pvz-share-only { display: none !important; }
        .pvz .pvz-name { white-space: normal; overflow: visible; text-overflow: clip; overflow-wrap: anywhere; }
        .pvz-v { display: flex; align-items: center; gap: 10px; min-width: 0; padding: 10px 8px 10px 2px; position: relative; border-bottom: 1.5px solid #e6ebf3; }
        .pvz-v:nth-child(odd):not(.full) { padding-left: 14px; }
        .pvz-v:nth-child(even):not(.full)::after { content: ""; position: absolute; right: 0; top: 14px; bottom: 14px; width: 1.5px; background: #d9dfe8; }
        .pvz-v.full { grid-column: 1 / -1; }
        .pvz-v > svg { flex: none; width: 26px; height: 26px; fill: none; stroke: #1D63ED; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
        .pvz-view .pvz-t b { white-space: normal; overflow-wrap: break-word; }
    }
    @media (min-width: 992px) {
        .vessels-mobile-view { display: none !important; }
        .vessels-desktop-view { display: block !important; }

        /* The shared top app bar (title + profile) is redundant here — the
           hero banner below already shows the "Pending Vessels" title, and
           the desktop sidebar already carries navigation/profile. Mobile
           (where this is the only header, with the hamburger menu) is
           untouched — this rule only fires at >=992px. */
        .top-app-bar { display: none !important; }

        /* Desktop table UI — richer "premium" pass: hero banner with live
           counts, pill-style toolbar, icon-chip/avatar table cells. Sidebar
           and nav are untouched (page-scoped <style>, nothing here touches
           .bottom-nav-bar/.desktop-sidebar-*). */
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
        .vd-hero-stat.warn { background: rgba(245,158,11,.2); border-color: rgba(251,191,36,.32); }
        .vd-hero-stat.warn .n { color: #FDE68A; }
        .vd-hero-stat.warn .l { color: rgba(253,230,138,.75); }

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

        .vd-date-filter {
            display: flex; align-items: center; gap: 7px;
            background: #F8FAFC; border: 1.5px solid #e2e8f0; border-radius: 999px;
            padding: 0 12px; height: 34px; flex: 0 0 auto; box-sizing: border-box;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .vd-date-filter:focus-within { border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); }
        .vd-date-filter i { color: #94A3B8; font-size: 12.5px; flex: none; }
        .vd-date-filter input[type=date] { border: none; background: transparent; font-size: 12.5px; color: #0f172a; height: 100%; min-width: 0; }
        .vd-date-filter input[type=date]:focus { outline: none; }

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
            padding: 13px 16px; text-align: left; white-space: nowrap; border-bottom: 1px solid #e2e8f0;
        }
        .vd-table tbody td {
            padding: 13px 16px; border-bottom: 1px solid #f1f5f9;
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
        .vd-vessel-photo { width: 46px; height: 36px; padding: 0; overflow: hidden; background: #eef3fb; }
        .vd-vessel-photo img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .vd-vessel-photo.real img { object-fit: cover; }
        .vd-vessel { font-weight: 700; color: #0f172a; text-decoration: none; font-size: 13.5px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
        .vd-vessel:hover { color: #5B2DEE; }
        .vd-sub { font-size: 11px; color: #94a3b8; margin-top: 1px; font-weight: 500; }
        .vd-vessel.vd-big { font-size: 16px; font-weight: 800; max-width: 260px; }
        .vd-caps { font-weight: 700; color: #1e293b; font-size: 14px; letter-spacing: .02em; }
        .vd-type-big { display: inline-block; font-weight: 800; color: #1d4ed8; font-size: 14.5px; letter-spacing: .02em; }
        .vd-dates-cell { white-space: nowrap; }
        .vd-date-line { font-size: 13px; color: #334155; line-height: 1.6; }
        .vd-date-line span { font-weight: 600; color: #64748b; }
        .vd-date-line b { font-weight: 800; color: #0f172a; letter-spacing: .02em; }

        .vd-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 800;
            padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
            max-width: 100%; box-shadow: 0 0 0 1px rgba(37,99,235,.08);
        }
        .vd-badge .dot { width: 5px; height: 5px; border-radius: 50%; background: #2563EB; flex: none; }
        .vd-status-ok { font-size: 11.5px; }
        .vd-status-none {
            display: inline-flex; align-items: center; gap: 5px;
            color: #B45309; font-size: 10.5px; font-weight: 600;
        }
        .vd-status-none i { font-size: 10px; }

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
</style>

<div class="scroll-content" data-list-scope data-testid="pending-vessels-page">
    <?php $page_title = 'Pending Vessels'; $back_url = ''; $page_testid = 'pending-vessels'; include 'includes/top_app_bar.php'; ?>

    <!-- ══════════════ MOBILE VIEW (modern card redesign) ══════════════ -->
    <div class="vessels-mobile-view">
        <div class="search-filter-row">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="vesselSearchInput" class="search-control" placeholder="Search by vessel, client, agent or type..." data-list-search data-testid="pending-vessels-search-input">
            </div>
            <button type="button" class="filter-btn" data-filter-toggle aria-expanded="false" data-testid="pending-vessels-filter-button"><i class="fa-solid fa-sliders"></i></button>
        </div>
        <div class="list-controls-panel" data-controls-panel data-testid="pending-vessels-controls-panel">
            <select multiple data-multi data-filter-type data-testid="pending-vessels-type-filter"><option value="">All survey types</option><?php foreach ($survey_types_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
            <select multiple data-multi data-filter-place data-testid="pending-vessels-place-filter"><option value="">All survey places</option><?php foreach ($survey_places_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
            <select multiple data-multi data-filter-client data-testid="pending-vessels-client-filter"><option value="">All clients</option><?php foreach ($survey_clients_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
            <select data-filter-status data-testid="pending-vessels-status-filter"><option value="">All statuses</option><option value="updated">Update Received</option><option value="pending">No Update Yet</option></select>
            <?php if ($is_full_access): ?>
            <select multiple data-multi data-filter-surveyor data-testid="pending-vessels-surveyor-filter"><option value="">All surveyors</option><?php foreach ($survey_surveyors_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
            <?php endif; ?>
            <input type="date" data-filter-appointment title="Appointment Date" data-testid="pending-vessels-appointment-filter">
            <button type="button" class="clear-filters-btn" data-clear-filters data-testid="pending-vessels-clear-filters"><i class="fa-solid fa-rotate-left"></i> Clear Filters</button>
        </div>

        <div class="pv-actions-row">
            <a href="export_vessels.php" class="pv-export-btn" data-mobile-export data-export-base="export_vessels.php" data-testid="pending-vessels-export-link">
                <i class="fa-solid fa-file-excel"></i> Export to Excel
            </a>
            <div class="pv-sort-wrap">
                <i class="fa-solid fa-arrow-down-wide-short"></i>
                <select class="pv-sort-select" data-list-sort data-testid="pending-vessels-sort-select">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="name-asc">Vessel A–Z</option>
                    <option value="name-desc">Vessel Z–A</option>
                </select>
            </div>
        </div>

        <div class="vessel-list-container" id="vesselListContainer" data-list-container data-testid="pending-vessels-list">
            <?php if (!empty($surveys) && count($surveys) > 0): ?>
                <?php foreach ($surveys as $survey): ?>
                    <?php
                        $vessel_name = !empty($survey['vessel_name']) ? $survey['vessel_name'] : 'Vessel';
                        $vessel_status_flag = !empty($survey['custom_live_status']) ? 'updated' : 'pending';
                        $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
                        $needsSurveyor = $is_full_access && (int)($survey['client_submitted'] ?? 0) === 1 && (int)($survey['surveyor_id'] ?? 0) === 1;
                    ?>
                    <div class="vessel-card vessel-card--stacked cc-card pvx pvz" onclick="location.href='vessel_detail.php?id=<?= $survey['id'] ?>'" data-survey-card data-name="<?= sanitize(strtolower($vessel_name)) ?>" data-type="<?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? '')) ?>" data-place="<?= sanitize($survey['port_name'] ?? '') ?>" data-client="<?= sanitize($survey['company_name'] ?? '') ?>" data-surveyor="<?= sanitize($surveyorNames) ?>" data-status="<?= $vessel_status_flag ?>" data-date="<?= surveyAppointmentTs($survey) ?>" data-appointment="<?= sanitize((string)($survey['appointment_date'] ?? '')) ?>" data-search="<?= sanitize(strtolower(implode(' ', [$vessel_name, $survey['company_name'] ?? '', $survey['agent_name'] ?? '', $survey['type_name'] ?? '', $survey['port_name'] ?? '']))) ?>" data-testid="pending-vessel-card-<?= (int)$survey['id'] ?>">
                        <?php
                            $pvType = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', !empty($survey['type_name']) ? $survey['type_name'] : 'N/A');
                            $pvAppt = !empty($survey['appointment_date']) ? date('d M Y', strtotime($survey['appointment_date'])) : '—';
                            $pvShare = implode("\n", array_filter([
                                $vessel_name,
                                'Client: ' . (($survey['company_name'] ?? '') ?: '—'),
                                'Agent: ' . (($survey['agent_name'] ?? '') ?: '—'),
                                'Survey Type: ' . $pvType,
                                $is_full_access ? 'Surveyor: ' . $surveyorNames : null,
                                'Port: ' . ($survey['port_name'] ?? 'N/A'),
                                'Appointment: ' . $pvAppt,
                                'Status: Survey Pending',
                                !empty($survey['custom_live_status']) ? 'Latest update: ' . $survey['custom_live_status'] : null,
                            ]));
                        ?>
                        <?php
                            // Names as written (Title Case) for client / agent / port / surveyor; vessel name stays in capitals.
                            $pvTc = static function ($v) { $v = trim((string)$v); return $v === '' ? '' : ucwords(strtolower($v), " -/(.&'"); };
                        ?>
                        <span class="cc-num" data-row-num data-testid="pv-row-num" hidden></span>
                        <?php
                            // Latest update as bullet points: one per line (or per sentence when it is one line).
                            $pvUpd = trim((string)($survey['custom_live_status'] ?? ''));
                            $pvUpdLines = $pvUpd === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $pvUpd))));
                            if (count($pvUpdLines) === 1) {
                                $pvSent = array_values(array_filter(array_map('trim', preg_split('/(?<=\.)\s+(?=[A-Z0-9])/', $pvUpdLines[0]))));
                                if (count($pvSent) > 1) $pvUpdLines = $pvSent;
                            }
                        ?>
                        <div class="pvz-head">
                            <?php [$viK, $viSrc, $viSt] = $viImg($vessel_name); ?>
                            <?php /* the photo is not shown on the card, only in the picture made for Share */ ?>
                            <span class="pvz-photo pvz-share-only<?= $viSt === 'found' ? ' real' : '' ?>"><img src="<?= sanitize($viSrc) ?>" alt="" width="64" height="54" data-vimg="<?= sanitize($viK) ?>" data-vimg-st="<?= sanitize($viSt) ?>" onerror="this.onerror=null;this.src='<?= VI_DEFAULT ?>';this.parentNode.classList.remove('real')" data-testid="pending-vessel-photo-<?= (int)$survey['id'] ?>"></span>
                            <span class="pvz-bar" aria-hidden="true"></span>
                            <span class="cc-name pvz-name"><?= sanitize($vessel_name) ?></span>
                            <button type="button" class="pvz-share" data-pv-share data-share-text="<?= sanitize($pvShare) ?>" aria-label="Share vessel details" title="Share" data-testid="pending-vessel-share-<?= (int)$survey['id'] ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V3.5M7.5 8 12 3.5 16.5 8"/><path d="M8 11H6.5A2.5 2.5 0 0 0 4 13.5v5A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5v-5A2.5 2.5 0 0 0 17.5 11H16"/></svg></button>
                        </div>
                        <?php
                            // On the card: Port, Survey Type, Client (+ Agent for surveyors), Appointment, then
                            // ETA / ETB / ETC / Expected Supply Date (only those set). The grid after this one is
                            // what the Share picture reads (unchanged) and is not shown.
                            $pvSvg = [
                                'port' => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
                                'type' => '<rect x="5" y="4" width="14" height="17" rx="2.5"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M11 10.5h5M11 14h5M11 17.5h5"/>',
                                'client' => '<path d="M11 17l2 2a1 1 0 0 0 3-3"/><path d="M14 14l2.5 2.5a1 1 0 0 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="M21 3l1 11h-2"/><path d="M3 3L2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
                                'agent' => '<circle cx="12" cy="7.5" r="3"/><circle cx="5.5" cy="9.5" r="2.3"/><circle cx="18.5" cy="9.5" r="2.3"/><path d="M7 20v-1.5c0-2.8 2.2-4.8 5-4.8s5 2 5 4.8V20zM2 19.5v-1c0-2 1.4-3.4 3.5-3.4M22 19.5v-1c0-2-1.4-3.4-3.5-3.4"/>',
                                'date' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
                            ];
                            $pvView = [
                                ['port', 'Port', mb_strtoupper((string)($survey['port_name'] ?? '')) ?: 'N/A', 'pending-vessel-port-' . (int)$survey['id']],
                                ['type', 'Survey Type', mb_strtoupper($pvType), ''],
                                ['client', 'Client', mb_strtoupper((string)($survey['company_name'] ?? '')) ?: '—', ''],
                            ];
                            if (!$is_full_access) $pvView[] = ['agent', 'Agent', $pvTc($survey['agent_name'] ?? '') ?: '—', ''];
                            $pvView[] = ['date', 'Appointment', $pvAppt, ''];
                            foreach (vesselDatesOf($survey) as $k => $d) $pvView[] = ['date', $d['label'], $d['text'], 'pending-vessel-' . $k . '-' . (int)$survey['id']];
                        ?>
                        <div class="pvz-grid pvz-view" data-testid="pending-vessel-details-<?= (int)$survey['id'] ?>">
                            <?php foreach ($pvView as $n => [$ic, $lab, $val, $tid]): // Port on its own row (long names); the rest two per row ?>
                            <div class="pvz-v<?= ($n === 0 || ($n === count($pvView) - 1 && count($pvView) % 2 === 0)) ? ' full' : '' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $pvSvg[$ic] ?></svg><div class="pvz-t"><span class="pvz-l"><?= sanitize($lab) ?></span><b<?= $tid ? ' data-testid="' . $tid . '"' : '' ?>><?= sanitize($val) ?></b></div></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="pvz-grid pvz-share-only<?= $is_full_access ? '' : ' pvz-no-surveyor' ?>" aria-hidden="true">
                            <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 17l2 2a1 1 0 0 0 3-3"/><path d="M14 14l2.5 2.5a1 1 0 0 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="M21 3l1 11h-2"/><path d="M3 3L2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/></svg><div class="pvz-t"><span class="pvz-l">Client</span><b><?= sanitize($pvTc($survey['company_name'] ?? '')) ?: '—' ?></b></div></div>
                            <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2.5"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M11 10.5h5M11 14h5M11 17.5h5"/><circle cx="8.3" cy="10.5" r=".6"/><circle cx="8.3" cy="14" r=".6"/><circle cx="8.3" cy="17.5" r=".6"/></svg><div class="pvz-t"><span class="pvz-l">Type of Survey</span><b><?= sanitize($pvType) ?></b></div></div>
                            <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><div class="pvz-t"><span class="pvz-l">Port</span><b data-testid="pending-vessel-place-<?= (int)$survey['id'] ?>"><?= sanitize($pvTc($survey['port_name'] ?? '')) ?: 'N/A' ?></b></div></div>
                            <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7.5" r="3"/><circle cx="5.5" cy="9.5" r="2.3"/><circle cx="18.5" cy="9.5" r="2.3"/><path d="M7 20v-1.5c0-2.8 2.2-4.8 5-4.8s5 2 5 4.8V20zM2 19.5v-1c0-2 1.4-3.4 3.5-3.4M22 19.5v-1c0-2-1.4-3.4-3.5-3.4"/></svg><div class="pvz-t"><span class="pvz-l">Agent</span><b><?= sanitize($pvTc($survey['agent_name'] ?? '')) ?: '—' ?></b></div></div>
                            <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg><div class="pvz-t"><span class="pvz-l">Appointment</span><b><?= sanitize($pvAppt) ?></b></div></div>
                            <?php if ($is_full_access): ?>
                            <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg><div class="pvz-t"><span class="pvz-l">Surveyor</span><b><?= sanitize($pvTc($surveyorNames)) ?></b></div></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($needsSurveyor): ?>
                        <div class="pvz-note"><span class="badge-needs-surveyor" data-testid="needs-surveyor-badge-<?= (int)$survey['id'] ?>"><i class="fa-solid fa-circle-info"></i> <?= sanitize($survey['company_name'] ?? 'Client') ?> assigned this — assign a surveyor</span></div>
                        <?php endif; ?>
                        <div class="pvz-upd" data-testid="pending-vessel-update-<?= (int)$survey['id'] ?>">
                            <div class="pvz-upd-k"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3.5" width="16" height="17" rx="3"/><path d="M8 8.5h8M8 12h8M8 15.5h8"/></svg><span>Latest Updates</span></div>
                            <span class="pvz-upd-bar" aria-hidden="true"></span>
                            <?php if ($pvUpdLines): ?>
                                <div class="pvz-upd-text" data-testid="pending-vessel-update-text-<?= (int)$survey['id'] ?>"><?= implode('<br>', array_map(fn($l) => '• ' . sanitize($l), $pvUpdLines)) ?></div>
                            <?php else: ?>
                                <div class="pvz-upd-none">No updates recorded yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5 px-3 mx-3 mt-4 bg-white rounded-4 border border-dashed shadow-sm"><h5>No Pending Vessels</h5></div>
            <?php endif; ?>
            <div class="text-center py-5 px-3 bg-white rounded-4 border shadow-sm list-empty-state" data-list-empty data-testid="pending-vessels-no-results" style="display:none;"><h5>No matching vessels found</h5></div>
        </div>
    </div>

    <!-- DESKTOP VIEW -->

    <div class="list-desktop-view vessels-desktop-view">
        <div class="vd-wrap">

            <div class="vd-hero" data-testid="pending-vessels-hero">
                <i class="fa-solid fa-ship vd-hero-icon-bg" aria-hidden="true"></i>
                <div>
                    <div class="vd-hero-title">Pending Vessels</div>
                    <div class="vd-hero-sub"><?= (int)$pv_total ?> vessel<?= $pv_total === 1 ? '' : 's' ?> currently awaiting a completed survey report</div>
                </div>
                <div class="vd-hero-stats">
                    <div class="vd-hero-stat">
                        <div class="n"><?= (int)$pv_total ?></div>
                        <div class="l">Total</div>
                    </div>
                    <div class="vd-hero-stat warn">
                        <div class="n"><?= (int)$pv_no_update ?></div>
                        <div class="l">No Update</div>
                    </div>
                    <?php if ($is_full_access): ?>
                    <div class="vd-hero-stat warn">
                        <div class="n"><?= (int)$pv_needs_surveyor ?></div>
                        <div class="l">Needs Surveyor</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="vd-toolbar">
                <div class="vd-toolbar-inner">
                    <div class="vd-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" data-desk-search placeholder="Search vessel" autocomplete="off">
                    </div>
                    <div class="vd-dd">
                        <i class="fa-solid fa-arrow-down-wide-short vd-dd-icon-src"></i>
                        <select data-desk-sort>
                            <option value="newest">Newest first</option>
                            <option value="oldest">Oldest first</option>
                            <option value="name-asc">Vessel A–Z</option>
                            <option value="name-desc">Vessel Z–A</option>
                        </select>
                    </div>
                    <div class="vd-dd">
                        <i class="fa-solid fa-list-check vd-dd-icon-src"></i>
                        <select multiple data-desk-type>
                            <option value="">All survey types</option>
                            <?php foreach ($survey_types_filter as $v): ?>
                                <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vd-dd">
                        <i class="fa-solid fa-location-dot vd-dd-icon-src"></i>
                        <select multiple data-desk-place>
                            <option value="">All ports</option>
                            <?php foreach ($survey_places_filter as $v): ?>
                                <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vd-dd">
                        <i class="fa-solid fa-building vd-dd-icon-src"></i>
                        <select multiple data-desk-client>
                            <option value="">All clients</option>
                            <?php foreach ($survey_clients_filter as $v): ?>
                                <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($is_full_access): ?>
                    <div class="vd-dd">
                        <i class="fa-solid fa-user-tie vd-dd-icon-src"></i>
                        <select multiple data-desk-surveyor>
                            <option value="">All surveyors</option>
                            <?php foreach ($survey_surveyors_filter as $v): ?>
                                <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="vd-date-filter">
                        <i class="fa-solid fa-calendar-day"></i>
                        <input type="date" data-desk-appointment title="Appointment Date">
                    </div>
                    <button type="button" class="vd-clear-btn" data-desk-clear><i class="fa-solid fa-rotate-left"></i> Clear</button>
                </div>
                <a href="export_vessels.php" class="btn-export" id="vdExportLink" data-desk-export><span class="vd-export-icon"><i class="fa-solid fa-arrow-down"></i></span> Download</a>
            </div>
            <div class="vd-meta" data-desk-meta></div>
            <div class="vd-table-card">
                <div data-desk-empty style="display:none;text-align:center;padding:48px 20px;color:#94a3b8;">
                    <i class="fa-solid fa-ship d-block" style="font-size:32px;margin-bottom:10px;opacity:.5;"></i>
                    No records match your search.
                </div>
                <div class="vd-table-wrap" style="overflow-x:auto;">
                    <table class="vd-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Vessel</th>
                                <th>Port</th>
                                <th>Survey type</th>
                                <th><?= $is_full_access ? 'Client' : 'Client / Agent' ?></th>
                                <th>Latest Update</th>
                                <th>Appointment Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($surveys)): foreach ($surveys as $i => $survey):
                                $vessel_name = $survey['vessel_name'] ?: 'Vessel';
                                $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
                                $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
                                $needsSurveyor = $is_full_access && (int)($survey['client_submitted'] ?? 0) === 1 && (int)($survey['surveyor_id'] ?? 0) === 1;
                                $date_src = $survey['report_uploaded_date'] ?? $survey['survey_completed_date'] ?? $survey['assign_date'] ?? '';
                                $date_disp = (!empty($date_src) && $date_src !== '0000-00-00' && $date_src !== '0000-00-00 00:00:00')
                                    ? date('d M Y H:i', strtotime($date_src)) : '—';
                                $ts = strtotime($date_src ?: '1970-01-01') ?: 0;
                                $appt_src = $survey['appointment_date'] ?? '';
                                $appt_disp = (!empty($appt_src) && strpos((string)$appt_src, '0000') !== 0) ? date('d M Y', strtotime($appt_src)) : '—';
                                $rowDates = vesselDatesOf($survey);
                            ?>
                            <tr class="vd-row"
                                data-search="<?= sanitize(strtolower(implode(' ', [$vessel_name, $survey['company_name'] ?? '', $survey['agent_name'] ?? '', $typeLabel, $survey['port_name'] ?? '', $surveyorNames, $survey['report_number'] ?? '']))) ?>"
                                data-name="<?= sanitize(strtolower($vessel_name)) ?>"
                                data-type="<?= sanitize(strtolower(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? ''))) ?>"
                                data-place="<?= sanitize(strtolower($survey['port_name'] ?? '')) ?>"
                                data-client="<?= sanitize(strtolower($survey['company_name'] ?? '')) ?>"
                                data-surveyor="<?= sanitize(strtolower($surveyorNames)) ?>"
                                data-date="<?= surveyAppointmentTs($survey) ?>"
                                data-appointment="<?= sanitize((string)$appt_src) ?>">
                                <td style="color:#94a3b8;font-weight:600;" data-row-num><?= $i + 1 ?></td>
                                <td>
                                    <div class="vd-vessel-cell">
                                        <div style="min-width:0;">
                                            <a class="vd-vessel vd-big" href="vessel_detail.php?id=<?= (int)$survey['id'] ?>"><?= sanitize($vessel_name) ?></a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="vd-port-cell">
                                        <span class="vd-caps"><?= sanitize(mb_strtoupper((string)($survey['port_name'] ?? 'N/A'))) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="vd-type-big" data-testid="pending-vessel-type-<?= (int)$survey['id'] ?>"><?= sanitize(mb_strtoupper($typeLabel)) ?></span>
                                </td>
                                <td>
                                    <div class="vd-caps"><?= sanitize(mb_strtoupper((string)($survey['company_name'] ?? ''))) ?></div>
                                    <?php if (!$is_full_access): /* the agent is shown to the surveyor only */ ?>
                                    <div class="vd-sub">Agent: <?= sanitize($survey['agent_name'] ?? '—') ?></div>
                                    <?php endif; ?>
                                    <?php if ($needsSurveyor): ?>
                                        <span class="badge-needs-surveyor" data-testid="needs-surveyor-badge-<?= (int)$survey['id'] ?>"><i class="fa-solid fa-circle-info"></i> Assign a surveyor</span>
                                    <?php endif; ?>
                                </td>
                                <td class="vd-dates-cell" data-testid="pending-vessel-dates-<?= (int)$survey['id'] ?>">
                                    <?php if ($rowDates): foreach ($rowDates as $k => $d): ?>
                                        <div class="vd-date-line"><span><?= sanitize($d['label']) ?>:</span> <b><?= sanitize($d['text']) ?></b></div>
                                    <?php endforeach; else: ?>
                                        <span class="vd-sub">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;color:#334155;font-size:13px;font-weight:600;" data-testid="pending-vessel-appointment-<?= (int)$survey['id'] ?>"><?= sanitize($appt_disp) ?></td>
                                <td>
                                    <div class="vd-actions">
                                        <a href="vessel_detail.php?id=<?= (int)$survey['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                                        <?php if ($can_edit): ?>
                                        <a class="edit" href="assign_vessel.php?edit_id=<?= (int)$survey['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="vd-pagination">
                    <div class="vd-page-info">25 per page</div>
                    <div class="vd-page-links" data-desk-pager></div>
                </div>
            </div>
        </div>
    </div>


<script>window.VC_CFG = <?= json_encode(['csrf' => csrf_token(), 'def' => VI_DEFAULT]) ?>;</script>
<script src="assets/js/vessel-cards.js?v=<?= @filemtime(__DIR__ . '/assets/js/vessel-cards.js') ?>"></script>
<script>
(function () {
  var PER = 25;
  var root = document.querySelector('.list-desktop-view, .vessels-desktop-view');
  if (!root) return;

  var search = root.querySelector('[data-desk-search]');
  var sortSel = root.querySelector('[data-desk-sort]');
  var typeSel = root.querySelector('[data-desk-type]');
  var placeSel = root.querySelector('[data-desk-place]');
  var clientSel = root.querySelector('[data-desk-client]');
  var surveyorSel = root.querySelector('[data-desk-surveyor]');
  var appointmentSel = root.querySelector('[data-desk-appointment]');
  var clearBtn = root.querySelector('[data-desk-clear]');
  var exportLink = root.querySelector('[data-desk-export]');
  var tbody = root.querySelector('.vd-table tbody');
  var meta = root.querySelector('[data-desk-meta]');
  var pager = root.querySelector('[data-desk-pager]');
  var empty = root.querySelector('[data-desk-empty]');
  if (!tbody) return;

  var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr.vd-row'));
  var page = 1;

  // 🌟 Modern custom-styled dropdown — a pill trigger + floating menu built
  // by JS over the real <select data-desk-*> (kept in the DOM, just
  // display:none'd), so filtered()/onChange() below still read these
  // selects' .value exactly as before; a custom item click just sets the
  // select's value and dispatches a real 'change' event on it.
  var enhancedDropdowns = [];
  function enhanceDropdown(selectEl) {
    if (!selectEl) return;
    var wrap = selectEl.closest('.vd-dd');
    if (!wrap) return;
    var iconSrc = wrap.querySelector('.vd-dd-icon-src');
    var iconClass = iconSrc ? iconSrc.className.replace('vd-dd-icon-src', '').trim() : '';
    if (iconSrc) iconSrc.remove();

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'vd-dd-trigger';
    trigger.innerHTML = '<i class="' + iconClass + ' vd-dd-icon"></i><span class="vd-dd-label"></span><i class="fa-solid fa-chevron-down vd-dd-chevron"></i>';

    var menu = document.createElement('div');
    menu.className = 'vd-dd-menu';
    Array.prototype.forEach.call(selectEl.options, function (opt) {
      var item = document.createElement('div');
      item.className = 'vd-dd-item';
      item.textContent = opt.textContent;
      item.dataset.value = opt.value;
      item.addEventListener('click', function (ev) {
        if (selectEl.multiple) {
          // Several values: tick / untick and keep the menu open; "All …" clears the choice.
          ev.stopPropagation();
          if (opt.value === '') Array.prototype.forEach.call(selectEl.options, function (o) { o.selected = false; });
          else { opt.selected = !opt.selected; selectEl.options[0].selected = false; }
          selectEl.dispatchEvent(new Event('change', { bubbles: true }));
          syncDropdownLabel(entry);
          return;
        }
        selectEl.value = opt.value;
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        syncDropdownLabel(entry);
        closeAllDropdowns();
      });
      menu.appendChild(item);
    });

    wrap.appendChild(trigger);
    wrap.appendChild(menu);

    trigger.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var wasOpen = menu.classList.contains('open');
      closeAllDropdowns();
      if (!wasOpen) { trigger.classList.add('open'); menu.classList.add('open'); }
    });

    var entry = { select: selectEl, trigger: trigger, menu: menu };
    enhancedDropdowns.push(entry);
    syncDropdownLabel(entry);
  }
  function syncDropdownLabel(entry) {
    if (entry.select.multiple) {
      var picked = Array.prototype.filter.call(entry.select.options, function (o) { return o.selected && o.value !== ''; });
      var allText = entry.select.options[0] ? entry.select.options[0].textContent : '';
      entry.trigger.querySelector('.vd-dd-label').textContent = !picked.length ? allText
        : picked.length === 1 ? picked[0].textContent : picked.length + ' ' + allText.replace(/^All\s+/i, '');
      entry.trigger.classList.toggle('has-value', picked.length > 0);
      Array.prototype.forEach.call(entry.menu.children, function (item, i) {
        item.classList.toggle('selected', item.dataset.value === '' ? !picked.length : entry.select.options[i].selected);
      });
      return;
    }
    var opt = entry.select.options[entry.select.selectedIndex];
    entry.trigger.querySelector('.vd-dd-label').textContent = opt ? opt.textContent : '';
    Array.prototype.forEach.call(entry.menu.children, function (item) {
      item.classList.toggle('selected', item.dataset.value === entry.select.value);
    });
  }
  function closeAllDropdowns() {
    enhancedDropdowns.forEach(function (e) {
      e.trigger.classList.remove('open');
      e.menu.classList.remove('open');
    });
  }
  document.addEventListener('click', closeAllDropdowns);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeAllDropdowns(); });
  [sortSel, typeSel, placeSel, clientSel, surveyorSel].forEach(enhanceDropdown);

  function val(sel) { return sel ? (sel.value || '').toLowerCase() : ''; }
  // Multi-select filters: the ticked values (lower case); a row matches when it has any of them.
  function vals(sel) {
    return sel ? Array.prototype.filter.call(sel.options, function (o) { return o.selected && o.value !== ''; })
      .map(function (o) { return o.value.toLowerCase(); }) : [];
  }
  // "Name1 + Name2" (surveyors, survey types) → matches when any of its names is ticked.
  function anyName(attr, picked) {
    var names = String(attr || '').toLowerCase().split(' + ').map(function (x) { return x.trim(); });
    return picked.some(function (p) { return names.indexOf(p) !== -1; });
  }

  function filtered() {
    var q = (search ? search.value : '').toLowerCase().trim();
    var parts = q ? q.split(/\s+/).filter(Boolean) : [];
    var types = vals(typeSel);
    var place = vals(placeSel);
    var client = vals(clientSel);
    var surveyor = vals(surveyorSel);
    var appointment = appointmentSel ? (appointmentSel.value || '') : '';
    var sort = sortSel ? sortSel.value : 'newest';

    var rows = allRows.filter(function (row) {
      var hay = (row.getAttribute('data-search') || '').toLowerCase();
      if (parts.length && !parts.every(function (p) { return hay.indexOf(p) !== -1; })) return false;
      if (types.length && !anyName(row.getAttribute('data-type'), types)) return false;
      if (place.length && place.indexOf((row.getAttribute('data-place') || '').toLowerCase()) === -1) return false;
      if (client.length && client.indexOf((row.getAttribute('data-client') || '').toLowerCase()) === -1) return false;
      if (surveyor.length && !anyName(row.getAttribute('data-surveyor'), surveyor)) return false;
      if (appointment && (row.getAttribute('data-appointment') || '') !== appointment) return false;
      return true;
    });

    rows.sort(function (a, b) {
      var an = (a.getAttribute('data-name') || '');
      var bn = (b.getAttribute('data-name') || '');
      var ad = parseInt(a.getAttribute('data-date') || '0', 10);
      var bd = parseInt(b.getAttribute('data-date') || '0', 10);
      if (sort === 'name-asc') return an.localeCompare(bn);
      if (sort === 'name-desc') return bn.localeCompare(an);
      if (sort === 'oldest') return ad - bd;
      return bd - ad; // newest
    });
    return rows;
  }

  function render() {
    var rows = filtered();
    var total = rows.length;
    var pages = Math.max(1, Math.ceil(total / PER));
    if (page > pages) page = pages;
    var start = (page - 1) * PER;
    var slice = rows.slice(start, start + PER);

    allRows.forEach(function (r) { r.style.display = 'none'; });
    slice.forEach(function (r, i) {
      r.style.display = '';
      // Move the row into the table in sorted order too — this "#" badge
      // was already being computed against the sorted array, but without
      // actually reordering the <tr> elements the row STAYED at its
      // original (PHP query) position, so the number and the row it sat
      // next to disagreed whenever the client-side sort (e.g. "newest")
      // differed from the initial render order.
      tbody.appendChild(r);
      var num = r.querySelector('[data-row-num]');
      if (num) num.textContent = String(start + i + 1);
    });

    if (empty) empty.style.display = total === 0 ? 'block' : 'none';
    var tableWrap = root.querySelector('.vd-table-wrap');
    if (tableWrap) tableWrap.style.display = total === 0 ? 'none' : '';

    if (meta) {
      var from = total ? start + 1 : 0;
      var to = Math.min(start + slice.length, total);
      meta.innerHTML = 'Showing <strong>' + from + '</strong>–<strong>' + to + '</strong> of <strong>' + total + '</strong> · Page <strong>' + page + '</strong> / <strong>' + pages + '</strong> · 25 per page';
    }

    if (pager) {
      var html = '';
      function btn(label, p, disabled, active) {
        if (disabled) return '<span class="disabled">' + label + '</span>';
        if (active) return '<span class="active">' + label + '</span>';
        return '<a href="#" data-page="' + p + '">' + label + '</a>';
      }
      html += btn('&laquo;', 1, page <= 1);
      html += btn('&lsaquo; Prev', page - 1, page <= 1);
      var s = Math.max(1, page - 2), e = Math.min(pages, page + 2);
      for (var p = s; p <= e; p++) html += btn(String(p), p, false, p === page);
      html += btn('Next &rsaquo;', page + 1, page >= pages);
      html += btn('&raquo;', pages, page >= pages);
      pager.innerHTML = html;
      pager.querySelectorAll('a[data-page]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
          ev.preventDefault();
          page = parseInt(a.getAttribute('data-page'), 10) || 1;
          render();
        });
      });
    }
  }

  // 🌟 Keep the Download/Export link in sync with the currently applied
  // filters — no filters applied → full export_vessels.php (unfiltered,
  // same as before); any filter active → append it as a query param so
  // export_vessels.php narrows the export to match what's on screen.
  function updateExportLink() {
    if (!exportLink) return;
    var params = [];
    var q = search ? search.value.trim() : '';
    if (q) params.push('q=' + encodeURIComponent(q));
    vals(typeSel).forEach(function (v) { params.push('type[]=' + encodeURIComponent(v)); });
    vals(placeSel).forEach(function (v) { params.push('place[]=' + encodeURIComponent(v)); });
    vals(clientSel).forEach(function (v) { params.push('client[]=' + encodeURIComponent(v)); });
    vals(surveyorSel).forEach(function (v) { params.push('surveyor[]=' + encodeURIComponent(v)); });
    var appointment = appointmentSel ? (appointmentSel.value || '') : '';
    if (appointment) params.push('appointment=' + encodeURIComponent(appointment));
    exportLink.href = 'export_vessels.php' + (params.length ? '?' + params.join('&') : '');
  }

  function onChange() { page = 1; render(); updateExportLink(); }

  if (search) search.addEventListener('input', onChange);
  [sortSel, typeSel, placeSel, clientSel, surveyorSel, appointmentSel].forEach(function (el) {
    if (el) el.addEventListener('change', onChange);
  });
  if (clearBtn) clearBtn.addEventListener('click', function (ev) {
    ev.preventDefault();
    if (search) search.value = '';
    [sortSel, typeSel, placeSel, clientSel, surveyorSel, appointmentSel].forEach(function (el) {
      if (!el) return;
      if (el === sortSel) el.value = 'newest';
      else el.value = '';
    });
    enhancedDropdowns.forEach(syncDropdownLabel);
    page = 1;
    render();
    updateExportLink();
  });

  render();
  updateExportLink();
})();
</script>

<?php include 'includes/nav.php'; ?>
<?php include 'includes/footer.php'; ?>
