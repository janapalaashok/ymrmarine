<?php
require_once 'config/config.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();

// Ensure invoice_templates table + seed default
try {
    $db->exec("CREATE TABLE IF NOT EXISTS invoice_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(150) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        uploaded_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $defaultFile = __DIR__ . '/invoice_templates/YMR_Standard_Invoice.xlsx';
    $count = (int)$db->query("SELECT COUNT(*) FROM invoice_templates")->fetchColumn();
    if ($count === 0 && is_file($defaultFile)) {
        $db->prepare("INSERT INTO invoice_templates (template_name, file_name, file_path, uploaded_by) VALUES (?,?,?,?)")
           ->execute(['YMR Standard Invoice', 'YMR_Standard_Invoice.xlsx', 'invoice_templates/YMR_Standard_Invoice.xlsx', $_SESSION['user_id'] ?? null]);
    }
} catch (Exception $e) {
    error_log('invoice_templates ensure: ' . $e->getMessage());
}

$vesselName = '';
$reportNumber = '';
$clientName = '';
$clientAddr1 = '';
$clientAddr2 = '';
$portName = '';
$surveyType = '';
$surveyorName = '';
$completeDate = '';
$completeDateRaw = '';
$backUrl = 'completed.php';

// Ensure clients address columns exist (safe if already present)
try {
    $db->exec("ALTER TABLE clients ADD COLUMN address_line1 VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) { /* column may already exist */ }
try {
    $db->exec("ALTER TABLE clients ADD COLUMN address_line2 VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) { /* column may already exist */ }

if ($id > 0) {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, c.address_line1, c.address_line2,
               p.port_name, t.type_name, surveyor.full_name AS surveyor_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        JOIN ports p ON s.port_id = p.id
        JOIN survey_types t ON s.survey_type_id = t.id
        LEFT JOIN users surveyor ON s.surveyor_id = surveyor.id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    $survey = $stmt->fetch();
    if ($survey) {
        $vesselName = $survey['vessel_name'] ?? '';
        $reportNumber = $survey['report_number'] ?? '';
        $clientName = $survey['company_name'] ?? '';
        $clientAddr1 = $survey['address_line1'] ?? '';
        $clientAddr2 = $survey['address_line2'] ?? '';
        $portName = $survey['port_name'] ?? '';
        try {
            $surveyType = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? '');
        } catch (Exception $e) {
            $surveyType = $survey['type_name'] ?? '';
        }
        $surveyorName = $survey['surveyor_name'] ?? '';
        if (!empty($survey['survey_completed_date']) && $survey['survey_completed_date'] !== '0000-00-00') {
            $completeDateRaw = $survey['survey_completed_date'];
            $completeDate = date('d M Y', strtotime($survey['survey_completed_date']));
        }
        $backUrl = 'completed_detail.php?id=' . $id;
    }
}

$defaultDescription = trim(
    ($vesselName !== '' ? $vesselName : 'Vessel')
    . ($surveyType !== '' ? ' - ' . strtoupper($surveyType) : '')
    . ($portName !== '' ? ' @' . strtoupper($portName) : '')
    . ($completeDate !== '' ? ' ' . strtoupper($completeDate) : '')
);
$invoiceDateDefault = $completeDateRaw !== '' ? date('Y-m-d', strtotime($completeDateRaw)) : date('Y-m-d');

$templates = [];
try {
    $templates = $db->query("SELECT id, template_name FROM invoice_templates ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {}

include 'includes/header.php';
?>

<style>
.inv-card {
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
    margin: 12px 12px 0;
    overflow: hidden;
}
.inv-card-header {
    background: linear-gradient(90deg, #0b1e46, #1e3a8a);
    color: #fff;
    font-weight: 600;
    font-size: 13px;
    padding: 10px 14px;
}
.inv-card-body { padding: 14px; }
.inv-autofill {
    background: #f1f5f9;
    color: #0b1e46;
    font-weight: 600;
}
.inv-generate-wrap { padding: 16px 12px 24px; text-align: center; }
.inv-btn-marine {
    background: linear-gradient(135deg, #0b1e46, #1e3a8a);
    border: none;
    color: #fff;
    font-weight: 600;
    border-radius: 10px;
    padding: .7rem 1.5rem;
    width: 100%;
    max-width: 360px;
}
.inv-btn-marine:hover { color: #fff; opacity: .92; }
.inv-note {
    font-size: 11px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 8px;
    padding: 8px 10px;
    color: #9a3412;
    margin-bottom: 10px;
}
</style>

<div class="scroll-content">
    <?php
    $page_title = 'Invoice Generator';
    $back_url = $backUrl;
    $page_testid = 'invoice-generator';
    include 'includes/top_app_bar.php';
    ?>

    <form method="POST" action="generate_invoice.php" id="invoiceForm">
        <input type="hidden" name="vessel" value="<?= sanitize($vesselName) ?>">
        <input type="hidden" name="client" value="<?= sanitize($clientName) ?>">
        <input type="hidden" name="invoice_no" value="<?= sanitize($reportNumber) ?>">

        <!-- Template dropdown -->
        <div class="inv-card">
            <div class="inv-card-header"><i class="fa-solid fa-file-excel me-1"></i> Invoice Template</div>
            <div class="inv-card-body">
                <?php if (empty($templates)): ?>
                    <div class="text-danger small">No templates found. Admin must upload one under Admin Controls → Invoice Templates.</div>
                <?php else: ?>
                    <label class="form-label small fw-semibold mb-1">Select Template</label>
                    <select name="template_id" id="templateId" class="form-select form-select-sm" required>
                        <?php foreach ($templates as $i => $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $i === 0 ? 'selected' : '' ?>><?= sanitize($t['template_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        </div>

        <!-- Invoice No = Report No -->
        <div class="inv-card">
            <div class="inv-card-header"><i class="fa-solid fa-hashtag me-1"></i> Invoice / Report Number</div>
            <div class="inv-card-body">
                <div class="inv-note">
                    <i class="fa-solid fa-info-circle me-1"></i>
                    Invoice No and Report No are the same (from survey).
                </div>
                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold mb-1">Invoice No / Report No</label>
                        <input type="text" class="form-control form-control-sm inv-autofill" value="<?= sanitize($reportNumber) ?>" readonly>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold mb-1">Invoice Date (Dated) *</label>
                        <input type="date" class="form-control form-control-sm" name="invoice_date" value="<?= sanitize($invoiceDateDefault) ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <!-- Client -->
        <div class="inv-card">
            <div class="inv-card-header"><i class="fa-solid fa-building me-1"></i> Client</div>
            <div class="inv-card-body">
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">Client Name</label>
                        <input type="text" class="form-control form-control-sm inv-autofill" value="<?= sanitize($clientName) ?>" readonly>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">Client Address Line 1 (Door no, street) *</label>
                        <input type="text" class="form-control form-control-sm <?= $clientAddr1 !== '' ? 'inv-autofill' : '' ?>" name="client_addr1" value="<?= sanitize($clientAddr1) ?>" required placeholder="Door no, street / building">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">Client Address Line 2 (City, country)</label>
                        <input type="text" class="form-control form-control-sm <?= $clientAddr2 !== '' ? 'inv-autofill' : '' ?>" name="client_addr2" value="<?= sanitize($clientAddr2) ?>" placeholder="City / country">
                    </div>
                </div>
            </div>
        </div>

        <!-- Services -->
        <div class="inv-card">
            <div class="inv-card-header"><i class="fa-solid fa-list me-1"></i> Description of Services</div>
            <div class="inv-card-body">
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1">Description *</label>
                        <textarea class="form-control form-control-sm" name="description" rows="2" required><?= sanitize($defaultDescription) ?></textarea>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-semibold mb-1">UNIT (Rate) *</label>
                        <input type="number" class="form-control form-control-sm" name="unit" id="unit" required min="0" step="0.01" placeholder="900">
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-semibold mb-1">Quantity *</label>
                        <input type="number" class="form-control form-control-sm" name="quantity" id="quantity" value="1" required min="0" step="any">
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-semibold mb-1">Amount</label>
                        <input type="text" class="form-control form-control-sm inv-autofill" id="amountPreview" value="0.00" readonly>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold mb-1">Supplier's Ref.</label>
                        <input type="text" class="form-control form-control-sm" name="supplier_ref" placeholder="Optional">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold mb-1">Other Reference(s)</label>
                        <input type="text" class="form-control form-control-sm" name="other_ref" placeholder="Optional">
                    </div>
                </div>
                <div class="mt-2 small text-muted" style="font-size:11px;">
                    Vessel: <strong><?= sanitize($vesselName ?: '—') ?></strong> ·
                    Port: <strong><?= sanitize($portName ?: '—') ?></strong> ·
                    Type: <strong><?= sanitize($surveyType ?: '—') ?></strong> ·
                    Surveyor: <strong><?= sanitize($surveyorName ?: '—') ?></strong>
                </div>
            </div>
        </div>

        <div class="inv-generate-wrap">
            <button type="submit" class="btn inv-btn-marine" id="generateBtn" <?= empty($templates) ? 'disabled' : '' ?>>
                <i class="fa-solid fa-download me-1"></i> Generate &amp; Download Invoice
            </button>
        </div>
    </form>
</div>

<?php include 'includes/nav.php'; ?>

<script>
function recalc() {
  var u = parseFloat(document.getElementById('unit').value) || 0;
  var q = parseFloat(document.getElementById('quantity').value) || 0;
  document.getElementById('amountPreview').value = (u * q).toFixed(2);
}
document.getElementById('unit').addEventListener('input', recalc);
document.getElementById('quantity').addEventListener('input', recalc);
recalc();
</script>

<?php include 'includes/footer.php'; ?>
