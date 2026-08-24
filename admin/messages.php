<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

if (isset($_GET['read'])) {
    $pdo->prepare('UPDATE contact_submissions SET is_read = 1 WHERE id = ?')->execute([(int)$_GET['read']]);
    redirect('messages.php');
}
if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM contact_submissions WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('success', 'Message deleted');
    redirect('messages.php');
}
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare('SELECT * FROM contact_submissions WHERE id = ?');
    $stmt->execute([(int)$_GET['view']]);
    $msg = $stmt->fetch();
    if ($msg && !$msg['is_read']) {
        $pdo->prepare('UPDATE contact_submissions SET is_read = 1 WHERE id = ?')->execute([$msg['id']]);
    }
}

$messages = $pdo->query('SELECT * FROM contact_submissions ORDER BY created_at DESC')->fetchAll();
?>
<?php if (!empty($msg)): ?>
<div class="card">
  <div class="card-title">
    Message from <?= e($msg['name']) ?>
    <a href="messages.php" class="btn btn-sm-action btn-secondary">Back</a>
  </div>
  <div style="display:grid;gap:0.6rem;font-size:0.95rem;">
    <p><strong>Company:</strong> <?= e($msg['company']) ?></p>
    <p><strong>Email:</strong> <a href="mailto:<?= e($msg['email']) ?>"><?= e($msg['email']) ?></a></p>
    <p><strong>Phone:</strong> <?= e($msg['phone']) ?></p>
    <p><strong>Service:</strong> <?= e($msg['service']) ?></p>
    <p><strong>Port:</strong> <?= e($msg['port']) ?></p>
    <p><strong>Date:</strong> <?= date('d M Y, H:i', strtotime($msg['created_at'])) ?></p>
    <hr style="border:none;border-top:1px solid #e2eaf0;margin:0.5rem 0;">
    <p><strong>Message:</strong></p>
    <p style="white-space:pre-wrap;background:#f8fafc;padding:1rem;border-radius:8px;"><?= e($msg['message']) ?></p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-title">Contact Form Submissions (<?= count($messages) ?>)</div>
  <?php if (empty($messages)): ?>
    <div class="empty"><i class="fas fa-inbox"></i><p>No messages yet</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Date</th><th>Name</th><th>Company</th><th>Service</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($messages as $m): ?>
        <tr style="<?= !$m['is_read'] ? 'font-weight:600;' : '' ?>">
          <td><?= date('d M Y', strtotime($m['created_at'])) ?></td>
          <td><?= e($m['name']) ?></td>
          <td><?= e($m['company']) ?></td>
          <td><?= e($m['service']) ?></td>
          <td><?= $m['is_read'] ? '<span class="badge badge-muted">Read</span>' : '<span class="badge badge-warning">New</span>' ?></td>
          <td style="white-space:nowrap;">
            <a href="?view=<?= $m['id'] ?>" class="btn btn-sm-action btn-secondary"><i class="fas fa-eye"></i></a>
            <a href="?delete=<?= $m['id'] ?>" class="btn btn-sm-action btn-danger" onclick="return confirm('Delete this message?')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
