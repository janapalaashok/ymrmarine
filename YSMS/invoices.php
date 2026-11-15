<?php
/**
 * Invoices (Admin). Lists the `invoices` table (includes/invoice_helpers.php) — its own records,
 * not a view of Pending Reports / Completed: a vessel registered here stays here whatever its
 * survey status becomes, and invoice status is stored only in `invoices`.
 * Actions run through ajax/invoices.php; Generate Invoice opens invoice_pdf.php.
 *
 * Same grid as Invoice Airtable (assets/css/invoice-airtable.css): summary cards, quick
 * filters (All pending / CP Prices / Cost / Other Reason / Completed), Hide fields, Filter,
 * Sort, Export, search, resizable / movable columns saved per user, paging and a record
 * panel. Rows load from ajax/invoices.php?a=list (assets/js/invoices-grid.js).
 */
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/invoice_helpers.php';
checkAuth();
if (!invoiceCanManage()) { header('Location: index.php'); exit; }

$db = getDB();
invoiceEnsureTable($db);
try {
    invoiceBackfill($db); // registers any Pending Report vessel not yet here
} catch (Throwable $e) {
    error_log('invoices backfill: ' . $e->getMessage());
}

// ---------- first state from the URL (links such as invoices.php?view=completed keep working) ----------
[$f] = invoiceListFilters($_GET);
$init = ['view' => in_array($f['view'], ['completed', 'deleted'], true) ? $f['view'] : 'pending', 'status' => $f['status'], 'q' => $f['q'], 'type' => $f['type'], 'month' => $f['month'],
    'client' => $f['client'] ?: '', 'port' => $f['port'] ?: '', 'vessel' => $f['vessel'], 'scd_from' => $f['scd_from'], 'scd_to' => $f['scd_to']];
$types = $db->query("SELECT type_name FROM survey_types WHERE type_name <> '' ORDER BY type_name")->fetchAll(PDO::FETCH_COLUMN);
// filter choices: what the entries show (a manual / edited entry uses its own details)
$clients = $db->query('SELECT DISTINCT c.id, c.company_name FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id JOIN clients c ON c.id = ' . INV_E_CLIENT . ' ORDER BY c.company_name')->fetchAll(PDO::FETCH_ASSOC);
$ports = $db->query('SELECT DISTINCT p.id, p.port_name FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id JOIN ports p ON p.id = ' . INV_E_PORT . ' ORDER BY p.port_name')->fetchAll(PDO::FETCH_ASSOC);
$months = $db->query("SELECT DISTINCT DATE_FORMAT(" . INV_E_SCD . ", '%Y-%m') m FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id
    WHERE YEAR(" . INV_E_SCD . ") > 0 ORDER BY m DESC")->fetchAll(PDO::FETCH_COLUMN);
$prefs = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `invoice_list_prefs` (`user_id` int(11) NOT NULL, `prefs` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q = $db->prepare('SELECT prefs FROM invoice_list_prefs WHERE user_id = ?');
    $q->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $prefs = json_decode((string)$q->fetchColumn(), true) ?: [];
} catch (Throwable $e) { $prefs = []; }
$QUICK = ['' => ['All Pending', 'pending', 'blue'], 'Pending for CP Prices' => ['CP Prices', 'cp', 'orange'], 'Pending for Cost' => ['Cost', 'cost', 'amber'],
    'Pending for Other Reason' => ['Other Reason', 'other', 'grey'], 'Completed' => ['Completed', 'done', 'green']];

$page_title = 'Invoices';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/invoice-airtable.css?v=<?= @filemtime(__DIR__ . '/assets/css/invoice-airtable.css') ?>">
<link rel="stylesheet" href="assets/css/invoice-form.css?v=<?= @filemtime(__DIR__ . '/assets/css/invoice-form.css') ?>">
<style>
/* Invoices grid extras (everything else is the Invoice Airtable stylesheet) */
.at-sum.five { grid-template-columns: repeat(6, minmax(0, 1fr)); }
.at-sum-i.grey::before { background: #64748B; } .at-sum-i.grey .v { color: #475569; }
.at-sum-i[data-quick] { cursor: pointer; transition: box-shadow .15s, border-color .15s; }
.at-sum-i[data-quick]:hover { border-color: #c9bdfb; }
.at-sum-i.on { border-color: #6C4CF1; box-shadow: 0 0 0 2px rgba(108,76,241,.15); }
.at-dot.cp { background: #EA580C; } .at-dot.cost { background: #D97706; } .at-dot.other { background: #64748B; } .at-dot.done { background: #16A34A; } .at-dot.pending { background: #2563EB; }
.at-chip .cnt { font-size: 10.5px; font-weight: 700; opacity: .75; }
.at-pill.cp { background: #ffedd5; color: #c2410c; } .at-pill.cost { background: #fef3c7; color: #a16207; } .at-pill.other { background: #eef0f3; color: #4b5563; } .at-pill.done { background: #dcfce7; color: #166534; }
.at-stsel { appearance: none; -webkit-appearance: none; border: 0; border-radius: 999px; font-size: 11px; font-weight: 700; padding: 2px 22px 2px 10px; line-height: 1.5; cursor: pointer; font-family: inherit; max-width: 100%;
    background-repeat: no-repeat; background-position: right 7px center; background-size: 8px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2364748b' stroke-width='1.6' fill='none'/%3E%3C/svg%3E"); }
.at-stsel.cp { background-color: #ffedd5; color: #c2410c; } .at-stsel.cost { background-color: #fef3c7; color: #a16207; } .at-stsel.other { background-color: #eef0f3; color: #4b5563; } .at-stsel.done { background-color: #dcfce7; color: #166534; }
.at-stsel.changed { box-shadow: 0 0 0 2px rgba(108,76,241,.35); }
.at-upd { border: 0; background: #6C4CF1; color: #fff; border-radius: 6px; height: 22px; padding: 0 8px; font-size: 11px; font-weight: 700; margin-left: 4px; display: inline-flex; align-items: center; gap: 4px; }
.at-upd[hidden] { display: none; }
.at-tag { display: inline-block; background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: .03em; max-width: 100%; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
.at-pend { font-size: 10px; font-weight: 700; color: #B45309; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 4px; padding: 0 5px; margin-left: 5px; }
.at-act a { border: 1px solid var(--at-line); background: #fff; border-radius: 6px; height: 24px; padding: 0 7px; font-size: 11.5px; font-weight: 600; color: #4b5563; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
.at-act a:hover { border-color: var(--at-acc); color: var(--at-acc); }
.at-pill.deleted { background: #fee2e2; color: #b42318; }
.at-act .rst { color: #166534; }
.at-del-note { font-size: 12px; color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 10px; margin-bottom: 10px; }
.at-act .gen { color: #6C4CF1; } .at-act .del { color: #b42318; } .at-act .del:hover { border-color: #b42318; color: #b42318; }
.at-act button { height: 24px !important; min-height: 0 !important; padding: 0 7px !important; margin: 0; box-shadow: none; line-height: 1; }
.at-doc a.rec { color: #0E7490; border-color: #a5f3fc; background: #ecfeff; }
.swal2-popup .inv-form label { display: block; text-align: left; font-size: 12px; font-weight: 700; color: #475569; margin: 10px 0 4px; }
.swal2-popup .inv-form input, .swal2-popup .inv-form textarea { width: 100%; font-size: 13.5px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 10px; }
.swal2-popup .inv-form .two { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.swal2-popup .inv-form small { display: block; text-align: left; color: #64748b; font-size: 11px; margin-top: 4px; }
@media (max-width: 1399.98px) { .at-sum.five { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 991.98px) { .at-sum.five { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<div class="scroll-content at-page" data-testid="invoices-page">
<?php $back_url = ''; $page_testid = 'invoices'; include 'includes/top_app_bar.php'; ?>
<div class="at-wrap">
    <div class="at-head" data-testid="invoices-hero">
        <div class="at-title"><span class="at-logo"><i class="fa-solid fa-file-invoice-dollar"></i></span>
            <div><h1>Invoices</h1><div class="at-sub"><span data-view-label>Pending invoices</span> · <span class="at-view"><i class="fa-solid fa-table-list"></i> Grid view</span></div></div>
        </div>
        <div class="at-head-r">
            <a class="at-link" href="invoices_airtable.php" data-testid="inv-open-airtable"><i class="fa-solid fa-table-cells"></i> Invoice Airtable</a>
            <button type="button" class="ivf-add" data-ivf-add data-testid="inv-add"><i class="fa-solid fa-plus"></i> Add Invoice</button>
        </div>
    </div>

    <div class="at-sum five" data-testid="inv-summary">
        <div class="at-sum-i purple"><div class="l">All Invoices</div><div class="v" data-count="all">—</div><div class="s">pending + completed</div></div>
        <?php foreach ($QUICK as $k => [$l, $cls, $col]): ?>
        <div class="at-sum-i <?= $col ?>" data-quick="<?= sanitize($k) ?>" role="button" tabindex="0" data-testid="inv-card-stat-<?= $cls ?>"><div class="l"><?= sanitize($l) ?></div><div class="v" data-count="<?= $k === '' ? 'pending' : sanitize($k) ?>">—</div><div class="s"><?= $k === '' ? 'every status except Completed' : ($k === 'Completed' ? 'invoices done' : sanitize($k)) ?></div></div>
        <?php endforeach; ?>
    </div>

    <div class="at-shell">
        <div class="at-bar" data-testid="inv-filters">
            <div class="at-bar-l">
                <button type="button" class="at-tb" data-pop="fields" data-testid="at-btn-fields"><i class="fa-regular fa-eye-slash"></i> <span>Hide fields</span><b class="at-n" data-n="fields"></b></button>
                <button type="button" class="at-tb" data-pop="filter" data-testid="at-btn-filter"><i class="fa-solid fa-filter"></i> <span>Filter</span><b class="at-n" data-n="filter"></b></button>
                <button type="button" class="at-tb" data-pop="sort" data-testid="at-btn-sort"><i class="fa-solid fa-arrow-down-wide-short"></i> <span>Sort</span><b class="at-n" data-n="sort"></b></button>
                <button type="button" class="at-tb" data-pop="export" data-testid="at-btn-export"><i class="fa-solid fa-file-export"></i> <span>Export</span></button>
                <button type="button" class="at-tb at-clear" data-act="clear" hidden data-testid="inv-reset"><i class="fa-solid fa-xmark"></i> Clear filters</button>
            </div>
            <label class="at-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search vessel, client, port, report no, invoice no…" aria-label="Search" data-testid="inv-search"></label>
        </div>
        <div class="at-quick" data-testid="inv-quick">
            <?php foreach ($QUICK as $k => [$l, $cls]): ?>
            <button type="button" class="at-chip" data-quick="<?= sanitize($k) ?>" data-testid="inv-stat-<?= $cls ?>"><i class="at-dot <?= $cls ?>"></i><?= sanitize($l) ?> <span class="cnt" data-count="<?= $k === '' ? 'pending' : sanitize($k) ?>"></span></button>
            <?php endforeach; ?>
            <button type="button" class="at-chip" data-quick="__deleted" data-testid="inv-stat-deleted" title="Entries deleted from Invoices (can be restored)"><i class="fa-solid fa-trash-can" style="font-size:10px;color:#b42318"></i> Deleted <span class="cnt" data-count="deleted"></span></button>
            <span class="at-sel-info" data-sel-info hidden></span>
        </div>

        <!-- popovers -->
        <div class="at-pop" data-popbox="fields" hidden data-testid="at-pop-fields"><div class="at-pop-h">Fields <small>drag to reorder</small></div><div class="at-fields" data-fields></div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-fields-all="1">Show all</button><button type="button" class="at-lnk" data-fields-all="0">Hide all</button></div></div>
        <div class="at-pop wide" data-popbox="filter" hidden data-testid="at-pop-filter">
            <div class="at-pop-h">Filter invoices</div>
            <div class="at-fgrid">
                <label data-only-pending>Invoice status<select data-f="status" data-testid="inv-f-status"><option value="">All pending statuses</option><?php foreach (INVOICE_STATUSES as $s): if ($s === 'Completed') continue; ?><option><?= sanitize($s) ?></option><?php endforeach; ?></select></label>
                <label>Survey type<select data-f="type" data-testid="inv-f-type"><option value="">All survey types</option><?php foreach ($types as $t): ?><option><?= sanitize($t) ?></option><?php endforeach; ?></select></label>
                <label>Client<select data-f="client" data-testid="inv-f-client"><option value="">Any</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= sanitize($c['company_name']) ?></option><?php endforeach; ?></select></label>
                <label>Port<select data-f="port" data-testid="inv-f-port"><option value="">Any</option><?php foreach ($ports as $p): ?><option value="<?= (int)$p['id'] ?>"><?= sanitize($p['port_name']) ?></option><?php endforeach; ?></select></label>
                <label>Vessel<input data-f="vessel" placeholder="Vessel name contains…" data-testid="inv-f-vessel"></label>
                <label>Survey completed month<select data-f="month" data-testid="inv-f-month"><option value="">Any month</option><?php foreach ($months as $m): ?><option value="<?= sanitize($m) ?>"><?= date('F Y', strtotime($m . '-01')) ?></option><?php endforeach; ?></select></label>
                <label>Survey completed from<input type="date" data-f="scd_from" data-testid="inv-f-from"></label>
                <label>to<input type="date" data-f="scd_to" data-testid="inv-f-to"></label>
            </div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-act="clear">Clear filters</button></div>
        </div>
        <div class="at-pop wide" data-popbox="sort" hidden data-testid="at-pop-sort"><div class="at-pop-h">Sort by <small>Shift + click a column header adds a level</small></div><div data-sorts></div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-sort-add data-testid="at-sort-add"><i class="fa-solid fa-plus"></i> Add another sort</button></div></div>
        <div class="at-pop" data-popbox="export" hidden data-testid="at-pop-export">
            <div class="at-pop-h">Export to Excel</div>
            <button type="button" class="at-menu" data-export="filtered" data-testid="inv-download"><i class="fa-solid fa-filter"></i> This list <small>(search + filters)</small></button>
            <button type="button" class="at-menu" data-export="selected" data-testid="inv-export-selected"><i class="fa-regular fa-square-check"></i> Selected invoices <small data-sel-count>(0)</small></button>
            <button type="button" class="at-menu" data-export="all" data-testid="inv-export-all"><i class="fa-solid fa-table"></i> All invoices <small>(pending + completed)</small></button>
        </div>

        <div class="at-grid" data-grid data-testid="inv-grid">
            <table class="at-t" data-testid="inv-table"><colgroup data-cols></colgroup><thead data-head></thead><tbody data-body></tbody><tfoot data-foot></tfoot></table>
            <div class="at-empty" data-empty hidden data-testid="inv-empty"><i class="fa-solid fa-file-invoice"></i><div data-empty-text>No invoices match.</div><small>Vessels appear here when their survey documents are submitted (Pending Reports), or add one with <b>Add Invoice</b>.</small></div>
        </div>
        <div class="at-pager" data-testid="inv-pager">
            <div data-count-text class="at-count" data-testid="inv-meta">Loading…</div>
            <div class="at-pg">
                <select data-per aria-label="Rows per page"><option>25</option><option selected>50</option><option>100</option><option>200</option></select>
                <button type="button" data-pg="-1" aria-label="Previous page" data-testid="inv-prev"><i class="fa-solid fa-chevron-left"></i></button>
                <span data-pginfo>1 / 1</span>
                <button type="button" data-pg="1" aria-label="Next page" data-testid="inv-next"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
</div>

<!-- record panel -->
<div class="at-scrim" data-scrim hidden></div>
<aside class="at-panel" data-panel aria-hidden="true" data-testid="inv-panel">
    <div class="at-p-head"><div><div class="at-p-kind" data-p-kind></div><h2 data-p-title>Invoice</h2></div>
        <div class="at-p-hr"><span data-p-status></span><button type="button" class="at-x" data-close aria-label="Close" data-testid="inv-panel-close"><i class="fa-solid fa-xmark"></i></button></div></div>
    <div class="at-p-body" data-p-body></div>
</aside>
</div>


<!-- Add Invoice / Edit Invoice (assets/js/invoice-form.js) -->
<button type="button" class="ivf-add ivf-fab" data-ivf-add aria-label="Add Invoice" data-testid="inv-add-fab"><i class="fa-solid fa-plus"></i> Add Invoice</button>
<div class="ivf-scrim" data-ivf hidden data-testid="ivf">
  <form class="ivf" role="dialog" aria-modal="true" aria-labelledby="ivfTitle" novalidate data-ivf-form>
    <div class="ivf-h"><span class="ic"><i class="fa-solid fa-file-invoice-dollar"></i></span>
        <div><h2 id="ivfTitle" data-ivf-title>Add Invoice</h2><small data-ivf-sub>Manual entry — not linked to Pending Reports</small></div>
        <button type="button" class="x" data-ivf-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ivf-b">
        <div class="ivf-no" data-ivf-nobox>
            <div class="ivf-no-top"><label for="ivfNo">Invoice Number</label><span class="ivf-badge none" data-ivf-nobadge>Not assigned</span>
                <button type="button" class="ivf-lnk" data-ivf-noauto data-testid="ivf-no-auto" hidden><i class="fa-solid fa-rotate"></i> Use next number</button></div>
            <input id="ivfNo" name="invoice_no" autocomplete="off" spellcheck="false" maxlength="60" data-testid="ivf-no">
            <div class="hint" data-ivf-nohint></div>
            <div class="ivf-e" data-e="invoice_no"></div>
        </div>

        <div class="ivf-sec"><div class="ivf-sec-h"><i class="fa-solid fa-receipt"></i> Invoice details</div>
            <div class="ivf-grid">
                <div class="ivf-fl"><label for="ivfDate">Invoice Date <b data-req-date>*</b></label><input type="date" id="ivfDate" name="invoice_date" data-testid="ivf-date"><div class="ivf-e" data-e="invoice_date"></div></div>
                <div class="ivf-fl"><label for="ivfStatus">Invoice Status <b>*</b></label><select id="ivfStatus" name="invoice_status" data-testid="ivf-status">
                    <?php foreach (INVOICE_STATUSES as $s): ?><option><?= sanitize($s) ?></option><?php endforeach; ?></select><div class="ivf-e" data-e="invoice_status"></div></div>
                <div class="ivf-fl"><label for="ivfAmt">Invoice Amount (USD)</label><div class="ivf-money"><span>$</span><input id="ivfAmt" name="invoice_amount" inputmode="decimal" placeholder="0.00" autocomplete="off" data-testid="ivf-amount"></div><div class="ivf-e" data-e="invoice_amount"></div></div>
                <div class="ivf-fl full"><label for="ivfNotes">Notes</label><textarea id="ivfNotes" name="invoice_notes" maxlength="500" rows="2" placeholder="Optional" data-testid="ivf-notes"></textarea><div class="ivf-e" data-e="invoice_notes"></div></div>
            </div></div>

        <div class="ivf-sec"><div class="ivf-sec-h"><i class="fa-solid fa-ship"></i> Vessel &amp; survey</div>
            <div class="ivf-grid">
                <div class="ivf-fl full" data-ivf-linkrow><label for="ivfLinkQ">Link to a survey record <span style="font-weight:500;color:#64748b">(optional — fills the details below)</span></label>
                    <div class="ivf-link"><input type="search" id="ivfLinkQ" placeholder="Search vessel name or report no…" autocomplete="off" data-testid="ivf-link-q">
                        <div class="ivf-res" data-ivf-res hidden></div></div>
                    <div class="ivf-linked" data-ivf-linked hidden><i class="fa-solid fa-link"></i><span data-ivf-linkedtxt></span><button type="button" class="ivf-lnk" data-ivf-unlink data-testid="ivf-unlink">Unlink</button></div>
                    <div class="ivf-small">Only surveys that have no Invoices entry yet are listed.</div><div class="ivf-e" data-e="survey_id"></div></div>
                <div class="ivf-fl"><label for="ivfVessel">Vessel Name <b>*</b></label><input id="ivfVessel" name="vessel_name" maxlength="255" autocomplete="off" data-testid="ivf-vessel"><div class="ivf-e" data-e="vessel_name"></div></div>
                <div class="ivf-fl"><label for="ivfClient">Client <b>*</b></label><select id="ivfClient" name="client_id" data-testid="ivf-client"><option value="">Choose client…</option></select><div class="ivf-e" data-e="client_id"></div></div>
                <div class="ivf-fl"><label for="ivfReport">Report No</label><input id="ivfReport" name="report_number" maxlength="50" autocomplete="off" data-testid="ivf-report"><div class="ivf-e" data-e="report_number"></div></div>
                <div class="ivf-fl"><label for="ivfPort">Port Name</label><input id="ivfPort" list="ivfPorts" placeholder="Type to search ports…" autocomplete="off" data-testid="ivf-port"><datalist id="ivfPorts"></datalist><div class="ivf-e" data-e="port_id"></div></div>
                <div class="ivf-fl"><label for="ivfScd">Survey Completed Date</label><input type="date" id="ivfScd" name="survey_completed_date" data-testid="ivf-scd"><div class="ivf-e" data-e="survey_completed_date"></div></div>
                <div class="ivf-fl full"><label>Survey Type</label><div class="ivf-types" data-ivf-types data-testid="ivf-types"></div><div class="ivf-e" data-e="survey_type_ids"></div></div>
            </div></div>

        <div class="ivf-sec"><div class="ivf-sec-h"><i class="fa-solid fa-paperclip"></i> Invoice document <span style="font-weight:500;text-transform:none;letter-spacing:0;color:#64748b">(optional)</span></div>
            <div class="ivf-fl"><div class="ivf-file-cur" data-ivf-filecur hidden></div><input type="file" id="ivfFile" accept="application/pdf,.pdf" data-testid="ivf-file">
                <div class="ivf-small">PDF only, up to <?= (int)(invoiceMaxBytes() / 1048576) ?> MB. You can also use Generate Invoice later.</div><div class="ivf-e" data-e="file"></div></div></div>
    </div>
    <div class="ivf-f"><button type="button" class="ivf-btn" data-ivf-close data-testid="ivf-cancel">Cancel</button>
        <button type="submit" class="ivf-btn pri" data-ivf-save data-testid="ivf-save"><i class="fa-solid fa-floppy-disk"></i> <span>Save Invoice</span></button></div>
  </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
window.INV_CONFIG = <?= json_encode([
    'api' => 'ajax/invoices.php',
    'export' => 'export_invoices.php',
    'csrf' => csrf_token(),
    'statuses' => INVOICE_STATUSES,
    'maxMb' => (int)(invoiceMaxBytes() / 1048576),
    'prefs' => $prefs ?: new stdClass(),
    'init' => $init,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/js/invoices-grid.js?v=<?= @filemtime(__DIR__ . '/assets/js/invoices-grid.js') ?>"></script>
<script src="assets/js/invoice-form.js?v=<?= @filemtime(__DIR__ . '/assets/js/invoice-form.js') ?>"></script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
