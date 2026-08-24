<?php
require_once 'config/config.php';
checkAuth();

$feature = trim((string)($_GET['feature'] ?? 'This feature'));
// Allow only safe display text
$feature = preg_replace('/[^\p{L}\p{N}\s\-\+\&\/\(\)\.]/u', '', $feature);
if ($feature === '') {
    $feature = 'This feature';
}

include 'includes/header.php';
?>
<style>
    .coming-soon-wrap { min-height: calc(100vh - 160px); padding: 30px 20px; display: flex; align-items: center; justify-content: center; }
    .coming-soon-card { max-width: 520px; width: 100%; padding: 38px 24px; border-radius: 20px; background: #fff; border: 1px solid var(--border-color); box-shadow: 0 12px 28px rgba(15,23,42,.07); text-align: center; }
    .coming-soon-icon { width: 68px; height: 68px; margin: 0 auto 18px; border-radius: 18px; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: #1e40af; font-size: 28px; }
</style>
<div class="scroll-content">
    <?php $page_title = sanitize($feature); $back_url = 'index.php'; $page_testid = 'coming-soon'; include 'includes/top_app_bar.php'; ?>
    <main class="coming-soon-wrap" data-testid="coming-soon-page">
        <section class="coming-soon-card">
            <div class="coming-soon-icon"><i class="fa-solid fa-clock"></i></div>
            <h2 class="fw-bold text-dark" style="font-size:22px;" data-testid="coming-soon-heading">Coming Soon</h2>
            <p class="text-muted mb-4" style="font-size:13px;" data-testid="coming-soon-message">
                <strong><?= sanitize($feature) ?></strong> is being prepared and will be available here soon.
            </p>
            <a href="index.php" class="blue-action-btn text-decoration-none" data-testid="coming-soon-home-link"><i class="fa-solid fa-house"></i> Return Home</a>
        </section>
    </main>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
