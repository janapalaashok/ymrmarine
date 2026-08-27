<?php
require_once 'config/config.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();

$stmt = $db->prepare("
    SELECT s.*, c.company_name, u.full_name as surveyor_name, p.port_name, t.type_name
    FROM surveys s 
    JOIN clients c ON s.client_id = c.id 
    JOIN users u ON s.surveyor_id = u.id
    JOIN ports p ON s.port_id = p.id
    JOIN survey_types t ON s.survey_type_id = t.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$survey = $stmt->fetch();

if (!$survey) { die("Report asset not found."); }

// 🌟 మునుపటి స్టేజ్ లో అప్‌లోడ్ చేసిన ఫైల్స్ లిస్ట్ తీసుకోవడం
$uploads_stmt = $db->prepare("SELECT * FROM uploads WHERE survey_id = ?");
$uploads_stmt->execute([$id]);
$uploaded_files = $uploads_stmt->fetchAll();

include 'includes/header.php';
?>

<style>
@media (min-width: 992px) {
    .scroll-content > .info-table-list {
        max-width: none;
        width: calc(100% - 32px);
        margin: 10px 16px 8px !important;
    }
    .detail-bottom-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        max-width: none;
        width: calc(100% - 32px);
        margin: 8px 16px 20px;
        align-items: stretch; /* equal height cards */
    }
    .detail-bottom-row > .detail-files-panel,
    .detail-bottom-row > .form-box {
        margin: 0 !important;
        max-width: none;
        height: 100%;
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 16px 18px;
        box-shadow: 0 2px 8px rgba(15,23,42,.04);
    }
    .detail-bottom-row > .form-box form {
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .detail-actions-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: auto;
        padding-top: 12px;
    }
    .detail-actions-row > a,
    .detail-actions-row > button {
        flex: 1 1 140px;
        width: auto !important;
        max-width: none;
        margin: 0 !important;
    }
    .info-row { padding: 10px 16px; }
}
/* Mobile alignment fixes */
@media (max-width: 991.98px) {
    .scroll-content { padding-bottom: 90px; }
    .info-table-list {
        margin: 8px 12px !important;
        width: calc(100% - 24px) !important;
        max-width: none !important;
        border-radius: 12px;
        overflow: hidden;
    }
    .info-row {
        display: flex;
        flex-direction: column;
        align-items: flex-start !important;
        gap: 4px;
        padding: 12px 14px !important;
    }
    .info-row .info-label {
        font-size: 11px !important;
        width: 100%;
    }
    .info-row .info-value {
        font-size: 13px !important;
        width: 100%;
        text-align: left !important;
        word-break: break-word;
    }
    .detail-bottom-row {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin: 8px 12px 24px !important;
        width: calc(100% - 24px) !important;
        max-width: none !important;
    }
    .detail-bottom-row > .detail-files-panel,
    .detail-bottom-row > .form-box {
        margin: 0 !important;
        width: 100% !important;
        max-width: none !important;
        border-radius: 12px;
        padding: 14px !important;
    }
    .detail-actions-row {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 12px;
    }
    .detail-actions-row > a,
    .detail-actions-row > button {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        text-align: center;
        justify-content: center;
    }
    .form-box .form-control,
    .form-box .form-control-sm {
        font-size: 14px;
    }
}
</style>


<div class="scroll-content">
    <?php $page_title = 'Pending Report Details'; $back_url = 'reports.php'; $page_testid = 'report-detail'; include 'includes/top_app_bar.php'; ?>

    <!-- Read-Only Info Table -->
    <div class="info-table-list shadow-sm" style="margin-bottom: 10px;">
        <div class="info-row"><span class="info-label">Vessel Name</span><span class="info-value"><?= sanitize($survey['vessel_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Report No</span><span class="info-value fw-bold text-dark"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></span></div>
        <div class="info-row"><span class="info-label">Client Name</span><span class="info-value"><?= sanitize($survey['company_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Surveyor Name</span><span class="info-value"><?= sanitize($survey['surveyor_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Survey Type</span><span class="info-value text-primary"><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></div>
        <div class="info-row"><span class="info-label">Survey Completed</span><span class="info-value text-success fw-bold"><?= date('d M Y', strtotime($survey['survey_completed_date'])) ?></span></div>
    </div>

    <div class="detail-bottom-row">
    <!-- 🌟 DOWNLOAD SECTION -->
    <div class="detail-files-panel px-3 my-3">
        <div class="fw-bold text-dark mb-2" style="font-size: 13px;"><i class="fa-solid fa-folder-open text-warning me-1"></i> Download Pre-Uploaded Survey Files</div>
        <?php if(count($uploaded_files) > 0): ?>
            <?php foreach($uploaded_files as $file): ?>
                <?php 
                    $raw_name = $file['file_name'] ?? '';
                    $clean_name = preg_replace('/^[0-9]+_/', '', $raw_name);
                    if ($clean_name === '' || $clean_name === null) $clean_name = $raw_name;
                    $icon = 'fa-file-pdf text-danger';
                    $lower = strtolower($raw_name . ' ' . ($file['file_type'] ?? ''));
                    if (str_contains($lower, 'xlsx') || str_contains($lower, 'xls') || str_contains($lower, 'excel')) $icon = 'fa-file-excel text-success';
                    elseif (str_contains($lower, 'doc') || str_contains($lower, 'word')) $icon = 'fa-file-word text-primary';
                ?>
                <div class="bg-white p-2 rounded-3 border d-flex align-items-center justify-content-between mb-2 shadow-sm" style="font-size: 12px;">
                    <div class="d-flex align-items-center gap-2" style="max-width:80%;">
                        <i class="fa-regular <?= $icon ?> fs-5"></i>
                        <span class="text-dark fw-semibold text-truncate" title="<?= sanitize($clean_name) ?>"><?= sanitize($clean_name) ?></span>
                    </div>
                    <a href="<?= sanitize($file['file_path']) ?>" download="<?= sanitize($clean_name) ?>" class="btn btn-sm btn-light py-1 border text-primary"><i class="fa-solid fa-download"></i></a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-muted small ps-2">No files attached to this vessel yet.</div>
        <?php endif; ?>
    </div>

    <!-- ✍️ UPLOAD FORM SECTION: కనీసం ఒక WORD FORMAT (.docx) అప్‌లోడ్ చేయాలి -->
    <div class="form-box mx-3 p-3 bg-white rounded-3 border shadow-sm">
        <div class="fw-bold text-dark mb-3" style="font-size: 14px;"><i class="fa-solid fa-cloud-arrow-up text-primary"></i> Upload Final Documentation</div>
        
        <form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data" id="reportUploadForm"><?= csrf_field() ?>
            <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
            <input type="hidden" name="current_status" value="<?= $survey['status'] ?>">
            <input type="hidden" name="no_report_confirmed" id="noReportConfirmed" value="0">

            <div class="mb-3">
                <label class="form-label fw-bold text-secondary" style="font-size:12px;">1. Formal Final Report (Word Document / .docx)</label>
                <input type="file" name="word_report" id="wordReportInput" class="form-control form-control-sm" accept=".docx, .doc">
            </div>

            <div id="additionalFilesContainer"></div>

            <button type="button" class="btn btn-light btn-sm text-primary fw-bold mb-3 border w-100 py-2" id="addMoreFilesBtn" style="font-size: 12px;">
                <i class="fa-solid fa-plus-circle"></i> + Add More Files (Optional)
            </button>

            <div class="detail-actions-row">
            <button type="submit" class="btn btn-primary mb-0" id="uploadAndMoveBtn" data-testid="complete-report-submit-button">Complete & Move</button>
            <a href="report_generator.php?id=<?= $survey['id'] ?>"
               class="btn btn-success mb-0"
               style="background-color: #28a745; border: none; font-weight: 600; padding: 10px; border-radius: 8px; color: #fff; text-decoration: none; text-align: center;"
               data-testid="generate-word-report-button">
                <i class="fa-solid fa-file-lines me-2"></i> Generate Word Report
            </a>
            <a href="photo_report_generator.php?id=<?= $survey['id'] ?>"
               class="btn btn-primary mb-0"
               style="border: none; font-weight: 600; padding: 10px; border-radius: 8px; color: #fff; text-decoration: none; text-align: center;"
               data-testid="generate-photo-report-button">
                <i class="fa-regular fa-images me-2"></i> Generate Photo Report
            </a>
            </div>

        </form>
    </div>
    </div><!-- /.detail-bottom-row -->

    <!-- 🌟 Confirmation modal shown when Upload and Move is clicked without a report document -->
    <div class="modal fade" id="noReportConfirmModal" tabindex="-1" aria-hidden="true" data-testid="no-report-confirm-modal">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-triangle-exclamation text-warning fs-2 mb-2"></i>
                    <p class="fw-semibold mb-0" style="font-size: 14px;">Do you really want to continue without report?</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal" data-testid="no-report-confirm-cancel">Cancel</button>
                    <button type="button" class="btn btn-primary flex-fill" id="noReportContinueBtn" data-testid="no-report-confirm-continue">Yes, Continue</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function() {
        let fileIndex = 1;
        $('#addMoreFilesBtn').on('click', function() {
            let html = `
                <div class="mb-3 p-2 border rounded bg-light position-relative">
                    <label class="form-label fw-bold text-muted" style="font-size:11px;">Extra Document #${fileIndex}</label>
                    <input type="file" name="extra_files[]" class="form-control form-control-sm">
                    <button type="button" class="btn btn-sm text-danger position-absolute end-0 top-0 mt-1 remove-file-btn"><i class="fa-solid fa-trash"></i></button>
                </div>`;
            $('#additionalFilesContainer').append(html);
            fileIndex++;
        });

        $(document).on('click', '.remove-file-btn', function() {
            $(this).parent().remove();
        });

        // 🌟 If no Word report file is selected, confirm before continuing the workflow
        $('#reportUploadForm').on('submit', function(e) {
            const hasFile = document.getElementById('wordReportInput').files.length > 0;
            const alreadyConfirmed = $('#noReportConfirmed').val() === '1';
            if (!hasFile && !alreadyConfirmed) {
                e.preventDefault();
                const confirmModal = new bootstrap.Modal(document.getElementById('noReportConfirmModal'));
                confirmModal.show();
            }
        });

        $('#noReportContinueBtn').on('click', function() {
            $('#noReportConfirmed').val('1');
            bootstrap.Modal.getInstance(document.getElementById('noReportConfirmModal')).hide();
            $('#reportUploadForm').trigger('submit');
        });
    });
</script>

<?php 
include 'includes/nav.php';
include 'includes/footer.php';
?>