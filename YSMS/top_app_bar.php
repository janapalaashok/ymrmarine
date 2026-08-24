<?php
$page_title = isset($page_title) ? $page_title : SITE_NAME;
$back_url = isset($back_url) ? $back_url : '';
$page_testid = isset($page_testid) ? $page_testid : 'page';
?>
<div class="top-app-bar" data-testid="<?= sanitize($page_testid) ?>-top-app-bar">
    <div class="top-app-bar-left">
        <button type="button" class="hamburger-menu-btn" aria-label="Open navigation" aria-expanded="false" data-testid="<?= sanitize($page_testid) ?>-hamburger-button">
            <i class="fa-solid fa-bars"></i>
        </button>
        <?php if ($back_url): ?>
            <a href="<?= sanitize($back_url) ?>" class="text-dark detail-back-btn" aria-label="Back" data-testid="<?= sanitize($page_testid) ?>-back-link"><i class="fa-solid fa-chevron-left"></i></a>
        <?php endif; ?>
    </div>
    <h1 class="top-app-bar-title" data-testid="<?= sanitize($page_testid) ?>-page-title"><?= sanitize($page_title) ?></h1>
    <div class="top-app-bar-right">
        <?php include __DIR__ . '/notifications_bell.php'; ?>
        <?php include __DIR__ . '/profile_dropdown.php'; ?>
    </div>
</div>
<div class="sidebar-screen-overlay" data-testid="<?= sanitize($page_testid) ?>-sidebar-overlay"></div>
<script>document.querySelector('.mobile-container').classList.add('sidebar-page');</script>
