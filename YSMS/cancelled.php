<?php
require_once 'config/config.php';
checkAuth();

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

/* Mobile: full list */
if ($role === 'Admin') {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, u.full_name as surveyor_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Cancelled'
        ORDER BY s.id DESC
    ");
    $stmt->execute();
} else {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, u.full_name as surveyor_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Cancelled' AND s.surveyor_id = ?
        ORDER BY s.id DESC
    ");
    $stmt->execute([$user_id]);
}
$surveys = $stmt->fetchAll();

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
    if (!empty($row['surveyor_name'])) $survey_surveyors_filter[$row['surveyor_name']] = $row['surveyor_name'];
}

/* Desktop: pagination */
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$q = trim((string)($_GET['q'] ?? ''));
$filter_type = trim((string)($_GET['type'] ?? ''));
$filter_place = trim((string)($_GET['place'] ?? ''));
$filter_client = trim((string)($_GET['client'] ?? ''));
$filter_surveyor = trim((string)($_GET['surveyor'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'newest'));

$where = ["s.status = 'Cancelled'"];
$params = [];
if ($role !== 'Admin') { $where[] = 's.surveyor_id = ?'; $params[] = $user_id; }
if ($q !== '') {
    $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filter_type !== '') { $where[] = 'st.type_name = ?'; $params[] = $filter_type; }
if ($filter_place !== '') { $where[] = 'p.port_name = ?'; $params[] = $filter_place; }
if ($filter_client !== '') { $where[] = 'c.company_name = ?'; $params[] = $filter_client; }
if ($role === 'Admin' && $filter_surveyor !== '') { $where[] = 'u.full_name = ?'; $params[] = $filter_surveyor; }
$whereSql = implode(' AND ', $where);
$orderSql = 's.id DESC';
if ($sort === 'oldest') $orderSql = 's.id ASC';
elseif ($sort === 'name-asc') $orderSql = 's.vessel_name ASC';
elseif ($sort === 'name-desc') $orderSql = 's.vessel_name DESC';

$countStmt = $db->prepare("
    SELECT COUNT(*) FROM surveys s
    JOIN clients c ON s.client_id = c.id
    LEFT JOIN users u ON s.surveyor_id = u.id
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
    SELECT s.*, c.company_name, u.full_name as surveyor_name, st.type_name, p.port_name
    FROM surveys s
    JOIN clients c ON s.client_id = c.id
    LEFT JOIN users u ON s.surveyor_id = u.id
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

include 'includes/header.php';
?>

<style>

    .list-desktop-view { display: none; }
    .list-mobile-view { display: block; }
    @media (min-width: 992px) {
        .list-mobile-view { display: none !important; }
        .list-desktop-view { display: block !important; }
        .vd-wrap { padding: 8px 20px 40px; max-width: 1400px; margin: 0 auto; }
        .vd-toolbar {
            display: flex; flex-wrap: nowrap; align-items: center; gap: 8px;
            margin-bottom: 14px; overflow-x: auto; padding-bottom: 4px;
        }
        .vd-toolbar form {
            display: flex; flex-wrap: nowrap; align-items: center; gap: 8px;
            flex: 1 1 auto; min-width: 0;
        }
        .vd-toolbar input[type=search],
        .vd-toolbar select {
            border: 1px solid #e2e8f0; border-radius: 10px; padding: 9px 12px;
            font-size: 13px; background: #fff; color: #0f172a; height: 40px; box-sizing: border-box;
        }
        .vd-toolbar input[type=search] { min-width: 180px; width: 220px; flex: 0 0 auto; }
        .vd-toolbar select { max-width: 140px; flex: 0 0 auto; }
        .vd-toolbar .btn-filter,
        .vd-toolbar .btn-clear,
        .vd-toolbar .btn-export {
            height: 40px; box-sizing: border-box; border-radius: 10px; padding: 0 14px;
            font-weight: 650; font-size: 13px; display: inline-flex; align-items: center;
            justify-content: center; gap: 6px; white-space: nowrap; flex: 0 0 auto;
            text-decoration: none; border: none; cursor: pointer; line-height: 1;
        }
        .vd-toolbar .btn-filter { background: #3b32b3; color: #fff; }
        .vd-toolbar .btn-clear { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .vd-toolbar .btn-export { background: #16a34a; color: #fff; margin-left: auto; }
        
        .vd-toolbar-inner {
            display: flex; flex-wrap: nowrap; align-items: center; gap: 8px;
            flex: 1 1 auto; min-width: 0;
        }
        .vd-export-row { margin: 0 0 12px 0; }
        .vd-export-row .btn-export {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            height: 40px; padding: 0 16px; border-radius: 10px; font-weight: 650; font-size: 13px;
            background: #16a34a; color: #fff; text-decoration: none; border: none;
        }
        .vd-meta { font-size: 12px; color: #64748b; margin-bottom: 10px; }
        .vd-table-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
            overflow: hidden; box-shadow: 0 4px 20px rgba(15,23,42,.04);
        }
        .vd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .vd-table thead th {
            background: #0b1e46; color: #fff; font-weight: 650; font-size: 11px;
            letter-spacing: .04em; text-transform: uppercase; padding: 12px 14px; text-align: left; white-space: nowrap;
        }
        .vd-table tbody td {
            padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #334155;
        }
        .vd-table tbody tr:hover { background: #f8fafc; }
        .vd-table tbody tr:last-child td { border-bottom: none; }
        .vd-vessel { font-weight: 700; color: #0f172a; text-decoration: none; }
        .vd-vessel:hover { color: #3b32b3; }
        .vd-sub { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .vd-badge {
            display: inline-block; background: #e0f2fe; color: #0369a1;
            font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 999px; text-transform: uppercase;
        }
        .vd-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .vd-actions a {
            display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 8px;
            font-size: 11px; font-weight: 650; text-decoration: none; border: 1px solid #e2e8f0;
            color: #3b32b3; background: #fff;
        }
        .vd-actions a:hover { background: #3b32b3; color: #fff; border-color: #3b32b3; }
        .vd-pagination {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
            padding: 14px 16px; background: #f8fafc; border-top: 1px solid #e2e8f0;
        }
        .vd-page-info { font-size: 12px; color: #64748b; }
        .vd-page-links { display: flex; gap: 4px; flex-wrap: wrap; }
        .vd-page-links a, .vd-page-links span {
            min-width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; font-size: 12px; font-weight: 650; text-decoration: none;
            border: 1px solid #e2e8f0; background: #fff; color: #334155; padding: 0 8px;
        }
        .vd-page-links a:hover { border-color: #3b32b3; color: #3b32b3; }
        .vd-page-links .active { background: #3b32b3; color: #fff; border-color: #3b32b3; }
        .vd-page-links .disabled { opacity: .45; pointer-events: none; }
    }

.vd-badge { background: #fee2e2 !important; color: #991b1b !important; }
</style>
<div class="scroll-content" data-list-scope data-testid="cancelled-vessels-page">
    <?php $page_title = 'Cancelled Vessels'; $back_url = 'index.php'; $page_testid = 'cancelled-vessels'; include 'includes/top_app_bar.php'; ?>

    <div class="list-mobile-view">
    <div class="search-filter-row">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-control" placeholder="Search by vessel, client or type..." data-list-search>
        </div>
        <button type="button" class="filter-btn" style="background:#fef2f2;color:#b91c1c;" data-filter-toggle aria-expanded="false"><i class="fa-solid fa-sliders"></i></button>
    </div>
    <div class="list-controls-panel" data-controls-panel>
        <select data-list-sort><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="name-asc">Vessel A–Z</option><option value="name-desc">Vessel Z–A</option></select>
        <select data-filter-type><option value="">All survey types</option><?php foreach ($survey_types_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
        <select data-filter-place><option value="">All survey places</option><?php foreach ($survey_places_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
        <select data-filter-client><option value="">All clients</option><?php foreach ($survey_clients_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
        <?php if ($role === 'Admin'): ?>
        <select data-filter-surveyor><option value="">All surveyors</option><?php foreach ($survey_surveyors_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <button type="button" class="clear-filters-btn" data-clear-filters><i class="fa-solid fa-rotate-left"></i> Clear Filters</button>
    </div>
    <div class="px-3 mb-3">
        <a href="export_cancelled.php" class="btn btn-success w-100" style="background-color:#28a745;border:none;font-weight:600;padding:8px;">
            <i class="fa-solid fa-file-excel me-2"></i> Export to Excel
        </a>
    </div>
    <div class="vessel-list-container" data-list-container>
        <?php if (count($surveys) > 0): foreach ($surveys as $survey): ?>
        <div class="vessel-card" style="background:white;border-radius:16px;padding:15px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;border-left:4px solid #ef4444;"
             data-survey-card
             data-name="<?= sanitize(strtolower($survey['vessel_name'] ?? '')) ?>"
             data-type="<?= sanitize($survey['type_name'] ?? '') ?>"
             data-place="<?= sanitize($survey['port_name'] ?? '') ?>"
             data-client="<?= sanitize($survey['company_name'] ?? '') ?>"
             data-surveyor="<?= sanitize($survey['surveyor_name'] ?? '') ?>"
             data-status="cancelled"
             data-date="<?= strtotime($survey['assign_date'] ?? '1970-01-01') ?>"
             data-search="<?= sanitize(strtolower(implode(' ', [$survey['vessel_name'] ?? '', $survey['company_name'] ?? '', $survey['type_name'] ?? '', $survey['port_name'] ?? '']))) ?>">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;border-radius:50%;background:#fee2e2;color:#b91c1c;display:flex;justify-content:center;align-items:center;font-weight:700;">
                    <?= strtoupper(substr($survey['vessel_name'] ?? 'X', 0, 1)) ?>
                </div>
                <div>
                    <h4 style="margin:0;font-size:14px;font-weight:700;"><?= sanitize($survey['vessel_name']) ?></h4>
                    <p style="margin:2px 0 0;font-size:12px;color:#64748b;">Client: <?= sanitize($survey['company_name'] ?? '') ?></p>
                    <p style="margin:2px 0 0;font-size:11px;">Type: <span style="color:#b91c1c;font-weight:600;"><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></p>
                </div>
            </div>
            <div style="text-align:right;">
                <span style="font-size:10px;padding:3px 8px;border-radius:6px;font-weight:600;background:#fee2e2;color:#991b1b;"><i class="fa-solid fa-ban"></i> Cancelled</span>
                <div style="font-size:11.5px;font-weight:700;color:#64748b;margin-top:4px;"><?= sanitize($survey['surveyor_name'] ?? 'N/A') ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="text-secondary px-2 mt-2" style="font-size:12px;">Showing <span data-list-count><?= count($surveys) ?></span> of <?= count($surveys) ?></div>
        <?php else: ?>
        <div class="bg-white p-4 text-center text-muted rounded-3 border border-dashed my-3"><i class="fa-solid fa-ban d-block mb-2 fs-2"></i>No cancelled vessels found.</div>
        <?php endif; ?>
        <div class="list-empty-state bg-white p-4 text-center text-muted rounded-3 border border-dashed my-3" data-list-empty style="display:none;">No matching records.</div>
    </div>
    </div>


    
    <div class="list-desktop-view vessels-desktop-view">
        <div class="vd-wrap">
            <div class="vd-toolbar">
                <div class="vd-toolbar-inner">
                    <input type="search" data-desk-search placeholder="Search vessel, client, agent, type…" autocomplete="off">
                    <select data-desk-sort>
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                        <option value="name-asc">Vessel A–Z</option>
                        <option value="name-desc">Vessel Z–A</option>
                    </select>
                    <select data-desk-type>
                        <option value="">All types</option>
                        <?php foreach ($survey_types_filter as $v): ?>
                            <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select data-desk-place>
                        <option value="">All ports</option>
                        <?php foreach ($survey_places_filter as $v): ?>
                            <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select data-desk-client>
                        <option value="">All clients</option>
                        <?php foreach ($survey_clients_filter as $v): ?>
                            <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($role === 'Admin'): ?>
                    <select data-desk-surveyor>
                        <option value="">All surveyors</option>
                        <?php foreach ($survey_surveyors_filter as $v): ?>
                            <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <button type="button" class="btn-clear" data-desk-clear><i class="fa-solid fa-rotate-left"></i> Clear</button>
                </div>
            </div>
            <div class="vd-export-row">
                <a href="export_cancelled.php" class="btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
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
                                <th>Client / Agent</th>
                                <th>Survey type</th>
                                <th>Port</th>
                                <?php if ($role === 'Admin'): ?><th>Surveyor</th><?php endif; ?>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($surveys)): foreach ($surveys as $i => $survey):
                                $vessel_name = $survey['vessel_name'] ?: 'Vessel';
                                $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
                                $date_src = $survey['report_uploaded_date'] ?? $survey['survey_completed_date'] ?? $survey['assign_date'] ?? '';
                                $date_disp = (!empty($date_src) && $date_src !== '0000-00-00' && $date_src !== '0000-00-00 00:00:00')
                                    ? date('d M Y H:i', strtotime($date_src)) : '—';
                                $ts = strtotime($date_src ?: '1970-01-01') ?: 0;
                            ?>
                            <tr class="vd-row"
                                data-search="<?= sanitize(strtolower(implode(' ', [$vessel_name, $survey['company_name'] ?? '', $survey['agent_name'] ?? '', $typeLabel, $survey['port_name'] ?? '', $survey['surveyor_name'] ?? '', $survey['report_number'] ?? '']))) ?>"
                                data-name="<?= sanitize(strtolower($vessel_name)) ?>"
                                data-type="<?= sanitize(strtolower($survey['type_name'] ?? '')) ?>"
                                data-place="<?= sanitize(strtolower($survey['port_name'] ?? '')) ?>"
                                data-client="<?= sanitize(strtolower($survey['company_name'] ?? '')) ?>"
                                data-surveyor="<?= sanitize(strtolower($survey['surveyor_name'] ?? '')) ?>"
                                data-date="<?= (int)$ts ?>">
                                <td style="color:#94a3b8;font-weight:600;" data-row-num><?= $i + 1 ?></td>
                                <td>
                                    <a class="vd-vessel" href="vessel_detail.php?id=<?= (int)$survey['id'] ?>"><?= sanitize($vessel_name) ?></a>
                                    <?php if (!empty($survey['report_number'])): ?>
                                        <div class="vd-sub">Report: <?= sanitize($survey['report_number']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= sanitize($survey['company_name'] ?? '') ?></div>
                                    <div class="vd-sub">Agent: <?= sanitize($survey['agent_name'] ?? '—') ?></div>
                                </td>
                                <td><span class="vd-badge"><?= sanitize($typeLabel) ?></span></td>
                                <td><i class="fa-solid fa-location-dot" style="opacity:.5;"></i> <?= sanitize($survey['port_name'] ?? 'N/A') ?></td>
                                <?php if ($role === 'Admin'): ?>
                                <td><?= sanitize($survey['surveyor_name'] ?? 'N/A') ?></td>
                                <?php endif; ?>
                                <td style="white-space:nowrap;"><?= sanitize($date_disp) ?></td>
                                <td>
                                    <div class="vd-actions">
                                        <a href="vessel_detail.php?id=<?= (int)$survey['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                                        <?php if ($role === 'Admin' && 'cancelled' === 'vessels'): ?>
                                        <a class="edit" href="vessel_detail.php?id=<?= (int)$survey['id'] ?>&edit=1"><i class="fa-solid fa-pen"></i> Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="vd-pagination">
                    <div class="vd-page-info">10 per page</div>
                    <div class="vd-page-links" data-desk-pager></div>
                </div>
            </div>
        </div>
    </div>


<script>
(function () {
  var PER = 10;
  var root = document.querySelector('.list-desktop-view, .vessels-desktop-view');
  if (!root) return;

  var search = root.querySelector('[data-desk-search]');
  var sortSel = root.querySelector('[data-desk-sort]');
  var typeSel = root.querySelector('[data-desk-type]');
  var placeSel = root.querySelector('[data-desk-place]');
  var clientSel = root.querySelector('[data-desk-client]');
  var surveyorSel = root.querySelector('[data-desk-surveyor]');
  var clearBtn = root.querySelector('[data-desk-clear]');
  var tbody = root.querySelector('.vd-table tbody');
  var meta = root.querySelector('[data-desk-meta]');
  var pager = root.querySelector('[data-desk-pager]');
  var empty = root.querySelector('[data-desk-empty]');
  if (!tbody) return;

  var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr.vd-row'));
  var page = 1;

  function val(sel) { return sel ? (sel.value || '').toLowerCase() : ''; }

  function filtered() {
    var q = (search ? search.value : '').toLowerCase().trim();
    var parts = q ? q.split(/\s+/).filter(Boolean) : [];
    var type = val(typeSel);
    var place = val(placeSel);
    var client = val(clientSel);
    var surveyor = val(surveyorSel);
    var sort = sortSel ? sortSel.value : 'newest';

    var rows = allRows.filter(function (row) {
      var hay = (row.getAttribute('data-search') || '').toLowerCase();
      if (parts.length && !parts.every(function (p) { return hay.indexOf(p) !== -1; })) return false;
      if (type && (row.getAttribute('data-type') || '').toLowerCase() !== type) return false;
      if (place && (row.getAttribute('data-place') || '').toLowerCase() !== place) return false;
      if (client && (row.getAttribute('data-client') || '').toLowerCase() !== client) return false;
      if (surveyor && (row.getAttribute('data-surveyor') || '').toLowerCase() !== surveyor) return false;
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
      var num = r.querySelector('[data-row-num]');
      if (num) num.textContent = String(start + i + 1);
    });

    if (empty) empty.style.display = total === 0 ? 'block' : 'none';
    var tableWrap = root.querySelector('.vd-table-wrap');
    if (tableWrap) tableWrap.style.display = total === 0 ? 'none' : '';

    if (meta) {
      var from = total ? start + 1 : 0;
      var to = Math.min(start + slice.length, total);
      meta.innerHTML = 'Showing <strong>' + from + '</strong>–<strong>' + to + '</strong> of <strong>' + total + '</strong> · Page <strong>' + page + '</strong> / <strong>' + pages + '</strong> · 10 per page';
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

  function onChange() { page = 1; render(); }

  if (search) search.addEventListener('input', onChange);
  [sortSel, typeSel, placeSel, clientSel, surveyorSel].forEach(function (el) {
    if (el) el.addEventListener('change', onChange);
  });
  if (clearBtn) clearBtn.addEventListener('click', function (ev) {
    ev.preventDefault();
    if (search) search.value = '';
    [sortSel, typeSel, placeSel, clientSel, surveyorSel].forEach(function (el) {
      if (!el) return;
      if (el === sortSel) el.value = 'newest';
      else el.value = '';
    });
    page = 1;
    render();
  });

  render();
})();
</script>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
