<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// 🌟 SAFETY NET: లైవ్ డేటాబేస్‌లో business_card_path / id_card_path కాలమ్‌లు
// లేకపోతే ఇక్కడే ఆటోమేటిక్‌గా జోడించడం (database/migration_id_business_cards.sql
// ఇంకా రన్ చేయని లైవ్ సర్వర్‌లలో కూడా ఈ ఫీచర్ పని చేయడానికి).
try {
    $col = $db->query("SHOW COLUMNS FROM users LIKE 'business_card_path'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE users ADD COLUMN business_card_path VARCHAR(255) DEFAULT NULL");
    }
    $col = $db->query("SHOW COLUMNS FROM users LIKE 'id_card_path'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE users ADD COLUMN id_card_path VARCHAR(255) DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('admin_surveyor_cards.php column check/add error: ' . $e->getMessage());
}

$cards_dir = 'uploads/cards/';
if (!is_dir($cards_dir)) {
    mkdir($cards_dir, 0755, true);
}

$card_error = '';
$card_success = '';
$allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $card_type = $_POST['card_type'] ?? ''; // 'business_card' or 'id_card'
    $column = $card_type === 'business_card' ? 'business_card_path' : ($card_type === 'id_card' ? 'id_card_path' : '');

    if (isset($_POST['upload_card']) && $target_user_id > 0 && $column !== '') {
        if (!isset($_FILES['card_file']) || $_FILES['card_file']['error'] !== UPLOAD_ERR_OK) {
            $card_error = 'Please choose a file to upload.';
        } else {
            $ext = strtolower(pathinfo($_FILES['card_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) {
                $card_error = 'Only PDF, JPG or PNG files are allowed.';
            } else {
                $new_name = $card_type . '_' . $target_user_id . '_' . time() . '.' . $ext;
                $dest = $cards_dir . $new_name;
                if (move_uploaded_file($_FILES['card_file']['tmp_name'], $dest)) {
                    // పాత ఫైల్‌ను తొలగించడం
                    $old = $db->prepare("SELECT $column FROM users WHERE id = ?");
                    $old->execute([$target_user_id]);
                    $old_path = $old->fetchColumn();
                    if (!empty($old_path) && is_file($old_path)) {
                        @unlink($old_path);
                    }
                    $update = $db->prepare("UPDATE users SET $column = ? WHERE id = ?");
                    $update->execute([$dest, $target_user_id]);
                    $card_success = 'File uploaded successfully.';
            try {
                $label = ($card_type === 'business_card') ? 'Business Card' : 'ID Card';
                $sid = (int)($_POST['surveyor_id'] ?? 0);
                if ($sid > 0) {
                    createNotification($db, $sid, $label . ' uploaded',
                        'Admin uploaded your ' . $label . '. Open Profile to download.',
                        'card', 'profile.php', (int)($_SESSION['user_id'] ?? 0));
                }
            } catch (Throwable $ne) { error_log('card notif: '.$ne->getMessage()); }
                } else {
                    $card_error = 'Could not upload the file. Please try again.';
                }
            }
        }
    } elseif (isset($_POST['remove_card']) && $target_user_id > 0 && $column !== '') {
        $old = $db->prepare("SELECT $column FROM users WHERE id = ?");
        $old->execute([$target_user_id]);
        $old_path = $old->fetchColumn();
        if (!empty($old_path) && is_file($old_path)) {
            @unlink($old_path);
        }
        $update = $db->prepare("UPDATE users SET $column = NULL WHERE id = ?");
        $update->execute([$target_user_id]);
        $card_success = 'File removed successfully.';
    }
}

$surveyors = $db->query("SELECT id, full_name, username, business_card_path, id_card_path FROM users WHERE role_id = 2 ORDER BY full_name ASC")->fetchAll();

include 'includes/header.php';
?>
<style>
    .sc-page { padding: 20px 16px 110px; }
    .sc-heading { font-size: 19px; font-weight: 750; color: var(--text-dark); margin: 0 0 14px; }
    .sc-card { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 15px; margin-bottom: 12px; }
    .sc-name { font-size: 14px; font-weight: 700; color: var(--text-dark); margin-bottom: 2px; }
    .sc-username { font-size: 11.5px; color: var(--text-muted); margin-bottom: 10px; }
    .sc-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 0; border-top: 1px dashed var(--border-color); }
    .sc-row:first-of-type { border-top: none; }
    .sc-label { font-size: 12.5px; font-weight: 650; color: var(--text-dark); }
    .sc-status { font-size: 11px; color: var(--text-muted); }
    .sc-actions { display: flex; gap: 6px; }
    .sc-btn { border: none; border-radius: 8px; padding: 6px 10px; font-size: 11px; font-weight: 650; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .sc-btn-download { background: #1e3a8a; color: #fff; }
    .sc-btn-upload { background: var(--accent-purple); color: #fff; cursor: pointer; }
    .sc-btn-remove { background: #fff; color: #dc2626; border: 1px solid #fca5a5; cursor: pointer; }
    .sc-file-input { display: none; }
</style>
<div class="scroll-content">
    <?php $page_title = 'ID / Business Cards'; $back_url = 'admin_controls.php'; $page_testid = 'admin-surveyor-cards'; include 'includes/top_app_bar.php'; ?>
    <main class="sc-page" data-testid="admin-surveyor-cards-page">
        <h2 class="sc-heading">Surveyor ID &amp; Business Cards</h2>
        <?php if ($card_error): ?><div class="alert alert-danger py-2" style="font-size:12px;" data-testid="surveyor-card-error"><?= sanitize($card_error) ?></div><?php endif; ?>
        <?php if ($card_success): ?><div class="alert alert-success py-2" style="font-size:12px;" data-testid="surveyor-card-success"><?= sanitize($card_success) ?></div><?php endif; ?>

        <?php if (empty($surveyors)): ?>
            <div class="text-center text-muted py-4">No surveyors found.</div>
        <?php endif; ?>

        <?php foreach ($surveyors as $s): ?>
            <div class="sc-card" data-testid="surveyor-card-<?= (int)$s['id'] ?>">
                <div class="sc-name"><?= sanitize($s['full_name']) ?></div>
                <div class="sc-username">@<?= sanitize($s['username']) ?></div>

                <?php foreach (['business_card' => ['label' => 'Business Card', 'path' => $s['business_card_path']], 'id_card' => ['label' => 'ID Card', 'path' => $s['id_card_path']]] as $card_key => $card_info): ?>
                    <div class="sc-row">
                        <div>
                            <div class="sc-label"><?= sanitize($card_info['label']) ?></div>
                            <div class="sc-status"><?= (!empty($card_info['path']) && is_file($card_info['path'])) ? 'Uploaded' : 'Not uploaded yet' ?></div>
                        </div>
                        <div class="sc-actions">
                            <?php if (!empty($card_info['path']) && is_file($card_info['path'])): ?>
                                <a href="<?= sanitize($card_info['path']) ?>" download class="sc-btn sc-btn-download" data-testid="surveyor-card-download-<?= $card_key ?>-<?= (int)$s['id'] ?>"><i class="fa-solid fa-download"></i></a>
                                <form method="POST" onsubmit="return confirm('Remove this file?');" data-testid="surveyor-card-remove-form-<?= $card_key ?>-<?= (int)$s['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
                                    <input type="hidden" name="card_type" value="<?= $card_key ?>">
                                    <button type="submit" name="remove_card" class="sc-btn sc-btn-remove" data-testid="surveyor-card-remove-<?= $card_key ?>-<?= (int)$s['id'] ?>"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" enctype="multipart/form-data" class="d-inline" data-testid="surveyor-card-upload-form-<?= $card_key ?>-<?= (int)$s['id'] ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
                                <input type="hidden" name="card_type" value="<?= $card_key ?>">
                                <input type="file" name="card_file" accept=".pdf,.jpg,.jpeg,.png" class="sc-file-input" id="file-<?= $card_key ?>-<?= (int)$s['id'] ?>" onchange="this.form.submit();">
                                <label for="file-<?= $card_key ?>-<?= (int)$s['id'] ?>" class="sc-btn sc-btn-upload" data-testid="surveyor-card-upload-<?= $card_key ?>-<?= (int)$s['id'] ?>"><i class="fa-solid fa-upload"></i></label>
                                <input type="hidden" name="upload_card" value="1">
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </main>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
