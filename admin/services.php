<?php
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/service_helpers.php';

$pdo = getDB();
ensureServicePageColumns($pdo);
seedServicePagesIfNeeded($pdo);

// Delete
if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('success', 'Service deleted');
    redirect('services.php');
}

// Toggle active
if (isset($_GET['toggle'])) {
    $pdo->prepare('UPDATE services SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_GET['toggle']]);
    redirect('services.php');
}

// Add / Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);

    $title = trim($_POST['title'] ?? '');
    $slug  = trim($_POST['slug'] ?? '');
    if ($slug === '') $slug = serviceSlugify($title);
    else $slug = serviceSlugify($slug);

    $features = [];
    $fTitles = $_POST['feat_title'] ?? [];
    $fBodies = $_POST['feat_body'] ?? [];
    $fIcons  = $_POST['feat_icon'] ?? [];
    foreach ($fTitles as $i => $t) {
        $t = trim($t);
        if ($t === '') continue;
        $features[] = [
            'icon'  => trim($fIcons[$i] ?? 'fa-check'),
            'title' => $t,
            'body'  => trim($fBodies[$i] ?? ''),
        ];
    }

    $process = [];
    $pTitles = $_POST['proc_title'] ?? [];
    $pBodies = $_POST['proc_body'] ?? [];
    foreach ($pTitles as $i => $t) {
        $t = trim($t);
        if ($t === '') continue;
        $process[] = ['title' => $t, 'body' => trim($pBodies[$i] ?? '')];
    }

    $faq = [];
    $qList = $_POST['faq_q'] ?? [];
    $aList = $_POST['faq_a'] ?? [];
    foreach ($qList as $i => $q) {
        $q = trim($q);
        if ($q === '') continue;
        $faq[] = ['q' => $q, 'a' => trim($aList[$i] ?? '')];
    }

    $heroImage = trim($_POST['existing_hero_image'] ?? '');
    $pageImage = trim($_POST['existing_page_image'] ?? '');

    if (!empty($_FILES['hero_image_file']['name'])) {
        $up = uploadImage($_FILES['hero_image_file'], 'services');
        if ($up) $heroImage = $up;
    }
    if (!empty($_FILES['page_image_file']['name'])) {
        $up = uploadImage($_FILES['page_image_file'], 'services');
        if ($up) $pageImage = $up;
    }

    if (!empty($_POST['clear_hero_image'])) $heroImage = '';
    if (!empty($_POST['clear_page_image'])) $pageImage = '';

    $data = [
        trim($_POST['code'] ?? ''),
        $title,
        $slug,
        trim($_POST['description'] ?? ''),
        trim($_POST['icon'] ?? 'fa-ship'),
        (int)($_POST['sort_order'] ?? 0),
        isset($_POST['is_featured']) ? 1 : 0,
        trim($_POST['badge'] ?? '') ?: null,
        isset($_POST['is_active']) ? 1 : 0,
        trim($_POST['meta_title'] ?? ''),
        trim($_POST['meta_description'] ?? ''),
        trim($_POST['meta_keywords'] ?? ''),
        $heroImage,
        trim($_POST['hero_subtitle'] ?? ''),
        trim($_POST['overview_title'] ?? ''),
        trim($_POST['overview_body'] ?? ''),
        trim($_POST['overview_body2'] ?? ''),
        json_encode($features, JSON_UNESCAPED_UNICODE),
        json_encode($process, JSON_UNESCAPED_UNICODE),
        json_encode($faq, JSON_UNESCAPED_UNICODE),
        trim($_POST['who_body'] ?? ''),
        $pageImage,
        trim($_POST['cta_text'] ?? ''),
    ];

    try {
        if ($id > 0) {
            $pdo->prepare('UPDATE services SET
                code=?, title=?, slug=?, description=?, icon=?, sort_order=?, is_featured=?, badge=?, is_active=?,
                meta_title=?, meta_description=?, meta_keywords=?, hero_image=?, hero_subtitle=?,
                overview_title=?, overview_body=?, overview_body2=?, features_json=?, process_json=?, faq_json=?,
                who_body=?, page_image=?, cta_text=?
                WHERE id=?')->execute([...$data, $id]);
            flash('success', 'Service page updated');
        } else {
            $pdo->prepare('INSERT INTO services (
                code, title, slug, description, icon, sort_order, is_featured, badge, is_active,
                meta_title, meta_description, meta_keywords, hero_image, hero_subtitle,
                overview_title, overview_body, overview_body2, features_json, process_json, faq_json,
                who_body, page_image, cta_text
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($data);
            flash('success', 'Service added');
        }
    } catch (Exception $e) {
        flash('error', 'Save failed: ' . $e->getMessage());
    }
    redirect('services.php' . ($id ? '?edit=' . $id : ''));
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

$services = $pdo->query('SELECT * FROM services ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);

$editFeatures = [];
$editProcess  = [];
$editFaq      = [];
if ($edit) {
    $editFeatures = json_decode($edit['features_json'] ?? '[]', true) ?: [];
    $editProcess  = json_decode($edit['process_json'] ?? '[]', true) ?: [];
    $editFaq      = json_decode($edit['faq_json'] ?? '[]', true) ?: [];
}
if (count($editFeatures) < 1) $editFeatures = [['icon' => 'fa-check', 'title' => '', 'body' => '']];
while (count($editFeatures) < 4) $editFeatures[] = ['icon' => 'fa-check', 'title' => '', 'body' => ''];
if (count($editProcess) < 1) $editProcess = [['title' => '', 'body' => '']];
while (count($editProcess) < 4) $editProcess[] = ['title' => '', 'body' => ''];
if (count($editFaq) < 1) $editFaq = [['q' => '', 'a' => '']];
while (count($editFaq) < 3) $editFaq[] = ['q' => '', 'a' => ''];
?>
<style>
  .page-section-title { font-size: 0.95rem; font-weight: 700; color: #0B1E2D; margin: 1.75rem 0 0.85rem; padding-bottom: 0.4rem; border-bottom: 2px solid #02bbff; display: flex; align-items: center; gap: 0.5rem; }
  .hint { font-size: 0.78rem; color: #64748b; margin-top: 0.25rem; }
  .img-preview { max-width: 180px; max-height: 100px; border-radius: 8px; margin-top: 0.5rem; display: block; object-fit: cover; }
  .repeat-block { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.85rem; margin-bottom: 0.6rem; }
  .repeat-block .form-grid { margin: 0; }
  .view-page-link { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.8rem; color: #02bbff; text-decoration: none; margin-left: 0.5rem; }
  .view-page-link:hover { text-decoration: underline; }
</style>

<div class="card">
  <div class="card-title">
    <?= $edit ? 'Edit Service Page' : 'Add New Service' ?>
    <?php if ($edit): ?>
      <a href="services.php" class="btn btn-sm-action btn-secondary">Cancel</a>
      <?php if (!empty($edit['slug'])): ?>
        <a href="service.php?slug=<?= e(rawurlencode($edit['slug'])) ?>" target="_blank" class="view-page-link">
          <i class="fas fa-external-link-alt"></i> View public page
        </a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <input type="hidden" name="existing_hero_image" value="<?= e($edit['hero_image'] ?? '') ?>">
    <input type="hidden" name="existing_page_image" value="<?= e($edit['page_image'] ?? '') ?>">

    <div class="page-section-title"><i class="fas fa-info-circle"></i> Basic (homepage card)</div>
    <div class="form-grid">
      <div class="form-group">
        <label>Code (e.g. S-01)</label>
        <input type="text" name="code" value="<?= e($edit['code'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Title</label>
        <input type="text" name="title" value="<?= e($edit['title'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>URL Slug</label>
        <input type="text" name="slug" value="<?= e($edit['slug'] ?? '') ?>" placeholder="auto from title">
        <div class="hint">Public URL: https://ymrmarine.in/service.php?slug=your-slug</div>
      </div>
      <div class="form-group">
        <label>Icon (Font Awesome)</label>
        <input type="text" name="icon" value="<?= e($edit['icon'] ?? 'fa-ship') ?>" placeholder="fa-gas-pump">
      </div>
      <div class="form-group full">
        <label>Short description (homepage card)</label>
        <textarea name="description" rows="2"><?= e($edit['description'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="sort_order" value="<?= e($edit['sort_order'] ?? 0) ?>">
      </div>
      <div class="form-group">
        <label>Badge (optional)</label>
        <input type="text" name="badge" value="<?= e($edit['badge'] ?? '') ?>" placeholder="Most Requested">
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:1.5rem;padding-top:1.5rem;">
        <label style="display:flex;align-items:center;gap:0.4rem;text-transform:none;font-size:0.9rem;">
          <input type="checkbox" name="is_featured" <?= ($edit['is_featured'] ?? 0) ? 'checked' : '' ?>> Featured
        </label>
        <label style="display:flex;align-items:center;gap:0.4rem;text-transform:none;font-size:0.9rem;">
          <input type="checkbox" name="is_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>> Active
        </label>
      </div>
    </div>

    <div class="page-section-title"><i class="fas fa-search"></i> SEO</div>
    <div class="form-grid">
      <div class="form-group full">
        <label>Meta Title</label>
        <input type="text" name="meta_title" value="<?= e($edit['meta_title'] ?? '') ?>" maxlength="200">
      </div>
      <div class="form-group full">
        <label>Meta Description</label>
        <textarea name="meta_description" rows="2"><?= e($edit['meta_description'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Meta Keywords</label>
        <input type="text" name="meta_keywords" value="<?= e($edit['meta_keywords'] ?? '') ?>">
      </div>
    </div>

    <div class="page-section-title"><i class="fas fa-image"></i> Images</div>
    <div class="form-grid">
      <div class="form-group">
        <label>Hero background image</label>
        <input type="file" name="hero_image_file" accept="image/*">
        <div class="hint">Recommended ~1600x900. Leave empty to keep current / dummy image.</div>
        <?php if (!empty($edit['hero_image'])): ?>
          <img src="<?= strpos($edit['hero_image'], 'http') === 0 ? e($edit['hero_image']) : '../' . e($edit['hero_image']) ?>" class="img-preview" alt="Hero">
          <label style="display:flex;align-items:center;gap:0.35rem;margin-top:0.4rem;font-size:0.82rem;text-transform:none;">
            <input type="checkbox" name="clear_hero_image" value="1"> Remove image (use default)
          </label>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Side / overview image</label>
        <input type="file" name="page_image_file" accept="image/*">
        <div class="hint">Shown beside overview text.</div>
        <?php if (!empty($edit['page_image'])): ?>
          <img src="<?= strpos($edit['page_image'], 'http') === 0 ? e($edit['page_image']) : '../' . e($edit['page_image']) ?>" class="img-preview" alt="Page">
          <label style="display:flex;align-items:center;gap:0.35rem;margin-top:0.4rem;font-size:0.82rem;text-transform:none;">
            <input type="checkbox" name="clear_page_image" value="1"> Remove image (use default)
          </label>
        <?php endif; ?>
      </div>
    </div>

    <div class="page-section-title"><i class="fas fa-heading"></i> Page content</div>
    <div class="form-grid">
      <div class="form-group full">
        <label>Hero subtitle</label>
        <textarea name="hero_subtitle" rows="2"><?= e($edit['hero_subtitle'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Overview heading</label>
        <input type="text" name="overview_title" value="<?= e($edit['overview_title'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Overview body (paragraph 1)</label>
        <textarea name="overview_body" rows="5"><?= e($edit['overview_body'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Overview body (paragraph 2, optional)</label>
        <textarea name="overview_body2" rows="3"><?= e($edit['overview_body2'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Who uses this service</label>
        <textarea name="who_body" rows="3"><?= e($edit['who_body'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label>CTA button text</label>
        <input type="text" name="cta_text" value="<?= e($edit['cta_text'] ?? '') ?>" placeholder="Request This Survey">
      </div>
    </div>

    <div class="page-section-title"><i class="fas fa-th-large"></i> Feature cards (up to 6)</div>
    <?php foreach ($editFeatures as $i => $f): ?>
    <div class="repeat-block">
      <div class="form-grid">
        <div class="form-group">
          <label>Icon</label>
          <input type="text" name="feat_icon[]" value="<?= e($f['icon'] ?? 'fa-check') ?>">
        </div>
        <div class="form-group">
          <label>Title</label>
          <input type="text" name="feat_title[]" value="<?= e($f['title'] ?? '') ?>">
        </div>
        <div class="form-group full">
          <label>Description</label>
          <input type="text" name="feat_body[]" value="<?= e($f['body'] ?? '') ?>">
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="repeat-block">
      <div class="form-grid">
        <div class="form-group">
          <label>Icon (extra)</label>
          <input type="text" name="feat_icon[]" value="fa-check" placeholder="fa-ship">
        </div>
        <div class="form-group">
          <label>Title</label>
          <input type="text" name="feat_title[]" value="" placeholder="Leave blank to skip">
        </div>
        <div class="form-group full">
          <label>Description</label>
          <input type="text" name="feat_body[]" value="">
        </div>
      </div>
    </div>

    <div class="page-section-title"><i class="fas fa-list-ol"></i> Process steps</div>
    <?php foreach ($editProcess as $i => $p): ?>
    <div class="repeat-block">
      <div class="form-grid">
        <div class="form-group">
          <label>Step title</label>
          <input type="text" name="proc_title[]" value="<?= e($p['title'] ?? '') ?>">
        </div>
        <div class="form-group full">
          <label>Step description</label>
          <input type="text" name="proc_body[]" value="<?= e($p['body'] ?? '') ?>">
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="repeat-block">
      <div class="form-grid">
        <div class="form-group">
          <label>Step title (extra)</label>
          <input type="text" name="proc_title[]" value="" placeholder="Leave blank to skip">
        </div>
        <div class="form-group full">
          <label>Step description</label>
          <input type="text" name="proc_body[]" value="">
        </div>
      </div>
    </div>

    <div class="page-section-title"><i class="fas fa-question-circle"></i> FAQ</div>
    <?php foreach ($editFaq as $i => $item): ?>
    <div class="repeat-block">
      <div class="form-grid">
        <div class="form-group full">
          <label>Question</label>
          <input type="text" name="faq_q[]" value="<?= e($item['q'] ?? '') ?>">
        </div>
        <div class="form-group full">
          <label>Answer</label>
          <textarea name="faq_a[]" rows="2"><?= e($item['a'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="repeat-block">
      <div class="form-grid">
        <div class="form-group full">
          <label>Question (extra)</label>
          <input type="text" name="faq_q[]" value="" placeholder="Leave blank to skip">
        </div>
        <div class="form-group full">
          <label>Answer</label>
          <textarea name="faq_a[]" rows="2"></textarea>
        </div>
      </div>
    </div>

    <button type="submit" class="btn" style="margin-top:1.25rem;">
      <i class="fas fa-save"></i> <?= $edit ? 'Update Service Page' : 'Add Service' ?>
    </button>
  </form>
</div>

<div class="card">
  <div class="card-title">All Services (<?= count($services) ?>)</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Code</th><th>Title</th><th>Slug / Page</th><th>Featured</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($services as $s):
          $sSlug = $s['slug'] ?: serviceSlugify($s['title']);
        ?>
        <tr>
          <td><?= (int)$s['sort_order'] ?></td>
          <td><?= e($s['code']) ?></td>
          <td><?= e($s['title']) ?></td>
          <td>
            <a href="service.php?slug=<?= e(rawurlencode($sSlug)) ?>" target="_blank" class="view-page-link">
              <?= e($sSlug) ?> <i class="fas fa-external-link-alt"></i>
            </a>
          </td>
          <td><?= $s['is_featured'] ? '<span class="badge badge-warning">Yes</span>' : '—' ?></td>
          <td>
            <a href="?toggle=<?= (int)$s['id'] ?>">
              <span class="badge <?= $s['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $s['is_active'] ? 'Active' : 'Hidden' ?></span>
            </a>
          </td>
          <td style="white-space:nowrap;">
            <a href="?edit=<?= (int)$s['id'] ?>" class="btn btn-sm-action btn-secondary"><i class="fas fa-edit"></i></a>
            <a href="?delete=<?= (int)$s['id'] ?>" class="btn btn-sm-action btn-danger" onclick="return confirm('Delete this service?')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>