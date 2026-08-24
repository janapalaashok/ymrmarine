<?php
$current_page = basename($_SERVER['PHP_SELF']);
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
        <div class="desktop-sidebar-mark">
            <?php if ($sidebar_has_logo): ?>
                <img src="<?= sanitize($sidebar_logo_path) ?>" alt="YSMS Logo" class="desktop-sidebar-logo-img">
            <?php else: ?>
                <i class="fa-solid fa-anchor" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
        <div class="desktop-sidebar-brand-text">
            <b>YSMS</b>
            <span>Survey Management</span>
        </div>
    </div>

    <div class="desktop-sidebar-nav-scroll">
        <div class="desktop-sidebar-section-label">Main</div>
        <a href="index.php" class="nav-item-btn <?= ($current_page == 'index.php') ? 'active' : '' ?>" data-testid="navigation-home-link">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>
        <a href="vessels.php" class="nav-item-btn <?= ($current_page == 'vessels.php') ? 'active' : '' ?>" data-testid="navigation-vessels-link">
            <i class="fa-solid fa-ship"></i>
            <span>Pending Vessels</span>
        </a>

        <?php
        // Mobile only FAB — desktop uses expanded sidebar links below
        if ($user_role === 'Admin'):
        ?>
            <button class="center-fab-btn" id="globalFabTrigger" data-testid="global-add-menu-button">
                <i class="fa-solid fa-plus"></i>
            </button>
        <?php
        endif;
        ?>

        <a href="reports.php" class="nav-item-btn <?= ($current_page == 'reports.php') ? 'active' : '' ?>" data-testid="navigation-reports-link">
            <i class="fa-solid fa-file-invoice"></i>
            <span>Pending Reports</span>
        </a>
        <a href="completed.php" class="nav-item-btn <?= ($current_page == 'completed.php') ? 'active' : '' ?>" data-testid="navigation-completed-link">
            <i class="fa-solid fa-circle-check"></i>
            <span>Completed</span>
        </a>
        <?php
        // Admin: hide Cancelled on mobile bottom bar only; keep in sidebar (drawer + desktop).
        // Surveyor: always show (same as before).
        $cancelled_bn_class = ($user_role === 'Admin') ? ' hide-cancelled-on-mobile-bn' : '';
        ?>
        <a href="cancelled.php" class="nav-item-btn<?= $cancelled_bn_class ?> <?= ($current_page == 'cancelled.php') ? 'active' : '' ?>" data-testid="navigation-cancelled-link">
            <i class="fa-solid fa-ban"></i>
            <span>Cancelled</span>
        </a>

        <?php if ($user_role === 'Admin'): ?>
        <!-- Desktop only: FAB Quick Actions as sidebar links -->
        <div class="desktop-sidebar-section-label desktop-only-nav">Quick Actions</div>
        <a href="assign_vessel.php" class="nav-item-btn desktop-only-nav <?= ($current_page == 'assign_vessel.php') ? 'active' : '' ?>" data-testid="sidebar-assign-vessel-link">
            <i class="fa-solid fa-ship"></i>
            <span>Assign Vessel</span>
        </a>
        <a href="add_surveyor.php" class="nav-item-btn desktop-only-nav <?= ($current_page == 'add_surveyor.php') ? 'active' : '' ?>" data-testid="sidebar-add-surveyor-link">
            <i class="fa-solid fa-user-gear"></i>
            <span>Add Surveyor</span>
        </a>
        <a href="coming_soon.php?feature=Add+Client" class="nav-item-btn desktop-only-nav" data-testid="sidebar-add-client-link">
            <i class="fa-solid fa-user-plus"></i>
            <span>Add Client</span>
        </a>
        <a href="admin_controls.php" class="nav-item-btn desktop-only-nav <?= ($current_page == 'admin_controls.php') ? 'active' : '' ?>" data-testid="sidebar-admin-controls-link">
            <i class="fa-solid fa-user-shield"></i>
            <span>Admin Controls</span>
        </a>
        
        <!-- Inside the navbar.php file, inside the <ul> tag, add this li: -->

    <a href="manage_templates.php" class="nav-item-btn desktop-only-nav <?= ($current_page == 'manage_templates.php') ? 'active' : '' ?>" data-testid="sidebar-manage-templates-link">
        <i class="fa-solid fa-file-contract"></i>
        <span>Manage Templates</span>
    </a>

        <?php endif; ?>

        <div class="desktop-sidebar-section-label desktop-only-nav">Account</div>
        <a href="profile.php" class="nav-item-btn desktop-only-nav <?= ($current_page == 'profile.php') ? 'active' : '' ?>" data-testid="sidebar-profile-link">
            <i class="fa-solid fa-user"></i>
            <span>My Profile</span>
        </a>
    </div>

    <!-- Desktop only: profile photo + name (click → profile) + logout at bottom of sidebar -->
    <div class="desktop-sidebar-footer" data-testid="desktop-sidebar-footer">
        <a href="profile.php" class="desktop-sidebar-user" data-testid="sidebar-profile-user-link" title="My Profile">
            <?php if ($sidebar_avatar): ?>
                <img src="<?= sanitize($sidebar_avatar) ?>" alt="Profile" class="desktop-sidebar-user-avatar-img">
            <?php else: ?>
                <div class="desktop-sidebar-user-avatar"><?= strtoupper(substr($sidebar_user_name, 0, 1)) ?></div>
            <?php endif; ?>
            <div class="desktop-sidebar-user-meta">
                <div class="desktop-sidebar-user-name" title="<?= htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="desktop-sidebar-user-role"><?= htmlspecialchars($user_role ?: 'User', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </a>
        <a href="logout.php" class="desktop-sidebar-logout" data-testid="sidebar-logout-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</div>
