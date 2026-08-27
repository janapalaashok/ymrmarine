<?php
require_once __DIR__ . '/includes/admin_header.php';
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = ['site_name','site_tagline','phone','email','address','map_embed','employee_login_url','footer_text','copyright','primary_color'];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) setSetting($k, trim($_POST[$k]));
    }
    // Logo upload
    if (!empty($_FILES['logo']['name'])) {
        $path = uploadImage($_FILES['logo'], 'logos');
        if ($path) setSetting('logo', $path);
    }
    flash('success', 'Settings saved successfully');
    redirect('settings.php');
}

$s = [];
$stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
foreach ($stmt->fetchAll() as $row) $s[$row['setting_key']] = $row['setting_value'];
?>
<div class="card">
  <div class="card-title">General Site Settings</div>
  <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
    <div class="form-grid">
      <div class="form-group">
        <label>Site Name</label>
        <input type="text" name="site_name" value="<?= e($s['site_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Tagline</label>
        <input type="text" name="site_tagline" value="<?= e($s['site_tagline'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Phone</label>
        <input type="text" name="phone" value="<?= e($s['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" value="<?= e($s['email'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Address</label>
        <input type="text" name="address" value="<?= e($s['address'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Google Maps Embed URL</label>
        <input type="url" name="map_embed" value="<?= e($s['map_embed'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Employee Login URL</label>
        <input type="url" name="employee_login_url" value="<?= e($s['employee_login_url'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Primary Accent Color</label>
        <input type="text" name="primary_color" value="<?= e($s['primary_color'] ?? '#02bbff') ?>" placeholder="#02bbff">
      </div>
      <div class="form-group full">
        <label>Footer Text</label>
        <textarea name="footer_text"><?= e($s['footer_text'] ?? '') ?></textarea>
      </div>
      <div class="form-group full">
        <label>Copyright Text</label>
        <input type="text" name="copyright" value="<?= e($s['copyright'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Logo Image</label>
        <input type="file" name="logo" accept="image/*">
        <?php if (!empty($s['logo'])): ?>
          <img src="../<?= e($s['logo']) ?>" class="img-preview" alt="Logo">
        <?php endif; ?>
      </div>
    </div>
    <button type="submit" class="btn"><i class="fas fa-save"></i> Save Settings</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
