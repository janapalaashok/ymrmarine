<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['eyebrow','title','title_highlight','subtitle','btn1_text','btn1_link','btn2_text','btn2_link',
               'stat1_value','stat1_label','stat2_value','stat2_label','stat3_value','stat3_label','stat4_value','stat4_label'];
    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');

    if (!empty($_FILES['bg_image']['name'])) {
        $path = uploadImage($_FILES['bg_image'], 'hero');
        if ($path) $data['bg_image'] = $path;
    }

    $sets = [];
    $vals = [];
    foreach ($data as $k => $v) {
        $sets[] = "$k = ?";
        $vals[] = $v;
    }
    $vals[] = 1;
    $pdo->prepare('UPDATE hero SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    flash('success', 'Hero section updated');
    redirect('hero.php');
}

$h = $pdo->query('SELECT * FROM hero WHERE id = 1')->fetch() ?: [];
?>
<div class="card">
  <div class="card-title">Hero Section</div>
  <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
    <div class="form-grid">
      <div class="form-group">
        <label>Eyebrow Text</label>
        <input type="text" name="eyebrow" value="<?= e($h['eyebrow'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Title Highlight Word</label>
        <input type="text" name="title_highlight" value="<?= e($h['title_highlight'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Main Title (full sentence)</label>
        <input type="text" name="title" value="<?= e($h['title'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Subtitle (HTML allowed for &lt;strong&gt;)</label>
        <textarea name="subtitle" rows="4"><?= e($h['subtitle'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>Button 1 Text</label>
        <input type="text" name="btn1_text" value="<?= e($h['btn1_text'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Button 1 Link</label>
        <input type="text" name="btn1_link" value="<?= e($h['btn1_link'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Button 2 Text</label>
        <input type="text" name="btn2_text" value="<?= e($h['btn2_text'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Button 2 Link</label>
        <input type="text" name="btn2_link" value="<?= e($h['btn2_link'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 1 Value</label>
        <input type="text" name="stat1_value" value="<?= e($h['stat1_value'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 1 Label</label>
        <input type="text" name="stat1_label" value="<?= e($h['stat1_label'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 2 Value</label>
        <input type="text" name="stat2_value" value="<?= e($h['stat2_value'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 2 Label</label>
        <input type="text" name="stat2_label" value="<?= e($h['stat2_label'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 3 Value</label>
        <input type="text" name="stat3_value" value="<?= e($h['stat3_value'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 3 Label</label>
        <input type="text" name="stat3_label" value="<?= e($h['stat3_label'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 4 Value</label>
        <input type="text" name="stat4_value" value="<?= e($h['stat4_value'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Stat 4 Label</label>
        <input type="text" name="stat4_label" value="<?= e($h['stat4_label'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Background Image</label>
        <input type="file" name="bg_image" accept="image/*">
        <?php if (!empty($h['bg_image'])): ?>
          <img src="../<?= e($h['bg_image']) ?>" class="img-preview" alt="BG">
        <?php endif; ?>
      </div>
    </div>
    <button type="submit" class="btn"><i class="fas fa-save"></i> Save Hero</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
