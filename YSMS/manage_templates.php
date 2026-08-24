<?php
require_once 'config/config.php';
checkAuth();

// 🌟 ఈ పేజీ Admin రోల్ కి మాత్రమే — formats_download.php లో వాడిన అదే ప్యాటర్న్
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// 🌟 word_templates టేబుల్ లేకపోతే (మైగ్రేషన్ ఇంకా రన్ చేయకపోతే) ఇక్కడే క్రియేట్ చేయడం
// (database/migration_word_templates.sql లో కూడా ఇదే స్టేట్‌మెంట్ ఉంది — డిప్లాయ్‌మెంట్ సులభతరం కోసం)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `word_templates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `template_name` VARCHAR(150) NOT NULL,
        `survey_type` VARCHAR(100) DEFAULT NULL,
        `file_name` VARCHAR(255) NOT NULL,
        `file_path` VARCHAR(255) NOT NULL,
        `uploaded_by` INT DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
} catch (Exception $e) {}

$template_upload_error = '';
$template_upload_success = false;
$template_action_error = '';
$template_action_success = '';

$target_directory = __DIR__ . '/word_templates';
if (!is_dir($target_directory)) mkdir($target_directory, 0755, true);

// ── Photo Report Generator template (single system file) ─────────
$photo_tpl_dir = __DIR__ . '/photo_report_templates';
if (!is_dir($photo_tpl_dir)) mkdir($photo_tpl_dir, 0755, true);
$photo_tpl_file = $photo_tpl_dir . '/YMR_Photo_Report_Template.docx';
$photo_tpl_meta = $photo_tpl_dir . '/meta.json';
$photo_tpl_upload_error = '';
$photo_tpl_upload_success = '';
$photo_tpl_info = null;
if (is_file($photo_tpl_file)) {
    $metaRaw = @file_get_contents($photo_tpl_meta);
    $meta = $metaRaw ? json_decode($metaRaw, true) : [];
    $photo_tpl_info = [
        'name' => is_array($meta) && !empty($meta['name']) ? $meta['name'] : 'YMR_Photo_Report_Template.docx',
        'size' => filesize($photo_tpl_file),
        'updated_at' => (is_array($meta) && !empty($meta['updated_at'])) ? $meta['updated_at'] : date('Y-m-d H:i:s', filemtime($photo_tpl_file)),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo_report_template'])) {
    if (!isset($_FILES['photo_template_file']) || $_FILES['photo_template_file']['error'] !== UPLOAD_ERR_OK) {
        $photo_tpl_upload_error = 'Please select a Word (.docx) Photo Report template.';
    } else {
        $orig = basename($_FILES['photo_template_file']['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext !== 'docx') {
            $photo_tpl_upload_error = 'Only .docx files are allowed.';
        } elseif (!move_uploaded_file($_FILES['photo_template_file']['tmp_name'], $photo_tpl_file)) {
            $photo_tpl_upload_error = 'Upload failed. Check folder permissions.';
        } else {
            $meta = [
                'name' => $orig,
                'uploaded_by' => (int)($_SESSION['user_id'] ?? 0),
                'uploaded_by_name' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'Admin',
                'updated_at' => date('Y-m-d H:i:s'),
                'size' => filesize($photo_tpl_file),
            ];
            @file_put_contents($photo_tpl_meta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $photo_tpl_upload_success = 'Photo Report template uploaded. Surveyors will use it automatically.';
            $photo_tpl_info = ['name' => $orig, 'size' => $meta['size'], 'updated_at' => $meta['updated_at']];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo_report_template'])) {
    if (is_file($photo_tpl_file)) @unlink($photo_tpl_file);
    if (is_file($photo_tpl_meta)) @unlink($photo_tpl_meta);
    $photo_tpl_info = null;
    $photo_tpl_upload_success = 'Photo Report template removed. Upload a new one before surveyors can generate reports.';
}

// 🌟 టెంప్లేట్ ఫైల్ పాత్ word_templates/ డైరెక్టరీ లోపలే ఉందని నిర్ధారించే హెల్పర్ (path traversal నుండి రక్షణ)
function resolve_template_file_path($relative_path) {
    $allowed_dir = realpath(__DIR__ . '/word_templates');
    $full_path = __DIR__ . '/' . ltrim($relative_path, '/');
    $real_path = realpath($full_path);
    if ($real_path === false || $allowed_dir === false) return false;
    if (strpos($real_path, $allowed_dir . DIRECTORY_SEPARATOR) === 0) return $real_path;
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_template'])) {
    $template_name = trim($_POST['template_name'] ?? '');
    $survey_type = trim($_POST['survey_type'] ?? '');

    if ($template_name === '') {
        $template_upload_error = 'Please enter a template name.';
    } elseif (!isset($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
        $template_upload_error = 'Please select a Word template to upload.';
    } else {
        $original_name = basename($_FILES['template_file']['name']);
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['docx'], true)) {
            $template_upload_error = 'Invalid file. Only Word (.docx) templates are allowed.';
        } else {
            $safe_name = preg_replace('/[^A-Za-z0-9._ -]/', '', $original_name);
            $safe_name = trim($safe_name) ?: ('template.' . $extension);
            $destination = $target_directory . '/' . time() . '_' . $safe_name;
            if (move_uploaded_file($_FILES['template_file']['tmp_name'], $destination)) {
                try {
                    $stmt = $db->prepare("INSERT INTO word_templates (template_name, survey_type, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $template_name,
                        $survey_type !== '' ? $survey_type : null,
                        basename($destination),
                        'word_templates/' . basename($destination),
                        $_SESSION['user_id'] ?? null,
                    ]);
                    $template_upload_success = true;
                } catch (Exception $e) {
                    @unlink($destination);
                    $template_upload_error = 'Database error. Please try again.';
                }
            } else {
                $template_upload_error = 'The template could not be uploaded. Please try again.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_template'])) {
    $template_id = (int)($_POST['template_id'] ?? 0);
    try {
        $stmt = $db->prepare("SELECT file_path FROM word_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $row = $stmt->fetch();
        if ($row) {
            $target = resolve_template_file_path($row['file_path']);
            $del = $db->prepare("DELETE FROM word_templates WHERE id = ?");
            $del->execute([$template_id]);
            if ($target && is_file($target)) @unlink($target);
            $template_action_success = 'The template was deleted successfully.';
        } else {
            $template_action_error = 'That template could not be found.';
        }
    } catch (Exception $e) {
        $template_action_error = 'Database error. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_template'])) {
    $template_id = (int)($_POST['template_id'] ?? 0);
    $new_name = trim($_POST['new_template_name'] ?? '');
    $new_survey_type = trim($_POST['new_survey_type'] ?? '');
    if ($new_name === '') {
        $template_action_error = 'Please enter a template name.';
    } else {
        try {
            $stmt = $db->prepare("UPDATE word_templates SET template_name = ?, survey_type = ? WHERE id = ?");
            $stmt->execute([$new_name, $new_survey_type !== '' ? $new_survey_type : null, $template_id]);
            $template_action_success = 'The template was updated successfully.';
        } catch (Exception $e) {
            $template_action_error = 'Database error. Please try again.';
        }
    }
}

$templates = [];
try {
    $templates = $db->query("SELECT * FROM word_templates ORDER BY template_name ASC")->fetchAll();
} catch (Exception $e) {}

include 'includes/header.php';
?>
<style>
    .templates-page { padding: 28px 20px 110px; }
    .templates-heading { font-size: 24px; font-weight: 750; color: var(--text-dark); margin: 0 0 6px; }
    .templates-subtitle { color: var(--text-muted); font-size: 13px; margin-bottom: 22px; }
    .templates-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; }
    .template-card { background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 18px; box-shadow: 0 4px 12px rgba(15,23,42,.04); }

    /* Desktop table / mobile cards */
    .templates-desktop-table-wrap { display: none; }
    .templates-mobile-cards { display: block; }
    @media (min-width: 992px) {
        .templates-page { padding: 24px 28px 40px; max-width: 1200px; margin: 0 auto; }
        .templates-mobile-cards { display: none !important; }
        .templates-desktop-table-wrap { display: block !important; }
        .templates-modern-table {
            width: 100%; border-collapse: separate; border-spacing: 0;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
            overflow: hidden; box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .templates-modern-table thead th {
            background: #f8fafc; color: #475569; font-size: 11.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .04em; padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap;
        }
        .templates-modern-table tbody td {
            padding: 14px 16px; border-bottom: 1px solid #f1f5f9;
            font-size: 13.5px; color: #0f172a; vertical-align: middle;
        }
        .templates-modern-table tbody tr:last-child td { border-bottom: 0; }
        .templates-modern-table tbody tr:hover { background: #f8fafc; }
        .templates-modern-table .tpl-name {
            font-weight: 650; display: flex; align-items: center; gap: 10px;
        }
        .templates-modern-table .tpl-name i {
            width: 36px; height: 36px; border-radius: 10px; background: #eff6ff; color: #1d4ed8;
            display: inline-flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;
        }
        .templates-modern-table .tpl-type { color: #64748b; font-size: 12.5px; }
        .templates-modern-table .tpl-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .templates-modern-table .template-rename-form { margin-top: 8px; max-width: 480px; }
    }
    .template-icon { width: 48px; height: 48px; border-radius: 13px; background: #eff6ff; color: #1d4ed8; display: flex; align-items: center; justify-content: center; font-size: 22px; flex: 0 0 auto; }
    .template-top { display: flex; align-items: center; gap: 14px; margin-bottom: 10px; }
    .template-name { font-size: 14px; font-weight: 700; color: var(--text-dark); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .template-type { font-size: 11px; color: var(--text-muted); }
    .template-delete-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 8px; background: #fff; color: #dc2626; border: 1px solid #fca5a5; font-size: 11px; font-weight: 650; }
    .template-delete-btn:hover { background: #fef2f2; }
    .template-rename-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 8px; background: #fff; color: #b45309; border: 1px solid #fcd34d; font-size: 11px; font-weight: 650; }
    .template-rename-btn:hover { background: #fffbeb; }
    .template-rename-form { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
    .template-empty { background: #fff; border: 1px dashed var(--border-color); border-radius: 16px; padding: 34px 20px; text-align: center; color: var(--text-muted); grid-column: 1 / -1; }
    .template-upload-panel { margin-bottom: 20px; padding: 16px; background: #fff; border: 1px solid var(--border-color); border-radius: 14px; }
    .template-upload-form { display: flex; flex-direction: column; gap: 10px; }
    .template-upload-form input, .template-upload-form select { font-size: 12px; }
    .template-upload-form button { white-space: nowrap; border: 0; border-radius: 9px; padding: 10px 15px; background: var(--accent-purple); color: #fff; font-size: 12px; font-weight: 650; }
    @media (max-width: 520px) { .templates-page { padding: 22px 16px 100px; } .templates-heading { font-size: 21px; } .templates-grid { grid-template-columns: 1fr; } }
</style>
<div class="scroll-content">
    <?php $page_title = 'Manage Word Templates'; $back_url = 'report_generator.php'; $page_testid = 'manage-templates'; include 'includes/top_app_bar.php'; ?>
    <main class="templates-page" data-testid="manage-templates-page">
        <h2 class="templates-heading" data-testid="manage-templates-heading">Word Report Templates</h2>
        <p class="templates-subtitle" data-testid="manage-templates-subtitle">Upload the predefined Word templates surveyors can pick from in the Report Generator.</p>

        <!-- Photo Report Generator — single system template -->
        <section class="template-upload-panel mb-3" style="border:2px solid #0b1e46;" data-testid="photo-report-template-panel">
            <div class="fw-bold text-dark mb-1" style="font-size:13.5px;">
                <i class="fa-solid fa-images text-primary me-1"></i> Photo Report Generator Template
            </div>
            <p class="text-muted mb-2" style="font-size:12px;">This is the <strong>only</strong> template used by Photo Report Generator. Surveyors cannot upload their own — they load this automatically.</p>
            <?php if ($photo_tpl_upload_error): ?><div class="alert alert-danger py-2" style="font-size:12px;"><?= sanitize($photo_tpl_upload_error) ?></div><?php endif; ?>
            <?php if ($photo_tpl_upload_success): ?><div class="alert alert-success py-2" style="font-size:12px;"><?= sanitize($photo_tpl_upload_success) ?></div><?php endif; ?>
            <?php if ($photo_tpl_info): ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 p-2 rounded" style="background:#ecfdf5;border:1px solid #a7f3d0;">
                    <div style="font-size:12.5px;">
                        <i class="fa-solid fa-circle-check text-success me-1"></i>
                        <strong><?= sanitize($photo_tpl_info['name']) ?></strong>
                        <span class="text-muted"> · <?= number_format($photo_tpl_info['size'] / 1024, 1) ?> KB · <?= sanitize($photo_tpl_info['updated_at']) ?></span>
                    </div>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove Photo Report template? Surveyors will not be able to generate until you upload again.');">
                        <button type="submit" name="delete_photo_report_template" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash me-1"></i>Remove</button>
                    </form>
                </div>
                <div class="text-muted mb-2" style="font-size:11.5px;">Upload a new file below to <strong>replace</strong> the current template.</div>
            <?php else: ?>
                <div class="alert alert-warning py-2 mb-2" style="font-size:12px;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    No Photo Report template uploaded yet. Surveyors will see <strong>“Contact Admin”</strong> until you upload one.
                </div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" class="template-upload-form">
                <input type="file" name="photo_template_file" accept=".docx" required class="form-control">
                <button type="submit" name="upload_photo_report_template"><i class="fa-solid fa-upload me-1"></i> Upload / Replace Photo Report Template</button>
            </form>
        </section>

        <hr class="my-3">
        <h3 class="fw-bold" style="font-size:15px;">Formal Report Templates</h3>
        <p class="templates-subtitle">Used by the formal Report Generator (not Photo Report).</p>

        <section class="template-upload-panel" data-testid="template-upload-panel">
            <div class="fw-bold text-dark mb-2" style="font-size:13px;"><i class="fa-solid fa-cloud-arrow-up text-primary me-1"></i> Upload New Template</div>
            <?php if ($template_upload_error): ?><div class="alert alert-danger py-2" style="font-size:12px;" data-testid="template-upload-error-message"><?= sanitize($template_upload_error) ?></div><?php endif; ?>
            <form method="POST" enctype="multipart/form-data" class="template-upload-form" data-testid="template-upload-form">
                <input type="text" name="template_name" class="form-control" placeholder="Template Name (e.g. Off Hire Bunker Survey)" required data-testid="template-name-input">
                <input type="text" name="survey_type" class="form-control" placeholder="Survey Type (optional)" data-testid="template-survey-type-input">
                <input type="file" name="template_file" accept=".docx" required class="form-control" data-testid="template-upload-file-input">
                <button type="submit" name="upload_template" data-testid="template-upload-submit-button"><i class="fa-solid fa-upload me-1"></i> Upload Template</button>
            </form>
        </section>

        <?php if ($template_action_error): ?><div class="alert alert-danger py-2 mb-3" style="font-size:12px;" data-testid="template-action-error-message"><?= sanitize($template_action_error) ?></div><?php endif; ?>
        <?php if ($template_action_success): ?><div class="alert alert-success py-2 mb-3" style="font-size:12px;" data-testid="template-action-success-message"><?= sanitize($template_action_success) ?></div><?php endif; ?>

        <div class="templates-mobile-cards">
        <div class="templates-grid" data-testid="templates-grid">
            <?php if ($templates): ?>
                <?php $t_index = 0; foreach ($templates as $t): $t_index++; ?>
                    <article class="template-card" data-testid="template-card-<?= $t_index ?>">
                        <div class="template-top">
                            <div class="template-icon"><i class="fa-regular fa-file-word"></i></div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="template-name" title="<?= sanitize($t['template_name']) ?>" data-testid="template-name-<?= $t_index ?>"><?= sanitize($t['template_name']) ?></div>
                                <?php if (!empty($t['survey_type'])): ?><div class="template-type" data-testid="template-type-<?= $t_index ?>"><?= sanitize($t['survey_type']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="template-rename-btn" onclick="document.getElementById('rename-form-<?= $t_index ?>').classList.toggle('d-none')" data-testid="template-rename-button-<?= $t_index ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                            <form method="POST" class="d-inline" data-testid="template-delete-form-<?= $t_index ?>" onsubmit="return confirm('Delete this template? This cannot be undone.');">
                                <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" name="delete_template" class="template-delete-btn" data-testid="template-delete-button-<?= $t_index ?>"><i class="fa-solid fa-trash"></i> Delete</button>
                            </form>
                        </div>
                        <form method="POST" id="rename-form-<?= $t_index ?>" class="template-rename-form d-none" data-testid="template-rename-form-<?= $t_index ?>">
                            <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                            <input type="text" name="new_template_name" class="form-control" value="<?= sanitize($t['template_name']) ?>" required data-testid="template-rename-name-input-<?= $t_index ?>">
                            <input type="text" name="new_survey_type" class="form-control" value="<?= sanitize($t['survey_type'] ?? '') ?>" placeholder="Survey Type (optional)" data-testid="template-rename-type-input-<?= $t_index ?>">
                            <button type="submit" name="rename_template" data-testid="template-rename-submit-button-<?= $t_index ?>"><i class="fa-solid fa-check me-1"></i> Save</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="template-empty" data-testid="templates-empty-message"><i class="fa-regular fa-folder-open fs-3 d-block mb-2"></i>No Word templates have been uploaded yet.</div>
            <?php endif; ?>
        </div>
        </div><!-- /.templates-mobile-cards -->

        <div class="templates-desktop-table-wrap" data-testid="templates-desktop-table">
            <?php if (!empty($templates)): ?>
            <table class="templates-modern-table">
                <thead>
                    <tr>
                        <th style="width:40%;">Template Name</th>
                        <th style="width:22%;">Survey Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $t_index = 0; foreach ($templates as $t): $t_index++; ?>
                    <tr data-testid="template-table-row-<?= $t_index ?>">
                        <td>
                            <div class="tpl-name">
                                <i class="fa-regular fa-file-word"></i>
                                <span title="<?= sanitize($t['template_name']) ?>"><?= sanitize($t['template_name']) ?></span>
                            </div>
                        </td>
                        <td class="tpl-type"><?= !empty($t['survey_type']) ? sanitize($t['survey_type']) : '—' ?></td>
                        <td>
                            <div class="tpl-actions">
                                <button type="button" class="template-rename-btn" onclick="document.getElementById('rename-form-desk-<?= $t_index ?>').classList.toggle('d-none')"><i class="fa-solid fa-pen"></i> Edit</button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this template? This cannot be undone.');">
                                    <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" name="delete_template" class="template-delete-btn"><i class="fa-solid fa-trash"></i> Delete</button>
                                </form>
                            </div>
                            <form method="POST" id="rename-form-desk-<?= $t_index ?>" class="template-rename-form d-none">
                                <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                                <input type="text" name="new_template_name" class="form-control" value="<?= sanitize($t['template_name']) ?>" required>
                                <input type="text" name="new_survey_type" class="form-control" value="<?= sanitize($t['survey_type'] ?? '') ?>" placeholder="Survey Type (optional)">
                                <button type="submit" name="rename_template"><i class="fa-solid fa-check me-1"></i> Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="template-empty"><i class="fa-regular fa-folder-open fs-3 d-block mb-2"></i>No Word templates have been uploaded yet.</div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if ($template_upload_success): ?><script>Swal.fire({icon:'success',title:'Template Uploaded',text:'The Word template is now available in the Report Generator.',confirmButtonColor:'#3b32b3'});</script><?php endif; ?>
<?php
include 'includes/nav.php';
include 'includes/footer.php';
?>
