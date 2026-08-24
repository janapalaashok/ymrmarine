<?php
require_once 'config/config.php';
checkAuth();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();
$stmt = $db->prepare('SELECT vessel_name FROM surveys WHERE id = ?');
$stmt->execute([$id]);
$vessel_name = $stmt->fetchColumn();
if (!$vessel_name) die('Survey asset not found.');
include 'includes/header.php';
?>
<style>
    .photo-report-entry { min-height: calc(100vh - 160px); padding: 30px 20px; display: flex; align-items: center; justify-content: center; }
    .photo-report-card { max-width: 560px; width: 100%; padding: 34px 24px; background: #fff; border: 1px solid var(--border-color); border-radius: 18px; box-shadow: 0 10px 26px rgba(15,23,42,.07); text-align: center; }
    .photo-report-icon { width: 66px; height: 66px; margin: 0 auto 17px; border-radius: 17px; background: #eff6ff; color: #1e40af; display: flex; align-items: center; justify-content: center; font-size: 27px; }
</style>
<div class="scroll-content">
    <?php $page_title = 'Generate Photo Report'; $back_url = 'report_detail.php?id=' . $id; $page_testid = 'generate-photo-report'; include 'includes/top_app_bar.php'; ?>
    <main class="photo-report-entry" data-testid="generate-photo-report-page">
        <section class="photo-report-card">
            <div class="photo-report-icon"><i class="fa-regular fa-images"></i></div>
            <h2 class="fw-bold text-dark" style="font-size:20px;" data-testid="generate-photo-report-heading">Photo Report</h2>
            <p class="text-muted mb-1" style="font-size:13px;" data-testid="generate-photo-report-vessel-name"><?= sanitize($vessel_name) ?></p>
            <p class="text-muted mb-4" style="font-size:12px;" data-testid="generate-photo-report-status-message">Photo report generation will be available here.</p>
            <a href="report_detail.php?id=<?= $id ?>" class="blue-action-btn text-decoration-none" data-testid="generate-photo-report-back-link"><i class="fa-solid fa-chevron-left"></i> Back to Report</a>
        </section>
    </main>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>