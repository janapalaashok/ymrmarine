</div> <!-- .mobile-container క్లోజింగ్ ట్యాగ్ -->

<?php
// The FAB trigger button itself is only rendered for Admin, Super Admin and
// Client (see includes/nav.php), but its content here must still match each
// role's own permissions — Super Admin never gets Assign Vessel / Add
// Surveyor / Admin Controls (only Add Client + Add Admin), and Client only
// ever gets Request Survey.
$fab_user_role = $user_role ?? ($_SESSION['role'] ?? '');
?>
<!-- Global Center FAB Action Drawer Overlay -->
<div class="fab-overlay" id="fabActionOverlay">
    <div class="fab-popup-sheet" id="fabPopupSheet">
        <h5 class="fw-bold mb-3 text-dark" style="font-size: 16px;">Quick Actions</h5>

        <?php if ($fab_user_role === 'Admin'): ?>
        <!-- 1. Assign Vessel -->
        <a href="assign_vessel.php" class="fab-option-item" data-testid="fab-assign-vessel-link">
            <i class="fa-solid fa-ship text-primary" style="width: 24px; text-align: center;"></i>
            <span>Assign Vessel</span>
        </a>

        <!-- 2. Add Surveyor -->
        <a href="add_surveyor.php" class="fab-option-item" data-testid="fab-add-surveyor-link">
            <i class="fa-solid fa-user-gear text-warning" style="width: 24px; text-align: center;"></i>
            <span>Add Surveyor</span>
        </a>

        <!-- 3. Add Client -->
        <a href="add_client.php" class="fab-option-item" data-testid="fab-add-client-link">
            <i class="fa-solid fa-user-plus text-success" style="width: 24px; text-align: center;"></i>
            <span>Add Client</span>
        </a>

        <!-- 4. Admin Controls -->
        <a href="admin_controls.php" class="fab-option-item" data-testid="fab-admin-controls-link">
            <i class="fa-solid fa-user-shield text-primary" style="width: 24px; text-align: center;"></i>
            <span>Admin Controls</span>
        </a>
        <?php elseif ($fab_user_role === 'Super Admin'): ?>
        <!-- Super Admin: Add Client + Add Admin only — matches the desktop
             sidebar Quick Actions in includes/nav.php. -->
        <a href="add_client.php" class="fab-option-item" data-testid="fab-add-client-link">
            <i class="fa-solid fa-user-plus text-success" style="width: 24px; text-align: center;"></i>
            <span>Add Client</span>
        </a>
        <a href="add_admin.php" class="fab-option-item" data-testid="fab-add-admin-link">
            <i class="fa-solid fa-user-shield text-primary" style="width: 24px; text-align: center;"></i>
            <span>Add Admin</span>
        </a>
        <!-- TEMPORARY — remove once go-live test-data cleanup is done. -->
        <a href="super_admin_controls.php" class="fab-option-item" data-testid="fab-data-cleanup-link">
            <i class="fa-solid fa-trash text-danger" style="width: 24px; text-align: center;"></i>
            <span>Data Cleanup (Temp)</span>
        </a>
        <?php elseif ($fab_user_role === 'Client'): ?>
        <a href="assign_vessel.php" class="fab-option-item" data-testid="fab-request-survey-link">
            <i class="fa-solid fa-ship text-primary" style="width: 24px; text-align: center;"></i>
            <span>Request Survey</span>
        </a>
        <?php endif; ?>

        <button class="btn btn-light w-100 mt-3 rounded-3 fw-bold text-danger" id="closeFabBtn" style="font-size: 14px; padding: 12px;" data-testid="fab-close-button">Close</button>
    </div>
</div>

<!-- Scripts Loader -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>

<?php if (!empty($_SESSION['user_id'])): ?>
<style>
.push-prompt-bar {
    position: fixed; top: 14px; left: 14px; right: 14px; z-index: 9998;
    max-width: 380px; margin: 0 auto;
    background: #1e293b; color: #fff; border-radius: 14px; padding: 12px 14px;
    box-shadow: 0 10px 25px -3px rgba(0,0,0,.3);
    display: flex; align-items: center; gap: 10px;
    animation: slideDownToast .3s ease-out;
}
.push-prompt-bar i.bell { font-size: 18px; flex-shrink: 0; }
.push-prompt-bar .ppb-text { flex: 1; font-size: 12.5px; line-height: 1.35; }
.push-prompt-bar button { border: none; border-radius: 8px; padding: 6px 11px; font-size: 11.5px; font-weight: 650; cursor: pointer; }
.push-prompt-bar .ppb-enable { background: var(--accent-purple, #6d5ae6); color: #fff; }
.push-prompt-bar .ppb-dismiss { background: transparent; color: rgba(255,255,255,.6); }
</style>
<script>
(function () {
    var VAPID_PUBLIC_KEY = <?= json_encode(VAPID_PUBLIC_KEY) ?>;
    var CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !VAPID_PUBLIC_KEY) return;

    navigator.serviceWorker.register('sw.js').catch(function () { /* installability is best-effort */ });

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
        return outputArray;
    }

    function postSubscription(url, sub) {
        var body = new URLSearchParams();
        body.set('csrf_token', CSRF_TOKEN);
        body.set('endpoint', sub.endpoint);
        if (sub.toJSON) {
            var keys = sub.toJSON().keys || {};
            body.set('p256dh', keys.p256dh || '');
            body.set('auth', keys.auth || '');
        }
        fetch(url, { method: 'POST', body: body }).catch(function () {});
    }

    function subscribe() {
        navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
            });
        }).then(function (sub) {
            postSubscription('ajax/push_subscribe.php', sub);
        }).catch(function (e) { console.warn('Push subscribe failed:', e); });
    }

    // Only prompt when permission hasn't been decided yet and the user hasn't
    // already dismissed it this browser — never re-ask once they've answered.
    if (Notification.permission === 'granted') {
        subscribe(); // e.g. re-subscribing on a new device/browser session
        return;
    }
    if (Notification.permission === 'denied') return;
    if (localStorage.getItem('ysms_push_prompt_dismissed') === '1') return;

    var bar = document.createElement('div');
    bar.className = 'push-prompt-bar';
    bar.innerHTML =
        '<i class="fa-solid fa-bell bell"></i>' +
        '<div class="ppb-text">Get notified instantly when a vessel is assigned or a status changes.</div>' +
        '<button type="button" class="ppb-enable">Enable</button>' +
        '<button type="button" class="ppb-dismiss">&times;</button>';
    document.body.appendChild(bar);

    bar.querySelector('.ppb-enable').addEventListener('click', function () {
        localStorage.setItem('ysms_push_prompt_dismissed', '1');
        bar.remove();
        Notification.requestPermission().then(function (perm) {
            if (perm === 'granted') subscribe();
        });
    });
    bar.querySelector('.ppb-dismiss').addEventListener('click', function () {
        localStorage.setItem('ysms_push_prompt_dismissed', '1');
        bar.remove();
    });
})();
</script>
<?php endif; ?>

</body>
</html>