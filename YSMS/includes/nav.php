<?php
$current_page = basename($_SERVER['PHP_SELF']);
// Add Completed Vessel (assign_vessel.php?completed=1) opens from Admin Controls → Completed Surveys
if ($current_page === 'assign_vessel.php' && ($_GET['completed'] ?? '') === '1') $current_page = 'admin_controls.php';
// సెషన్‌లో ఉన్న యూజర్ రోల్‌ను తెచ్చుకోవడం (Admin లేదా Surveyor)
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$sidebar_user_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'User');

// Sidebar footer profile photo (DB → session → initial letter fallback)
$sidebar_avatar = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $db_nav = getDB();
        $stmt_nav = $db_nav->prepare("SELECT avatar, profile_pic FROM users WHERE id = ? LIMIT 1");
        $stmt_nav->execute([$_SESSION['user_id']]);
        $nav_user_row = $stmt_nav->fetch(PDO::FETCH_ASSOC);
        $fresh = null;
        if (!empty($nav_user_row['avatar'])) {
            $fresh = $nav_user_row['avatar'];
        } elseif (!empty($nav_user_row['profile_pic'])) {
            $fresh = $nav_user_row['profile_pic'];
        }
        if ($fresh && strpos($fresh, 'uploads/') === 0 && file_exists(__DIR__ . '/../' . $fresh)) {
            $sidebar_avatar = $fresh;
            $_SESSION['avatar'] = $fresh;
        }
    } catch (Exception $e) {
        if (!empty($_SESSION['avatar']) && strpos($_SESSION['avatar'], 'uploads/') === 0 && file_exists(__DIR__ . '/../' . $_SESSION['avatar'])) {
            $sidebar_avatar = $_SESSION['avatar'];
        }
    }
}
// Optional company logo: place file at assets/images/logo.png to replace the anchor icon
$sidebar_logo_path = 'assets/images/logo.png';
$sidebar_has_logo = is_file(__DIR__ . '/../' . $sidebar_logo_path);
?>

<div class="bottom-nav-bar" data-testid="global-navigation">
    <!-- Brand (desktop sidebar only) — logo image if present, else anchor icon -->
    <div class="desktop-sidebar-brand" data-testid="desktop-sidebar-brand">
        <div class="desktop-sidebar-mark<?= $sidebar_has_logo ? ' has-logo' : '' ?>">
            <?php if ($sidebar_has_logo): ?>
                <img src="<?= sanitize($sidebar_logo_path) ?>" alt="YMR Survey Management Logo" class="desktop-sidebar-logo-img">
            <?php else: ?>
                <i class="fa-solid fa-anchor" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
        <div class="desktop-sidebar-brand-text">
            <b>YMR</b>
            <span>SURVEY MANAGEMENT</span>
        </div>
        <button type="button" class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close navigation menu" data-testid="drawer-close-button">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="desktop-sidebar-nav-scroll">
        <?php /* Inbox and Team Chat open from the profile picture menu (includes/profile_dropdown.php) */ ?>
        <?php if ($user_role === 'Sales'): ?>
        <!-- Sales role (includes/sales.php): only its own pages; everything else is refused by config.php -->
        <div class="desktop-sidebar-section-label drawer-reveal">Sales</div>
        <a href="sales_dashboard.php" class="nav-item-btn <?= ($current_page == 'sales_dashboard.php') ? 'active' : '' ?>" data-testid="navigation-sales-dashboard-link">
            <i class="fa-solid fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        <a href="sales_clients.php" class="nav-item-btn <?= ($current_page == 'sales_clients.php') ? 'active' : '' ?>" data-testid="navigation-sales-clients-link">
            <i class="fa-solid fa-building"></i>
            <span>Clients</span>
        </a>
        <a href="sales_surveys.php" class="nav-item-btn <?= ($current_page == 'sales_surveys.php') ? 'active' : '' ?>" data-testid="navigation-sales-surveys-link">
            <i class="fa-solid fa-ship"></i>
            <span>Surveys</span>
        </a>
        <a href="assign_vessel.php" class="nav-item-btn <?= ($current_page == 'assign_vessel.php') ? 'active' : '' ?>" data-testid="navigation-sales-request-link">
            <i class="fa-solid fa-paper-plane"></i>
            <span>Request Survey</span>
        </a>
        <?php else: ?>
        <a href="index.php" class="nav-item-btn <?= ($current_page == 'index.php') ? 'active' : '' ?>" data-testid="navigation-home-link">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>
        <div class="desktop-sidebar-section-label drawer-reveal">Vessels Data</div>
        <a href="vessels.php" class="nav-item-btn <?= ($current_page == 'vessels.php') ? 'active' : '' ?>" data-testid="navigation-vessels-link">
            <i class="fa-solid fa-ship"></i>
            <span class="bn-long">Pending Vessels</span><span class="bn-short">Vessels</span>
        </a>

        <?php
        // Mobile only FAB — desktop uses expanded sidebar links below.
        // Admin, Super Admin and Client all get the FAB (its content in
        // includes/footer.php is role-aware per role: Super Admin sees Add
        // Client + Add Admin, Client sees Request Survey).
        if (in_array($user_role, ['Admin', 'Super Admin', 'Client'], true)):
        ?>
            <button class="center-fab-btn" id="globalFabTrigger" aria-label="Quick actions" aria-expanded="false" data-testid="global-add-menu-button">
                <i class="fa-solid fa-plus"></i>
            </button>
        <?php
        endif;
        ?>

        <a href="reports.php" class="nav-item-btn <?= ($current_page == 'reports.php') ? 'active' : '' ?>" data-testid="navigation-reports-link">
            <i class="fa-solid fa-file-invoice"></i>
            <span class="bn-long">Pending Reports</span><span class="bn-short">Reports</span>
        </a>
        <?php if ($user_role === 'Admin'): ?>
        <!-- Invoices (Admin): sidebar + drawer only, so the phone bottom bar keeps its 5 slots -->
        <a href="invoices.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'invoices.php') ? 'active' : '' ?>" data-testid="navigation-invoices-link">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span>Invoices</span>
            <b class="badge rounded-pill ms-auto nav-tag-badge" style="background:#16A34A;font-size:9.5px;letter-spacing:.04em" data-testid="nav-invoices-new-badge">NEW</b>
        </a>
        <?php endif; ?>
        <a href="completed.php" class="nav-item-btn <?= ($current_page == 'completed.php') ? 'active' : '' ?>" data-testid="navigation-completed-link">
            <i class="fa-solid fa-circle-check"></i>
            <span>Completed</span>
        </a>
        <?php if (in_array($user_role, ['Admin', 'Super Admin', 'Surveyor'], true)): ?>
        <!-- Missed Vessels (includes/missed_vessels.php): sidebar + drawer only, so the phone bottom bar keeps its slots -->
        <a href="missed.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'missed.php') ? 'active' : '' ?>" data-testid="navigation-missed-link">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Missed Vessels</span>
        </a>
        <?php endif; ?>
        <?php
        // Admin, Super Admin and Client: hide Cancelled on mobile bottom bar
        // only — the bar there is Home / Pending Vessels / FAB / Reports /
        // Completed — while keeping it reachable via the sidebar (drawer +
        // desktop). Surveyor: always show (same as before).
        $cancelled_bn_class = in_array($user_role, ['Admin', 'Super Admin', 'Client'], true) ? ' hide-cancelled-on-mobile-bn' : '';
        ?>
        <a href="cancelled.php" class="nav-item-btn<?= $cancelled_bn_class ?> <?= ($current_page == 'cancelled.php') ? 'active' : '' ?>" data-testid="navigation-cancelled-link">
            <i class="fa-solid fa-ban"></i>
            <span>Cancelled</span>
        </a>
        <a href="recovery.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'recovery.php') ? 'active' : '' ?>" data-testid="navigation-recovery-link">
            <i class="fa-solid fa-gas-pump"></i>
            <span>Recovery</span>
        </a>
        <?php if (in_array($user_role, ['Admin', 'Super Admin'], true)): ?>
        <!-- Monthly Data (Appointments / Survey Completed / Recovery of a month): sidebar + drawer only -->
        <a href="monthly_data.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'monthly_data.php') ? 'active' : '' ?>" data-testid="navigation-monthly-data-link">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Monthly Data</span>
        </a>
        <?php endif; ?>

        <?php if ($user_role === 'Admin'): ?>
        <!-- Desktop sidebar + mobile drawer. Sales Team, Word / Photo Templates, Possible Duplicates,
             Login Activity and Formats open from Admin Controls. -->
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Admin Access</div>
        <a href="assign_vessel.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'assign_vessel.php') ? 'active' : '' ?>" data-testid="sidebar-assign-vessel-link">
            <i class="fa-solid fa-ship"></i>
            <span>Assign Vessel</span>
        </a>
        <a href="admin_controls.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'admin_controls.php') ? 'active' : '' ?>" data-testid="sidebar-admin-controls-link">
            <i class="fa-solid fa-user-shield"></i>
            <span>Admin Controls</span>
        </a>
        <a href="add_surveyor.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'add_surveyor.php') ? 'active' : '' ?>" data-testid="sidebar-add-surveyor-link">
            <i class="fa-solid fa-user-gear"></i>
            <span>Add Surveyor</span>
        </a>
        <a href="add_client.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'add_client.php') ? 'active' : '' ?>" data-testid="sidebar-add-client-link">
            <i class="fa-solid fa-user-plus"></i>
            <span>Add Client</span>
        </a>

        <?php elseif ($user_role === 'Client'): ?>
        <!-- Desktop sidebar + mobile drawer: Client-only section. Request
             Survey mirrors the mobile FAB's "Request Survey" option so it's
             reachable from the desktop sidebar too (desktop hides the FAB
             entirely — see the "+" trigger comment below). -->
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Client</div>
        <a href="assign_vessel.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'assign_vessel.php') ? 'active' : '' ?>" data-testid="sidebar-request-survey-link">
            <i class="fa-solid fa-ship"></i>
            <span>Request Survey</span>
        </a>
        <?php elseif ($user_role === 'Super Admin'): ?>
        <!-- Desktop sidebar + mobile drawer: Super Admin Quick Actions — Add Client +
             Add Admin only (Assign Vessel / Admin Controls / Manage Templates /
             Add Surveyor stay Admin-only, both here and server-side on those pages). -->
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Quick Actions</div>
        <a href="add_client.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'add_client.php') ? 'active' : '' ?>" data-testid="sidebar-add-client-link">
            <i class="fa-solid fa-user-plus"></i>
            <span>Add Client</span>
        </a>
        <a href="add_admin.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'add_admin.php') ? 'active' : '' ?>" data-testid="sidebar-add-admin-link">
            <i class="fa-solid fa-user-shield"></i>
            <span>Add Admin</span>
        </a>
        <a href="super_admin_clients.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'super_admin_clients.php') ? 'active' : '' ?>" data-testid="sidebar-manage-clients-link">
            <i class="fa-solid fa-building"></i>
            <span>Manage Clients</span>
        </a>
        <a href="login_activity.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'login_activity.php') ? 'active' : '' ?>" data-testid="sidebar-login-activity-link">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Login Activity</span>
        </a>
        <!-- 🌟 TEMPORARY — remove this link (and super_admin_controls.php) once
             go-live test-data cleanup is done. -->
        <a href="super_admin_controls.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'super_admin_controls.php') ? 'active' : '' ?>" data-testid="sidebar-data-cleanup-link">
            <i class="fa-solid fa-trash"></i>
            <span>Data Cleanup (Temp)</span>
        </a>
        <?php endif; ?>

        <?php endif; /* not Sales */ ?>

        <?php if ($user_role !== 'Admin'): /* Admin: Formats opens from Admin Controls; profile from the sidebar footer */ ?>
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Account</div>
        <a href="profile.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'profile.php') ? 'active' : '' ?>" data-testid="sidebar-profile-link">
            <i class="fa-solid fa-user"></i>
            <span>My Profile</span>
        </a>
        <?php endif; ?>
    </div>

    <!-- Desktop only: profile photo + name (click → profile) + logout at bottom of sidebar -->
    <?php require_once __DIR__ . '/user_menu.php'; $sbu = userMenuState(); $sbu_unread = (int)$sbu['inbox'] + (int)$sbu['chat']; ?>
    <div class="desktop-sidebar-footer" data-testid="desktop-sidebar-footer">
        <!-- Profile menu (click the name / photo below): My Profile, Inbox, Team Chat -->
        <div class="sbu-menu" id="sbuMenu" role="menu" hidden data-testid="sidebar-profile-menu">
            <a href="profile.php" class="sbu-item" role="menuitem" data-testid="sidebar-menu-profile-link"><i class="fa-regular fa-user"></i><span>My Profile</span></a>
            <?php if ($sbu['inbox'] !== null): ?>
            <a href="inbox.php" class="sbu-item <?= $current_page === 'inbox.php' ? 'active' : '' ?>" role="menuitem" data-testid="sidebar-menu-inbox-link"><i class="fa-solid fa-inbox"></i><span>Inbox</span><?= userMenuCount($sbu['inbox']) ?></a>
            <?php endif; ?>
            <?php if ($sbu['chat'] !== null): ?>
            <a href="team_chat.php" class="sbu-item <?= $current_page === 'team_chat.php' ? 'active' : '' ?>" role="menuitem" data-testid="sidebar-menu-team-chat-link"><i class="fa-solid fa-comments"></i><span>Team Chat</span><?= userMenuCount($sbu['chat']) ?></a>
            <?php endif; ?>
        </div>
        <a href="profile.php" class="desktop-sidebar-user" data-testid="sidebar-profile-user-link" title="Profile menu" aria-haspopup="true" aria-expanded="false" aria-controls="sbuMenu" data-sbu-toggle>
            <?php if ($sidebar_avatar): ?>
                <img src="<?= sanitize($sidebar_avatar) ?>" alt="Profile" class="desktop-sidebar-user-avatar-img">
            <?php else: ?>
                <div class="desktop-sidebar-user-avatar"><?= strtoupper(substr($sidebar_user_name, 0, 1)) ?></div>
            <?php endif; ?>
            <div class="desktop-sidebar-user-meta">
                <div class="desktop-sidebar-user-name" title="<?= htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="desktop-sidebar-user-role"><?= htmlspecialchars($user_role ?: 'User', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <?php if ($sbu_unread > 0): ?><span class="pm-count" data-testid="sidebar-menu-unread"><?= $sbu_unread > 99 ? '99+' : $sbu_unread ?></span><?php endif; ?>
            <i class="fa-solid fa-chevron-up sbu-chev" aria-hidden="true"></i>
        </a>
        <script>
        (function () {
            var t = document.querySelector('[data-sbu-toggle]'), m = document.getElementById('sbuMenu');
            if (!t || !m) return;
            t.addEventListener('click', function (ev) {
                ev.preventDefault();
                m.hidden = !m.hidden;
                t.setAttribute('aria-expanded', String(!m.hidden));
                t.classList.toggle('open', !m.hidden);
            });
            document.addEventListener('click', function (ev) {
                if (m.hidden || ev.target.closest('[data-sbu-toggle]') || ev.target.closest('#sbuMenu')) return;
                m.hidden = true; t.setAttribute('aria-expanded', 'false'); t.classList.remove('open');
            });
        })();
        </script>
        <a href="coming_soon.php?feature=Support" class="mobile-drawer-support-link" data-testid="drawer-support-link">
            <i class="fa-solid fa-circle-question"></i>
            <span>Support</span>
        </a>
        <a href="logout.php" class="desktop-sidebar-logout" data-testid="sidebar-logout-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</div>
