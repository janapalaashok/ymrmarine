<?php
// Change the signed-in admin's own password. The current password is asked again, the new one
// is checked (length, letter + number, not a default/easy one) and stored only as a hash.
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/rate_limit.php';

$pdo = getDB();
$forced = !empty($_SESSION['admin_must_change_pw']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPw = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $adminId = (int)$_SESSION['admin_id'];
    $rateKey = 'chpw|' . $adminId;

    $st = $pdo->prepare('SELECT username, password FROM admins WHERE id = ? LIMIT 1');
    $st->execute([$adminId]);
    $admin = $st->fetch();

    $wait = rate_limit_check($rateKey);
    if (!$admin) {
        session_destroy();
        redirect('login.php');
    } elseif ($wait > 0) {
        $error = 'Too many attempts. Please wait ' . $wait . ' seconds and try again.';
    } elseif (!password_verify($currentPw, $admin['password'])) {
        rate_limit_record_failure($rateKey);
        $error = 'The current password is not correct.';
    } elseif ($new !== $confirm) {
        $error = 'The new password and its confirmation do not match.';
    } elseif (hash_equals($currentPw, $new)) {
        $error = 'The new password must be different from the current one.';
    } elseif (($why = admin_password_problem($new, (string)$admin['username'])) !== '') {
        $error = $why;
    } else {
        rate_limit_clear($rateKey);
        $up = $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?');
        $up->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);
        session_regenerate_id(true);
        $_SESSION['admin_must_change_pw'] = false;
        flash('success', 'Your password has been changed. Use the new password next time you sign in.');
        redirect('index.php');
    }
}
?>
<div class="card" style="max-width:560px">
  <div class="card-title"><span><i class="fas fa-key"></i> Change Password</span></div>
  <?php if ($forced): ?>
  <div class="alert alert-error">You are still using the default (or an easy) password. Please set a new one — the other admin pages open after this.</div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="POST" autocomplete="off"><?= csrf_field() ?>
    <div class="form-group">
      <label>Current Password</label>
      <input type="password" name="current_password" required autocomplete="current-password">
    </div>
    <div class="form-group">
      <label>New Password</label>
      <input type="password" name="new_password" required minlength="10" maxlength="128" autocomplete="new-password">
      <small style="color:#55707F">At least 10 characters, with at least one letter and one number.</small>
    </div>
    <div class="form-group">
      <label>Confirm New Password</label>
      <input type="password" name="confirm_password" required minlength="10" maxlength="128" autocomplete="new-password">
    </div>
    <button type="submit" class="btn"><i class="fas fa-save"></i> Change Password</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
