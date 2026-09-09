<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
checkAuth();
// Add Client is available to Admin and Super Admin only — Surveyor and Client
// are blocked here (and can't reach this page through the UI either).
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin'], true)) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$error = '';
$success = false;

// Ensure optional columns exist on users (same safety-net pattern as add_surveyor.php)
try {
    $cols = [];
    foreach ($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower($c['Field'])] = true;
    }
    if (empty($cols['phone'])) $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(30) DEFAULT NULL");
    if (empty($cols['email'])) $db->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) DEFAULT NULL");
} catch (Exception $e) {
    error_log('add_client column ensure: ' . $e->getMessage());
}

$company_name = $contact_person = $username = $mobile = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name   = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $username       = trim($_POST['username'] ?? '');
    $mobile         = trim($_POST['mobile'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $confirm        = $_POST['confirm_password'] ?? '';

    if ($company_name === '' || $contact_person === '' || $username === '' || $mobile === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $check->execute([$username]);
        if ($check->fetchColumn()) {
            $error = 'Username already exists. Please choose another.';
        } else {
            $roleId = (int)$db->query("SELECT id FROM roles WHERE name = 'Client' LIMIT 1")->fetchColumn();
            if ($roleId <= 0) {
                $error = 'Client role is not configured. Please contact the developer.';
            } else {
                try {
                    $db->beginTransaction();

                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $insertUser = $db->prepare("
                        INSERT INTO users (role_id, username, password, full_name, phone, email, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'Active')
                    ");
                    $insertUser->execute([$roleId, $username, $hashed, $contact_person, $mobile, $email]);
                    $newUserId = (int)$db->lastInsertId();

                    $insertClient = $db->prepare('INSERT INTO clients (user_id, company_name, contact_person) VALUES (?, ?, ?)');
                    $insertClient->execute([$newUserId, $company_name, $contact_person]);

                    $db->commit();
                    $success = true;

                    try {
                        createNotification($db, $newUserId, 'Welcome to YSMS',
                            'Your client account has been created. You can log in to track your vessels here.',
                            'client', 'index.php', (int)($_SESSION['user_id'] ?? 0));
                        notifyAllAdmins($db, 'New client added',
                            ($_SESSION['full_name'] ?? 'Admin') . ' added a new client: ' . $company_name . '.',
                            'client', 'add_client.php', (int)($_SESSION['user_id'] ?? 0));
                    } catch (Throwable $ne) { error_log('add client notif: ' . $ne->getMessage()); }

                    $company_name = $contact_person = $username = $mobile = $email = '';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('add_client insert error: ' . $e->getMessage());
                    $error = 'Could not create the client account. Please try again.';
                }
            }
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
    <?php $page_title = 'Add Client'; $back_url = 'index.php'; $page_testid = 'add-client'; include 'includes/top_app_bar.php'; ?>
    <div class="surveyor-form-card">
        <div class="surveyor-form-title">Add Client</div>
        <div class="surveyor-form-copy">Create a client login. The client can sign in with this username and password to view their own vessels and reports.</div>

        <?php if ($success): ?>
            <div class="alert alert-success py-2">Client added successfully.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off"><?= csrf_field() ?>
            <div class="surveyor-grid">
                <div class="surveyor-field" style="grid-column:1 / -1;">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" value="<?= sanitize($company_name) ?>" required>
                </div>
                <div class="surveyor-field" style="grid-column:1 / -1;">
                    <label>Contact Person *</label>
                    <input type="text" name="contact_person" value="<?= sanitize($contact_person) ?>" required>
                </div>
                <div class="surveyor-field">
                    <label>Username *</label>
                    <input type="text" name="username" value="<?= sanitize($username) ?>" required autocomplete="off">
                </div>
                <div class="surveyor-field">
                    <label>Mobile *</label>
                    <input type="text" name="mobile" value="<?= sanitize($mobile) ?>" placeholder="10-digit mobile" required>
                </div>
                <div class="surveyor-field" style="grid-column:1 / -1;">
                    <label>Email *</label>
                    <input type="email" name="email" value="<?= sanitize($email) ?>" placeholder="client@company.com" required>
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
            <button type="submit" class="btn-save-surveyor"><i class="fa-solid fa-user-plus me-1"></i> Create Client</button>
        </form>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
