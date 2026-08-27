<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
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

// Companies that don't have a login yet (candidates for "link existing")
$unlinked_clients = $db->query("SELECT id, company_name, contact_person FROM clients WHERE user_id IS NULL ORDER BY company_name")->fetchAll();

$mode = 'new';
$existing_client_id = 0;
$company_name = $contact_person = $username = $mobile = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = ($_POST['mode'] ?? 'new') === 'existing' ? 'existing' : 'new';
    $existing_client_id = (int)($_POST['existing_client_id'] ?? 0);
    $company_name = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($mode === 'new' && $company_name === '') {
        $error = 'Company name is required.';
    } elseif ($mode === 'existing' && $existing_client_id <= 0) {
        $error = 'Please choose a company to link.';
    } elseif ($username === '' || $password === '' || $confirm === '') {
        $error = 'Username and password are required.';
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
            $roleId = (int)$db->query("SELECT id FROM roles WHERE name = 'Client' LIMIT 1")->fetchColumn();
            if ($roleId <= 0) {
                $error = 'Client role is not configured. Please contact the developer.';
            } else {
                try {
                    $db->beginTransaction();
                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $fullName = ($mode === 'new') ? $company_name : null; // set after we know the company name

                    if ($mode === 'existing') {
                        $cstmt = $db->prepare('SELECT company_name FROM clients WHERE id = ? AND user_id IS NULL');
                        $cstmt->execute([$existing_client_id]);
                        $cRow = $cstmt->fetch();
                        if (!$cRow) {
                            throw new RuntimeException('Selected company is no longer available to link.');
                        }
                        $fullName = $cRow['company_name'];
                    }

                    $insertUser = $db->prepare("
                        INSERT INTO users (role_id, username, password, full_name, phone, email, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'Active')
                    ");
                    $insertUser->execute([$roleId, $username, $hashed, $fullName, $mobile ?: null, $email ?: null]);
                    $newUserId = (int)$db->lastInsertId();

                    if ($mode === 'new') {
                        $insertClient = $db->prepare('INSERT INTO clients (user_id, company_name, contact_person) VALUES (?, ?, ?)');
                        $insertClient->execute([$newUserId, $company_name, $contact_person ?: null]);
                    } else {
                        $linkClient = $db->prepare('UPDATE clients SET user_id = ? WHERE id = ?');
                        $linkClient->execute([$newUserId, $existing_client_id]);
                    }

                    $db->commit();
                    $success = true;

                    try {
                        notifyAllAdmins($db, 'New client login added',
                            ($_SESSION['full_name'] ?? 'Admin') . ' added a client portal login (' . $fullName . ').',
                            'client', 'add_client.php', (int)($_SESSION['user_id'] ?? 0));
                    } catch (Throwable $ne) { error_log('add client notif: ' . $ne->getMessage()); }

                    $company_name = $contact_person = $username = $mobile = $email = '';
                    $existing_client_id = 0;
                    $unlinked_clients = $db->query("SELECT id, company_name, contact_person FROM clients WHERE user_id IS NULL ORDER BY company_name")->fetchAll();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('add_client insert: ' . $e->getMessage());
                    $error = 'Something went wrong while creating the client login. Please try again.';
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
    .surveyor-field input, .surveyor-field select { width: 100%; padding: 11px 13px; border: 1px solid var(--border-color); border-radius: 10px; background: #f8fafc; color: var(--text-dark); font-size: 13px; outline: none; }
    .surveyor-field input:focus, .surveyor-field select:focus { border-color: var(--accent-purple); background: #fff; }
    .surveyor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .client-mode-toggle { display: flex; gap: 10px; margin-bottom: 18px; }
    .client-mode-toggle label { flex: 1; text-align: center; padding: 10px; border: 1px solid var(--border-color); border-radius: 10px; font-size: 12px; font-weight: 650; cursor: pointer; }
    .client-mode-toggle input { margin-right: 6px; }
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
        <div class="surveyor-form-copy">Create a client portal login, either for a brand-new company or to link login access to an existing client company already in the system.</div>

        <?php if ($success): ?>
            <div class="alert alert-success py-2">Client login created successfully.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" id="addClientForm"><?= csrf_field() ?>
            <div class="client-mode-toggle">
                <label><input type="radio" name="mode" value="new" <?= $mode === 'new' ? 'checked' : '' ?> onchange="document.getElementById('newCompanyFields').style.display='block';document.getElementById('existingCompanyFields').style.display='none';"> New Company</label>
                <label><input type="radio" name="mode" value="existing" <?= $mode === 'existing' ? 'checked' : '' ?> onchange="document.getElementById('newCompanyFields').style.display='none';document.getElementById('existingCompanyFields').style.display='block';"> Link Existing Company</label>
            </div>

            <div id="newCompanyFields" style="display:<?= $mode === 'existing' ? 'none' : 'block' ?>;">
                <div class="surveyor-field">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" value="<?= sanitize($company_name) ?>">
                </div>
                <div class="surveyor-field">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" value="<?= sanitize($contact_person) ?>">
                </div>
            </div>
            <div id="existingCompanyFields" style="display:<?= $mode === 'existing' ? 'block' : 'none' ?>;">
                <div class="surveyor-field">
                    <label>Company *</label>
                    <select name="existing_client_id">
                        <option value="">Select a company…</option>
                        <?php foreach ($unlinked_clients as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $existing_client_id === (int)$c['id'] ? 'selected' : '' ?>><?= sanitize($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($unlinked_clients)): ?>
                        <div class="surveyor-form-copy" style="margin-top:6px;margin-bottom:0;">Every existing company already has a login, or none exist yet — use "New Company" instead.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="surveyor-grid">
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
                    <input type="email" name="email" value="<?= sanitize($email) ?>" placeholder="client@company.com">
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
            <button type="submit" class="btn-save-surveyor"><i class="fa-solid fa-user-plus me-1"></i> Create Client Login</button>
        </form>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
