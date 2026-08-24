<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
checkAuth();

$format_upload_error = '';
$format_upload_success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_format']) && ($_SESSION['role'] ?? '') === 'Admin') {
    $target_directory = __DIR__ . '/formats';
    if (!is_dir($target_directory)) mkdir($target_directory, 0755, true);
    if (!isset($_FILES['format_file']) || $_FILES['format_file']['error'] !== UPLOAD_ERR_OK) {
        $format_upload_error = 'Please select an Excel format to upload.';
    } else {
        $original_name = basename($_FILES['format_file']['name']);
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xls', 'xlsx', 'xlsm'], true)) {
            $format_upload_error = 'Only Excel files (.xls, .xlsx, .xlsm) are allowed.';
        } else {
            $safe_name = preg_replace('/[^A-Za-z0-9._ -]/', '', $original_name);
            $safe_name = trim($safe_name) ?: ('survey-format.' . $extension);
            $destination = $target_directory . '/' . time() . '_' . $safe_name;
            if (move_uploaded_file($_FILES['format_file']['tmp_name'], $destination)) {
                $format_upload_success = true;
                try {
                    $fname = pathinfo($destination, PATHINFO_FILENAME);
                    $fname = preg_replace('/^\d+_/', '', $fname);
                    $fname = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $fname)));
                    notifyAllSurveyors($db, 'New survey format available',
                        'Admin uploaded a new format: ' . $fname,
                        'format', 'formats_download.php', (int)($_SESSION['user_id'] ?? 0));
                } catch (Throwable $ne) { error_log('format notif: '.$ne->getMessage()); }
            } else {
                $format_upload_error = 'The format could not be uploaded. Please try again.';
            }
        }
    }
}

// 🌟 ఈ పేజీ ద్వారా అడ్మిన్ అప్‌లోడ్ చేసిన ఫార్మాట్‌లు మాత్రమే formats/ డైరెక్టరీ లో ఉంటాయి.
// vessel_detail.php లో సర్వేయర్లు అప్‌లోడ్ చేసే ఫైల్స్ (uploads/ డైరెక్టరీ, `uploads` టేబుల్) పూర్తిగా వేరే —
// అవి ఇక్కడ కనిపించకూడదు, report_generator.php లోని Excel డ్రాప్‌డౌన్ లో మాత్రమే కనిపించాలి.
$format_directories = [__DIR__ . '/formats'];

$format_action_error = '';
$format_action_success = '';

// 🌟 ఫార్మాట్ ఫైల్ పాత్ ను formats/ డైరెక్టరీ లోపలే ఉందని నిర్ధారించే హెల్పర్ (path traversal నుండి రక్షణ)
function resolve_format_file_path($relative_path) {
    $allowed_dirs = [__DIR__ . '/formats'];
    $full_path = __DIR__ . '/' . ltrim($relative_path, '/');
    $real_path = realpath($full_path);
    if ($real_path === false) return false;
    foreach ($allowed_dirs as $dir) {
        $real_dir = realpath($dir);
        if ($real_dir !== false && strpos($real_path, $real_dir . DIRECTORY_SEPARATOR) === 0) {
            return $real_path;
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SESSION['role'] ?? '') === 'Admin') {
    // 🌟 పాత ఫార్మాట్ ఫైల్ ని తీసివేయడం (Delete)
    if (isset($_POST['delete_format'])) {
        $target = resolve_format_file_path($_POST['file_path'] ?? '');
        if ($target && is_file($target)) {
            if (unlink($target)) {
                $format_action_success = 'The format was deleted successfully.';
            } else {
                $format_action_error = 'The format could not be deleted. Please try again.';
            }
        } else {
            $format_action_error = 'That format file could not be found.';
        }
    }

    // 🌟 పాత ఫార్మాట్ ఫైల్ ని కొత్త ఫైల్ తో మార్చడం (Modify / Replace)
    if (isset($_POST['modify_format'])) {
        $old_target = resolve_format_file_path($_POST['old_file_path'] ?? '');
        if (!isset($_FILES['modify_format_file']) || $_FILES['modify_format_file']['error'] !== UPLOAD_ERR_OK) {
            $format_action_error = 'Please select a replacement Excel file.';
        } else {
            $original_name = basename($_FILES['modify_format_file']['name']);
            $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xls', 'xlsx', 'xlsm'], true)) {
                $format_action_error = 'Only Excel files (.xls, .xlsx, .xlsm) are allowed.';
            } else {
                $target_directory = __DIR__ . '/formats';
                if (!is_dir($target_directory)) mkdir($target_directory, 0755, true);
                $safe_name = preg_replace('/[^A-Za-z0-9._ -]/', '', $original_name);
                $safe_name = trim($safe_name) ?: ('survey-format.' . $extension);
                $destination = $target_directory . '/' . time() . '_' . $safe_name;
                if (move_uploaded_file($_FILES['modify_format_file']['tmp_name'], $destination)) {
                    if ($old_target && is_file($old_target)) unlink($old_target);
                    $format_action_success = 'The format was updated successfully.';
                    try {
                        notifyAllSurveyors($db, 'Survey format updated',
                            'Admin replaced a survey format. Please download the latest version.',
                            'format', 'formats_download.php', (int)($_SESSION['user_id'] ?? 0));
                    } catch (Throwable $ne) { error_log('format modify notif: '.$ne->getMessage()); }
                } else {
                    $format_action_error = 'The replacement file could not be uploaded. Please try again.';
                }
            }
        }
    }
}

$format_files = [];
$allowed_extensions = ['xls', 'xlsx', 'xlsm'];

foreach ($format_directories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }
    foreach (scandir($directory) as $file) {
        if ($file === '.' || $file === '..') continue;
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_extensions, true)) continue;
        $relative_path = basename($directory) . '/' . $file;
        $full_path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $file;
        $mtime = is_file($full_path) ? (int)@filemtime($full_path) : 0;
        $display_name = preg_replace('/^\d+_/', '', pathinfo($file, PATHINFO_FILENAME));
        $display_name = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $display_name)));
        $format_key = strtolower($display_name);
        // Prefer newer file if same display name appears in multiple folders
        if (!isset($format_files[$format_key]) || $mtime >= (int)($format_files[$format_key]['mtime'] ?? 0)) {
            $format_files[$format_key] = [
                'name'  => $display_name,
                'path'  => $relative_path,
                'mtime' => $mtime,
            ];
        }
    }
}

$one_week_ago = time() - (7 * 24 * 60 * 60);
foreach ($format_files as $k => $f) {
    $mt = (int)($f['mtime'] ?? 0);
    $format_files[$k]['updated_label'] = $mt > 0 ? date('d M Y, h:i A', $mt) : '—';
    $format_files[$k]['is_recent'] = ($mt > 0 && $mt >= $one_week_ago);
}

uasort($format_files, function ($a, $b) {
    // Recent first, then by name
    $ar = !empty($a['is_recent']) ? 1 : 0;
    $br = !empty($b['is_recent']) ? 1 : 0;
    if ($ar !== $br) return $br - $ar;
    return strcasecmp($a['name'], $b['name']);
});
$whatsapp_message = rawurlencode("Hello Admin,\nI found an issue in one of the survey formats.");
$whatsapp_number = preg_replace('/\D+/', '', WHATSAPP_NUMBER);
$whatsapp_url = $whatsapp_number !== ''
    ? 'https://wa.me/' . $whatsapp_number . '?text=' . $whatsapp_message
    : 'https://api.whatsapp.com/send?text=' . $whatsapp_message;
include 'includes/header.php';
?>
<style>
    .formats-page { padding: 28px 20px 110px; }
    .formats-heading { font-size: 24px; font-weight: 750; color: var(--text-dark); margin: 0 0 6px; }
    .formats-subtitle { color: var(--text-muted); font-size: 13px; margin-bottom: 22px; }
    .formats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; }
    .format-card { background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 4px 12px rgba(15,23,42,.04); }

    /* Desktop table / mobile cards */
    .formats-desktop-table-wrap { display: none; }
    .formats-mobile-cards { display: block; }
    @media (min-width: 992px) {
        .formats-page { padding: 24px 28px 40px; max-width: 1200px; margin: 0 auto; }
        .formats-mobile-cards { display: none !important; }
        .formats-desktop-table-wrap { display: block !important; }
        .formats-modern-table {
            width: 100%; border-collapse: separate; border-spacing: 0;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
            overflow: hidden; box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .formats-modern-table thead th {
            background: #f8fafc; color: #475569; font-size: 11.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .04em; padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap;
        }
        .formats-modern-table tbody td {
            padding: 14px 16px; border-bottom: 1px solid #f1f5f9;
            font-size: 13.5px; color: #0f172a; vertical-align: middle;
        }
        .formats-modern-table tbody tr:last-child td { border-bottom: 0; }
        .formats-modern-table tbody tr:hover { background: #f8fafc; }
        .formats-modern-table tbody tr.format-recent {
            background: #f0fdf4;
        }
        .formats-modern-table tbody tr.format-recent:hover {
            background: #ecfdf5;
        }
        .formats-modern-table .fmt-updated {
            font-size: 12px;
            color: #64748b;
            white-space: nowrap;
        }
        .formats-modern-table .fmt-new-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            margin-left: 6px;
            text-transform: uppercase;
            letter-spacing: .03em;
            vertical-align: middle;
        }
        .formats-modern-table .fmt-name {
            font-weight: 650; display: flex; align-items: center; gap: 10px;
        }
        .formats-modern-table .fmt-name i {
            width: 36px; height: 36px; border-radius: 10px; background: #ecfdf5; color: #15803d;
            display: inline-flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;
        }
        .formats-modern-table .fmt-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .formats-modern-table .format-modify-form { margin-top: 8px; max-width: 420px; }
    }
    .format-icon { width: 48px; height: 48px; border-radius: 13px; background: #ecfdf5; color: #15803d; display: flex; align-items: center; justify-content: center; font-size: 25px; flex: 0 0 auto; }
    .format-main { min-width: 0; flex: 1; }
    .format-name { font-size: 14px; font-weight: 700; color: var(--text-dark); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-bottom: 4px; }
    .format-updated {
        font-size: 11px;
        color: #64748b;
        font-weight: 550;
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 7px;
    }
    .format-updated .fmt-new-badge {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        background: #dcfce7;
        color: #166534;
        border: 1px solid #86efac;
        font-size: 10px;
        font-weight: 700;
        padding: 1px 7px;
        border-radius: 999px;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .format-card.format-recent {
        border-color: #86efac;
        background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 55%);
        box-shadow: 0 4px 14px rgba(22, 163, 74, 0.08);
    }
    .format-card.format-recent .format-icon {
        background: #dcfce7;
        color: #15803d;
    }
    .format-download-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 8px; background: #1e3a8a; color: #fff; font-size: 11px; font-weight: 650; text-decoration: none; transition: transform .2s ease, background-color .2s ease; }
    .format-download-btn:hover { color: #fff; background: #172e70; transform: translateY(-1px); }
    .format-modify-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 8px; background: #fff; color: #b45309; border: 1px solid #fcd34d; font-size: 11px; font-weight: 650; }
    .format-modify-btn:hover { background: #fffbeb; }
    .format-delete-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 8px; background: #fff; color: #dc2626; border: 1px solid #fca5a5; font-size: 11px; font-weight: 650; }
    .format-delete-btn:hover { background: #fef2f2; }
    .format-modify-form { display: flex; gap: 8px; align-items: center; margin-top: 10px; }
    .format-modify-form input[type="file"] { flex: 1; min-width: 0; font-size: 11px; }
    .format-modify-form button { white-space: nowrap; border: 0; border-radius: 8px; padding: 8px 12px; background: var(--accent-purple); color: #fff; font-size: 11px; font-weight: 650; }
    @media (max-width: 520px) { .format-modify-form { flex-direction: column; align-items: stretch; } }
    .format-empty { background: #fff; border: 1px dashed var(--border-color); border-radius: 16px; padding: 34px 20px; text-align: center; color: var(--text-muted); grid-column: 1 / -1; }
    .format-contact { margin-top: 24px; padding: 17px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 14px; color: #9a3412; text-align: center; font-size: 13px; }
    .format-contact a { color: #1e3a8a; font-weight: 700; }
    .format-upload-panel { margin-bottom: 20px; padding: 16px; background: #fff; border: 1px solid var(--border-color); border-radius: 14px; }
    .format-upload-form { display: flex; gap: 10px; align-items: center; }
    .format-upload-form input { flex: 1; min-width: 0; font-size: 12px; }
    .format-upload-form button { white-space: nowrap; border: 0; border-radius: 9px; padding: 10px 15px; background: var(--accent-purple); color: #fff; font-size: 12px; font-weight: 650; }
    @media (max-width: 520px) { .formats-page { padding: 22px 16px 100px; } .formats-heading { font-size: 21px; } .formats-grid { grid-template-columns: 1fr; } }
    @media (max-width: 520px) { .format-upload-form { align-items: stretch; flex-direction: column; } }
</style>
<div class="scroll-content">
    <?php $page_title = 'Formats Download'; $back_url = 'index.php'; $page_testid = 'formats-download'; include 'includes/top_app_bar.php'; ?>
    <main class="formats-page" data-testid="formats-download-page">
        <h2 class="formats-heading" data-testid="formats-download-heading">Latest Formats For Surveys</h2>
        <p class="formats-subtitle" data-testid="formats-download-subtitle">Select the latest approved Excel format for your survey.</p>
        <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
            <section class="format-upload-panel" data-testid="format-upload-panel">
                <div class="fw-bold text-dark mb-2" style="font-size:13px;"><i class="fa-solid fa-cloud-arrow-up text-primary me-1"></i> Upload Survey Format</div>
                <?php if ($format_upload_error): ?><div class="alert alert-danger py-2" style="font-size:12px;" data-testid="format-upload-error-message"><?= sanitize($format_upload_error) ?></div><?php endif; ?>
                <form method="POST" enctype="multipart/form-data" class="format-upload-form" data-testid="format-upload-form">
                    <input type="file" name="format_file" accept=".xls,.xlsx,.xlsm" required class="form-control" data-testid="format-upload-file-input">
                    <button type="submit" name="upload_format" data-testid="format-upload-submit-button"><i class="fa-solid fa-upload me-1"></i> Upload</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if ($format_action_error): ?><div class="alert alert-danger py-2 mb-3" style="font-size:12px;" data-testid="format-action-error-message"><?= sanitize($format_action_error) ?></div><?php endif; ?>
        <?php if ($format_action_success): ?><div class="alert alert-success py-2 mb-3" style="font-size:12px;" data-testid="format-action-success-message"><?= sanitize($format_action_success) ?></div><?php endif; ?>
        <div class="formats-mobile-cards">
        <div class="formats-grid" data-testid="formats-download-grid">
            <?php if ($format_files): ?>
                <?php $format_index = 0; foreach ($format_files as $format): $format_index++; ?>
                    <article class="format-card<?= !empty($format['is_recent']) ? ' format-recent' : '' ?>" data-testid="format-card-<?= $format_index ?>">
                        <div class="format-icon"><i class="fa-regular fa-file-excel"></i></div>
                        <div class="format-main">
                            <div class="format-name" title="<?= sanitize($format['name']) ?>" data-testid="format-name-<?= $format_index ?>"><?= sanitize($format['name']) ?></div>
                            <div class="format-updated" data-testid="format-updated-<?= $format_index ?>">
                                <i class="fa-regular fa-clock"></i>
                                <span><?= sanitize($format['updated_label'] ?? '—') ?></span>
                                <?php if (!empty($format['is_recent'])): ?>
                                    <span class="fmt-new-badge"><i class="fa-solid fa-bolt"></i> Updated</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="<?= sanitize($format['path']) ?>" download class="format-download-btn" data-testid="format-download-button-<?= $format_index ?>"><i class="fa-solid fa-download"></i> Download</a>
                                <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                                    <button type="button" class="format-modify-btn" onclick="document.getElementById('modify-form-<?= $format_index ?>').classList.toggle('d-none')" data-testid="format-modify-button-<?= $format_index ?>"><i class="fa-solid fa-rotate"></i> Modify</button>
                                    <form method="POST" class="d-inline" data-testid="format-delete-form-<?= $format_index ?>" onsubmit="return confirm('Delete this format? This cannot be undone.');">
                                        <input type="hidden" name="file_path" value="<?= sanitize($format['path']) ?>">
                                        <button type="submit" name="delete_format" class="format-delete-btn" data-testid="format-delete-button-<?= $format_index ?>"><i class="fa-solid fa-trash"></i> Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                                <form method="POST" enctype="multipart/form-data" id="modify-form-<?= $format_index ?>" class="format-modify-form d-none" data-testid="format-modify-form-<?= $format_index ?>">
                                    <input type="hidden" name="old_file_path" value="<?= sanitize($format['path']) ?>">
                                    <input type="file" name="modify_format_file" accept=".xls,.xlsx,.xlsm" required class="form-control" data-testid="format-modify-file-input-<?= $format_index ?>">
                                    <button type="submit" name="modify_format" data-testid="format-modify-submit-button-<?= $format_index ?>"><i class="fa-solid fa-upload me-1"></i> Replace</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="format-empty" data-testid="formats-empty-message"><i class="fa-regular fa-folder-open fs-3 d-block mb-2"></i>No survey formats have been uploaded yet.</div>
            <?php endif; ?>
        </div>
        </div><!-- /.formats-mobile-cards -->

        <div class="formats-desktop-table-wrap" data-testid="formats-desktop-table">
            <?php if (!empty($format_files)): ?>
            <table class="formats-modern-table">
                <thead>
                    <tr>
                        <th style="width:38%;">Format Name</th>
                        <th style="width:24%;">Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $format_index = 0; foreach ($format_files as $format): $format_index++; ?>
                    <tr class="<?= !empty($format['is_recent']) ? 'format-recent' : '' ?>" data-testid="format-table-row-<?= $format_index ?>">
                        <td>
                            <div class="fmt-name">
                                <i class="fa-solid fa-file-excel"></i>
                                <span title="<?= sanitize($format['name']) ?>"><?= sanitize($format['name']) ?></span>
                                <?php if (!empty($format['is_recent'])): ?>
                                    <span class="fmt-new-badge"><i class="fa-solid fa-bolt"></i> Updated</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="fmt-updated"><?= sanitize($format['updated_label'] ?? '—') ?></td>
                        <td>
                            <div class="fmt-actions">
                                <a href="<?= sanitize($format['path']) ?>" download class="format-download-btn"><i class="fa-solid fa-download"></i> Download</a>
                                <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                                    <button type="button" class="format-modify-btn" onclick="document.getElementById('modify-form-desk-<?= $format_index ?>').classList.toggle('d-none')"><i class="fa-solid fa-pen"></i> Modify</button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this format? This cannot be undone.');">
                                        <input type="hidden" name="file_path" value="<?= sanitize($format['path']) ?>">
                                        <button type="submit" name="delete_format" class="format-delete-btn"><i class="fa-solid fa-trash"></i> Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                                <form method="POST" enctype="multipart/form-data" id="modify-form-desk-<?= $format_index ?>" class="format-modify-form d-none">
                                    <input type="hidden" name="old_file_path" value="<?= sanitize($format['path']) ?>">
                                    <input type="file" name="modify_format_file" accept=".xls,.xlsx,.xlsm" required class="form-control">
                                    <button type="submit" name="modify_format"><i class="fa-solid fa-upload me-1"></i> Replace</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="format-empty"><i class="fa-regular fa-folder-open fs-3 d-block mb-2"></i>No survey formats have been uploaded yet.</div>
            <?php endif; ?>
        </div>

        <div class="format-contact" data-testid="formats-contact-notice">Have any error in any format? <a href="<?= sanitize($whatsapp_url) ?>" target="_blank" rel="noopener" data-testid="formats-contact-admin-link">Please contact Admin.</a></div>
    </main>
</div>
<?php if ($format_upload_success): ?><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script><script>Swal.fire({icon:'success',title:'Format Uploaded',text:'The Excel format is now available for download.',confirmButtonColor:'#3b32b3'});</script><?php endif; ?>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>