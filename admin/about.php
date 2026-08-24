<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['tag','title','body','body2','cert_title','cert_subtitle',
               'stat1_value','stat1_label','stat2_value','stat2_label','stat3_value','stat3_label','stat4_value','stat4_label'];
    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');

    if (!empty($_FILES['img_main']['name'])) {
        $path = uploadImage($_FILES['img_main'], 'about');
        if ($path) $data['img_main'] = $path;
    }
    if (!empty($_FILES['img_secondary']['name'])) {
        $path = uploadImage($_FILES['img_secondary'], 'about');
        if ($path) $data['img_secondary'] = $path;
    }

    $sets = []; $vals = [];
    foreach ($data as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
    $vals[] = 1;
    $pdo->prepare('UPDATE about SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    flash('success', 'About section updated');
    redirect('about.php');
}

$a = $pdo->query('SELECT * FROM about WHERE id = 1')->fetch() ?: [];
?>
<div class="card">
  <div class="card-title">About Section</div>
  <form method="POST" enctype="multipart/form-data">
    <div class="form-grid">
      <div class="form-group">
        <label>Tag</label>
        <input type="text" name="tag" value="<?= e($a['tag'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Title</label>
        <input type="text" name="title" value="<?= e($a['title'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Main Body</label>
        <textarea name="body" rows="8"><?= e($a['body'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Secondary Paragraph</label>
        <textarea name="body2" rows="3"><?= e($a['body2'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>Cert Title</label>
        <input type="text" name="cert_title" value="<?= e($a['cert_title'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Cert Subtitle</label>
        <input type="text" name="cert_subtitle" value="<?= e($a['cert_subtitle'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 1 Value / Label</label>
        <input type="text" name="stat1_value" value="<?= e($a['stat1_value'] ?? '') ?>" placeholder="Value">
        <input type="text" name="stat1_label" value="<?= e($a['stat1_label'] ?? '') ?>" placeholder="Label" style="margin-top:0.4rem">
      </div>
      <div class="form-group">
        <label>Stat 2 Value / Label</label>
        <input type="text" name="stat2_value" value="<?= e($a['stat2_value'] ?? '') ?>" placeholder="Value">
        <input type="text" name="stat2_label" value="<?= e($a['stat2_label'] ?? '') ?>" placeholder="Label" style="margin-top:0.4rem">
      </div>
      <div class="form-group">
        <label>Stat 3 Value / Label</label>
        <input type="text" name="stat3_value" value="<?= e($a['stat3_value'] ?? '') ?>" placeholder="Value">
        <input type="text" name="stat3_label" value="<?= e($a['stat3_label'] ?? '') ?>" placeholder="Label" style="margin-top:0.4rem">
      </div>
      <div class="form-group">
        <label>Stat 4 Value / Label</label>
        <input type="text" name="stat4_value" value="<?= e($a['stat4_value'] ?? '') ?>" placeholder="Value">
        <input type="text" name="stat4_label" value="<?= e($a['stat4_label'] ?? '') ?>" placeholder="Label" style="margin-top:0.4rem">
      </div>
      <div class="form-group">
        <label>Main Image</label>
        <input type="file" name="img_main" accept="image/*">
        <?php if (!empty($a['img_main'])): ?><img src="../<?= e($a['img_main']) ?>" class="img-preview"><?php endif; ?>
      </div>
      <div class="form-group">
        <label>Secondary Image</label>
        <input type="file" name="img_secondary" accept="image/*">
        <?php if (!empty($a['img_secondary'])): ?><img src="../<?= e($a['img_secondary']) ?>" class="img-preview"><?php endif; ?>
      </div>
    </div>
    <button type="submit" class="btn"><i class="fas fa-save"></i> Save About</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
