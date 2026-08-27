<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM testimonials WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('success', 'Testimonial deleted');
    redirect('testimonials.php');
}
if (isset($_GET['toggle'])) {
    $pdo->prepare('UPDATE testimonials SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_GET['toggle']]);
    redirect('testimonials.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $quote = trim($_POST['quote'] ?? '');
    $name = trim($_POST['author_name'] ?? '');
    $role = trim($_POST['author_role'] ?? '');
    $initials = trim($_POST['avatar_initials'] ?? 'CL');
    $rating = (float)($_POST['rating'] ?? 5);
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($id > 0) {
        $pdo->prepare('UPDATE testimonials SET quote=?, author_name=?, author_role=?, avatar_initials=?, rating=?, sort_order=?, is_active=? WHERE id=?')
            ->execute([$quote, $name, $role, $initials, $rating, $sort, $active, $id]);
        flash('success', 'Updated');
    } else {
        $pdo->prepare('INSERT INTO testimonials (quote, author_name, author_role, avatar_initials, rating, sort_order, is_active) VALUES (?,?,?,?,?,?,?)')
            ->execute([$quote, $name, $role, $initials, $rating, $sort, $active]);
        flash('success', 'Added');
    }
    redirect('testimonials.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM testimonials WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}
$items = $pdo->query('SELECT * FROM testimonials ORDER BY sort_order')->fetchAll();
?>
<div class="card">
  <div class="card-title"><?= $edit ? 'Edit Testimonial' : 'Add Testimonial' ?>
    <?php if ($edit): ?><a href="testimonials.php" class="btn btn-sm-action btn-secondary">Cancel</a><?php endif; ?>
  </div>
  <form method="POST"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <div class="form-grid">
      <div class="form-group full">
        <label>Quote</label>
        <textarea name="quote" rows="4" required><?= e($edit['quote'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>Author Name</label>
        <input type="text" name="author_name" value="<?= e($edit['author_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Author Role / Company</label>
        <input type="text" name="author_role" value="<?= e($edit['author_role'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Avatar Initials</label>
        <input type="text" name="avatar_initials" value="<?= e($edit['avatar_initials'] ?? '') ?>" maxlength="3">
      </div>
      <div class="form-group">
        <label>Rating (1–5)</label>
        <input type="number" name="rating" min="1" max="5" step="0.5" value="<?= e($edit['rating'] ?? 5) ?>">
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
  <div class="card-title">All Testimonials</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Author</th><th>Rating</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($items as $t): ?>
        <tr>
          <td><?= $t['sort_order'] ?></td>
          <td><?= e($t['author_name']) ?></td>
          <td><?= $t['rating'] ?> ★</td>
          <td><a href="?toggle=<?= $t['id'] ?>"><span class="badge <?= $t['is_active']?'badge-success':'badge-muted' ?>"><?= $t['is_active']?'Active':'Hidden' ?></span></a></td>
          <td>
            <a href="?edit=<?= $t['id'] ?>" class="btn btn-sm-action btn-secondary"><i class="fas fa-edit"></i></a>
            <a href="?delete=<?= $t['id'] ?>" class="btn btn-sm-action btn-danger" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
