<?php
// Notification bell + panel (mobile + desktop)
if (empty($_SESSION['user_id'])) {
    return;
}
$__notif_count = 0;
try {
    if (!function_exists('getUnreadNotificationCount')) {
        require_once __DIR__ . '/notifications.php';
    }
    if (function_exists('getDB')) {
        $__dbn = getDB();
        $__notif_count = getUnreadNotificationCount($__dbn, (int)$_SESSION['user_id']);
    }
} catch (Throwable $e) {
    $__notif_count = 0;
}
?>
<div class="notif-bell-wrap" data-testid="global-notification-bell">
    <button type="button" class="notif-bell-btn" id="notifBellBtn" aria-label="Notifications" aria-expanded="false" data-testid="notification-bell-button">
        <i class="fa-solid fa-bell"></i>
        <span class="notif-badge<?= $__notif_count > 0 ? ' show' : '' ?>" id="notifBadge"><?= $__notif_count > 99 ? '99+' : ($__notif_count > 0 ? (int)$__notif_count : '') ?></span>
    </button>
    <div class="notif-panel" id="notifPanel" role="dialog" aria-label="Notifications" data-testid="notification-panel">
        <div class="notif-panel-head">
            <div class="notif-panel-title"><i class="fa-solid fa-bell me-2"></i>Notifications</div>
            <button type="button" class="notif-mark-all" id="notifMarkAll" data-testid="notification-mark-all">Mark all read</button>
        </div>
        <div class="notif-panel-body" id="notifPanelBody">
            <div class="notif-loading">Loading…</div>
        </div>
        <div class="notif-panel-foot">
            <span class="notif-foot-hint">Updates appear here in real time</span>
        </div>
    </div>
</div>
