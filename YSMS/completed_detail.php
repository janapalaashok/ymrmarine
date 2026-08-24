<?php
require_once 'config/config.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();

// 1. వెసెల్ పూర్తి వివరాలు డేటాబేస్ నుండి లోడ్ చేయడం
$stmt = $db->prepare("
    SELECT s.*, c.company_name, p.port_name, t.type_name, uploader.full_name AS uploader_name, surveyor.full_name AS surveyor_name
    FROM surveys s 
    JOIN clients c ON s.client_id = c.id 
    JOIN ports p ON s.port_id = p.id
    JOIN survey_types t ON s.survey_type_id = t.id
    LEFT JOIN users uploader ON s.status_updated_by = uploader.id
    LEFT JOIN users surveyor ON s.surveyor_id = surveyor.id
    WHERE s.id = ? AND s.status = 'Completed'
");
$stmt->execute([$id]);
$survey = $stmt->fetch();

if (!$survey) { 
    die("Completed survey asset parameters not found or mission is uncompleted."); 
}

// 2. ఈ వెసెల్ కి సంబంధించి అప్‌లోడ్ చేసిన అన్ని రకాల ఫైల్స్ (PDF, Excel, Word, Extra) ఒకేసారి లోడ్ చేయడం
$uploads_stmt = $db->prepare("SELECT * FROM uploads WHERE survey_id = ? ORDER BY id ASC");
$uploads_stmt->execute([$id]);
$all_files = $uploads_stmt->fetchAll();


function fmtDateTime($val) {
    if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($val);
    if (!$ts) return '—';
    // Always show date + time (HH:MM)
    return date('d M Y, H:i', $ts);
}

include 'includes/header.php';
?>

<style>
@media (min-width: 992px) {
    .scroll-content .detail-header-card {
        max-width: none;
        width: calc(100% - 32px);
        margin: 10px 16px 8px;
        border-radius: 14px;
        border: 1px solid var(--border-color);
        text-align: left;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 16px;
        padding: 16px 20px;
        background: #fff;
    }
    .scroll-content .detail-header-card .vessel-initial-avatar {
        margin: 0 !important;
    }
    .scroll-content .detail-header-card h3,
    .scroll-content .detail-header-card p { text-align: left; margin: 0 !important; }
    .detail-top-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        max-width: none;
        width: calc(100% - 32px);
        margin: 0 16px 8px;
    }
    .detail-top-grid > .info-table-list {
        margin: 0 !important;
        max-width: none;
    }
    .detail-files-wrap {
        max-width: none;
        width: calc(100% - 32px);
        margin: 8px 16px 8px;
        padding: 0 !important;
    }
    .detail-files-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .scroll-content .action-btn-container {
        max-width: none;
        width: calc(100% - 32px);
        margin: 12px 16px 20px !important;
        padding: 0 !important;
        display: block !important;
        visibility: visible !important;
    }
    .scroll-content .action-btn-container .blue-action-btn {
        width: auto !important;
        max-width: 320px;
        display: inline-flex !important;
        visibility: visible !important;
        padding: 12px 22px;
    }
    .info-row { padding: 10px 16px; }
}
</style>

<div class="scroll-content">
    <?php $page_title = 'Vessel Complete History'; $back_url = 'completed.php'; $page_testid = 'completed-detail'; include 'includes/top_app_bar.php'; ?>

    <!-- Top Summary Card -->
    <div class="detail-header-card">
        <div class="vessel-initial-avatar mx-auto mb-2" style="width: 55px; height: 55px; background:#e0f2fe; color:#0369a1; font-weight:700; display:flex; justify-content:center; align-items:center; border-radius:50%;">
            <?= strtoupper(substr($survey['vessel_name'], 3, 1)) ?>
        </div>
        <h3 class="fw-bold text-dark m-0" style="font-size: 18px;"><?= sanitize($survey['vessel_name']) ?></h3>
        <p class="text-muted m-0 mt-1" style="font-size: 12px;">Client: <span class="text-primary fw-bold"><?= sanitize($survey['company_name']) ?></span></p>
        <?php if (!empty($survey['report_number'])): ?>
        <p class="text-muted m-0 mt-1" style="font-size: 12px;">Report No: <span class="text-dark fw-bold"><?= sanitize($survey['report_number']) ?></span></p>
        <?php endif; ?>
        <div class="mt-2"><span class="badge bg-success px-2 py-1" style="font-size:10px;"><i class="fa-solid fa-circle-check"></i> Survey Fully Completed</span></div>
        <p class="text-muted m-0 mt-2" style="font-size:12px;" data-testid="completed-detail-uploaded-by">Uploaded By: <span class="text-dark fw-bold"><?= sanitize($survey['uploader_name'] ?: $survey['surveyor_name'] ?: 'Admin') ?></span></p>
    </div>

    <div class="detail-top-grid">
    <!-- Timeline Dates -->
    <div class="info-table-list shadow-sm">
        <div class="info-row">
            <span class="info-label"><i class="fa-regular fa-calendar-plus text-primary"></i> Survey Assigned Date</span>
            <span class="info-value"><span class="badge bg-light text-dark border"><?= fmtDateTime($survey['assign_date'] ?? '') ?></span></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="fa-regular fa-calendar-check text-warning"></i> Survey Completed Date</span>
            <span class="info-value"><span class="badge bg-light text-dark border"><?= fmtDateTime($survey['survey_completed_date'] ?? '') ?></span></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="fa-solid fa-calendar-check text-success"></i> Report Completed Date</span>
            <span class="info-value"><span class="badge bg-light text-dark border"><?= fmtDateTime($survey['report_uploaded_date'] ?? '') ?></span></span>
        </div>
    </div>

    <!-- Other records -->
    <div class="info-table-list shadow-sm">
        <div class="info-row"><span class="info-label">Report No</span><span class="info-value fw-bold text-dark"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></span></div>
        <div class="info-row"><span class="info-label">Survey Operation Type</span><span class="info-value text-primary"><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></div>
        <div class="info-row"><span class="info-label">Port of Operation</span><span class="info-value"><?= sanitize($survey['port_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Agent Handle</span><span class="info-value"><?= sanitize($survey['agent_name']) ?></span></div>
    </div>
    </div><!-- /.detail-top-grid -->

    <!-- Files -->
    <div class="px-3 mt-3 detail-files-wrap">
        <div class="fw-bold text-dark mb-2" style="font-size: 14px;">
            <i class="fa-solid fa-cloud-arrow-down text-primary me-1"></i> Dossier Archive (All Uploaded Files)
        </div>
        <div class="detail-files-grid">
        <?php if(count($all_files) > 0): ?>
            <?php foreach($all_files as $file): ?>
                <?php 
                    // ఫైల్ ఎక్స్‌టెన్షన్‌ను బట్టి క్లీన్ ఐకాన్ మరియు కలర్ సెట్ చేయడం
                    $icon = 'fa-file-lines text-secondary';
                    if (str_contains($file['file_type'], 'PDF')) {
                        $icon = 'fa-file-pdf text-danger';
                    } elseif (str_contains($file['file_type'], 'Excel')) {
                        $icon = 'fa-file-excel text-success';
                    } elseif (str_contains($file['file_type'], 'Word')) {
                        $icon = 'fa-file-word text-primary';
                    }
                ?>
                <div class="bg-white p-3 rounded-3 border d-flex align-items-center justify-content-between mb-2 shadow-sm">
                    <div class="d-flex align-items-center gap-3" style="max-width: 80%;">
                        <i class="fa-regular <?= $icon ?> fs-3"></i>
                        <div style="overflow: hidden;">
                            <div class="fw-bold text-dark text-truncate" style="font-size:13px; max-width: 100%;" title="<?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?>">
                                <?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?>
                            </div>
                            <div class="text-muted d-flex gap-2 align-items-center" style="font-size:11px; margin-top:2px;">
                                <span class="badge bg-light text-secondary border py-0.5"><?= sanitize($file['file_type']) ?></span>
                                <span><?= sanitize($file['file_size']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php
                        $clean_name = preg_replace('/^[0-9]+_/', '', $file['file_name']);
                    ?>
                    <a href="<?= $file['file_path'] ?>" download="<?= sanitize($clean_name) ?>" class="btn btn-sm btn-light border py-1.5 text-primary">
                        <i class="fa-solid fa-download"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-white p-3 text-center text-muted rounded-3 border small">
                <i class="fa-solid fa-folder-open d-block mb-1 fs-4 text-secondary"></i> No files archived for this vessel.
            </div>
        <?php endif; ?>
        </div><!-- /.detail-files-grid -->
    </div>

    <!-- Generate Invoice (Admin only) + Generate Expenses (Admin + Surveyor) -->
    <div class="action-btn-container mb-4 px-3" style="display:flex;flex-direction:column;gap:10px;">
        <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
        <a href="invoice_generator.php?id=<?= (int)$survey['id'] ?>" class="blue-action-btn text-decoration-none d-inline-flex align-items-center justify-content-center" data-testid="generate-invoice-button">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Generate Invoice
        </a>
        <?php endif; ?>
        <a href="expense_generator.php?id=<?= (int)$survey['id'] ?>" class="blue-action-btn text-decoration-none d-inline-flex align-items-center justify-content-center" style="background:#0f766e;" data-testid="generate-expenses-button">
            <i class="fa-solid fa-receipt me-1"></i> Generate Expenses
        </a>
    </div>
</div>

<?php 
include 'includes/nav.php';
include 'includes/footer.php';
?>