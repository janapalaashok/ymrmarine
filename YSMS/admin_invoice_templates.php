<?php
require_once 'config/config.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS invoice_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(150) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        uploaded_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log('invoice_templates table: ' . $e->getMessage());
}

$dir = __DIR__ . '/invoice_templates';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

// Seed default template if none and file exists
$defaultFile = $dir . '/YMR_Standard_Invoice.xlsx';
$count = (int)$db->query("SELECT COUNT(*) FROM invoice_templates")->fetchColumn();
if ($count === 0 && is_file($defaultFile)) {
    $stmt = $db->prepare("INSERT INTO invoice_templates (template_name, file_name, file_path, uploaded_by) VALUES (?,?,?,?)");
    $stmt->execute([
        'YMR Standard Invoice',
        'YMR_Standard_Invoice.xlsx',
        'invoice_templates/YMR_Standard_Invoice.xlsx',
        $_SESSION['user_id'] ?? null
    ]);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $row = $db->prepare("SELECT * FROM invoice_templates WHERE id = ?");
        $row->execute([$id]);
        $tpl = $row->fetch();
        if ($tpl) {
            $path = __DIR__ . '/' . ltrim($tpl['file_path'], '/');
            if (is_file($path)) {
                @unlink($path);
            }
            $db->prepare("DELETE FROM invoice_templates WHERE id = ?")->execute([$id]);
            $success = 'Template deleted.';
        }
    }

    if (isset($_POST['upload_template'])) {
        $name = trim($_POST['template_name'] ?? '');
        if ($name === '') {
            $error = 'Please enter a template name.';
        } elseif (!isset($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select an Excel file (.xlsx).';
        } else {
            $orig = basename($_FILES['template_file']['name']);
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xls'], true)) {
                $error = 'Only Excel files (.xlsx, .xls) are allowed.';
            } else {
                $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
                $stored = time() . '_' . $safe . '.' . $ext;
                $dest = $dir . '/' . $stored;
                if (move_uploaded_file($_FILES['template_file']['tmp_name'], $dest)) {
                    $stmt = $db->prepare("INSERT INTO invoice_templates (template_name, file_name, file_path, uploaded_by) VALUES (?,?,?,?)");
                    $stmt->execute([
                        $name,
                        $orig,
                        'invoice_templates/' . $stored,
                        $_SESSION['user_id'] ?? null
                    ]);
                    $success = 'Template uploaded successfully.';
                } else {
                    $error = 'Upload failed. Check folder permissions.';
                }
            }
        }
    }
}

$templates = $db->query("SELECT * FROM invoice_templates ORDER BY id ASC")->fetchAll();

include 'includes/header.php';
?>
<div class="scroll-content">
    <?php $page_title = 'Invoice Templates'; $back_url = 'admin_controls.php'; $page_testid = 'admin-invoice-templates'; include 'includes/top_app_bar.php'; ?>

    <div class="px-3 pt-3">
        <?php if ($error): ?>
            <div class="alert alert-danger py-2" style="font-size:13px;"><?= sanitize($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success py-2" style="font-size:13px;"><?= sanitize($success) ?></div>
        <?php endif; ?>

        <div class="bg-white rounded-3 border shadow-sm p-3 mb-3">
            <div class="fw-bold mb-2" style="font-size:14px;"><i class="fa-solid fa-upload text-primary me-1"></i> Upload Invoice Template</div>
            <p class="text-muted mb-2" style="font-size:12px;">Upload a .xlsx invoice layout. Use placeholders such as <code>{{INVOICE_NO}}</code>, <code>{{CLIENT}}</code>, <code>{{UNIT}}</code> in cells.</p>
            <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
                <input type="hidden" name="upload_template" value="1">
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Template Name *</label>
                    <input type="text" name="template_name" class="form-control form-control-sm" placeholder="e.g. YMR Standard Invoice" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Excel File *</label>
                    <input type="file" name="template_file" class="form-control form-control-sm" accept=".xlsx,.xls" required>
                </div>
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i> Add Template</button>
            </form>
        </div>

        <div class="fw-bold mb-2" style="font-size:13px;">Saved Templates (<?= count($templates) ?>)</div>
        <?php if (empty($templates)): ?>
            <div class="text-center text-muted py-4 bg-white rounded-3 border" style="font-size:13px;">No invoice templates yet.</div>
        <?php else: ?>
            <?php foreach ($templates as $t): ?>
                <div class="bg-white rounded-3 border shadow-sm p-3 mb-2 d-flex justify-content-between align-items-center gap-2">
                    <div class="min-w-0">
                        <div class="fw-semibold text-dark" style="font-size:13.5px;"><?= sanitize($t['template_name']) ?></div>
                        <div class="text-muted" style="font-size:11px;"><?= sanitize($t['file_name']) ?> · <?= date('d M Y', strtotime($t['created_at'])) ?></div>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <a href="<?= sanitize($t['file_path']) ?>" class="btn btn-sm btn-light border" download title="Download"><i class="fa-solid fa-download"></i></a>
                        <form method="POST" onsubmit="return confirm('Delete this template?');" class="m-0"><?= csrf_field() ?>
                            <input type="hidden" name="delete_id" value="<?= (int)$t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-light border text-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
