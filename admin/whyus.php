<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM why_us WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('success', 'Item deleted');
    redirect('whyus.php');
}
if (isset($_GET['toggle'])) {
    $pdo->prepare('UPDATE why_us SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_GET['toggle']]);
    redirect('whyus.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [
        trim($_POST['title'] ?? ''),
        trim($_POST['body'] ?? ''),
        trim($_POST['icon'] ?? 'fa-check'),
        (int)($_POST['sort_order'] ?? 0),
        isset($_POST['is_active']) ? 1 : 0,
    ];
    if ($id > 0) {
        $pdo->prepare('UPDATE why_us SET title=?, body=?, icon=?, sort_order=?, is_active=? WHERE id=?')->execute([...$data, $id]);
        flash('success', 'Updated');
    } else {
        $pdo->prepare('INSERT INTO why_us (title, body, icon, sort_order, is_active) VALUES (?,?,?,?,?)')->execute($data);
        flash('success', 'Added');
    }
    redirect('whyus.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM why_us WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}
$items = $pdo->query('SELECT * FROM why_us ORDER BY sort_order')->fetchAll();
?>
<div class="card">
  <div class="card-title"><?= $edit ? 'Edit Item' : 'Add Why Us Item' ?>
    <?php if ($edit): ?><a href="whyus.php" class="btn btn-sm-action btn-secondary">Cancel</a><?php endif; ?>
  </div>
  <form method="POST"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-grid">
      <div class="form-group">
        <label>Title</label>
        <input type="text" name="title" value="<?= e($edit['title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Icon</label>
        <input type="text" name="icon" value="<?= e($edit['icon'] ?? 'fa-check') ?>">
      </div>
      <div class="form-group full">
        <label>Body</label>
        <textarea name="body" rows="3"><?= e($edit['body'] ?? '') ?></textarea>
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
  <div class="card-title">All Items</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Title</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($items as $i): ?>
        <tr>
          <td><?= $i['sort_order'] ?></td>
          <td><?= e($i['title']) ?></td>
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
