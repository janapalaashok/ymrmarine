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

</body>
</html>