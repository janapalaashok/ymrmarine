<?php
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps,
]);
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if (isLoggedIn()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $rateKey = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($user);
    $wait = rate_limit_check($rateKey);
    if (!csrf_valid()) {
        $error = 'Invalid username or password';
    } elseif ($wait > 0) {
        $error = 'Too many attempts. Please wait ' . $wait . ' seconds and try again.';
    } else {
        $stmt = getDB()->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$user]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($pass, $admin['password'])) {
            rate_limit_clear($rateKey);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['full_name'];
            redirect('index.php');
        }
        rate_limit_record_failure($rateKey);
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login | YMR Marine</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
:root { --navy:#0B1E2D; --accent:#02bbff; --cream:#F0EDE6; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Space Grotesk',sans-serif; background:linear-gradient(135deg,#0B1E2D 0%,#132C3F 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1rem; }
.login-card { background:#fff; border-radius:20px; padding:2.5rem; width:100%; max-width:400px; box-shadow:0 25px 60px rgba(0,0,0,0.35); }
.logo { text-align:center; margin-bottom:1.8rem; }
.logo span { font-size:1.6rem; font-weight:800; }
.logo .a { color:var(--accent); } .logo .b { color:var(--navy); }
h1 { font-size:1.3rem; color:var(--navy); text-align:center; margin-bottom:0.4rem; }
.sub { text-align:center; color:#55707F; font-size:0.88rem; margin-bottom:1.8rem; }
.form-group { margin-bottom:1.1rem; }
label { display:block; font-size:0.78rem; font-weight:600; color:#55707F; letter-spacing:0.06em; text-transform:uppercase; margin-bottom:0.4rem; }
input { width:100%; padding:0.8rem 1rem; border:1.5px solid #e0e8ef; border-radius:10px; font-family:inherit; font-size:0.95rem; transition:border-color .25s; }
input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(2,187,255,0.15); }
.btn { width:100%; background:var(--accent); color:var(--navy); border:none; padding:0.9rem; border-radius:50px; font-family:inherit; font-weight:700; font-size:0.95rem; cursor:pointer; margin-top:0.5rem; transition:background .25s, transform .25s; }
.btn:hover { background:#33d4ff; transform:translateY(-2px); }
.error { background:#fee2e2; color:#b91c1c; padding:0.7rem 1rem; border-radius:10px; font-size:0.88rem; margin-bottom:1rem; text-align:center; }
.hint { text-align:center; margin-top:1.2rem; font-size:0.8rem; color:#8DA8BC; }
</style>
</head>
<body>
<div class="login-card">
  <div class="logo"><span class="a">YMR</span> <span class="b">MARINE</span></div>
  <h1>Admin Panel</h1>
  <p class="sub">Sign in to manage website content</p>
  <?php if ($error): ?><div class="error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div><?php endif; ?>
  <form method="POST"><?= csrf_field() ?>
    <div class="form-group">
      <label>Username</label>
      <input type="text" name="username" required autofocus placeholder="admin">
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" required placeholder="••••••••">
    </div>
    <button type="submit" class="btn">Sign In <i class="fas fa-arrow-right"></i></button>
  </form>
</div>
</body>
</html>
