<?php
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/page_helpers.php';
$pdo = getDB();
ensureAchievementsTable($pdo);

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM achievements WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('success', 'Achievement deleted');
    redirect('achievements.php');
}
if (isset($_GET['toggle'])) {
    $pdo->prepare('UPDATE achievements SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_GET['toggle']]);
    redirect('achievements.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [
        trim($_POST['icon'] ?? 'fa-award'),
        trim($_POST['value'] ?? ''),
        trim($_POST['label'] ?? ''),
        (int)($_POST['sort_order'] ?? 0),
        isset($_POST['is_active']) ? 1 : 0,
    ];
    if ($id > 0) {
        $pdo->prepare('UPDATE achievements SET icon=?, value=?, label=?, sort_order=?, is_active=? WHERE id=?')->execute([...$data, $id]);
        flash('success', 'Updated');
    } else {
        $pdo->prepare('INSERT INTO achievements (icon, value, label, sort_order, is_active) VALUES (?,?,?,?,?)')->execute($data);
        flash('success', 'Added');
    }
    redirect('achievements.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM achievements WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}
$items = $pdo->query('SELECT * FROM achievements ORDER BY sort_order')->fetchAll();
?>
<div class="card">
  <div class="card-title">
    Our Achievements <span style="font-weight:400;font-size:0.8rem;color:#64748b;">(shown on homepage — replace the placeholder values below with real figures)</span>
  </div>
</div>

<div class="card">
  <div class="card-title"><?= $edit ? 'Edit Achievement' : 'Add Achievement' ?>
    <?php if ($edit): ?><a href="achievements.php" class="btn btn-sm-action btn-secondary">Cancel</a><?php endif; ?>
  </div>
  <form method="POST"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-grid">
      <div class="form-group">
        <label>Icon (Font Awesome)</label>
        <input type="text" name="icon" value="<?= e($edit['icon'] ?? 'fa-award') ?>" placeholder="fa-ship">
      </div>
      <div class="form-group">
        <label>Value (big number)</label>
        <input type="text" name="value" value="<?= e($edit['value'] ?? '') ?>" placeholder="e.g. 15+ or 500+" required>
      </div>
      <div class="form-group full">
        <label>Label</label>
        <input type="text" name="label" value="<?= e($edit['label'] ?? '') ?>" placeholder="e.g. Years of Experience" required>
      </div>
      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="sort_order" value="<?= e($edit['sort_order'] ?? 0) ?>">
      </div>
      <div class="form-group" style="padding-top:1.5rem;">
        <label style="display:flex;align-items:center;gap:0.4rem;text-transform:none;">
          <input type="checkbox" name="is_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>> Active
        </label>
      </div>
    </div>
    <button type="submit" class="btn"><i class="fas fa-save"></i> Save</button>
  </form>
</div>

<div class="card">
  <div class="card-title">All Achievements</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Icon</th><th>Value</th><th>Label</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($items as $i): ?>
        <tr>
          <td><?= $i['sort_order'] ?></td>
          <td><i class="fas <?= e($i['icon']) ?>"></i></td>
          <td><?= e($i['value']) ?></td>
          <td><?= e($i['label']) ?></td>
          <td><a href="?toggle=<?= $i['id'] ?>"><span class="badge <?= $i['is_active']?'badge-success':'badge-muted' ?>"><?= $i['is_active']?'Active':'Hidden' ?></span></a></td>
          <td>
            <a href="?edit=<?= $i['id'] ?>" class="btn btn-sm-action btn-secondary"><i class="fas fa-edit"></i></a>
            <a href="?delete=<?= $i['id'] ?>" class="btn btn-sm-action btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
