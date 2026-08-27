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

// Ensure optional columns exist on users
try {
    $cols = [];
    foreach ($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower($c['Field'])] = true;
    }
    if (empty($cols['first_name'])) $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(80) DEFAULT NULL AFTER full_name");
    if (empty($cols['last_name']))  $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(80) DEFAULT NULL AFTER first_name");
    if (empty($cols['dob']))        $db->exec("ALTER TABLE users ADD COLUMN dob DATE DEFAULT NULL");
    if (empty($cols['date_of_joining'])) $db->exec("ALTER TABLE users ADD COLUMN date_of_joining DATE DEFAULT NULL");
    if (empty($cols['phone']))      $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(30) DEFAULT NULL");
    if (empty($cols['email']))      $db->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) DEFAULT NULL");
} catch (Exception $e) {
    error_log('add_surveyor column ensure: ' . $e->getMessage());
}

$first_name = $last_name = $username = $mobile = $email = $dob = $doj = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $dob        = trim($_POST['dob'] ?? '');
    $doj        = trim($_POST['date_of_joining'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $mobile     = trim($_POST['mobile'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    $full_name = trim($first_name . ' ' . $last_name);

    if ($first_name === '' || $last_name === '' || $username === '' || $password === '' || $confirm === '') {
        $error = 'First name, last name, username and password are required.';
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
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $dobVal = ($dob !== '') ? $dob : null;
            $dojVal = ($doj !== '') ? $doj : null;
            $insert = $db->prepare("
                INSERT INTO users (role_id, username, password, full_name, first_name, last_name, dob, date_of_joining, phone, email, status)
                VALUES (2, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
            ");
            $insert->execute([$username, $hashed, $full_name, $first_name, $last_name, $dobVal, $dojVal, $mobile ?: null, $email ?: null]);
                        $success = true;
            try {
                $newId = (int)$db->lastInsertId();
                if ($newId > 0) {
                    createNotification($db, $newId, 'Welcome to YSMS',
                        'Your surveyor account has been created. Please update your profile.',
                        'surveyor', 'profile.php', (int)($_SESSION['user_id'] ?? 0));
                    notifyAllAdmins($db, 'New surveyor added',
                        ($_SESSION['full_name'] ?? 'Admin') . ' added a new surveyor account.',
                        'surveyor', 'add_surveyor.php', (int)($_SESSION['user_id'] ?? 0));
                }
            } catch (Throwable $ne) { error_log('add surveyor notif: '.$ne->getMessage()); }

            $first_name = $last_name = $username = $mobile = $email = $dob = $doj = '';
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
    <?php $page_title = 'Add Surveyor'; $back_url = 'index.php'; $page_testid = 'add-surveyor'; include 'includes/top_app_bar.php'; ?>
    <div class="surveyor-form-card">
        <div class="surveyor-form-title">Add Surveyor</div>
        <div class="surveyor-form-copy">Create a new surveyor login. Surveyors can later change first name, last name, DOB and password only from Profile.</div>

        <?php if ($success): ?>
            <div class="alert alert-success py-2">Surveyor added successfully.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off"><?= csrf_field() ?>
            <div class="surveyor-grid">
                <div class="surveyor-field">
                    <label>First Name *</label>
                    <input type="text" name="first_name" value="<?= sanitize($first_name) ?>" required>
                </div>
                <div class="surveyor-field">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" value="<?= sanitize($last_name) ?>" required>
                </div>
                <div class="surveyor-field">
                    <label>Date of Birth (optional)</label>
                    <input type="date" name="dob" value="<?= sanitize($dob) ?>">
                </div>
                <div class="surveyor-field">
                    <label>Date of Joining</label>
                    <input type="date" name="date_of_joining" value="<?= sanitize($doj) ?>">
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
                    <input type="email" name="email" value="<?= sanitize($email) ?>" placeholder="surveyor@company.com">
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
            <button type="submit" class="btn-save-surveyor"><i class="fa-solid fa-user-plus me-1"></i> Create Surveyor</button>
        </form>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
