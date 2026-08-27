<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM ports WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('success', 'Port deleted');
    redirect('ports.php');
}
if (isset($_GET['toggle'])) {
    $pdo->prepare('UPDATE ports SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_GET['toggle']]);
    redirect('ports.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $state = trim($_POST['state'] ?? 'All Ports');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($id > 0) {
        $pdo->prepare('UPDATE ports SET name=?, state=?, sort_order=?, is_active=? WHERE id=?')->execute([$name, $state, $sort, $active, $id]);
        flash('success', 'Updated');
    } else {
        $pdo->prepare('INSERT INTO ports (name, state, sort_order, is_active) VALUES (?,?,?,?)')->execute([$name, $state, $sort, $active]);
        flash('success', 'Added');
    }
    redirect('ports.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM ports WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}
$ports = $pdo->query('SELECT * FROM ports ORDER BY sort_order')->fetchAll();
?>
<div class="card">
  <div class="card-title"><?= $edit ? 'Edit Port' : 'Add Port / Region' ?>
    <?php if ($edit): ?><a href="ports.php" class="btn btn-sm-action btn-secondary">Cancel</a><?php endif; ?>
  </div>
  <form method="POST"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-grid">
      <div class="form-group">
        <label>Name (Country / Region)</label>
        <input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>State / Subtext</label>
        <input type="text" name="state" value="<?= e($edit['state'] ?? 'All Ports') ?>">
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
  <div class="card-title">All Ports / Regions</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>State</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($ports as $p): ?>
        <tr>
          <td><?= $p['sort_order'] ?></td>
          <td><?= e($p['name']) ?></td>
          <td><?= e($p['state']) ?></td>
          <td><a href="?toggle=<?= $p['id'] ?>"><span class="badge <?= $p['is_active']?'badge-success':'badge-muted' ?>"><?= $p['is_active']?'Active':'Hidden' ?></span></a></td>
          <td>
            <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm-action btn-secondary"><i class="fas fa-edit"></i></a>
            <a href="?delete=<?= $p['id'] ?>" class="btn btn-sm-action btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
