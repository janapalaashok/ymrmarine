<?php
/**
 * Admin-only SMTP test page.
 * Open while logged in as Admin: /test_email.php
 */
require_once 'config/config.php';
require_once 'includes/mailer.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    die('Admin only.');
}

$result = '';
$resultType = 'info';
$to = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to_email'] ?? '');
    $name = trim($_POST['to_name'] ?? 'Test User');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = 'Enter a valid email address.';
        $resultType = 'danger';
    } else {
        $ok = sendHtmlEmail(
            $to,
            $name,
            'YSMS SMTP test',
            '<div style="font-family:Arial,sans-serif;padding:16px;"><h2>YSMS test email</h2><p>If you received this, SMTP is working.</p><p style="color:#64748b;font-size:12px;">Sent at ' . date('Y-m-d H:i:s') . '</p></div>',
            'YSMS test email — SMTP is working. Sent at ' . date('Y-m-d H:i:s')
        );
        if ($ok) {
            $result = 'SUCCESS — test email sent to ' . $to;
            $resultType = 'success';
        } else {
            $err = $GLOBALS['ysms_last_mail_error'] ?? 'Unknown error';
            $result = 'FAILED — ' . $err;
            $resultType = 'danger';
        }
    }
}

$cfg = [
    'SMTP_HOST' => defined('SMTP_HOST') ? SMTP_HOST : '?',
    'SMTP_PORT' => defined('SMTP_PORT') ? (string)SMTP_PORT : '?',
    'SMTP_SECURE' => defined('SMTP_SECURE') ? SMTP_SECURE : '?',
    'SMTP_USERNAME' => defined('SMTP_USERNAME') ? SMTP_USERNAME : '?',
    'SMTP_FROM_EMAIL' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '?',
    'SMTP_PASSWORD' => (defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '') ? ('(set, ' . strlen(SMTP_PASSWORD) . ' chars)') : '(EMPTY — this is why mail fails)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMTP Test | YSMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:520px;">
  <h1 class="h5 mb-3">YSMS SMTP Test</h1>
  <div class="card mb-3">
    <div class="card-body small">
      <div class="fw-semibold mb-2">Current config</div>
      <?php foreach ($cfg as $k => $v): ?>
        <div><code><?= htmlspecialchars($k) ?></code> = <?= htmlspecialchars((string)$v) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($result): ?>
    <div class="alert alert-<?= htmlspecialchars($resultType) ?>"><?= htmlspecialchars($result) ?></div>
  <?php endif; ?>
  <form method="POST" class="card card-body"><?= csrf_field() ?>
    <label class="form-label">Send test email to</label>
    <input type="email" name="to_email" class="form-control mb-2" required value="<?= htmlspecialchars($to) ?>" placeholder="you@example.com">
    <input type="text" name="to_name" class="form-control mb-3" value="Test User" placeholder="Name">
    <button class="btn btn-primary w-100">Send test email</button>
  </form>
  <a href="index.php" class="d-block text-center mt-3 small">Back</a>
</div>
</body>
</html>
