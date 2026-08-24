<?php
require_once 'config/config.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();
$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

$stmt = $db->prepare("
    SELECT s.*, c.company_name, p.port_name, t.type_name, u.full_name AS surveyor_name
    FROM surveys s
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN ports p ON s.port_id = p.id
    LEFT JOIN survey_types t ON s.survey_type_id = t.id
    LEFT JOIN users u ON s.surveyor_id = u.id
    WHERE s.id = ? AND s.status = 'Completed'
");
$stmt->execute([$id]);
$survey = $stmt->fetch();

if (!$survey) {
    die('Completed survey not found.');
}
if ($role !== 'Admin' && (int)$survey['surveyor_id'] !== $user_id) {
    die('Access denied.');
}

include 'includes/header.php';
?>
<style>
.exp-card { background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:18px; margin:12px 16px; box-shadow:0 2px 8px rgba(15,23,42,.04); }
.exp-card h5 { font-size:15px; font-weight:700; margin-bottom:14px; }
.exp-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media (max-width:640px){ .exp-grid{ grid-template-columns:1fr; } }
.exp-grid label { font-size:11px; font-weight:650; color:var(--text-muted); margin-bottom:4px; display:block; }
.exp-grid input, .exp-grid textarea {
    width:100%; padding:10px 12px; border:1px solid var(--border-color); border-radius:10px;
    font-size:13px; background:#f8fafc;
}
.exp-grid .full { grid-column:1 / -1; }
.exp-submit {
    margin-top:16px; width:100%; background:#3b32b3; color:#fff; border:none;
    padding:12px; border-radius:12px; font-weight:700; font-size:14px;
}
.exp-meta { font-size:12px; color:var(--text-muted); margin-bottom:12px; }
</style>

<div class="scroll-content">
    <?php $page_title = 'Generate Expenses'; $back_url = 'completed_detail.php?id=' . $id; $page_testid = 'expense-generator'; include 'includes/top_app_bar.php'; ?>

    <div class="exp-card">
        <h5><i class="fa-solid fa-receipt text-primary me-1"></i> Expense Claim — <?= sanitize($survey['vessel_name']) ?></h5>
        <div class="exp-meta">
            Client: <strong><?= sanitize($survey['company_name'] ?? '') ?></strong> ·
            Port: <strong><?= sanitize($survey['port_name'] ?? '') ?></strong> ·
            Surveyor: <strong><?= sanitize($survey['surveyor_name'] ?? '') ?></strong>
        </div>

        <form action="generate_expense.php" method="POST">
            <input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>">
            <input type="hidden" name="vessel_name" value="<?= sanitize($survey['vessel_name']) ?>">
            <input type="hidden" name="client_name" value="<?= sanitize($survey['company_name'] ?? '') ?>">
            <input type="hidden" name="port_name" value="<?= sanitize($survey['port_name'] ?? '') ?>">
            <input type="hidden" name="surveyor_name" value="<?= sanitize($survey['surveyor_name'] ?? ($_SESSION['full_name'] ?? '')) ?>">
            <input type="hidden" name="report_number" value="<?= sanitize($survey['report_number'] ?? '') ?>">

            <div class="exp-grid">
                <div class="full">
                    <label>Claim Date</label>
                    <input type="date" name="claim_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label>Hotel / Lodging (INR)</label>
                    <input type="number" name="hotel" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div>
                    <label>Taxi / Local Transport (INR)</label>
                    <input type="number" name="taxi" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div>
                    <label>Train (INR)</label>
                    <input type="number" name="train" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div>
                    <label>Flight (INR)</label>
                    <input type="number" name="flight" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div>
                    <label>Food / Meals (INR)</label>
                    <input type="number" name="food" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div>
                    <label>Other Expenses (INR)</label>
                    <input type="number" name="other" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div class="full">
                    <label>Remarks / Notes</label>
                    <textarea name="remarks" rows="3" placeholder="Optional notes about this claim"></textarea>
                </div>
            </div>
            <button type="submit" class="exp-submit"><i class="fa-solid fa-file-excel me-1"></i> Generate Expense Excel</button>
        </form>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
