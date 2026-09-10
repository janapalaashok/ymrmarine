<?php
require_once 'config/config.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Super Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// 🌟 TEMPORARY PAGE — built only to let Super Admin wipe test data before
// go-live (see conversation). Not linked anywhere except the Super Admin
// sidebar/FAB, and gated to Super Admin only, same as the rest of the app's
// role checks. Remove this file (and its nav.php / footer.php links) once
// go-live testing is done and this is no longer needed.

$delete_targets = [
    'pending_vessel' => ['status' => 'Pending Vessel', 'label' => 'Pending Vessels'],
    'pending_report' => ['status' => 'Pending Report', 'label' => 'Pending Reports'],
    'cancelled'       => ['status' => 'Cancelled',      'label' => 'Cancelled Vessels'],
    'completed'       => ['status' => 'Completed',      'label' => 'Completed Vessels'],
];

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_target'])) {
    $target = (string)$_POST['delete_target'];
    if (!isset($delete_targets[$target])) {
        $errors[$target] = 'Unknown delete target.';
    } elseif (trim($_POST['confirm_text'] ?? '') !== 'DELETE') {
        $errors[$target] = 'Please type DELETE exactly to confirm.';
    } else {
        try {
            $status = $delete_targets[$target]['status'];
            $countStmt = $db->prepare("SELECT COUNT(*) FROM surveys WHERE status = ?");
            $countStmt->execute([$status]);
            $count = (int)$countStmt->fetchColumn();

            $delStmt = $db->prepare("DELETE FROM surveys WHERE status = ?");
            $delStmt->execute([$status]);

            $messages[$target] = $count . ' ' . strtolower($delete_targets[$target]['label']) . ' record(s) permanently deleted.';
        } catch (Throwable $e) {
            error_log('super_admin_controls delete all (' . $target . '): ' . $e->getMessage());
            $errors[$target] = 'Could not delete ' . strtolower($delete_targets[$target]['label']) . '. Please try again.';
        }
    }
}

include 'includes/header.php';
?>
<style>
    .sac-banner {
        margin: 16px 16px 0;
        padding: 12px 14px;
        border-radius: 12px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
        font-size: 12.5px;
        font-weight: 600;
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    .sac-banner i { margin-top: 1px; }
    .admin-danger-zone {
        margin: 16px 16px 24px;
        padding: 16px;
        border: 1px solid rgba(239,68,68,.3);
        background: rgba(239,68,68,.05);
        border-radius: 14px;
    }
    .admin-danger-zone-title { color: #dc2626; font-weight: 700; font-size: 13px; margin-bottom: 10px; }
    .admin-danger-zone-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
    .admin-danger-zone-label { font-weight: 650; font-size: 13.5px; color: var(--text-dark); }
    .admin-danger-zone-copy { font-size: 12px; color: var(--text-muted); margin-top: 2px; max-width: 480px; }
    .admin-danger-zone-btn {
        flex: 0 0 auto; background: #dc2626; color: #fff; border: none; border-radius: 10px;
        padding: 10px 16px; font-weight: 650; font-size: 13px; white-space: nowrap;
    }
    @media (min-width: 992px) {
        .sac-banner, .admin-danger-zone { max-width: 900px; margin-left: auto; margin-right: auto; }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Super Admin — Data Cleanup'; $back_url = 'index.php'; $page_testid = 'super-admin-controls'; include 'includes/top_app_bar.php'; ?>

    <div class="sac-banner" data-testid="sac-temporary-banner">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>Temporary tool — for clearing test data before go-live only. Each action below permanently deletes every record in that category, with no way to undo it. Remove this page once testing is complete.</span>
    </div>

    <?php foreach ($delete_targets as $key => $cfg): ?>
    <div class="admin-danger-zone" data-testid="sac-danger-zone-<?= sanitize($key) ?>">
        <div class="admin-danger-zone-title"><i class="fa-solid fa-triangle-exclamation"></i> Danger Zone — <?= sanitize($cfg['label']) ?></div>
        <?php if (!empty($messages[$key])): ?>
            <div class="alert alert-success py-2 mb-2"><?= sanitize($messages[$key]) ?></div>
        <?php endif; ?>
        <?php if (!empty($errors[$key])): ?>
            <div class="alert alert-danger py-2 mb-2"><?= sanitize($errors[$key]) ?></div>
        <?php endif; ?>
        <div class="admin-danger-zone-row">
            <div>
                <div class="admin-danger-zone-label">Delete all <?= sanitize($cfg['label']) ?></div>
                <div class="admin-danger-zone-copy">Permanently deletes every record currently in <?= sanitize($cfg['label']) ?>, along with any uploaded survey files and reports linked to them. This cannot be undone.</div>
            </div>
            <button type="button" class="admin-danger-zone-btn" data-bs-toggle="modal" data-bs-target="#sacDeleteModal-<?= sanitize($key) ?>" data-testid="sac-delete-all-<?= sanitize($key) ?>-btn">
                <i class="fa-solid fa-trash"></i> Delete All
            </button>
        </div>
    </div>

    <div class="modal fade" id="sacDeleteModal-<?= sanitize($key) ?>" tabindex="-1" data-testid="sac-delete-modal-<?= sanitize($key) ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none;">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title fw-bold text-danger" style="font-size: 15px;"><i class="fa-solid fa-triangle-exclamation me-1"></i> Delete all <?= sanitize($cfg['label']) ?>?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST"><?= csrf_field() ?>
                    <input type="hidden" name="delete_target" value="<?= sanitize($key) ?>">
                    <div class="modal-body">
                        <p class="text-muted" style="font-size:13px;">This permanently deletes every <?= sanitize(strtolower($cfg['label'])) ?> record and its uploaded files. This action cannot be undone.</p>
                        <label class="form-label" style="font-size:12px;font-weight:650;">Type <b>DELETE</b> to confirm</label>
                        <input type="text" name="confirm_text" class="form-control" placeholder="DELETE" required autocomplete="off">
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash me-1"></i> Delete All</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
