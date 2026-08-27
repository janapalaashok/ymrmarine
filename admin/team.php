<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM team WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('success', 'Member deleted');
    redirect('team.php');
}
if (isset($_GET['toggle'])) {
    $pdo->prepare('UPDATE team SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_GET['toggle']]);
    redirect('team.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $initials = trim($_POST['avatar_initials'] ?? 'YM');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $photo = null;

    if (!empty($_FILES['photo']['name'])) {
        $photo = uploadImage($_FILES['photo'], 'team');
    }

    if ($id > 0) {
        if ($photo) {
            $pdo->prepare('UPDATE team SET name=?, role=?, bio=?, avatar_initials=?, photo=?, sort_order=?, is_active=? WHERE id=?')
                ->execute([$name, $role, $bio, $initials, $photo, $sort, $active, $id]);
        } else {
            $pdo->prepare('UPDATE team SET name=?, role=?, bio=?, avatar_initials=?, sort_order=?, is_active=? WHERE id=?')
                ->execute([$name, $role, $bio, $initials, $sort, $active, $id]);
        }
        flash('success', 'Member updated');
    } else {
        $pdo->prepare('INSERT INTO team (name, role, bio, avatar_initials, photo, sort_order, is_active) VALUES (?,?,?,?,?,?,?)')
            ->execute([$name, $role, $bio, $initials, $photo, $sort, $active]);
        flash('success', 'Member added');
    }
    redirect('team.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM team WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}
$members = $pdo->query('SELECT * FROM team ORDER BY sort_order')->fetchAll();
?>
<div class="card">
  <div class="card-title"><?= $edit ? 'Edit Member' : 'Add Team Member' ?>
    <?php if ($edit): ?><a href="team.php" class="btn btn-sm-action btn-secondary">Cancel</a><?php endif; ?>
  </div>
  <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-grid">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Role / Designation</label>
        <input type="text" name="role" value="<?= e($edit['role'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Bio</label>
        <textarea name="bio" rows="3"><?= e($edit['bio'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>Avatar Initials (fallback)</label>
        <input type="text" name="avatar_initials" value="<?= e($edit['avatar_initials'] ?? '') ?>" maxlength="3">
      </div>
      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="sort_order" value="<?= e($edit['sort_order'] ?? 0) ?>">
      </div>
      <div class="form-group">
        <label>Photo</label>
        <input type="file" name="photo" accept="image/*">
        <?php if (!empty($edit['photo'])): ?><img src="../<?= e($edit['photo']) ?>" class="img-preview"><?php endif; ?>
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
  <div class="card-title">Team Members</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <tr>
          <td><?= $m['sort_order'] ?></td>
          <td><?= e($m['name']) ?></td>
          <td><?= e($m['role']) ?></td>
          <td><a href="?toggle=<?= $m['id'] ?>"><span class="badge <?= $m['is_active']?'badge-success':'badge-muted' ?>"><?= $m['is_active']?'Active':'Hidden' ?></span></a></td>
          <td>
            <a href="?edit=<?= $m['id'] ?>" class="btn btn-sm-action btn-secondary"><i class="fas fa-edit"></i></a>
            <a href="?delete=<?= $m['id'] ?>" class="btn btn-sm-action btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
