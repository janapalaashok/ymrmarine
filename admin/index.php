<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

$counts = [
    'services' => $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn(),
    'team' => $pdo->query('SELECT COUNT(*) FROM team')->fetchColumn(),
    'ports' => $pdo->query('SELECT COUNT(*) FROM ports')->fetchColumn(),
    'testimonials' => $pdo->query('SELECT COUNT(*) FROM testimonials')->fetchColumn(),
    'messages' => $pdo->query('SELECT COUNT(*) FROM contact_submissions WHERE is_read = 0')->fetchColumn(),
];
$recent = $pdo->query('SELECT * FROM contact_submissions ORDER BY created_at DESC LIMIT 5')->fetchAll();
?>
<div class="stats-grid">
  <div class="stat-card"><div class="val"><?= $counts['services'] ?></div><div class="lbl">Services</div></div>
  <div class="stat-card"><div class="val"><?= $counts['team'] ?></div><div class="lbl">Team Members</div></div>
  <div class="stat-card"><div class="val"><?= $counts['ports'] ?></div><div class="lbl">Ports / Regions</div></div>
  <div class="stat-card"><div class="val"><?= $counts['testimonials'] ?></div><div class="lbl">Testimonials</div></div>
  <div class="stat-card"><div class="val"><?= $counts['messages'] ?></div><div class="lbl">Unread Messages</div></div>
</div>

<div class="card">
  <div class="card-title">
    Recent Contact Submissions
    <a href="messages.php" class="btn btn-sm-action btn-secondary">View All</a>
  </div>
  <?php if (empty($recent)): ?>
    <div class="empty"><i class="fas fa-inbox"></i><p>No messages yet</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Company</th><th>Service</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td><?= e($r['company']) ?></td>
          <td><?= e($r['service']) ?></td>
          <td><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
          <td><?php if (!$r['is_read']): ?><span class="badge badge-warning">New</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title">Quick Links</div>
  <div style="display:flex;flex-wrap:wrap;gap:0.75rem;">
    <a href="hero.php" class="btn btn-secondary"><i class="fas fa-home"></i> Edit Hero</a>
    <a href="services.php" class="btn btn-secondary"><i class="fas fa-ship"></i> Manage Services</a>
    <a href="team.php" class="btn btn-secondary"><i class="fas fa-users"></i> Manage Team</a>
    <a href="settings.php" class="btn btn-secondary"><i class="fas fa-cog"></i> Site Settings</a>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
