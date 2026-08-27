<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
checkAuth();
if (empty($_SESSION['is_super_admin'])) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$error = '';
$success = false;

// Ensure the 'Super Admin' role exists (safety-net pattern used throughout this codebase)
try {
    $roleCheck = $db->prepare("SELECT id FROM roles WHERE name = 'Super Admin' LIMIT 1");
    $roleCheck->execute();
    if (!$roleCheck->fetchColumn()) {
        $db->exec("INSERT INTO roles (name) VALUES ('Super Admin')");
    }
} catch (Exception $e) {
    error_log('add_admin role ensure: ' . $e->getMessage());
}

$full_name = $username = $mobile = $email = '';
$make_super_admin = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $make_super_admin = !empty($_POST['is_super_admin']);

    if ($full_name === '' || $username === '' || $password === '' || $confirm === '') {
        $error = 'Full name, username and password are required.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $check = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $check->execute([$username]);
        if ($check->fetchColumn()) {
            $error = 'Username already exists. Please choose another.';
        } else {
            $roleName = $make_super_admin ? 'Super Admin' : 'Admin';
            $roleStmt = $db->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
            $roleStmt->execute([$roleName]);
            $roleId = (int)$roleStmt->fetchColumn();

            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $insert = $db->prepare("
                INSERT INTO users (role_id, username, password, full_name, phone, email, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Active')
            ");
            $insert->execute([$roleId, $username, $hashed, $full_name, $mobile ?: null, $email ?: null]);
            $success = true;

            try {
                notifyAllAdmins($db, 'New ' . $roleName . ' login added',
                    ($_SESSION['full_name'] ?? 'Super Admin') . ' added a new ' . strtolower($roleName) . ' account (' . $full_name . ').',
                    'admin', 'add_admin.php', (int)($_SESSION['user_id'] ?? 0));
            } catch (Throwable $ne) { error_log('add admin notif: ' . $ne->getMessage()); }

            $full_name = $username = $mobile = $email = '';
        }
    }
}
include 'includes/header.php';
?>
<style>
    .surveyor-form-card { max-width: 640px; margin: 24px auto; background: #fff; border: 1px solid var(--border-color); border-radius: 18px; padding: 24px; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
    .surveyor-form-title { font-size: 18px; font-weight: 700; color: var(--text-dark); margin-bottom: 4px; }
    .surveyor-form-copy { color: var(--text-muted); font-size: 12px; margin-bottom: 22px; }
    .surveyor-field { margin-bottom: 16px; }
    .surveyor-field label { display: block; margin-bottom: 6px; color: var(--text-muted); font-size: 12px; font-weight: 650; }
    .surveyor-field input { width: 100%; padding: 11px 13px; border: 1px solid var(--border-color); border-radius: 10px; background: #f8fafc; color: var(--text-dark); font-size: 13px; outline: none; }
    .surveyor-field input:focus { border-color: var(--accent-purple); background: #fff; }
    .surveyor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .super-admin-toggle { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border: 1px dashed var(--border-color); border-radius: 12px; margin-bottom: 18px; background: #fffbeb; }
    @media (max-width: 680px) {
        .surveyor-form-card { margin: 18px 16px; padding: 20px; }
        .surveyor-grid { grid-template-columns: 1fr; }
    }
    .btn-save-surveyor {
        width: 100%; background: #3b32b3; color: #fff; border: none; padding: 12px; border-radius: 12px;
        font-weight: 700; font-size: 14px; margin-top: 8px;
    }
</style>
<div class="scroll-content">
    <?php $page_title = 'Add Admin'; $back_url = 'index.php'; $page_testid = 'add-admin'; include 'includes/top_app_bar.php'; ?>
    <div class="surveyor-form-card">
        <div class="surveyor-form-title">Add Admin</div>
        <div class="surveyor-form-copy">Create another Admin login. Only Super Admins can create Admin logins, and only Super Admins can grant Super Admin access.</div>

        <?php if ($success): ?>
            <div class="alert alert-success py-2">Login created successfully.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off"><?= csrf_field() ?>
            <div class="surveyor-grid">
                <div class="surveyor-field" style="grid-column:1 / -1;">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" value="<?= sanitize($full_name) ?>" required>
                </div>
                <div class="surveyor-field">
                    <label>Username *</label>
                    <input type="text" name="username" value="<?= sanitize($username) ?>" required autocomplete="off">
                </div>
                <div class="surveyor-field">
                    <label>Mobile</label>
                    <input type="text" name="mobile" value="<?= sanitize($mobile) ?>" placeholder="10-digit mobile">
                </div>
                <div class="surveyor-field" style="grid-column:1 / -1;">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= sanitize($email) ?>" placeholder="admin@company.com">
                </div>
                <div class="surveyor-field">
                    <label>Password *</label>
                    <input type="password" name="password" required autocomplete="new-password">
                </div>
                <div class="surveyor-field">
                    <label>Re-enter Password *</label>
                    <input type="password" name="confirm_password" required autocomplete="new-password">
                </div>
            </div>
            <label class="super-admin-toggle">
                <input type="checkbox" name="is_super_admin" value="1" <?= $make_super_admin ? 'checked' : '' ?>>
                <span style="font-size:12px;font-weight:650;color:#92400e;">Also grant Super Admin access (can create other Admin logins)</span>
            </label>
            <button type="submit" class="btn-save-surveyor"><i class="fa-solid fa-user-shield me-1"></i> Create Login</button>
        </form>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
