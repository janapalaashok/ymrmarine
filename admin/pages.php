<?php
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/page_helpers.php';

$pdo = getDB();
ensurePageContentTable($pdo);
seedPageContentIfNeeded($pdo);

$tab = $_GET['tab'] ?? 'about';
if (!in_array($tab, ['about', 'ports', 'contact'], true)) $tab = 'about';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['page_key'] ?? 'about';
    if (!in_array($key, ['about', 'ports', 'contact'], true)) $key = 'about';

    $existing = getPageContent($pdo, $key);
    $heroImage = $existing['hero_image'] ?? '';

    if (!empty($_FILES['hero_image_file']['name'])) {
        $up = uploadImage($_FILES['hero_image_file'], 'pages');
        if ($up) $heroImage = $up;
    }
    if (!empty($_POST['clear_hero_image'])) $heroImage = '';

    savePageContent($pdo, $key, [
        'meta_title' => trim($_POST['meta_title'] ?? ''),
        'meta_description' => trim($_POST['meta_description'] ?? ''),
        'meta_keywords' => trim($_POST['meta_keywords'] ?? ''),
        'hero_image' => $heroImage,
        'hero_subtitle' => trim($_POST['hero_subtitle'] ?? ''),
        'body' => trim($_POST['body'] ?? ''),
        'body2' => trim($_POST['body2'] ?? ''),
        'cta_text' => trim($_POST['cta_text'] ?? ''),
    ]);
    flash('success', ucfirst($key) . ' page saved');
    redirect('pages.php?tab=' . $key);
}

$pc = getPageContent($pdo, $tab);
$viewMap = ['about' => '../about-us.php', 'ports' => '../ports.php', 'contact' => '../contact.php'];
?>
<style>
  .tab-bar { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.25rem; }
  .tab-bar a {
    padding:0.5rem 1rem; border-radius:8px; text-decoration:none; font-size:0.88rem; font-weight:600;
    background:#f1f5f9; color:#334155; border:1px solid #e2e8f0;
  }
  .tab-bar a.active { background:#02bbff; color:#fff; border-color:#02bbff; }
  .hint { font-size:0.78rem; color:#64748b; margin-top:0.25rem; }
  .img-preview { max-width:220px; max-height:110px; border-radius:8px; margin-top:0.5rem; object-fit:cover; display:block; }
  .view-link { font-size:0.85rem; color:#02bbff; margin-left:0.75rem; text-decoration:none; }
</style>

<div class="card">
  <div class="card-title">
    Static Pages (SEO + Content)
    <a href="<?= e($viewMap[$tab]) ?>" target="_blank" class="view-link"><i class="fas fa-external-link-alt"></i> View page</a>
  </div>

  <div class="tab-bar">
    <a href="?tab=about" class="<?= $tab==='about'?'active':'' ?>">About</a>
    <a href="?tab=ports" class="<?= $tab==='ports'?'active':'' ?>">Ports</a>
    <a href="?tab=contact" class="<?= $tab==='contact'?'active':'' ?>">Contact</a>
  </div>

  <p class="hint" style="margin-bottom:1rem;">
    <?php if ($tab === 'about'): ?>
      About page text & SEO. Homepage About section images/stats are still edited under <strong>About</strong> in the sidebar.
    <?php elseif ($tab === 'ports'): ?>
      Ports page intro & SEO. Individual port names are managed under <strong>Ports</strong> in the sidebar.
    <?php else: ?>
      Contact page SEO & intro. Phone, email, address and map are under <strong>Site Settings</strong>.
    <?php endif; ?>
  </p>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="page_key" value="<?= e($tab) ?>">
    <div class="form-grid">
      <div class="form-group full">
        <label>Meta Title</label>
        <input type="text" name="meta_title" value="<?= e($pc['meta_title'] ?? '') ?>" maxlength="200">
      </div>
      <div class="form-group full">
        <label>Meta Description</label>
        <textarea name="meta_description" rows="2"><?= e($pc['meta_description'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Meta Keywords</label>
        <input type="text" name="meta_keywords" value="<?= e($pc['meta_keywords'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Hero subtitle</label>
        <textarea name="hero_subtitle" rows="2"><?= e($pc['hero_subtitle'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>Hero background image</label>
        <input type="file" name="hero_image_file" accept="image/*">
        <div class="hint">Optional. Leave empty to keep current / default image.</div>
        <?php if (!empty($pc['hero_image'])): ?>
          <img src="<?= strpos($pc['hero_image'],'http')===0 ? e($pc['hero_image']) : '../'.e($pc['hero_image']) ?>" class="img-preview" alt="">
          <label style="display:flex;align-items:center;gap:0.35rem;margin-top:0.4rem;font-size:0.82rem;text-transform:none;">
            <input type="checkbox" name="clear_hero_image" value="1"> Remove (use default)
          </label>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>CTA button text</label>
        <input type="text" name="cta_text" value="<?= e($pc['cta_text'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Main body text</label>
        <textarea name="body" rows="6"><?= e($pc['body'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Second paragraph (optional)</label>
        <textarea name="body2" rows="4"><?= e($pc['body2'] ?? '') ?></textarea>
      </div>
    </div>
    <button type="submit" class="btn"><i class="fas fa-save"></i> Save <?= e(ucfirst($tab)) ?> Page</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
